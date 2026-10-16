#!/usr/bin/env python3
"""Real multipart/photo permission and persistence checks; disposable loopback QA only.

Uses the existing private QA runtime. Never runs against a production hostname or
database. It creates isolated products and verifies their stock stays unchanged.
"""
from pathlib import Path
import argparse
import copy
import importlib.util
import json
import re
import secrets
import struct
import urllib.error
import urllib.request
import zlib

spec = importlib.util.spec_from_file_location('qa_base', Path(__file__).with_name('integration.py'))
qa = importlib.util.module_from_spec(spec)
spec.loader.exec_module(qa)


def png(width=1, height=1):
    def chunk(kind, data):
        return struct.pack('!I', len(data)) + kind + data + struct.pack('!I', zlib.crc32(kind + data))
    # Large dimensions are a header fixture, not an allocated large bitmap.
    return (b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('!2I5B', width, height, 8, 2, 0, 0, 0))
            + chunk(b'IDAT', zlib.compress(b'\x00\x20\x40\x60')) + chunk(b'IEND', b''))


class Images(qa.Checks):
    def upload(self, actor, contents, filename='photo.png', mime='image/png', expected=200, csrf=True):
        boundary = 'DezhawoPhoto' + secrets.token_hex(16)
        body = (('--' + boundary + '\r\nContent-Disposition: form-data; name="image"; filename="'
                 + filename + '"\r\nContent-Type: ' + mime + '\r\n\r\n').encode()
                + contents + ('\r\n--' + boundary + '--\r\n').encode())
        headers = {'Content-Type': 'multipart/form-data; boundary=' + boundary}
        if csrf:
            headers['X-CSRF-Token'] = actor.csrf
        request = urllib.request.Request(actor.base + '/api.php?action=product.imageUpload', data=body, headers=headers)
        try:
            response = actor.opener.open(request, timeout=20)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            payload = json.loads(response.read())
            assert response.status == expected, f'photo upload HTTP {response.status}, expected {expected}'
        actor.csrf = payload['csrf']
        assert bool(payload['ok']) == (expected == 200)
        return payload.get('data')

    def stored_product(self):
        return next(p for p in self.owner.bootstrap()['products'] if p['id'] == self.product_value['id'])

    def login(self):
        self.otp_sign_in(self.secrets['owner_phone'], self.owner)
        self.product_value = {'id': self.prefix + '_photo', 'title': self.prefix + ' عکس واقعی',
                              'category': 'case', 'price': 100, 'enabled': True,
                              'variants': [{'id': self.prefix + '_variant', 'name': 'مشکی',
                                            'color': '#202020', 'stock': 3, 'enabled': True}]}
        self.owner.action('product.save', {'product': self.product_value})
        self.expected_stock = 3
        assert self.stored_product()['imageUrl'] is None

    def permissions(self):
        guest = qa.Client(self.args.base)
        guest.bootstrap()
        self.upload(guest, png(), expected=419, csrf=False)
        self.upload(guest, png(), expected=401)
        role_id = self.owner.action('role.save', {'role': {'name': self.prefix + ' مشاهده', 'permissions': ['products.view']}})['id']
        phone = '09' + ''.join(str(secrets.randbelow(10)) for _ in range(9))
        self.owner.action('user.create', {'name': self.prefix, 'phone': phone, 'roleId': role_id})
        reader = self.otp_sign_in(phone)
        self.upload(reader, png(), expected=403)
        self.owner.action('role.save', {'role': {'id': role_id, 'name': self.prefix + ' ویرایش',
                                                'permissions': ['products.view', 'products.edit']}})
        self.reader = reader

    def formats(self):
        self.upload(self.owner, b'<?php echo "not a photo"; ?>', filename='fake.jpg', mime='image/jpeg', expected=415)
        self.upload(self.owner, b'<svg xmlns="http://www.w3.org/2000/svg"></svg>', filename='fake.png', expected=415)
        self.upload(self.owner, png(8001, 1), expected=400)
        self.upload(self.owner, png(6000, 6000), expected=400)
        self.upload(self.owner, b'X' * 8388609, expected=413)
        self.owner.action('product.imageUpload', {}, expected=415)

    def upload_and_attach(self):
        result = self.upload(self.reader, png(), filename='unsafe.php.png', mime='application/octet-stream')
        self.url = result['imageUrl']
        assert re.fullmatch(r'/uploads/products/[a-f0-9]{48}\.png', self.url)
        with urllib.request.urlopen(self.args.base.rstrip('/') + self.url, timeout=10) as response:
            assert response.status == 200 and response.read() == png()
            assert response.headers.get_content_type() == 'image/png'
        value = dict(self.product_value, imageUrl=self.url)
        self.reader.action('product.save', {'product': value})
        self.product_value = value
        stored = self.stored_product()
        assert stored['imageUrl'] == self.url and stored['variants'][0]['stock'] == 3
        public = qa.Client(self.args.base).bootstrap()
        assert next(p for p in public['products'] if p['id'] == value['id'])['imageUrl'] == self.url

    def forged_paths(self):
        for value in ('https://example.com/photo.png', 'javascript:alert(1)', '../app/config.php',
                      '/uploads/products/' + 'f' * 48 + '.png', '/uploads/products/../config.php',
                      '/uploads/products/' + 'f' * 48 + '.svg'):
            edit = dict(self.product_value, imageUrl=value)
            self.reader.action('product.save', {'product': edit}, expected=400)
        stored = self.stored_product()
        assert stored['imageUrl'] == self.url and stored['variants'][0]['stock'] == 3

    def preserve_and_remove(self):
        edit = copy.deepcopy(self.product_value)
        edit.pop('imageUrl')
        edit['title'] += ' ویرایش قدیمی'
        self.reader.action('product.save', {'product': edit})
        assert self.stored_product()['imageUrl'] == self.url
        edit['imageUrl'] = None
        self.reader.action('product.save', {'product': edit})
        stored = self.stored_product()
        assert stored['imageUrl'] is None and stored['variants'][0]['stock'] == self.expected_stock
        # Removing one reference must not delete a photo possibly used elsewhere.
        with urllib.request.urlopen(self.args.base.rstrip('/') + self.url, timeout=10) as response:
            assert response.status == 200

    def concurrent_inventory(self):
        self.owner.action('inventory.adjust', {'productId': self.product_value['id'],
                                              'variantId': self.product_value['variants'][0]['id'],
                                              'delta': 2, 'reason': 'گردش هم‌زمان با ویرایش عکس آزمون'})
        edit = copy.deepcopy(self.product_value)
        edit['variants'][0].pop('stock')
        self.reader.action('product.save', {'product': edit})
        stored = self.stored_product()
        assert stored['imageUrl'] == self.url and stored['variants'][0]['stock'] == 5
        self.product_value = edit
        self.expected_stock = 5


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--private-dir', required=True)
    parser.add_argument('--base', default='http://127.0.0.1:8080')
    parser.add_argument('--db-container', default='dezhawo-qa-db')
    parser.add_argument('--web-container', default='dezhawo-qa-web')
    args = parser.parse_args()
    checks = Images(args)
    for title, work in [('photo login and empty state', checks.login),
                        ('photo CSRF and live role permissions', checks.permissions),
                        ('photo MIME, dimension and size boundaries', checks.formats),
                        ('real multipart upload and public persistence', checks.upload_and_attach),
                        ('managed photo path validation', checks.forged_paths),
                        ('photo-only save preserves concurrent inventory', checks.concurrent_inventory),
                        ('legacy edit preservation and explicit removal', checks.preserve_and_remove)]:
        checks.check(title, work)
    print(f'PASS {checks.check_count} product photo HTTP scenarios')


if __name__ == '__main__':
    main()

#!/usr/bin/env python3
"""Product photos, gallery upgrades and deletion over isolated real HTTP/MariaDB.

Creates its own generated *_qa database, PHP container, private configuration,
upload directory and OTP outbox. Production configuration and the usual QA data
are never read or changed. All isolated resources are removed in finally.
"""
from copy import deepcopy
from pathlib import Path
import argparse
import importlib.util
import json
import re
import shutil
import urllib.request


def module(name, filename):
    spec = importlib.util.spec_from_file_location(name, Path(__file__).with_name(filename))
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value


upgrades = module('gallery_upgrades', 'database_upgrades.py')
photos = module('gallery_photos', 'product_images.py')
http = upgrades.http


class GalleryChecks(upgrades.UpgradeChecks):
    def __init__(self, args):
        super().__init__(args)
        self.args.base = self.base
        self.uploads = self.private / 'uploads'
        self.uploads.mkdir(mode=0o755)
        shutil.copyfile(self.repository / 'uploads/products/.htaccess', self.uploads / '.htaccess')

    def command(self, arguments, input=None):
        if arguments[:3] == ['docker', 'run', '-d']:
            arguments = arguments[:-1] + ['-v', str(self.uploads) + ':/var/www/html/uploads/products'] + arguments[-1:]
        return super().command(arguments, input)

    def product(self, actor=None):
        return next(row for row in (actor or self.owner).bootstrap()['products'] if row['id'] == 'gallery_product')

    def save(self, value=None, actor=None, expected=200):
        return (actor or self.owner).action('product.save', {'product': value or self.value}, expected=expected)

    def image_snapshot(self):
        return self.sql("SELECT HEX(JSON_OBJECT('title',title,'description',description,'main',image_url,"
                        "'gallery',gallery_json,'stock',(SELECT stock FROM product_variants WHERE id='gallery_variant'))) "
                        "FROM products WHERE id='gallery_product';")

    def prepare_photos(self):
        self.owner = self.login(self.owner_phone)
        self.owner.action('database.upgrade', {})
        assert self.sql("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() "
                        "AND TABLE_NAME='products' AND COLUMN_NAME='gallery_json';") == '1', 'Fresh schema lacks gallery column.'
        self.staff = self.login(self.staff_phone)
        self.customer = self.login(self.customer_phone)
        self.value = {'id': 'gallery_product', 'title': 'گالری آزمون', 'category': 'case', 'price': 321000,
                      'description': 'توضیحات قبل از ارتقا', 'enabled': True,
                      'variants': [{'id': 'gallery_variant', 'name': 'مشکی', 'color': '#111111',
                                    'stock': 4, 'enabled': True}]}
        self.save()
        self.urls = []
        for _ in range(9):
            result = photos.Images.upload(self, self.owner, photos.png())
            self.urls.append(result['imageUrl'])
        self.value['imageUrl'] = self.urls[0]
        result = self.save()
        assert result['imageUrl'] == self.urls[0] and result['galleryUrls'] == []
        assert self.product()['imageUrl'] == self.urls[0], 'Uploaded primary image did not survive a separate bootstrap.'
        with urllib.request.urlopen(self.base + self.urls[0], timeout=10) as response:
            assert response.status == 200 and response.read() == photos.png()

    def legacy_and_upgrade(self):
        self.sql('ALTER TABLE products DROP COLUMN gallery_json;')
        structure = self.structure()
        public = http.Client(self.base)
        for _ in range(2):
            product = self.product(public)
            assert product['imageUrl'] == self.urls[0] and product['galleryUrls'] == []
        assert self.structure() == structure, 'Gallery inspection performed DDL during GET.'
        status = self.owner.bootstrap()['databaseUpgrade']
        assert status['required'] and not status['recovery']
        assert [item['id'] for item in status['pending']] == ['product-gallery-20261017']
        self.save()  # A legacy client can still save the primary image before upgrading.
        self.save(dict(self.value, galleryUrls=[]))
        before = self.sql("SELECT title,image_url,description,price FROM products WHERE id='gallery_product';")
        error = self.save(dict(self.value, title='نباید ذخیره شود', galleryUrls=[self.urls[1]]), expected=503)
        assert 'ارتقای دیتابیس' in error['error']
        assert self.sql("SELECT title,image_url,description,price FROM products WHERE id='gallery_product';") == before
        for actor, expected in [(public, 401), (self.staff, 403), (self.customer, 403)]:
            assert 'databaseUpgrade' not in actor.bootstrap()
            actor.action('database.upgrade', {}, expected=expected)
        assert self.owner.request('/api.php?action=database.upgrade', {}, csrf=False)[0] == 419
        assert self.owner.request('/api.php?action=database.upgrade')[0] == 405
        result = self.owner.action('database.upgrade', {})
        assert result['applied'] == ['product-gallery-20261017']
        assert self.sql("SELECT title,image_url,description,price FROM products WHERE id='gallery_product';") == before
        before_structure = self.structure()
        for _ in range(2):
            assert self.owner.action('database.upgrade', {})['applied'] == []
        assert self.structure() == before_structure
        assert self.owner.bootstrap()['databaseUpgrade'] == {'required': False, 'pending': [], 'recovery': False}

    def full_gallery(self):
        self.value.update(galleryUrls=self.urls[1:], description='توضیحات کامل محصول\nسازگار با گوشی سامسونگ؛ <b>متن ساده</b>')
        result = self.save()
        assert result['imageUrl'] == self.urls[0] and result['galleryUrls'] == self.urls[1:]
        stored = self.product()
        assert stored['imageUrl'] == self.urls[0] and stored['galleryUrls'] == self.urls[1:]
        assert stored['description'] == self.value['description'] and stored['variants'][0]['stock'] == 4
        raw = self.sql("SELECT HEX(gallery_json) FROM products WHERE id='gallery_product';")
        assert json.loads(bytes.fromhex(raw).decode()) == [url.lstrip('/') for url in self.urls[1:]], \
            'Gallery must store relative managed upload paths.'
        public = self.product(http.Client(self.base))
        assert public['galleryUrls'] == stored['galleryUrls'] and 'cost' not in public
        for url in public['galleryUrls']:
            with urllib.request.urlopen(self.base + url, timeout=10) as response:
                assert response.status == 200 and response.read() == photos.png()

    def validation_and_deduplication(self):
        before = self.image_snapshot()
        invalid = [None, {'image': self.urls[1]}, 'photo.png', [None], [''], self.urls + [self.urls[1]],
                   ['https://example.com/photo.png'], ['javascript:alert(1)'],
                   ['../app/config.php'], ['/uploads/products/' + 'f' * 48 + '.png'],
                   ['/uploads/products/../config.php'], ['/uploads/products/' + 'f' * 48 + '.svg']]
        for gallery in invalid:
            self.save(dict(self.value, galleryUrls=gallery, title='رد شود'), expected=400)
            assert self.image_snapshot() == before, 'Invalid gallery partially changed product or stock.'
        result = self.save(dict(self.value, galleryUrls=[self.urls[1], self.urls[1], self.urls[0], self.urls[2]]))
        self.value['galleryUrls'] = self.urls[1:3]
        assert result['galleryUrls'] == self.value['galleryUrls']
        assert self.product()['galleryUrls'] == self.value['galleryUrls']
        self.sql("UPDATE products SET gallery_json='[\"javascript:alert(1)\",\"../app/config.php\",\""
                 + self.urls[1].lstrip('/') + "\",\"" + self.urls[0].lstrip('/') + "\"]' WHERE id='gallery_product';")
        assert self.product(http.Client(self.base))['galleryUrls'] == [self.urls[1]], 'Stored invalid URLs were exposed publicly.'
        self.save()

    def inventory_legacy_and_removal(self):
        self.owner.action('inventory.adjust', {'productId': 'gallery_product', 'variantId': 'gallery_variant',
                                               'delta': 2, 'reason': 'تغییر موجودی هم‌زمان با ویرایش توضیحات'})
        legacy = deepcopy(self.value)
        legacy.pop('galleryUrls')
        legacy.pop('imageUrl')
        legacy['variants'][0].pop('stock')
        legacy['description'] = 'شرح جدید بدون ارسال فیلد عکس‌ها'
        result = self.save(legacy)
        stored = self.product()
        assert stored['variants'][0]['stock'] == 6 and stored['description'] == legacy['description']
        assert stored['imageUrl'] == result['imageUrl'] == self.urls[0]
        assert stored['galleryUrls'] == result['galleryUrls'] == self.urls[1:3]
        promoted = dict(legacy, imageUrl=self.urls[1])
        assert self.save(promoted)['galleryUrls'] == [self.urls[2]], 'Promoted primary image remained duplicated in gallery.'
        self.save(dict(legacy, imageUrl=None, galleryUrls=[]))
        stored = self.product()
        assert stored['imageUrl'] is None and stored['galleryUrls'] == [] and stored['variants'][0]['stock'] == 6
        with urllib.request.urlopen(self.base + self.urls[1], timeout=10) as response:
            assert response.status == 200, 'Removing an image reference deleted a potentially shared file.'
        self.value = dict(legacy, imageUrl=self.urls[0], galleryUrls=self.urls[1:3])
        self.save()

    def deletion(self):
        guest = http.Client(self.base)
        guest.bootstrap()
        guest.action('product.delete', {'id': 'gallery_product'}, expected=401)
        self.customer.action('product.delete', {'id': 'gallery_product'}, expected=403)
        self.staff.action('product.delete', {'id': 'gallery_product'}, expected=403)
        self.sql("INSERT INTO reservations(id,cart_key,user_id,status,expires_at) VALUES('gallery_reserved',REPEAT('b',64),"
                 "'upgrade_customer','active',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 HOUR));"
                 "INSERT INTO reservation_items(reservation_id,product_id,variant_id,quantity,unit_price,unit_cost) VALUES"
                 "('gallery_reserved','gallery_product','gallery_variant',1,321000,0);")
        self.owner.action('product.delete', {'id': 'gallery_product'}, expected=409)
        assert self.product()['enabled'] is True
        self.sql("DELETE FROM reservations WHERE id='gallery_reserved';")
        order = self.owner.action('pos.sale', {'lines': [{'productId': 'gallery_product', 'variantId': 'gallery_variant',
                                                         'quantity': 1}], 'payment': 'card',
                                              'customer': {'firstName': 'خریدار', 'lastName': 'آزمون', 'phone': '09123456789'}})
        self.owner.action('product.delete', {'id': 'gallery_product'})
        stored = self.product()
        assert stored['enabled'] is False and stored['variants'][0]['stock'] == 5
        assert stored['imageUrl'] == self.urls[0] and stored['galleryUrls'] == self.urls[1:3]
        assert 'gallery_product' not in {row['id'] for row in guest.bootstrap()['products']}
        assert self.sql("SELECT COUNT(*) FROM orders WHERE id='" + order['id'] + "';") == '1'
        assert self.sql("SELECT quantity,title FROM order_items WHERE order_id='" + order['id'] + "';") == '1\tگالری آزمون'
        self.owner.action('product.enabled', {'id': 'gallery_product', 'enabled': True})
        assert self.product(guest)['galleryUrls'] == self.urls[1:3]

    def run(self):
        try:
            self.start()
            for label, check in [
                ('uploaded primary photo survives save and a new public bootstrap', self.prepare_photos),
                ('legacy primary photos keep working; gallery upgrade is owner-only, read-only on GET and idempotent', self.legacy_and_upgrade),
                ('one primary and eight extra photos plus descriptions persist and are publicly readable', self.full_gallery),
                ('invalid gallery paths, types and limits reject atomically; duplicates and primary overlap are removed', self.validation_and_deduplication),
                ('legacy edits preserve photos and concurrent stock; promoting or removing photos is explicit', self.inventory_legacy_and_removal),
                ('deletion checks permissions and reservations, hides the product and preserves stock and historical invoices', self.deletion),
            ]:
                check()
                self.count += 1
                print('PASS ' + label, flush=True)
            print(f'All {self.count} isolated product gallery HTTP checks passed.')
        finally:
            self.cleanup()


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--db-container', default='dezhawo-qa-db')
    parser.add_argument('--network', default='dezhawo-qa-net')
    parser.add_argument('--php-image', default='dezhawo-php-qa:8.3')
    parser.add_argument('--port', type=int, default=8083)
    arguments = parser.parse_args()
    assert 1024 <= arguments.port <= 65535
    GalleryChecks(arguments).run()

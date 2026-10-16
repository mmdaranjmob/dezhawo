#!/usr/bin/env python3
"""Real SSR catalog/SEO checks on the disposable loopback *_qa runtime only.

Creates isolated catalog fixtures, then disables them. No production credentials,
SMS messages, payment calls or changes to the running server config are needed.
"""
from html.parser import HTMLParser
from pathlib import Path
import argparse
import importlib.util
import json
import secrets
import struct
import subprocess
import urllib.error
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET
import zlib

spec = importlib.util.spec_from_file_location('qa_base', Path(__file__).with_name('integration.py'))
qa = importlib.util.module_from_spec(spec)
spec.loader.exec_module(qa)


class Document(HTMLParser):
    def __init__(self, html):
        super().__init__(convert_charrefs=True)
        self.meta, self.canonical, self.links, self.images, self.buttons = {}, [], [], [], []
        self.h1, self.schemas = [], []
        self.in_h1, self.in_schema, self.schema_text = False, False, ''
        self.feed(html)

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == 'meta':
            self.meta[attrs.get('name', attrs.get('property', ''))] = attrs.get('content', '')
        elif tag == 'link' and attrs.get('rel') == 'canonical':
            self.canonical.append(attrs['href'])
        elif tag == 'a':
            self.links.append(attrs.get('href', ''))
        elif tag == 'img':
            self.images.append(attrs)
        elif tag == 'button':
            self.buttons.append(attrs)
        elif tag == 'h1':
            self.h1.append('')
            self.in_h1 = True
        elif tag == 'script' and attrs.get('type') == 'application/ld+json':
            self.in_schema, self.schema_text = True, ''

    def handle_endtag(self, tag):
        if tag == 'h1':
            self.in_h1 = False
        elif tag == 'script' and self.in_schema:
            self.schemas.append(json.loads(self.schema_text))
            self.in_schema = False

    def handle_data(self, data):
        if self.in_h1:
            self.h1[-1] += data
        if self.in_schema:
            self.schema_text += data


class Seo(qa.Checks):
    def __init__(self, args):
        super().__init__(args)
        self.prefix = 'seoqa_' + secrets.token_hex(5)
        self.products = []
        self.reserver = None
        self.photo = None

    def fetch(self, path, expected=200):
        try:
            response = urllib.request.urlopen(self.args.base.rstrip('/') + path, timeout=20)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            status, headers, body = response.status, response.headers, response.read().decode('utf-8')
        assert status == expected, f'SEO endpoint {path}: HTTP {status}, expected {expected}'
        return headers, body

    def fixture(self, suffix, category='case', enabled=True, options=True):
        product_id = self.prefix + suffix
        value = {'id': product_id, 'title': 'محصول سئو «ویژه» & <img src=x onerror=alert(1)>',
                 'category': category, 'price': 1111, 'cost': 17, 'enabled': enabled,
                 'description': 'توضیح کامل & دقیق؛ </script><script>alert("seo-probe")</script>',
                 'imageUrl': self.photo_url,
                 'variants': [{'id': product_id + '_a', 'name': 'مشکی', 'color': '#222222',
                               'stock': 2, 'enabled': options},
                              {'id': product_id + '_b', 'name': 'سفید', 'color': '#eeeeee',
                               'price': 2222, 'stock': 0, 'enabled': options},
                              {'id': product_id + '_private', 'name': 'گزینهٔ غیرفعال',
                               'price': 9, 'stock': 100, 'enabled': False}]}
        self.owner.action('product.save', {'product': value})
        self.products.append(product_id)
        return product_id

    def setup(self):
        self.otp_sign_in(self.secrets['owner_phone'], self.owner)
        # A valid, isolated merchant-photo fixture in the existing private QA mount.
        def chunk(kind, data):
            return struct.pack('!I', len(data)) + kind + data + struct.pack('!I', zlib.crc32(kind + data))
        photo = (b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('!2I5B', 1, 1, 8, 2, 0, 0, 0))
                 + chunk(b'IDAT', zlib.compress(b'\x00\x20\x40\x60')) + chunk(b'IEND', b''))
        self.photo = self.private / 'product-uploads' / (secrets.token_hex(24) + '.png')
        self.photo.write_bytes(photo)
        self.photo.chmod(0o644)
        self.photo_url = '/uploads/products/' + self.photo.name
        self.active = self.fixture('_active')
        self.inactive = self.fixture('_inactive', enabled=False)
        self.no_variant = self.fixture('_no_variant', options=False)
        self.digital = self.fixture('_digital', category='digital')
        self.sql("UPDATE product_variants SET stock=0 WHERE product_id='" + self.digital + "';")
        self.canonical_base = Document(self.fetch('/')[1]).canonical[0].rstrip('/')

    def homepage(self):
        _, body = self.fetch('/')
        doc = Document(body)
        assert len(doc.h1) == 1 and doc.h1[0].strip(), 'Homepage must expose one readable SSR heading'
        assert doc.canonical == [self.canonical_base + '/']
        assert doc.meta['og:type'] == 'website' and doc.meta['description']
        assert doc.meta['robots'].startswith('index,follow')
        assert 'product.php?id=' + self.active in doc.links, 'Product links depend on JavaScript'
        assert all('product.php?id=' + product not in doc.links for product in (self.inactive, self.no_variant))
        assert any(schema['@type'] == 'WebSite' for schema in doc.schemas)

    def product(self):
        _, body = self.fetch('/product.php?id=' + self.active)
        doc = Document(body)
        url = self.canonical_base + '/product.php?id=' + self.active
        assert len(doc.h1) == 1 and 'محصول سئو' in doc.h1[0], 'Product heading is not server-rendered'
        assert doc.canonical == [url] and doc.meta['og:url'] == url and doc.meta['og:type'] == 'product'
        assert doc.meta['description'] and doc.meta['og:image'] == self.canonical_base + self.photo_url
        schema = next(value for value in doc.schemas if value['@type'] == 'Product')
        assert schema['url'] == url and schema['image'] == [self.canonical_base + self.photo_url]
        offers = {value['sku']: value for value in schema['offers']}
        assert set(offers) == {self.active + '_a', self.active + '_b'}, 'Inactive options leaked into offers'
        assert offers[self.active + '_a']['price'] == '11110', 'Toman fallback price was not converted to IRR'
        assert offers[self.active + '_b']['price'] == '22220', 'Variant price was not converted to IRR'
        assert all(offer['priceCurrency'] == 'IRR' for offer in offers.values())
        assert offers[self.active + '_a']['availability'].endswith('/InStock')
        assert offers[self.active + '_b']['availability'].endswith('/OutOfStock')
        assert any(image.get('src') == schema['image'][0] and image.get('alt') for image in doc.images)
        assert any(button.get('data-action') == 'product' and button.get('data-id') == self.active
                   and 'disabled' not in button for button in doc.buttons), 'Page cannot open the purchasing dialog'
        assert 'index.php#catalog' in doc.links, 'Product navigation does not return to the storefront'

    def escaping(self):
        _, body = self.fetch('/product.php?id=' + self.active)
        doc = Document(body)
        assert '<img src=x onerror=alert(1)>' in doc.h1[0], 'Merchant title was not retained as plain text'
        assert all(image.get('onerror') != 'alert(1)' for image in doc.images), 'Merchant title became executable HTML'
        assert '</script><script>alert("seo-probe")</script>' not in body, 'Merchant description can close a script'
        schema = next(value for value in doc.schemas if value['@type'] == 'Product')
        assert '<img src=x onerror=alert(1)>' in schema['name'], 'Safe structured data lost its title'
        assert 'cost' not in schema and 'cost' not in schema['offers'][0], 'Private accounting data leaked'

    def missing(self):
        for suffix in ('', '?id=' + self.prefix + '_unknown', '?id=' + self.inactive,
                       '?id=' + self.no_variant, '?id[]=array', '?id=%3Cscript%3E'):
            headers, body = self.fetch('/product.php' + suffix, expected=404)
            doc = Document(body)
            assert headers.get('X-Robots-Tag') == 'noindex, nofollow'
            assert doc.meta['robots'] == 'noindex,nofollow' and not doc.canonical and not doc.schemas
            assert len(doc.h1) == 1 and 'پیدا نشد' in doc.h1[0]

    def availability(self):
        self.reserver = qa.Client(self.args.base)
        self.reserver.bootstrap()
        self.reserver.action('cart.add', {'productId': self.active, 'variantId': self.active + '_a', 'quantity': 2})
        self.reserver.action('reservation.create', {})
        try:
            doc = Document(self.fetch('/product.php?id=' + self.active)[1])
            schema = next(value for value in doc.schemas if value['@type'] == 'Product')
            assert all(offer['availability'].endswith('/OutOfStock') for offer in schema['offers'])
            assert any(button.get('data-action') == 'product' and button.get('data-id') == self.active
                       and 'disabled' in button for button in doc.buttons), 'Fully reserved stock remained purchasable'
        finally:
            self.reserver.action('reservation.release', {})
            self.reserver = None
        doc = Document(self.fetch('/product.php?id=' + self.digital)[1])
        schema = next(value for value in doc.schemas if value['@type'] == 'Product')
        assert all(offer['availability'].endswith('/InStock') for offer in schema['offers']), 'Digital offers inherited physical inventory limits'

    def discovery(self):
        headers, body = self.fetch('/sitemap.php')
        assert headers.get_content_type() == 'application/xml'
        tree = ET.fromstring(body)
        locations = {node.text for node in tree.findall('{*}url/{*}loc')}
        assert self.canonical_base + '/' in locations
        for product in (self.active, self.digital):
            assert self.canonical_base + '/product.php?id=' + product in locations
        for product in (self.inactive, self.no_variant):
            assert self.canonical_base + '/product.php?id=' + product not in locations
        assert all('api.php' not in value and 'install.php' not in value for value in locations)
        _, robots = self.fetch('/robots.php')
        assert 'Sitemap: ' + self.canonical_base + '/sitemap.xml' in robots
        assert all('Disallow: /' + path in robots for path in ('app/', 'api.php', 'install.php', 'payment-callback.php'))
        for path in ('/install.php', '/payment-callback.php', '/digipay-callback.php'):
            try:
                response = urllib.request.urlopen(self.args.base.rstrip('/') + path, timeout=20)
            except urllib.error.HTTPError as error:
                response = error
            with response:
                assert response.headers.get('X-Robots-Tag') == 'noindex, nofollow', 'Private page can be indexed'

    def isolated_endpoints(self):
        # Separate PHP processes receive a synthetic config. The running QA config
        # and database are not changed; port 1 is deliberately not a DB server.
        for endpoint in ('index.php', 'product.php', 'sitemap.php', 'robots.php'):
            config = {'app': {'env': 'testing', 'key': '1' * 64, 'base_url': 'https://seo.example.test/shop'},
                      'database': {'host': '127.0.0.1', 'port': 1, 'name': 'isolated_qa', 'user': 'isolated_qa'}}
            if endpoint == 'robots.php':
                config['app']['base_url'] = 'invalid-config-url'
            script = '<?php\n$fixture=json_decode(' + json.dumps(json.dumps(config)) + ',true);\n'
            script += '$path=tempnam(sys_get_temp_dir(),"seo-qa-");chmod($path,0600);'
            script += 'file_put_contents($path,"<?php return ".var_export($fixture,true).";");'
            script += 'putenv("DEZHAWO_CONFIG=".$path);$_SERVER["SCRIPT_NAME"]="/' + endpoint + '";'
            script += '$_GET["id"]="isolated-product";ob_start();require "/var/www/html/' + endpoint + '";'
            script += '$body=ob_get_clean();unlink($path);echo json_encode(["status"=>http_response_code(),"body"=>$body]);'
            result = subprocess.run(['docker', 'exec', '-i', self.args.web_container, 'php'],
                                    input=script, text=True, capture_output=True, check=True)
            value = json.loads(result.stdout)
            assert value['status'] == 503, f'{endpoint} outage became a normal indexable page'
            if endpoint in ('index.php', 'product.php'):
                assert Document(value['body']).meta['robots'] == 'noindex,nofollow'
            elif endpoint == 'sitemap.php':
                assert ET.fromstring(value['body']).tag == 'error'
            else:
                assert 'Disallow: /' in value['body']
        script = '''<?php
$path=tempnam(sys_get_temp_dir(),'seo-qa-');chmod($path,0600);
$fixture=['app'=>['env'=>'testing','key'=>str_repeat('1',64),'base_url'=>'https://seo.example.test/shop'],
          'database'=>['host'=>'unused','name'=>'isolated_qa','user'=>'unused']];
file_put_contents($path,'<?php return '.var_export($fixture,true).';');putenv('DEZHAWO_CONFIG='.$path);
ob_start();require '/var/www/html/robots.php';$body=ob_get_clean();unlink($path);echo $body;
'''
        result = subprocess.run(['docker', 'exec', '-i', self.args.web_container, 'php'],
                                input=script, text=True, capture_output=True, check=True)
        assert 'Allow: /shop/' in result.stdout and 'Disallow: /shop/app/' in result.stdout
        assert 'Sitemap: https://seo.example.test/shop/sitemap.xml' in result.stdout

    def cleanup(self):
        if self.reserver:
            self.reserver.action('reservation.release', {})
        for product in self.products:
            self.owner.action('product.enabled', {'id': product, 'enabled': False})
        if self.photo:
            self.photo.unlink(missing_ok=True)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--private-dir', required=True)
    parser.add_argument('--base', default='http://127.0.0.1:8080')
    parser.add_argument('--db-container', default='dezhawo-qa-db')
    parser.add_argument('--web-container', default='dezhawo-qa-web')
    args = parser.parse_args()
    checks = Seo(args)
    try:
        checks.setup()
        for name, fn in [('homepage catalog without JavaScript', checks.homepage),
                         ('real product metadata, photo and IRR offers', checks.product),
                         ('merchant text escapes HTML and structured data', checks.escaping),
                         ('unknown and inactive product HTTP 404', checks.missing),
                         ('reservation-aware stock and digital availability', checks.availability),
                         ('active sitemap, robots and private-page noindex', checks.discovery),
                         ('outage 503 and subdirectory robots isolation', checks.isolated_endpoints)]:
            checks.check(name, fn)
    finally:
        checks.cleanup()
    print(f'PASS {checks.check_count} server-rendered SEO scenarios')


if __name__ == '__main__':
    main()

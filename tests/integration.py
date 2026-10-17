#!/usr/bin/env python3
"""Real HTTP + MariaDB integration checks, ONLY against the disposable Docker QA DB.

Run with private runtime secrets: python3 tests/integration.py --private-dir PATH
No application credentials or OTP codes are printed. This test creates test data.
It refuses non-loopback HTTP hosts or databases not ending in '_qa'.
"""
from concurrent.futures import ThreadPoolExecutor
from http.cookiejar import CookieJar
from pathlib import Path
import argparse
import json
import re
import secrets
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request


class Client:
    def __init__(self, base):
        self.base = base.rstrip('/')
        self.cookies = CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.cookies))
        self.csrf = None

    def request(self, path, data=None, csrf=True, form=False):
        headers = {}
        if data is not None:
            if form:
                raw = urllib.parse.urlencode(data).encode()
                headers['Content-Type'] = 'application/x-www-form-urlencoded'
            else:
                raw = json.dumps(data, ensure_ascii=False).encode()
                headers['Content-Type'] = 'application/json'
                if csrf and self.csrf:
                    headers['X-CSRF-Token'] = self.csrf
        else:
            raw = None
        request = urllib.request.Request(self.base + path, data=raw, headers=headers)
        try:
            response = self.opener.open(request, timeout=20)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            body = response.read().decode('utf-8')
            status = response.status
        try:
            parsed = json.loads(body)
            if isinstance(parsed, dict) and parsed.get('csrf'):
                self.csrf = parsed['csrf']
            return status, parsed
        except ValueError:
            return status, body

    def bootstrap(self):
        status, data = self.request('/api.php?action=bootstrap')
        assert status == 200 and data.get('ok'), 'bootstrap did not return successful JSON'
        return data['data']

    def action(self, name, value=None, expected=200):
        status, data = self.request('/api.php?action=' + name, value or {})
        assert status == expected, f'{name}: HTTP {status}, expected {expected}'
        if expected == 200:
            assert data.get('ok'), f'{name}: API reported failure'
            return data['data']
        assert not data.get('ok'), f'{name}: rejected operation reported success'
        return data


class Checks:
    def __init__(self, args):
        self.args = args
        self.private = Path(args.private_dir).resolve()
        self.secrets = json.loads((self.private / 'secrets.json').read_text())
        self.db_name = self.secrets['db_name']
        host = urllib.parse.urlsplit(args.base).hostname
        assert host in ('localhost', '127.0.0.1', '::1'), 'QA only accepts a loopback URL'
        assert self.db_name.endswith('_qa'), 'QA requires a disposable *_qa database'
        self.owner = Client(args.base)
        self.owner.bootstrap()
        self.prefix = 'qa_' + secrets.token_hex(6)
        self.check_count = 0
        assert self.sql('SELECT DATABASE();') == self.db_name, 'Container database does not match private QA config'
        # Clear only the disposable QA rate-limit table so retries remain repeatable.
        self.sql('DELETE FROM rate_limits;')

    def sql(self, sql):
        command = ['docker', 'exec', '-i', self.args.db_container, 'sh', '-c',
                   'MYSQL_PWD="$MARIADB_PASSWORD" mariadb -u "$MARIADB_USER" '
                   '--database="$MARIADB_DATABASE" -N -B']
        result = subprocess.run(command, input=sql, text=True, capture_output=True)
        assert result.returncode == 0, 'QA database query failed (details intentionally private)'
        return result.stdout.strip()

    def check(self, title, fn):
        fn()
        self.check_count += 1
        print('PASS', title, flush=True)

    def install(self):
        count = int(self.sql('SELECT COUNT(*) FROM users;'))
        status, html = self.owner.request('/install.php')
        if count == 0:
            assert status == 200
            token = re.search(r'name=["\']csrf["\'][^>]*value=["\']([^"\']+)', html)
            assert token, 'installer CSRF field absent'
            fields = {'csrf': token.group(1), 'installation_key': self.secrets['installation_key'],
                      'name': 'مدیر آزمون', 'phone': self.secrets['owner_phone']}
            bad = dict(fields, csrf='invalid')
            assert self.owner.request('/install.php', bad, form=True)[0] == 403
            bad = dict(fields, installation_key='invalid')
            assert self.owner.request('/install.php', bad, form=True)[0] == 403
            status, _ = self.owner.request('/install.php', fields, form=True)
            assert status == 201, f'first-owner installer HTTP {status}'
            assert int(self.sql('SELECT COUNT(*) FROM users;')) == 1
        else:
            assert status == 409, 'installer remained enabled after first owner'
        assert self.owner.request('/install.php')[0] == 409
        assert self.owner.request('/install.php', {}, form=True)[0] == 409

    def owner_login(self):
        self.otp_sign_in(self.secrets['owner_phone'], self.owner)
        data = self.owner.bootstrap()
        assert data['currentUser']['roleId'] == 'owner'
        assert 'roles.edit' in data['currentUser']['permissions']
        self.owner_id = data['currentUser']['id']
        self.owner.action('shipping.save', {'shipping': {'id': 'qa_shipping', 'name': 'ارسال آزمون',
                          'description': 'فقط محیط آزمون', 'fee': 50000, 'payOnDelivery': False, 'enabled': True}})

    def privacy(self):
        guest = Client(self.args.base)
        boot = guest.bootstrap()
        assert not boot.get('currentUser')
        assert not boot.get('users') and not boot.get('orders') and not boot.get('movements')
        forbidden = ('password_hash', 'merchant_id', 'api_key', 'installation_key', 'app_key')
        packed = json.dumps(boot)
        assert all(key not in packed for key in forbidden)
        status, response = guest.request('/api.php?action=inventory.adjust', {}, csrf=False)
        assert status in (403, 419) and not response.get('ok'), 'CSRF missing accepted'
        status, response = guest.request('/api.php?action=inventory.adjust', {})
        assert status in (401, 403) and not response.get('ok'), 'guest inventory accepted'
        status, _ = guest.request('/api.php?action=product.save')
        assert status in (400, 405), 'mutation accepted through GET'
        self.owner.action('user.enabled', {'userId': self.owner_id, 'enabled': False}, expected=409)
        self.owner.action('user.role', {'userId': self.owner_id, 'roleId': 'customer'}, expected=409)

    def otp_sign_in(self, phone, client=None, claims=None):
        """Use the actual HTTP OTP flow; only the testing outbox supplies the code."""
        client = client or Client(self.args.base)
        client.bootstrap()
        before = set((self.private / 'outbox').glob('*.json'))
        result = client.action('auth.requestOtp', {'phone': phone, **(claims or {})})
        assert 'code' not in json.dumps(result), 'OTP exposed in response'
        paths = set((self.private / 'outbox').glob('*.json')) - before
        assert len(paths) == 1, 'expected one private OTP outbox file'
        payload = json.loads(paths.pop().read_text())
        code = payload['code']
        assert self.sql("SELECT code_hash FROM otp_challenges WHERE id='" + payload['id'] + "';") != code
        assert self.sql("SELECT purpose FROM otp_challenges WHERE id='" + payload['id'] + "';") == 'login'
        client.action('auth.verifyOtp', {'phone': phone, 'code': code, **(claims or {})})
        client.action('auth.verifyOtp', {'phone': phone, 'code': code}, expected=401)
        return client

    def otp_login(self, label):
        phone = '09' + ''.join(str(secrets.randbelow(10)) for _ in range(9))
        # A client-supplied role must never provision an administrator.
        client = self.otp_sign_in(phone, claims={'roleId': 'owner', 'role': 'owner', 'permissions': ['roles.edit']})
        status, data = client.request('/api.php?action=auth.requestOtp', {'phone': phone})
        assert status == 429 and not data.get('ok'), 'OTP request limit not applied'
        assert client.bootstrap()['currentUser']['roleId'] == 'customer'
        return client, phone

    def auth_cases(self):
        self.customer, self.customer_phone = self.otp_login('customer')
        self.other_customer, _ = self.otp_login('other')
        status, data = self.customer.request('/api.php?action=role.save',
                                            {'role': {'name': 'bad', 'permissions': ['settings.edit']}})
        assert status == 403 and not data.get('ok')
        customer_boot = self.customer.bootstrap()
        assert not customer_boot.get('users')
        assert not customer_boot['currentUser']['permissions'], 'new phone obtained a privileged role'
        self.customer.action('auth.adminLogin', {'phone': self.customer_phone, 'password': 'removed'}, expected=404)
        self.owner.action('user.password', {'userId': self.owner_id, 'password': 'removed'}, expected=404)

    def otp_challenge(self):
        client = Client(self.args.base)
        client.bootstrap()
        phone = '09' + ''.join(str(secrets.randbelow(10)) for _ in range(9))
        before = set((self.private / 'outbox').glob('*.json'))
        client.action('auth.requestOtp', {'phone': phone})
        path, = set((self.private / 'outbox').glob('*.json')) - before
        return client, phone, json.loads(path.read_text())

    def otp_expiry_attempts(self):
        client, phone, challenge = self.otp_challenge()
        self.sql("UPDATE otp_challenges SET expires_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND) WHERE id='"+challenge['id']+"';")
        client.action('auth.verifyOtp', {'phone': phone, 'code': challenge['code']}, expected=401)
        assert not client.bootstrap().get('currentUser')
        client, phone, challenge = self.otp_challenge()
        wrong = '100000' if challenge['code'] != '100000' else '100001'
        for _ in range(5):
            client.action('auth.verifyOtp', {'phone': phone, 'code': wrong}, expected=401)
        client.action('auth.verifyOtp', {'phone': phone, 'code': challenge['code']}, expected=401)
        row = self.sql("SELECT attempts, consumed_at IS NOT NULL FROM otp_challenges WHERE id='"+challenge['id']+"';")
        assert row == '5\t1', 'five wrong attempts did not consume challenge'
    def staff_session_revocation(self):
        phone = '09' + ''.join(str(secrets.randbelow(10)) for _ in range(9))
        employee = self.owner.action('user.create', {'name': 'صندوق‌دار آزمون '+self.prefix,
                                 'phone': phone, 'roleId': 'cashier'})
        staff = self.otp_sign_in(phone)
        user = staff.bootstrap()['currentUser']
        assert user['roleId'] == 'cashier' and 'pos.create' in user['permissions']
        assert 'users.edit' not in user['permissions']
        self.owner.action('user.role', {'userId': employee['id'], 'roleId': 'customer'})
        assert not staff.bootstrap().get('currentUser'), 'old OTP session survived a role change'
        staff.action('pos.sale', {}, expected=401)

        phone = '09' + ''.join(str(secrets.randbelow(10)) for _ in range(9))
        employee = self.owner.action('user.create', {'name': 'کاربر غیرفعال آزمون '+self.prefix,
                                 'phone': phone, 'roleId': 'cashier'})
        staff = self.otp_sign_in(phone)
        self.owner.action('user.enabled', {'userId': employee['id'], 'enabled': False})
        assert not staff.bootstrap().get('currentUser'), 'disabled staff retained an OTP session'
        # An eligible challenge must also be rejected if the account is disabled before verification.
        pending, phone, challenge = self.otp_challenge()
        customer = self.sql("SELECT id FROM users WHERE phone='" + phone + "';")
        assert not customer
        created = self.owner.action('user.create', {'name': 'کاربر منتظر آزمون '+self.prefix,
                                 'phone': phone, 'roleId': 'cashier'})
        self.owner.action('user.enabled', {'userId': created['id'], 'enabled': False})
        pending.action('auth.verifyOtp', {'phone': phone, 'code': challenge['code']}, expected=401)
        assert not pending.bootstrap().get('currentUser')

        phone = '09' + ''.join(str(secrets.randbelow(10)) for _ in range(9))
        disabled = self.owner.action('user.create', {'name': 'کاربر مسدود آزمون '+self.prefix,
                                 'phone': phone, 'roleId': 'cashier'})
        self.owner.action('user.enabled', {'userId': disabled['id'], 'enabled': False})
        before = set((self.private / 'outbox').glob('*.json'))
        blocked = Client(self.args.base); blocked.bootstrap()
        reply = blocked.action('auth.requestOtp', {'phone': phone})
        assert set((self.private / 'outbox').glob('*.json')) == before, 'disabled account received an OTP'
        assert reply.get('expiresIn') == 300 and 'code' not in json.dumps(reply)

    def product(self, suffix, stock=5, category='case'):
        product_id, variant_id = self.prefix + suffix, self.prefix + '_v' + suffix
        data = {'id': product_id, 'title': 'محصول آزمون ' + suffix, 'category': category,
                'badge': '', 'description': 'آزمون مستقل پایگاه داده', 'art': 'case',
                'price': 120000, 'cost': 70000, 'enabled': True,
                'variants': [{'id': variant_id, 'name': 'مشکی', 'color': '#111111',
                              'stock': stock, 'enabled': True, 'price': None}]}
        self.owner.action('product.save', {'product': data})
        return product_id, variant_id

    def stock(self, variant_id):
        return int(self.sql("SELECT stock FROM product_variants WHERE id='" + variant_id + "';"))

    def pos_customer(self):
        return {'firstName': 'خریدار', 'lastName': 'آزمون', 'phone': '09120000000'}

    def inventory_pos(self):
        product, variant = self.product('_pos', 5)
        self.owner.action('inventory.adjust', {'productId': product, 'variantId': variant,
                          'delta': 3, 'reason': 'ورود کالا آزمون'})
        assert self.stock(variant) == 8
        sale = self.owner.action('pos.sale', {'lines': [{'productId': product, 'variantId': variant,
                                  'quantity': 2}], 'payment': 'card', 'customer': self.pos_customer()})
        assert self.stock(variant) == 6
        self.owner.action('pos.sale', {'lines': [{'productId': product, 'variantId': variant,
                           'quantity': 7}], 'payment': 'cash', 'customer': self.pos_customer()}, expected=409)
        assert self.stock(variant) == 6
        self.pos_order = sale.get('id', sale.get('orderId'))
        assert self.pos_order, 'POS did not return order identifier'

    def reservation_concurrency(self):
        product, variant = self.product('_race', 1)
        clients = [Client(self.args.base), Client(self.args.base)]
        for client in clients:
            client.bootstrap()
            client.action('cart.add', {'productId': product, 'variantId': variant, 'quantity': 1})
        def reserve(client):
            return client.request('/api.php?action=reservation.create', {})
        with ThreadPoolExecutor(2) as pool:
            results = list(pool.map(reserve, clients))
        assert sorted(result[0] for result in results) == [200, 409], 'parallel reservations oversold last unit'
        accepted = next(result[1]['data'] for result in results if result[0] == 200)
        assert 'unitCost' not in json.dumps(accepted), 'reservation exposed private acquisition cost'
        self.owner.action('pos.sale', {'lines': [{'productId': product, 'variantId': variant,
                          'quantity': 1}], 'payment': 'cash', 'customer': self.pos_customer()}, expected=409)
        assert self.stock(variant) == 1
        winner = clients[[result[0] for result in results].index(200)]
        winner.action('reservation.release', {})
        self.owner.action('pos.sale', {'lines': [{'productId': product, 'variantId': variant,
                          'quantity': 1}], 'payment': 'cash', 'customer': self.pos_customer()})
        assert self.stock(variant) == 0

    def parallel_pos(self):
        product, variant = self.product('_posrace', 1)
        clients = [Client(self.args.base), Client(self.args.base)]
        for client in clients:
            phone = '09' + ''.join(str(secrets.randbelow(10)) for _ in range(9))
            self.owner.action('user.create', {'name': 'فروشنده همزمان '+self.prefix,
                              'phone': phone, 'roleId': 'cashier'})
            self.otp_sign_in(phone, client)
        def sell(client):
            return client.request('/api.php?action=pos.sale', {'lines': [{'productId': product,
                                  'variantId': variant, 'quantity': 1}], 'payment': 'cash', 'customer': self.pos_customer()})
        with ThreadPoolExecutor(2) as pool:
            results = list(pool.map(sell, clients))
        assert sorted(result[0] for result in results) == [200, 409], 'parallel POS sales oversold last unit'
        assert self.stock(variant) == 0

    def checkout(self, client, product, variant, digital=False):
        client.action('cart.add', {'productId': product, 'variantId': variant, 'quantity': 1})
        client.action('reservation.create', {})
        enabled = [method for method in client.bootstrap()['shipping'] if method['enabled']]
        assert enabled, 'no shipping seed present'
        value = {'customer': {'name': 'خریدار آزمون', 'phone': self.customer_phone,
                             'telegramUsername': 'dezhawo_qa_buyer' if digital else ''},
                 'address': {'province': 'تهران', 'city': 'تهران', 'line': 'خیابان آزمون پلاک ۱',
                             'postalCode': '1234567890'}, 'shippingId': enabled[0]['id']}
        return client.action('order.checkout', value)

    def payment(self):
        product, variant = self.product('_online', 4)
        order = self.checkout(self.customer, product, variant)
        assert self.stock(variant) == 4, 'stock deducted before verified payment'
        self.customer.action('cart.set', {'productId': product, 'variantId': variant, 'quantity': 0}, expected=409)
        self.customer.action('reservation.create', {}, expected=409)
        # Calling an arbitrary callback cannot authorize stock mutation.
        self.customer.request('/payment-callback.php?Authority=TEST_nonexistent&Status=OK')
        assert self.stock(variant) == 4
        redirect = order['redirectUrl']
        path = urllib.parse.urlsplit(redirect)
        callback = path.path + ('?' + path.query if path.query else '')
        # Test a real server verification failure before a successful retry.
        config_path = self.private / 'qa-config.php'
        original_config = config_path.read_text()
        assert "'payment_stub'=>true" in original_config
        try:
            config_path.write_text(original_config.replace("'payment_stub'=>true", "'payment_stub'=>true,'payment_stub_failure'=>true"))
            time.sleep(1.1)
            self.customer.request(callback)
            assert self.stock(variant) == 4, 'provider verification failure deducted stock'
            pending = next(item for item in self.customer.bootstrap()['orders'] if item['id'] == order['orderId'])
            assert pending['paymentStatus'] == 'unpaid', 'browser Status=OK bypassed provider verification'
        finally:
            config_path.write_text(original_config)
            time.sleep(1.1)
        status, _ = self.customer.request(callback)
        assert status in (200, 302, 303), f'payment callback HTTP {status}'
        assert self.stock(variant) == 3, 'verified payment did not deduct exactly one'
        self.customer.request(callback)
        assert self.stock(variant) == 3, 'payment replay deducted stock again'
        saved = next(item for item in self.customer.bootstrap()['orders'] if item['id'] == order['orderId'])
        assert saved['paymentStatus'] == 'paid'
        assert not self.customer.bootstrap()['cart'], 'verified payment did not clear its own cart snapshot'
        assert saved['total'] == saved['subtotal'] + saved['shippingFee']
        assert not self.other_customer.bootstrap().get('orders'), 'another customer sees private order'
        self.owner.action('order.status', {'id': order['orderId'], 'status': 'cancelled'})
        assert self.stock(variant) == 4
        self.owner.action('order.status', {'id': order['orderId'], 'status': 'cancelled'})
        assert self.stock(variant) == 4, 'cancel replay restocked twice'
        self.online_order = order['orderId']

    def late_payment(self):
        product, variant = self.product('_late', 1)
        order = self.checkout(self.customer, product, variant)
        resumed = self.customer.action('order.resume', {'id': order['orderId']})
        assert resumed['redirectUrl'] == order['redirectUrl'], 'resume created another payment attempt'
        self.other_customer.action('order.resume', {'id': order['orderId']}, expected=404)
        self.sql("UPDATE reservations r JOIN orders o ON o.reservation_id=r.id SET r.expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE o.id='"+order['orderId']+"';")
        self.customer.action('order.resume', {'id': order['orderId']}, expected=409)
        self.owner.action('pos.sale', {'lines': [{'productId': product, 'variantId': variant,
                          'quantity': 1}], 'payment': 'cash', 'customer': self.pos_customer()})
        callback = urllib.parse.urlsplit(order['redirectUrl'])
        path = callback.path + '?' + callback.query
        self.customer.request(path)
        saved = next(item for item in self.customer.bootstrap()['orders'] if item['id'] == order['orderId'])
        assert saved['status'] == 'payment_review' and saved['paymentStatus'] == 'paid', 'late paid shortage not routed to review'
        assert self.stock(variant) == 0, 'late payment oversold consumed stock'
        self.customer.request(path)
        assert self.stock(variant) == 0, 'late payment replay oversold stock'
        self.owner.action('order.status', {'id': order['orderId'], 'status': 'cancelled'})
        assert self.stock(variant) == 0, 'review cancellation returned stock that was never deducted'

    def production_flags(self):
        path = self.private / 'production-flags.php'
        path.write_text((self.private / 'qa-config.php').read_text().replace("'env'=>'testing'", "'env'=>'production'"))
        path.chmod(0o600)
        program = "<?php require '/var/www/html/app/bootstrap.php';require '/var/www/html/app/Sms.php';require '/var/www/html/app/Payments.php';if(payment_is_stub()||sms_testing_outbox()!==null){exit(1);}"
        try:
            result = subprocess.run(['docker','exec','-i','-e','DEZHAWO_CONFIG=/qa/production-flags.php',
                                     self.args.web_container,'php'], input=program, text=True, capture_output=True)
            assert result.returncode == 0, 'production honored QA payment or OTP flags'
        finally:
            path.unlink()

    def digital(self):
        product, variant = self.product('_digital', 0, category='digital')
        order = self.checkout(self.customer, product, variant, digital=True)
        callback = urllib.parse.urlsplit(order['redirectUrl'])
        self.customer.request(callback.path + '?' + callback.query)
        assert self.stock(variant) == 0, 'digital payment changed physical inventory'
        saved = next(item for item in self.customer.bootstrap()['orders'] if item['id'] == order['orderId'])
        assert saved['customer']['telegramUsername'].lstrip('@') == 'dezhawo_qa_buyer', 'digital username was not persisted'
        assert saved['shippingFee'] == 0, 'digital-only order charged physical shipping'

    def persistence(self):
        role = self.owner.action('role.save', {'role': {'name': self.prefix + ' پشتیبان',
                                'permissions': ['tickets.view', 'tickets.reply']}})
        assert any(item['name'] == self.prefix + ' پشتیبان' for item in self.owner.bootstrap()['roles'])
        self.owner.action('settings.save', {'settings': {'notice': 'اطلاعیه آزمون ' + self.prefix}})
        assert Client(self.args.base).bootstrap()['settings']['notice'] == 'اطلاعیه آزمون ' + self.prefix
        ticket = self.customer.action('ticket.create', {'subject': 'تیکت آزمون ' + self.prefix,
                                      'message': 'پیام مشتری آزمون', 'orderId': self.online_order})
        ticket_id = ticket.get('id', ticket.get('ticketId'))
        assert ticket_id
        self.owner.action('ticket.reply', {'id': ticket_id, 'message': 'پاسخ پشتیبانی آزمون'})
        saved = next(item for item in self.customer.bootstrap()['tickets'] if item['id'] == ticket_id)
        assert len(saved['messages']) == 2
        assert saved['messages'][-1]['staff']
        assert not self.other_customer.bootstrap().get('tickets'), 'another customer sees private ticket'

    def run(self):
        for title, fn in [('first-owner installation and lock', self.install),
                          ('owner uses the shared mobile OTP login with stored permissions', self.owner_login),
                          ('CSRF, privacy, last-owner guard', self.privacy),
                          ('customer OTP, role claim isolation and removed password endpoints', self.auth_cases),
                          ('OTP expiry and five-attempt challenge consumption', self.otp_expiry_attempts),
                          ('staff OTP permissions, role revocation and disabled account rejection', self.staff_session_revocation),
                          ('shared inventory and POS transactions', self.inventory_pos),
                          ('parallel reservations and POS availability', self.reservation_concurrency),
                          ('parallel physical POS sales never oversell', self.parallel_pos),
                          ('verified payment, replay, privacy and single restock', self.payment),
                          ('digital username persistence and zero shipping', self.digital),
                          ('payment resume ownership and expired paid stock shortage', self.late_payment),
                          ('production ignores all private OTP/payment test flags', self.production_flags),
                          ('CMS, roles and customer ticket persistence', self.persistence)]:
            self.check(title, fn)
        print(f'PASS {self.check_count} integration scenarios against disposable MariaDB', flush=True)


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--private-dir', required=True)
    parser.add_argument('--base', default='http://127.0.0.1:8080')
    parser.add_argument('--db-container', default='dezhawo-qa-db')
    parser.add_argument('--web-container', default='dezhawo-qa-web')
    args = parser.parse_args()
    try:
        Checks(args).run()
    except (AssertionError, KeyError) as error:
        print('FAIL', str(error), file=sys.stderr)
        sys.exit(1)

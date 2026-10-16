#!/usr/bin/env python3
"""Payment-method boundaries against the disposable loopback *_qa database only.

No provider credentials or network requests are used. Bank numbers are synthetic
checksum fixtures. Settings are restored even when a scenario fails.
"""
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
import argparse
import copy
import importlib.util
import json
import secrets
import sys
import urllib.error
import urllib.request

spec = importlib.util.spec_from_file_location('qa_base', Path(__file__).with_name('integration.py'))
qa = importlib.util.module_from_spec(spec)
spec.loader.exec_module(qa)


class Payments(qa.Checks):
    def __init__(self, args):
        super().__init__(args)
        self.prefix = 'payqa_' + secrets.token_hex(5)
        self.saved_options = None
        self.owner_id = None
        self.customer_phone = None

    def stateless_callback(self):
        request = urllib.request.Request(self.args.base.rstrip('/') + '/digipay-callback.php?s=' + '0' * 64,
                                         data=b'', headers={'Content-Type': 'application/x-www-form-urlencoded'})
        try:
            response = urllib.request.urlopen(request, timeout=10)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            assert response.status == 400, 'malformed callback was not rejected'
            assert not response.headers.get_all('Set-Cookie'), 'cross-site callback could overwrite the customer login cookie'

    @staticmethod
    def iban(serial):
        account = str(serial).zfill(22)
        check_digits = 98 - int(account + '182700') % 97
        return 'IR' + str(check_digits).zfill(2) + account

    def login(self):
        self.otp_sign_in(self.secrets['owner_phone'], self.owner)
        boot = self.owner.bootstrap()
        assert boot['currentUser']['roleId'] == 'owner'
        self.owner_id = boot['currentUser']['id']
        self.saved_options = copy.deepcopy(boot['paymentSettings'])
        assert isinstance(self.saved_options, dict), 'owner payment settings absent'
        self.owner.action('shipping.save', {'shipping': {'id': self.prefix + '_shipping',
                          'name': 'ارسال آزمون پرداخت', 'description': 'فقط آزمون',
                          'fee': 0, 'payOnDelivery': False, 'enabled': True}})
        self.options = dict(self.saved_options, zarinpalEnabled=True, digipayEnabled=False,
                            bankTransferEnabled=True, bankCard='4000000000000002',
                            bankIban=self.iban(1), bankHolder='حساب ساختگی آزمون ' + self.prefix,
                            bankName='بانک ساختگی آزمون', bankInstructions='فقط محیط آزمون')
        self.owner.action('payments.settings', {'settings': self.options})

    def customer(self):
        phone = '09' + ''.join(str(secrets.randbelow(10)) for _ in range(9))
        return self.otp_sign_in(phone), phone

    def staff(self, label, permissions):
        role = self.owner.action('role.save', {'role': {'name': self.prefix + label,
                                 'permissions': permissions}})['id']
        phone = '09' + ''.join(str(secrets.randbelow(10)) for _ in range(9))
        self.owner.action('user.create', {'name': self.prefix + label, 'phone': phone, 'roleId': role})
        return self.otp_sign_in(phone)

    def bank_order(self, suffix, stock=2):
        buyer, phone = self.customer()
        product, variant = self.product(suffix, stock)
        result = self.checkout_method(buyer, phone, product, variant, 'bank_transfer')
        assert result['paymentMethod'] == 'bank_transfer' and result.get('redirectUrl') is None
        return buyer, result, product, variant

    def checkout_method(self, buyer, phone, product, variant, method, expected=200):
        buyer.action('cart.add', {'productId': product, 'variantId': variant, 'quantity': 1})
        buyer.action('reservation.create', {})
        return buyer.action('order.checkout', {
            'customer': {'name': 'خریدار آزمون پرداخت', 'phone': phone, 'telegramUsername': ''},
            'address': {'province': 'تهران', 'city': 'تهران', 'line': 'نشانی ساختگی آزمون',
                        'postalCode': '1234567890'},
            'shippingId': self.prefix + '_shipping', 'paymentMethod': method}, expected=expected)

    def stored_order(self, order_id):
        rows = self.sql("SELECT status,payment_status FROM orders WHERE id='" + order_id + "';").split('\t')
        assert len(rows) == 2, 'expected one stored order'
        return rows

    def metadata(self, order_id):
        return json.loads(self.sql("SELECT metadata_json FROM payment_attempts WHERE order_id='" + order_id + "' ORDER BY created_at DESC,id DESC LIMIT 1;"))

    def reservation_expiry(self, order_id):
        return self.sql("SELECT r.expires_at FROM reservations r JOIN orders o ON o.reservation_id=r.id WHERE o.id='" + order_id + "';")

    def expire(self, order_id):
        self.sql("UPDATE reservations r JOIN orders o ON o.reservation_id=r.id SET r.expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE o.id='" + order_id + "';")

    def review(self, order_id, decision='approve', client=None, expected=200):
        return (client or self.owner).action('transfer.review', {
            'orderId': order_id, 'decision': decision, 'note': 'بررسی ساختگی آزمون'}, expected=expected)

    def settings(self):
        guest = qa.Client(self.args.base)
        public = guest.bootstrap()
        assert public.get('paymentSettings') is None, 'guest saw manager payment settings'
        assert self.options['bankCard'] not in json.dumps(public), 'bank account leaked before checkout'
        assert self.options['bankIban'] not in json.dumps(public), 'IBAN leaked before checkout'
        assert {'zarinpal', 'digipay', 'bank_transfer'} == {row['id'] for row in public['paymentMethods']}
        assert next(row for row in public['paymentMethods'] if row['id'] == 'bank_transfer')['available']
        for changes in [
            {'bankCard': '4000000000000003'}, {'bankCard': '0000000000000000'},
            {'bankIban': self.iban(1)[:-1] + '2'}, {'bankIban': 'IR123'},
            {'bankCard': '', 'bankIban': ''}, {'bankHolder': ''},
            {'bankTransferEnabled': 'true'}, {'client_secret': 'forbidden_fixture_field'},
            {'bankInstructions': 'x' * 2001},
        ]:
            self.owner.action('payments.settings', {'settings': changes}, expected=400)
            assert self.owner.bootstrap()['paymentSettings'] == self.options, 'invalid input partially changed settings'
        finance = self.staff(' مالی', ['finance.view', 'orders.edit'])
        finance.action('payments.settings', {'settings': {'bankTransferEnabled': False}}, expected=403)
        assert finance.bootstrap().get('paymentSettings') is None
        self.finance = finance

    def disabled_methods(self):
        self.owner.action('payments.settings', {'settings': {'bankTransferEnabled': False}})
        buyer, phone = self.customer()
        product, variant = self.product('_disabled', 1)
        self.checkout_method(buyer, phone, product, variant, 'bank_transfer', expected=503)
        assert self.stock(variant) == 1
        buyer.action('reservation.release', {})
        buyer.action('order.checkout', {'paymentMethod': 'digipay'}, expected=503)
        buyer.action('order.checkout', {'paymentMethod': 'unknown_provider'}, expected=400)
        self.owner.action('payments.settings', {'settings': {'bankTransferEnabled': True}})

    def snapshot_and_authorization(self):
        buyer, order, product, variant = self.bank_order('_snapshot', 2)
        order_id = order['orderId']
        self.snapshot_order = order_id
        assert order['manualPayment']['card'] == self.options['bankCard']
        assert order['manualPayment']['holder'] == self.options['bankHolder']
        assert self.stock(variant) == 2 and self.stored_order(order_id)[1] == 'unpaid'
        remaining = int(self.sql("SELECT TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(),r.expires_at) FROM reservations r JOIN orders o ON o.reservation_id=r.id WHERE o.id='" + order_id + "';"))
        assert 0 < remaining <= 7200, 'manual transfer reservation is not bounded to two hours'
        expires = self.reservation_expiry(order_id)
        self.review(order_id, expected=409)
        self.owner.action('payments.settings', {'settings': {'bankCard': '5555555555554444',
                          'bankIban': self.iban(2), 'bankHolder': 'حساب ساختگی دوم'}})
        resumed = buyer.action('order.resume', {'id': order_id})
        assert resumed['manualPayment']['card'] == self.options['bankCard'], 'pending order bank account changed retroactively'
        assert resumed['manualPayment']['holder'] == self.options['bankHolder']
        self.owner.action('payments.settings', {'settings': {'bankTransferEnabled': False}})
        assert buyer.action('order.resume', {'id': order_id})['manualPayment']['card'] == self.options['bankCard'], 'disabling new transfers concealed an existing account snapshot'
        other, _ = self.customer()
        other.action('order.resume', {'id': order_id}, expected=404)
        other.action('transfer.submit', {'orderId': order_id, 'reference': 'fixture-private'}, expected=404)
        assert not any(row['id'] == order_id for row in other.bootstrap()['orders'])
        buyer.action('transfer.review', {'orderId': order_id, 'decision': 'approve'}, expected=403)
        orders_only = self.staff(' سفارش', ['orders.view', 'orders.edit'])
        self.review(order_id, client=orders_only, expected=403)
        finance_only = self.staff(' فقط مالی', ['finance.view'])
        self.review(order_id, client=finance_only, expected=403)
        buyer.action('transfer.submit', {'orderId': order_id, 'reference': 'x' * 101}, expected=400)
        buyer.action('transfer.submit', {'orderId': order_id, 'reference': 'fixture-reference', 'note': 'x' * 1001}, expected=400)
        for field, value in [('total', 1), ('paymentStatus', 'paid'), ('userId', self.owner_id)]:
            buyer.action('transfer.submit', {'orderId': order_id, 'reference': 'fixture-reference', field: value}, expected=400)
        buyer.action('transfer.submit', {'orderId': order_id, 'reference': 'fixture-reference', 'note': 'رسید ساختگی'})
        buyer.action('transfer.submit', {'orderId': order_id, 'reference': 'fixture-reference', 'note': 'رسید ساختگی'})
        assert self.reservation_expiry(order_id) == expires, 'resume or receipt replay renewed a stock reservation'
        self.owner.action('transfer.review', {'orderId': order_id, 'decision': 'approve', 'total': 1}, expected=400)
        state = self.stored_order(order_id)
        assert state[1] != 'paid' and self.stock(variant) == 2, 'customer receipt submission finalized payment'
        self.snapshot_buyer, self.snapshot_variant = buyer, variant
        self.owner.action('payments.settings', {'settings': self.options})

    def concurrent_approval_and_cancel(self):
        order_id, variant = self.snapshot_order, self.snapshot_variant
        def approve(client):
            return client.request('/api.php?action=transfer.review', {'orderId': order_id, 'decision': 'approve'})
        with ThreadPoolExecutor(2) as pool:
            results = list(pool.map(approve, [self.owner, self.finance]))
        assert all(status == 200 and body.get('ok') for status, body in results), 'parallel approval was not idempotent'
        assert self.stock(variant) == 1 and self.stored_order(order_id)[1] == 'paid'
        self.review(order_id)
        assert self.stock(variant) == 1, 'approval replay deducted stock twice'
        self.review(order_id, 'reject', expected=409)
        movements = self.sql("SELECT COUNT(*) FROM stock_movements WHERE order_id='" + order_id + "' AND delta<0;")
        assert movements == '1', 'approval did not record one inventory allocation'
        self.owner.action('order.status', {'id': order_id, 'status': 'cancelled'})
        self.owner.action('order.status', {'id': order_id, 'status': 'cancelled'})
        assert self.stock(variant) == 2, 'cancel replay restocked twice'

    def rejection(self):
        buyer, order, product, variant = self.bank_order('_reject', 1)
        order_id = order['orderId']
        buyer.action('transfer.submit', {'orderId': order_id, 'reference': 'fixture-rejected'})
        self.review(order_id, 'reject')
        self.review(order_id, 'reject')
        self.review(order_id, 'approve', expected=409)
        assert self.stored_order(order_id)[1] != 'paid' and self.stock(variant) == 1
        self.owner.action('pos.sale', {'lines': [{'productId': product, 'variantId': variant,
                          'quantity': 1}], 'payment': 'cash', 'customer': 'فروش ساختگی پس از رد'})
        assert self.stock(variant) == 0, 'rejected transfer kept its stock reservation'
        self.owner.action('order.status', {'id': order_id, 'status': 'cancelled'})
        self.owner.action('order.status', {'id': order_id, 'status': 'cancelled'})
        assert self.stock(variant) == 0, 'unallocated rejected transfer restored stock'

    def late_approval(self):
        buyer, order, product, variant = self.bank_order('_late', 1)
        order_id = order['orderId']
        buyer.action('transfer.submit', {'orderId': order_id, 'reference': 'fixture-late'})
        self.expire(order_id)
        expires = self.reservation_expiry(order_id)
        buyer.action('order.resume', {'id': order_id})
        assert self.reservation_expiry(order_id) == expires, 'late resume renewed the expired reservation'
        self.owner.action('pos.sale', {'lines': [{'productId': product, 'variantId': variant,
                          'quantity': 1}], 'payment': 'cash', 'customer': 'فروش ساختگی پس از پایان رزرو'})
        self.review(order_id)
        assert self.stored_order(order_id) == ['payment_review', 'paid'], 'late approval with shortage did not enter payment review'
        self.review(order_id)
        assert self.stock(variant) == 0, 'late approval/replay oversold last unit'
        self.owner.action('order.status', {'id': order_id, 'status': 'cancelled'})
        assert self.stock(variant) == 0, 'shortage cancellation restored unallocated stock'

    def cancelled_pending(self):
        buyer, order, product, variant = self.bank_order('_cancelpending', 1)
        order_id = order['orderId']
        buyer.action('transfer.submit', {'orderId': order_id, 'reference': 'fixture-cancelled'})
        self.owner.action('order.status', {'id': order_id, 'status': 'cancelled'})
        self.owner.action('order.status', {'id': order_id, 'status': 'cancelled'})
        self.review(order_id, expected=409)
        buyer.action('transfer.submit', {'orderId': order_id, 'reference': 'fixture-cancelled'}, expected=409)
        assert self.stock(variant) == 1, 'pending cancellation or late approval changed unallocated stock'
        assert self.stored_order(order_id) == ['cancelled', 'failed']

    def competing_expired_approvals(self):
        product, variant = self.product('_lastrace', 1)
        orders = []
        for index in range(2):
            buyer, phone = self.customer()
            order_id = self.checkout_method(buyer, phone, product, variant, 'bank_transfer')['orderId']
            buyer.action('transfer.submit', {'orderId': order_id, 'reference': 'fixture-race-' + str(index)})
            self.expire(order_id)
            orders.append(order_id)
        def approve(pair):
            client, order_id = pair
            return client.request('/api.php?action=transfer.review', {'orderId': order_id, 'decision': 'approve'})
        with ThreadPoolExecutor(2) as pool:
            results = list(pool.map(approve, zip([self.owner, self.finance], orders)))
        assert all(status == 200 and body.get('ok') for status, body in results)
        states = [self.stored_order(order_id) for order_id in orders]
        assert sorted(state[0] for state in states) == ['paid', 'payment_review']
        assert all(state[1] == 'paid' for state in states) and self.stock(variant) == 0, 'concurrent last-unit approval oversold or lost a confirmed payment'
        for order_id in orders:
            self.owner.action('order.status', {'id': order_id, 'status': 'cancelled'})
        assert self.stock(variant) == 1, 'two cancellations restored stock for both the allocated and shortage order'

    def run(self):
        try:
            self.login()
            for title, fn in [
                ('cross-site callback rejects forged input without replacing the login cookie', self.stateless_callback),
                ('payment settings validation, public privacy and manager permission', self.settings),
                ('disabled or unknown payment methods cannot create payments', self.disabled_methods),
                ('bank snapshot, owner privacy, staff scope and unpaid receipt submission', self.snapshot_and_authorization),
                ('concurrent approval, replay and one-time cancellation allocation', self.concurrent_approval_and_cancel),
                ('rejected transfer releases reservation without restoring unallocated stock', self.rejection),
                ('late bank approval sends paid shortage to review without overselling', self.late_approval),
                ('cancelled pending transfer cannot be approved or restore unallocated inventory', self.cancelled_pending),
                ('concurrent expired transfers allocate the final unit only once', self.competing_expired_approvals),
            ]:
                self.check(title, fn)
        finally:
            if self.saved_options is not None:
                self.owner.action('payments.settings', {'settings': self.saved_options})
                assert self.owner.bootstrap()['paymentSettings'] == self.saved_options, 'QA payment settings were not restored'
        print(f'PASS {self.check_count} payment-method scenarios; original payment settings restored', flush=True)


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--private-dir', required=True)
    parser.add_argument('--base', default='http://127.0.0.1:8080')
    parser.add_argument('--db-container', default='dezhawo-qa-db')
    parser.add_argument('--web-container', default='dezhawo-qa-web')
    args = parser.parse_args()
    try:
        Payments(args).run()
    except (AssertionError, KeyError) as error:
        print('FAIL', str(error), file=sys.stderr)
        sys.exit(1)

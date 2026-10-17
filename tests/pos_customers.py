#!/usr/bin/env python3
"""POS customer identity over real HTTP and MariaDB, only on a disposable *_qa DB.

Run: python3 tests/pos_customers.py --private-dir /path/to/private/qa
The test creates isolated synthetic products/users and removes them in finally.
Production configuration is never read, changed or printed.
"""
from concurrent.futures import ThreadPoolExecutor
from copy import deepcopy
import argparse
import importlib.util
import json
from pathlib import Path
import secrets

spec = importlib.util.spec_from_file_location('qa_base', Path(__file__).with_name('integration.py'))
qa = importlib.util.module_from_spec(spec)
spec.loader.exec_module(qa)
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--private-dir', required=True)
parser.add_argument('--base', default='http://127.0.0.1:8080')
parser.add_argument('--db-container', default='dezhawo-qa-db')
parser.add_argument('--web-container', default='dezhawo-qa-web')
args = parser.parse_args()
safety = qa.Checks(args)
owner = safety.otp_sign_in(safety.secrets['owner_phone'])
owner_id = owner.bootstrap()['currentUser']['id']
prefix = 'poscustomer_' + secrets.token_hex(5)
product_ids, user_ids, role_ids = [], [], []
checks = 0


def check(label, fn):
    global checks
    fn()
    checks += 1
    print('PASS', label, flush=True)


def phone():
    return '09' + ''.join(str(secrets.randbelow(10)) for _ in range(9))


def actor(permissions):
    role = owner.action('role.save', {'role': {'name': prefix, 'permissions': permissions}})
    role_ids.append(role['id'])
    user = owner.action('user.create', {'name': prefix + ' همکار', 'phone': phone(), 'roleId': role['id']})
    user_ids.append(user['id'])
    return safety.otp_sign_in(user['phone']), user


def product(suffix, stock):
    product_id, variant_id = prefix + suffix, prefix + suffix + '_v'
    owner.action('product.save', {'product': {
        'id': product_id, 'title': prefix + ' محصول', 'category': 'case',
        'badge': '', 'description': 'محصول مستقل آزمون فروش حضوری', 'art': 'case',
        'price': 321000, 'cost': 12345, 'enabled': True,
        'variants': [{'id': variant_id, 'name': 'مشکی', 'color': '#111111',
                      'stock': stock, 'enabled': True, 'price': None}],
    }})
    product_ids.append(product_id)
    return product_id, variant_id


def payload(product_id=None, variant_id=None):
    return {'lines': [{'productId': product_id or main_product, 'variantId': variant_id or main_variant,
                       'quantity': 1}], 'payment': 'card',
            'customer': {'firstName': 'علی', 'lastName': 'رضایی', 'phone': invoice_phone}}


def snapshot():
    return safety.sql("SELECT CONCAT_WS('|',"
                      "(SELECT stock FROM product_variants WHERE id='" + main_variant + "'),"
                      '(SELECT COUNT(*) FROM orders),(SELECT COUNT(*) FROM order_items),'
                      '(SELECT COUNT(*) FROM stock_movements),(SELECT COUNT(*) FROM audit_logs));')


def reject(value, message=None):
    before = snapshot()
    result = seller.action('pos.sale', value, expected=400)
    text = result.get('error', '')
    assert isinstance(text, str) and text.strip(), 'Invalid POS identity lacks an actionable error'
    if message:
        assert message in text, 'Invalid POS identity lacks the relevant field label'
    assert snapshot() == before, 'Rejected identity changed an order, inventory movement or audit entry'


def stored(order_id):
    raw = safety.sql("SELECT HEX(JSON_OBJECT('name',customer_name,'phone',customer_phone,"
                     "'address',address_json,'staff',user_id,'type',type,'total',total,"
                     "'status',status,'paymentStatus',payment_status)) FROM orders WHERE id='" + order_id + "';")
    # HEX bypasses MariaDB batch-mode backslash escaping for nested address JSON.
    result = json.loads(bytes.fromhex(raw).decode('utf-8'))
    result['address'] = json.loads(result['address']) if result['address'] else None
    return result


def invoice(order_id, client=owner):
    return next(row for row in client.bootstrap()['orders'] if row['id'] == order_id)


def required_identity():
    for key in ('firstName', 'lastName', 'phone'):
        value = payload()
        del value['customer'][key]
        reject(value)
        for empty in ('', '   ', '\u2003\u00a0'):
            value['customer'][key] = empty
            reject(value)
    for legacy in ('مشتری حضوری', '', None, [], ['علی'], True, 123):
        reject(dict(payload(), customer=legacy))
    value = payload()
    del value['customer']
    reject(value)


def bounded_text():
    for field in ('firstName', 'lastName'):
        for invalid in (12, True, [], {}, 'ا' * 51, 'نام\nدیگر', 'نام\tدیگر', 'نام\x00دیگر', 'نام\x7fدیگر'):
            value = payload()
            value['customer'][field] = invalid
            reject(value)
    value = payload()
    value['customer'].update(firstName='ا' * 50, lastName='ب' * 50)
    reject(value, '۱۰۰')
    for invalid in (True, 9123456789, [], {}, '02112345678', '0912', '09123456789tail',
                    '09123456789' + ' ' * 21, '0912345\n6789'):
        value = payload()
        value['customer']['phone'] = invalid
        reject(value)


def invalid_addresses():
    for invalid in ('آدرس', 14, False, ['آدرس'], {'line': 3}, {'line': True}, {'line': []},
                    {'line': 'ا' * 501}, {'line': 'آدرس\x00پنهان'}, {'line': 'آدرس\x85پنهان'}):
        reject(dict(payload(), address=invalid), 'آدرس')


def optional_addresses():
    for kind in ('missing', None, {'line': ''}, {'line': '   \n\t  '}, {}):
        value = payload()
        if kind != 'missing':
            value['address'] = kind
        result = seller.action('pos.sale', value)
        row = stored(result['id'])
        assert row['address'] is None, 'An omitted or blank optional address was not SQL NULL'
        assert row['name'] == 'علی رضایی' and row['phone'] == invoice_phone
        assert row['staff'] == seller_user['id'] and row['type'] == 'pos'
        assert row['status'] == 'completed' and row['paymentStatus'] == 'paid'
        assert invoice(result['id'], seller)['address'] is None


def normalized_phone_and_name():
    persian = str.maketrans('0123456789', '۰۱۲۳۴۵۶۷۸۹')
    arabic = str.maketrans('0123456789', '٠١٢٣٤٥٦٧٨٩')
    formats = [invoice_phone.translate(persian), ('+98' + invoice_phone[1:]).translate(arabic),
               '0098 (' + invoice_phone[1:4] + ') ' + invoice_phone[4:7] + '-' + invoice_phone[7:]]
    for raw in formats:
        value = payload()
        value['customer'].update(firstName='  علی\u200cاکبر  ', lastName='\u2003رضایی\u00a0', phone=raw)
        sale = seller.action('pos.sale', value)
        row = stored(sale['id'])
        assert row['phone'] == invoice_phone, 'Persian, Arabic or international-format mobile was not normalized'
        assert row['name'] == 'علی\u200cاکبر رضایی', 'Trimmed full name or Persian half-space was lost'
        assert invoice(sale['id'], seller)['customer'] == {
            'name': row['name'], 'phone': invoice_phone, 'telegramUsername': ''}


def full_invoice():
    global saved_invoice
    value = payload()
    value['customer'].update(firstName='ا' * 50, lastName='ب' * 49)
    value['address'] = {'line': '  تهران، خیابان آزمون\nپلاک ۱۲  '}
    value['lines'].append(deepcopy(value['lines'][0]))
    before = safety.stock(main_variant)
    sale = seller.action('pos.sale', value)
    saved_invoice = sale['id']
    row = stored(saved_invoice)
    assert len(row['name']) == 100, 'A legal 100-character full name was not stored intact'
    assert row['address'] == {'line': 'تهران، خیابان آزمون\nپلاک ۱۲'}
    assert row['staff'] == seller_user['id'], 'POS ownership moved from the cashier to the customer'
    assert row['total'] == 642000 and sale['total'] == 642000
    assert safety.stock(main_variant) == before - 2, 'Aggregated POS lines did not deduct inventory exactly once'
    public = invoice(saved_invoice, seller)
    assert public['address'] == row['address'] and public['customer']['name'] == row['name']
    assert public['customer']['phone'] == invoice_phone and public['lines'][0]['quantity'] == 2
    assert 'unitCost' not in public['lines'][0], 'Cashier invoice leaked private acquisition cost'
    assert invoice(saved_invoice)['lines'][0]['unitCost'] == 12345
    value = payload()
    value['address'] = {'line': 'ا' * 500}
    assert len(stored(seller.action('pos.sale', value)['id'])['address']['line']) == 500


def privacy_permissions_and_no_accounts():
    guest = qa.Client(args.base)
    guest_boot = guest.bootstrap()
    assert not guest_boot['orders'] and invoice_phone not in json.dumps(guest_boot)
    guest.action('pos.sale', payload(), expected=401)
    customer.action('pos.sale', payload(), expected=403)
    assert saved_invoice not in {row['id'] for row in customer.bootstrap()['orders']}, \
        'Matching a POS customer phone gave that account access to a staff-owned sale'
    assert saved_invoice not in {row['id'] for row in other_seller.bootstrap()['orders']}, \
        'A cashier without orders.view can read another staff member’s invoice'
    assert invoice(saved_invoice, viewer)['customer']['phone'] == invoice_phone
    assert int(safety.sql('SELECT COUNT(*) FROM users;')) == users_before_sales, \
        'POS recording created a customer account or required an OTP account'
    assert safety.sql("SELECT name FROM users WHERE phone='" + invoice_phone + "';") == 'مشتری مستقل آزمون', \
        'Recording an invoice renamed an existing customer account'
    unregistered = phone()
    value = payload()
    value['customer']['phone'] = unregistered
    seller.action('pos.sale', value)
    assert safety.sql("SELECT COUNT(*) FROM users WHERE phone='" + unregistered + "';") == '0', \
        'A POS customer mobile silently provisioned an account'
    assert int(safety.sql('SELECT COUNT(*) FROM users;')) == users_before_sales


def stock_failure_and_concurrent_sales():
    race_product, race_variant = product('_last', 1)
    wrong = payload(race_product, race_variant)
    wrong['customer']['firstName'] = ''
    before_orders = safety.sql('SELECT COUNT(*) FROM orders;')
    seller.action('pos.sale', wrong, expected=400)
    assert safety.stock(race_variant) == 1
    assert safety.sql('SELECT COUNT(*) FROM orders;') == before_orders
    # Two independently authenticated cashier sessions race for the actual last unit.
    def sell(client):
        return client.request('/api.php?action=pos.sale', payload(race_product, race_variant))
    with ThreadPoolExecutor(2) as pool:
        results = list(pool.map(sell, [seller, other_seller]))
    assert sorted(status for status, _ in results) == [200, 409], 'POS customer validation weakened last-unit locking'
    assert safety.stock(race_variant) == 0
    rows = safety.sql("SELECT COUNT(*),SUM(quantity) FROM order_items WHERE product_id='" + race_product + "';")
    assert rows == '1\t1', 'A rejected concurrent sale wrote order items'
    movement = safety.sql("SELECT COUNT(*),SUM(delta) FROM stock_movements WHERE product_id='" + race_product + "' AND delta<0;")
    assert movement == '1\t-1', 'A rejected concurrent sale wrote a stock movement'
    successful = next(data['data'] for status, data in results if status == 200)
    assert stored(successful['id'])['phone'] == invoice_phone


try:
    seller, seller_user = actor(['pos.create'])
    other_seller, _ = actor(['pos.create'])
    viewer, _ = actor(['orders.view'])
    invoice_phone = phone()
    customer = safety.otp_sign_in(invoice_phone)
    customer_user = customer.bootstrap()['currentUser']
    user_ids.append(customer_user['id'])
    safety.sql("UPDATE users SET name='مشتری مستقل آزمون' WHERE id='" + customer_user['id'] + "';")
    users_before_sales = int(safety.sql('SELECT COUNT(*) FROM users;'))
    main_product, main_variant = product('_main', 30)
    check('first name, last name and mobile are mandatory; legacy anonymous submissions reject atomically', required_identity)
    check('name and mobile types, control characters and exact length bounds reject atomically', bounded_text)
    check('malformed or oversized optional addresses reject without inventory or order changes', invalid_addresses)
    check('missing, null and blank addresses remain optional and persist as SQL NULL', optional_addresses)
    check('Persian and Arabic mobile digits, +98, 0098 and trimmed Persian names persist correctly', normalized_phone_and_name)
    check('full invoice stores normalized identity and optional address, preserves cashier ownership and server totals', full_invoice)
    check('POS permissions and invoice privacy hold; no OTP account is created or renamed', privacy_permissions_and_no_accounts)
    check('invalid identity and simultaneous cashiers cannot oversell or leave partial orders', stock_failure_and_concurrent_sales)
finally:
    # Remove only this run's synthetic rows after the loopback/*_qa guards above.
    if product_ids:
        owned = ','.join("'" + item + "'" for item in product_ids)
        safety.sql('DELETE a FROM audit_logs a JOIN order_items i ON i.order_id=a.target_id WHERE i.product_id IN (' + owned + ');')
        safety.sql('DELETE o FROM orders o JOIN order_items i ON i.order_id=o.id WHERE i.product_id IN (' + owned + ');')
        safety.sql('DELETE FROM stock_movements WHERE product_id IN (' + owned + ');')
        safety.sql('DELETE FROM products WHERE id IN (' + owned + ');')
    for user_id in user_ids:
        safety.sql("DELETE FROM users WHERE id='" + user_id + "';")
    for role_id in role_ids:
        safety.sql("DELETE FROM roles WHERE id='" + role_id + "';")
print(f'POS customer identity scenarios passed: {checks}')

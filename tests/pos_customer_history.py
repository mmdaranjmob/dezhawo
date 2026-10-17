#!/usr/bin/env python3
"""POS purchase history through real HTTP/MariaDB on a disposable *_qa DB only.

Run: python3 tests/pos_customer_history.py --private-dir /path/to/private/qa
Synthetic accounts and orders are removed in finally; no credentials are printed.
"""
import argparse
import importlib.util
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
prefix = 'poshistory_' + secrets.token_hex(5)
user_ids, role_ids, phones = [], [], []
checks = 0


def check(label, fn):
    global checks
    fn()
    checks += 1
    print('PASS', label, flush=True)


def phone():
    value = '09' + ''.join(str(secrets.randbelow(10)) for _ in range(9))
    phones.append(value)
    return value


def actor(permissions):
    role = owner.action('role.save', {'role': {'name': prefix, 'permissions': permissions}})
    role_ids.append(role['id'])
    user = owner.action('user.create', {'name': prefix + ' همکار', 'phone': phone(), 'roleId': role['id']})
    user_ids.append(user['id'])
    return safety.otp_sign_in(user['phone']), user


def customer_account():
    client = safety.otp_sign_in(phone())
    user = client.bootstrap()['currentUser']
    user_ids.append(user['id'])
    return client, user


def order(suffix, mobile, status='completed', payment_status='paid', kind='pos', quantity=None):
    order_id = prefix + '_' + suffix
    safety.sql("INSERT INTO orders (id,number,type,user_id,customer_name,customer_phone,status,payment_status,"
               "subtotal,total,payment) VALUES ('" + order_id + "','" + order_id + "','" + kind + "','" +
               seller_user['id'] + "','private synthetic identity','" + mobile + "','" + status + "','" +
               payment_status + "',1000,1000,'card');")
    if quantity is not None:
        safety.sql("INSERT INTO order_items (order_id,product_id,variant_id,title,variant_name,category,quantity,unit_price,unit_cost) "
                   "VALUES ('" + order_id + "','" + prefix + "_p','" + prefix + "_v','synthetic','black','case'," +
                   str(quantity) + ",1000,100);")
    return order_id


def lookup(mobile, client=None):
    return (client or seller).action('pos.customerLookup', {'phone': mobile})


def snapshot():
    return safety.sql("SELECT CONCAT_WS('|',(SELECT COUNT(*) FROM users),(SELECT COUNT(*) FROM orders),"
                      "(SELECT COUNT(*) FROM order_items),(SELECT COUNT(*) FROM stock_movements),"
                      "(SELECT COUNT(*) FROM audit_logs));")


def protocol_and_permissions():
    guest = qa.Client(args.base)
    guest.bootstrap()
    status, response = guest.request('/api.php?action=pos.customerLookup', {'phone': existing_user['phone']}, csrf=False)
    assert status in (403, 419) and not response['ok'], 'History lookup accepted a request without CSRF'
    status, response = seller.request('/api.php?action=pos.customerLookup')
    assert status == 405 and not response['ok'], 'History lookup accepted GET'
    guest.action('pos.customerLookup', {'phone': existing_user['phone']}, expected=401)
    customer.action('pos.customerLookup', {'phone': existing_user['phone']}, expected=403)
    viewer.action('pos.customerLookup', {'phone': existing_user['phone']}, expected=403)
    # Authorization comes first, including malformed input that must reveal no validation details.
    customer.action('pos.customerLookup', {'phone': []}, expected=403)
    viewer.action('pos.customerLookup', {}, expected=403)
    assert lookup(existing_user['phone']) == {'phone': existing_user['phone'], 'exists': True, 'purchaseCount': 0}


def bounded_validation():
    before = snapshot()
    for invalid in (None, True, 9123456789, [], {}, '', '0912', '02112345678', '09123456789tail',
                    '09123456789' + ' ' * 21, '0912345\n6789', '0912345\t6789',
                    '0912345\x006789', '0912345\x856789', "09123456789' OR 1=1"):
        response = seller.action('pos.customerLookup', {'phone': invalid}, expected=400)
        assert isinstance(response['error'], str) and response['error'], 'Invalid mobile lacks an actionable error'
    seller.action('pos.customerLookup', {}, expected=400)
    assert snapshot() == before, 'Rejected lookup mutated data'


def new_and_registered_customers():
    new_phone = phone()
    before = snapshot()
    assert lookup(new_phone) == {'phone': new_phone, 'exists': False, 'purchaseCount': 0}
    assert lookup(existing_user['phone']) == {'phone': existing_user['phone'], 'exists': True, 'purchaseCount': 0}
    assert snapshot() == before, 'Read-only lookup created an account, order or audit entry'
    assert safety.sql("SELECT COUNT(*) FROM users WHERE phone='" + new_phone + "';") == '0'


def all_channels_and_statuses():
    global history_phone
    history_phone = phone()
    successful = [('paid', 'online'), ('processing', 'online'), ('shipped', 'online'), ('completed', 'pos')]
    for i, (status, kind) in enumerate(successful):
        order('success_' + str(i), history_phone, status=status, kind=kind, quantity=9 if i == 0 else 1)
    for i, (status, payment_status) in enumerate([
        ('awaiting_payment', 'unpaid'), ('awaiting_review', 'pending_review'), ('cancelled', 'paid'),
        ('payment_failed', 'failed'), ('payment_review', 'paid'), ('completed', 'refund_pending'),
        ('processing', 'unpaid'), ('shipped', 'pending_review'), ('completed', 'failed'),
    ]):
        order('excluded_' + str(i), history_phone, status=status, payment_status=payment_status, kind='online')
    before = snapshot()
    assert lookup(history_phone) == {'phone': history_phone, 'exists': True, 'purchaseCount': 4}, \
        'History must count paid successful orders from both channels, not units or rejected payments'
    assert snapshot() == before, 'Successful lookup changed inventory, users, orders or audits'
    assert safety.sql("SELECT COUNT(*) FROM users WHERE phone='" + history_phone + "';") == '0', \
        'Purchase history required a registered customer or silently created one'


def zero_with_unsuccessful_history():
    mobile = phone()
    order('unpaid_only', mobile, status='awaiting_payment', payment_status='unpaid', kind='online')
    assert lookup(mobile) == {'phone': mobile, 'exists': True, 'purchaseCount': 0}, \
        'A historical customer without a successful purchase should have a zero count'
    assert lookup(phone())['purchaseCount'] == 0, 'History leaked between distinct exact mobile numbers'


def normalized_numbers_and_minimal_response():
    persian = str.maketrans('0123456789', '۰۱۲۳۴۵۶۷۸۹')
    arabic = str.maketrans('0123456789', '٠١٢٣٤٥٦٧٨٩')
    formats = [history_phone.translate(persian), ('+98' + history_phone[1:]).translate(arabic),
               '0098 (' + history_phone[1:4] + ') ' + history_phone[4:7] + '-' + history_phone[7:]]
    for raw in formats:
        result = lookup(raw)
        assert result == {'phone': history_phone, 'exists': True, 'purchaseCount': 4}, \
            'Lookup and POS sale do not share Iranian mobile normalization'
        assert set(result) == {'phone', 'exists', 'purchaseCount'}, 'Lookup leaked identity or invoice details'
        assert isinstance(result['exists'], bool) and type(result['purchaseCount']) is int
    assert lookup(history_phone, owner)['purchaseCount'] == 4, 'Owner cannot access POS purchase history'


def entire_history_not_bootstrap_limit():
    mobile = phone()
    values = []
    for i in range(503):
        order_id = prefix + '_bulk_' + str(i)
        values.append("('" + order_id + "','" + order_id + "','pos','" + seller_user['id'] +
                      "','synthetic','" + mobile + "','completed','paid',1000,1000,'cash')")
    safety.sql("INSERT INTO orders (id,number,type,user_id,customer_name,customer_phone,status,payment_status,subtotal,total,payment) VALUES " +
               ','.join(values) + ';')
    assert len(seller.bootstrap()['orders']) == 500, 'Fixture did not exercise the existing order bootstrap limit'
    assert lookup(mobile) == {'phone': mobile, 'exists': True, 'purchaseCount': 503}, \
        'Purchase history was truncated to bootstrap’s recent 500 orders'
    order('bulk_new', mobile, quantity=3)
    assert lookup(mobile)['purchaseCount'] == 504, 'A fresh purchase was not counted once immediately'


try:
    seller, seller_user = actor(['pos.create'])
    viewer, _ = actor(['orders.view'])
    customer, existing_user = customer_account()
    check('POST, CSRF and POS permission protect lookup before validation; POS-only staff can read counts', protocol_and_permissions)
    check('phone types, length, control characters and malformed mobiles reject without data changes', bounded_validation)
    check('new and registered zero-purchase customers are distinguished without creating accounts', new_and_registered_customers)
    check('successful paid orders from POS and online count once; unpaid, cancelled, review and refund states are excluded', all_channels_and_statuses)
    check('existing unsuccessful history returns zero and exact mobile matching isolates customers', zero_with_unsuccessful_history)
    check('Persian, Arabic and international mobiles normalize; response contains only count and existence', normalized_numbers_and_minimal_response)
    check('history exceeds 500 bootstrap orders and includes new invoices immediately', entire_history_not_bootstrap_limit)
finally:
    # All targets are generated alphanumeric IDs after the loopback/*_qa safety guard.
    safety.sql("DELETE FROM orders WHERE id LIKE '" + prefix + "_%';")
    targets = user_ids + role_ids
    if targets:
        safety.sql('DELETE FROM audit_logs WHERE target_id IN (' + ','.join("'" + value + "'" for value in targets) + ');')
    for user_id in user_ids:
        safety.sql("DELETE FROM users WHERE id='" + user_id + "';")
    for role_id in role_ids:
        safety.sql("DELETE FROM roles WHERE id='" + role_id + "';")
    if phones:
        safety.sql('DELETE FROM otp_challenges WHERE phone IN (' + ','.join("'" + value + "'" for value in phones) + ');')
print(f'POS customer history scenarios passed: {checks}')

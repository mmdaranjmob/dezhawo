#!/usr/bin/env python3
"""Category trees over real HTTP and MariaDB, only on the disposable *_qa DB.

Apply database/upgrade-20261016-categories.sql to the disposable DB first.
Run: python3 tests/categories.py --private-dir /path/to/private/qa
Fixtures are removed in finally; no production credentials are read or printed.
"""
import argparse
import importlib.util
import json
from pathlib import Path
import secrets
import subprocess
import urllib.parse

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
prefix = 'catqa_' + secrets.token_hex(5)
category_ids, product_ids, user_ids, role_ids, order_ids = [], [], [], [], []
checks = 0
tree = {}
inventory_before = None


def check(label, fn):
    global checks
    fn()
    checks += 1
    print('PASS', label, flush=True)


def save_category(key, name=None, parent=None, product_type=None, enabled=True, actor=owner,
                  expected=200, **extra):
    category_id = tree.get(key, prefix + '_' + key)
    raw = {'id': category_id, 'name': name or prefix + ' ' + key, 'parentId': parent,
           'enabled': enabled, 'sortOrder': 0, **extra}
    if product_type is not None:
        raw['productType'] = product_type
    result = actor.action('category.save', {'category': raw}, expected=expected)
    if expected == 200 and category_id not in category_ids:
        category_ids.append(category_id)
    return result


def category_map(actor=owner):
    return {row['id']: row for row in actor.bootstrap()['categories']}


def save_product(key, category_id, legacy='case', expected=200):
    product_id = prefix + '_p_' + key
    value = {'id': product_id, 'title': prefix + ' محصول ' + key,
             'category': legacy, 'categoryId': category_id, 'price': 1234567, 'cost': 0,
             'enabled': True, 'badge': '', 'description': 'آزمون دسته‌بندی شاخه‌ای', 'art': 'case',
             'variants': [{'id': product_id + '_v', 'name': 'مشکی', 'color': '#222222',
                           'stock': 9, 'enabled': True}]}
    result = owner.action('product.save', {'product': value}, expected=expected)
    if expected == 200 and product_id not in product_ids:
        product_ids.append(product_id)
    return result


def staff(permissions):
    role = owner.action('role.save', {'role': {'name': prefix + ' نقش', 'permissions': permissions}})
    role_ids.append(role['id'])
    phone = '09' + ''.join(str(secrets.randbelow(10)) for _ in range(9))
    user = owner.action('user.create', {'name': prefix + ' همکار', 'phone': phone, 'roleId': role['id']})
    user_ids.append(user['id'])
    return safety.otp_sign_in(phone)


def migration_roots():
    current = category_map()
    assert {row['legacyKey'] for row in current.values() if row.get('legacyKey')} == {
        'case', 'glass', 'cable', 'charger', 'audio', 'digital'
    }, 'Existing six product groups were not retained in the category migration'
    assert {'categories.view', 'categories.edit'} <= set(owner.bootstrap()['currentUser']['permissions'])
    for row in current.values():
        if row.get('legacyKey'):
            assert row['productType'] == ('digital' if row['legacyKey'] == 'digital' else 'physical')
    assert safety.sql('SELECT COUNT(*) FROM products WHERE category_id IS NULL;') == '0', \
        'Legacy products were not backfilled with a category id'


def create_tree():
    for key, parent, kind in [('root', None, 'physical'), ('samsung', 'root', None),
                              ('iphone', 'root', None), ('galaxy', 'samsung', None),
                              ('digital', None, 'digital'), ('empty', None, 'physical')]:
        parent_id = tree[parent] if parent else None
        result = save_category(key, parent=parent_id, product_type=kind)
        tree[key] = result['id']
    current = category_map()
    assert current[tree['galaxy']]['parentId'] == tree['samsung']
    assert current[tree['samsung']]['parentId'] == tree['root']
    assert current[tree['galaxy']]['productType'] == 'physical'
    assert current[tree['digital']]['productType'] == 'digital'


def edit_tree():
    save_category('samsung', name='سامسونگ ' + prefix, parent=tree['root'], sortOrder=7)
    current = category_map()
    assert current[tree['samsung']]['name'] == 'سامسونگ ' + prefix
    assert current[tree['samsung']]['sortOrder'] == 7
    # Sibling reparenting is legal and preserves the remaining branch.
    save_category('empty', parent=tree['iphone'])
    assert category_map()[tree['empty']]['parentId'] == tree['iphone']
    save_category('empty', product_type='physical')


def rerun_migration():
    statement = (Path(__file__).resolve().parents[1] / 'database/upgrade-20261016-categories.sql').read_text()
    safety.sql(statement)


def migration_preserves_admin_changes():
    assert safety.sql("SELECT value FROM settings WHERE name='categories_initialized';") == 'true'
    candidate = safety.sql("SELECT c.id FROM categories c WHERE c.legacy_key IS NOT NULL "
                           "AND NOT EXISTS(SELECT 1 FROM products p WHERE p.category_id=c.id OR "
                           "(p.category_id IS NULL AND p.category=c.legacy_key)) "
                           "AND NOT EXISTS(SELECT 1 FROM categories child WHERE child.parent_id=c.id) LIMIT 1;")
    assert candidate, 'This disposable fixture needs one unused original category to test deliberate deletion'
    encoded = safety.sql("SELECT JSON_OBJECT('id',id,'name',name,'parent_id',parent_id,'product_type',product_type,"
                         "'legacy_key',legacy_key,'enabled',enabled,'sort_order',sort_order,'created_at',created_at,"
                         "'updated_at',updated_at) FROM categories WHERE id='" + candidate + "';")
    original = json.loads(encoded)
    before = category_map()
    try:
        owner.action('category.delete', {'id': candidate})
        rerun_migration()
        assert candidate not in category_map(), 'Rerunning the migration resurrected an intentionally deleted root'
        assert category_map() == {key: value for key, value in before.items() if key != candidate}, \
            'Migration overwrote manager-created names, display order or category branches'
    finally:
        def sql_value(value):
            if value is None:
                return 'NULL'
            if isinstance(value, int):
                return str(value)
            return 'CONVERT(0x' + str(value).encode().hex() + ' USING utf8mb4)'
        keys = ['id', 'name', 'parent_id', 'product_type', 'legacy_key', 'enabled', 'sort_order', 'created_at', 'updated_at']
        safety.sql('INSERT INTO categories(' + ','.join(keys) + ') VALUES(' +
                   ','.join(sql_value(original[key]) for key in keys) + ');')


def fresh_schema_initialization():
    name = prefix + '_fresh_qa'
    command = ['docker', 'exec', '-i', args.db_container, 'sh', '-c',
               'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" mariadb -u root -N -B']
    schema = (Path(__file__).resolve().parents[1] / 'database/schema.sql').read_text()
    try:
        result = subprocess.run(command, input='CREATE DATABASE `' + name + '`;USE `' + name + '`;\n' + schema +
                                "\nSELECT COUNT(*) FROM categories;SELECT value FROM settings WHERE name='categories_initialized';",
                                text=True, capture_output=True)
        assert result.returncode == 0, 'Fresh disposable schema import failed; database details withheld'
        assert result.stdout.strip().splitlines()[-2:] == ['6', 'true'], \
            'Fresh schema did not initialize the six categories and migration marker'
    finally:
        subprocess.run(command, input='DROP DATABASE IF EXISTS `' + name + '`;', text=True, capture_output=True, check=True)


def invalid_tree():
    before = category_map()
    cases = [({'parent': tree['root']}, 'root'),
             ({'parent': tree['galaxy']}, 'root'),
             ({'parent': prefix + '_missing'}, 'empty'),
             ({'product_type': 'invalid'}, 'empty'),
             ({'parent': tree['digital'], 'product_type': 'physical'}, 'empty'),
             ({'sortOrder': -1}, 'empty'),
             ({'name': ' '}, 'empty')]
    for options, key in cases:
        save_category(key, expected=400, **options)
    assert category_map() == before, 'Rejected category edits changed the tree'


def product_assignment():
    save_product('samsung', tree['galaxy'])
    save_product('iphone', tree['iphone'])
    save_product('digital', tree['digital'], legacy='case')
    products = {row['id']: row for row in owner.bootstrap()['products']}
    assert products[prefix + '_p_samsung']['categoryId'] == tree['galaxy']
    assert products[prefix + '_p_digital']['category'] == 'digital', \
        'Digital child category did not set the physical/digital fulfilment type'
    save_product('missing', prefix + '_missing', expected=400)


def inventory_snapshot():
    return (safety.sql("SELECT id,stock FROM product_variants WHERE product_id LIKE '" + prefix + "%' ORDER BY id;"),
            safety.sql("SELECT COUNT(*) FROM stock_movements WHERE product_id LIKE '" + prefix + "%';"))


def occupied_tree_guards():
    before = category_map()
    owner.action('category.delete', {'id': tree['root']}, expected=409)
    owner.action('category.delete', {'id': tree['galaxy']}, expected=409)
    save_category('root', product_type='digital', expected=409)
    save_category('samsung', parent=tree['digital'], expected=409)
    owner.action('category.save', {'category': {'id': tree['root'].upper(), 'name': 'Case alias',
                 'parentId': None, 'productType': 'digital', 'enabled': True, 'sortOrder': 0}}, expected=400)
    assert category_map() == before, 'Occupied-branch rejection changed the tree'
    assert inventory_snapshot() == inventory_before, 'Category changes modified stock'


def hide_ancestor():
    save_category('samsung', parent=tree['root'], enabled=False)
    public = qa.Client(args.base).bootstrap()
    public_ids = {row['id'] for row in public['categories']}
    products = {row['id'] for row in public['products']}
    assert tree['samsung'] not in public_ids and tree['galaxy'] not in public_ids
    assert prefix + '_p_samsung' not in products and prefix + '_p_iphone' in products
    assert tree['samsung'] in category_map(), 'Staff cannot repair the disabled branch'
    status, _ = qa.Client(args.base).request('/product.php?id=' + urllib.parse.quote(prefix + '_p_samsung'))
    assert status == 404, 'Disabled ancestor product remained public through its direct URL'
    status, sitemap = qa.Client(args.base).request('/sitemap.php')
    assert status == 200 and prefix + '_p_samsung' not in sitemap
    save_product('disabled', tree['galaxy'], expected=400)
    save_category('samsung', parent=tree['root'], enabled=True)
    public = qa.Client(args.base).bootstrap()
    assert prefix + '_p_samsung' in {row['id'] for row in public['products']}
    assert inventory_snapshot() == inventory_before


def reservation_guards():
    phone = '09' + ''.join(str(secrets.randbelow(10)) for _ in range(9))
    customer = safety.otp_sign_in(phone)
    customer_id = customer.bootstrap()['currentUser']['id']
    user_ids.append(customer_id)
    save_category('hidden', product_type='physical', enabled=False)
    hidden_id = prefix + '_hidden'
    product_id = prefix + '_p_samsung'
    customer.action('cart.add', {'productId': product_id, 'variantId': product_id + '_v', 'quantity': 1})
    customer.action('reservation.create')
    try:
        before = category_map()
        save_category('samsung', parent=tree['root'], enabled=False, expected=409)
        save_category('samsung', parent=hidden_id, expected=409)
        assert category_map() == before, 'A reserved branch was partly hidden after rejection'
        assert prefix + '_p_samsung' in {row['id'] for row in qa.Client(args.base).bootstrap()['products']}
    finally:
        customer.action('reservation.release')
    customer.action('reservation.create')
    shipping = next(row['id'] for row in customer.bootstrap()['shipping'] if row['enabled'])
    pending = customer.action('order.checkout', {
        'customer': {'name': prefix + ' خریدار', 'phone': phone, 'telegramUsername': ''},
        'address': {'province': 'تهران', 'city': 'تهران', 'line': 'خیابان آزمون پلاک ۱', 'postalCode': '1234567890'},
        'shippingId': shipping,
    })
    order_ids.append(pending['orderId'])
    assert safety.sql("SELECT r.status FROM reservations r JOIN orders o ON o.reservation_id=r.id WHERE o.id='" +
                      pending['orderId'] + "';") == 'payment_pending'
    save_category('samsung', parent=tree['root'], enabled=False, expected=409)
    save_category('samsung', parent=hidden_id, expected=409)
    owner.action('order.status', {'id': pending['orderId'], 'status': 'cancelled'})
    save_category('samsung', parent=hidden_id)
    assert prefix + '_p_samsung' not in {row['id'] for row in qa.Client(args.base).bootstrap()['products']}
    save_category('samsung', parent=tree['root'])
    customer.action('reservation.create')
    safety.sql("UPDATE reservations SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE user_id='" +
               customer_id + "' AND status='active';")
    save_category('samsung', parent=tree['root'], enabled=False)
    save_category('samsung', parent=tree['root'])
    customer.action('reservation.release')
    owner.action('category.delete', {'id': hidden_id})
    category_ids.remove(hidden_id)
    assert inventory_snapshot() == inventory_before, 'Reservation guards changed physical stock'


def permissions():
    viewer = staff(['categories.view'])
    assert tree['root'] in category_map(viewer)
    save_category('denied', actor=viewer, expected=403)
    viewer.action('category.delete', {'id': tree['empty']}, expected=403)
    editor = staff(['categories.edit'])
    save_category('editor', parent=tree['root'], actor=editor)
    editor.action('category.delete', {'id': prefix + '_editor'})
    category_ids.remove(prefix + '_editor')
    product_editor = staff(['products.edit'])
    save_category('denied', actor=product_editor, expected=403)
    guest = qa.Client(args.base)
    guest.bootstrap()
    save_category('denied', actor=guest, expected=401)
    guest.action('category.delete', {'id': tree['empty']}, expected=401)


def delete_empty():
    owner.action('category.delete', {'id': tree['empty']})
    category_ids.remove(tree['empty'])
    assert tree['empty'] not in category_map()
    owner.action('category.delete', {'id': tree['empty']}, expected=404)
    assert inventory_snapshot() == inventory_before


try:
    check('migration retains legacy groups, product assignments and owner permissions', migration_roots)
    check('manager creates an arbitrary nested physical tree and a digital root', create_tree)
    check('names, display order and compatible branch moves persist', edit_tree)
    check('reapplying the migration preserves custom categories and deliberate root deletion', migration_preserves_admin_changes)
    check('a fresh disposable database imports its categories and initialization marker', fresh_schema_initialization)
    check('cycles, missing parents, incompatible type and invalid fields reject atomically', invalid_tree)
    check('products persist leaf assignment and digital fulfilment semantics', product_assignment)
    inventory_before = inventory_snapshot()
    check('occupied branches cannot be deleted or moved between physical and digital', occupied_tree_guards)
    check('disabling an ancestor hides descendants, direct product pages and sitemap entries', hide_ancestor)
    check('active/payment-pending reservations block hiding while released or expired reservations do not', reservation_guards)
    check('category permissions remain separate from product editing and public visitors', permissions)
    check('empty categories can be deleted while all inventory remains unchanged', delete_empty)
finally:
    # The safety helper already refuses non-loopback hosts and non-*_qa DBs.
    # Delete only this run's unreferenced synthetic fixtures, deepest rows first.
    for order_id in order_ids:
        safety.sql("DELETE FROM payment_attempts WHERE order_id='" + order_id + "';")
        safety.sql("DELETE FROM orders WHERE id='" + order_id + "';")
    if product_ids:
        safety.sql("DELETE FROM products WHERE id IN (" + ','.join("'" + item + "'" for item in product_ids) + ');')
    if category_ids:
        owned = ','.join("'" + item + "'" for item in category_ids)
        safety.sql('UPDATE categories SET parent_id=NULL WHERE id IN (' + owned + ');')
        safety.sql('DELETE FROM categories WHERE id IN (' + owned + ');')
    for user_id in user_ids:
        safety.sql("DELETE FROM reservations WHERE user_id='" + user_id + "';")
        safety.sql("DELETE FROM users WHERE id='" + user_id + "';")
    for role_id in role_ids:
        safety.sql("DELETE FROM roles WHERE id='" + role_id + "';")
print(f'Category hierarchy scenarios passed: {checks}')

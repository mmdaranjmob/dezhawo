#!/usr/bin/env python3
"""Homepage product settings over real HTTP, only on a disposable *_qa database."""
import argparse
import importlib.util
import json
import secrets
from pathlib import Path

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
baseline = owner.bootstrap()['settings']
prefix = 'heroqa_' + secrets.token_hex(5)
ids = [prefix + '_' + key for key in ('a', 'b', 'inactive', 'no_variant')]
ids += [prefix + '_extra_' + str(i) for i in range(8)]
eligible_ids = [ids[0], ids[1], *ids[4:]]
checks = 0
inventory_before = None


def check(label, fn):
    global checks
    fn()
    checks += 1
    print('PASS', label, flush=True)


def save(value, actor=owner, expected=200, **extra):
    return actor.action('settings.save', {'settings': {'heroProductIds': value, **extra}}, expected=expected)


def staff(permissions):
    role_id = owner.action('role.save', {'role': {'name': prefix, 'permissions': permissions}})['id']
    phone = '09' + ''.join(str(secrets.randbelow(10)) for _ in range(9))
    owner.action('user.create', {'name': prefix, 'phone': phone, 'roleId': role_id})
    return safety.otp_sign_in(phone)


def round_trip():
    save([ids[1], ids[0]])
    public = qa.Client(args.base).bootstrap()['settings']
    assert public['heroProductIds'] == [ids[1], ids[0]], 'Ordered selection did not survive public bootstrap'
    assert {key: value for key, value in public.items() if key != 'heroProductIds'} == {
        key: value for key, value in baseline.items() if key != 'heroProductIds'
    }, 'Unrelated storefront settings changed'


def invalid_input():
    before = owner.bootstrap()['settings']
    for value in (None, True, ids[0], {'first': ids[0]}, [7], [''], [' ' + ids[0]],
                  [ids[0], ids[0]], eligible_ids, [prefix + '_missing'], [ids[2]], [ids[3]]):
        save(value, expected=400)
    assert owner.bootstrap()['settings'] == before, 'Rejected settings changed persisted values'


def atomic_rejection():
    before = owner.bootstrap()['settings']
    save([ids[2]], expected=400, notice=prefix)
    assert owner.bootstrap()['settings'] == before, 'Invalid selection partially applied unrelated fields'


def settings_permission():
    editor = staff(['settings.edit'])
    save([ids[0]], actor=editor)
    assert editor.bootstrap()['settings']['heroProductIds'] == [ids[0]]
    save([ids[1]], actor=editor, expected=403, notice=prefix)
    assert owner.bootstrap()['settings']['heroProductIds'] == [ids[0]], 'Mixed unauthorized update was partly saved'


def appearance_permission():
    appearance = staff(['appearance.edit'])
    save([ids[1]], actor=appearance, expected=403)
    appearance.action('settings.save', {'settings': {'notice': baseline['notice']}})
    assert owner.bootstrap()['settings']['heroProductIds'] == [ids[0]], 'Appearance editor changed product selection'


def guest_permission():
    guest = qa.Client(args.base)
    guest.bootstrap()
    save([], actor=guest, expected=401)


def disable_after_selection():
    owner.action('product.enabled', {'id': ids[0], 'enabled': False})
    save([ids[0]], expected=400)
    save([ids[1]])
    public = qa.Client(args.base).bootstrap()
    assert public['settings']['heroProductIds'] == [ids[1]]
    assert ids[0] not in [product['id'] for product in public['products']], 'Disabled product was exposed publicly'


def reset_design():
    save([])
    assert qa.Client(args.base).bootstrap()['settings']['heroProductIds'] == []


def maximum_selection():
    selected = list(reversed(eligible_ids[:9]))
    save(selected)
    assert qa.Client(args.base).bootstrap()['settings']['heroProductIds'] == selected


def inventory_snapshot():
    return (safety.sql("SELECT id,stock FROM product_variants WHERE product_id LIKE '" + prefix +
                       "%' ORDER BY id;"),
            safety.sql("SELECT COUNT(*) FROM stock_movements WHERE product_id LIKE '" + prefix + "%';"))


def inventory_unchanged():
    assert inventory_snapshot() == inventory_before, 'Homepage settings changed stock or stock movements'


try:
    for index, product_id in enumerate(ids):
        owner.action('product.save', {'product': {
            'id': product_id, 'title': prefix + ' محصول', 'category': 'case', 'price': 500,
            'cost': 0, 'art': 'case', 'description': '', 'badge': '', 'enabled': index != 2,
            'variants': [{'id': product_id + '_v', 'name': 'مشکی', 'color': '#222222',
                          'stock': 2 if index == 0 else 0, 'enabled': index != 3}],
        }})
    inventory_before = inventory_snapshot()
    check('ordered selection includes out-of-stock public products and preserves CMS fields', round_trip)
    check('malformed, duplicate, excessive, unknown and inactive selections are rejected', invalid_input)
    check('nine different products retain their selected display order', maximum_selection)
    check('invalid product selection cannot partially save unrelated settings', atomic_rejection)
    check('settings editors can select products without appearance or inventory permissions', settings_permission)
    check('appearance permission cannot change homepage products', appearance_permission)
    check('guest cannot change homepage settings', guest_permission)
    check('disabled selected products remain private and can be replaced', disable_after_selection)
    check('empty selection restores the initial decorative design', reset_design)
    check('homepage selection never changes inventory or stock movements', inventory_unchanged)
finally:
    # Restore the exact prior setting, even if a pre-existing choice is inactive.
    encoded = json.dumps(baseline.get('heroProductIds', []), ensure_ascii=False).encode().hex()
    safety.sql("INSERT INTO settings(name,value,updated_at) VALUES('heroProductIds',CONVERT(0x" + encoded +
               " USING utf8mb4),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE value=VALUES(value);")
    for product_id in ids:
        owner.action('product.enabled', {'id': product_id, 'enabled': False})
print(f'Homepage product settings scenarios passed: {checks}')

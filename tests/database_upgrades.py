#!/usr/bin/env python3
"""Exercise real database upgrades against a new, disposable *_qa database.

Uses the existing local Docker MariaDB and PHP QA image, but creates its own
database, restricted DB user, generated configuration, OTP outbox and HTTP
container. Never reads production credentials or mutates the usual QA database.
All isolated resources are removed in finally, including after a failed check.
"""
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
import argparse
import base64
import hashlib
import importlib.util
import json
import re
import secrets
import shutil
import subprocess
import tempfile
import time
import urllib.parse

spec = importlib.util.spec_from_file_location('upgrade_http', Path(__file__).with_name('integration.py'))
http = importlib.util.module_from_spec(spec)
spec.loader.exec_module(http)


class UpgradeChecks:
    def __init__(self, args):
        self.args = args
        self.repository = Path(__file__).resolve().parents[1]
        token = secrets.token_hex(6)
        self.database = self.user = 'upgrade_' + token + '_qa'
        self.container = 'dezhawo-upgrade-' + token
        self.password = secrets.token_hex(32)
        self.private = Path(tempfile.mkdtemp(prefix='dezhawo-upgrade-qa-'))
        self.private.chmod(0o700)
        (self.private / 'outbox').mkdir(mode=0o700)
        self.base = 'http://127.0.0.1:' + str(args.port)
        self.owner_phone, self.staff_phone, self.customer_phone = '09121112233', '09121112234', '09121112235'
        self.count = 0
        self.created = False

    def command(self, arguments, input=None):
        result = subprocess.run(arguments, input=input, text=True, capture_output=True)
        assert result.returncode == 0, 'Isolated QA operation failed; private details withheld.'
        return result.stdout.strip()

    def sql(self, statement, database=True):
        assert re.fullmatch(r'upgrade_[a-f0-9]{12}_qa', self.database), 'Only isolated QA databases are allowed.'
        shell = 'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" mariadb -u root -N -B'
        arguments = ['docker', 'exec', '-i', self.args.db_container, 'sh', '-c', shell + (' --database=' + self.database if database else '')]
        return self.command(arguments, statement)

    def configure(self, env='testing'):
        values = {'app': {'env': env, 'base_url': self.base, 'key': self.password,
                          'installation_key': secrets.token_hex(32), 'timezone': 'Asia/Tehran'},
                  'database': {'host': self.args.db_container, 'port': 3306, 'name': self.database,
                               'user': self.user, 'password': self.password, 'charset': 'utf8mb4'},
                  'session': {'name': self.database, 'secure': False},
                  'sms': {'provider': 'kavenegar', 'api_key': '', 'sender': ''},
                  'temporary_login': {'enabled': False, 'phone': '', 'password_hash': ''},
                  'payment': {'provider': 'zarinpal', 'merchant_id': '', 'sandbox': True},
                  'testing': {'otp_outbox': '/qa/outbox', 'payment_stub': True}}
        encoded = base64.b64encode(json.dumps(values).encode()).decode()
        target = self.private / 'config.php'
        target.write_text("<?php\nreturn json_decode(base64_decode('" + encoded + "'), true);\n")
        target.chmod(0o600)

    def start(self):
        self.sql('CREATE DATABASE ' + self.database + ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
                 + "CREATE USER '" + self.user + "'@'%' IDENTIFIED BY '" + self.password + "';"
                 + 'GRANT ALL PRIVILEGES ON ' + self.database + ".* TO '" + self.user + "'@'%';", False)
        self.created = True
        self.sql((self.repository / 'database/schema.sql').read_text())
        self.sql("ALTER TABLE products DROP FOREIGN KEY fk_products_category, DROP KEY idx_products_category_id, DROP COLUMN category_id, DROP COLUMN image_url;"
                 "DROP TABLE categories;DELETE FROM settings WHERE name='categories_initialized';"
                 "DELETE FROM role_permissions WHERE permission IN ('categories.view','categories.edit');"
                 "ALTER TABLE payment_attempts DROP COLUMN provider, DROP COLUMN metadata_json;"
                 "INSERT INTO roles(id,name,protected) VALUES('upgrade_staff','کارمند تنظیمات',0);"
                 "INSERT INTO role_permissions(role_id,permission) VALUES('upgrade_staff','settings.edit'),('upgrade_staff','roles.edit');"
                 "INSERT INTO users(id,name,phone,role_id) VALUES('upgrade_owner','مدیر آزمون','" + self.owner_phone + "','owner'),"
                 "('upgrade_staff','کارمند آزمون','" + self.staff_phone + "','upgrade_staff'),"
                 "('upgrade_customer','مشتری آزمون','" + self.customer_phone + "','customer');"
                 "INSERT INTO products(id,title,category,description,price,cost,enabled) VALUES"
                 "('upgrade_case','قاب حفظ اطلاعات','case','شرح اصلی پیش از ارتقا',321000,123000,1),"
                 "('upgrade_digital','اشتراک حفظ اطلاعات','digital','اشتراک اصلی',654000,234000,1);"
                 "INSERT INTO product_variants(id,product_id,name,color,stock,price,enabled) VALUES"
                 "('upgrade_case_v','upgrade_case','مشکی','#111111',17,345000,1),"
                 "('upgrade_digital_v','upgrade_digital','یک ماه','#333333',0,NULL,1);"
                 "INSERT INTO orders(id,number,type,user_id,customer_name,customer_phone,status,payment_status,subtotal,total,payment) VALUES"
                 "('upgrade_order','UPGRADE-ORDER','web','upgrade_customer','مشتری آزمون','" + self.customer_phone + "','processing','paid',345000,345000,'zarinpal');"
                 "INSERT INTO order_items(order_id,product_id,variant_id,title,variant_name,category,quantity,unit_price,unit_cost) VALUES"
                 "('upgrade_order','upgrade_case','upgrade_case_v','نام زمان خرید','مشکی','case',1,345000,123000);"
                 "INSERT INTO payment_attempts(id,order_id,authority,amount_rial,status,reference_id) VALUES"
                 "('upgrade_payment','upgrade_order','upgrade-authority',3450000,'verified','upgrade-reference');"
                 "INSERT INTO reservations(id,cart_key,user_id,status,expires_at) VALUES"
                 "('upgrade_reservation',REPEAT('a',64),'upgrade_customer','active',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 HOUR));"
                 "INSERT INTO reservation_items(reservation_id,product_id,variant_id,quantity,unit_price,unit_cost) VALUES"
                 "('upgrade_reservation','upgrade_case','upgrade_case_v',2,345000,123000);"
                 "INSERT INTO settings(name,value) VALUES('upgrade_custom','{\"preserve\":true}'),('installation_complete','true');"
                 "UPDATE settings SET value='\"نام فروشگاه پیش از ارتقا\"' WHERE name='brand';")
        self.original = self.snapshot()
        self.original_permissions = self.sql("SELECT role_id,permission FROM role_permissions ORDER BY role_id,permission;")
        self.configure()
        self.command(['docker', 'run', '-d', '--name', self.container, '--network', self.args.network,
                      '-p', '127.0.0.1:' + str(self.args.port) + ':8080',
                      '-v', str(self.repository) + ':/var/www/html:ro', '-v', str(self.private) + ':/qa',
                      '-e', 'DEZHAWO_CONFIG=/qa/config.php', '-e', 'PHP_CLI_SERVER_WORKERS=4', self.args.php_image])
        self.owner = http.Client(self.base)
        for attempt in range(40):
            try:
                if self.owner.request('/api.php?action=bootstrap')[0] == 200:
                    return
            except OSError:
                pass
            time.sleep(.15)
        raise AssertionError('Isolated upgrade HTTP server did not become ready.')

    def snapshot(self):
        statements = {
            'products': 'SELECT id,title,category,badge,description,art,price,cost,enabled,created_at,updated_at FROM products ORDER BY id;',
            'variants': 'SELECT * FROM product_variants ORDER BY id;',
            'orders': 'SELECT * FROM orders ORDER BY id;',
            'order_items': 'SELECT * FROM order_items ORDER BY id;',
            'payments': 'SELECT id,order_id,authority,amount_rial,status,reference_id,created_at,updated_at FROM payment_attempts ORDER BY id;',
            'reservations': 'SELECT * FROM reservations ORDER BY id;',
            'reservation_items': 'SELECT * FROM reservation_items ORDER BY reservation_id,variant_id;',
            'settings': "SELECT name,value,updated_at FROM settings WHERE name NOT IN ('categories_initialized') AND name NOT LIKE 'database_upgrade%' AND name NOT LIKE 'schema_%' ORDER BY name;",
            'users': 'SELECT * FROM users ORDER BY id;',
        }
        return {name: self.sql(statement) for name, statement in statements.items()}

    def structure(self):
        return self.sql("SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COALESCE(COLUMN_DEFAULT,'<NULL>') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION;")

    def login(self, phone):
        client = http.Client(self.base)
        client.bootstrap()
        before = set((self.private / 'outbox').glob('*.json'))
        result = client.action('auth.requestOtp', {'phone': phone})
        assert 'code' not in json.dumps(result), 'OTP leaked in response.'
        paths = set((self.private / 'outbox').glob('*.json')) - before
        assert len(paths) == 1, 'Isolated OTP outbox did not receive one message.'
        message_path = paths.pop()
        assert re.fullmatch(r'otp_[a-f0-9]+\.json', message_path.name), 'Unexpected isolated OTP fixture path.'
        # PHP writes this private file as the container user with mode 0600.
        message = self.command(['docker', 'exec', self.container, 'cat', '/qa/outbox/' + message_path.name])
        code = json.loads(message)['code']
        client.action('auth.verifyOtp', {'phone': phone, 'code': code})
        return client

    def read_only_detection(self):
        original_structure = self.structure()
        guest = http.Client(self.base)
        for _ in range(3):
            state = guest.bootstrap()
            assert state['configured'] is True, 'Legacy schema blocked login bootstrap.'
            assert 'databaseUpgrade' not in state, 'Guest received owner migration details.'
            assert state['capabilities']['databaseUpgradeRequired'] is True
        assert original_structure == self.structure(), 'GET bootstrap performed schema changes.'
        assert self.snapshot() == self.original, 'Read-only detection changed store data.'

    def authorization(self):
        self.owner = self.login(self.owner_phone)
        state = self.owner.bootstrap()
        assert state['currentUser']['roleId'] == 'owner', 'Owner cannot log in before schema recovery.'
        status = state['databaseUpgrade']
        assert status['required'] is True and status['recovery'] is True and len(status['pending']) == 3
        assert all(set(item) >= {'id', 'label'} for item in status['pending'])
        self.pending_ids = {item['id'] for item in status['pending']}
        for client, expected in [(http.Client(self.base), 401), (self.login(self.staff_phone), 403),
                                 (self.login(self.customer_phone), 403)]:
            boot = client.bootstrap()
            assert 'databaseUpgrade' not in boot, 'Non-owner received migration list.'
            client.action('database.upgrade', {}, expected=expected)
        assert self.owner.request('/api.php?action=database.upgrade', {}, csrf=False)[0] == 419
        assert self.owner.request('/api.php?action=database.upgrade')[0] == 405
        before = self.structure()
        self.configure('production')
        try:
            assert self.owner.request('/api.php?action=database.upgrade', {})[0] == 426, 'Production upgrade allowed HTTP.'
        finally:
            self.configure()
        assert before == self.structure(), 'Rejected upgrades changed the schema.'

    def partial_failure(self):
        self.sql('REVOKE CREATE ON ' + self.database + ".* FROM '" + self.user + "'@'%';", False)
        try:
            status, result = self.owner.request('/api.php?action=database.upgrade', {})
            assert status >= 400 and result.get('ok') is False, 'Missing DB privilege did not report a failed upgrade.'
            error = result.get('error', '')
            assert error and all(secret not in error for secret in [self.password, self.database, self.user]), 'Upgrade error leaked connection details.'
        finally:
            self.sql('GRANT CREATE ON ' + self.database + ".* TO '" + self.user + "'@'%';", False)
        boot = self.owner.bootstrap()
        pending = boot['databaseUpgrade']['pending']
        assert boot['databaseUpgrade']['required'] is True
        assert 0 < len(pending) < len(self.pending_ids), 'Completed migration steps did not survive a later failed step.'
        assert self.snapshot() == self.original, 'Partial upgrade changed existing commerce data.'

    def finish_and_preserve(self):
        self.owner.action('database.upgrade', {})
        state = self.owner.bootstrap()
        assert state['configured'] and state['databaseUpgrade'] == {'required': False, 'pending': [], 'recovery': False}
        assert state['capabilities']['categoriesReady'] is True
        product = next(row for row in state['products'] if row['id'] == 'upgrade_case')
        digital = next(row for row in state['products'] if row['id'] == 'upgrade_digital')
        assert product['categoryId'] == 'case' and digital['categoryId'] == 'digital'
        assert product['variants'][0]['stock'] == 17 and product['variants'][0]['available'] == 15
        assert self.sql("SELECT provider,metadata_json IS NULL FROM payment_attempts WHERE id='upgrade_payment';") == 'zarinpal\t1'
        assert self.sql('SELECT COUNT(*) FROM products WHERE image_url IS NULL;') == '2'
        assert self.sql("SELECT value FROM settings WHERE name='categories_initialized';") == 'true'
        assert self.snapshot() == self.original, 'Upgrade changed existing products, inventory, orders, accounts or settings.'
        permissions = self.sql("SELECT role_id,permission FROM role_permissions WHERE permission NOT IN ('categories.view','categories.edit') ORDER BY role_id,permission;")
        assert permissions == self.original_permissions, 'Upgrade changed existing role grants.'
        assert self.sql("SELECT role_id,permission FROM role_permissions WHERE permission IN ('categories.view','categories.edit') ORDER BY role_id,permission;") == 'owner\tcategories.edit\nowner\tcategories.view'

    def interrupted_category_stage(self):
        self.sql("ALTER TABLE products DROP FOREIGN KEY fk_products_category, DROP KEY idx_products_category_id;"
                 "DELETE FROM role_permissions WHERE role_id='owner' AND permission='categories.edit';")
        before = self.snapshot()
        state = self.owner.bootstrap()
        assert state['databaseUpgrade']['required'] is True
        assert len(state['databaseUpgrade']['pending']) == 1 and state['databaseUpgrade']['recovery'] is False
        assert any(row['id'] == 'upgrade_case' for row in state['products']), 'Category-only upgrade hid a working legacy catalog.'
        self.owner.action('database.upgrade', {})
        assert self.owner.bootstrap()['databaseUpgrade']['required'] is False
        assert self.snapshot() == before, 'Resumed category stage changed store data.'

    def idempotent_and_deleted_root(self):
        self.sql("DELETE FROM categories WHERE id='glass';")
        before = self.snapshot()
        structure = self.structure()
        for _ in range(2):
            self.owner.action('database.upgrade', {})
            assert self.owner.bootstrap()['databaseUpgrade']['required'] is False
        assert self.sql("SELECT COUNT(*) FROM categories WHERE id='glass';") == '0', 'Repeat upgrade resurrected a deliberately deleted root.'
        assert before == self.snapshot() and structure == self.structure(), 'Repeated upgrade was not idempotent.'
        status, _ = self.owner.request('/api.php?action=database.upgrade', {'sql': 'DROP TABLE products', 'file': '/etc/passwd'})
        assert status in (200, 400), 'Unexpected arbitrary upgrade payload response.'
        assert before == self.snapshot(), 'Client-supplied upgrade instructions were executed.'

    def concurrent(self):
        # Separate owner sessions avoid PHP session locking hiding an unsafe race.
        self.sql('DELETE FROM rate_limits;')
        owners = [self.owner, self.login(self.owner_phone)]
        self.sql('ALTER TABLE products DROP COLUMN image_url;')
        before = self.snapshot()
        structure = self.structure()
        lock_name = 'dezhawo.upgrade.' + hashlib.sha256(self.database.encode()).hexdigest()[:40]
        script = "require '/var/www/html/app/bootstrap.php';$pdo=db();$q=$pdo->query(\"SELECT GET_LOCK('" + lock_name + "',0)\");echo $q->fetchColumn().PHP_EOL;flush();fgets(STDIN);$pdo->query(\"SELECT RELEASE_LOCK('" + lock_name + "')\");"
        holder = subprocess.Popen(['docker', 'exec', '-i', self.container, 'php', '-r', script],
                                  stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        try:
            assert holder.stdout.readline().strip() == '1', 'Could not hold isolated migration lock.'
            self.owner.action('database.upgrade', {}, expected=409)
            assert structure == self.structure(), 'Contending migration ignored the database lock.'
        finally:
            holder.communicate('\n', timeout=10)
        with ThreadPoolExecutor(max_workers=2) as pool:
            results = list(pool.map(lambda client: client.request('/api.php?action=database.upgrade', {}), owners))
        assert all(status in (200, 409) for status, _ in results), 'Concurrent upgrade failed with a duplicate-column/server error.'
        assert any(status == 200 for status, _ in results)
        assert self.owner.bootstrap()['databaseUpgrade']['required'] is False
        assert self.snapshot() == before, 'Concurrent upgrade changed merchant data.'

    def cleanup(self):
        subprocess.run(['docker', 'rm', '-f', self.container], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        try:
            if self.created:
                self.sql('DROP DATABASE IF EXISTS ' + self.database + ";DROP USER IF EXISTS '" + self.user + "'@'%';", False)
        finally:
            shutil.rmtree(self.private, ignore_errors=True)

    def run(self):
        try:
            self.start()
            for title, check in [
                ('legacy bootstrap stays usable and never performs DDL on GET', self.read_only_detection),
                ('owner OTP recovery works; guests, staff, CSRF, GET and production HTTP are rejected', self.authorization),
                ('a later DDL failure reports safely and retains completed steps', self.partial_failure),
                ('one-click completion preserves products, orders, reservations, settings and existing grants', self.finish_and_preserve),
                ('an interrupted category index/grant stage resumes without hiding or changing catalog data', self.interrupted_category_stage),
                ('repeat upgrades preserve deleted roots and ignore arbitrary SQL or file payloads', self.idempotent_and_deleted_root),
                ('separate owner sessions can race safely without duplicate-column errors', self.concurrent),
            ]:
                check()
                self.count += 1
                print('PASS ' + title, flush=True)
            print(f'All {self.count} isolated database upgrade checks passed.')
        finally:
            self.cleanup()


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--db-container', default='dezhawo-qa-db')
    parser.add_argument('--network', default='dezhawo-qa-net')
    parser.add_argument('--php-image', default='dezhawo-php-qa:8.3')
    parser.add_argument('--port', type=int, default=8082)
    arguments = parser.parse_args()
    assert 1024 <= arguments.port <= 65535
    UpgradeChecks(arguments).run()

#!/usr/bin/env python3
"""Temporary owner login checks against disposable loopback *_qa data only.

Uses a generated phone/password, not production credentials. The private QA
configuration and provisioning markers are restored even if a check fails.
Run this suite sequentially with other tests that change the QA configuration.
"""
from pathlib import Path
import argparse
import base64
import importlib.util
import json
import secrets
import subprocess
import urllib.parse

spec = importlib.util.spec_from_file_location('qa_base', Path(__file__).with_name('integration.py'))
qa = importlib.util.module_from_spec(spec)
spec.loader.exec_module(qa)


class TemporaryLogin(qa.Checks):
    def __init__(self, args):
        super().__init__(args)
        self.path = self.private / 'qa-config.php'
        self.original = self.path.read_text()
        self.phone = '09' + ''.join(str(secrets.randbelow(10)) for _ in range(9))
        self.password = secrets.token_hex(12)
        self.password_hash = self.hash_password(self.password)
        self.markers = self.sql("SELECT name,HEX(value) FROM settings WHERE name IN ('temporary_owner_provisioned','installation_complete');")
        self.original_owner_count = int(self.sql("SELECT COUNT(*) FROM users WHERE role_id='owner';"))
        self.user_id = None

    def hash_password(self, value):
        result = subprocess.run(['docker', 'exec', '-i', self.args.web_container, 'php', '-r',
                                 'echo password_hash(stream_get_contents(STDIN), PASSWORD_DEFAULT);'],
                                input=value, text=True, capture_output=True)
        assert result.returncode == 0 and len(result.stdout) >= 60, 'QA password hashing failed'
        return result.stdout

    def configure(self, **changes):
        values = dict(enabled=True, phone=self.phone, password_hash=self.password_hash)
        values.update(changes)
        encoded = base64.b64encode(json.dumps(values).encode()).decode()
        assert self.original.startswith('<?php')
        self.path.write_text('<?php\n$configuration=(static function(){\n'
                             + self.original[len('<?php'):] + '\n})();\n'
                             + "$configuration['temporary_login']=json_decode(base64_decode('" + encoded + "'),true);\n"
                             + 'return $configuration;\n')

    def client(self):
        client = qa.Client(self.args.base)
        client.bootstrap()
        return client

    def login(self, client=None, expected=200, **changes):
        client = client or self.client()
        client.action('auth.temporaryLogin', dict(phone=self.phone, password=self.password, **changes), expected=expected)
        return client

    def privacy(self):
        self.configure()
        self.sql("DELETE FROM settings WHERE name='temporary_owner_provisioned';")
        boot = self.client().bootstrap()
        assert boot['capabilities']['temporaryLoginEnabled'] is True
        serialized = json.dumps(boot)
        assert self.phone not in serialized and self.password_hash not in serialized
        assert 'temporary_login' not in serialized and 'password_hash' not in serialized

    def rejected(self):
        wrong_phone = '09' + ''.join(str(secrets.randbelow(10)) for _ in range(9))
        client = self.client()
        first = client.action('auth.temporaryLogin', {'phone': wrong_phone, 'password': self.password}, expected=401)
        second = client.action('auth.temporaryLogin', {'phone': self.phone, 'password': 'incorrect-fixture'}, expected=401)
        assert first['error'] == second['error'], 'rejection reveals configured phone'
        assert not client.bootstrap()['currentUser']
        assert self.sql("SELECT COUNT(*) FROM users WHERE phone='" + self.phone + "';") == '0'
        status, _ = client.request('/api.php?action=auth.temporaryLogin', {'phone': self.phone, 'password': self.password}, csrf=False)
        assert status == 419, 'temporary login accepted without CSRF'
        status, _ = client.request('/api.php?action=auth.temporaryLogin')
        assert status == 405, 'temporary login accepted through GET'

    def provision(self):
        self.user = self.login()
        user = self.user.bootstrap()['currentUser']
        self.user_id = user['id']
        assert user['roleId'] == 'owner' and 'roles.edit' in user['permissions']
        assert int(self.sql("SELECT COUNT(*) FROM users WHERE role_id='owner';")) == self.original_owner_count + 1
        assert self.sql("SELECT value FROM settings WHERE name='temporary_owner_provisioned';") == 'true'
        assert self.sql("SELECT value FROM settings WHERE name='installation_complete';") == 'true'
        assert self.sql("SELECT password_hash IS NULL FROM users WHERE id='" + self.user_id + "';") == '1'
        assert self.user.request('/install.php')[0] == 409
        self.user.action('auth.logout', {})
        assert not self.user.bootstrap()['currentUser']
        self.sql('DELETE FROM rate_limits;')
        self.login(self.user)
        assert self.user.bootstrap()['currentUser']['id'] == self.user_id, 'repeat login duplicated account'

    def no_repromotion(self):
        self.sql("UPDATE users SET role_id='customer' WHERE id='" + self.user_id + "';")
        assert not self.user.bootstrap()['currentUser'], 'role change failed to revoke session'
        self.login(self.user)
        user = self.user.bootstrap()['currentUser']
        assert user['roleId'] == 'customer' and not user['permissions'], 'repeat temporary login restored owner'
        self.sql("UPDATE users SET role_id='owner' WHERE id='" + self.user_id + "';")
        self.sql('DELETE FROM rate_limits;')
        self.login(self.user)

    def payment_callback(self):
        product, variant = self.prefix + '_temporary_payment', self.prefix + '_temporary_variant'
        self.user.action('product.save', {'product': {
            'id': product, 'title': 'اشتراک آزمون ورود موقت', 'category': 'digital',
            'description': 'فقط آزمون مستقل بازگشت پرداخت', 'price': 120000, 'cost': 0,
            'enabled': True, 'variants': [{'id': variant, 'name': 'یک ماه', 'color': '#111111',
                                        'stock': 0, 'enabled': True, 'price': None}]}})
        self.customer_phone = self.phone
        order = self.checkout(self.user, product, variant, digital=True)
        callback = urllib.parse.urlsplit(order['redirectUrl'])
        status, _ = self.user.request(callback.path + '?' + callback.query)
        assert status == 200, 'temporary owner payment callback failed'
        boot = self.user.bootstrap()
        assert boot['currentUser'] and boot['currentUser']['id'] == self.user_id, 'payment callback revoked temporary login'
        saved = next(row for row in boot['orders'] if row['id'] == order['orderId'])
        assert saved['paymentStatus'] == 'paid', 'testing payment was not verified'
        assert not boot['cart'], 'verified temporary-owner purchase retained cart snapshot'

    def disabled(self):
        self.sql("UPDATE users SET enabled=0 WHERE id='" + self.user_id + "';")
        assert not self.user.bootstrap()['currentUser'], 'disabled user retained temporary session'
        self.login(self.user, expected=401)
        self.sql("UPDATE users SET enabled=1 WHERE id='" + self.user_id + "';")
        self.login(self.user)

    def config_revocation(self):
        otp_owner = self.otp_sign_in(self.secrets['owner_phone'])
        self.configure(enabled=False)
        assert not self.user.bootstrap()['currentUser'], 'disabling temporary login left session alive'
        assert self.user.bootstrap()['capabilities']['temporaryLoginEnabled'] is False
        self.login(self.user, expected=403)
        assert otp_owner.bootstrap()['currentUser']['roleId'] == 'owner', 'temporary settings revoked OTP session'
        self.configure()
        self.sql('DELETE FROM rate_limits;')
        self.login(self.user)
        changed_hash = self.hash_password(secrets.token_hex(12))
        self.configure(password_hash=changed_hash)
        assert not self.user.bootstrap()['currentUser'], 'hash change left temporary session alive'
        self.login(self.user, expected=401)
        self.configure()
        self.login(self.user)
        other_phone = '09' + ''.join(str(secrets.randbelow(10)) for _ in range(9))
        self.configure(phone=other_phone)
        assert not self.user.bootstrap()['currentUser'], 'configured phone change left session alive'
        other = self.client()
        other.action('auth.temporaryLogin', {'phone': other_phone, 'password': self.password}, expected=401)
        assert self.sql("SELECT COUNT(*) FROM users WHERE phone='" + other_phone + "';") == '0', 'marker permitted second owner provisioning'
        self.configure()

    def rate_limit(self):
        self.sql('DELETE FROM rate_limits;')
        client = self.client()
        for _ in range(5):
            client.action('auth.temporaryLogin', {'phone': self.phone, 'password': 'incorrect-fixture'}, expected=401)
        self.login(client, expected=429)
        self.sql('DELETE FROM rate_limits;')
        self.login(client)

    def otp_unchanged(self):
        self.sql('DELETE FROM rate_limits;')
        self.user.action('auth.logout', {})
        self.otp_sign_in(self.phone, self.user)
        assert self.user.bootstrap()['currentUser']['roleId'] == 'owner'
        self.configure(enabled=False)
        assert self.user.bootstrap()['currentUser']['roleId'] == 'owner', 'OTP login used temporary session kind'
        self.configure()
        client = self.client()
        client.action('auth.verifyOtp', {'phone': self.phone, 'code': self.password[:4]}, expected=401)

    def cleanup(self):
        self.path.write_text(self.original)
        self.sql("DELETE FROM audit_logs WHERE target_id IN (SELECT id FROM orders WHERE user_id IN (SELECT id FROM users WHERE phone='" + self.phone + "'));")
        self.sql("DELETE FROM payment_attempts WHERE order_id IN (SELECT id FROM orders WHERE user_id IN (SELECT id FROM users WHERE phone='" + self.phone + "'));")
        self.sql("DELETE FROM orders WHERE user_id IN (SELECT id FROM users WHERE phone='" + self.phone + "');")
        self.sql("DELETE FROM reservations WHERE user_id IN (SELECT id FROM users WHERE phone='" + self.phone + "');")
        self.sql("DELETE FROM products WHERE id='" + self.prefix + "_temporary_payment';")
        self.sql("DELETE FROM audit_logs WHERE user_id IN (SELECT id FROM users WHERE phone='" + self.phone + "');")
        self.sql("DELETE FROM otp_challenges WHERE phone='" + self.phone + "';")
        self.sql("DELETE FROM users WHERE phone='" + self.phone + "';")
        self.sql("DELETE FROM settings WHERE name IN ('temporary_owner_provisioned','installation_complete');")
        for row in self.markers.splitlines():
            name, value = row.split('\t')
            self.sql("INSERT INTO settings (name,value) VALUES ('" + name + "',UNHEX('" + value + "'));")
        self.sql('DELETE FROM rate_limits;')

    def run(self):
        try:
            for title, fn in [
                ('temporary capability hides configured phone and hash', self.privacy),
                ('wrong credentials, CSRF and GET rejected', self.rejected),
                ('configured account becomes owner once and logs out', self.provision),
                ('verified payment callback preserves temporary session', self.payment_callback),
                ('role changes revoke sessions without re-promotion', self.no_repromotion),
                ('disabled accounts cannot use temporary login', self.disabled),
                ('configuration changes revoke only temporary sessions', self.config_revocation),
                ('temporary login throttles password guesses', self.rate_limit),
                ('normal one-use OTP authentication remains independent', self.otp_unchanged),
            ]:
                self.check(title, fn)
            print(f'All {self.check_count} temporary login checks passed.')
        finally:
            self.cleanup()


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--private-dir', required=True)
    parser.add_argument('--base', default='http://127.0.0.1:8080')
    parser.add_argument('--db-container', default='dezhawo-qa-db')
    parser.add_argument('--web-container', default='dezhawo-qa-web')
    TemporaryLogin(parser.parse_args()).run()

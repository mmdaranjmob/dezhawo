#!/usr/bin/env python3
"""Optional disposable local QA runtime. Hosting deployment does not need Docker.

python3 tests/runtime.py start --private-dir /tmp/dezhawo-qa
python3 tests/integration.py --private-dir /tmp/dezhawo-qa
python3 tests/runtime.py stop --private-dir /tmp/dezhawo-qa

Private configuration is generated outside the checkout. Never use production
credentials. The database is retained on stop; this helper never drops a DB.
"""
from pathlib import Path
import argparse
import json
import os
import secrets
import subprocess
import time


def run(args, *, input=None, capture=False):
    result = subprocess.run(args, input=input, text=True, check=True,
                            stdout=subprocess.PIPE if capture else None)
    return result.stdout.strip() if capture else ''


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=['start', 'stop'])
    parser.add_argument('--private-dir', required=True)
    parser.add_argument('--port', type=int, default=8080)
    args = parser.parse_args()
    repository = Path(__file__).resolve().parents[1]
    private = Path(args.private_dir).resolve()
    assert private != repository and repository not in private.parents, 'Private path must be outside checkout'
    assert 1024 <= args.port <= 65535
    prefix = 'dezhawo-qa'
    db_container, web_container, network = prefix + '-db', prefix + '-web', prefix + '-net'
    if args.action == 'stop':
        for name in [web_container, db_container]:
            subprocess.run(['docker', 'stop', name], check=False, stdout=subprocess.DEVNULL)
        print('QA containers stopped; private test database retained.')
        return
    private.mkdir(mode=0o700, parents=True, exist_ok=True)
    secret_path = private / 'secrets.json'
    if secret_path.exists():
        values = json.loads(secret_path.read_text())
    else:
        values = {key: secrets.token_hex(32) for key in
                  ['database_password', 'root_password', 'app_key', 'installation_key']}
        values.update(db_name='dezhawo_qa', owner_phone='09121112233',
                      owner_password='QA!'+secrets.token_hex(12)+'Aa9')
        secret_path.write_text(json.dumps(values))
        secret_path.chmod(0o600)
    assert values['db_name'] == 'dezhawo_qa', 'Unexpected private DB name'
    config_path = private / 'qa-config.php'
    # Values generated here contain only validated hex tokens, never pasted secrets.
    for key in ['database_password', 'root_password', 'app_key', 'installation_key']:
        assert len(values[key]) == 64 and all(ch in '0123456789abcdef' for ch in values[key])
    config_path.write_text("<?php\nreturn ['app'=>['env'=>'testing','base_url'=>'http://127.0.0.1:" + str(args.port)
        + "','key'=>'"+values['app_key']+"','installation_key'=>'"+values['installation_key']
        + "','timezone'=>'Asia/Tehran'],'database'=>['host'=>'"+db_container
        + "','port'=>3306,'name'=>'dezhawo_qa','user'=>'dezhawo_qa','password'=>'"+values['database_password']
        + "','charset'=>'utf8mb4'],'session'=>['name'=>'dezhawo_qa','secure'=>false],"
          "'sms'=>['provider'=>'kavenegar','api_key'=>'','sender'=>''],"
          "'payment'=>['provider'=>'zarinpal','merchant_id'=>'','sandbox'=>true],"
          "'testing'=>['otp_outbox'=>'/qa/outbox','payment_stub'=>true]];\n")
    config_path.chmod(0o600)
    env_path = private / 'mariadb.env'
    env_path.write_text('MARIADB_ROOT_PASSWORD='+values['root_password']+'\nMARIADB_DATABASE=dezhawo_qa\n'
                        'MARIADB_USER=dezhawo_qa\nMARIADB_PASSWORD='+values['database_password']+'\n')
    env_path.chmod(0o600)
    (private / 'outbox').mkdir(mode=0o700, exist_ok=True)
    (private / 'docker').mkdir(exist_ok=True)
    build_dir = private / 'build'
    build_dir.mkdir(exist_ok=True)
    (build_dir / 'Dockerfile').write_text('FROM php:8.3-cli\nRUN docker-php-ext-install pdo_mysql\n'
                                         'WORKDIR /var/www/html\n'
                                         'CMD ["php","-d","opcache.enable=0","-S","0.0.0.0:8080","-t","/var/www/html"]\n')
    run(['docker', 'pull', 'mariadb:11.4'])
    run(['docker', '--config', str(private / 'docker'), 'build', '--pull', '-t', 'dezhawo-php-qa:8.3', str(build_dir)])
    if network not in run(['docker', 'network', 'ls', '--format', '{{.Name}}'], capture=True).splitlines():
        run(['docker', 'network', 'create', network])
    existing = run(['docker', 'ps', '-a', '--format', '{{.Names}}'], capture=True).splitlines()
    if db_container in existing:
        mounted = json.loads(run(['docker', 'inspect', db_container], capture=True))[0]
        assert any(m.get('Source') == str(private / 'db') for m in mounted['Mounts']), 'Existing QA DB uses another private directory'
        run(['docker', 'start', db_container])
    else:
        run(['docker', 'run', '-d', '--name', db_container, '--network', network,
             '--env-file', str(env_path), '-v', str(private / 'db')+':/var/lib/mysql', 'mariadb:11.4'])
    sql_cmd = ['docker', 'exec', '-i', db_container, 'sh', '-c',
               'MYSQL_PWD="$MARIADB_PASSWORD" mariadb -u "$MARIADB_USER" --database="$MARIADB_DATABASE" -N -B']
    for _ in range(60):
        result = subprocess.run(sql_cmd, input='SELECT 1;', text=True, capture_output=True)
        if result.returncode == 0:
            break
        time.sleep(1)
    else:
        raise RuntimeError('QA database did not become ready')
    tables = run(sql_cmd, input='SHOW TABLES;', capture=True)
    if not tables:
        run(sql_cmd, input=(repository / 'database/schema.sql').read_text())
    if web_container in existing:
        mounted = json.loads(run(['docker', 'inspect', web_container], capture=True))[0]
        assert any(m.get('Source') == str(private) for m in mounted['Mounts']), 'Existing QA web uses another private directory'
        run(['docker', 'rm', '-f', web_container])
    run(['docker', 'run', '-d', '--name', web_container, '--user', f'{os.getuid()}:{os.getgid()}',
         '--network', network, '-p', f'127.0.0.1:{args.port}:8080', '-e', 'PHP_CLI_SERVER_WORKERS=8',
         '-e', 'DEZHAWO_CONFIG=/qa/qa-config.php', '-v', str(repository)+':/var/www/html:ro',
         '-v', str(private)+':/qa', 'dezhawo-php-qa:8.3'])
    print(f'QA ready at http://127.0.0.1:{args.port}; private config and OTP files stay outside checkout.')


if __name__ == '__main__':
    main()

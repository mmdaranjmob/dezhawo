#!/usr/bin/env python3
"""Read-only guards test for a local Apache with mod_rewrite + AllowOverride All.

The PHP development server does not implement .htaccess; use an actual Apache
instance for this check. No private response contents are printed.
"""
import argparse
import urllib.error
import urllib.parse
import urllib.request

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--base', default='http://127.0.0.1:8081')
args = parser.parse_args()
assert urllib.parse.urlsplit(args.base).hostname in ('localhost', '127.0.0.1', '::1')
checks = [('/', 200), ('/index.php', 200), ('/assets/app.css', 200),
          ('/app/config.example.php', 403), ('/app/config.php', 403),
          ('/database/schema.sql', 403), ('/tests/integration.py', 403),
          ('/.git/config', 403), ('/.env', 403)]
for path, expected in checks:
    try:
        response = urllib.request.urlopen(args.base.rstrip('/')+path, timeout=10)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        status, contents = response.status, response.read()
    assert status == expected, f'{path}: HTTP {status}, expected {expected}'
    if path == '/':
        assert b'assets/api.js' in contents, 'DirectoryIndex did not select the PHP application'
    print(f'PASS Apache {path}: {status}')
print('PASS 9 public entry and private file hosting guards')

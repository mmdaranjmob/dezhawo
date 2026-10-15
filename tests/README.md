# Development checks

These helpers are optional development tools. cPanel deployment needs PHP and
MySQL/MariaDB, **not Docker, Python, Node, or Playwright**.

Use a disposable local database. The integration suite writes products, users,
roles, orders, tickets, CMS settings and temporary inventory fixtures. It refuses
non-loopback URLs and a database name without the `_qa` suffix. It never drops
tables or deletes application data. Only its disposable rate-limit table is
cleared at the beginning to allow a repeatable test run.

```sh
python3 tests/runtime.py start --private-dir /tmp/dezhawo-qa
python3 tests/integration.py --private-dir /tmp/dezhawo-qa
python3 tests/admin_regression.py --private-dir /tmp/dezhawo-qa
python3 tests/runtime.py stop --private-dir /tmp/dezhawo-qa
```

The runtime uses official `php:8.3-cli` and `mariadb:11.4` images, builds the
PDO MySQL extension, runs eight PHP workers to exercise competing inventory
transactions, and imports `database/schema.sql` only into an empty test database.
Container and network names are `dezhawo-qa-web`, `dezhawo-qa-db`, and
`dezhawo-qa-net`. Stop retains the private database for inspection. An existing
container bound to another private directory is rejected.

Generated installation keys, application keys, passwords and OTP messages stay
in the private directory outside the repository. Passwords and OTP codes are not
printed. The explicit testing environment uses a private OTP outbox and a payment
stub; production ignores these switches. The local PHP server disables opcode
caching so temporary private configuration changes are visible immediately.

The HTTP and database tests exercise first-owner installation, session and OTP
authentication, expiry/replay/rate limits, CSRF, role boundaries, privacy, password
revocation, shared inventory, concurrent POS/reservations, provider rejection,
payment replay, single restocking, expired paid stock shortages, payment resume,
digital delivery details, CMS and customer tickets. Actual SMS delivery and bank
transactions require the host's own provider credentials and a separate sandbox
verification.

Optional browser checks need Playwright available to Node and a local Chromium
executable. Install test dependencies outside this checkout if needed:

```sh
npm install --prefix /tmp/dezhawo-browser playwright
NODE_PATH=/tmp/dezhawo-browser/node_modules \
DEZHAWO_QA_URL=http://127.0.0.1:8080 \
DEZHAWO_QA_SECRETS=/tmp/dezhawo-qa/secrets.json \
DEZHAWO_QA_OUTBOX=/tmp/dezhawo-qa/outbox \
DEZHAWO_QA_PAYMENTS=1 \
CHROMIUM_PATH=/usr/bin/chromium \
node tests/browser.js
```

Browser checks cover five viewport sizes, staff login, colors and prices, POS,
customer OTP, tickets, cart locking, payment resume and callback state. The
administrator regression suite additionally checks privilege escalation,
restricted price/stock updates, reserved-item disabling, historical variants,
password reset and role demotion.

`python3 tests/hosting.py --base http://127.0.0.1:8081` checks private directory
guards and the PHP default document using a separate actual Apache instance with
`mod_rewrite` and `AllowOverride All`. The built-in PHP development server ignores
`.htaccess`, so it cannot validate those hosting protections.

Appearance checks use synthetic bootstrap responses in isolated browser contexts;
they do not sign in, change the database or need private credentials. They cover
system appearance changes, saved overrides, cross-tab synchronization, keyboard
controls, mobile layouts and readable dark surfaces:

```sh
DEZHAWO_THEME_QA_URL=http://127.0.0.1:8080 node tests/theme.js
```

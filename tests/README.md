# Development checks

These helpers are optional development tools. cPanel deployment needs PHP and
MySQL/MariaDB, **not Docker, Python, Node, or Playwright**.

Use a disposable local database. The integration suite writes products, users,
roles, orders, tickets, CMS settings and temporary inventory fixtures. It refuses
non-loopback URLs and a database name without the `_qa` suffix. It never drops
tables or deletes application data. The HTTP, administrator and browser suites
clear only the disposable rate-limit table at the beginning to allow immediate
repeat runs. An administrator session-revocation check can also expire those QA
windows before reauthenticating; production limits are never changed.

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

Generated installation keys, application keys, database passwords and OTP messages
stay in the private directory outside the repository. Secrets and OTP codes are not
printed. The explicit testing environment uses a private OTP outbox and a payment
stub; production ignores these switches. The local PHP server disables opcode
caching so temporary private configuration changes are visible immediately.

The HTTP and database tests exercise first-owner installation, session and OTP
authentication for owners, employees and customers through one mobile flow,
expiry/replay/rate limits, rejection of removed password routes, client-supplied
role isolation, disabled-account rejection, session revocation on role changes,
CSRF, role boundaries, privacy, shared inventory, concurrent POS/reservations, provider rejection,
payment replay, single restocking, expired paid stock shortages, payment resume,
digital delivery details, CMS and customer tickets. Actual SMS delivery and bank
transactions require the host's own provider credentials and a separate sandbox
verification.

Provider protocol checks run the actual SMS implementation with deterministic
cURL fixtures, without a database, configuration secrets, or external requests:

```sh
php tests/sms_provider.php
# With the optional development runtime already running:
docker exec dezhawo-qa-web php /var/www/html/tests/sms_provider.php
```

These checks cover Niazpardaz API-key and username/password request formats,
documented result envelopes, unsuccessful and malformed results, HTTP/network
failures, no automatic resend, TLS verification, disabled redirects, timeouts,
streamed response limits, unsafe configuration rejection, production ignoring
the testing outbox, private testing files, and existing Kavenegar compatibility.
The fixture loads the unchanged provider source into an isolated PHP namespace;
there is no production testing endpoint or transport override. PHP needs the
cURL extension for its constants. Successful fixtures verify the protocol, not
delivery to a real handset or the account's sending-line authorization.

The payment extension has separate HTTP/database and provider-fixture checks:

```sh
python3 tests/payment_methods.py --private-dir /tmp/dezhawo-qa
docker exec dezhawo-qa-web php /var/www/html/tests/digipay_provider.php --database
```

Run these suites sequentially against the disposable QA runtime. They restore
payment settings and never send real gateway requests. Coverage includes bank
snapshots and checksums, review permissions, unpaid receipt submission, competing
last-unit approvals, cancellation/replay, callback cookie preservation, DigiPay
state/amount/identifier verification, uncertain results and delivery retries.

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

Browser checks cover five viewport sizes, the shared owner/customer OTP form,
permission-controlled management entry, colors and prices, POS,
customer OTP, tickets, cart locking, payment resume and callback state. The
administrator regression suite additionally checks privilege escalation,
restricted price/stock updates, reserved-item disabling, historical variants,
immediate permission edits, role demotion, customer promotion without a password
and last-owner protection for an owner provisioned through OTP.

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

Product photographs have HTTP and actual-browser checks. The local runtime keeps
source read-only and mounts a writable `product-uploads` folder from the private
QA directory. JPEG, PNG and WebP uploads are limited to 8 MiB; PHP is started with
an 8 MiB upload limit and 12 MiB POST limit. Existing QA databases need the additive
`database/upgrade-20261016-product-images.sql` migration before these checks.

```sh
python3 tests/product_images.py --private-dir /tmp/dezhawo-qa
NODE_PATH=/tmp/dezhawo-browser/node_modules node tests/product_photos_browser.js
DEZHAWO_QA_PHOTOS_LIVE=1 \
DEZHAWO_QA_SECRETS=/tmp/dezhawo-qa/secrets.json \
DEZHAWO_QA_OUTBOX=/tmp/dezhawo-qa/outbox \
NODE_PATH=/tmp/dezhawo-browser/node_modules node tests/product_photos_browser.js
```

The HTTP checks cover permissions/CSRF, actual image formats and limits, forged
paths, preserved/removed photographs and unchanged stock. Browser checks cover the
original storefront in eight viewport/appearance combinations, upload and removal,
photographs in catalog/details/cart, role restrictions and a sale occurring while
the product editor is open. Saving an unchanged stock field must retain the latest
inventory. These checks use disposable QA products and local image fixtures only.

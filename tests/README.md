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

Temporary owner access, homepage selections and server-rendered SEO have focused
checks. Run database/configuration-changing suites sequentially:

```sh
python3 tests/temporary_login.py --private-dir /tmp/dezhawo-qa
DEZHAWO_QA_PRIVATE_DIR=/tmp/dezhawo-qa \
NODE_PATH=/tmp/dezhawo-browser/node_modules node tests/temporary_login_browser.js
python3 tests/hero_products.py --private-dir /tmp/dezhawo-qa
python3 tests/seo.py --private-dir /tmp/dezhawo-qa
DEZHAWO_QA_SECRETS=/tmp/dezhawo-qa/secrets.json \
DEZHAWO_QA_OUTBOX=/tmp/dezhawo-qa/outbox \
NODE_PATH=/tmp/dezhawo-browser/node_modules node tests/homepage_browser.js
```

The temporary-login checks restore private QA configuration and provisioning
markers, and verify one-time owner setup, role changes, disabled accounts, CSRF,
rate limits, logout, configuration-based session revocation and session retention
after a verified testing-payment callback. Homepage checks
cover ordered selections, invalid/duplicate/disabled products, permissions, native
navigation to a product page and unchanged inventory. SEO checks use real HTTP
responses with JavaScript disabled: public catalog/product content, safe metadata
and JSON-LD, toman-to-IRR conversion, stock/reservations, inactive404 responses,
outage503 responses, sitemap, robots and subdirectory installation.

Hierarchical categories and administrator form behavior have focused checks:

```sh
python3 tests/categories.py --private-dir /tmp/dezhawo-qa
DEZHAWO_QA_SECRETS=/tmp/dezhawo-qa/secrets.json \
DEZHAWO_QA_OUTBOX=/tmp/dezhawo-qa/outbox \
NODE_PATH=/tmp/dezhawo-browser/node_modules node tests/admin_forms_browser.js
```

Apply `database/upgrade-20261016-categories.sql` to an older disposable QA database
first. Category checks cover parent/descendant operations, cycles, occupied-branch
deletion, physical/digital boundaries, permissions, hidden branches in public
catalog/SEO and unchanged stock, migration reapplication and a fresh temporary
schema. Browser checks use actual disposable database fixtures and the owner OTP
flow to verify category management and customer navigation, mobile/dark layouts,
price grouping and numeric payloads, blank new-product inputs, retained existing
zero values, modal dismissal after success and one submission while a save is
pending. One intercepted failed save verifies visible inline errors without
discarding entered values. Run both suites sequentially with other database QA;
their synthetic products and categories are removed in `finally`.

In-site database upgrades have an isolated legacy-schema suite and synthetic
browser checks:

```sh
python3 tests/database_upgrades.py
node tests/database_upgrades_browser.js
```

The database suite uses the existing local QA MariaDB container, network and
PHP image to create a separate disposable database, restricted user, private
configuration/outbox and HTTP container on port 8082. It removes those resources
afterward and does not change the normal QA database or configuration. Checks
cover read-only detection, owner/CSRF/HTTPS restrictions, interrupted DDL and
resumability, preserved catalog/orders/reservations/settings, repeat upgrades,
deleted-root preservation and concurrent requests. The browser suite intercepts
all API replies and verifies the owner-only top banner, one-click busy state,
inline errors/retry, verified completion and mobile light/dark readability.

POS customer identity has focused API/database and actual-browser checks:

```sh
python3 tests/pos_customers.py --private-dir /tmp/dezhawo-qa
DEZHAWO_QA_SECRETS=/tmp/dezhawo-qa/secrets.json \
DEZHAWO_QA_OUTBOX=/tmp/dezhawo-qa/outbox \
node tests/pos_customers_browser.js
```

Run these sequentially against the disposable QA runtime. They verify required
first/last names and Iranian mobile numbers, optional addresses, normalized
stored invoice data, privacy and authorization, unchanged stock on validation
failures, and concurrent last-unit sales. Browser checks exercise draft retention
across invoice edits/refreshes, inline errors, duplicate blocking during a pending
save and clearing customer fields only after a successful sale. Fixtures are
removed afterward; no database migration or account creation is required.

POS purchase history has separate API/database and browser checks:

```sh
python3 tests/pos_customer_history.py --private-dir /tmp/dezhawo-qa
DEZHAWO_QA_SECRETS=/tmp/dezhawo-qa/secrets.json \
DEZHAWO_QA_OUTBOX=/tmp/dezhawo-qa/outbox \
node tests/pos_customer_history_browser.js
```

Run these sequentially against the same disposable runtime. They check POS-only
authorization, normalized mobiles, registered customers and new numbers, counts
across paid online/POS invoices with excluded statuses, and history beyond the
recent-order list. Browser checks cover debounce, draft retention, stale replies,
inline retry, clearing after a real sale, and mobile light/dark presentation.

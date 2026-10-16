/* Focused temporary-owner UI checks against disposable loopback QA only.
 * Run sequentially: this temporarily replaces the private QA configuration and
 * restores its exact contents, provisioning markers and fixture account finally.
 * Credentials are generated in memory and never printed or stored in the repo.
 */
const {chromium} = require('playwright');
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const {spawnSync} = require('node:child_process');

const privateDir = process.env.DEZHAWO_QA_PRIVATE_DIR;
assert.ok(privateDir, 'Provide the private QA fixture directory.');
const base = (process.env.DEZHAWO_QA_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
assert.ok(['localhost', '127.0.0.1', '[::1]'].includes(new URL(base).hostname), 'QA requires a loopback URL.');
const fixture = JSON.parse(fs.readFileSync(path.join(privateDir, 'secrets.json'), 'utf8'));
assert.ok(fixture.db_name.endsWith('_qa'), 'QA requires a disposable database.');
function sql(statement) {
  const result = spawnSync('docker', ['exec', '-i', process.env.DEZHAWO_QA_DB_CONTAINER || 'dezhawo-qa-db', 'sh', '-c',
    'MYSQL_PWD="$MARIADB_PASSWORD" mariadb -u "$MARIADB_USER" --database="$MARIADB_DATABASE" -N -B'], {input:statement, encoding:'utf8'});
  assert.equal(result.status, 0, 'Disposable QA database operation failed; details withheld.');
  return result.stdout.trim();
}
assert.equal(sql('SELECT DATABASE();'), fixture.db_name, 'QA database and private fixtures do not match.');
const configPath = path.join(privateDir, 'qa-config.php');
const originalConfig = fs.readFileSync(configPath, 'utf8');
assert.ok(originalConfig.startsWith('<?php'), 'QA configuration must be PHP.');
const originalMarkers = sql("SELECT name,HEX(value) FROM settings WHERE name IN ('temporary_owner_provisioned','installation_complete');");
let phone;
do { phone = '09' + Array.from({length:9}, () => crypto.randomInt(10)).join(''); }
while (sql(`SELECT COUNT(*) FROM users WHERE phone='${phone}';`) !== '0');
const password = crypto.randomBytes(16).toString('hex');
const hashResult = spawnSync('docker', ['exec', '-i', process.env.DEZHAWO_QA_WEB_CONTAINER || 'dezhawo-qa-web', 'php', '-r',
  'echo password_hash(stream_get_contents(STDIN), PASSWORD_DEFAULT);'], {input:password, encoding:'utf8'});
assert.equal(hashResult.status, 0, 'QA password hashing failed.');
assert.ok(hashResult.stdout.length >= 60, 'QA password hash is incomplete.');
const passwordHash = hashResult.stdout;
function configure(enabled) {
  const encoded = Buffer.from(JSON.stringify({enabled, phone, password_hash:passwordHash})).toString('base64');
  fs.writeFileSync(configPath, '<?php\n$configuration=(static function(){\n' + originalConfig.slice(5) + '\n})();\n' +
    `$configuration['temporary_login']=json_decode(base64_decode('${encoded}'),true);\n` +
    "$configuration['testing']['otp_outbox']='';\n$configuration['sms']=['provider'=>'niazpardaz','niazpardaz_auth'=>'api_key','api_key'=>'','sender'=>''];\nreturn $configuration;\n");
}
function privateValuesAbsent(value) {
  return ![phone, password, passwordHash].some(secret => value.includes(secret));
}
let checkCount = 0;
function pass(title) { checkCount++; console.log('PASS ' + title); }

(async () => {
  let browser;
  try {
    configure(true);
    sql("DELETE FROM settings WHERE name='temporary_owner_provisioned';DELETE FROM rate_limits;");
    browser = await chromium.launch({executablePath:process.env.CHROMIUM_PATH || '/usr/bin/chromium', headless:true});
    const context = await browser.newContext({viewport:{width:1280,height:900}, reducedMotion:'reduce'});
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(base + '/index.php', {waitUntil:'networkidle'});
    await page.waitForFunction(() => DZ.state.configured === true);
    assert.ok(await page.evaluate(() => !DZ.state.capabilities.smsConfigured && DZ.state.capabilities.temporaryLoginEnabled), 'SMS-disabled temporary capability is unavailable.');
    const publicBootstrap = await context.request.get(base + '/api.php?action=bootstrap');
    assert.ok(privateValuesAbsent(await publicBootstrap.text()), 'Guest bootstrap exposes private temporary credentials.');
    assert.ok(privateValuesAbsent(await page.content()), 'Public HTML exposes private temporary credentials.');
    for (const asset of ['assets/api.js','assets/store.js','assets/admin.js']) {
      assert.ok(privateValuesAbsent(await (await context.request.get(base + '/' + asset)).text()), 'Public JavaScript exposes temporary credentials.');
    }
    await page.locator('.profile-btn[data-action="account"]').click();
    assert.ok(await page.locator('#loginPhoneForm [type="submit"]').isDisabled(), 'SMS login remains enabled without SMS.');
    await page.locator('[data-login-mode="temporary"]').click();
    const form = page.locator('#temporaryLoginForm');
    await form.waitFor();
    assert.ok(await form.locator('[name="phone"]').inputValue() === '' && await form.locator('[name="password"]').inputValue() === '', 'Private credentials were prefilled.');
    pass('SMS-disabled store offers temporary login without exposing credentials');

    await form.locator('[name="phone"]').fill(phone);
    await form.locator('[name="password"]').fill('wrong-fixture-password');
    await form.locator('[type="submit"]').click();
    await page.waitForFunction(() => Boolean(document.getElementById('loginError')?.textContent.trim()));
    assert.ok(await form.isVisible(), 'Wrong password unexpectedly closed the login form.');
    assert.ok(await page.evaluate(() => DZ.state.currentUser === null), 'Wrong password authenticated an account.');
    pass('wrong temporary password stays on the dialog with an inline error');

    await form.locator('[name="password"]').fill(password);
    await form.locator('[type="submit"]').click();
    await page.waitForFunction(() => DZ.state.currentUser?.roleId === 'owner');
    assert.ok(await page.locator('[data-management-entry]:visible').count() > 0, 'Owner management entry is hidden.');
    await page.locator('#modalContent [data-action="admin"]').click();
    await page.locator('#adminView').waitFor({state:'visible'});
    await page.locator('[data-admin-action="tab"][data-tab="products"]').click();
    await page.locator('[data-admin-action="product-new"]').waitFor({state:'visible'});
    const ownerBootstrap = await context.request.get(base + '/api.php?action=bootstrap');
    const ownerPayload = await ownerBootstrap.text();
    assert.ok(!ownerPayload.includes(password) && !ownerPayload.includes(passwordHash) && !ownerPayload.includes('password_hash'), 'Owner bootstrap exposes a password or its hash.');
    pass('correct temporary password opens owner management and product controls');

    configure(false);
    await page.evaluate(() => DZ.refresh());
    await page.locator('#adminView').waitFor({state:'hidden'});
    assert.ok(await page.evaluate(() => DZ.state.currentUser === null && !DZ.state.capabilities.temporaryLoginEnabled), 'Disabling temporary access retained its session.');
    assert.equal(await page.locator('[data-management-entry]:visible').count(), 0);
    await page.locator('.profile-btn[data-action="account"]').click();
    await page.locator('#loginPhoneForm').waitFor();
    assert.equal(await page.locator('[data-login-mode="temporary"]').count(), 0);
    assert.equal(errors.length, 0, 'Browser reported uncaught JavaScript errors.');
    pass('disabling temporary access revokes the session and removes its login option');
    console.log(`All ${checkCount} temporary login browser checks passed.`);
  } finally {
    try { if (browser) await browser.close(); }
    finally {
      fs.writeFileSync(configPath, originalConfig);
      sql(`DELETE FROM audit_logs WHERE user_id IN (SELECT id FROM users WHERE phone='${phone}');DELETE FROM otp_challenges WHERE phone='${phone}';DELETE FROM users WHERE phone='${phone}';`);
      sql("DELETE FROM settings WHERE name IN ('temporary_owner_provisioned','installation_complete');");
      for (const row of originalMarkers.split('\n').filter(Boolean)) {
        const [name, value] = row.split('\t');
        assert.ok(/^[a-z_]+$/.test(name) && /^[A-F0-9]*$/.test(value), 'QA provisioning snapshot is invalid.');
        sql(`INSERT INTO settings(name,value) VALUES('${name}',UNHEX('${value}'));`);
      }
      sql('DELETE FROM rate_limits;');
    }
  }
})().catch(error => { console.error(error.message); process.exitCode = 1; });

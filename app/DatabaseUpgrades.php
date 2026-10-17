<?php
declare(strict_types=1);

/**
 * Fixed, server-owned schema upgrades. Inspection is read-only; only the
 * owner-only POST endpoint below may execute an upgrade. Do not accept SQL,
 * filenames, migration IDs or column names from a browser here.
 *
 * Add future upgrades to this registry together with a read-only completion
 * check and an idempotent apply function. A marker alone is never proof that
 * the corresponding schema is complete.
 */
function database_upgrade_registry(): array
{
    return [
        ['id'=>'payment-methods-20261015', 'label'=>'روش‌های پرداخت',
            'complete'=>'database_upgrade_payment_complete', 'apply'=>'database_upgrade_payment_apply'],
        ['id'=>'product-images-20261016', 'label'=>'تصاویر محصولات',
            'complete'=>'database_upgrade_images_complete', 'apply'=>'database_upgrade_images_apply'],
        ['id'=>'categories-20261016', 'label'=>'دسته‌بندی و زیردسته‌ها',
            'complete'=>'database_upgrade_categories_complete', 'apply'=>'database_upgrade_categories_apply'],
        ['id'=>'product-gallery-20261017', 'label'=>'گالری تصاویر محصولات',
            'complete'=>'database_upgrade_gallery_complete', 'apply'=>'database_upgrade_gallery_apply'],
    ];
}

function database_upgrade_category_columns(): array
{
    return ['id','name','parent_id','product_type','legacy_key','enabled','sort_order','created_at','updated_at'];
}

/** A private metadata snapshot; it is never serialized into the API response. */
function database_upgrade_snapshot(): array
{
    $snapshot = ['columns'=>[], 'indexes'=>[], 'foreignKeys'=>[],
        'paymentOptions'=>false, 'categoriesInitialized'=>false, 'categoryOwnerGrants'=>false,
        'categoryMappingComplete'=>false];
    $rows = db()->query("SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('products','payment_attempts','categories')")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) $snapshot['columns'][$row['TABLE_NAME']][$row['COLUMN_NAME']] = true;
    $rows = db()->query("SELECT TABLE_NAME,INDEX_NAME,COLUMN_NAME,SEQ_IN_INDEX,NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('products','categories') ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $key = $row['TABLE_NAME'] . '.' . $row['INDEX_NAME'];
        $snapshot['indexes'][$key]['columns'][] = $row['COLUMN_NAME'];
        $snapshot['indexes'][$key]['unique'] = (int)$row['NON_UNIQUE'] === 0;
    }
    $rows = db()->query("SELECT k.TABLE_NAME,k.CONSTRAINT_NAME,k.COLUMN_NAME,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME WHERE k.CONSTRAINT_SCHEMA=DATABASE() AND k.TABLE_NAME IN ('products','categories') AND k.REFERENCED_TABLE_NAME IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) $snapshot['foreignKeys'][] = $row;
    $query = db()->query("SELECT name,value FROM settings WHERE name IN ('payment_options','categories_initialized')");
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ($row['name'] === 'payment_options') $snapshot['paymentOptions'] = true;
        if ($row['name'] === 'categories_initialized' && $row['value'] === 'true') $snapshot['categoriesInitialized'] = true;
    }
    $snapshot['categoryOwnerGrants'] = (int)db()->query("SELECT COUNT(DISTINCT permission) FROM role_permissions WHERE role_id='owner' AND permission IN ('categories.view','categories.edit')")->fetchColumn() === 2;
    if (database_upgrade_category_table_complete($snapshot) && !empty($snapshot['columns']['products']['category_id'])) {
        $snapshot['categoryMappingComplete'] = !(bool)db()->query('SELECT EXISTS(SELECT 1 FROM products p JOIN categories c ON c.legacy_key=p.category WHERE p.category_id IS NULL)')->fetchColumn();
    }
    return $snapshot;
}

function database_upgrade_index(array $snapshot, string $table, string $name, array $columns, bool $unique = false): bool
{
    $index = $snapshot['indexes'][$table . '.' . $name] ?? null;
    return is_array($index) && $index['columns'] === $columns && (!$unique || $index['unique']);
}

function database_upgrade_foreign_key(array $snapshot, string $table, string $column, string $target): bool
{
    foreach ($snapshot['foreignKeys'] as $row) {
        if ($row['TABLE_NAME'] === $table && $row['COLUMN_NAME'] === $column
            && $row['REFERENCED_TABLE_NAME'] === $target && $row['REFERENCED_COLUMN_NAME'] === 'id'
            && in_array($row['DELETE_RULE'], ['RESTRICT','NO ACTION'], true)) return true;
    }
    return false;
}

function database_upgrade_category_table_complete(array $snapshot): bool
{
    foreach (database_upgrade_category_columns() as $column) {
        if (empty($snapshot['columns']['categories'][$column])) return false;
    }
    return true;
}

function database_upgrade_payment_complete(array $snapshot): bool
{
    return !empty($snapshot['columns']['payment_attempts']['provider'])
        && !empty($snapshot['columns']['payment_attempts']['metadata_json']) && $snapshot['paymentOptions'];
}

function database_upgrade_images_complete(array $snapshot): bool
{
    return !empty($snapshot['columns']['products']['image_url']);
}

function database_upgrade_gallery_complete(array $snapshot): bool
{
    return !empty($snapshot['columns']['products']['gallery_json']);
}

function database_upgrade_categories_complete(array $snapshot): bool
{
    return database_upgrade_category_table_complete($snapshot)
        && !empty($snapshot['columns']['products']['category_id'])
        && database_upgrade_index($snapshot,'categories','PRIMARY',['id'],true)
        && database_upgrade_index($snapshot,'categories','uq_categories_legacy',['legacy_key'],true)
        && database_upgrade_index($snapshot,'categories','idx_categories_parent_order',['parent_id','sort_order'])
        && database_upgrade_index($snapshot,'products','idx_products_category_id',['category_id'])
        && database_upgrade_foreign_key($snapshot,'categories','parent_id','categories')
        && database_upgrade_foreign_key($snapshot,'products','category_id','categories')
        && $snapshot['categoriesInitialized'] && $snapshot['categoryOwnerGrants'] && $snapshot['categoryMappingComplete'];
}

/** Safe labels and booleans only. Send this object only to the actual owner. */
function database_upgrade_status(?array $snapshot = null): array
{
    $snapshot ??= database_upgrade_snapshot();
    $pending = [];
    foreach (database_upgrade_registry() as $upgrade) {
        if (!$upgrade['complete']($snapshot)) $pending[] = ['id'=>$upgrade['id'],'label'=>$upgrade['label']];
    }
    // Older catalog/payment columns cannot serve the current application safely.
    // A missing category table/column still has the existing legacy fallback.
    $partialCategoryTable = isset($snapshot['columns']['categories'])
        && !database_upgrade_category_table_complete($snapshot)
        && !empty($snapshot['columns']['products']['category_id']);
    $recovery = !database_upgrade_images_complete($snapshot)
        || empty($snapshot['columns']['payment_attempts']['provider'])
        || empty($snapshot['columns']['payment_attempts']['metadata_json']) || $partialCategoryTable;
    return ['required'=>$pending !== [], 'pending'=>$pending, 'recovery'=>$recovery];
}

function database_upgrade_require_owner(): array
{
    $user = require_user();
    // settings.edit can belong to a delegated role; it never grants schema access.
    if (($user['roleId'] ?? $user['role_id'] ?? '') !== 'owner') {
        app_error('ارتقای دیتابیس فقط برای مدیر اصلی فروشگاه مجاز است.',403);
    }
    return $user;
}

function database_upgrade_payment_apply(): void
{
    $snapshot = database_upgrade_snapshot();
    if (empty($snapshot['columns']['payment_attempts']['provider'])) {
        db()->exec("ALTER TABLE payment_attempts ADD COLUMN provider VARCHAR(30) NOT NULL DEFAULT 'zarinpal' AFTER order_id");
    }
    if (empty($snapshot['columns']['payment_attempts']['metadata_json'])) {
        db()->exec('ALTER TABLE payment_attempts ADD COLUMN metadata_json TEXT NULL AFTER reference_id');
    }
    $query = db()->prepare('INSERT IGNORE INTO settings(name,value,updated_at) VALUES(?,?,UTC_TIMESTAMP())');
    $query->execute(['payment_options',json_value(payment_options_defaults())]);
}

function database_upgrade_images_apply(): void
{
    if (!database_upgrade_images_complete(database_upgrade_snapshot())) {
        db()->exec('ALTER TABLE products ADD COLUMN image_url VARCHAR(255) NULL AFTER art');
    }
}

function database_upgrade_gallery_apply(): void
{
    if (!database_upgrade_gallery_complete(database_upgrade_snapshot())) {
        db()->exec('ALTER TABLE products ADD COLUMN gallery_json TEXT NULL AFTER image_url');
    }
}

function database_upgrade_categories_apply(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS categories (
        id VARCHAR(64) NOT NULL,
        name VARCHAR(100) NOT NULL,
        parent_id VARCHAR(64) NULL,
        product_type VARCHAR(20) NOT NULL DEFAULT 'physical',
        legacy_key VARCHAR(20) NULL,
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_categories_legacy (legacy_key),
        KEY idx_categories_parent_order (parent_id,sort_order),
        CONSTRAINT fk_categories_parent FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $snapshot = database_upgrade_snapshot();
    // A partially applied upgrade consists of complete SQL statements. An
    // unrelated/malformed existing table must not be guessed at or overwritten.
    if (!database_upgrade_category_table_complete($snapshot)) throw new RuntimeException('Unexpected category schema');
    if (empty($snapshot['columns']['products']['category_id'])) {
        db()->exec('ALTER TABLE products ADD COLUMN category_id VARCHAR(64) NULL AFTER category');
    }
    if (!database_upgrade_index($snapshot,'categories','PRIMARY',['id'],true)) {
        db()->exec('ALTER TABLE categories ADD PRIMARY KEY (id)');
    }
    if (!database_upgrade_index($snapshot,'categories','uq_categories_legacy',['legacy_key'],true)) {
        db()->exec('ALTER TABLE categories ADD UNIQUE KEY uq_categories_legacy (legacy_key)');
    }
    if (!database_upgrade_index($snapshot,'categories','idx_categories_parent_order',['parent_id','sort_order'])) {
        db()->exec('ALTER TABLE categories ADD KEY idx_categories_parent_order (parent_id,sort_order)');
    }
    if (!database_upgrade_index($snapshot,'products','idx_products_category_id',['category_id'])) {
        db()->exec('ALTER TABLE products ADD KEY idx_products_category_id (category_id)');
    }
    if (!database_upgrade_foreign_key($snapshot,'categories','parent_id','categories')) {
        db()->exec('ALTER TABLE categories ADD CONSTRAINT fk_categories_parent FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE RESTRICT');
    }
    // Seed and map in their own DML transaction. DDL statements are deliberately
    // outside it: MySQL commits DDL implicitly and cannot roll it back here.
    transaction(function(): void {
        db()->query('SELECT name FROM settings ORDER BY name FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN);
        $query = db()->query("SELECT value FROM settings WHERE name='categories_initialized'");
        if ($query->fetchColumn() !== 'true') {
            $insert = db()->prepare('INSERT IGNORE INTO categories(id,name,parent_id,product_type,legacy_key,enabled,sort_order) VALUES(?,?,NULL,?,?,1,?)');
            foreach (categories_defaults() as $row) {
                $insert->execute([$row['id'],$row['name'],$row['productType'],$row['legacyKey'],$row['sortOrder']]);
            }
        }
        db()->exec('UPDATE products p JOIN categories c ON c.legacy_key=p.category SET p.category_id=c.id WHERE p.category_id IS NULL');
        db()->exec("INSERT IGNORE INTO role_permissions(role_id,permission) SELECT id,'categories.view' FROM roles WHERE id='owner'");
        db()->exec("INSERT IGNORE INTO role_permissions(role_id,permission) SELECT id,'categories.edit' FROM roles WHERE id='owner'");
    });
    if (!database_upgrade_foreign_key($snapshot,'products','category_id','categories')) {
        db()->exec('ALTER TABLE products ADD CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT');
    }
    // Set the completion marker only after the real schema, data mapping and
    // grants succeeded. Verify everything once more after writing the marker.
    $snapshot = database_upgrade_snapshot();
    $snapshot['categoriesInitialized'] = true;
    if (!database_upgrade_categories_complete($snapshot)) throw new RuntimeException('Category verification failed');
    db()->exec("INSERT INTO settings(name,value,updated_at) VALUES('categories_initialized','true',UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE value=VALUES(value),updated_at=UTC_TIMESTAMP()");
}

function database_upgrade_apply(array $input): array
{
    database_upgrade_require_owner();
    if ($input !== []) app_error('ارتقای دیتابیس ورودی یا فایل SQL نمی‌پذیرد.');
    $pdo = db();
    if ($pdo->inTransaction()) app_error('ارتقای دیتابیس اکنون ممکن نیست؛ دوباره تلاش کنید.',503);
    $database = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    $lockName = 'dezhawo.upgrade.' . substr(hash('sha256',$database),0,40);
    try {
        $query = $pdo->prepare('SELECT GET_LOCK(?,0)');
        $query->execute([$lockName]);
        $locked = $query->fetchColumn();
    } catch (Throwable $error) {
        error_log('DEZHAWO upgrade lock acquisition failure: ' . get_class($error) . ' code=' . $error->getCode());
        app_error('امکان شروع امن ارتقا در این هاست فراهم نشد؛ تنظیمات دیتابیس و دسترسی قفل‌گذاری را با پشتیبانی هاست بررسی کنید.',503);
    }
    if ((string)$locked === '0') {
        app_error('ارتقای دیگری در حال اجراست؛ کمی صبر کنید و صفحه را تازه کنید.',409);
    }
    if ((string)$locked !== '1') {
        app_error('امکان شروع امن ارتقا در این هاست فراهم نشد؛ تنظیمات دیتابیس و دسترسی قفل‌گذاری را با پشتیبانی هاست بررسی کنید.',503);
    }
    $applied = [];
    $currentId = '';
    try {
        database_upgrade_require_owner();
        // Bound waiting on live transactions instead of holding an upgrade
        // request open indefinitely. Safe reruns finish any completed steps.
        $pdo->exec('SET SESSION lock_wait_timeout=10');
        $pdo->exec('SET SESSION innodb_lock_wait_timeout=10');
        foreach (database_upgrade_registry() as $upgrade) {
            $currentId = $upgrade['id'];
            if ($upgrade['complete'](database_upgrade_snapshot())) continue;
            $upgrade['apply']();
            if (!$upgrade['complete'](database_upgrade_snapshot())) throw new RuntimeException('Upgrade verification failed');
            $applied[] = $upgrade['id'];
        }
        $status = database_upgrade_status();
        if ($status['required']) throw new RuntimeException('Upgrade remains incomplete');
        try {
            if ($applied) audit('database.upgrade','schema',['applied'=>$applied]);
        } catch (Throwable $error) {
            // A log failure must not misreport already verified DDL as failed.
            error_log('DEZHAWO upgrade audit failure: ' . get_class($error) . ' code=' . $error->getCode());
        }
        return ['message'=>$applied ? 'ارتقای دیتابیس با موفقیت انجام شد.' : 'دیتابیس به‌روز است؛ ارتقای جدیدی لازم نیست.',
            'databaseUpgrade'=>$status,'applied'=>$applied];
    } catch (Throwable $error) {
        // Query texts, connection details and underlying DB messages are private.
        error_log('DEZHAWO database upgrade failure: id=' . $currentId . ' type=' . get_class($error) . ' code=' . $error->getCode());
        app_error('ارتقای دیتابیس کامل نشد. ممکن است بخشی از مراحل انجام شده باشد؛ تلاش دوباره مراحل باقی‌مانده را ادامه می‌دهد. مجوزهای CREATE، ALTER، INDEX و REFERENCES کاربر دیتابیس و فضای هاست را بررسی کنید؛ اگر مشکل ادامه داشت با پشتیبانی هاست تماس بگیرید.',503);
    } finally {
        try {
            $query = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $query->execute([$lockName]);
        } catch (Throwable $error) {
            error_log('DEZHAWO database upgrade lock release failure: ' . get_class($error));
        }
    }
}

<?php
declare(strict_types=1);

/** Store taxonomy is independent of the legacy physical/digital order category. */
function categories_defaults(): array
{
    $names = ['case'=>'قاب','glass'=>'گلس','cable'=>'کابل','charger'=>'شارژر','audio'=>'هندزفری','digital'=>'اشتراک دیجیتال'];
    $rows = [];
    foreach ($names as $id=>$name) $rows[] = ['id'=>$id,'name'=>$name,'parentId'=>null,
        'productType'=>$id === 'digital' ? 'digital' : 'physical','legacyKey'=>$id,'enabled'=>true,'sortOrder'=>count($rows)];
    return $rows;
}

function categories_ready(): bool
{
    static $ready;
    if (is_bool($ready)) return $ready;
    $query = db()->query("SELECT EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='categories') AS has_categories, EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='products' AND COLUMN_NAME='category_id') AS has_product_category");
    $row = $query->fetch(PDO::FETCH_ASSOC);
    return $ready = !empty($row['has_categories']) && !empty($row['has_product_category']);
}

function categories_require_ready(): void
{
    if (!categories_ready()) app_error('برای فعال‌شدن دسته‌بندی‌ها، مدیر اصلی باید دکمهٔ «ارتقای دیتابیس» بالای سایت را بزند.',503);
}

function categories_map(bool $lock = false): array
{
    $rows = categories_ready()
        ? db()->query('SELECT id,name,parent_id,product_type,legacy_key,enabled,sort_order FROM categories ORDER BY sort_order,id' . ($lock ? ' FOR UPDATE' : ''))->fetchAll(PDO::FETCH_ASSOC)
        : [];
    $map = [];
    if (!categories_ready()) {
        foreach (categories_defaults() as $row) $map[$row['id']] = $row;
        return $map;
    }
    foreach ($rows as $row) $map[$row['id']] = ['id'=>(string)$row['id'],'name'=>$row['name'],
        'parentId'=>$row['parent_id'] !== null ? (string)$row['parent_id'] : null,'productType'=>$row['product_type'],
        'legacyKey'=>$row['legacy_key'],'enabled'=>(bool)$row['enabled'],'sortOrder'=>(int)$row['sort_order']];
    return $map;
}

/** Returns leaf-to-root rows, or [] for a missing parent/cycle. */
function category_lineage(string $id, array $map): array
{
    $lineage = [];
    $seen = [];
    while ($id !== '') {
        if (!isset($map[$id]) || isset($seen[$id])) return [];
        $seen[$id] = true;
        $lineage[] = $map[$id];
        $id = $map[$id]['parentId'] ?? '';
    }
    return $lineage;
}

function category_lineage_enabled(string $id, array $map): bool
{
    $lineage = category_lineage($id,$map);
    if (!$lineage) return false;
    foreach ($lineage as $row) if (!$row['enabled']) return false;
    return true;
}

function categories_all(bool $staff = false): array
{
    $map = categories_map();
    return array_values(array_filter($map,fn(array $row): bool => $staff || category_lineage_enabled($row['id'],$map)));
}

function category_public_ids(): array
{
    return array_column(categories_all(),'id');
}

function category_public_id(?string $categoryId, ?string $legacyCategory = null): ?string
{
    $id = $categoryId ?: $legacyCategory;
    if ($id === null || $id === '') return null;
    return category_lineage_enabled($id,categories_map()) ? $id : null;
}

function category_product_is_public(string $productId): bool
{
    if (!categories_ready()) return true;
    $query = db()->prepare('SELECT category_id,category FROM products WHERE id=?');
    $query->execute([$productId]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    return $row !== false && category_public_id($row['category_id'],$row['category']) !== null;
}

/** Resolve API input while supporting old clients which send only legacy category. */
function category_for_product(array $raw, ?array $existing, array $map): array
{
    $legacyKeys = ['case','glass','cable','charger','audio','digital'];
    if (array_key_exists('categoryId',$raw)) {
        categories_require_ready();
        $id = admin_id($raw['categoryId']);
    } elseif (array_key_exists('category',$raw)) {
        $legacy = admin_text($raw['category'],20);
        if (!in_array($legacy,$legacyKeys,true)) app_error('دسته‌بندی محصول معتبر نیست.');
        $matches = array_values(array_filter($map,fn(array $row): bool => $row['legacyKey'] === $legacy));
        $id = $matches[0]['id'] ?? $legacy;
    } elseif ($existing) {
        $id = ($existing['category_id'] ?? null) ?: $existing['category'];
    } else {
        app_error('دسته‌بندی محصول را انتخاب کنید.');
    }
    if (!isset($map[$id]) || !category_lineage_enabled($id,$map)) app_error('دسته‌بندی انتخاب‌شده یا یکی از شاخه‌های بالاتر غیرفعال است یا وجود ندارد.');
    $lineage = category_lineage($id,$map);
    $root = $lineage[count($lineage)-1];
    $legacy = $root['productType'] === 'digital' ? 'digital' : ($root['legacyKey'] ?: 'case');
    if ($legacy === 'digital' && $root['productType'] !== 'digital') $legacy = 'case';
    return [$legacy,$id];
}

function category_descendant_ids(string $id, array $map): array
{
    $ids = [$id];
    for ($i=0; $i<count($ids); $i++) {
        foreach ($map as $row) if ($row['parentId'] === $ids[$i] && !in_array($row['id'],$ids,true)) $ids[] = $row['id'];
    }
    return $ids;
}

function admin_category_save(array $input): array
{
    $actor = require_user();
    if (admin_role_id($actor) === 'owner') categories_require_ready();
    require_permission('categories.edit');
    categories_require_ready();
    $raw = admin_object($input['category'] ?? null,'دسته‌بندی');
    $id = admin_id($raw['id'] ?? '',false) ?: uid('cat');
    $name = admin_text($raw['name'] ?? '',100,'نام دسته‌بندی',true);
    $parentId = $raw['parentId'] ?? null;
    if ($parentId !== null && $parentId !== '') $parentId = admin_id($parentId);
    else $parentId = null;
    $enabled = array_key_exists('enabled',$raw) ? admin_bool($raw['enabled']) : true;
    $sortOrder = int_value($raw['sortOrder'] ?? 0,'ترتیب نمایش',0,1000000);
    return transaction(function() use($raw,$id,$name,$parentId,$enabled,$sortOrder): array {
        $map = categories_map(true);
        // MySQL's category key collation ignores letter case, while the tree
        // map uses exact keys. Reject aliases before an upsert can bypass the
        // existing branch's type, reservation or descendant checks.
        foreach ($map as $knownId=>$row) {
            if ($knownId !== $id && strcasecmp($knownId,$id) === 0) app_error('شناسهٔ دسته‌بندی را دقیقاً مطابق دستهٔ موجود وارد کنید.');
        }
        $existing = $map[$id] ?? null;
        $subtree = category_descendant_ids($id,$map);
        if ($parentId !== null && (!isset($map[$parentId]) || !category_lineage($parentId,$map))) app_error('دسته‌بندی بالاتر معتبر نیست.');
        if ($parentId !== null && in_array($parentId,$subtree,true)) app_error('نمی‌توانید دسته‌بندی را زیر خودش یا زیرشاخهٔ خودش قرار دهید.');
        $productType = $parentId !== null ? $map[$parentId]['productType'] : admin_text($raw['productType'] ?? ($existing['productType'] ?? 'physical'),20);
        if (!in_array($productType,['physical','digital'],true)) app_error('نوع دسته‌بندی باید کالای فیزیکی یا محصول دیجیتال باشد.');
        if ($parentId !== null && array_key_exists('productType',$raw) && $raw['productType'] !== $productType) app_error('نوع زیردسته باید با دسته‌بندی بالاتر یکسان باشد.');
        $typeChanged = array_filter($subtree,fn(string $child): bool => isset($map[$child]) && $map[$child]['productType'] !== $productType);
        if ($typeChanged) {
            $placeholders = implode(',',array_fill(0,count($subtree),'?'));
            $occupied = admin_one('SELECT id FROM products WHERE category_id IN (' . $placeholders . ') OR (category_id IS NULL AND category IN (' . $placeholders . ')) LIMIT 1 FOR UPDATE',array_merge($subtree,$subtree));
            if ($occupied) app_error('این شاخه محصول دارد؛ تغییر نوع یا انتقال بین کالای فیزیکی و دیجیتال مجاز نیست. ابتدا محصولات را به شاخهٔ هم‌نوع دیگری منتقل کنید.',409);
        }
        $candidateMap = $map;
        $candidateMap[$id] = ['id'=>$id,'name'=>$name,'parentId'=>$parentId,'productType'=>$productType,
            'legacyKey'=>$existing['legacyKey'] ?? null,'enabled'=>$enabled,'sortOrder'=>$sortOrder];
        $hiddenIds = array_values(array_filter($subtree,fn(string $child): bool =>
            category_lineage_enabled($child,$map) && !category_lineage_enabled($child,$candidateMap)));
        if ($hiddenIds) {
            $placeholders = implode(',',array_fill(0,count($hiddenIds),'?'));
            $productIds = admin_query('SELECT id FROM products WHERE category_id IN (' . $placeholders . ') OR (category_id IS NULL AND category IN (' . $placeholders . ')) ORDER BY id',array_merge($hiddenIds,$hiddenIds))->fetchAll(PDO::FETCH_COLUMN);
            foreach ($productIds as $productId) {
                // Match product disabling: serialize against checkout's variant
                // locks before checking active/payment-pending reservations.
                admin_one('SELECT id FROM products WHERE id=? FOR UPDATE',[$productId]);
                admin_assert_not_reserved(admin_variant_locks($productId));
            }
        }
        admin_query('INSERT INTO categories(id,name,parent_id,product_type,legacy_key,enabled,sort_order,created_at,updated_at) VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE name=VALUES(name),parent_id=VALUES(parent_id),product_type=VALUES(product_type),enabled=VALUES(enabled),sort_order=VALUES(sort_order),updated_at=UTC_TIMESTAMP()',[$id,$name,$parentId,$productType,$existing['legacyKey'] ?? null,(int)$enabled,$sortOrder]);
        if ($typeChanged) {
            $ids = array_values(array_filter($subtree,fn(string $child): bool => $child !== $id));
            if ($ids) admin_query('UPDATE categories SET product_type=?,updated_at=UTC_TIMESTAMP() WHERE id IN (' . implode(',',array_fill(0,count($ids),'?')) . ')',array_merge([$productType],$ids));
        }
        audit('category.save',$id,['parentId'=>$parentId,'enabled'=>$enabled,'productType'=>$productType]);
        return ['id'=>$id];
    });
}

function admin_category_delete(array $input): array
{
    $actor = require_user();
    if (admin_role_id($actor) === 'owner') categories_require_ready();
    require_permission('categories.edit');
    categories_require_ready();
    $id = admin_id($input['id'] ?? null);
    return transaction(function() use($id): array {
        $map = categories_map(true);
        if (!isset($map[$id])) app_error('دسته‌بندی پیدا نشد.',404);
        foreach ($map as $row) if ($row['parentId'] === $id) app_error('این دسته‌بندی زیرشاخه دارد؛ ابتدا زیرشاخه‌ها را منتقل یا حذف کنید.',409);
        if (admin_one('SELECT id FROM products WHERE category_id=? OR (category_id IS NULL AND category=?) LIMIT 1 FOR UPDATE',[$id,$map[$id]['legacyKey'] ?: $id])) app_error('این دسته‌بندی محصول دارد؛ ابتدا دسته‌بندی محصولات را تغییر دهید.',409);
        admin_query('DELETE FROM categories WHERE id=?',[$id]);
        audit('category.delete',$id);
        return ['id'=>$id];
    });
}

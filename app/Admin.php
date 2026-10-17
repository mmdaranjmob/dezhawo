<?php
declare(strict_types=1);

/** Administrative mutations and permission-filtered storefront state. */
function admin_text(mixed $value, int $max, string $label = '', bool $required = false): string
{
    if (!is_string($value) && !is_int($value) && !is_float($value) && $value !== null) {
        app_error(($label ?: 'مقدار') . ' معتبر نیست.');
    }
    $result = clean($value, $max);
    if ($required && $result === '') app_error(($label ?: 'مقدار') . ' را وارد کنید.');
    return $result;
}

function admin_id(mixed $value, bool $required = true): string
{
    $id = admin_text($value, 64);
    if (($id === '' && $required) || ($id !== '' && !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,63}\z/D', $id))) {
        app_error('شناسه معتبر نیست.');
    }
    return $id;
}

function admin_bool(mixed $value): bool
{
    if (!is_bool($value)) app_error('مقدار فعال یا غیرفعال باید معتبر باشد.');
    return $value;
}

function admin_object(mixed $value, string $label): array
{
    if (!is_array($value) || ($value !== [] && array_is_list($value))) app_error($label . ' معتبر نیست.');
    return $value;
}

function admin_query(string $sql, array $arguments = []): PDOStatement
{
    $query = db()->prepare($sql);
    $query->execute($arguments);
    return $query;
}

function admin_one(string $sql, array $arguments = []): ?array
{
    $row = admin_query($sql, $arguments)->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

function admin_role_id(array $user): string
{
    return (string)($user['roleId'] ?? $user['role_id'] ?? '');
}

function admin_user(array $row): array
{
    return ['id'=>(string)$row['id'], 'name'=>(string)$row['name'], 'phone'=>(string)$row['phone'],
        'roleId'=>admin_role_id($row), 'enabled'=>(bool)$row['enabled']];
}

function admin_roles(): array
{
    $roles = [];
    foreach (admin_query('SELECT id,name,protected FROM roles ORDER BY created_at,id')->fetchAll(PDO::FETCH_ASSOC) as $role) {
        $roles[$role['id']] = ['id'=>$role['id'], 'name'=>$role['name'], 'protected'=>(bool)$role['protected'], 'permissions'=>[]];
    }
    foreach (admin_query('SELECT role_id,permission FROM role_permissions ORDER BY role_id,permission')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($roles[$row['role_id']]) && in_array($row['permission'], permissions(), true)) {
            $roles[$row['role_id']]['permissions'][] = $row['permission'];
        }
    }
    return array_values($roles);
}

function admin_defaults(): array
{
    return ['brand'=>'DEZHAWO', 'tagline'=>'دژاوو؛ انتخابی که می‌ماند.', 'notice'=>'انتخاب‌های کوچک، برای روزهای بهتر',
        'phone'=>'', 'address'=>'', 'instagram'=>'', 'footerText'=>'دژاوو؛ لوازم جانبی برای هر روز، انتخابی برای ماندن.',
        'footerStoreTitle'=>'دنیای دژاوو', 'footerHelpTitle'=>'کنار تو هستیم',
        'headerLinks'=>[['label'=>'لوازم جانبی','target'=>'#catalog'],['label'=>'دنیای دیجیتال','target'=>'#digital'],
            ['label'=>'داستان دژاوو','target'=>'#story'],['label'=>'پشتیبانی','target'=>'#support']],
        'footerLinks'=>[['label'=>'لوازم جانبی موبایل','target'=>'#catalog','group'=>'store'],
            ['label'=>'اشتراک‌های دیجیتال','target'=>'#digital','group'=>'store'],
            ['label'=>'دربارهٔ دژاوو','target'=>'#story','group'=>'store'],
            ['label'=>'حساب کاربری و سفارش‌ها','target'=>'#account','group'=>'help'],
            ['label'=>'ثبت و پیگیری تیکت','target'=>'#support','group'=>'help'],
            ['label'=>'روش‌های ارسال','target'=>'#shipping-info','group'=>'help'],
            ['label'=>'راهنمای خرید و مرجوعی','target'=>'#policies','group'=>'help']],
        'enamadUrl'=>'', 'enamadEnabled'=>false, 'policiesText'=>'', 'refundPolicy'=>'', 'digitalPolicy'=>'',
        'heroProductIds'=>[]];
}

function admin_settings(): array
{
    $settings = admin_defaults();
    foreach (admin_query('SELECT name,value FROM settings')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (array_key_exists($row['name'], $settings)) {
            $decoded = json_decode($row['value'], true);
            if (json_last_error() === JSON_ERROR_NONE) $settings[$row['name']] = $decoded;
        }
    }
    return $settings;
}

function admin_shipping(array $row): array
{
    return ['id'=>$row['id'],'name'=>$row['name'],'description'=>$row['description'],'fee'=>(int)$row['fee'],
        'payOnDelivery'=>(bool)$row['pay_on_delivery'],'enabled'=>(bool)$row['enabled']];
}

function admin_products(bool $staff = false): array
{
    $products = [];
    $finance = can('finance.view');
    $taxonomyReady = categories_ready();
    $categoryMap = categories_map();
    foreach (admin_query('SELECT id,title,category,' . ($taxonomyReady ? 'category_id' : 'category AS category_id') . ',badge,description,art,image_url,price,cost,enabled FROM products' . ($staff ? '' : ' WHERE enabled=1') . ' ORDER BY created_at,id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $categoryId = $row['category_id'] ?: $row['category'];
        if (!$staff && !category_lineage_enabled($categoryId,$categoryMap)) continue;
        $product = ['id'=>$row['id'],'title'=>$row['title'],'category'=>$row['category'],'badge'=>$row['badge'],
            'categoryId'=>$categoryId,
            'description'=>$row['description'],'art'=>$row['art'],'imageUrl'=>product_image_public_url($row['image_url']),'price'=>(int)$row['price'],
            'enabled'=>(bool)$row['enabled'],'variants'=>[]];
        if ($finance) $product['cost'] = (int)$row['cost'];
        $products[$row['id']] = $product;
    }
    $reserved = [];
    foreach (admin_query("SELECT ri.variant_id,SUM(ri.quantity) AS quantity FROM reservation_items ri JOIN reservations r ON r.id=ri.reservation_id WHERE r.status IN ('active','payment_pending') AND r.expires_at>UTC_TIMESTAMP() GROUP BY ri.variant_id")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $reserved[$row['variant_id']] = (int)$row['quantity'];
    }
    foreach (admin_query('SELECT id,product_id,name,color,stock,price,enabled FROM product_variants' . ($staff ? '' : ' WHERE enabled=1') . ' ORDER BY created_at,id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!isset($products[$row['product_id']])) continue;
        $quantity = $reserved[$row['id']] ?? 0;
        $digital = $products[$row['product_id']]['category'] === 'digital';
        $available = $digital ? 99999 : max(0, (int)$row['stock'] - $quantity);
        if (!$row['enabled'] || !$products[$row['product_id']]['enabled'] || !category_lineage_enabled($products[$row['product_id']]['categoryId'],$categoryMap)) $available = 0;
        $variant = ['id'=>$row['id'],'name'=>$row['name'],'color'=>$row['color'],'stock'=>(int)$row['stock'],
            'available'=>$available,'reserved'=>$quantity,'enabled'=>(bool)$row['enabled']];
        if ($row['price'] !== null) $variant['price'] = (int)$row['price'];
        $products[$row['product_id']]['variants'][] = $variant;
    }
    return array_values($products);
}

function admin_order(array $row, array $lines): array
{
    $address = $row['address_json'] ? json_decode($row['address_json'], true) : null;
    $order = ['id'=>$row['id'],'number'=>$row['number'],'type'=>$row['type'],'status'=>$row['status'],
        'paymentStatus'=>$row['payment_status'],'userId'=>$row['user_id'],
        'customer'=>['name'=>$row['customer_name'],'phone'=>$row['customer_phone'] ?? '', 'telegramUsername'=>$row['telegram_username'] ?? ''],
        'address'=>is_array($address) ? $address : null,'lines'=>$lines,'subtotal'=>(int)$row['subtotal'],
        'shippingFee'=>(int)$row['shipping_fee'],'total'=>(int)$row['total'],'shippingId'=>$row['shipping_id'],
        'shippingName'=>$row['shipping_name'],'payOnDelivery'=>(bool)$row['pay_on_delivery'],
        'payment'=>$row['payment'],'trackingCode'=>$row['tracking_code'],'stockReturned'=>(bool)$row['stock_returned'],
        'createdAt'=>iso_time($row['created_at']),'updatedAt'=>iso_time($row['updated_at'])];
    if ($row['payment'] === 'bank_transfer') $order['manualPayment'] = manual_payment_details((string)$row['id']);
    if ($row['payment'] === 'digipay') $order['deliveryReport'] = digipay_delivery_details((string)$row['id']);
    return $order;
}

function admin_orders(?array $user): array
{
    if (!$user) return [];
    $viewAll = can('orders.view',$user);
    $finance = can('finance.view',$user);
    $rows = admin_query('SELECT * FROM orders' . ($viewAll ? '' : ' WHERE user_id=?') . ' ORDER BY created_at DESC,id DESC LIMIT 500', $viewAll ? [] : [$user['id']])->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return [];
    $ids = array_column($rows, 'id');
    $lines = [];
    foreach (admin_query('SELECT * FROM order_items WHERE order_id IN (' . implode(',', array_fill(0,count($ids),'?')) . ') ORDER BY id', $ids)->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $line = ['productId'=>$row['product_id'],'variantId'=>$row['variant_id'],'title'=>$row['title'],
            'variantName'=>$row['variant_name'],'category'=>$row['category'],'quantity'=>(int)$row['quantity'],'unitPrice'=>(int)$row['unit_price']];
        if ($finance) $line['unitCost'] = (int)$row['unit_cost'];
        $lines[$row['order_id']][] = $line;
    }
    return array_map(fn(array $row): array => admin_order($row,$lines[$row['id']] ?? []), $rows);
}

function admin_tickets(?array $user): array
{
    if (!$user) return [];
    $viewAll = can('tickets.view',$user);
    $rows = admin_query('SELECT * FROM tickets' . ($viewAll ? '' : ' WHERE user_id=?') . ' ORDER BY updated_at DESC,id DESC LIMIT 500', $viewAll ? [] : [$user['id']])->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return [];
    $messages = [];
    $ids = array_column($rows, 'id');
    $recentMessages = admin_query('SELECT id,ticket_id,user_id,user_name,text,staff,created_at FROM ticket_messages WHERE ticket_id IN (' . implode(',',array_fill(0,count($ids),'?')) . ') ORDER BY created_at DESC,id DESC LIMIT 5000', $ids)->fetchAll(PDO::FETCH_ASSOC);
    foreach (array_reverse($recentMessages) as $row) {
        $messages[$row['ticket_id']][] = ['id'=>$row['id'],'text'=>$row['text'],'staff'=>(bool)$row['staff'],
            'userId'=>$row['user_id'],'userName'=>$row['user_name'],'createdAt'=>iso_time($row['created_at'])];
    }
    return array_map(fn(array $row): array => ['id'=>$row['id'],'number'=>$row['number'],'subject'=>$row['subject'],
        'orderId'=>$row['order_id'],'userId'=>$row['user_id'],'status'=>$row['status'],
        'createdAt'=>iso_time($row['created_at']),'updatedAt'=>iso_time($row['updated_at']),
        'messages'=>$messages[$row['id']] ?? []], $rows);
}

function bootstrap_data(): array
{
    $user = current_user();
    $safeUser = $user ? admin_user($user) : null;
    if ($safeUser) $safeUser['permissions'] = array_values(array_filter(permissions(),fn(string $permission): bool => can($permission,$user)));
    $databaseUpgrade = database_upgrade_status();
    if ($databaseUpgrade['recovery']) {
        // Keep the same login and owner upgrade controls usable on older schema
        // versions. Do not query catalog/payment columns which are not present.
        $state = ['configured'=>true,'products'=>[],'categories'=>[],'shipping'=>[],
            'settings'=>admin_settings(),'paymentMethods'=>[],'paymentSettings'=>null,
            'currentUser'=>$safeUser,'users'=>[],
            'roles'=>$safeUser ? [['id'=>$safeUser['roleId'],'name'=>'','permissions'=>$safeUser['permissions']]] : [],
            'cart'=>[],'reservation'=>null,'orders'=>[],'movements'=>[],'tickets'=>[],
            'permissions'=>permissions(),
            'capabilities'=>['smsConfigured'=>sms_configured(),'paymentConfigured'=>false,
                'temporaryLoginEnabled'=>temporary_login_enabled(),'categoriesReady'=>false,'databaseUpgradeRequired'=>true],
            'metadata'=>['ordersLimit'=>500,'ticketsLimit'=>500,'ticketMessagesLimit'=>5000,'usersLimit'=>1000,'movementsLimit'=>1000]];
        if ($user && admin_role_id($user) === 'owner') $state['databaseUpgrade'] = $databaseUpgrade;
        return $state;
    }
    $staffProducts = can('products.view') || can('products.edit') || can('inventory.view') || can('inventory.edit') || can('pos.create');
    $roles = (can('roles.edit') || can('users.edit')) ? admin_roles() : [];
    // Frontend compatibility: a user's own role is safe; never expose other users by default.
    if ($safeUser && !$roles) $roles = [['id'=>$safeUser['roleId'],'name'=>'','permissions'=>$safeUser['permissions']]];
    $users = can('users.edit') ? array_map('admin_user',admin_query('SELECT id,name,phone,role_id,enabled FROM users ORDER BY created_at DESC,id LIMIT 1000')->fetchAll(PDO::FETCH_ASSOC)) : [];
    $cart = [];
    foreach (admin_query('SELECT product_id,variant_id,quantity FROM cart_items WHERE cart_key=? ORDER BY updated_at,variant_id',[cart_key()])->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cart[] = ['productId'=>$row['product_id'],'variantId'=>$row['variant_id'],'quantity'=>(int)$row['quantity']];
    }
    $reservation = admin_one("SELECT id,status,order_id,expires_at,created_at FROM reservations WHERE cart_key=? AND status IN ('active','payment_pending') AND expires_at>UTC_TIMESTAMP() ORDER BY created_at DESC,id DESC LIMIT 1",[cart_key()]);
    if ($reservation) {
        $lines = [];
        foreach (admin_query('SELECT product_id,variant_id,quantity FROM reservation_items WHERE reservation_id=? ORDER BY variant_id',[$reservation['id']])->fetchAll(PDO::FETCH_ASSOC) as $line) {
            $lines[] = ['productId'=>$line['product_id'],'variantId'=>$line['variant_id'],'quantity'=>(int)$line['quantity']];
        }
        $reservation = ['id'=>$reservation['id'],'status'=>$reservation['status'],'orderId'=>$reservation['order_id'],'expiresAt'=>(int)(strtotime($reservation['expires_at'].' UTC')*1000),
            'createdAt'=>iso_time($reservation['created_at']),'lines'=>$lines];
    }
    $movements = [];
    if (can('inventory.view')) {
        foreach (admin_query('SELECT id,product_id,variant_id,product_title,variant_name,delta,reason,user_id,user_name,order_id,created_at FROM stock_movements ORDER BY created_at DESC,id DESC LIMIT 1000')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $movements[] = ['id'=>$row['id'],'productId'=>$row['product_id'],'variantId'=>$row['variant_id'],
                'productTitle'=>$row['product_title'],'variantName'=>$row['variant_name'],'delta'=>(int)$row['delta'],
                'reason'=>$row['reason'],'userId'=>$row['user_id'],'userName'=>$row['user_name'],
                'orderId'=>$row['order_id'],'createdAt'=>iso_time($row['created_at'])];
        }
    }
    $smsConfigured = sms_configured();
    $paymentMethods = payment_methods_public();
    $paymentConfigured = count(array_filter($paymentMethods,fn(array $method): bool => $method['available'])) > 0;
    $state = ['configured'=>true,'products'=>admin_products($staffProducts),
        'categories'=>categories_all(can('categories.view') || can('categories.edit') || can('products.edit')),
        'shipping'=>array_map('admin_shipping',admin_query('SELECT * FROM shipping_methods' . (can('shipping.edit') ? '' : ' WHERE enabled=1') . ' ORDER BY created_at,id')->fetchAll(PDO::FETCH_ASSOC)),
        'settings'=>admin_settings(),'paymentMethods'=>$paymentMethods,
        'paymentSettings'=>can('settings.edit',$user) ? payment_options() : null,
        'currentUser'=>$safeUser,'users'=>$users,'roles'=>$roles,
        'cart'=>$cart,'reservation'=>$reservation,'orders'=>admin_orders($user),'movements'=>$movements,'tickets'=>admin_tickets($user),
        'permissions'=>permissions(),'capabilities'=>['smsConfigured'=>$smsConfigured,'paymentConfigured'=>$paymentConfigured,'temporaryLoginEnabled'=>temporary_login_enabled(),'categoriesReady'=>categories_ready(),'databaseUpgradeRequired'=>false],
        'metadata'=>['ordersLimit'=>500,'ticketsLimit'=>500,'ticketMessagesLimit'=>5000,'usersLimit'=>1000,'movementsLimit'=>1000]];
    if ($user && admin_role_id($user) === 'owner') $state['databaseUpgrade'] = $databaseUpgrade;
    return $state;
}

function admin_variant_locks(string $productId): array
{
    $ids = admin_query('SELECT id FROM product_variants WHERE product_id=? ORDER BY id',[$productId])->fetchAll(PDO::FETCH_COLUMN);
    $rows = [];
    foreach ($ids as $id) {
        $row = admin_one('SELECT * FROM product_variants WHERE id=? FOR UPDATE',[$id]);
        if ($row) $rows[$id] = $row;
    }
    return $rows;
}

function admin_reserved(string $variantId): int
{
    $row = admin_one("SELECT COALESCE(SUM(ri.quantity),0) AS quantity FROM reservation_items ri JOIN reservations r ON r.id=ri.reservation_id WHERE ri.variant_id=? AND r.status IN ('active','payment_pending') AND r.expires_at>UTC_TIMESTAMP()",[$variantId]);
    return (int)($row['quantity'] ?? 0);
}

function admin_assert_not_reserved(array $variants): void
{
    foreach ($variants as $variant) {
        if (admin_reserved($variant['id']) > 0) app_error('این کالا رزرو فعال دارد؛ پس از پایان رزرو دوباره اقدام کنید.',409);
    }
}

function admin_product_save(array $input): array
{
    $user = require_permission('products.edit');
    $raw = admin_object($input['product'] ?? null,'محصول');
    $id = admin_id($raw['id'] ?? '',false) ?: uid('prd');
    $title = admin_text($raw['title'] ?? '',160,'عنوان محصول',true);
    [$category,$categoryId] = category_for_product($raw,null,categories_map());
    $price = int_value($raw['price'] ?? null,'قیمت');
    $badge = admin_text($raw['badge'] ?? '',50);
    $description = admin_text($raw['description'] ?? '',6000);
    $stockReason = admin_text($raw['stockReason'] ?? 'ثبت موجودی در ویرایش محصول',300,'دلیل تغییر موجودی',true);
    $art = admin_text($raw['art'] ?? ($category==='digital'?'telegram':$category),20);
    if (!in_array($art,['case','glass','cable','charger','audio','telegram'],true)) app_error('نمای محصول معتبر نیست.');
    $imagePath = array_key_exists('imageUrl', $raw) ? product_image_path($raw['imageUrl']) : null;
    if (!isset($raw['variants']) || !is_array($raw['variants']) || !array_is_list($raw['variants']) || count($raw['variants']) < 1 || count($raw['variants']) > 100) app_error('بین یک تا صد رنگ یا گزینه وارد کنید.');
    $variants = [];
    foreach ($raw['variants'] as $value) {
        $variant = admin_object($value,'گزینه محصول');
        $variantId = admin_id($variant['id'] ?? '',false) ?: uid('var');
        if (isset($variants[$variantId])) app_error('شناسه رنگ تکراری است.');
        $name = admin_text($variant['name'] ?? '',80,'نام رنگ یا گزینه',true);
        $color = admin_text($variant['color'] ?? '#888888',7);
        if (!preg_match('/\A#[0-9a-fA-F]{6}\z/D',$color)) app_error('رنگ معتبر نیست.');
        $variants[$variantId] = ['id'=>$variantId,'name'=>$name,'color'=>strtolower($color),
            'stock'=>array_key_exists('stock',$variant) ? int_value($variant['stock'],'موجودی',0,1000000000) : null,
            'price'=>isset($variant['price']) && $variant['price']!=='' ? int_value($variant['price'],'قیمت رنگ') : null,
            'enabled'=>array_key_exists('enabled',$variant) ? admin_bool($variant['enabled']) : true];
    }
    return transaction(function() use ($raw,$id,$title,$price,$badge,$description,$art,$imagePath,$variants,$user,$stockReason): array {
        // Category mutations take these locks first as well: no branch can be
        // disabled/deleted or change product type during product assignment.
        $categoryMap = categories_map(categories_ready());
        $existing = admin_one('SELECT * FROM products WHERE id=? FOR UPDATE',[$id]);
        [$category,$categoryId] = category_for_product($raw,$existing,$categoryMap);
        $imagePath = array_key_exists('imageUrl', $raw) ? $imagePath : ($existing['image_url'] ?? null);
        $existingVariants = $existing ? admin_variant_locks($id) : [];
        $enabled = array_key_exists('enabled',$raw) ? admin_bool($raw['enabled']) : (bool)($existing['enabled'] ?? 1);
        if ($existing && $existing['enabled'] && !$enabled) {
            require_permission('products.delete');
            admin_assert_not_reserved($existingVariants);
        }
        $cost = (int)($existing['cost'] ?? 0);
        if (array_key_exists('cost',$raw)) {
            $newCost = int_value($raw['cost'],'قیمت خرید');
            if ($newCost !== $cost) require_permission('finance.view');
            $cost = $newCost;
        }
        if ($existing && $existing['category'] !== $category) {
            admin_assert_not_reserved($existingVariants);
            if (!array_key_exists('categoryId',$raw) || (($existing['category'] === 'digital') !== ($category === 'digital'))) {
                foreach ($existingVariants as $variant) {
                    if ((int)$variant['stock'] !== 0) app_error('برای تغییر نوع دسته‌بندی، ابتدا موجودی رنگ‌ها را صفر کنید.',409);
                }
            }
        }
        $removed = array_diff_key($existingVariants,$variants);
        foreach ($removed as $variant) {
            if ($variant['enabled']) { require_permission('products.delete'); admin_assert_not_reserved([$variant]); }
        }
        foreach ($variants as $variantId=>$variant) {
            $old = $existingVariants[$variantId] ?? null;
            if (!$old && admin_one('SELECT id FROM product_variants WHERE id=?',[$variantId])) app_error('شناسه رنگ به محصول دیگری تعلق دارد.');
            if ($old && $old['enabled'] && !$variant['enabled']) {
                require_permission('products.delete'); admin_assert_not_reserved([$old]);
            }
            $stock = $variant['stock'] ?? (int)($old['stock'] ?? 0);
            if ($stock !== (int)($old['stock'] ?? 0)) require_permission('inventory.edit');
            if ($category !== 'digital' && $stock < admin_reserved($variantId)) app_error('موجودی جدید از تعداد رزروشده کمتر است.',409);
        }
        if ($existing) {
            admin_query('UPDATE products SET title=?,category=?,badge=?,description=?,art=?,image_url=?,price=?,cost=?,enabled=?,updated_at=UTC_TIMESTAMP() WHERE id=?',[$title,$category,$badge,$description,$art,$imagePath,$price,$cost,(int)$enabled,$id]);
        } else {
            admin_query('INSERT INTO products(id,title,category,badge,description,art,image_url,price,cost,enabled,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())',[$id,$title,$category,$badge,$description,$art,$imagePath,$price,$cost,(int)$enabled]);
        }
        if (categories_ready()) admin_query('UPDATE products SET category_id=? WHERE id=?',[$categoryId,$id]);
        foreach ($removed as $variant) admin_query('UPDATE product_variants SET enabled=0,updated_at=UTC_TIMESTAMP() WHERE id=?',[$variant['id']]);
        foreach ($variants as $variantId=>$variant) {
            $old = $existingVariants[$variantId] ?? null;
            $stock = $variant['stock'] ?? (int)($old['stock'] ?? 0);
            if ($old) {
                admin_query('UPDATE product_variants SET name=?,color=?,stock=?,price=?,enabled=?,updated_at=UTC_TIMESTAMP() WHERE id=?',[$variant['name'],$variant['color'],$stock,$variant['price'],(int)$variant['enabled'],$variantId]);
            } else {
                admin_query('INSERT INTO product_variants(id,product_id,name,color,stock,price,enabled,created_at,updated_at) VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())',[$variantId,$id,$variant['name'],$variant['color'],$stock,$variant['price'],(int)$variant['enabled']]);
            }
            $delta = $stock - (int)($old['stock'] ?? 0);
            if ($delta !== 0) admin_query('INSERT INTO stock_movements(id,product_id,variant_id,product_title,variant_name,delta,reason,user_id,user_name,created_at) VALUES(?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())',[uid('mov'),$id,$variantId,$title,$variant['name'],$delta,$stockReason,$user['id'],$user['name']]);
        }
        audit('product.save',$id,['variantCount'=>count($variants),'enabled'=>$enabled]);
        return ['id'=>$id];
    });
}

function admin_product_enabled(array $input, bool $delete = false): array
{
    $id = admin_id($input['id'] ?? null);
    $enabled = $delete ? false : admin_bool($input['enabled'] ?? null);
    require_permission($enabled ? 'products.edit' : 'products.delete');
    return transaction(function() use($id,$enabled,$delete): array {
        $product = admin_one('SELECT id,enabled FROM products WHERE id=? FOR UPDATE',[$id]);
        if (!$product) app_error('محصول پیدا نشد.',404);
        $variants = admin_variant_locks($id);
        if (!$enabled) admin_assert_not_reserved($variants);
        admin_query('UPDATE products SET enabled=?,updated_at=UTC_TIMESTAMP() WHERE id=?',[(int)$enabled,$id]);
        audit($delete?'product.delete':'product.enabled',$id,['enabled'=>$enabled]);
        return ['id'=>$id,'enabled'=>$enabled];
    });
}

function admin_variant_enabled(array $input): array
{
    $productId = admin_id($input['productId'] ?? null);
    $variantId = admin_id($input['variantId'] ?? null);
    $enabled = admin_bool($input['enabled'] ?? null);
    require_permission($enabled ? 'products.edit' : 'products.delete');
    return transaction(function() use($productId,$variantId,$enabled): array {
        if (!admin_one('SELECT id FROM products WHERE id=? FOR UPDATE',[$productId])) app_error('محصول پیدا نشد.',404);
        $variant = admin_one('SELECT id,product_id FROM product_variants WHERE id=? FOR UPDATE',[$variantId]);
        if (!$variant || $variant['product_id'] !== $productId) app_error('رنگ پیدا نشد.',404);
        if (!$enabled) admin_assert_not_reserved([$variant]);
        admin_query('UPDATE product_variants SET enabled=?,updated_at=UTC_TIMESTAMP() WHERE id=?',[(int)$enabled,$variantId]);
        audit('variant.enabled',$variantId,['enabled'=>$enabled]);
        return ['id'=>$variantId,'enabled'=>$enabled];
    });
}

function admin_url(mixed $value, bool $fragment = false, bool $enamad = false): string
{
    $url = admin_text($value,1500);
    if ($url === '') return '';
    if ($fragment && preg_match('/\A#[A-Za-z0-9_-]*\z/D',$url)) return $url;
    $parsed = parse_url($url);
    if (!$parsed || ($parsed['scheme'] ?? '') !== 'https' || !isset($parsed['host']) || isset($parsed['user']) || isset($parsed['pass']) || !filter_var($url,FILTER_VALIDATE_URL)) app_error('لینک باید یک آدرس HTTPS معتبر باشد.');
    if ($enamad) {
        $host = strtolower($parsed['host']);
        if (($host !== 'enamad.ir' && !str_ends_with($host,'.enamad.ir')) || (isset($parsed['port']) && $parsed['port'] !== 443)) app_error('لینک اینماد باید متعلق به دامنه رسمی enamad.ir باشد.');
    }
    return $url;
}

function admin_settings_save(array $input): array
{
    require_user();
    $raw = admin_object($input['settings'] ?? null,'تنظیمات');
    $allowed = admin_defaults();
    $changes = [];
    foreach ($raw as $key=>$value) {
        if (!array_key_exists($key,$allowed)) app_error('فیلد تنظیمات مجاز نیست.');
        $permission = str_starts_with($key,'enamad') ? 'enamad.edit' : (in_array($key,['policiesText','refundPolicy','digitalPolicy','heroProductIds'],true) ? 'settings.edit' : 'appearance.edit');
        require_permission($permission);
        if ($key === 'heroProductIds') {
            if (!is_array($value) || !array_is_list($value) || count($value) > 9) app_error('حداکثر ۹ محصول را به ترتیب برای ویترین صفحه اول انتخاب کنید.');
            $ids = [];
            foreach ($value as $id) {
                if (!is_string($id) || $id === '' || trim($id) !== $id) app_error('شناسهٔ محصول ویترین معتبر نیست.');
                $id = admin_id($id);
                if (in_array($id,$ids,true)) app_error('یک محصول را بیش از یک بار در ویترین انتخاب نکنید.');
                $ids[] = $id;
            }
            $changes[$key] = $ids;
            continue;
        }
        if ($key === 'enamadEnabled') { $changes[$key] = admin_bool($value); continue; }
        if ($key === 'instagram' || $key === 'enamadUrl') { $changes[$key] = admin_url($value,false,$key==='enamadUrl'); continue; }
        if ($key === 'headerLinks' || $key === 'footerLinks') {
            if (!is_array($value) || !array_is_list($value) || count($value) > ($key==='headerLinks'?12:24)) app_error('فهرست لینک‌ها معتبر نیست.');
            $links = [];
            foreach ($value as $link) {
                $link = admin_object($link,'لینک');
                $label = admin_text($link['label'] ?? '',80,'عنوان لینک',true);
                $target = admin_url($link['target'] ?? '',true);
                if ($target === '') app_error('مقصد لینک را وارد کنید.');
                $item = ['label'=>$label,'target'=>$target];
                if ($key === 'footerLinks') {
                    $group = admin_text($link['group'] ?? '',10);
                    if (!in_array($group,['store','help'],true)) app_error('گروه لینک معتبر نیست.');
                    $item['group'] = $group;
                }
                $links[] = $item;
            }
            $changes[$key] = $links;
            continue;
        }
        $changes[$key] = admin_text($value,in_array($key,['address','footerText','policiesText','refundPolicy','digitalPolicy'],true)?6000:300);
    }
    return transaction(function() use($changes): array {
        // Serialize settings merges, including linked URL/enabled validation.
        admin_query('SELECT name FROM settings ORDER BY name FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($changes['heroProductIds'])) {
            $ids = $changes['heroProductIds'];
            $visible = admin_query('SELECT p.id FROM products p WHERE p.id IN (' . implode(',',array_fill(0,count($ids),'?')) . ') AND p.enabled=1 AND EXISTS(SELECT 1 FROM product_variants v WHERE v.product_id=p.id AND v.enabled=1) ORDER BY p.id FOR UPDATE',$ids)->fetchAll(PDO::FETCH_COLUMN);
            if (count($visible) !== count($ids)) app_error('محصول ویترین باید فعال و دارای حداقل یک رنگ یا گزینهٔ فعال باشد؛ انتخاب‌های غیرفعال یا حذف‌شده را جایگزین کنید.');
            foreach ($ids as $id) if (!category_product_is_public($id)) app_error('دسته‌بندی محصول ویترین یا یکی از شاخه‌های بالاتر غیرفعال است؛ محصول دیگری انتخاب کنید.');
        }
        $settings = array_replace(admin_settings(),$changes);
        if ($settings['enamadEnabled'] && $settings['enamadUrl'] === '') app_error('برای نمایش اینماد لینک رسمی را وارد کنید.');
        foreach ($changes as $key=>$value) admin_query('INSERT INTO settings(name,value,updated_at) VALUES(?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE value=VALUES(value),updated_at=UTC_TIMESTAMP()',[$key,json_value($value)]);
        audit('settings.save','settings',['fields'=>array_keys($changes)]);
        return ['settings'=>$settings];
    });
}

function admin_shipping_save(array $input): array
{
    require_permission('shipping.edit');
    $raw = admin_object($input['shipping'] ?? null,'روش ارسال');
    $id = admin_id($raw['id'] ?? '',false) ?: uid('ship');
    $name = admin_text($raw['name'] ?? '',100,'نام روش ارسال',true);
    $description = admin_text($raw['description'] ?? '',500);
    $fee = int_value($raw['fee'] ?? 0,'هزینه ارسال');
    $cod = array_key_exists('payOnDelivery',$raw) ? admin_bool($raw['payOnDelivery']) : false;
    $enabled = array_key_exists('enabled',$raw) ? admin_bool($raw['enabled']) : true;
    if ($cod) $fee = 0;
    return transaction(function() use($id,$name,$description,$fee,$cod,$enabled): array {
        admin_query('INSERT INTO shipping_methods(id,name,description,fee,pay_on_delivery,enabled,created_at,updated_at) VALUES(?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),fee=VALUES(fee),pay_on_delivery=VALUES(pay_on_delivery),enabled=VALUES(enabled),updated_at=UTC_TIMESTAMP()',[$id,$name,$description,$fee,(int)$cod,(int)$enabled]);
        audit('shipping.save',$id,['enabled'=>$enabled,'payOnDelivery'=>$cod]);
        return ['id'=>$id];
    });
}

function admin_shipping_disable(array $input): array
{
    require_permission('shipping.edit');
    $id = admin_id($input['id'] ?? null);
    return transaction(function() use($id): array {
        if (!admin_one('SELECT id FROM shipping_methods WHERE id=? FOR UPDATE',[$id])) app_error('روش ارسال پیدا نشد.',404);
        admin_query('UPDATE shipping_methods SET enabled=0,updated_at=UTC_TIMESTAMP() WHERE id=?',[$id]);
        audit('shipping.disable',$id);
        return ['id'=>$id,'enabled'=>false];
    });
}

function admin_lock_roles(): void
{
    // All role/user mutations take the same short lock sequence to protect the last owner.
    $ids = admin_query('SELECT id FROM roles ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) admin_one('SELECT id FROM roles WHERE id=? FOR UPDATE',[$id]);
}

function admin_role_permissions(string $id): array
{
    return admin_query('SELECT permission FROM role_permissions WHERE role_id=? ORDER BY permission',[$id])->fetchAll(PDO::FETCH_COLUMN);
}

function admin_assert_assignable(array $actor, array $role): void
{
    $actorOwner = admin_role_id($actor) === 'owner';
    if ($role['id'] === 'owner' && !$actorOwner) app_error('فقط مدیر اصلی می‌تواند نقش مدیر اصلی را تخصیص دهد.',403);
    $targetPermissions = admin_role_permissions($role['id']);
    if (!$actorOwner) {
        foreach ($targetPermissions as $permission) if (!can($permission,$actor)) app_error('نمی‌توانید دسترسی بالاتر از نقش خود تخصیص دهید.',403);
    }
}

function admin_protect_owner(array $actor, array $target): void
{
    if (admin_role_id($target) === 'owner' && admin_role_id($actor) !== 'owner') app_error('تغییر حساب مدیر اصلی مجاز نیست.',403);
}

function admin_assert_owner_remains(array $target): void
{
    if (admin_role_id($target) !== 'owner' || !$target['enabled']) return;
    $owners = admin_query("SELECT id FROM users WHERE role_id='owner' AND enabled=1 ORDER BY id FOR UPDATE")->fetchAll(PDO::FETCH_COLUMN);
    if (in_array($target['id'],$owners,true) && count($owners) <= 1) app_error('آخرین مدیر اصلی قابل حذف یا سلب دسترسی نیست.',409);
}

function admin_role_save(array $input): array
{
    $actor = require_permission('roles.edit');
    $raw = admin_object($input['role'] ?? null,'نقش');
    $id = admin_id($raw['id'] ?? '',false) ?: uid('role');
    $name = admin_text($raw['name'] ?? '',100,'نام نقش',true);
    $perms = $raw['permissions'] ?? null;
    if (!is_array($perms) || !array_is_list($perms) || count($perms) > count(permissions())) app_error('فهرست دسترسی‌ها معتبر نیست.');
    foreach ($perms as $permission) {
        if (!is_string($permission) || !in_array($permission,permissions(),true)) app_error('دسترسی ناشناخته است.');
        if (admin_role_id($actor) !== 'owner' && !can($permission,$actor)) app_error('نمی‌توانید دسترسی بالاتر از نقش خود ایجاد کنید.',403);
    }
    $perms = array_values(array_unique($perms));
    sort($perms);
    return transaction(function() use($actor,$id,$name,$perms): array {
        admin_lock_roles();
        $actor = require_permission('roles.edit');
        foreach ($perms as $permission) {
            if (admin_role_id($actor) !== 'owner' && !can($permission,$actor)) app_error('نمی‌توانید دسترسی بالاتر از نقش خود ایجاد کنید.',403);
        }
        $existing = admin_one('SELECT id,name,protected FROM roles WHERE id=?',[$id]);
        if ($id === 'owner') {
            if (admin_role_id($actor) !== 'owner') app_error('ویرایش نقش مدیر اصلی مجاز نیست.',403);
            $expected = permissions(); sort($expected);
            if ($perms !== $expected) app_error('مدیر اصلی باید همه دسترسی‌ها را داشته باشد.');
        }
        if ($id === 'customer' && $perms !== []) app_error('نقش پایه مشتری نمی‌تواند دسترسی مدیریتی داشته باشد.');
        if ($existing) admin_query('UPDATE roles SET name=? WHERE id=?',[$name,$id]);
        else admin_query('INSERT INTO roles(id,name,protected,created_at) VALUES(?,?,0,UTC_TIMESTAMP())',[$id,$name]);
        admin_query('DELETE FROM role_permissions WHERE role_id=?',[$id]);
        foreach ($perms as $permission) admin_query('INSERT INTO role_permissions(role_id,permission) VALUES(?,?)',[$id,$permission]);
        audit('role.save',$id,['permissions'=>$perms]);
        return ['id'=>$id,'name'=>$name,'permissions'=>$perms];
    });
}

function admin_role_delete(array $input): array
{
    $actor = require_permission('roles.edit');
    $id = admin_id($input['id'] ?? null);
    $replacementId = admin_id($input['replacementId'] ?? '',false);
    if ($replacementId === $id) app_error('نقش جایگزین باید متفاوت باشد.');
    return transaction(function() use($actor,$id,$replacementId): array {
        admin_lock_roles();
        $actor = require_permission('roles.edit');
        $role = admin_one('SELECT id,protected FROM roles WHERE id=?',[$id]);
        if (!$role) app_error('نقش پیدا نشد.',404);
        if ($role['protected'] || in_array($id,['owner','customer'],true)) app_error('نقش پایه قابل حذف نیست.');
        $users = admin_query('SELECT id,role_id,enabled FROM users WHERE role_id=? ORDER BY id FOR UPDATE',[$id])->fetchAll(PDO::FETCH_ASSOC);
        if ($users) {
            $replacement = $replacementId ? admin_one('SELECT id FROM roles WHERE id=?',[$replacementId]) : null;
            if (!$replacement) app_error('برای کاربران این نقش، نقش جایگزین تعیین کنید.');
            admin_assert_assignable($actor,$replacement);
        }
        audit('role.delete',$id,['replacementId'=>$replacementId,'usersAffected'=>count($users)]);
        if ($users) {
            admin_query('UPDATE users SET role_id=?,updated_at=UTC_TIMESTAMP() WHERE role_id=?',[$replacementId,$id]);
        }
        admin_query('DELETE FROM role_permissions WHERE role_id=?',[$id]);
        admin_query('DELETE FROM roles WHERE id=?',[$id]);
        return ['id'=>$id];
    });
}

function admin_user_role(array $input): array
{
    $actor = require_permission('users.edit');
    $id = admin_id($input['userId'] ?? null);
    $roleId = admin_id($input['roleId'] ?? null);
    return transaction(function() use($actor,$id,$roleId): array {
        admin_lock_roles();
        $actor = require_permission('users.edit');
        $target = admin_one('SELECT id,name,phone,role_id,enabled FROM users WHERE id=? FOR UPDATE',[$id]);
        $role = admin_one('SELECT id FROM roles WHERE id=?',[$roleId]);
        if (!$target || !$role) app_error('کاربر یا نقش پیدا نشد.',404);
        admin_protect_owner($actor,$target);
        admin_assert_assignable($actor,$role);
        if ($roleId !== 'owner') admin_assert_owner_remains($target);
        audit('user.role',$id,['from'=>$target['role_id'],'to'=>$roleId]);
        admin_query('UPDATE users SET role_id=?,updated_at=UTC_TIMESTAMP() WHERE id=?',[$roleId,$id]);
        $target['role_id'] = $roleId;
        return admin_user($target);
    });
}

function admin_user_enabled(array $input): array
{
    $actor = require_permission('users.edit');
    $id = admin_id($input['userId'] ?? null);
    $enabled = admin_bool($input['enabled'] ?? null);
    return transaction(function() use($actor,$id,$enabled): array {
        admin_lock_roles();
        $actor = require_permission('users.edit');
        $target = admin_one('SELECT id,name,phone,role_id,enabled FROM users WHERE id=? FOR UPDATE',[$id]);
        if (!$target) app_error('کاربر پیدا نشد.',404);
        admin_protect_owner($actor,$target);
        if (!$enabled) admin_assert_owner_remains($target);
        audit('user.enabled',$id,['enabled'=>$enabled]);
        admin_query('UPDATE users SET enabled=?,updated_at=UTC_TIMESTAMP() WHERE id=?',[(int)$enabled,$id]);
        $target['enabled'] = (int)$enabled;
        return admin_user($target);
    });
}

function admin_user_create(array $input): array
{
    $actor = require_permission('users.edit');
    $name = admin_text($input['name'] ?? '',100,'نام کاربر',true);
    $phone = phone_value($input['phone'] ?? '');
    $roleId = admin_id($input['roleId'] ?? null);
    if ($roleId === 'customer') app_error('این فرم برای ایجاد حساب کارکنان است.');
    return transaction(function() use($actor,$name,$phone,$roleId): array {
        admin_lock_roles();
        $actor = require_permission('users.edit');
        $role = admin_one('SELECT id FROM roles WHERE id=?',[$roleId]);
        if (!$role) app_error('نقش پیدا نشد.',404);
        admin_assert_assignable($actor,$role);
        if (admin_one('SELECT id FROM users WHERE phone=?',[$phone])) app_error('این شماره قبلاً ثبت شده؛ نقش حساب موجود را تغییر دهید.',409);
        $id = uid('usr');
        admin_query('INSERT INTO users(id,name,phone,password_hash,role_id,enabled,created_at,updated_at) VALUES(?,?,?,NULL,?,1,UTC_TIMESTAMP(),UTC_TIMESTAMP())',[$id,$name,$phone,$roleId]);
        audit('user.create',$id,['roleId'=>$roleId]);
        return ['id'=>$id,'name'=>$name,'phone'=>$phone,'roleId'=>$roleId,'enabled'=>true];
    });
}

function admin_ticket_create(array $input): array
{
    $user = require_user();
    $subject = admin_text($input['subject'] ?? '',150,'موضوع',true);
    $message = admin_text($input['message'] ?? '',6000,'متن تیکت',true);
    $orderId = admin_id($input['orderId'] ?? '',false);
    return transaction(function() use($user,$subject,$message,$orderId): array {
        if ($orderId && !admin_one('SELECT id FROM orders WHERE id=? AND user_id=?',[$orderId,$user['id']])) app_error('سفارش متعلق به حساب شما نیست.',403);
        $id = uid('tkt');
        $number = 'DZ-T-' . strtoupper(bin2hex(random_bytes(6)));
        admin_query("INSERT INTO tickets(id,number,subject,order_id,user_id,status,created_at,updated_at) VALUES(?,?,?,?,?,'open',UTC_TIMESTAMP(),UTC_TIMESTAMP())",[$id,$number,$subject,$orderId ?: null,$user['id']]);
        admin_query('INSERT INTO ticket_messages(id,ticket_id,user_id,user_name,text,staff,created_at) VALUES(?,?,?,?,?,0,UTC_TIMESTAMP(6))',[uid('msg'),$id,$user['id'],$user['name'],$message]);
        audit('ticket.create',$id);
        return ['id'=>$id,'number'=>$number,'status'=>'open'];
    });
}

function admin_ticket_reply(array $input): array
{
    $user = require_user();
    $id = admin_id($input['id'] ?? null);
    $message = admin_text($input['message'] ?? '',6000,'متن پاسخ',true);
    return transaction(function() use($user,$id,$message): array {
        $ticket = admin_one('SELECT id,user_id,status FROM tickets WHERE id=? FOR UPDATE',[$id]);
        if (!$ticket) app_error('تیکت پیدا نشد.',404);
        $staff = can('tickets.reply',$user);
        if ($ticket['user_id'] !== $user['id'] && !$staff) app_error('دسترسی این تیکت را ندارید.',403);
        if ($ticket['status'] === 'closed') app_error('تیکت بسته است؛ تیکت جدید ثبت کنید یا از پشتیبانی بخواهید آن را باز کند.',409);
        admin_query('INSERT INTO ticket_messages(id,ticket_id,user_id,user_name,text,staff,created_at) VALUES(?,?,?,?,?,?,UTC_TIMESTAMP(6))',[uid('msg'),$id,$user['id'],$user['name'],$message,(int)$staff]);
        $status = $staff ? 'waiting' : 'open';
        admin_query('UPDATE tickets SET status=?,updated_at=UTC_TIMESTAMP() WHERE id=?',[$status,$id]);
        audit('ticket.reply',$id,['staff'=>$staff]);
        return ['id'=>$id,'status'=>$status];
    });
}

function admin_ticket_status(array $input): array
{
    require_permission('tickets.reply');
    $id = admin_id($input['id'] ?? null);
    $status = admin_text($input['status'] ?? '',20);
    if (!in_array($status,['open','waiting','closed'],true)) app_error('وضعیت تیکت معتبر نیست.');
    return transaction(function() use($id,$status): array {
        if (!admin_one('SELECT id FROM tickets WHERE id=? FOR UPDATE',[$id])) app_error('تیکت پیدا نشد.',404);
        admin_query('UPDATE tickets SET status=?,updated_at=UTC_TIMESTAMP() WHERE id=?',[$status,$id]);
        audit('ticket.status',$id,['status'=>$status]);
        return ['id'=>$id,'status'=>$status];
    });
}

function admin_action(string $action, array $input): mixed
{
    return match($action) {
        'category.save'=>admin_category_save($input), 'category.delete'=>admin_category_delete($input),
        'product.save'=>admin_product_save($input), 'product.enabled'=>admin_product_enabled($input),
        'product.delete'=>admin_product_enabled($input,true), 'variant.enabled'=>admin_variant_enabled($input),
        'settings.save'=>admin_settings_save($input), 'payments.settings'=>payment_settings_save($input),
        'shipping.save'=>admin_shipping_save($input),
        'shipping.disable'=>admin_shipping_disable($input), 'role.save'=>admin_role_save($input),
        'role.delete'=>admin_role_delete($input), 'user.role'=>admin_user_role($input),
        'user.enabled'=>admin_user_enabled($input),
        'user.create'=>admin_user_create($input), 'ticket.create'=>admin_ticket_create($input),
        'ticket.reply'=>admin_ticket_reply($input), 'ticket.status'=>admin_ticket_status($input),
        default=>app_error('عملیات پشتیبانی نمی‌شود.',404),
    };
}

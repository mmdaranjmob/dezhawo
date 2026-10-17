<?php
declare(strict_types=1);

/** Merchant photos only. No remote downloads, original names or executable formats. */
function product_image_prefix(): string
{
    $path = parse_url((string)(config()['app']['base_url'] ?? ''), PHP_URL_PATH);
    return rtrim(is_string($path) ? $path : '', '/') . '/';
}

function product_image_public_url(?string $path): ?string
{
    if ($path === null || !preg_match('~\Auploads/products/[a-f0-9]{48}\.(?:jpg|png|webp)\z~D', $path)) return null;
    return product_image_prefix() . $path;
}

/** Metadata inspection only; legacy stores keep their existing primary photo. */
function product_gallery_ready(): bool
{
    static $ready;
    if (is_bool($ready)) return $ready;
    return $ready = (bool)db()->query("SELECT EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='products' AND COLUMN_NAME='gallery_json')")->fetchColumn();
}

/** Stored image paths are never trusted as arbitrary URLs or HTML. */
function product_gallery_stored_paths(mixed $storedJson, ?string $mainPath = null): array
{
    if (!is_string($storedJson)) return [];
    $values = json_decode($storedJson, true);
    if (!is_array($values) || !array_is_list($values)) return [];
    $paths = [];
    foreach ($values as $path) {
        if (!is_string($path) || $path === $mainPath || product_image_public_url($path) === null) continue;
        $paths[$path] = $path;
        if (count($paths) === 8) break;
    }
    return array_values($paths);
}

function product_gallery_public_urls(mixed $storedJson, ?string $mainPath = null): array
{
    return array_map('product_image_public_url', product_gallery_stored_paths($storedJson, $mainPath));
}

/** Every supplied gallery file passes the same checks as the primary photo. */
function product_gallery_paths(mixed $value, ?string $mainPath = null): array
{
    if (!is_array($value) || !array_is_list($value) || count($value) > 8) {
        app_error('حداکثر ۸ عکس تکمیلی برای گالری محصول انتخاب کنید.');
    }
    $paths = [];
    foreach ($value as $url) {
        if (!is_string($url) || $url === '') app_error('عکس گالری محصول معتبر نیست.');
        $path = product_image_path($url);
        if ($path !== null && $path !== $mainPath) $paths[$path] = $path;
    }
    return array_values($paths);
}

function product_image_inspect(string $file): string
{
    $size = filesize($file);
    if ($size === false || $size < 1 || $size > 8388608) app_error('حجم عکس باید حداکثر ۸ مگابایت باشد.', 413);
    if (!extension_loaded('fileinfo')) app_error('برای بارگذاری عکس، افزونهٔ fileinfo در تنظیمات PHP هاست فعال شود.', 503);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file);
    $extensions = ['image/jpeg'=>'jpg', 'image/png'=>'png', 'image/webp'=>'webp'];
    $dimensions = @getimagesize($file);
    if (!is_string($mime) || !isset($extensions[$mime]) || !is_array($dimensions) || ($dimensions['mime'] ?? '') !== $mime) {
        app_error('فقط عکس واقعی JPG، PNG یا WebP قابل بارگذاری است.', 415);
    }
    if ($dimensions[0] < 1 || $dimensions[1] < 1 || $dimensions[0] > 8000 || $dimensions[1] > 8000 || $dimensions[0] * $dimensions[1] > 24000000) {
        app_error('ابعاد عکس باید حداکثر ۸۰۰۰ پیکسل و مجموعاً حداکثر ۲۴ مگاپیکسل باشد.');
    }
    return $extensions[$mime];
}

/** Accept only existing files produced by this uploader, in the current installation. */
function product_image_path(mixed $value): ?string
{
    if ($value === null || $value === '') return null;
    if (!is_string($value)) app_error('عکس محصول معتبر نیست.');
    $prefix = product_image_prefix();
    $path = str_starts_with($value, $prefix) ? substr($value, strlen($prefix)) : $value;
    if (!preg_match('~\Auploads/products/[a-f0-9]{48}\.(?:jpg|png|webp)\z~D', $path)) app_error('عکس را از قسمت بارگذاری محصول انتخاب کنید.');
    $file = dirname(__DIR__) . '/' . $path;
    if (is_link($file) || !is_file($file)) app_error('فایل عکس پیدا نشد؛ عکس را دوباره بارگذاری کنید.');
    if (product_image_inspect($file) !== pathinfo($file, PATHINFO_EXTENSION)) app_error('پسوند عکس معتبر نیست.');
    return $path;
}

function product_image_upload(): array
{
    $user = require_permission('products.edit');
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 8519680) app_error('حجم عکس باید حداکثر ۸ مگابایت باشد.', 413);
    if (!$_FILES && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 8388608) app_error('حجم درخواست از محدودیت بارگذاری هاست بیشتر است؛ عکس کوچک‌تری انتخاب کنید یا post_max_size هاست را افزایش دهید.', 413);
    if (!str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'multipart/form-data')) app_error('عکس باید به صورت فایل بارگذاری شود.', 415);
    $upload = $_FILES['image'] ?? null;
    if (!is_array($upload) || count($_FILES) !== 1 || is_array($upload['error'] ?? null)) app_error('یک عکس برای محصول انتخاب کنید.');
    $error = $upload['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) app_error('حجم عکس از محدودیت هاست بیشتر است؛ عکس کوچک‌تری انتخاب کنید یا upload_max_filesize و post_max_size هاست را افزایش دهید.', 413);
    if ($error !== UPLOAD_ERR_OK || !is_string($upload['tmp_name'] ?? null) || !is_uploaded_file($upload['tmp_name'])) app_error('بارگذاری عکس کامل نشد؛ دوباره انتخاب کنید.');
    $extension = product_image_inspect($upload['tmp_name']);
    $directory = dirname(__DIR__) . '/uploads/products';
    // Ship the access guard with the project; never create an unguarded upload directory.
    if (!is_dir($directory) || is_link($directory) || !is_file($directory . '/.htaccess')) app_error('پوشهٔ uploads/products و فایل محافظ آن را از نسخهٔ کامل پروژه روی هاست قرار دهید.', 503);
    if (!is_writable($directory)) app_error('پوشهٔ uploads/products باید برای کاربر PHP هاست قابل نوشتن باشد.', 503);
    $name = bin2hex(random_bytes(24)) . '.' . $extension;
    if (!move_uploaded_file($upload['tmp_name'], $directory . '/' . $name)) app_error('ذخیرهٔ عکس روی هاست انجام نشد؛ فضای هاست و دسترسی پوشهٔ uploads/products را بررسی کنید.', 503);
    chmod($directory . '/' . $name, 0644);
    // Old files are retained: another product or an open editor may still reference them.
    audit('product.imageUpload', $name, ['userId'=>$user['id']]);
    return ['imageUrl'=>product_image_public_url('uploads/products/' . $name)];
}

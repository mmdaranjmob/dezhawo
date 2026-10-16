<?php
declare(strict_types=1);

/** Public payment choices are independent of private provider credentials. */
function payment_options_defaults(): array
{
    return ['zarinpalEnabled'=>true, 'digipayEnabled'=>false, 'bankTransferEnabled'=>false,
        'bankCard'=>'', 'bankIban'=>'', 'bankHolder'=>'', 'bankName'=>'', 'bankInstructions'=>''];
}

function payment_options(): array
{
    $options = payment_options_defaults();
    $query = db()->prepare('SELECT value FROM settings WHERE name=?');
    $query->execute(['payment_options']);
    $raw = $query->fetchColumn();
    $saved = $raw === false ? null : json_decode((string)$raw, true);
    if (!is_array($saved) || array_is_list($saved)) return $options;
    foreach ($options as $key=>$default) {
        if (array_key_exists($key,$saved) && gettype($saved[$key]) === gettype($default)) {
            $options[$key] = $saved[$key];
        }
    }
    return $options;
}

function payment_bank_card_valid(string $card): bool
{
    if (!preg_match('/\A[0-9]{16}\z/D',$card) || preg_match('/\A([0-9])\1{15}\z/D',$card)) return false;
    $sum = 0;
    for ($i=0;$i<16;$i++) {
        $number = (int)$card[$i];
        if ($i % 2 === 0) { $number *= 2; if ($number > 9) $number -= 9; }
        $sum += $number;
    }
    return $sum % 10 === 0;
}

function payment_bank_iban_valid(string $iban): bool
{
    if (!preg_match('/\AIR[0-9]{24}\z/D',$iban)) return false;
    // Move IR and its check digits to the end; I=18 and R=27. Avoid integer overflow.
    $number = substr($iban,4) . '1827' . substr($iban,2,2);
    $remainder = 0;
    for ($i=0,$length=strlen($number);$i<$length;$i++) {
        $remainder = ($remainder * 10 + (int)$number[$i]) % 97;
    }
    return $remainder === 1;
}

function payment_bank_details_valid(array $options): bool
{
    $card = $options['bankCard'] ?? '';
    $iban = $options['bankIban'] ?? '';
    $holder = $options['bankHolder'] ?? '';
    return is_string($holder) && trim($holder) !== '' && is_string($card) && is_string($iban)
        && ($card !== '' || $iban !== '')
        && ($card === '' || payment_bank_card_valid($card))
        && ($iban === '' || payment_bank_iban_valid($iban));
}

function payment_methods_public(): array
{
    $methods = [];
    foreach (['zarinpal'=>'پرداخت آنلاین زرین‌پال','digipay'=>'خرید اقساطی با دیجی‌پی','bank_transfer'=>'کارت‌به‌کارت / واریز بانکی'] as $id=>$label) {
        try {
            // Local configuration and switch checks only; no provider HTTP requests.
            payment_require_configured($id);
            $available = true;
        } catch (AppError) {
            $available = false;
        }
        $methods[] = ['id'=>$id,'label'=>$label,'available'=>$available];
    }
    return $methods;
}

function payment_settings_text(mixed $value, int $limit): string
{
    if (!is_string($value) || !mb_check_encoding($value,'UTF-8') || mb_strlen($value,'UTF-8') > $limit
        || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u',$value)) {
        app_error('مشخصات حساب بانکی معتبر نیست.');
    }
    return trim($value);
}

function payment_settings_save(array $input): array
{
    require_permission('settings.edit');
    $raw = $input['settings'] ?? null;
    if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) app_error('تنظیمات پرداخت معتبر نیست.');
    $defaults = payment_options_defaults();
    $changes = [];
    foreach ($raw as $key=>$value) {
        if (!is_string($key) || !array_key_exists($key,$defaults)) app_error('فیلد تنظیمات پرداخت مجاز نیست.');
        if (is_bool($defaults[$key])) {
            if (!is_bool($value)) app_error('وضعیت روش پرداخت باید فعال یا غیرفعال باشد.');
            $changes[$key] = $value;
            continue;
        }
        $value = payment_settings_text($value,$key === 'bankInstructions' ? 2000 : 160);
        if ($key === 'bankCard' || $key === 'bankIban') {
            $value = strtoupper(preg_replace('/[\s-]/u','',digits($value)) ?? '');
            if ($value !== '' && !($key === 'bankCard' ? payment_bank_card_valid($value) : payment_bank_iban_valid($value))) {
                app_error($key === 'bankCard' ? 'شماره کارت ۱۶ رقمی معتبر وارد کنید.' : 'شماره شبای معتبر با پیشوند IR وارد کنید.');
            }
        }
        $changes[$key] = $value;
    }
    return transaction(function() use($changes): array {
        // Match the lock order of the other administrative settings writer.
        db()->query('SELECT name FROM settings ORDER BY name FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN);
        require_permission('settings.edit');
        $options = array_replace(payment_options(),$changes);
        if ($options['bankTransferEnabled'] && !payment_bank_details_valid($options)) {
            app_error('برای فعال‌سازی واریز بانکی، نام صاحب حساب و حداقل یک شماره کارت یا شبای معتبر لازم است.');
        }
        $query = db()->prepare('INSERT INTO settings(name,value,updated_at) VALUES(?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE value=VALUES(value),updated_at=UTC_TIMESTAMP()');
        $query->execute(['payment_options',json_value($options)]);
        // Account numbers and any provider credentials never enter the audit log.
        audit('payments.settings','payment_options',['fields'=>array_keys($changes)]);
        return ['settings'=>$options,'paymentMethods'=>payment_methods_public()];
    });
}

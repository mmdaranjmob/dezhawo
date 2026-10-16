<?php
declare(strict_types=1);
// PRIVATE hosting configuration. Never publish this file.
return array (
  'app' =>
  array (
    'env' => 'production',
    'base_url' => 'https://dpsshop.ir',
    'key' => '99faee21b446a2a3054c8f151b8970e34df15205ffc298c60da01a4c4b4b9a60',
    'installation_key' => 'b543082ef8722eeff67a896b94cf86400865c736f1156c34f0f84e96a114ccc4',
    'timezone' => 'Asia/Tehran',
  ),
  'database' =>
  array (
    'host' => 'localhost',
    'port' => 3306,
    'name' => 'ujingbca_dezhawo',
    'user' => 'ujingbca_mmdaranj',
    'password' => 'Mnj4769!',
    'charset' => 'utf8mb4',
  ),
  'session' =>
  array (
    'name' => 'dezhawo_session',
    'secure' => true,
  ),
  'temporary_login'=>['enabled'=>true,'phone'=>'09128546547','password_hash'=>'$2y$10$lAdlBcQpAmrFlztnN.VBceaPWUxjRyNnGLOXJFsa6Zt4zyTyrU.pK'],
'sms' =>
  array (
    'provider' => 'niazpardaz',
    'niazpardaz_auth' => 'api_key',
    'api_key' => '',
    'sender' => '',
    'username' => '',
    'password' => '',
  ),
  'payment' =>
  array (
    'provider' => 'zarinpal',
    'merchant_id' => '',
    'sandbox' => false,
    'digipay' =>
    array (
      'username' => '',
      'password' => '',
      'client_id' => '',
      'client_secret' => '',
      'sandbox' => false,
      'seller_id' => '',
      'supplier_id' => '',
      'category_id' => '',
      'brand' => 'DEZHAWO',
      'product_type' => 1,
    ),
  ),
  'testing' =>
  array (
    'otp_outbox' => '',
    'payment_stub' => false,
  ),
);

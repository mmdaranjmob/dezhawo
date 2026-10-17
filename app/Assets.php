<?php
declare(strict_types=1);

/** Change asset URLs with their contents so deployments cannot reuse old JS/CSS. */
function asset_url(string $path): string
{
    if (!preg_match('/\Aassets\/[A-Za-z0-9._-]+\.(?:css|js)\z/D', $path)) {
        throw new InvalidArgumentException('Invalid public asset path');
    }
    $file = dirname(__DIR__) . '/' . $path;
    $hash = is_file($file) ? hash_file('sha256', $file) : false;
    return $path . ($hash !== false ? '?v=' . substr($hash, 0, 12) : '');
}

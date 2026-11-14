<?php
declare(strict_types=1);

if (php_sapi_name() === 'cli-server') {
    $requestPath = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    $isPublicAsset = (str_starts_with($requestPath, '/images/') && !str_contains($requestPath, '..'))
        || in_array($requestPath, ['/index.css', '/app.js', '/robots.txt', '/sitemap.xml', '/favicon.ico'], true);
    if ($isPublicAsset && is_file(__DIR__ . $requestPath)) {
        return false;
    }
}

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? null) === 443);
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => $isHttps]);
session_start();
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (str_starts_with($class, $prefix)) {
        $path = __DIR__ . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }
});

$vendor = __DIR__ . '/vendor/autoload.php';
if (is_file($vendor)) {
    require $vendor;
}

$config = require __DIR__ . '/config/app.php';
$repository = new App\Models\RfqRepository($config['storage_path']);
$mailer = new App\Services\RfqMailer($config['mail']);
$controller = new App\Controllers\PageController($repository, $mailer);

$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$scriptDirectory = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
if ($scriptDirectory !== '' && $scriptDirectory !== '/' && str_starts_with($path, $scriptDirectory)) {
    $path = substr($path, strlen($scriptDirectory)) ?: '/';
}
$path = '/' . ltrim($path, '/');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $path === '/rfq') {
    $controller->submitRfq();
    exit;
}

$pages = [
    '/' => 'home',
    '/index.php' => 'home',
    '/fashion-wear.php' => 'fashion-wear',
    '/fashion-wear' => 'fashion-wear',
    '/fashionwear' => 'fashion-wear',
    '/sports-wear.php' => 'sports-wear',
    '/sports-wear' => 'sports-wear',
    '/sportswear' => 'sports-wear',
    '/street-wear.php' => 'street-wear',
    '/street-wear' => 'street-wear',
    '/streetwear' => 'street-wear',
    '/work-wear.php' => 'work-wear',
    '/work-wear' => 'work-wear',
    '/workwear' => 'work-wear',
    '/privacy-policy.php' => 'privacy-policy',
    '/privacy-policy' => 'privacy-policy',
    '/terms-of-service.php' => 'terms-of-service',
    '/terms-of-service' => 'terms-of-service',
];

if (isset($pages[$path])) {
    $controller->show($pages[$path]);
    exit;
}

http_response_code(404);
echo 'Page not found.';

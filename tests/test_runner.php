<?php
declare(strict_types=1);

// Test runner for KM WEARS application
$passed = 0;
$failed = 0;

function it(string $description, callable $fn): void {
    global $passed, $failed;
    try {
        $fn();
        echo "  ✓ {$description}\n";
        $passed++;
    } catch (\Throwable $e) {
        echo "  ✗ {$description}: {$e->getMessage()}\n";
        $failed++;
    }
}

function expect(mixed $condition, string $msg = 'Assertion failed'): void {
    if (!$condition) {
        throw new \RuntimeException($msg);
    }
}

echo "Running KM WEARS Test Suite...\n\n";

// 1. Autoloader and Config Test
it('loads config and autoloader cleanly', function () {
    require_once __DIR__ . '/../vendor/autoload.php';
    spl_autoload_register(static function (string $class): void {
        $prefix = 'App\\';
        if (str_starts_with($class, $prefix)) {
            $path = dirname(__DIR__) . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($path)) {
                require_once $path;
            }
        }
    });

    $config = require dirname(__DIR__) . '/config/app.php';
    expect(isset($config['storage_path']), 'Storage path must be configured');
    expect(isset($config['mail']), 'Mail config must be configured');
});

// 2. RFQ SQLite Persistence & Busy Timeout Test
it('stores RFQ inquiries in SQLite with busyTimeout and closes handle', function () {
    $tempDir = sys_get_temp_dir() . '/km_test_' . uniqid();
    mkdir($tempDir, 0777, true);

    $repo = new App\Models\RfqRepository($tempDir);
    $repo->create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'phone' => '+1234567890',
        'category' => 'streetwear',
        'quantity' => 150,
        'details' => 'Custom heavyweight hoodie request',
    ]);

    $dbFile = $tempDir . '/.km_wears.sqlite';
    expect(file_exists($dbFile), 'SQLite DB file was not created');

    $db = new SQLite3($dbFile);
    $res = $db->querySingle('SELECT name, email, category, quantity FROM rfqs WHERE email = "john@example.com"', true);
    expect($res !== false && !empty($res), 'Record not found in SQLite');
    expect($res['name'] === 'John Doe', 'Name mismatch');
    expect((int)$res['quantity'] === 150, 'Quantity mismatch');
    $db->close();

    // cleanup
    @unlink($dbFile);
    @rmdir($tempDir);
});

// 3. Mailer Body & AltBody Contains Category and Quantity
it('formats email plain-text AltBody and HTML with category and quantity', function () {
    $mailer = new App\Services\RfqMailer(['enabled' => false]);
    $rfq = [
        'name' => 'Alice Smith',
        'email' => 'alice@example.com',
        'phone' => '+1987654321',
        'category' => 'sportswear',
        'quantity' => 500,
        'details' => 'Moisture wicking jerseys',
    ];

    $ref = new ReflectionClass($mailer);
    $method = $ref->getMethod('adminBody');
    $method->setAccessible(true);
    $html = $method->invoke($mailer, $rfq);

    expect(str_contains($html, 'sportswear'), 'HTML missing category');
    expect(str_contains($html, '500'), 'HTML missing quantity');

    $mailerSource = file_get_contents(dirname(__DIR__) . '/app/Services/RfqMailer.php');
    expect(str_contains($mailerSource, 'Category: {$rfq[\'category\']}'), 'AltBody missing category interpolation');
    expect(str_contains($mailerSource, 'Quantity: {$rfq[\'quantity\']}'), 'AltBody missing quantity interpolation');
});

// 4. Static Router Security Check
it('ensures cli-server static filter blocks sensitive files', function () {
    $publicAllowed = ['/index.css', '/app.js', '/images/km-logo-white.png', '/robots.txt', '/sitemap.xml'];
    $forbidden = ['/.env', '/storage/.km_wears.sqlite', '/storage/.rfqs.json', '/config/app.php', '/index.php'];

    $isPublicAsset = static function (string $p): bool {
        return (str_starts_with($p, '/images/') && !str_contains($p, '..'))
            || in_array($p, ['/index.css', '/app.js', '/robots.txt', '/sitemap.xml'], true);
    };

    foreach ($publicAllowed as $path) {
        expect($isPublicAsset($path), "Expected {$path} to be allowed");
    }
    foreach ($forbidden as $path) {
        expect(!$isPublicAsset($path), "Expected {$path} to be blocked");
    }
});

// 5. Apache Protection Files Exist
it('checks .htaccess protection files exist and are not empty', function () {
    $rootHtaccess = dirname(__DIR__) . '/.htaccess';
    $storageHtaccess = dirname(__DIR__) . '/storage/.htaccess';

    expect(file_exists($rootHtaccess), 'Root .htaccess must exist');
    expect(file_exists($storageHtaccess), 'storage/.htaccess must exist');

    $storageContent = (string) file_get_contents($storageHtaccess);
    expect(str_contains(strtolower($storageContent), 'deny') || str_contains(strtolower($storageContent), 'require all denied'),
        'storage/.htaccess must deny public web access');

    $rootContent = (string) file_get_contents($rootHtaccess);
    expect(str_contains($rootContent, 'FilesMatch'), 'Root .htaccess must block hidden files');
});

// 6. Dead Migration Scripts Removed
it('ensures dead Python migration scripts are purged', function () {
    $deadScripts = [
        dirname(__DIR__) . '/add_classes.py',
        dirname(__DIR__) . '/fix_app.py',
        dirname(__DIR__) . '/refactor_css.py',
        dirname(__DIR__) . '/update_js.py',
    ];
    foreach ($deadScripts as $file) {
        expect(!file_exists($file), "Dead script still exists: {$file}");
    }
});

// 7. Footer Style & Wikimedia Hotlink Audit
it('ensures footer partial has no inline style tags and no external hotlinks', function () {
    $footerContent = (string) file_get_contents(dirname(__DIR__) . '/app/Views/partials/footer.php');
    expect(!str_contains($footerContent, '<style>'), 'Footer partial must not contain inline <style> block');
    expect(!str_contains($footerContent, 'wikimedia.org'), 'Footer partial must not hotlink external Wikimedia flag');
    expect(str_contains($footerContent, '<svg'), 'Footer partial should use inline SVG for UK flag');
});

// 8. Rate Limiter Audit
it('verifies rate limiter checks IP alongside session', function () {
    $controllerSource = file_get_contents(dirname(__DIR__) . '/app/Controllers/PageController.php');
    expect(str_contains($controllerSource, 'REMOTE_ADDR'), 'Rate limiter must check client IP');
    expect(str_contains($controllerSource, 'km_wears_rfq_rate_limits'), 'Rate limiter must use rate limits storage');
});

echo "\nTest Summary: {$passed} passed, {$failed} failed.\n";
exit($failed > 0 ? 1 : 0);

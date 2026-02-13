<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;
} else {
    spl_autoload_register(function (string $class) use ($root): void {
        if (!str_starts_with($class, 'WebAlbum\\')) {
            return;
        }
        $path = $root . '/src/' . str_replace('\\', '/', substr($class, 9)) . '.php';
        if (is_file($path)) {
            require $path;
        }
    });
}

use WebAlbum\Security\PathGuard;

$photosRoot = getenv('WA_PHOTOS_ROOT') ?: '/';
$outside = '/etc/passwd';

fwrite(STDOUT, "PathGuard regression check\n");
fwrite(STDOUT, "Configured root: {$photosRoot}\n");

$failed = false;
try {
    PathGuard::assertInsideRoot($outside, $photosRoot);
    fwrite(STDOUT, "FAIL: outside path accepted: {$outside}\n");
    $failed = true;
} catch (Throwable $e) {
    fwrite(STDOUT, "OK: outside path rejected ({$outside})\n");
}

if ($failed) {
    exit(1);
}

fwrite(STDOUT, "PASS\n");
exit(0);

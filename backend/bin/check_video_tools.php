<?php

declare(strict_types=1);

use WebAlbum\SystemTools;

$root = dirname(__DIR__);
$autoload = $root . "/vendor/autoload.php";
if (is_file($autoload)) {
    require $autoload;
} else {
    spl_autoload_register(function (string $class): void {
        if (!str_starts_with($class, "WebAlbum\\")) {
            return;
        }
        $path = __DIR__ . "/../src/" . str_replace("\\", "/", substr($class, 9)) . ".php";
        if (is_file($path)) {
            require $path;
        }
    });
}

$configPath = $argv[1] ?? ($root . "/config/config.php");
if (!is_file($configPath)) {
    fwrite(STDERR, "Config not found: {$configPath}\n");
    exit(1);
}

$config = require $configPath;
$status = SystemTools::checkExternalTools($config, true);
$tools = $status['tools'] ?? [];

$out = [
    'checked_at' => $status['checked_at'] ?? null,
    'ffmpeg' => $tools['ffmpeg'] ?? ['available' => false, 'path' => null],
    'ffprobe' => $tools['ffprobe'] ?? ['available' => false, 'path' => null],
];

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

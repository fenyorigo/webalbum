<?php

declare(strict_types=1);

use WebAlbum\Db\SqliteIndex;
use WebAlbum\Thumb\ThumbPolicy;

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

$opts = getopt("", [
    "config::",
    "dry-run::",
    "limit::",
]);

$configPath = (string)($opts["config"] ?? ($root . "/config/config.php"));
$dryRun = isset($opts["dry-run"]) ? (bool)((int)$opts["dry-run"]) : true;
$limit = isset($opts["limit"]) ? max(1, min(500000, (int)$opts["limit"])) : 200000;

if (!is_file($configPath)) {
    fwrite(STDERR, "Config not found: {$configPath}\n");
    exit(1);
}

try {
    $config = require $configPath;
    $thumbRoot = (string)($config["thumbs"]["root"] ?? "");
    $sqlitePath = (string)($config["sqlite"]["path"] ?? "");
    if ($thumbRoot === "" || $sqlitePath === "") {
        throw new RuntimeException("Missing thumbs.root or sqlite.path in config");
    }

    $db = new SqliteIndex($sqlitePath);
    $rows = $db->query(
        "SELECT rel_path FROM files WHERE type = 'video' AND rel_path IS NOT NULL AND rel_path <> '' ORDER BY id ASC LIMIT ?",
        [$limit]
    );

    $scanned = 0;
    $matches = 0;
    $removed = 0;
    $bytes = 0;

    foreach ($rows as $row) {
        $relPath = trim((string)($row["rel_path"] ?? ""));
        if ($relPath === "") {
            continue;
        }
        $thumbPath = ThumbPolicy::thumbPath($thumbRoot, $relPath);
        if ($thumbPath === null || !is_file($thumbPath)) {
            continue;
        }
        $scanned++;

        if (!ThumbPolicy::isLikelyPlaceholderThumb($thumbPath, "video", $config)) {
            continue;
        }

        $matches++;
        $size = (int)@filesize($thumbPath);
        $bytes += max(0, $size);

        if (!$dryRun && @unlink($thumbPath)) {
            $removed++;
        }
    }

    $payload = [
        "ok" => true,
        "dry_run" => $dryRun,
        "limit" => $limit,
        "scanned_existing_video_thumbs" => $scanned,
        "placeholder_matches" => $matches,
        "removed" => $dryRun ? 0 : $removed,
        "bytes" => $bytes,
    ];

    fwrite(STDOUT, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}

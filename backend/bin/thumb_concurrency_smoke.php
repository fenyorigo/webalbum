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

if (!function_exists('curl_multi_init')) {
    fwrite(STDERR, "cURL extension with multi support is required\n");
    exit(1);
}

$opts = getopt("", [
    "config::",
    "base-url:",
    "username:",
    "password:",
    "id::",
    "concurrency::",
    "rounds::",
    "timeout::",
    "reset::",
]);

$configPath = (string)($opts["config"] ?? ($root . "/config/config.php"));
$baseUrl = rtrim((string)($opts["base-url"] ?? ""), "/");
$username = (string)($opts["username"] ?? "");
$password = (string)($opts["password"] ?? "");
$targetId = isset($opts["id"]) ? (int)$opts["id"] : 0;
$concurrency = isset($opts["concurrency"]) ? max(2, min(100, (int)$opts["concurrency"])) : 8;
$rounds = isset($opts["rounds"]) ? max(1, min(50, (int)$opts["rounds"])) : 4;
$timeout = isset($opts["timeout"]) ? max(3, min(300, (int)$opts["timeout"])) : 30;
$reset = isset($opts["reset"]) ? (bool)((int)$opts["reset"]) : true;

if (!is_file($configPath)) {
    fwrite(STDERR, "Config not found: {$configPath}\n");
    exit(1);
}
if ($baseUrl === "" || $username === "" || $password === "") {
    fwrite(STDERR, "Usage: php backend/bin/thumb_concurrency_smoke.php --base-url http://host --username admin --password secret [--config ...] [--id 123] [--concurrency 8] [--rounds 4] [--timeout 30] [--reset 1]\n");
    exit(1);
}

$config = require $configPath;
$sqlitePath = (string)($config["sqlite"]["path"] ?? "");
$thumbRoot = (string)($config["thumbs"]["root"] ?? "");
if ($sqlitePath === "" || $thumbRoot === "") {
    fwrite(STDERR, "Config missing sqlite.path or thumbs.root\n");
    exit(1);
}

$db = new SqliteIndex($sqlitePath);
if ($targetId > 0) {
    $rows = $db->query("SELECT id, rel_path, type FROM files WHERE id = ? LIMIT 1", [$targetId]);
} else {
    $rows = $db->query("SELECT id, rel_path, type FROM files WHERE type = 'video' ORDER BY id ASC LIMIT 3");
}

$targets = [];
foreach ($rows as $row) {
    if ((string)($row["type"] ?? "") !== "video") {
        continue;
    }
    $id = (int)($row["id"] ?? 0);
    $rel = trim((string)($row["rel_path"] ?? ""));
    if ($id > 0 && $rel !== "") {
        $thumb = ThumbPolicy::thumbPath($thumbRoot, $rel);
        if ($thumb !== null) {
            $targets[] = ["id" => $id, "rel_path" => $rel, "thumb" => $thumb];
        }
    }
}

if ($targets === []) {
    fwrite(STDERR, "No target video rows found\n");
    exit(1);
}

$cookieFile = tempnam(sys_get_temp_dir(), "wa_cookie_");
if ($cookieFile === false) {
    fwrite(STDERR, "Unable to create cookie file\n");
    exit(1);
}

$loginRes = httpJson(
    $baseUrl . "/api/auth/login",
    "POST",
    ["username" => $username, "password" => $password],
    $cookieFile,
    $timeout
);
if ($loginRes["status"] !== 200) {
    @unlink($cookieFile);
    fwrite(STDERR, "Login failed: HTTP {$loginRes['status']}\n");
    exit(1);
}

$totalRequests = 0;
$non200 = 0;
$placeholderResponses = 0;
$failures = [];

foreach ($targets as $target) {
    $id = (int)$target["id"];
    $thumbPath = (string)$target["thumb"];

    if ($reset && is_file($thumbPath)) {
        @unlink($thumbPath);
    }

    for ($r = 1; $r <= $rounds; $r++) {
        $results = runThumbBurst($baseUrl, $id, $concurrency, $cookieFile, $timeout);
        foreach ($results as $result) {
            $totalRequests++;
            if ((int)$result["status"] !== 200) {
                $non200++;
            }
            $ctype = strtolower((string)($result["content_type"] ?? ""));
            if (str_contains($ctype, "image/svg+xml")) {
                $placeholderResponses++;
            }
        }
        usleep(120000);
    }

    if (!is_file($thumbPath)) {
        // Trigger one last attempt so an in-progress lock can complete first.
        runThumbBurst($baseUrl, $id, 1, $cookieFile, $timeout);
        usleep(150000);
    }

    if (!is_file($thumbPath)) {
        $failures[] = ["id" => $id, "reason" => "thumb_missing_after_burst"];
        continue;
    }

    if (ThumbPolicy::isLikelyPlaceholderThumb($thumbPath, "video", $config)) {
        $size = (int)@filesize($thumbPath);
        $failures[] = ["id" => $id, "reason" => "placeholder_on_disk", "size" => $size];
        continue;
    }

    [$ok, $reason] = ThumbPolicy::validateGeneratedThumb($thumbPath, "video", $config);
    if (!$ok) {
        $failures[] = ["id" => $id, "reason" => "invalid_generated_thumb", "detail" => $reason];
    }
}

// Best-effort logout.
@httpJson($baseUrl . "/api/auth/logout", "POST", [], $cookieFile, $timeout);
@unlink($cookieFile);

$report = [
    "ok" => $failures === [] && $non200 === 0,
    "targets" => count($targets),
    "requests" => $totalRequests,
    "non_200" => $non200,
    "transient_placeholder_responses" => $placeholderResponses,
    "failures" => $failures,
    "config" => [
        "concurrency" => $concurrency,
        "rounds" => $rounds,
        "timeout" => $timeout,
        "reset" => $reset,
    ],
];

echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
exit(($report["ok"] ?? false) ? 0 : 2);

/**
 * @return array{status:int,body:string,content_type:string}
 */
function httpJson(string $url, string $method, array $payload, string $cookieFile, int $timeout): array
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    if ($method !== "GET") {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    $body = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    return ["status" => $status, "body" => $body, "content_type" => $ctype];
}

/**
 * @return array<int, array{status:int,content_type:string}>
 */
function runThumbBurst(string $baseUrl, int $id, int $count, string $cookieFile, int $timeout): array
{
    $mh = curl_multi_init();
    $chs = [];
    $url = $baseUrl . "/api/thumb?id=" . $id;

    for ($i = 0; $i < $count; $i++) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
        curl_multi_add_handle($mh, $ch);
        $chs[] = $ch;
    }

    do {
        $status = curl_multi_exec($mh, $active);
        if ($active) {
            curl_multi_select($mh, 1.0);
        }
    } while ($active && $status === CURLM_OK);

    $results = [];
    foreach ($chs as $ch) {
        curl_multi_getcontent($ch);
        $results[] = [
            "status" => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE),
            "content_type" => (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE),
        ];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }

    curl_multi_close($mh);
    return $results;
}

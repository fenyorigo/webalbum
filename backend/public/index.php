<?php

declare(strict_types=1);

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

use WebAlbum\Http\Controllers\HealthController;
use WebAlbum\Http\Controllers\SearchController;
use WebAlbum\Http\Controllers\TagsController;

$method = $_SERVER["REQUEST_METHOD"] ?? "GET";
$uri = $_SERVER["REQUEST_URI"] ?? "/";
$path = parse_url($uri, PHP_URL_PATH) ?: "/";

if ($method === "GET" && !str_starts_with($path, "/api")) {
    $index = __DIR__ . "/dist/index.html";
    if (is_file($index)) {
        header("Content-Type: text/html; charset=utf-8");
        readfile($index);
        exit;
    }
}
if ($method === "GET" && $path === "/api/health") {
    (new HealthController($root . "/config/config.php"))->handle();
    exit;
}
if ($method === "GET" && $path === "/api/tags") {
    (new TagsController($root . "/config/config.php"))->handleAutocomplete();
    exit;
}
if ($method === "GET" && $path === "/api/tags/list") {
    (new TagsController($root . "/config/config.php"))->handleList();
    exit;
}
if ($method === "POST" && $path === "/api/tags/prefs") {
    (new TagsController($root . "/config/config.php"))->handlePrefs();
    exit;
}
if ($method === "POST" && $path === "/api/search") {
    (new SearchController($root . "/config/config.php"))->handle();
    exit;
}

http_response_code(404);
header("Content-Type: application/json; charset=utf-8");
echo json_encode(["error" => "Not Found"]);

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
use WebAlbum\Http\Controllers\FileController;
use WebAlbum\Http\Controllers\FileTagsController;
use WebAlbum\Http\Controllers\MediaTagsController;
use WebAlbum\Http\Controllers\MediaRotateController;
use WebAlbum\Http\Controllers\VideoController;
use WebAlbum\Http\Controllers\DownloadController;
use WebAlbum\Http\Controllers\ThumbController;
use WebAlbum\Http\Controllers\UsersController;
use WebAlbum\Http\Controllers\FavoritesController;
use WebAlbum\Http\Controllers\SavedSearchesController;
use WebAlbum\Http\Controllers\AuthController;
use WebAlbum\Http\Controllers\SetupController;
use WebAlbum\Http\Controllers\PrefsController;
use WebAlbum\Http\Controllers\AuditLogController;
use WebAlbum\Http\Controllers\AdminTrashController;
use WebAlbum\Http\Controllers\MaintenanceController;
use WebAlbum\Http\Controllers\TreeController;
use WebAlbum\Http\Controllers\AdminAssetsController;
use WebAlbum\Http\Controllers\AssetController;

$method = $_SERVER["REQUEST_METHOD"] ?? "GET";
$uri = $_SERVER["REQUEST_URI"] ?? "/";
$path = parse_url($uri, PHP_URL_PATH) ?: "/";

$forwardedProtoHeader = (string)($_SERVER["HTTP_X_FORWARDED_PROTO"] ?? "");
$forwardedProto = strtolower(trim(explode(",", $forwardedProtoHeader)[0] ?? ""));
$isSecure = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off")
    || ($forwardedProto === "https");
$env = strtolower((string)(getenv("WEBALBUM_ENV") ?: getenv("APP_ENV") ?: "dev"));
$forceSecure = $isSecure || in_array($env, ["prod", "production"], true);
session_set_cookie_params([
    "lifetime" => 0,
    "path" => "/",
    "httponly" => true,
    "samesite" => "Lax",
    "secure" => $forceSecure,
]);
session_start();

if ($method === "POST" && preg_match("#^/api/media/(\\d+)/rotate$#", $path, $m)) {
    (new MediaRotateController($root . "/config/config.php"))->save((int)$m[1]);
    exit;
}

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
if ($method === "GET" && $path === "/api/admin/tools/status") {
    (new HealthController($root . "/config/config.php"))->adminToolStatus();
    exit;
}
if ($method === "POST" && $path === "/api/admin/tools/configure") {
    (new HealthController($root . "/config/config.php"))->configureTools();
    exit;
}
if ($method === "POST" && $path === "/api/admin/tools/recheck") {
    (new HealthController($root . "/config/config.php"))->recheckTools();
    exit;
}
if ($method === "GET" && $path === "/api/tree/roots") {
    (new TreeController($root . "/config/config.php"))->roots();
    exit;
}
if ($method === "GET" && $path === "/api/tree") {
    (new TreeController($root . "/config/config.php"))->children();
    exit;
}
if ($method === "GET" && $path === "/api/tags") {
    (new TagsController($root . "/config/config.php"))->handleAutocomplete();
    exit;
}
if ($method === "GET" && $path === "/api/users") {
    (new UsersController($root . "/config/config.php"))->handle();
    exit;
}
if ($method === "POST" && $path === "/api/users") {
    (new UsersController($root . "/config/config.php"))->create();
    exit;
}
if ($method === "PUT" && preg_match("#^/api/users/(\\d+)$#", $path, $m)) {
    (new UsersController($root . "/config/config.php"))->update((int)$m[1]);
    exit;
}
if ($method === "POST" && preg_match("#^/api/users/(\\d+)/password$#", $path, $m)) {
    (new UsersController($root . "/config/config.php"))->setPassword((int)$m[1]);
    exit;
}
if ($method === "DELETE" && preg_match("#^/api/users/(\\d+)$#", $path, $m)) {
    (new UsersController($root . "/config/config.php"))->delete((int)$m[1]);
    exit;
}
if ($method === "POST" && $path === "/api/auth/login") {
    (new AuthController($root . "/config/config.php"))->login();
    exit;
}
if ($method === "POST" && $path === "/api/auth/logout") {
    (new AuthController($root . "/config/config.php"))->logout();
    exit;
}
if ($method === "GET" && $path === "/api/auth/me") {
    (new AuthController($root . "/config/config.php"))->me();
    exit;
}
if ($method === "POST" && $path === "/api/users/me/password") {
    (new AuthController($root . "/config/config.php"))->changePassword();
    exit;
}
if ($method === "GET" && ($path === "/api/admin/audit-logs" || $path === "/api/admin/logs")) {
    (new AuditLogController($root . "/config/config.php"))->list();
    exit;
}
if ($method === "GET" && ($path === "/api/admin/audit-logs/export" || $path === "/api/admin/logs/export")) {
    (new AuditLogController($root . "/config/config.php"))->exportCsv();
    exit;
}
if ($method === "GET" && $path === "/api/admin/audit-logs/meta") {
    (new AuditLogController($root . "/config/config.php"))->meta();
    exit;
}
if ($method === "POST" && $path === "/api/admin/tags/reenable-all") {
    (new TagsController($root . "/config/config.php"))->handleReenableAll();
    exit;
}
if ($method === "GET" && $path === "/api/admin/tags/export") {
    (new TagsController($root . "/config/config.php"))->handleExportCsv();
    exit;
}
if ($method === "GET" && $path === "/api/admin/assets") {
    (new AdminAssetsController($root . "/config/config.php"))->listAssets();
    exit;
}
if ($method === "POST" && $path === "/api/admin/assets/requeue") {
    (new AdminAssetsController($root . "/config/config.php"))->requeue();
    exit;
}
if ($method === "POST" && $path === "/api/admin/assets/scan") {
    (new AdminAssetsController($root . "/config/config.php"))->scan();
    exit;
}
if ($method === "GET" && $path === "/api/admin/jobs/status") {
    (new AdminAssetsController($root . "/config/config.php"))->jobsStatus();
    exit;
}
if ($method === "GET" && $path === "/api/asset") {
    $id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;
    (new AssetController($root . "/config/config.php"))->get($id);
    exit;
}
if ($method === "GET" && preg_match("#^/api/asset/(\d+)$#", $path, $m)) {
    (new AssetController($root . "/config/config.php"))->get((int)$m[1]);
    exit;
}
if ($method === "GET" && $path === "/api/asset/file") {
    $id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;
    (new AssetController($root . "/config/config.php"))->file($id);
    exit;
}
if ($method === "GET" && $path === "/api/asset/view") {
    $id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;
    (new AssetController($root . "/config/config.php"))->view($id);
    exit;
}
if ($method === "GET" && $path === "/api/asset/thumb") {
    $id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;
    (new AssetController($root . "/config/config.php"))->thumb($id);
    exit;
}
if ($method === "GET" && $path === "/api/admin/trash") {
    (new AdminTrashController($root . "/config/config.php"))->list();
    exit;
}
if ($method === "POST" && $path === "/api/admin/trash") {
    (new AdminTrashController($root . "/config/config.php"))->move();
    exit;
}
if ($method === "GET" && $path === "/api/admin/trash/thumb") {
    $id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;
    (new AdminTrashController($root . "/config/config.php"))->thumb($id);
    exit;
}
if ($method === "POST" && $path === "/api/admin/trash/restore") {
    (new AdminTrashController($root . "/config/config.php"))->restore();
    exit;
}
if ($method === "POST" && $path === "/api/admin/trash/restore-bulk") {
    (new AdminTrashController($root . "/config/config.php"))->restoreBulk();
    exit;
}
if ($method === "POST" && $path === "/api/admin/trash/purge") {
    (new AdminTrashController($root . "/config/config.php"))->purge();
    exit;
}
if ($method === "POST" && $path === "/api/admin/trash/purge-bulk") {
    (new AdminTrashController($root . "/config/config.php"))->purgeBulk();
    exit;
}
if ($method === "POST" && $path === "/api/admin/trash/empty") {
    (new AdminTrashController($root . "/config/config.php"))->empty();
    exit;
}
if ($method === "POST" && $path === "/api/admin/maintenance/clean-structure") {
    (new MaintenanceController($root . "/config/config.php"))->cleanStructure();
    exit;
}
if ($method === "POST" && $path === "/api/admin/maintenance/purge-placeholder-thumbs") {
    (new MaintenanceController($root . "/config/config.php"))->purgePlaceholderThumbs();
    exit;
}
if ($method === "POST" && $path === "/api/admin/maintenance/clear-all-thumbs") {
    (new MaintenanceController($root . "/config/config.php"))->clearAllThumbs();
    exit;
}
if ($method === "GET" && $path === "/api/setup/status") {
    (new SetupController($root . "/config/config.php"))->status();
    exit;
}
if ($method === "POST" && $path === "/api/setup") {
    (new SetupController($root . "/config/config.php"))->create();
    exit;
}
if ($method === "GET" && $path === "/api/prefs") {
    (new PrefsController($root . "/config/config.php"))->get();
    exit;
}
if ($method === "POST" && $path === "/api/prefs") {
    (new PrefsController($root . "/config/config.php"))->update();
    exit;
}
if ($method === "POST" && $path === "/api/favorites/toggle") {
    (new FavoritesController($root . "/config/config.php"))->toggle();
    exit;
}
if ($method === "GET" && $path === "/api/favorites/list") {
    (new FavoritesController($root . "/config/config.php"))->list();
    exit;
}
if ($method === "GET" && $path === "/api/saved-searches") {
    (new SavedSearchesController($root . "/config/config.php"))->list();
    exit;
}
if ($method === "POST" && $path === "/api/saved-searches") {
    (new SavedSearchesController($root . "/config/config.php"))->create();
    exit;
}
if ($method === "GET" && preg_match("#^/api/saved-searches/(\\d+)$#", $path, $m)) {
    (new SavedSearchesController($root . "/config/config.php"))->get((int)$m[1]);
    exit;
}
if ($method === "PUT" && preg_match("#^/api/saved-searches/(\\d+)$#", $path, $m)) {
    (new SavedSearchesController($root . "/config/config.php"))->update((int)$m[1]);
    exit;
}
if ($method === "DELETE" && preg_match("#^/api/saved-searches/(\\d+)$#", $path, $m)) {
    (new SavedSearchesController($root . "/config/config.php"))->delete((int)$m[1]);
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
if ($method === "GET" && $path === "/api/file") {
    $id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;
    (new FileController($root . "/config/config.php"))->handle($id);
    exit;
}
if ($method === "GET" && $path === "/api/file-tags") {
    $id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;
    (new FileTagsController($root . "/config/config.php"))->handle($id);
    exit;
}
if ($method === "GET" && preg_match("#^/api/file-tags/(\\d+)$#", $path, $m)) {
    (new FileTagsController($root . "/config/config.php"))->handle((int)$m[1]);
    exit;
}
if ($method === "GET" && preg_match("#^/api/media/(\\d+)/tags$#", $path, $m)) {
    (new MediaTagsController($root . "/config/config.php"))->get((int)$m[1]);
    exit;
}
if ($method === "POST" && preg_match("#^/api/media/(\\d+)/tags$#", $path, $m)) {
    (new MediaTagsController($root . "/config/config.php"))->save((int)$m[1]);
    exit;
}
if ($method === "GET" && preg_match("#^/api/file/(\\d+)$#", $path, $m)) {
    (new FileController($root . "/config/config.php"))->handle((int)$m[1]);
    exit;
}
if ($method === "GET" && $path === "/api/video") {
    $id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;
    (new VideoController($root . "/config/config.php"))->handle($id);
    exit;
}
if ($method === "GET" && preg_match("#^/api/video/(\\d+)$#", $path, $m)) {
    (new VideoController($root . "/config/config.php"))->handle((int)$m[1]);
    exit;
}
if ($method === "GET" && $path === "/api/thumb") {
    $id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;
    (new ThumbController($root . "/config/config.php"))->handle($id);
    exit;
}
if ($method === "GET" && preg_match("#^/api/thumb/(\\d+)$#", $path, $m)) {
    (new ThumbController($root . "/config/config.php"))->handle((int)$m[1]);
    exit;
}
if ($method === "POST" && $path === "/api/download") {
    (new DownloadController($root . "/config/config.php"))->handle();
    exit;
}
if ($method === "POST" && $path === "/api/search") {
    (new SearchController($root . "/config/config.php"))->handle();
    exit;
}

http_response_code(404);
header("Content-Type: application/json; charset=utf-8");
echo json_encode(["error" => "Not Found"]);

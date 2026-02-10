<?php

declare(strict_types=1);

use WebAlbum\Db\Maria;
use WebAlbum\UserContext;

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

$options = getopt("", ["user_id:"]);

$config = require $root . "/config/config.php";
$db = new Maria(
    $config["mariadb"]["dsn"],
    $config["mariadb"]["user"],
    $config["mariadb"]["pass"]
);

$count = $db->query("SELECT COUNT(*) AS c FROM wa_users WHERE is_active = 1");
$active = (int)$count[0]["c"];

$user = null;
if (isset($options["user_id"])) {
    $id = (int)$options["user_id"];
    $rows = $db->query(
        "SELECT id, username, display_name FROM wa_users WHERE id = ? AND is_active = 1",
        [$id]
    );
    $user = $rows[0] ?? null;
}

echo "Active users: " . $active . PHP_EOL;
echo "Lookup user: " . ($user ? ($user["id"] . " " . $user["username"]) : "none") . PHP_EOL;

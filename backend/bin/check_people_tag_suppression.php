<?php

declare(strict_types=1);

use WebAlbum\Db\SqliteIndex;
use WebAlbum\Tag\TagVisibility;

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

$options = getopt("", ["db:", "name::"]);
$dbPath = $options["db"] ?? "";
$name = isset($options["name"]) && is_string($options["name"]) && trim($options["name"]) !== ""
    ? trim($options["name"])
    : "András";
if ($dbPath === "") {
    fwrite(STDERR, "Usage: php backend/bin/check_people_tag_suppression.php --db /path/to/index.db [--name András]\n");
    exit(1);
}

try {
    $db = new SqliteIndex($dbPath);
    $visible = TagVisibility::suppressPeopleVariantSql("t");

    $allRows = $db->query("SELECT DISTINCT t.tag FROM tags t ORDER BY t.tag ASC");
    $allTags = array_fill_keys(array_map(fn (array $row): string => (string)$row["tag"], $allRows), true);

    $visibleRows = $db->query(
        "SELECT DISTINCT t.tag FROM tags t WHERE " . $visible . " ORDER BY t.tag ASC"
    );
    $visibleTags = array_fill_keys(array_map(fn (array $row): string => (string)$row["tag"], $visibleRows), true);

    $plain = $name;
    $hier = "People|" . $name;

    $plainShown = isset($visibleTags[$plain]);
    $hierShown = isset($visibleTags[$hier]);
    $peopleShown = isset($visibleTags["People"]);
    $plainExists = isset($allTags[$plain]);
    $hierExists = isset($allTags[$hier]);

    echo "Name: " . $name . "\n";
    echo "Plain exists in DB: " . ($plainExists ? "yes" : "no") . "\n";
    echo "People|Name exists in DB: " . ($hierExists ? "yes" : "no") . "\n";
    echo "Visible plain tag: " . ($plainShown ? "yes" : "no") . "\n";
    echo "Visible People|Name tag: " . ($hierShown ? "yes" : "no") . "\n";
    echo "Visible People tag: " . ($peopleShown ? "yes" : "no") . "\n";

    $ok = $plainShown && !$hierShown && $peopleShown;
    echo "Check: " . ($ok ? "PASS" : "FAIL") . "\n";
    exit($ok ? 0 : 2);
} catch (Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}

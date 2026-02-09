<?php

declare(strict_types=1);

use WebAlbum\Db\SqliteIndex;
use WebAlbum\Query\Model;
use WebAlbum\Query\Runner;

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

$options = getopt("", ["db:"]);
$dbPath = $options["db"] ?? "";
if ($dbPath === "") {
    fwrite(STDERR, "Usage: php backend/bin/search.php --db /path/to/index.db\n");
    exit(1);
}

$sample = [
    "where" => [
        "group" => "ALL",
        "items" => [
            ["field" => "type", "op" => "is", "value" => "image"],
            ["field" => "taken", "op" => "after", "value" => "2020-01-01"],
        ],
    ],
    "sort" => ["field" => "taken", "dir" => "desc"],
    "limit" => 20,
];

try {
    $query = Model::validateSearch($sample);
    $db = new SqliteIndex($dbPath);
    $runner = new Runner($db);
    $result = $runner->run($query);

    echo "SQL:\n" . $result["sql"] . "\n\n";
    echo "Params:\n" . json_encode($result["params"], JSON_PRETTY_PRINT) . "\n\n";
    echo "First 20 paths:\n";
    foreach ($result["rows"] as $row) {
        echo $row["path"] . "\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}

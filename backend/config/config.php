<?php

declare(strict_types=1);

return [
    "sqlite" => [
        "path" => getenv("WEBALBUM_SQLITE_PATH") ?: "/Users/bajanp/Projects/images-1.db",
    ],
    "mariadb" => [
        "dsn" => getenv("WEBALBUM_MARIADB_DSN") ?: "mysql:host=localhost;dbname=webalbum;charset=utf8mb4",
        "user" => getenv("WEBALBUM_MARIADB_USER") ?: "webalbum",
        "pass" => getenv("WEBALBUM_MARIADB_PASS") ?: "79baNyor",
    ],
];

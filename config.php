<?php

declare(strict_types=1);

return [
    "sqlite" => [
        "path" => getenv("WA_SQLITE_DB") ?: (getenv("WEBALBUM_SQLITE_PATH") ?: "/Users/bajanp/Projects/images-1.db"),
    ],
    "photos" => [
        "root" => getenv("WA_PHOTOS_ROOT") ?: "/Users/bajanp/Projects/indexer-test",
    ],
    "thumbs" => [
        "root" => getenv("WA_THUMBS_ROOT") ?: "/Users/bajanp/Projects/indexer-test-thumbs",
        "max" => (int)(getenv("WA_THUMB_MAX") ?: 256),
        "quality" => (int)(getenv("WA_THUMB_QUALITY") ?: 75),
    ],
    "mariadb" => [
        "dsn" => getenv("WEBALBUM_MARIADB_DSN") ?: "mysql:host=localhost;dbname=webalbum;charset=utf8mb4",
        "user" => getenv("WEBALBUM_MARIADB_USER") ?: "webalbum",
        "pass" => getenv("WEBALBUM_MARIADB_PASS") ?: "79baNyor",
    ],
];

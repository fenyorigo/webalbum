<?php

declare(strict_types=1);

namespace WebAlbum\Db;

use PDO;

final class SqliteIndex
{
    private PDO $pdo;
    private string $path;

    public function __construct(string $path)
    {
        if ($path === "") {
            throw new \InvalidArgumentException("SQLite path is required");
        }
        if (!is_file($path)) {
            throw new \RuntimeException("SQLite DB not found: " . $path);
        }

        $this->path = $path;

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];

        if (class_exists("Pdo\\\\Sqlite") && defined("Pdo\\\\Sqlite::ATTR_OPEN_FLAGS")) {
            $options[\Pdo\Sqlite::ATTR_OPEN_FLAGS] = \Pdo\Sqlite::OPEN_READONLY;
        } elseif (defined("PDO::SQLITE_ATTR_OPEN_FLAGS") && defined("SQLITE_OPEN_READONLY")) {
            $options[\PDO::SQLITE_ATTR_OPEN_FLAGS] = \SQLITE_OPEN_READONLY;
        }

        $this->pdo = new PDO(
            "sqlite:" . $path,
            null,
            null,
            $options
        );

        $this->pdo->exec("PRAGMA query_only = ON");
    }

    public function query(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function getPath(): string
    {
        return $this->path;
    }
}

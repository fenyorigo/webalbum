<?php

declare(strict_types=1);

namespace WebAlbum\Http\Controllers;

use WebAlbum\Db\Maria;
use WebAlbum\Db\SqliteIndex;
use WebAlbum\Tag\TagVisibility;
use WebAlbum\UserContext;

final class FileTagsController
{
    private string $configPath;

    public function __construct(string $configPath)
    {
        $this->configPath = $configPath;
    }

    public function handle(int $id): void
    {
        try {
            if ($id <= 0) {
                $this->json(["error" => "id is required"], 400);
                return;
            }

            [$sqlite, $maria] = $this->connections();
            $user = UserContext::currentUser($maria);
            if ($user === null) {
                $this->json(["error" => "Not authenticated"], 401);
                return;
            }

            $rows = $sqlite->query(
                "SELECT DISTINCT t.tag\n" .
                "FROM file_tags ft\n" .
                "JOIN tags t ON t.id = ft.tag_id\n" .
                "WHERE ft.file_id = ? AND " . TagVisibility::excludePeopleForViewerSql("t") . "\n" .
                "ORDER BY LOWER(t.tag) ASC",
                [$id]
            );

            $this->json([
                "tags" => array_map(fn (array $row): string => (string)$row["tag"], $rows),
            ]);
        } catch (\Throwable $e) {
            $this->json(["error" => $e->getMessage()], 400);
        }
    }

    private function connections(): array
    {
        $config = require $this->configPath;
        $sqlite = new SqliteIndex($config["sqlite"]["path"]);
        $maria = new Maria(
            $config["mariadb"]["dsn"],
            $config["mariadb"]["user"],
            $config["mariadb"]["pass"]
        );
        return [$sqlite, $maria];
    }

    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode($payload);
    }
}

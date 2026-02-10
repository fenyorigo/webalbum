<?php

declare(strict_types=1);

namespace WebAlbum\Http\Controllers;

use WebAlbum\Db\Maria;
use WebAlbum\Db\SqliteIndex;
use WebAlbum\UserContext;

final class FavoritesController
{
    private string $configPath;

    public function __construct(string $configPath)
    {
        $this->configPath = $configPath;
    }

    public function toggle(): void
    {
        try {
            $config = require $this->configPath;
            $maria = new Maria(
                $config["mariadb"]["dsn"],
                $config["mariadb"]["user"],
                $config["mariadb"]["pass"]
            );
            $user = UserContext::currentUser($maria);
            if ($user === null) {
                $this->json(["error" => "Not authenticated"], 401);
                return;
            }

            $body = file_get_contents("php://input");
            $data = json_decode($body ?: "", true, 512, JSON_THROW_ON_ERROR);
            $fileId = $data["file_id"] ?? null;
            if (!is_int($fileId) && !ctype_digit((string)$fileId)) {
                $this->json(["error" => "file_id must be an integer"], 400);
                return;
            }
            $fileId = (int)$fileId;
            if ($fileId < 1) {
                $this->json(["error" => "Invalid file_id"], 400);
                return;
            }

            $exists = $maria->query(
                "SELECT 1 FROM wa_favorites WHERE user_id = ? AND file_id = ?",
                [$user["id"], $fileId]
            );
            if ($exists !== []) {
                $maria->exec(
                    "DELETE FROM wa_favorites WHERE user_id = ? AND file_id = ?",
                    [$user["id"], $fileId]
                );
                $this->json(["file_id" => $fileId, "is_favorite" => false]);
                return;
            }

            $maria->exec(
                "INSERT INTO wa_favorites (user_id, file_id) VALUES (?, ?)",
                [$user["id"], $fileId]
            );
            $this->json(["file_id" => $fileId, "is_favorite" => true]);
        } catch (\JsonException $e) {
            $this->json(["error" => "Invalid JSON"], 400);
        } catch (\Throwable $e) {
            $this->json(["error" => $e->getMessage()], 500);
        }
    }

    public function list(): void
    {
        try {
            $config = require $this->configPath;
            $maria = new Maria(
                $config["mariadb"]["dsn"],
                $config["mariadb"]["user"],
                $config["mariadb"]["pass"]
            );
            $user = UserContext::currentUser($maria);
            if ($user === null) {
                $this->json(["error" => "Not authenticated"], 401);
                return;
            }

            $limit = $this->limitParam("limit", 50, 200);
            $offset = $this->offsetParam("offset");
            $sort = $_GET["sort"] ?? "date_new";
            $order = match ($sort) {
                "name_asc" => "files.path ASC",
                "name_desc" => "files.path DESC",
                "date_old" => "files.taken_ts ASC",
                default => "files.taken_ts DESC",
            };

            $totalRows = $maria->query(
                "SELECT COUNT(*) AS c FROM wa_favorites WHERE user_id = ?",
                [$user["id"]]
            );
            $total = (int)$totalRows[0]["c"];
            if ($total === 0) {
                $this->json([
                    "items" => [],
                    "total" => 0,
                    "offset" => $offset,
                    "limit" => $limit,
                ]);
                return;
            }

            $favRows = $maria->query(
                "SELECT file_id FROM wa_favorites WHERE user_id = ?",
                [$user["id"]]
            );
            $ids = array_map(fn (array $row): int => (int)$row["file_id"], $favRows);
            if ($ids === []) {
                $this->json([
                    "items" => [],
                    "total" => 0,
                    "offset" => $offset,
                    "limit" => $limit,
                ]);
                return;
            }

            $sqlite = new SqliteIndex($config["sqlite"]["path"]);
            $placeholders = implode(",", array_fill(0, count($ids), "?"));
            $items = $sqlite->query(
                "SELECT id, path, taken_ts, type FROM files\n" .
                "WHERE id IN (" . $placeholders . ")\n" .
                "ORDER BY " . $order . "\n" .
                "LIMIT " . (int)$limit . " OFFSET " . (int)$offset,
                $ids
            );

            foreach ($items as &$row) {
                $row["is_favorite"] = true;
            }
            unset($row);

            $this->json([
                "items" => $items,
                "total" => $total,
                "offset" => $offset,
                "limit" => $limit,
            ]);
        } catch (\Throwable $e) {
            $this->json(["error" => $e->getMessage()], 500);
        }
    }

    private function limitParam(string $key, int $default, int $max): int
    {
        $value = $_GET[$key] ?? null;
        if ($value === null) {
            return $default;
        }
        $value = is_numeric($value) ? (int)$value : $default;
        if ($value < 1) {
            return $default;
        }
        return min($value, $max);
    }

    private function offsetParam(string $key): int
    {
        $value = $_GET[$key] ?? null;
        if ($value === null) {
            return 0;
        }
        $value = is_numeric($value) ? (int)$value : 0;
        return max(0, $value);
    }

    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode($payload);
    }
}

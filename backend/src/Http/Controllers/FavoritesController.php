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

            $sqlite = new SqliteIndex($config["sqlite"]["path"]);
            $rows = $sqlite->query("SELECT rel_path FROM files WHERE id = ?", [$fileId]);
            if ($rows === []) {
                $this->json(["error" => "Not Found"], 404);
                return;
            }
            $relPath = trim((string)($rows[0]["rel_path"] ?? ""));
            if ($relPath !== "") {
                $trashed = $maria->query(
                    "SELECT id FROM wa_media_trash WHERE rel_path = ? AND status = 'trashed' LIMIT 1",
                    [$relPath]
                );
                if ($trashed !== []) {
                    $this->json(["error" => "Cannot favorite trashed media"], 400);
                    return;
                }
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

            $favRows = $maria->query(
                "SELECT file_id FROM wa_favorites WHERE user_id = ?",
                [$user["id"]]
            );
            $ids = array_values(array_unique(array_map(fn (array $row): int => (int)$row["file_id"], $favRows)));
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
            $rows = $sqlite->query(
                "SELECT id, rel_path, path, taken_ts, type FROM files WHERE id IN (" . $placeholders . ")",
                $ids
            );
            if ($rows === []) {
                $this->json([
                    "items" => [],
                    "total" => 0,
                    "offset" => $offset,
                    "limit" => $limit,
                ]);
                return;
            }

            $relPaths = [];
            foreach ($rows as $row) {
                $rel = trim((string)($row["rel_path"] ?? ""));
                if ($rel !== "") {
                    $relPaths[$rel] = true;
                }
            }

            $trashed = [];
            if ($relPaths !== []) {
                $trashRows = $maria->query(
                    "SELECT rel_path FROM wa_media_trash WHERE status = 'trashed' AND rel_path IN (" .
                    implode(",", array_fill(0, count($relPaths), "?")) . ")",
                    array_keys($relPaths)
                );
                foreach ($trashRows as $trashRow) {
                    $rel = trim((string)($trashRow["rel_path"] ?? ""));
                    if ($rel !== "") {
                        $trashed[$rel] = true;
                    }
                }
            }

            $items = [];
            foreach ($rows as $row) {
                $rel = trim((string)($row["rel_path"] ?? ""));
                if ($rel !== "" && isset($trashed[$rel])) {
                    continue;
                }
                $row["is_favorite"] = true;
                unset($row["rel_path"]);
                $items[] = $row;
            }

            if ($sort === "name_asc") {
                usort($items, fn (array $a, array $b): int => strcasecmp((string)($a["path"] ?? ""), (string)($b["path"] ?? "")));
            } elseif ($sort === "name_desc") {
                usort($items, fn (array $a, array $b): int => strcasecmp((string)($b["path"] ?? ""), (string)($a["path"] ?? "")));
            } elseif ($sort === "date_old") {
                usort($items, fn (array $a, array $b): int => ((int)($a["taken_ts"] ?? 0)) <=> ((int)($b["taken_ts"] ?? 0)));
            } else {
                usort($items, fn (array $a, array $b): int => ((int)($b["taken_ts"] ?? 0)) <=> ((int)($a["taken_ts"] ?? 0)));
            }

            $total = count($items);
            $paged = array_slice($items, $offset, $limit);

            $this->json([
                "items" => array_values($paged),
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

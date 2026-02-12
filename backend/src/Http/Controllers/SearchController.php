<?php

declare(strict_types=1);

namespace WebAlbum\Http\Controllers;

use WebAlbum\Db\Maria;
use WebAlbum\Db\SqliteIndex;
use WebAlbum\Query\Model;
use WebAlbum\Query\Runner;
use WebAlbum\UserContext;
use WebAlbum\Http\Controllers\AdminTrashController;

final class SearchController
{
    private string $configPath;

    public function __construct(string $configPath)
    {
        $this->configPath = $configPath;
    }

    public function handle(): void
    {
        try {
            $body = file_get_contents("php://input");
            $data = json_decode($body ?: "", true, 512, JSON_THROW_ON_ERROR);

            $query = Model::validateSearch($data);

            $config = require $this->configPath;
            $db = new SqliteIndex($config["sqlite"]["path"]);
            $maria = null;
            try {
                $maria = new Maria(
                    $config["mariadb"]["dsn"],
                    $config["mariadb"]["user"],
                    $config["mariadb"]["pass"]
                );
            } catch (\Throwable $e) {
                $maria = null;
            }
            $user = UserContext::currentUser($maria);
            if ($user === null) {
                $this->json(["error" => "Not authenticated"], 401);
                return;
            }
            $userId = (int)($user["id"] ?? 0);
            $isAdmin = (int)($user["is_admin"] ?? 0) === 1;

            $runner = new Runner($db);
            $restrictIds = null;
            if (!empty($query["only_favorites"])) {
                if ($userId <= 0 || $maria === null) {
                    $this->json([
                        "items" => [],
                        "total" => 0,
                        "offset" => $query["offset"],
                        "limit" => $query["limit"],
                    ]);
                    return;
                }
                $favRows = $maria->query(
                    "SELECT file_id FROM wa_favorites WHERE user_id = ?",
                    [$userId]
                );
                $restrictIds = array_map(fn (array $row): int => (int)$row["file_id"], $favRows);
            }

            $excludeTags = [];
            $excludeRelPaths = [];
            if ($maria !== null && $userId > 0) {
                $excludeTags = $this->hiddenTagsForSearch($maria, $userId, $isAdmin);
                $excludeRelPaths = AdminTrashController::activeTrashedRelPaths($maria);
            }

            $result = $runner->run($query, $restrictIds, $excludeTags, $excludeRelPaths);

            $items = $result["rows"];
            if ($userId > 0 && $maria !== null && $items !== []) {
                $ids = array_map(fn (array $row): int => (int)$row["id"], $items);
                $placeholders = implode(",", array_fill(0, count($ids), "?"));
                $favRows = $maria->query(
                    "SELECT file_id FROM wa_favorites WHERE user_id = ? AND file_id IN (" . $placeholders . ")",
                    array_merge([$userId], $ids)
                );
                $favSet = [];
                foreach ($favRows as $fav) {
                    $favSet[(int)$fav["file_id"]] = true;
                }
                foreach ($items as &$row) {
                    $row["is_favorite"] = isset($favSet[(int)$row["id"]]);
                }
                unset($row);
            } else {
                foreach ($items as &$row) {
                    $row["is_favorite"] = false;
                }
                unset($row);
            }

            if ($this->isDebug()) {
                $this->json([
                    "items" => $items,
                    "total" => $result["total"],
                    "offset" => $query["offset"],
                    "limit" => $query["limit"],
                    "debug" => [
                        "sql" => $result["sql"],
                        "params" => $result["params"],
                    ],
                ]);
                return;
            }

            $this->json([
                "items" => $items,
                "total" => $result["total"],
                "offset" => $query["offset"],
                "limit" => $query["limit"],
            ]);
        } catch (\JsonException $e) {
            $this->json(["error" => "Invalid JSON"], 400);
        } catch (\Throwable $e) {
            $this->json(["error" => $e->getMessage()], 400);
        }
    }

    private function hiddenTagsForSearch(Maria $maria, int $userId, bool $isAdmin): array
    {
        $tags = [];

        if (!$isAdmin) {
            $globalWhere = $this->hasGlobalHiddenColumn($maria) ? "is_hidden = 1" : "1 = 0";
            if ($globalWhere !== "1 = 0") {
                $globalRows = $maria->query(
                    "SELECT tag FROM wa_tag_prefs_global WHERE " . $globalWhere
                );
                foreach ($globalRows as $row) {
                    $tag = (string)($row["tag"] ?? "");
                    if ($tag !== "") {
                        $tags[$tag] = true;
                    }
                }
            }
        }

        $userRows = $maria->query(
            "SELECT tag FROM wa_tag_prefs_user WHERE user_id = ? AND is_hidden = 1",
            [$userId]
        );
        foreach ($userRows as $row) {
            $tag = (string)($row["tag"] ?? "");
            if ($tag !== "") {
                $tags[$tag] = true;
            }
        }

        return array_keys($tags);
    }

    private function hasGlobalHiddenColumn(Maria $maria): bool
    {
        try {
            $rows = $maria->query(
                "SELECT COUNT(*) AS c\n" .
                "FROM information_schema.columns\n" .
                "WHERE table_schema = DATABASE()\n" .
                "  AND table_name = 'wa_tag_prefs_global'\n" .
                "  AND column_name = 'is_hidden'"
            );
            return ((int)($rows[0]["c"] ?? 0)) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function isDebug(): bool
    {
        if (getenv("WEBALBUM_DEBUG_SQL") === "1") {
            return true;
        }
        $uri = $_SERVER["REQUEST_URI"] ?? "";
        $query = parse_url($uri, PHP_URL_QUERY) ?: "";
        parse_str($query, $params);
        return isset($params["debug"]) && $params["debug"] === "1";
    }

    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode($payload);
    }
}

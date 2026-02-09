<?php

declare(strict_types=1);

namespace WebAlbum\Http\Controllers;

use WebAlbum\Db\Maria;
use WebAlbum\Db\SqliteIndex;

final class TagsController
{
    private string $configPath;
    private array $prefsCache = [];

    public function __construct(string $configPath)
    {
        $this->configPath = $configPath;
    }

    public function handleAutocomplete(): void
    {
        try {
            [$sqlite, $maria] = $this->connections();

            $q = $this->queryParam("q");
            $limit = $this->limitParam("limit", 50, 200);
            $fetchLimit = $limit * 3;

            $rows = $this->fetchSqliteTags($sqlite, $q, $fetchLimit);
            $tags = array_map(fn (array $row): string => $row["tag"], $rows);

            if ($q !== null && $q !== "") {
                $pinned = $this->fetchPinnedTags($maria);
                $missingPinned = array_values(array_diff($pinned, $tags));
                if ($missingPinned !== []) {
                    $rows = array_merge($rows, $this->fetchSqliteTagsByList($sqlite, $missingPinned));
                }
            }

            $merged = $this->mergeWithPrefs($rows, $maria);

            if ($q !== null && $q !== "") {
                $merged = array_values(array_filter($merged, function (array $row) use ($q): bool {
                    if ((int)$row["pinned"] === 1) {
                        return true;
                    }
                    if ((int)$row["is_noise"] === 0) {
                        return true;
                    }
                    return $this->startsWithCaseInsensitive($row["tag"], $q);
                }));
            }

            $this->sortTags($merged);
            $merged = array_slice($merged, 0, $limit);

            $this->json(array_map(function (array $row): array {
                return [
                    "tag" => $row["tag"],
                    "cnt" => (int)$row["cnt"],
                ];
            }, $merged));
        } catch (\Throwable $e) {
            $this->json(["error" => $e->getMessage()], 400);
        }
    }

    public function handleList(): void
    {
        try {
            [$sqlite, $maria] = $this->connections();

            $q = $this->queryParam("q");
            $limit = $this->limitParam("limit", 50, 200);
            $offset = $this->offsetParam("offset");

            [$rows, $total] = $this->fetchSqliteTagsPage($sqlite, $q, $limit, $offset);
            $merged = $this->mergeWithPrefs($rows, $maria);

            $this->json([
                "rows" => $merged,
                "total" => $total,
            ]);
        } catch (\Throwable $e) {
            $this->json(["error" => $e->getMessage()], 400);
        }
    }

    public function handlePrefs(): void
    {
        try {
            $body = file_get_contents("php://input");
            $data = json_decode($body ?: "", true, 512, JSON_THROW_ON_ERROR);

            $tag = isset($data["tag"]) && is_string($data["tag"]) ? trim($data["tag"]) : "";
            if ($tag === "") {
                throw new \InvalidArgumentException("tag is required");
            }

            $isNoise = $this->boolInt($data["is_noise"] ?? null, "is_noise");
            $pinned = $this->boolInt($data["pinned"] ?? 0, "pinned");

            [, $maria] = $this->connections();
            $maria->exec(
                "INSERT INTO wa_tag_prefs_global (tag, is_noise, pinned)\n" .
                "VALUES (?, ?, ?)\n" .
                "ON DUPLICATE KEY UPDATE is_noise = VALUES(is_noise), pinned = VALUES(pinned)",
                [$tag, $isNoise, $pinned]
            );

            $this->json([
                "ok" => true,
                "tag" => $tag,
                "is_noise" => $isNoise,
                "pinned" => $pinned,
            ]);
        } catch (\JsonException $e) {
            $this->json(["error" => "Invalid JSON"], 400);
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

    private function fetchSqliteTags(SqliteIndex $db, ?string $q, int $limit): array
    {
        if ($q !== null && $q !== "") {
            $like = self::escapeLike($q) . "%";
            return $db->query(
                "SELECT t.tag, COUNT(DISTINCT ft.file_id) AS cnt\n" .
                "FROM tags t\n" .
                "JOIN file_tags ft ON ft.tag_id = t.id\n" .
                "WHERE t.tag LIKE ? ESCAPE '\\' COLLATE NOCASE\n" .
                "GROUP BY t.tag\n" .
                "ORDER BY LOWER(t.tag) ASC\n" .
                "LIMIT " . (int)$limit,
                [$like]
            );
        }

        return $db->query(
            "SELECT t.tag, COUNT(DISTINCT ft.file_id) AS cnt\n" .
            "FROM tags t\n" .
            "JOIN file_tags ft ON ft.tag_id = t.id\n" .
            "GROUP BY t.tag\n" .
            "ORDER BY cnt DESC, LOWER(t.tag) ASC\n" .
            "LIMIT " . (int)$limit
        );
    }

    private function fetchSqliteTagsPage(SqliteIndex $db, ?string $q, int $limit, int $offset): array
    {
        if ($q !== null && $q !== "") {
            $like = self::escapeLike($q) . "%";
            $total = $db->query(
                "SELECT COUNT(DISTINCT t.tag) AS c FROM tags t WHERE t.tag LIKE ? ESCAPE '\\' COLLATE NOCASE",
                [$like]
            );
            $rows = $db->query(
                "SELECT t.tag, COUNT(*) AS variants\n" .
                "FROM tags t\n" .
                "WHERE t.tag LIKE ? ESCAPE '\\' COLLATE NOCASE\n" .
                "GROUP BY t.tag\n" .
                "ORDER BY LOWER(t.tag) ASC\n" .
                "LIMIT " . (int)$limit . " OFFSET " . (int)$offset,
                [$like]
            );
            return [$rows, (int)$total[0]["c"]];
        }

        $total = $db->query("SELECT COUNT(DISTINCT tag) AS c FROM tags");
        $rows = $db->query(
            "SELECT t.tag, COUNT(*) AS variants\n" .
            "FROM tags t\n" .
            "GROUP BY t.tag\n" .
            "ORDER BY LOWER(t.tag) ASC\n" .
            "LIMIT " . (int)$limit . " OFFSET " . (int)$offset
        );
        return [$rows, (int)$total[0]["c"]];
    }

    private function fetchSqliteTagsByList(SqliteIndex $db, array $tags): array
    {
        if ($tags === []) {
            return [];
        }
        $placeholders = implode(",", array_fill(0, count($tags), "?"));
        return $db->query(
            "SELECT t.tag, t.kind, COUNT(ft.file_id) AS cnt\n" .
            "FROM tags t\n" .
            "JOIN file_tags ft ON ft.tag_id = t.id\n" .
            "WHERE t.tag IN (" . $placeholders . ")\n" .
            "GROUP BY t.id",
            $tags
        );
    }

    private function fetchPinnedTags(Maria $db): array
    {
        $rows = $db->query("SELECT tag FROM wa_tag_prefs_global WHERE pinned = 1");
        return array_map(fn (array $row): string => $row["tag"], $rows);
    }

    private function mergeWithPrefs(array $rows, Maria $db): array
    {
        $tags = array_map(fn (array $row): string => $row["tag"], $rows);
        $prefs = $this->prefsForTags($db, $tags);
        $merged = [];
        foreach ($rows as $row) {
            $tag = $row["tag"];
            $pref = $prefs[$tag] ?? ["is_noise" => 0, "pinned" => 0];
            $mergedRow = $row;
            if (isset($mergedRow["cnt"])) {
                $mergedRow["cnt"] = (int)$mergedRow["cnt"];
            }
            if (isset($mergedRow["variants"])) {
                $mergedRow["variants"] = (int)$mergedRow["variants"];
            }
            $mergedRow["is_noise"] = (int)$pref["is_noise"];
            $mergedRow["pinned"] = (int)$pref["pinned"];
            $merged[] = $mergedRow;
        }
        return $merged;
    }

    private function prefsForTags(Maria $db, array $tags): array
    {
        $tags = array_values(array_unique(array_filter($tags)));
        if ($tags === []) {
            return [];
        }
        $missing = [];
        foreach ($tags as $tag) {
            if (!array_key_exists($tag, $this->prefsCache)) {
                $missing[] = $tag;
            }
        }
        if ($missing !== []) {
            $placeholders = implode(",", array_fill(0, count($missing), "?"));
            $rows = $db->query(
                "SELECT tag, is_noise, pinned FROM wa_tag_prefs_global WHERE tag IN (" . $placeholders . ")",
                $missing
            );
            foreach ($rows as $row) {
                $this->prefsCache[$row["tag"]] = [
                    "is_noise" => (int)$row["is_noise"],
                    "pinned" => (int)$row["pinned"],
                ];
            }
            foreach ($missing as $tag) {
                if (!array_key_exists($tag, $this->prefsCache)) {
                    $this->prefsCache[$tag] = ["is_noise" => 0, "pinned" => 0];
                }
            }
        }
        return $this->prefsCache;
    }

    private function sortTags(array &$rows): void
    {
        usort($rows, function (array $a, array $b): int {
            $aSpecial = $this->isHardNoise($a["tag"]) ? 1 : 0;
            $bSpecial = $this->isHardNoise($b["tag"]) ? 1 : 0;
            if ($a["pinned"] !== $b["pinned"]) {
                return $b["pinned"] <=> $a["pinned"];
            }
            if ($a["is_noise"] !== $b["is_noise"]) {
                return $a["is_noise"] <=> $b["is_noise"];
            }
            if ($aSpecial !== $bSpecial) {
                return $aSpecial <=> $bSpecial;
            }
            if ($a["cnt"] !== $b["cnt"]) {
                return $b["cnt"] <=> $a["cnt"];
            }
            return strcmp($a["tag"], $b["tag"]);
        });
    }

    private function boolInt(mixed $value, string $key): int
    {
        if ($value === 0 || $value === 1) {
            return (int)$value;
        }
        if ($value === "0" || $value === "1") {
            return (int)$value;
        }
        throw new \InvalidArgumentException($key . " must be 0 or 1");
    }

    private function startsWithCaseInsensitive(string $value, string $prefix): bool
    {
        $valueLower = $this->lower($value);
        $prefixLower = $this->lower($prefix);
        return str_starts_with($valueLower, $prefixLower);
    }

    private function lower(string $value): string
    {
        if (function_exists("mb_strtolower")) {
            return mb_strtolower($value, "UTF-8");
        }
        return strtolower($value);
    }

    private function isHardNoise(string $tag): bool
    {
        $value = $this->lower($tag);
        return $value === "people" || $value === "unimportant";
    }

    private function queryParam(string $key): ?string
    {
        $value = $_GET[$key] ?? null;
        return is_string($value) ? trim($value) : null;
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

    private static function escapeLike(string $value): string
    {
        return str_replace(["\\", "%", "_"], ["\\\\", "\\%", "\\_"], $value);
    }

    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode($payload);
    }
}

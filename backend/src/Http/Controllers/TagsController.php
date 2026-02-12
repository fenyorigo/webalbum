<?php

declare(strict_types=1);

namespace WebAlbum\Http\Controllers;

use WebAlbum\AuditLogMetaCache;
use WebAlbum\Db\Maria;
use WebAlbum\Db\SqliteIndex;
use WebAlbum\Tag\TagVisibility;
use WebAlbum\UserContext;

final class TagsController
{
    private string $configPath;
    private array $prefsCache = [];
    private ?bool $globalHiddenColumn = null;

    public function __construct(string $configPath)
    {
        $this->configPath = $configPath;
    }

    public function handleAutocomplete(): void
    {
        try {
            [$sqlite, $maria] = $this->connections(true);
            if ($maria === null) {
                $this->json(["error" => "MariaDB unavailable"], 500);
                return;
            }
            $user = UserContext::currentUser($maria);
            if ($user === null) {
                $this->json(["error" => "Not authenticated"], 401);
                return;
            }

            $userId = (int)$user["id"];
            $isAdmin = (int)($user["is_admin"] ?? 0) === 1;
            $q = $this->queryParam("q");
            $limit = $this->limitParam("limit", 50, 200);
            $fetchLimit = max($limit * 8, 200);
            $revealHidden = $this->revealHiddenParam() && $isAdmin;

            $rows = $this->fetchSqliteTags($sqlite, $q, $fetchLimit);
            $tags = array_map(fn (array $row): string => $row["tag"], $rows);

            if ($q !== null && $q !== "") {
                $pinned = $this->fetchPinnedTags($maria, $userId);
                $missingPinned = array_values(array_diff($pinned, $tags));
                if ($missingPinned !== []) {
                    $rows = array_merge($rows, $this->fetchSqliteTagsByList($sqlite, $missingPinned));
                }
            }

            $merged = $this->mergeWithPrefs($rows, $maria, $userId, $isAdmin);
            $merged = array_values(array_filter($merged, function (array $row) use ($q, $revealHidden): bool {
                if (!$revealHidden && (int)($row["is_hidden"] ?? 0) === 1) {
                    return false;
                }
                if ($q === null || $q === "") {
                    return true;
                }
                if ((int)$row["pinned"] === 1) {
                    return true;
                }
                if ((int)$row["is_noise"] === 0) {
                    return true;
                }
                return $this->startsWithCaseInsensitive($row["tag"], $q);
            }));

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
            [$sqlite, $maria] = $this->connections(true);
            if ($maria === null) {
                $this->json(["error" => "MariaDB unavailable"], 500);
                return;
            }
            $user = UserContext::currentUser($maria);
            if ($user === null) {
                $this->json(["error" => "Not authenticated"], 401);
                return;
            }

            $userId = (int)$user["id"];
            $isAdmin = (int)($user["is_admin"] ?? 0) === 1;
            $q = $this->queryParam("q");
            $limit = $this->limitParam("limit", 50, 200);
            $offset = $this->offsetParam("offset");

            if ($isAdmin) {
                [$rows, $total] = $this->fetchSqliteTagsPage($sqlite, $q, $limit, $offset);
                $merged = $this->mergeWithPrefs($rows, $maria, $userId, true);
            } else {
                $rows = $this->fetchSqliteTagsForListAll($sqlite, $q);
                $mergedAll = $this->mergeWithPrefs($rows, $maria, $userId, false);
                $visible = array_values(array_filter($mergedAll, fn (array $row): bool => (int)$row["enabled_global"] === 1));
                $total = count($visible);
                $merged = array_slice($visible, $offset, $limit);
            }

            $this->json([
                "rows" => $merged,
                "total" => $total,
                "is_admin" => $isAdmin ? 1 : 0,
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

            [, $maria] = $this->connections(true);
            if ($maria === null) {
                $this->json(["error" => "MariaDB unavailable"], 500);
                return;
            }
            $user = UserContext::currentUser($maria);
            if ($user === null) {
                $this->json(["error" => "Not authenticated"], 401);
                return;
            }
            $userId = (int)$user["id"];
            $isAdmin = (int)($user["is_admin"] ?? 0) === 1;

            $enabledPresent = array_key_exists("enabled", $data) || array_key_exists("is_hidden", $data);
            if ($enabledPresent) {
                $scope = isset($data["scope"]) && is_string($data["scope"]) ? strtolower(trim($data["scope"])) : "personal";
                if (!in_array($scope, ["global", "personal"], true)) {
                    throw new \InvalidArgumentException("scope must be global or personal");
                }

                $isHidden = array_key_exists("is_hidden", $data)
                    ? $this->boolInt($data["is_hidden"], "is_hidden")
                    : ($this->boolInt($data["enabled"], "enabled") === 1 ? 0 : 1);
                $enabled = $isHidden === 0;

                if ($scope === "global") {
                    if (!$isAdmin) {
                        $this->json(["error" => "Forbidden"], 403);
                        return;
                    }
                    if (!$this->hasGlobalHiddenColumn($maria)) {
                        throw new \RuntimeException("Missing wa_tag_prefs_global.is_hidden. Run backend/sql/mysql/010_tag_global_enabled.sql");
                    }
                    $maria->exec(
                        "INSERT INTO wa_tag_prefs_global (tag, is_noise, pinned, is_hidden)\n" .
                        "VALUES (?, 0, 0, ?)\n" .
                        "ON DUPLICATE KEY UPDATE is_hidden = VALUES(is_hidden)",
                        [$tag, $isHidden]
                    );
                    $this->logAudit(
                        $maria,
                        $userId,
                        null,
                        ($enabled ? "tag_global_enable" : "tag_global_disable"),
                        "web",
                        [
                            "tag" => $tag,
                            "enabled" => $enabled,
                            "scope" => "global",
                        ]
                    );
                } else {
                    $maria->exec(
                        "INSERT INTO wa_tag_prefs_user (user_id, tag, is_noise, pinned, is_hidden)\n" .
                        "VALUES (?, ?, NULL, NULL, ?)\n" .
                        "ON DUPLICATE KEY UPDATE is_hidden = VALUES(is_hidden)",
                        [$userId, $tag, $isHidden]
                    );
                    $this->logAudit(
                        $maria,
                        $userId,
                        $userId,
                        ($enabled ? "tag_user_enable" : "tag_user_disable"),
                        "web",
                        [
                            "tag" => $tag,
                            "enabled" => $enabled,
                            "scope" => "personal",
                            "affected_user_id" => $userId,
                        ]
                    );
                }

                $this->json([
                    "ok" => true,
                    "tag" => $tag,
                    "scope" => $scope,
                    "enabled" => $enabled,
                    "is_hidden" => $isHidden,
                ]);
                return;
            }

            $isNoise = $this->boolInt($data["is_noise"] ?? null, "is_noise");
            $pinned = $this->boolInt($data["pinned"] ?? 0, "pinned");

            if (!$isAdmin) {
                $this->json(["error" => "Forbidden"], 403);
                return;
            }

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

    public function handleReenableAll(): void
    {
        try {
            [, $maria] = $this->connections(true);
            if ($maria === null) {
                $this->json(["error" => "MariaDB unavailable"], 500);
                return;
            }
            $user = UserContext::currentUser($maria);
            if ($user === null) {
                $this->json(["error" => "Not authenticated"], 401);
                return;
            }
            $userId = (int)$user["id"];
            $isAdmin = (int)($user["is_admin"] ?? 0) === 1;
            if (!$isAdmin) {
                $this->json(["error" => "Forbidden"], 403);
                return;
            }

            if ($this->hasGlobalHiddenColumn($maria)) {
                $maria->exec("UPDATE wa_tag_prefs_global SET is_hidden = 0");
            }
            $maria->exec("UPDATE wa_tag_prefs_user SET is_hidden = 0");

            $this->logAudit(
                $maria,
                $userId,
                null,
                "tag_reenable_all",
                "web",
                ["scope" => "global+personal", "enabled" => true]
            );

            $this->json(["ok" => true]);
        } catch (\Throwable $e) {
            $this->json(["error" => $e->getMessage()], 400);
        }
    }

    private function connections(bool $requireMaria = false): array
    {
        $config = require $this->configPath;
        $sqlite = new SqliteIndex($config["sqlite"]["path"]);
        $maria = null;
        try {
            $maria = new Maria(
                $config["mariadb"]["dsn"],
                $config["mariadb"]["user"],
                $config["mariadb"]["pass"]
            );
        } catch (\Throwable $e) {
            if ($requireMaria) {
                $maria = null;
            }
        }
        return [$sqlite, $maria];
    }

    private function fetchSqliteTags(SqliteIndex $db, ?string $q, int $limit): array
    {
        $visible = TagVisibility::suppressPeopleVariantSql("t");
        if ($q !== null && $q !== "") {
            $like = self::escapeLike($q) . "%";
            return $db->query(
                "SELECT t.tag, COUNT(DISTINCT ft.file_id) AS cnt\n" .
                "FROM tags t\n" .
                "JOIN file_tags ft ON ft.tag_id = t.id\n" .
                "WHERE t.tag LIKE ? ESCAPE '\\' COLLATE NOCASE AND " . $visible . "\n" .
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
            "WHERE " . $visible . "\n" .
            "GROUP BY t.tag\n" .
            "ORDER BY cnt DESC, LOWER(t.tag) ASC\n" .
            "LIMIT " . (int)$limit
        );
    }

    private function fetchSqliteTagsForListAll(SqliteIndex $db, ?string $q): array
    {
        $visible = TagVisibility::suppressPeopleVariantSql("t");
        if ($q !== null && $q !== "") {
            $like = self::escapeLike($q) . "%";
            return $db->query(
                "SELECT t.tag, COUNT(*) AS variants\n" .
                "FROM tags t\n" .
                "WHERE t.tag LIKE ? ESCAPE '\\' COLLATE NOCASE AND " . $visible . "\n" .
                "GROUP BY t.tag\n" .
                "ORDER BY LOWER(t.tag) ASC",
                [$like]
            );
        }

        return $db->query(
            "SELECT t.tag, COUNT(*) AS variants\n" .
            "FROM tags t\n" .
            "WHERE " . $visible . "\n" .
            "GROUP BY t.tag\n" .
            "ORDER BY LOWER(t.tag) ASC"
        );
    }

    private function fetchSqliteTagsPage(SqliteIndex $db, ?string $q, int $limit, int $offset): array
    {
        $visible = TagVisibility::suppressPeopleVariantSql("t");
        if ($q !== null && $q !== "") {
            $like = self::escapeLike($q) . "%";
            $total = $db->query(
                "SELECT COUNT(DISTINCT t.tag) AS c FROM tags t\n" .
                "WHERE t.tag LIKE ? ESCAPE '\\' COLLATE NOCASE AND " . $visible,
                [$like]
            );
            $rows = $db->query(
                "SELECT t.tag, COUNT(*) AS variants\n" .
                "FROM tags t\n" .
                "WHERE t.tag LIKE ? ESCAPE '\\' COLLATE NOCASE AND " . $visible . "\n" .
                "GROUP BY t.tag\n" .
                "ORDER BY LOWER(t.tag) ASC\n" .
                "LIMIT " . (int)$limit . " OFFSET " . (int)$offset,
                [$like]
            );
            return [$rows, (int)$total[0]["c"]];
        }

        $total = $db->query("SELECT COUNT(DISTINCT t.tag) AS c FROM tags t WHERE " . $visible);
        $rows = $db->query(
            "SELECT t.tag, COUNT(*) AS variants\n" .
            "FROM tags t\n" .
            "WHERE " . $visible . "\n" .
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
            "SELECT t.tag, COUNT(DISTINCT ft.file_id) AS cnt\n" .
            "FROM tags t\n" .
            "JOIN file_tags ft ON ft.tag_id = t.id\n" .
            "WHERE t.tag IN (" . $placeholders . ") AND " . TagVisibility::suppressPeopleVariantSql("t") . "\n" .
            "GROUP BY t.tag",
            $tags
        );
    }

    private function fetchPinnedTags(Maria $db, int $userId): array
    {
        $rows = $db->query(
            "SELECT tag FROM wa_tag_prefs_global WHERE pinned = 1\n" .
            "UNION\n" .
            "SELECT tag FROM wa_tag_prefs_user WHERE user_id = ? AND pinned = 1",
            [$userId]
        );
        return array_map(fn (array $row): string => $row["tag"], $rows);
    }

    private function mergeWithPrefs(array $rows, Maria $db, int $userId, bool $isAdmin): array
    {
        $tags = array_map(fn (array $row): string => $row["tag"], $rows);
        $prefs = $this->prefsForTags($db, $tags, $userId, $isAdmin);
        $merged = [];
        foreach ($rows as $row) {
            $tag = $row["tag"];
            $pref = $prefs[$tag] ?? [
                "is_noise" => 0,
                "pinned" => 0,
                "is_hidden" => 0,
                "enabled_global" => 1,
                "enabled_personal" => 1,
            ];
            $mergedRow = $row;
            if (isset($mergedRow["cnt"])) {
                $mergedRow["cnt"] = (int)$mergedRow["cnt"];
            }
            if (isset($mergedRow["variants"])) {
                $mergedRow["variants"] = (int)$mergedRow["variants"];
            }
            $mergedRow["is_noise"] = (int)$pref["is_noise"];
            $mergedRow["pinned"] = (int)$pref["pinned"];
            $mergedRow["is_hidden"] = (int)$pref["is_hidden"];
            $mergedRow["enabled_global"] = (int)$pref["enabled_global"];
            $mergedRow["enabled_personal"] = (int)$pref["enabled_personal"];
            $merged[] = $mergedRow;
        }
        return $merged;
    }

    private function prefsForTags(Maria $db, array $tags, int $userId, bool $isAdmin): array
    {
        $tags = array_values(array_unique(array_filter($tags)));
        if ($tags === []) {
            return [];
        }
        $cacheKey = ($isAdmin ? "admin_" : "user_") . $userId;
        if (!isset($this->prefsCache[$cacheKey])) {
            $this->prefsCache[$cacheKey] = [];
        }
        $missing = [];
        foreach ($tags as $tag) {
            if (!array_key_exists($tag, $this->prefsCache[$cacheKey])) {
                $missing[] = $tag;
            }
        }
        if ($missing !== []) {
            $placeholders = implode(",", array_fill(0, count($missing), "?"));
            $globalHiddenExpr = $this->hasGlobalHiddenColumn($db) ? "is_hidden" : "0 AS is_hidden";
            $globalRows = $db->query(
                "SELECT tag, is_noise, pinned, " . $globalHiddenExpr . " FROM wa_tag_prefs_global WHERE tag IN (" . $placeholders . ")",
                $missing
            );
            $global = [];
            foreach ($globalRows as $row) {
                $global[$row["tag"]] = [
                    "is_noise" => (int)$row["is_noise"],
                    "pinned" => (int)$row["pinned"],
                    "is_hidden" => (int)($row["is_hidden"] ?? 0),
                ];
            }

            $userRows = $db->query(
                "SELECT tag, is_noise, pinned, is_hidden FROM wa_tag_prefs_user WHERE user_id = ? AND tag IN (" . $placeholders . ")",
                array_merge([$userId], $missing)
            );
            $user = [];
            foreach ($userRows as $row) {
                $user[$row["tag"]] = [
                    "is_noise" => $row["is_noise"] !== null ? (int)$row["is_noise"] : null,
                    "pinned" => $row["pinned"] !== null ? (int)$row["pinned"] : null,
                    "is_hidden" => (int)$row["is_hidden"],
                ];
            }

            foreach ($missing as $tag) {
                $g = $global[$tag] ?? ["is_noise" => 0, "pinned" => 0, "is_hidden" => 0];
                $u = $user[$tag] ?? ["is_noise" => null, "pinned" => null, "is_hidden" => null];

                $globalHidden = (int)$g["is_hidden"];
                $personalHidden = $u["is_hidden"];

                // Global and personal hidden flags both hide tags in search/autocomplete.
                $effectiveHidden = (($globalHidden === 1 || ($personalHidden ?? 0) === 1) ? 1 : 0);

                $enabledPersonal = $personalHidden === null
                    ? ($globalHidden === 1 ? 0 : 1)
                    : ($personalHidden === 1 ? 0 : 1);

                $this->prefsCache[$cacheKey][$tag] = [
                    "is_noise" => $u["is_noise"] !== null ? $u["is_noise"] : $g["is_noise"],
                    "pinned" => $u["pinned"] !== null ? $u["pinned"] : $g["pinned"],
                    "is_hidden" => $effectiveHidden,
                    "enabled_global" => $globalHidden === 1 ? 0 : 1,
                    "enabled_personal" => $enabledPersonal,
                ];
            }
        }
        return $this->prefsCache[$cacheKey];
    }

    private function hasGlobalHiddenColumn(Maria $db): bool
    {
        if ($this->globalHiddenColumn !== null) {
            return $this->globalHiddenColumn;
        }
        try {
            $rows = $db->query(
                "SELECT COUNT(*) AS c\n" .
                "FROM information_schema.columns\n" .
                "WHERE table_schema = DATABASE()\n" .
                "  AND table_name = 'wa_tag_prefs_global'\n" .
                "  AND column_name = 'is_hidden'"
            );
            $this->globalHiddenColumn = ((int)($rows[0]["c"] ?? 0)) > 0;
        } catch (\Throwable $e) {
            $this->globalHiddenColumn = false;
        }
        return $this->globalHiddenColumn;
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
            if (($a["cnt"] ?? 0) !== ($b["cnt"] ?? 0)) {
                return ($b["cnt"] ?? 0) <=> ($a["cnt"] ?? 0);
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
        if ($value === true || $value === false) {
            return $value ? 1 : 0;
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

    private function revealHiddenParam(): bool
    {
        $value = $_GET["reveal_hidden"] ?? null;
        return $value === "1" || $value === 1 || $value === true;
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(["\\", "%", "_"], ["\\\\", "\\%", "\\_"], $value);
    }

    private function logAudit(
        Maria $db,
        ?int $actorId,
        ?int $targetId,
        string $action,
        string $source,
        ?array $details = null
    ): void {
        try {
            $ip = $_SERVER["REMOTE_ADDR"] ?? null;
            $agent = $_SERVER["HTTP_USER_AGENT"] ?? null;
            $db->exec(
                "INSERT INTO wa_audit_log (actor_user_id, target_user_id, action, source, ip_address, user_agent, details)\n" .
                "VALUES (?, ?, ?, ?, ?, ?, ?)",
                [
                    $actorId,
                    $targetId,
                    $action,
                    $source,
                    $ip,
                    $agent,
                    $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                ]
            );
            AuditLogMetaCache::invalidateIfMissing($action, $source);
        } catch (\Throwable $e) {
            // audit logging must not block tag updates
        }
    }

    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode($payload);
    }
}

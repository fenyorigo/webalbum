<?php

declare(strict_types=1);

namespace WebAlbum;

use WebAlbum\Db\Maria;

final class AuditLogMetaCache
{
    private const TTL_SECONDS = 300;

    private static ?array $cache = null;
    private static ?int $expiresAt = null;

    public static function get(Maria $db): array
    {
        $now = time();
        if (self::$cache !== null && self::$expiresAt !== null && $now < self::$expiresAt) {
            return self::$cache;
        }

        $meta = self::fetchMeta($db, true);
        if (empty($meta["actions"]) && empty($meta["sources"])) {
            $meta = self::fetchMeta($db, false);
        }

        self::$cache = $meta;
        self::$expiresAt = $now + self::TTL_SECONDS;

        return $meta;
    }

    public static function invalidateIfMissing(string $action, string $source): void
    {
        if (self::$cache === null) {
            return;
        }
        $action = trim($action);
        $source = self::normalizeSource($source);
        $actions = self::$cache["actions"] ?? [];
        $sources = self::$cache["sources"] ?? [];

        if (($action !== "" && !in_array($action, $actions, true))
            || ($source !== "" && !in_array($source, $sources, true))
        ) {
            self::$cache = null;
            self::$expiresAt = null;
        }
    }

    private static function fetchMeta(Maria $db, bool $recentOnly): array
    {
        $where = "";
        if ($recentOnly) {
            $where = " WHERE l.created_at >= (NOW() - INTERVAL 90 DAY)";
        }

        $actionsRows = $db->query(
            "SELECT l.action, COUNT(*) AS c FROM wa_audit_log l" . $where .
            " GROUP BY action ORDER BY c DESC, action ASC"
        );
        $sourcesRows = $db->query(
            "SELECT l.source, COUNT(*) AS c FROM wa_audit_log l" . $where .
            " GROUP BY source ORDER BY c DESC, source ASC"
        );
        $actorsRows = $db->query(
            "SELECT l.actor_user_id AS id, COALESCE(u.display_name, u.username) AS label, COUNT(*) AS c\n" .
            "FROM wa_audit_log l\n" .
            "LEFT JOIN wa_users u ON u.id = l.actor_user_id\n" .
            $where . ($where ? " AND" : " WHERE") . " l.actor_user_id IS NOT NULL\n" .
            "GROUP BY l.actor_user_id, label\n" .
            "ORDER BY c DESC, label ASC"
        );
        $targetsRows = $db->query(
            "SELECT l.target_user_id AS id, COALESCE(u.display_name, u.username) AS label, COUNT(*) AS c\n" .
            "FROM wa_audit_log l\n" .
            "LEFT JOIN wa_users u ON u.id = l.target_user_id\n" .
            $where . ($where ? " AND" : " WHERE") . " l.target_user_id IS NOT NULL\n" .
            "GROUP BY l.target_user_id, label\n" .
            "ORDER BY c DESC, label ASC"
        );

        $actions = [];
        foreach ($actionsRows as $row) {
            $value = trim((string)($row["action"] ?? ""));
            if ($value === "") {
                continue;
            }
            $actions[] = $value;
        }

        $sources = [];
        foreach ($sourcesRows as $row) {
            $value = trim((string)($row["source"] ?? ""));
            if ($value === "") {
                continue;
            }
            $value = self::normalizeSource($value);
            if (!in_array($value, $sources, true)) {
                $sources[] = $value;
            }
        }

        $actors = [];
        foreach ($actorsRows as $row) {
            $id = $row["id"] ?? null;
            if ($id === null) {
                continue;
            }
            $label = trim((string)($row["label"] ?? ""));
            $actors[] = [
                "id" => (int)$id,
                "label" => $label !== "" ? $label : (string)$id,
            ];
        }

        $targets = [];
        foreach ($targetsRows as $row) {
            $id = $row["id"] ?? null;
            if ($id === null) {
                continue;
            }
            $label = trim((string)($row["label"] ?? ""));
            $targets[] = [
                "id" => (int)$id,
                "label" => $label !== "" ? $label : (string)$id,
            ];
        }

        return [
            "actions" => $actions,
            "sources" => $sources,
            "actors" => $actors,
            "targets" => $targets,
        ];
    }

    private static function normalizeSource(string $source): string
    {
        $source = strtolower(trim($source));
        if ($source === "self") {
            return "ui";
        }
        return $source;
    }
}

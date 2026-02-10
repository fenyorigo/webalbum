<?php

declare(strict_types=1);

namespace WebAlbum\Http\Controllers;

use WebAlbum\Db\Maria;
use WebAlbum\AuditLogMetaCache;
use WebAlbum\UserContext;

final class AuditLogController
{
    private string $configPath;

    public function __construct(string $configPath)
    {
        $this->configPath = $configPath;
    }

    public function list(): void
    {
        try {
            $db = $this->connect();
            $user = UserContext::currentUser($db);
            if ($user === null) {
                $this->json(["error" => "Not authenticated"], 401);
                return;
            }
            if ((int)($user["is_admin"] ?? 0) !== 1) {
                $this->json(["error" => "Forbidden"], 403);
                return;
            }
            $page = $this->pageParam("page", 1);
            $pageSize = $this->pageSizeParam("page_size", 50, [25, 50, 100]);
            $offset = ($page - 1) * $pageSize;
            $filters = $this->filters();
            $where = $filters["where"];
            $params = $filters["params"];

            $totalRows = $db->query(
                "SELECT COUNT(*) AS c FROM wa_audit_log l\n" .
                "LEFT JOIN wa_users a ON a.id = l.actor_user_id\n" .
                "LEFT JOIN wa_users t ON t.id = l.target_user_id\n" .
                $where,
                $params
            );
            $total = (int)($totalRows[0]["c"] ?? 0);
            $totalPages = $pageSize > 0 ? (int)max(1, (int)ceil($total / $pageSize)) : 1;

            $rows = $db->query(
                "SELECT l.id, l.created_at, l.action, l.source, l.actor_user_id, l.target_user_id,\n" .
                "l.ip_address, l.user_agent, l.details,\n" .
                "a.username AS actor_username, a.display_name AS actor_display_name,\n" .
                "t.username AS target_username, t.display_name AS target_display_name\n" .
                "FROM wa_audit_log l\n" .
                "LEFT JOIN wa_users a ON a.id = l.actor_user_id\n" .
                "LEFT JOIN wa_users t ON t.id = l.target_user_id\n" .
                $where . "\n" .
                "ORDER BY l.created_at DESC, l.id DESC\n" .
                "LIMIT " . (int)$pageSize . " OFFSET " . (int)$offset,
                $params
            );

            foreach ($rows as &$row) {
                if (isset($row["source"]) && is_string($row["source"])) {
                    $row["source"] = $this->normalizeSource($row["source"]);
                }
                if (isset($row["details"]) && is_string($row["details"]) && $row["details"] !== "") {
                    $decoded = json_decode($row["details"], true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $row["details"] = $decoded;
                    }
                }
            }
            unset($row);

            $this->json([
                "page" => $page,
                "page_size" => $pageSize,
                "total" => $total,
                "total_pages" => $totalPages,
                "rows" => $rows,
            ]);
        } catch (\Throwable $e) {
            $this->json(["error" => $e->getMessage()], 500);
        }
    }

    public function meta(): void
    {
        try {
            $db = $this->connect();
            $user = UserContext::currentUser($db);
            if ($user === null) {
                $this->json(["error" => "Not authenticated"], 401);
                return;
            }
            if ((int)($user["is_admin"] ?? 0) !== 1) {
                $this->json(["error" => "Forbidden"], 403);
                return;
            }

            $this->json(AuditLogMetaCache::get($db));
        } catch (\Throwable $e) {
            $this->json(["error" => $e->getMessage()], 500);
        }
    }

    private function connect(): Maria
    {
        $config = require $this->configPath;
        return new Maria(
            $config["mariadb"]["dsn"],
            $config["mariadb"]["user"],
            $config["mariadb"]["pass"]
        );
    }

    private function pageParam(string $key, int $default): int
    {
        $value = $_GET[$key] ?? null;
        if ($value === null) {
            return $default;
        }
        $value = is_numeric($value) ? (int)$value : $default;
        return max(1, $value);
    }

    private function pageSizeParam(string $key, int $default, array $allowed): int
    {
        $value = $_GET[$key] ?? null;
        if ($value === null) {
            return $default;
        }
        $value = is_numeric($value) ? (int)$value : $default;
        if (!in_array($value, $allowed, true)) {
            return $default;
        }
        return $value;
    }

    private function filters(): array
    {
        $clauses = [];
        $params = [];

        $action = trim((string)($_GET["action"] ?? ""));
        if ($action !== "") {
            $clauses[] = "l.action = ?";
            $params[] = $action;
        }
        $source = trim((string)($_GET["source"] ?? ""));
        if ($source !== "") {
            $source = $this->normalizeSource($source);
            if ($source === "ui") {
                $clauses[] = "l.source IN (?, ?)";
                $params[] = "ui";
                $params[] = "self";
            } else {
                $clauses[] = "l.source = ?";
                $params[] = $source;
            }
        }
        $actorId = trim((string)($_GET["actor_user_id"] ?? ""));
        if ($actorId !== "" && is_numeric($actorId)) {
            $clauses[] = "l.actor_user_id = ?";
            $params[] = (int)$actorId;
        }
        $targetId = trim((string)($_GET["target_user_id"] ?? ""));
        if ($targetId !== "" && is_numeric($targetId)) {
            $clauses[] = "l.target_user_id = ?";
            $params[] = (int)$targetId;
        }

        $actor = trim((string)($_GET["actor"] ?? ""));
        if ($actor !== "" && $actorId === "") {
            $clauses[] = "(a.username LIKE ? OR a.display_name LIKE ?)";
            $needle = "%" . $actor . "%";
            $params[] = $needle;
            $params[] = $needle;
        }
        $target = trim((string)($_GET["target"] ?? ""));
        if ($target !== "" && $targetId === "") {
            $clauses[] = "(t.username LIKE ? OR t.display_name LIKE ?)";
            $needle = "%" . $target . "%";
            $params[] = $needle;
            $params[] = $needle;
        }

        $where = "";
        if ($clauses) {
            $where = " WHERE " . implode(" AND ", $clauses);
        }

        return ["where" => $where, "params" => $params];
    }

    private function normalizeSource(string $source): string
    {
        $source = strtolower(trim($source));
        if ($source === "self") {
            return "ui";
        }
        $allowed = ["setup", "ui", "admin", "api"];
        if (in_array($source, $allowed, true)) {
            return $source;
        }
        return $source;
    }

    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode($payload);
    }
}

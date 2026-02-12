<?php

declare(strict_types=1);

namespace WebAlbum\Http\Controllers;

use WebAlbum\Db\Maria;
use WebAlbum\AuditLogMetaCache;
use WebAlbum\UserContext;

final class UsersController
{
    private string $configPath;

    public function __construct(string $configPath)
    {
        $this->configPath = $configPath;
    }

    public function handle(): void
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
            $rows = $db->query(
                "SELECT id, username, display_name, is_active, is_admin, force_password_change\n" .
                "FROM wa_users ORDER BY display_name ASC"
            );
            $this->json($rows);
        } catch (\Throwable $e) {
            $this->json(["error" => $e->getMessage()], 500);
        }
    }

    public function create(): void
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
            $body = file_get_contents("php://input");
            $data = json_decode($body ?: "", true, 512, JSON_THROW_ON_ERROR);
            $username = isset($data["username"]) && is_string($data["username"]) ? trim($data["username"]) : "";
            $displayName = isset($data["display_name"]) && is_string($data["display_name"]) ? trim($data["display_name"]) : "";
            $password = isset($data["password"]) && is_string($data["password"]) ? $data["password"] : "";
            $isActive = $this->boolInt($data["is_active"] ?? 1);
            $isAdmin = $this->boolInt($data["is_admin"] ?? 0);
            if ($username === "") {
                $this->json(["error" => "Invalid username"], 400);
                return;
            }
            if ($displayName === "") {
                $displayName = $username;
            }
            if ($password === "") {
                $this->json(["error" => "Password is required"], 400);
                return;
            }
            if (strlen($password) < 8) {
                $this->json(["error" => "Password must be at least 8 characters"], 400);
                return;
            }
            $exists = $db->query("SELECT id FROM wa_users WHERE username = ?", [$username]);
            if ($exists !== []) {
                $this->json(["error" => "Username already exists"], 409);
                return;
            }
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $db->exec(
                "INSERT INTO wa_users (username, display_name, password_hash, is_active, is_admin, force_password_change)\n" .
                "VALUES (?, ?, ?, ?, ?, 1)",
                [$username, $displayName, $hash, $isActive, $isAdmin]
            );
            $idRow = $db->query("SELECT LAST_INSERT_ID() AS id");
            $newId = (int)($idRow[0]["id"] ?? 0);
            $this->logAudit($db, (int)$user["id"], $newId, "admin_user_create", "web", ["username" => $username, "display_name" => $displayName, "is_active" => $isActive === 1, "is_admin" => $isAdmin === 1]);
            $this->json([
                "id" => (int)$idRow[0]["id"],
                "username" => $username,
                "display_name" => $displayName,
            ], 201);
        } catch (\JsonException $e) {
            $this->json(["error" => "Invalid JSON"], 400);
        } catch (\Throwable $e) {
            $this->json(["error" => $e->getMessage()], 500);
        }
    }

    public function update(int $id): void
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
            $body = file_get_contents("php://input");
            $data = json_decode($body ?: "", true, 512, JSON_THROW_ON_ERROR);
            $existingRows = $db->query("SELECT id, username, display_name, is_active, is_admin FROM wa_users WHERE id = ?", [$id]);
            if ($existingRows === []) {
                $this->json(["error" => "Not Found"], 404);
                return;
            }
            $existing = $existingRows[0];
            $fields = [];
            $params = [];
            if (isset($data["username"])) {
                $username = is_string($data["username"]) ? trim($data["username"]) : "";
                if ($username === "") {
                    $this->json(["error" => "Invalid username"], 400);
                    return;
                }
                $exists = $db->query(
                    "SELECT id FROM wa_users WHERE username = ? AND id <> ?",
                    [$username, $id]
                );
                if ($exists !== []) {
                    $this->json(["error" => "Username already exists"], 409);
                    return;
                }
                $fields[] = "username = ?";
                $params[] = $username;
            }
            if (isset($data["display_name"])) {
                $displayName = is_string($data["display_name"]) ? trim($data["display_name"]) : "";
                if ($displayName === "") {
                    $this->json(["error" => "Invalid display name"], 400);
                    return;
                }
                $fields[] = "display_name = ?";
                $params[] = $displayName;
            }
            if (isset($data["is_active"])) {
                $fields[] = "is_active = ?";
                $params[] = $this->boolInt($data["is_active"]);
            }
            if (isset($data["is_admin"])) {
                $fields[] = "is_admin = ?";
                $params[] = $this->boolInt($data["is_admin"]);
            }
            if (isset($data["password"])) {
                $password = is_string($data["password"]) ? $data["password"] : "";
                if (strlen($password) < 8) {
                    $this->json(["error" => "Password must be at least 8 characters"], 400);
                    return;
                }
                $fields[] = "password_hash = ?";
                $params[] = password_hash($password, PASSWORD_DEFAULT);
                $fields[] = "force_password_change = 1";
                $this->logAudit($db, (int)$user["id"], $id, "admin_user_reset_password", "web");
            }
            if ($fields === []) {
                $this->json(["error" => "No updates"], 400);
                return;
            }
            $params[] = $id;
            $db->exec(
                "UPDATE wa_users SET " . implode(", ", $fields) . " WHERE id = ?",
                $params
            );

            $changed = array_map(static fn (string $f): string => trim(explode('=', $f)[0]), $fields);
            $this->logAudit($db, (int)$user["id"], $id, "admin_user_update", "web", ["fields" => $changed]);

            if (array_key_exists("is_active", $data)) {
                $newActive = $this->boolInt($data["is_active"]);
                if ((int)$existing["is_active"] !== $newActive) {
                    $this->logAudit($db, (int)$user["id"], $id, "admin_user_toggle_active", "web", ["is_active" => $newActive === 1]);
                }
            }
            if (array_key_exists("is_admin", $data)) {
                $newAdmin = $this->boolInt($data["is_admin"]);
                if ((int)$existing["is_admin"] !== $newAdmin) {
                    $this->logAudit($db, (int)$user["id"], $id, "admin_user_toggle_admin", "web", ["is_admin" => $newAdmin === 1]);
                }
            }

            $this->json(["ok" => true]);
        } catch (\JsonException $e) {
            $this->json(["error" => "Invalid JSON"], 400);
        } catch (\Throwable $e) {
            $this->json(["error" => $e->getMessage()], 500);
        }
    }

    public function setPassword(int $id): void
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
            $body = file_get_contents("php://input");
            $data = json_decode($body ?: "", true, 512, JSON_THROW_ON_ERROR);
            $password = isset($data["password"]) && is_string($data["password"]) ? $data["password"] : "";
            if (strlen($password) < 8) {
                $this->json(["error" => "Password must be at least 8 characters"], 400);
                return;
            }
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $count = $db->exec(
                "UPDATE wa_users SET password_hash = ?, force_password_change = 1 WHERE id = ?",
                [$hash, $id]
            );
            $this->logAudit($db, (int)$user["id"], $id, "admin_user_reset_password", "web");
            if ($count === 0) {
                $this->json(["error" => "Not Found"], 404);
                return;
            }
            $this->json(["ok" => true]);
        } catch (\JsonException $e) {
            $this->json(["error" => "Invalid JSON"], 400);
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

    private function boolInt($value): int
    {
        return $value ? 1 : 0;
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
            // audit logging must not block admin actions
        }
    }

    public function delete(int $id): void
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
            if ((int)$user["id"] === $id) {
                $this->json(["error" => "Cannot delete your own user"], 400);
                return;
            }
            $count = $db->exec("UPDATE wa_users SET is_active = 0 WHERE id = ?", [$id]);
            if ($count === 0) {
                $this->json(["error" => "Not Found"], 404);
                return;
            }
            $this->logAudit($db, (int)$user["id"], $id, "admin_user_delete", "web", ["is_active" => false]);
            $this->json(["ok" => true]);
        } catch (\Throwable $e) {
            $this->json(["error" => $e->getMessage()], 500);
        }
    }

    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode($payload);
    }
}

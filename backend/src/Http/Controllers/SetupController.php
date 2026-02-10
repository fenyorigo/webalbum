<?php

declare(strict_types=1);

namespace WebAlbum\Http\Controllers;

use WebAlbum\Db\Maria;
use WebAlbum\AuditLogMetaCache;

final class SetupController
{
    private string $configPath;

    public function __construct(string $configPath)
    {
        $this->configPath = $configPath;
    }

    public function status(): void
    {
        $required = $this->isSetupRequired();
        $this->json(["setup_required" => $required]);
    }

    public function create(): void
    {
        try {
            if (!$this->isSetupRequired()) {
                $this->json(["error" => "Setup already completed"], 409);
                return;
            }
            $body = file_get_contents("php://input");
            $data = json_decode($body ?: "", true, 512, JSON_THROW_ON_ERROR);
            $username = isset($data["username"]) && is_string($data["username"]) ? trim($data["username"]) : "";
            $password = isset($data["password"]) && is_string($data["password"]) ? $data["password"] : "";
            if ($username === "") {
                $this->json(["error" => "Invalid username"], 400);
                return;
            }
            $error = $this->validateStrongPassword($password);
            if ($error !== null) {
                $this->json(["error" => $error], 400);
                return;
            }
            $db = $this->connect();
            $exists = $db->query("SELECT id FROM wa_users WHERE username = ?", [$username]);
            if ($exists !== []) {
                $this->json(["error" => "Username already exists"], 409);
                return;
            }
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $db->exec(
                "INSERT INTO wa_users (username, display_name, password_hash, is_active, is_admin, force_password_change)\n" .
                "VALUES (?, ?, ?, 1, 1, 0)",
                [$username, $username, $hash]
            );
            $idRow = $db->query("SELECT LAST_INSERT_ID() AS id");
            $newId = (int)($idRow[0]["id"] ?? 0);
            $this->logAudit($db, null, $newId, "password_set", "setup");
            $this->ensureLock();
            $this->json(["ok" => true], 201);
        } catch (\JsonException $e) {
            $this->json(["error" => "Invalid JSON"], 400);
        } catch (\Throwable $e) {
            $this->json(["error" => $e->getMessage()], 500);
        }
    }

    private function isSetupRequired(): bool
    {
        if (is_file($this->lockPath())) {
            return false;
        }
        try {
            $db = $this->connect();
            $rows = $db->query("SELECT COUNT(*) AS cnt FROM wa_users");
            $count = (int)($rows[0]["cnt"] ?? 0);
            return $count === 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function ensureLock(): void
    {
        $path = $this->lockPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        if (!is_file($path)) {
            file_put_contents($path, "setup complete\n");
        }
    }

    private function lockPath(): string
    {
        $base = dirname($this->configPath, 2);
        return $base . "/var/setup.lock";
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

    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode($payload);
    }

    private function validateStrongPassword(string $password): ?string
    {
        if (strlen($password) < 12) {
            return "Password must be at least 12 characters";
        }
        if (!preg_match("/[a-z]/", $password)) {
            return "Password must include a lowercase letter";
        }
        if (!preg_match("/[A-Z]/", $password)) {
            return "Password must include an uppercase letter";
        }
        if (!preg_match("/[0-9]/", $password)) {
            return "Password must include a number";
        }
        if (!preg_match("/[^A-Za-z0-9]/", $password)) {
            return "Password must include a special character";
        }
        return null;
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
            // audit logging must not block setup
        }
    }
}

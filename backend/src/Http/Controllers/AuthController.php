<?php

declare(strict_types=1);

namespace WebAlbum\Http\Controllers;

use WebAlbum\Db\Maria;
use WebAlbum\AuditLogMetaCache;
use WebAlbum\SystemTools;
use WebAlbum\UserContext;

final class AuthController
{
    private string $configPath;

    public function __construct(string $configPath)
    {
        $this->configPath = $configPath;
    }

    public function login(): void
    {
        try {
            $body = file_get_contents("php://input");
            $data = json_decode($body ?: "", true, 512, JSON_THROW_ON_ERROR);
            $username = isset($data["username"]) && is_string($data["username"]) ? trim($data["username"]) : "";
            $password = isset($data["password"]) && is_string($data["password"]) ? $data["password"] : "";
            if ($username === "" || $password === "") {
                $this->json(["error" => "Invalid credentials"], 400);
                return;
            }
            $db = $this->connect();
            $clientIp = $this->clientIp();
            $retryAfter = $this->currentThrottleDelay($clientIp, $username);
            if ($retryAfter > 0) {
                header("Retry-After: " . (string)$retryAfter);
                $this->logAudit($db, null, null, "auth_throttle", "api", ["username" => $username, "retry_after" => $retryAfter]);
                $this->json(["error" => "Too many login attempts. Try again later."], 429);
                return;
            }
            $rows = $db->query(
                "SELECT id, username, display_name, password_hash, is_admin, force_password_change\n" .
                "FROM wa_users WHERE username = ? AND is_active = 1",
                [$username]
            );
            if ($rows === []) {
                $this->registerFailedAttempt($clientIp, $username);
                $this->json(["error" => "Invalid credentials"], 401);
                return;
            }
            $user = $rows[0];
            $hash = (string)$user["password_hash"];
            if ($hash === "" || !password_verify($password, $hash)) {
                $this->registerFailedAttempt($clientIp, $username);
                $this->json(["error" => "Invalid credentials"], 401);
                return;
            }
            $this->clearThrottle($clientIp, $username);
            session_regenerate_id(true);
            $_SESSION["wa_user_id"] = (int)$user["id"];
            $mustChange = (int)($user["force_password_change"] ?? 0) === 1;
            if ((int)($user["is_admin"] ?? 0) === 1) {
                try {
                    $config = require $this->configPath;
                    SystemTools::checkExternalTools($config, true);
                } catch (\Throwable $toolErr) {
                    // non-blocking
                }
            }
            $this->logAudit($db, (int)$user["id"], (int)$user["id"], "login", "ui");
            $this->json([
                "user" => [
                    "id" => (int)$user["id"],
                    "username" => $user["username"],
                    "display_name" => $user["display_name"],
                    "is_admin" => (int)$user["is_admin"] === 1,
                    "is_active" => 1,
                    "force_password_change" => $mustChange,
                ],
            ]);
        } catch (\JsonException $e) {
            $this->json(["error" => "Invalid JSON"], 400);
        } catch (\Throwable $e) {
            $this->json(["error" => $e->getMessage()], 500);
        }
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), "", time() - 3600, $params["path"] ?? "/", "", $params["secure"] ?? false, $params["httponly"] ?? true);
        }
        session_destroy();
        $this->json(["ok" => true]);
    }

    public function me(): void
    {
        $db = $this->connect();
        $user = UserContext::currentUser($db);
        if ($user === null) {
            $this->json(["error" => "Not authenticated"], 401);
            return;
        }
        $this->json(["user" => $user]);
    }

    public function changePassword(): void
    {
        try {
            $db = $this->connect();
            $user = UserContext::currentUser($db);
            if ($user === null) {
                $this->json(["error" => "Not authenticated"], 401);
                return;
            }
            $body = file_get_contents("php://input");
            $data = json_decode($body ?: "", true, 512, JSON_THROW_ON_ERROR);
            $current = isset($data["current_password"]) && is_string($data["current_password"])
                ? $data["current_password"]
                : "";
            $password = isset($data["new_password"]) && is_string($data["new_password"])
                ? $data["new_password"]
                : "";
            $confirm = isset($data["confirm_password"]) && is_string($data["confirm_password"])
                ? $data["confirm_password"]
                : "";
            if ($password === "" || $confirm === "" || $current === "") {
                $this->json(["error" => "Missing fields"], 400);
                return;
            }
            if ($password !== $confirm) {
                $this->json(["error" => "Passwords do not match"], 400);
                return;
            }
            $error = $this->validateStrongPassword($password);
            if ($error !== null) {
                $this->json(["error" => $error], 400);
                return;
            }
            $rows = $db->query(
                "SELECT password_hash FROM wa_users WHERE id = ?",
                [$user["id"]]
            );
            $hash = (string)($rows[0]["password_hash"] ?? "");
            if ($hash === "" || !password_verify($current, $hash)) {
                $this->json(["error" => "Current password is incorrect"], 400);
                return;
            }
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            $db->exec(
                "UPDATE wa_users SET password_hash = ?, force_password_change = 0 WHERE id = ?",
                [$newHash, $user["id"]]
            );
            $this->logAudit($db, (int)$user["id"], (int)$user["id"], "password_change", "ui");
            $this->json(["ok" => true]);
        } catch (\JsonException $e) {
            $this->json(["error" => "Invalid JSON"], 400);
        } catch (\Throwable $e) {
            $this->json(["error" => $e->getMessage()], 500);
        }
    }


    private function clientIp(): string
    {
        $xff = (string)($_SERVER["HTTP_X_FORWARDED_FOR"] ?? "");
        if ($xff !== "") {
            $first = trim(explode(",", $xff)[0] ?? "");
            if ($first !== "") {
                return $first;
            }
        }
        return (string)($_SERVER["REMOTE_ADDR"] ?? "unknown");
    }

    private function currentThrottleDelay(string $ip, string $username): int
    {
        $now = time();
        $key = $this->throttleKey($ip, $username);
        $entry = $this->mutateThrottleState(function (array &$state) use ($key, $now): array {
            $entry = $state[$key] ?? ["attempts" => [], "blocked_until" => 0];
            $attempts = array_values(array_filter((array)($entry["attempts"] ?? []), static fn ($ts): bool => (int)$ts > ($now - 300)));
            $blockedUntil = (int)($entry["blocked_until"] ?? 0);
            if ($blockedUntil <= $now) {
                $blockedUntil = 0;
            }
            $entry = ["attempts" => $attempts, "blocked_until" => $blockedUntil];
            if ($attempts === [] && $blockedUntil === 0) {
                unset($state[$key]);
            } else {
                $state[$key] = $entry;
            }
            return $entry;
        });

        $blockedUntil = (int)($entry["blocked_until"] ?? 0);
        return $blockedUntil > $now ? ($blockedUntil - $now) : 0;
    }

    private function registerFailedAttempt(string $ip, string $username): void
    {
        $now = time();
        $key = $this->throttleKey($ip, $username);
        $this->mutateThrottleState(function (array &$state) use ($key, $now): void {
            $entry = $state[$key] ?? ["attempts" => [], "blocked_until" => 0];
            $attempts = array_values(array_filter((array)($entry["attempts"] ?? []), static fn ($ts): bool => (int)$ts > ($now - 300)));
            $attempts[] = $now;
            $count = count($attempts);
            $blockedUntil = (int)($entry["blocked_until"] ?? 0);
            if ($count >= 5) {
                $step = max(0, $count - 5);
                $delay = min(900, 30 * (2 ** $step));
                $blockedUntil = max($blockedUntil, $now + $delay);
            }
            $state[$key] = ["attempts" => $attempts, "blocked_until" => $blockedUntil];
        });
    }

    private function clearThrottle(string $ip, string $username): void
    {
        $key = $this->throttleKey($ip, $username);
        $this->mutateThrottleState(function (array &$state) use ($key): void {
            unset($state[$key]);
        });
    }

    private function throttleKey(string $ip, string $username): string
    {
        return hash("sha256", strtolower($ip) . "|" . strtolower($username));
    }

    private function throttleStatePath(): string
    {
        return dirname($this->configPath, 2) . "/var/auth_throttle.json";
    }

    private function mutateThrottleState(callable $mutator): mixed
    {
        $path = $this->throttleStatePath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $fp = fopen($path, "c+");
        if ($fp === false) {
            return null;
        }

        try {
            if (!flock($fp, LOCK_EX)) {
                return null;
            }
            rewind($fp);
            $raw = stream_get_contents($fp);
            $state = [];
            if (is_string($raw) && trim($raw) !== "") {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $state = $decoded;
                }
            }

            $result = $mutator($state);

            rewind($fp);
            ftruncate($fp, 0);
            fwrite($fp, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            fflush($fp);
            flock($fp, LOCK_UN);
            return $result;
        } finally {
            fclose($fp);
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
            // audit logging must not block auth flows
        }
    }
}

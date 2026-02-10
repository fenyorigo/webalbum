<?php

declare(strict_types=1);

namespace WebAlbum\Http\Controllers;

use WebAlbum\Db\Maria;
use WebAlbum\UserContext;

final class SessionController
{
    private string $configPath;

    public function __construct(string $configPath)
    {
        $this->configPath = $configPath;
    }

    public function me(): void
    {
        try {
            $db = $this->connect();
            $user = UserContext::currentUser($db);
            $this->json(["user" => $user]);
        } catch (\Throwable $e) {
            $this->json(["error" => $e->getMessage()], 500);
        }
    }

    public function selectUser(): void
    {
        try {
            $body = file_get_contents("php://input");
            $data = json_decode($body ?: "", true, 512, JSON_THROW_ON_ERROR);
            $id = $data["user_id"] ?? null;
            if (!is_int($id) && !ctype_digit((string)$id)) {
                $this->json(["error" => "user_id must be an integer"], 400);
                return;
            }
            $id = (int)$id;
            if ($id < 1) {
                $this->json(["error" => "Invalid user_id"], 400);
                return;
            }
            $db = $this->connect();
            $rows = $db->query(
                "SELECT id, username, display_name FROM wa_users WHERE id = ? AND is_active = 1",
                [$id]
            );
            if ($rows === []) {
                $this->json(["error" => "User not found"], 404);
                return;
            }
            $user = $rows[0];
            $this->setUserCookie((string)$user["id"]);
            $this->json(["ok" => true, "user" => $user]);
        } catch (\JsonException $e) {
            $this->json(["error" => "Invalid JSON"], 400);
        } catch (\Throwable $e) {
            $this->json(["error" => $e->getMessage()], 500);
        }
    }

    public function logout(): void
    {
        $this->setUserCookie("", time() - 3600);
        $this->json(["ok" => true]);
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

    private function setUserCookie(string $value, ?int $expires = null): void
    {
        $secure = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off")
            || (isset($_SERVER["SERVER_PORT"]) && (int)$_SERVER["SERVER_PORT"] === 443);
        setcookie(
            "wa_user_id",
            $value,
            [
                "expires" => $expires ?? (time() + 180 * 24 * 60 * 60),
                "path" => "/",
                "httponly" => true,
                "samesite" => "Lax",
                "secure" => $secure,
            ]
        );
    }

    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode($payload);
    }
}

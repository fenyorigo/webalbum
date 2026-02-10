<?php

declare(strict_types=1);

namespace WebAlbum\Http\Controllers;

use WebAlbum\Db\Maria;
use WebAlbum\UserContext;

final class PrefsController
{
    private string $configPath;

    public function __construct(string $configPath)
    {
        $this->configPath = $configPath;
    }

    public function get(): void
    {
        $db = $this->connect();
        $user = UserContext::currentUser($db);
        if ($user === null) {
            $this->json(["error" => "Not authenticated"], 401);
            return;
        }
        $prefs = $this->loadPrefs($db, (int)$user["id"]);
        $this->json($prefs);
    }

    public function update(): void
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
            $current = $this->loadPrefs($db, (int)$user["id"]);
            $next = [
                "default_view" => $this->validateView($data["default_view"] ?? $current["default_view"]),
                "page_size" => $this->validatePageSize($data["page_size"] ?? $current["page_size"]),
                "thumb_size" => $this->validateThumbSize($data["thumb_size"] ?? $current["thumb_size"]),
                "sort_mode" => $this->validateSortMode($data["sort_mode"] ?? $current["sort_mode"]),
            ];
            $db->exec(
                "INSERT INTO wa_user_prefs (user_id, default_view, page_size, thumb_size, sort_mode) VALUES (?, ?, ?, ?, ?)\n" .
                "ON DUPLICATE KEY UPDATE default_view = VALUES(default_view), page_size = VALUES(page_size), thumb_size = VALUES(thumb_size), sort_mode = VALUES(sort_mode)",
                [
                    (int)$user["id"],
                    $next["default_view"],
                    $next["page_size"],
                    $next["thumb_size"],
                    $next["sort_mode"],
                ]
            );
            $this->json($next);
        } catch (\JsonException $e) {
            $this->json(["error" => "Invalid JSON"], 400);
        } catch (\Throwable $e) {
            $this->json(["error" => $e->getMessage()], 500);
        }
    }

    private function loadPrefs(Maria $db, int $userId): array
    {
        $defaults = [
            "default_view" => "grid",
            "page_size" => 50,
            "thumb_size" => 180,
            "sort_mode" => "name_az",
        ];
        $rows = $db->query(
            "SELECT default_view, page_size, thumb_size, sort_mode FROM wa_user_prefs WHERE user_id = ?",
            [$userId]
        );
        if ($rows === []) {
            return $defaults;
        }
        $row = $rows[0];
        return [
            "default_view" => $this->validateView($row["default_view"] ?? $defaults["default_view"]),
            "page_size" => $this->validatePageSize($row["page_size"] ?? $defaults["page_size"]),
            "thumb_size" => $this->validateThumbSize($row["thumb_size"] ?? $defaults["thumb_size"]),
            "sort_mode" => $this->validateSortMode($row["sort_mode"] ?? $defaults["sort_mode"]),
        ];
    }

    private function validateView($value): string
    {
        return $value === "list" ? "list" : "grid";
    }

    private function validatePageSize($value): int
    {
        $size = (int)$value;
        if ($size < 10) {
            return 10;
        }
        if ($size > 200) {
            return 200;
        }
        return $size;
    }

    private function validateThumbSize($value): int
    {
        $size = (int)$value;
        if ($size < 100) {
            return 100;
        }
        if ($size > 400) {
            return 400;
        }
        return $size;
    }

    private function validateSortMode($value): string
    {
        $allowed = ["name_az", "name_za", "date_new_old", "date_old_new"];
        return in_array($value, $allowed, true) ? $value : "name_az";
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
}

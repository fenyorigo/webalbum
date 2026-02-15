<?php

declare(strict_types=1);

namespace WebAlbum\Http\Controllers;

use WebAlbum\Db\SqliteIndex;
use WebAlbum\Db\Maria;
use WebAlbum\SystemTools;
use WebAlbum\UserContext;

final class HealthController
{
    private const VERSION = "webalbum 1.2.0";

    private string $configPath;

    public function __construct(string $configPath)
    {
        $this->configPath = $configPath;
    }

    public function handle(): void
    {
        try {
            $config = require $this->configPath;
            $db = new SqliteIndex($config["sqlite"]["path"]);

            $schema = null;
            $meta = $db->query("SELECT db_version FROM meta LIMIT 1");
            if ($meta !== []) {
                $schema = (int)$meta[0]["db_version"];
            }

            $files = (int)$db->query("SELECT COUNT(*) AS c FROM files")[0]["c"];
            $images = (int)$db->query("SELECT COUNT(*) AS c FROM files WHERE type = 'image'")[0]["c"];
            $videos = (int)$db->query("SELECT COUNT(*) AS c FROM files WHERE type = 'video'")[0]["c"];

            $tools = SystemTools::checkExternalTools($config);

            $this->json([
                "status" => "ok",
                "db" => [
                    "path" => $db->getPath(),
                    "read_only" => true,
                ],
                "version" => self::VERSION,
                "schema" => $schema,
                "time" => date("c"),
                "files_count" => $files,
                "images_count" => $images,
                "videos_count" => $videos,
                "tools" => $tools["tools"],
                "tools_checked_at" => $tools["checked_at"] ?? null,
            ]);
        } catch (\Throwable $e) {
            $this->json(["status" => "error", "error" => $e->getMessage()], 500);
        }
    }

    public function adminToolStatus(): void
    {
        try {
            $config = require $this->configPath;
            $admin = $this->requireAdmin($config);
            if ($admin === null) {
                return;
            }

            $tools = SystemTools::checkExternalTools($config);
            $this->json([
                "ok" => true,
                "tools" => $tools["tools"] ?? [],
                "tools_checked_at" => $tools["checked_at"] ?? null,
                "overrides" => $tools["overrides"] ?? [],
            ]);
        } catch (\Throwable $e) {
            $this->json(["error" => $e->getMessage()], 500);
        }
    }

    public function configureTools(): void
    {
        try {
            $config = require $this->configPath;
            $admin = $this->requireAdmin($config);
            if ($admin === null) {
                return;
            }

            $raw = file_get_contents("php://input") ?: "{}";
            $payload = json_decode($raw, true);
            if (!is_array($payload)) {
                $payload = [];
            }

            $updates = [];
            foreach (["exiftool", "ffmpeg", "ffprobe"] as $tool) {
                if (!array_key_exists($tool, $payload)) {
                    continue;
                }
                $value = trim((string)$payload[$tool]);
                if ($value !== "" && !$this->isAbsolutePath($value)) {
                    $this->json(["error" => ucfirst($tool) . " path must be absolute"], 400);
                    return;
                }
                $updates[$tool] = $value;
            }

            if ($updates === []) {
                $this->json(["error" => "No tool paths supplied"], 400);
                return;
            }

            SystemTools::setOverrides($updates);
            $tools = SystemTools::checkExternalTools($config, true);

            $this->json([
                "ok" => true,
                "tools" => $tools["tools"] ?? [],
                "tools_checked_at" => $tools["checked_at"] ?? null,
                "overrides" => $tools["overrides"] ?? [],
            ]);
        } catch (\Throwable $e) {
            $this->json(["error" => $e->getMessage()], 500);
        }
    }

    public function recheckTools(): void
    {
        try {
            $config = require $this->configPath;
            $admin = $this->requireAdmin($config);
            if ($admin === null) {
                return;
            }

            SystemTools::clearCache();
            $tools = SystemTools::checkExternalTools($config, true);
            $this->json([
                "ok" => true,
                "tools" => $tools["tools"],
                "tools_checked_at" => $tools["checked_at"] ?? null,
                "overrides" => $tools["overrides"] ?? [],
            ]);
        } catch (\Throwable $e) {
            $this->json(["error" => $e->getMessage()], 500);
        }
    }

    private function requireAdmin(array $config): ?array
    {
        $maria = new Maria(
            $config["mariadb"]["dsn"],
            $config["mariadb"]["user"],
            $config["mariadb"]["pass"]
        );
        $user = UserContext::currentUser($maria);
        if ($user === null) {
            $this->json(["error" => "Not authenticated"], 401);
            return null;
        }
        if ((int)($user["is_admin"] ?? 0) !== 1) {
            $this->json(["error" => "Forbidden"], 403);
            return null;
        }
        return $user;
    }

    private function isAbsolutePath(string $path): bool
    {
        if ($path === "") {
            return false;
        }
        if ($path[0] === "/") {
            return true;
        }
        return (bool)preg_match('/^[A-Za-z]:\\\\/', $path);
    }

    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode($payload);
    }
}

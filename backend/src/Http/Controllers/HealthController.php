<?php

declare(strict_types=1);

namespace WebAlbum\Http\Controllers;

use WebAlbum\Db\SqliteIndex;

final class HealthController
{
    private const VERSION = "webalbum 0.9.0";

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
            ]);
        } catch (\Throwable $e) {
            $this->json(["status" => "error", "error" => $e->getMessage()], 500);
        }
    }

    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode($payload);
    }
}

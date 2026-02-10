<?php

declare(strict_types=1);

namespace WebAlbum\Http\Controllers;

use WebAlbum\Db\Maria;
use WebAlbum\Db\SqliteIndex;
use WebAlbum\UserContext;

final class FileController
{
    private string $configPath;

    public function __construct(string $configPath)
    {
        $this->configPath = $configPath;
    }

    public function handle(int $id): void
    {
        try {
            if ($id < 1) {
                throw new \InvalidArgumentException("Invalid id");
            }

            $config = require $this->configPath;
            $maria = new Maria(
                $config["mariadb"]["dsn"],
                $config["mariadb"]["user"],
                $config["mariadb"]["pass"]
            );
            $user = UserContext::currentUser($maria);
            if ($user === null) {
                $this->json(["error" => "Not authenticated"], 401);
                return;
            }
            $db = new SqliteIndex($config["sqlite"]["path"]);

            $rows = $db->query(
                "SELECT id, path, rel_path, mime, type FROM files WHERE id = ?",
                [$id]
            );
            if ($rows === []) {
                $this->json(["error" => "Not Found"], 404);
                return;
            }

            $row = $rows[0];
            if (($row["type"] ?? "") !== "image") {
                $this->json(["error" => "Only images are supported"], 400);
                return;
            }

            $path = $this->resolveOriginalPath(
                $row["path"] ?? "",
                $row["rel_path"] ?? "",
                $config["photos"]["root"] ?? ""
            );
            if ($path === null || !is_file($path)) {
                $this->json(["error" => "File not found"], 404);
                return;
            }

            $mime = is_string($row["mime"]) && $row["mime"] !== "" ? $row["mime"] : $this->detectMime($path);
            header("Content-Type: " . $mime);
            header("Cache-Control: private, max-age=3600");
            header("Content-Length: " . (string)filesize($path));
            header("Content-Disposition: inline; filename=\"" . basename($path) . "\"");
            readfile($path);
        } catch (\Throwable $e) {
            $this->json(["error" => $e->getMessage()], 400);
        }
    }

    private function resolveOriginalPath(string $path, string $relPath, string $photosRoot): ?string
    {
        if ($path !== "" && is_file($path)) {
            return $path;
        }
        $fallback = $this->safeJoin($photosRoot, $relPath);
        if ($fallback === null) {
            return null;
        }
        $realRoot = realpath($photosRoot);
        $realFile = realpath($fallback);
        if ($realRoot === false || $realFile === false) {
            return null;
        }
        if (!str_starts_with($realFile, $realRoot . DIRECTORY_SEPARATOR)) {
            return null;
        }
        return $realFile;
    }

    private function safeJoin(string $root, string $relPath): ?string
    {
        if ($root === "" || $relPath === "") {
            return null;
        }
        $rel = str_replace("\\", "/", $relPath);
        if ($rel[0] === "/" || str_contains($rel, ":")) {
            return null;
        }
        foreach (explode("/", $rel) as $part) {
            if ($part === "..") {
                return null;
            }
        }
        return rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $rel;
    }

    private function detectMime(string $path): string
    {
        if (class_exists(\finfo::class)) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($path);
            if (is_string($mime) && $mime !== "") {
                return $mime;
            }
        }
        return "application/octet-stream";
    }

    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode($payload);
    }
}

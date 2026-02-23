<?php

declare(strict_types=1);

namespace WebAlbum\Http\Controllers;

use WebAlbum\Db\Maria;
use WebAlbum\Db\SqliteIndex;
use WebAlbum\UserContext;
use WebAlbum\Security\PathGuard;

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
            $relPath = trim((string)($row["rel_path"] ?? ""));
            if ($relPath !== "" && $this->isRelPathTrashed($maria, $relPath)) {
                $this->json(["error" => "Trashed"], 410);
                return;
            }
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
            $mtime = (int)filemtime($path);
            $size = (int)filesize($path);
            $etag = "\"" . md5((string)$mtime . ":" . (string)$size . ":" . $path) . "\"";
            header("Content-Type: " . $mime);
            header("Cache-Control: private, no-cache, must-revalidate, max-age=0");
            header("Pragma: no-cache");
            header("ETag: " . $etag);
            header("Last-Modified: " . gmdate("D, d M Y H:i:s", $mtime) . " GMT");
            if (isset($_SERVER["HTTP_IF_NONE_MATCH"]) && trim((string)$_SERVER["HTTP_IF_NONE_MATCH"]) === $etag) {
                http_response_code(304);
                return;
            }
            header("Content-Length: " . (string)$size);
            header("Content-Disposition: inline; filename=\"" . basename($path) . "\"");
            readfile($path);
        } catch (\Throwable $e) {
            $this->json(["error" => $e->getMessage()], 400);
        }
    }

    private function resolveOriginalPath(string $path, string $relPath, string $photosRoot): ?string
    {
        if ($path !== "" && is_file($path)) {
            return PathGuard::assertInsideRoot($path, $photosRoot);
        }
        $fallback = $this->safeJoin($photosRoot, $relPath);
        if ($fallback === null) {
            return null;
        }
        return PathGuard::assertInsideRoot($fallback, $photosRoot);
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

    private function isRelPathTrashed(Maria $maria, string $relPath): bool
    {
        try {
            $rows = $maria->query(
                "SELECT id FROM wa_media_trash WHERE rel_path = ? AND status = 'trashed' LIMIT 1",
                [$relPath]
            );
            return $rows !== [];
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode($payload);
    }
}

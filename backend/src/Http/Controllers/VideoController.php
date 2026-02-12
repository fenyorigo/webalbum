<?php

declare(strict_types=1);

namespace WebAlbum\Http\Controllers;

use WebAlbum\Db\Maria;
use WebAlbum\Db\SqliteIndex;
use WebAlbum\UserContext;

final class VideoController
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
            if (($row["type"] ?? "") !== "video") {
                $this->json(["error" => "Only videos are supported"], 400);
                return;
            }

            $photosRoot = (string)($config["photos"]["root"] ?? "");
            $path = $this->resolveOriginalPath(
                (string)($row["path"] ?? ""),
                (string)($row["rel_path"] ?? ""),
                $photosRoot
            );
            if ($path === null || !is_file($path)) {
                $this->json(["error" => "File not found"], 404);
                return;
            }

            $mime = is_string($row["mime"]) && $row["mime"] !== "" ? $row["mime"] : $this->detectMime($path);
            $size = filesize($path);
            if ($size === false) {
                throw new \RuntimeException("Unable to read file size");
            }

            header("Content-Type: " . $mime);
            header("Cache-Control: private, max-age=3600");
            header("Accept-Ranges: bytes");
            header("Content-Disposition: inline; filename=\"" . basename($path) . "\"");

            $rangeHeader = $_SERVER["HTTP_RANGE"] ?? null;
            if (!is_string($rangeHeader) || $rangeHeader === "") {
                header("Content-Length: " . (string)$size);
                $this->streamFile($path, 0, $size - 1);
                return;
            }

            [$start, $end] = $this->parseRange($rangeHeader, $size);
            if ($start === null || $end === null) {
                http_response_code(416);
                header("Content-Range: bytes */" . $size);
                return;
            }

            $length = $end - $start + 1;
            http_response_code(206);
            header("Content-Range: bytes " . $start . "-" . $end . "/" . $size);
            header("Content-Length: " . (string)$length);
            $this->streamFile($path, $start, $end);
        } catch (\Throwable $e) {
            $this->json(["error" => $e->getMessage()], 400);
        }
    }

    private function parseRange(string $header, int $size): array
    {
        if (!preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $m)) {
            return [null, null];
        }
        $startStr = $m[1];
        $endStr = $m[2];

        if ($startStr === "" && $endStr === "") {
            return [null, null];
        }

        if ($startStr === "") {
            $suffix = (int)$endStr;
            if ($suffix <= 0) {
                return [null, null];
            }
            $start = max(0, $size - $suffix);
            $end = $size - 1;
            return [$start, $end];
        }

        $start = (int)$startStr;
        $end = $endStr === "" ? $size - 1 : (int)$endStr;
        if ($start < 0 || $end < $start || $start >= $size) {
            return [null, null];
        }
        $end = min($end, $size - 1);
        return [$start, $end];
    }

    private function streamFile(string $path, int $start, int $end): void
    {
        $fh = fopen($path, "rb");
        if ($fh === false) {
            throw new \RuntimeException("Unable to open file");
        }
        try {
            fseek($fh, $start);
            $remaining = $end - $start + 1;
            $chunkSize = 1024 * 1024;
            while ($remaining > 0 && !feof($fh)) {
                $read = (int)min($chunkSize, $remaining);
                $buffer = fread($fh, $read);
                if ($buffer === false || $buffer === "") {
                    break;
                }
                echo $buffer;
                $remaining -= strlen($buffer);
                if (function_exists("ob_flush")) {
                    @ob_flush();
                }
                flush();
            }
        } finally {
            fclose($fh);
        }
    }

    private function resolveOriginalPath(string $path, string $relPath, string $photosRoot): ?string
    {
        $realRoot = realpath($photosRoot);
        if ($realRoot === false) {
            return null;
        }
        $rootPrefix = rtrim($realRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        if ($path !== "" && is_file($path)) {
            $realPath = realpath($path);
            if ($realPath !== false && str_starts_with($realPath, $rootPrefix)) {
                return $realPath;
            }
        }

        $fallback = $this->safeJoin($photosRoot, $relPath);
        if ($fallback === null) {
            return null;
        }
        $realFile = realpath($fallback);
        if ($realFile === false) {
            return null;
        }
        if (!str_starts_with($realFile, $rootPrefix)) {
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
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($ext) {
            "mp4" => "video/mp4",
            "mov" => "video/quicktime",
            "m4v" => "video/x-m4v",
            "webm" => "video/webm",
            "mkv" => "video/x-matroska",
            "avi" => "video/x-msvideo",
            default => "application/octet-stream",
        };
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

<?php

declare(strict_types=1);

namespace WebAlbum\Http\Controllers;

use WebAlbum\Db\Maria;
use WebAlbum\Db\SqliteIndex;
use WebAlbum\UserContext;

final class ThumbController
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
                "SELECT id, path, rel_path, type, mtime FROM files WHERE id = ?",
                [$id]
            );
            if ($rows === []) {
                $this->json(["error" => "Not Found"], 404);
                return;
            }

            $row = $rows[0];
            if (($row["type"] ?? "") !== "image") {
                $this->json(["error" => "Not Found"], 404);
                return;
            }

            $original = $this->resolveOriginalPath(
                $row["path"] ?? "",
                $row["rel_path"] ?? "",
                $config["photos"]["root"] ?? ""
            );
            if ($original === null || !is_file($original)) {
                $this->json(["error" => "File not found"], 404);
                return;
            }

            $thumbRoot = $config["thumbs"]["root"] ?? "";
            $thumbMax = (int)($config["thumbs"]["max"] ?? 256);
            $quality = (int)($config["thumbs"]["quality"] ?? 75);
            if ($thumbRoot === "") {
                throw new \RuntimeException("Thumbs root not configured");
            }
            $this->ensureDir($thumbRoot);

            $thumb = $this->thumbPath($thumbRoot, $row["rel_path"] ?? "");
            if ($thumb === null) {
                $this->json(["error" => "Invalid rel_path"], 400);
                return;
            }

            $this->ensureDir(dirname($thumb));

            if ($this->isFreshThumb($thumb, $original)) {
                $this->serveThumb($thumb);
                return;
            }

            $lockPath = $thumb . ".lock";
            $lock = fopen($lockPath, "c");
            if ($lock === false) {
                throw new \RuntimeException("Unable to lock thumb");
            }
            if (!flock($lock, LOCK_EX)) {
                fclose($lock);
                throw new \RuntimeException("Unable to lock thumb");
            }

            try {
                if ($this->isFreshThumb($thumb, $original)) {
                    $this->serveThumb($thumb);
                    return;
                }

                $tmp = $thumb . ".tmp";
                $this->generateThumb($original, $tmp, $thumbMax, $quality);
                rename($tmp, $thumb);
                $this->serveThumb($thumb);
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
                if (is_file($lockPath)) {
                    unlink($lockPath);
                }
            }
        } catch (\Throwable $e) {
            $this->json(["error" => $e->getMessage()], 500);
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

    private function thumbPath(string $thumbRoot, string $relPath): ?string
    {
        $full = $this->safeJoin($thumbRoot, $relPath);
        if ($full === null) {
            return null;
        }
        $info = pathinfo($full);
        return $info["dirname"] . DIRECTORY_SEPARATOR . $info["filename"] . ".jpg";
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new \RuntimeException("Unable to create directory: " . $dir);
            }
        }
    }

    private function isFreshThumb(string $thumb, string $original): bool
    {
        if (!is_file($thumb) || filesize($thumb) === 0) {
            return false;
        }
        return filemtime($thumb) >= filemtime($original);
    }

    private function serveThumb(string $thumb): void
    {
        $etag = "\"" . md5((string)filemtime($thumb) . ":" . (string)filesize($thumb)) . "\"";
        header("Content-Type: image/jpeg");
        header("Cache-Control: public, max-age=31536000");
        header("ETag: " . $etag);
        if (isset($_SERVER["HTTP_IF_NONE_MATCH"]) && trim($_SERVER["HTTP_IF_NONE_MATCH"]) === $etag) {
            http_response_code(304);
            return;
        }
        header("Content-Length: " . (string)filesize($thumb));
        readfile($thumb);
    }

    private function generateThumb(string $src, string $dest, int $max, int $quality): void
    {
        if (class_exists(\Imagick::class)) {
            $this->generateThumbImagick($src, $dest, $max, $quality);
            return;
        }
        $this->generateThumbGd($src, $dest, $max, $quality);
    }

    private function generateThumbImagick(string $src, string $dest, int $max, int $quality): void
    {
        $image = new \Imagick($src);
        $image->autoOrient();
        $image->thumbnailImage($max, $max, true, true);
        $image->stripImage();
        $image->setImageFormat("jpeg");
        $image->setImageCompressionQuality($quality);
        $image->writeImage($dest);
        $image->clear();
        $image->destroy();
    }

    private function generateThumbGd(string $src, string $dest, int $max, int $quality): void
    {
        $info = getimagesize($src);
        if ($info === false) {
            throw new \RuntimeException("Unsupported image");
        }
        [$width, $height] = $info;
        $mime = $info["mime"] ?? "";
        $image = match ($mime) {
            "image/jpeg" => imagecreatefromjpeg($src),
            "image/png" => imagecreatefrompng($src),
            "image/gif" => imagecreatefromgif($src),
            default => false,
        };
        if ($image === false) {
            throw new \RuntimeException("Unsupported image");
        }

        if ($mime === "image/jpeg" && function_exists("exif_read_data")) {
            $exif = @exif_read_data($src);
            $orientation = $exif["Orientation"] ?? 1;
            if ($orientation === 3) {
                $image = imagerotate($image, 180, 0);
            } elseif ($orientation === 6) {
                $image = imagerotate($image, -90, 0);
            } elseif ($orientation === 8) {
                $image = imagerotate($image, 90, 0);
            }
            $width = imagesx($image);
            $height = imagesy($image);
        }

        $ratio = min($max / $width, $max / $height, 1);
        $newW = (int)round($width * $ratio);
        $newH = (int)round($height * $ratio);
        $thumb = imagecreatetruecolor($newW, $newH);
        $white = imagecolorallocate($thumb, 255, 255, 255);
        imagefill($thumb, 0, 0, $white);
        imagecopyresampled($thumb, $image, 0, 0, 0, 0, $newW, $newH, $width, $height);
        imagejpeg($thumb, $dest, $quality);
        imagedestroy($thumb);
        imagedestroy($image);
    }

    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode($payload);
    }
}

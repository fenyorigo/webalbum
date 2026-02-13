<?php

declare(strict_types=1);

namespace WebAlbum\Http\Controllers;

use WebAlbum\Db\Maria;
use WebAlbum\Db\SqliteIndex;
use WebAlbum\UserContext;
use WebAlbum\SystemTools;
use WebAlbum\Security\PathGuard;

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
            $relPath = trim((string)($row["rel_path"] ?? ""));
            if ($relPath !== "" && $this->isRelPathTrashed($maria, $relPath)) {
                $this->json(["error" => "Trashed"], 410);
                return;
            }
            $type = (string)($row["type"] ?? "");
            if ($type !== "image" && $type !== "video") {
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
                $this->generateThumb($original, $tmp, $thumbMax, $quality, $type);
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

    private function generateThumb(string $src, string $dest, int $max, int $quality, string $type): void
    {
        if ($type === "video") {
            $this->generateVideoThumb($src, $dest, $max, $quality);
            return;
        }
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
    }

    private function generateVideoThumb(string $src, string $dest, int $max, int $quality): void
    {
        $config = require $this->configPath;
        $toolStatus = SystemTools::checkExternalTools($config);
        $ffmpegTool = $toolStatus['tools']['ffmpeg'] ?? ['available' => false, 'path' => null];
        if (!(bool)($ffmpegTool['available'] ?? false)) {
            $this->generateVideoPlaceholder($dest, $max, $quality);
            return;
        }

        $jpegQ = $this->ffmpegJpegQ($quality);
        $filter = "scale=w=" . $max . ":h=" . $max . ":force_original_aspect_ratio=decrease," .
            "pad=" . $max . ":" . $max . ":(ow-iw)/2:(oh-ih)/2:color=white";

        $ffmpeg = (string)($ffmpegTool['path'] ?? 'ffmpeg');
        $ok = $this->runFfmpegFrame($ffmpeg, $src, $dest, 3, $filter, $jpegQ);
        if (!$ok) {
            $ok = $this->runFfmpegFrame($ffmpeg, $src, $dest, 1, $filter, $jpegQ);
        }
        if (!$ok || !is_file($dest) || filesize($dest) === 0) {
            $this->generateVideoPlaceholder($dest, $max, $quality);
            return;
        }

        // If drawing overlay fails, keep the plain video thumbnail.
        $this->overlayPlayIcon($dest, $quality);
    }

    private function generateVideoPlaceholder(string $dest, int $max, int $quality): void
    {
        $size = max(64, $max);
        if (function_exists('imagecreatetruecolor')) {
            $img = imagecreatetruecolor($size, $size);
            $bg = imagecolorallocate($img, 238, 233, 222);
            imagefilledrectangle($img, 0, 0, $size, $size, $bg);
            $circle = imagecolorallocatealpha($img, 20, 20, 20, 75);
            imagefilledellipse($img, (int)($size / 2), (int)($size / 2), (int)($size * 0.38), (int)($size * 0.38), $circle);
            $white = imagecolorallocate($img, 255, 255, 255);
            $tw = (int)round($size * 0.12);
            $th = (int)round($size * 0.18);
            $cx = (int)($size / 2);
            $cy = (int)($size / 2);
            imagefilledpolygon($img, [
                $cx - (int)($tw * 0.35), $cy - (int)($th / 2),
                $cx - (int)($tw * 0.35), $cy + (int)($th / 2),
                $cx + (int)($tw * 0.65), $cy,
            ], $white);
            imagejpeg($img, $dest, max(1, min(100, $quality)));
            imagedestroy($img);
            return;
        }
        @file_put_contents($dest, '');
    }

    private function ffmpegJpegQ(int $quality): int
    {
        $quality = max(1, min(100, $quality));
        return max(2, min(31, (int)round((100 - $quality) / 3)));
    }

    private function runFfmpegFrame(string $ffmpeg, string $src, string $dest, int $seekSec, string $filter, int $jpegQ): bool
    {
        $cmd = escapeshellarg($ffmpeg) . " -v error -y " .
            "-ss " . (int)$seekSec . " " .
            "-i " . escapeshellarg($src) . " " .
            "-frames:v 1 " .
            "-vf " . escapeshellarg($filter) . " " .
            "-f image2 -vcodec mjpeg " .
            "-q:v " . (int)$jpegQ . " " .
            escapeshellarg($dest);

        $descriptors = [
            1 => ["pipe", "w"],
            2 => ["pipe", "w"],
        ];
        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            return false;
        }
        if (isset($pipes[1]) && is_resource($pipes[1])) {
            stream_get_contents($pipes[1]);
            fclose($pipes[1]);
        }
        if (isset($pipes[2]) && is_resource($pipes[2])) {
            stream_get_contents($pipes[2]);
            fclose($pipes[2]);
        }
        $exitCode = proc_close($process);
        return $exitCode === 0;
    }

    private function resolveFfmpegBinary(): string
    {
        $fromEnv = getenv("WA_FFMPEG_BIN");
        if (is_string($fromEnv) && $fromEnv !== "" && is_executable($fromEnv)) {
            return $fromEnv;
        }

        $candidates = [
            "/opt/homebrew/bin/ffmpeg",
            "/usr/local/bin/ffmpeg",
            "/usr/bin/ffmpeg",
            "ffmpeg",
        ];
        foreach ($candidates as $candidate) {
            if ($candidate === "ffmpeg") {
                return $candidate;
            }
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return "ffmpeg";
    }

    private function overlayPlayIcon(string $jpegPath, int $quality): void
    {
        if (!function_exists("imagecreatefromjpeg")) {
            return;
        }
        $img = @imagecreatefromjpeg($jpegPath);
        if ($img === false) {
            return;
        }

        $w = imagesx($img);
        $h = imagesy($img);
        $short = min($w, $h);
        $diameter = max(24, (int)round($short * 0.35));
        $radius = $diameter / 2.0;
        $cx = (int)round($w / 2);
        $cy = (int)round($h / 2);

        imagealphablending($img, true);
        imagesavealpha($img, false);

        $circle = imagecolorallocatealpha($img, 0, 0, 0, 63);
        imagefilledellipse($img, $cx, $cy, $diameter, $diameter, $circle);

        $triangleColor = imagecolorallocatealpha($img, 255, 255, 255, 0);
        $triW = max(10, (int)round($diameter * 0.40));
        $triH = max(12, (int)round($diameter * 0.46));
        $xLeft = (int)round($cx - ($triW * 0.35));
        $xRight = (int)round($cx + ($triW * 0.65));
        $yTop = (int)round($cy - ($triH / 2));
        $yBottom = (int)round($cy + ($triH / 2));

        imagefilledpolygon($img, [$xLeft, $yTop, $xLeft, $yBottom, $xRight, $cy], $triangleColor);

        imagejpeg($img, $jpegPath, max(1, min(100, $quality)));
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

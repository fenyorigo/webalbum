<?php

declare(strict_types=1);

namespace WebAlbum\Http\Controllers;

use WebAlbum\Db\Maria;
use WebAlbum\Db\SqliteIndex;
use WebAlbum\Security\PathGuard;
use WebAlbum\SystemTools;
use WebAlbum\Thumb\ThumbPolicy;
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
        $ctx = [
            "file_id" => $id,
            "media_type" => null,
            "thumb_path" => null,
        ];

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
            $type = (string)($row["type"] ?? "");
            $ctx["media_type"] = $type;
            if ($type !== "image" && $type !== "video") {
                $this->json(["error" => "Not Found"], 404);
                return;
            }

            $relPath = trim((string)($row["rel_path"] ?? ""));
            if ($relPath !== "" && $this->isRelPathTrashed($maria, $relPath)) {
                $this->json(["error" => "Trashed"], 410);
                return;
            }

            $original = $this->resolveOriginalPath(
                (string)($row["path"] ?? ""),
                (string)($row["rel_path"] ?? ""),
                (string)($config["photos"]["root"] ?? "")
            );
            if ($original === null || !is_file($original)) {
                $this->json(["error" => "File not found"], 404);
                return;
            }

            $thumbRoot = (string)($config["thumbs"]["root"] ?? "");
            $thumbMax = (int)($config["thumbs"]["max"] ?? 256);
            $quality = (int)($config["thumbs"]["quality"] ?? 75);
            if ($thumbRoot === "") {
                throw new \RuntimeException("Thumbs root not configured");
            }
            $this->ensureDir($thumbRoot);

            $thumb = ThumbPolicy::thumbPath($thumbRoot, (string)($row["rel_path"] ?? ""));
            if ($thumb === null) {
                $this->json(["error" => "Invalid rel_path"], 400);
                return;
            }
            $ctx["thumb_path"] = $thumb;
            $this->ensureDir(dirname($thumb));

            $this->logThumb("request", $ctx + ["original_path" => $original]);

            if ($this->isFreshThumb($thumb, $original, $type, $config)) {
                $this->logThumb("cache_hit", $ctx + ["state" => "fresh"]);
                $this->serveThumb($thumb);
                return;
            }

            $lock = $this->acquireThumbLock($thumb);
            if ($lock === null) {
                if ($this->isFreshThumb($thumb, $original, $type, $config)) {
                    $this->logThumb("cache_hit", $ctx + ["state" => "fresh_after_lock_busy"]);
                    $this->serveThumb($thumb);
                    return;
                }

                $this->logThumb("cache_miss", $ctx + ["reason" => "generation_in_progress"]);
                $this->serveTransientPlaceholder($type);
                return;
            }

            try {
                if ($this->isFreshThumb($thumb, $original, $type, $config)) {
                    $this->logThumb("cache_hit", $ctx + ["state" => "fresh_after_lock"]);
                    $this->serveThumb($thumb);
                    return;
                }

                ignore_user_abort(true);
                @set_time_limit(120);

                $start = microtime(true);
                $tmp = $this->tmpThumbPath($thumb);
                $this->logThumb("generation_start", $ctx + ["tmp_path" => $tmp]);

                try {
                    $genMeta = $this->generateThumb($original, $tmp, $thumbMax, $quality, $type, $config);
                    [$ok, $reason, $validateMeta] = ThumbPolicy::validateGeneratedThumb($tmp, $type, $config);
                    if (!$ok) {
                        throw new \RuntimeException("Thumb validation failed: " . $reason);
                    }
                    if (!@rename($tmp, $thumb)) {
                        throw new \RuntimeException("Failed to publish thumb atomically");
                    }

                    $durationMs = (int)round((microtime(true) - $start) * 1000);
                    $this->logThumb("generation_finish", $ctx + [
                        "duration_ms" => $durationMs,
                        "generator" => $genMeta,
                        "validation" => $validateMeta,
                    ]);

                    $this->serveThumb($thumb);
                    return;
                } finally {
                    if (isset($tmp) && is_string($tmp) && is_file($tmp)) {
                        @unlink($tmp);
                    }
                }
            } finally {
                $this->releaseThumbLock($lock);
            }
        } catch (\Throwable $e) {
            $this->logThumb("error", $ctx + ["error" => $e->getMessage()]);
            $this->json(["error" => $e->getMessage()], 500);
        }
    }

    private function resolveOriginalPath(string $path, string $relPath, string $photosRoot): ?string
    {
        if ($path !== "" && is_file($path)) {
            return PathGuard::assertInsideRoot($path, $photosRoot);
        }
        $fallback = ThumbPolicy::safeJoin($photosRoot, $relPath);
        if ($fallback === null) {
            return null;
        }
        return PathGuard::assertInsideRoot($fallback, $photosRoot);
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new \RuntimeException("Unable to create directory: " . $dir);
            }
        }
    }

    private function isFreshThumb(string $thumb, string $original, string $type, array $config): bool
    {
        if (!$this->isUsableThumb($thumb, $type, $config)) {
            return false;
        }
        return filemtime($thumb) >= filemtime($original);
    }

    private function isUsableThumb(string $thumb, string $type, array $config): bool
    {
        if (!is_file($thumb)) {
            return false;
        }
        $size = (int)@filesize($thumb);
        if ($size <= 0) {
            return false;
        }
        $dims = @getimagesize($thumb);
        if (!is_array($dims) || !isset($dims[0], $dims[1])) {
            return false;
        }
        if ((int)$dims[0] <= 0 || (int)$dims[1] <= 0) {
            return false;
        }
        if (ThumbPolicy::isLikelyPlaceholderThumb($thumb, $type, $config)) {
            return false;
        }
        return true;
    }

    private function serveThumb(string $thumb): void
    {
        $mtime = (int)filemtime($thumb);
        $size = (int)filesize($thumb);
        $etag = "\"" . md5((string)$mtime . ":" . (string)$size) . "\"";
        header("Content-Type: image/jpeg");
        header("Cache-Control: private, no-cache, must-revalidate, max-age=0");
        header("Pragma: no-cache");
        header("ETag: " . $etag);
        header("Last-Modified: " . gmdate("D, d M Y H:i:s", $mtime) . " GMT");
        if (isset($_SERVER["HTTP_IF_NONE_MATCH"]) && trim((string)$_SERVER["HTTP_IF_NONE_MATCH"]) === $etag) {
            http_response_code(304);
            return;
        }
        header("Content-Length: " . (string)$size);
        readfile($thumb);
    }

    private function serveTransientPlaceholder(string $type): void
    {
        $label = $type === "video" ? "Video" : "Image";
        $glyph = $type === "video" ? ">" : "IMG";
        $svg = "<svg xmlns='http://www.w3.org/2000/svg' width='256' height='256' viewBox='0 0 256 256'>"
            . "<rect width='256' height='256' fill='#efe9de'/>"
            . "<circle cx='128' cy='128' r='52' fill='rgba(0,0,0,0.45)'/>"
            . "<text x='128' y='136' text-anchor='middle' font-size='36' fill='#ffffff' font-family='Arial,sans-serif'>" . htmlspecialchars($glyph, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</text>"
            . "<text x='128' y='228' text-anchor='middle' font-size='14' fill='#555' font-family='Arial,sans-serif'>" . $label . " thumbnail pending</text>"
            . "</svg>";

        http_response_code(200);
        header("Content-Type: image/svg+xml; charset=utf-8");
        header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
        header("Pragma: no-cache");
        echo $svg;
    }

    private function acquireThumbLock(string $thumbPath)
    {
        $lockPath = $this->thumbLockPath($thumbPath);
        $lock = @fopen($lockPath, "c");
        if ($lock === false) {
            $this->logThumb("lock_open_failed", ["thumb_path" => $thumbPath, "lock_path" => $lockPath]);
            return null;
        }
        if (!@flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return null;
        }
        return $lock;
    }

    private function releaseThumbLock($lock): void
    {
        if (is_resource($lock)) {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function tmpThumbPath(string $thumb): string
    {
        $rand = bin2hex(random_bytes(4));
        $dir = dirname($thumb);
        $base = pathinfo($thumb, PATHINFO_FILENAME);
        return $dir . DIRECTORY_SEPARATOR . $base . ".tmp." . getmypid() . "." . $rand . ".jpg";
    }

    private function thumbLockPath(string $thumbPath): string
    {
        $tmp = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
        if ($tmp === '') {
            $tmp = '/tmp';
        }
        return $tmp . DIRECTORY_SEPARATOR . 'wa-thumb-' . sha1($thumbPath) . '.lock';
    }

    private function generateThumb(string $src, string $dest, int $max, int $quality, string $type, array $config): array
    {
        if ($type === "video") {
            return $this->generateVideoThumb($src, $dest, $max, $quality, $config);
        }
        if (class_exists(\Imagick::class)) {
            $this->generateThumbImagick($src, $dest, $max, $quality);
            return ["engine" => "imagick", "exit_code" => 0];
        }
        $this->generateThumbGd($src, $dest, $max, $quality);
        return ["engine" => "gd", "exit_code" => 0];
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

    private function generateVideoThumb(string $src, string $dest, int $max, int $quality, array $config): array
    {
        $toolStatus = SystemTools::checkExternalTools($config);
        $ffmpegTool = $toolStatus["tools"]["ffmpeg"] ?? ["available" => false, "path" => null];
        if (!(bool)($ffmpegTool["available"] ?? false)) {
            throw new \RuntimeException("ffmpeg not available for video thumbnail generation");
        }

        $jpegQ = $this->ffmpegJpegQ($quality);
        $filter = "scale=w=" . $max . ":h=" . $max . ":force_original_aspect_ratio=decrease,"
            . "pad=" . $max . ":" . $max . ":(ow-iw)/2:(oh-ih)/2:color=white";

        $ffmpeg = (string)($ffmpegTool["path"] ?? "ffmpeg");
        $attempts = [];

        $first = $this->runFfmpegFrame($ffmpeg, $src, $dest, 3, $filter, $jpegQ);
        $attempts[] = $first;
        if (!($first["ok"] ?? false) || !is_file($dest) || (int)@filesize($dest) <= 0) {
            $second = $this->runFfmpegFrame($ffmpeg, $src, $dest, 1, $filter, $jpegQ);
            $attempts[] = $second;
        }
        if (!is_file($dest) || (int)@filesize($dest) <= 0) {
            $third = $this->runFfmpegFrame($ffmpeg, $src, $dest, null, $filter, $jpegQ);
            $attempts[] = $third;
        }
        if (!is_file($dest) || (int)@filesize($dest) <= 0) {
            $fallbackFilter = "thumbnail=24," . $filter;
            $fourth = $this->runFfmpegFrame($ffmpeg, $src, $dest, null, $fallbackFilter, $jpegQ);
            $attempts[] = $fourth;
        }

        if (!is_file($dest) || (int)filesize($dest) <= 0) {
            $last = end($attempts);
            $stderr = $this->clip((string)($last["stderr"] ?? ""), 220);
            $dir = dirname($dest);
            $writable = is_dir($dir) && is_writable($dir) ? "yes" : "no";
            throw new \RuntimeException(
                "ffmpeg did not produce thumbnail output (dir_writable=" . $writable . ", dest=" . $dest . ", stderr=" . $stderr . ")"
            );
        }

        $this->overlayPlayIcon($dest, $quality);

        $last = end($attempts);
        return [
            "engine" => "ffmpeg",
            "exit_code" => (int)($last["exit_code"] ?? 0),
            "stderr" => $this->clip((string)($last["stderr"] ?? ""), 200),
            "attempts" => count($attempts),
        ];
    }

    private function ffmpegJpegQ(int $quality): int
    {
        $quality = max(1, min(100, $quality));
        return max(2, min(31, (int)round((100 - $quality) / 3)));
    }

    private function runFfmpegFrame(string $ffmpeg, string $src, string $dest, ?int $seekSec, string $filter, int $jpegQ): array
    {
        $seekArg = $seekSec !== null ? ("-ss " . (int)$seekSec . " ") : "";
        $cmd = escapeshellarg($ffmpeg) . " -v warning -y "
            . $seekArg
            . "-i " . escapeshellarg($src) . " "
            . "-an "
            . "-frames:v 1 "
            . "-vf " . escapeshellarg($filter) . " "
            . "-f image2 -update 1 -vcodec mjpeg "
            . "-q:v " . (int)$jpegQ . " "
            . escapeshellarg($dest);

        $descriptors = [
            1 => ["pipe", "w"],
            2 => ["pipe", "w"],
        ];
        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            return ["ok" => false, "exit_code" => -1, "stderr" => "proc_open failed"];
        }

        $stdout = "";
        $stderr = "";
        if (isset($pipes[1]) && is_resource($pipes[1])) {
            $stdout = (string)stream_get_contents($pipes[1]);
            fclose($pipes[1]);
        }
        if (isset($pipes[2]) && is_resource($pipes[2])) {
            $stderr = (string)stream_get_contents($pipes[2]);
            fclose($pipes[2]);
        }

        $exitCode = proc_close($process);
        return [
            "ok" => $exitCode === 0,
            "exit_code" => (int)$exitCode,
            "stdout" => $stdout,
            "stderr" => $stderr,
            "seek_sec" => $seekSec,
        ];
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

    private function clip(string $text, int $max): string
    {
        $clean = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        if ($clean === '') {
            return '';
        }
        if (strlen($clean) <= $max) {
            return $clean;
        }
        return substr($clean, 0, $max) . "...";
    }

    private function logThumb(string $event, array $context): void
    {
        $payload = [
            "event" => "thumb_" . $event,
            "ts" => date('c'),
            "context" => $context,
        ];
        error_log(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode($payload);
    }
}

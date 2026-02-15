<?php

declare(strict_types=1);

use WebAlbum\Db\SqliteIndex;
use WebAlbum\SystemTools;
use WebAlbum\Thumb\ThumbPolicy;

$root = dirname(__DIR__);
$autoload = $root . "/vendor/autoload.php";
if (is_file($autoload)) {
    require $autoload;
} else {
    spl_autoload_register(function (string $class): void {
        if (!str_starts_with($class, "WebAlbum\\")) {
            return;
        }
        $path = __DIR__ . "/../src/" . str_replace("\\", "/", substr($class, 9)) . ".php";
        if (is_file($path)) {
            require $path;
        }
    });
}

$opts = getopt("", [
    "config::",
    "id::",
    "limit::",
    "only-missing::",
]);

$configPath = (string)($opts["config"] ?? ($root . "/config/config.php"));
$id = isset($opts["id"]) ? (int)$opts["id"] : 0;
$limit = isset($opts["limit"]) ? max(1, min(500000, (int)$opts["limit"])) : 20000;
$onlyMissing = isset($opts["only-missing"]) ? (bool)((int)$opts["only-missing"]) : true;

if (!is_file($configPath)) {
    fwrite(STDERR, "Config not found: {$configPath}\n");
    exit(1);
}

try {
    $config = require $configPath;
    $sqlitePath = (string)($config["sqlite"]["path"] ?? "");
    $photosRoot = (string)($config["photos"]["root"] ?? "");
    $thumbRoot = (string)($config["thumbs"]["root"] ?? "");
    $max = (int)($config["thumbs"]["max"] ?? 256);
    $quality = (int)($config["thumbs"]["quality"] ?? 75);

    if ($sqlitePath === "" || $photosRoot === "" || $thumbRoot === "") {
        throw new RuntimeException("Missing sqlite/photos/thumbs root in config");
    }

    $tools = SystemTools::checkExternalTools($config, true);
    $ffmpeg = $tools["tools"]["ffmpeg"] ?? ["available" => false, "path" => null];
    if (!(bool)($ffmpeg["available"] ?? false)) {
        throw new RuntimeException("ffmpeg not available");
    }
    $ffmpegBin = (string)($ffmpeg["path"] ?? "ffmpeg");

    $db = new SqliteIndex($sqlitePath);
    if ($id > 0) {
        $rows = $db->query("SELECT id, path, rel_path, type FROM files WHERE id = ? LIMIT 1", [$id]);
    } else {
        $rows = $db->query("SELECT id, path, rel_path, type FROM files WHERE type = 'video' ORDER BY id ASC LIMIT ?", [$limit]);
    }

    $scanned = 0;
    $generated = 0;
    $skipped = 0;
    $errors = 0;

    foreach ($rows as $row) {
        if ((string)($row["type"] ?? "") !== "video") {
            continue;
        }
        $scanned++;

        $relPath = trim((string)($row["rel_path"] ?? ""));
        if ($relPath === "") {
            $errors++;
            continue;
        }
        $src = resolveOriginalPath((string)($row["path"] ?? ""), $relPath, $photosRoot);
        if ($src === null || !is_file($src)) {
            $errors++;
            continue;
        }
        $thumb = ThumbPolicy::thumbPath($thumbRoot, $relPath);
        if ($thumb === null) {
            $errors++;
            continue;
        }

        $thumbDir = dirname($thumb);
        if (!is_dir($thumbDir) && !mkdir($thumbDir, 0755, true) && !is_dir($thumbDir)) {
            $errors++;
            continue;
        }

        if ($onlyMissing && is_file($thumb) && !ThumbPolicy::isLikelyPlaceholderThumb($thumb, "video", $config)) {
            $skipped++;
            continue;
        }

        $lock = @fopen(lockPathForThumb($thumb), "c");
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            $skipped++;
            continue;
        }

        $tmp = tmpThumbPath($thumb);
        try {
            generateVideoThumb($ffmpegBin, $src, $tmp, $max, $quality);
            [$ok, $reason] = ThumbPolicy::validateGeneratedThumb($tmp, "video", $config);
            if (!$ok) {
                throw new RuntimeException("validate failed: " . $reason);
            }
            if (!@rename($tmp, $thumb)) {
                throw new RuntimeException("rename failed");
            }
            $generated++;
        } catch (Throwable $e) {
            $errors++;
            fwrite(STDERR, "id=" . (int)$row["id"] . " error=" . $e->getMessage() . "\n");
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    $report = [
        "ok" => true,
        "scanned" => $scanned,
        "generated" => $generated,
        "skipped" => $skipped,
        "errors" => $errors,
        "only_missing" => $onlyMissing,
    ];
    echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}

function resolveOriginalPath(string $path, string $relPath, string $photosRoot): ?string
{
    if ($path !== "" && is_file($path)) {
        return $path;
    }
    return ThumbPolicy::safeJoin($photosRoot, $relPath);
}

function lockPathForThumb(string $thumbPath): string
{
    $tmp = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
    if ($tmp === "") {
        $tmp = "/tmp";
    }
    return $tmp . DIRECTORY_SEPARATOR . "wa-thumb-" . sha1($thumbPath) . ".lock";
}

function tmpThumbPath(string $thumbPath): string
{
    $dir = dirname($thumbPath);
    $base = pathinfo($thumbPath, PATHINFO_FILENAME);
    return $dir . DIRECTORY_SEPARATOR . $base . ".tmp." . getmypid() . "." . bin2hex(random_bytes(4)) . ".jpg";
}

function generateVideoThumb(string $ffmpeg, string $src, string $dest, int $max, int $quality): void
{
    $jpegQ = ffmpegJpegQ($quality);
    $filter = "scale=w=" . $max . ":h=" . $max . ":force_original_aspect_ratio=decrease,"
        . "pad=" . $max . ":" . $max . ":(ow-iw)/2:(oh-ih)/2:color=white";

    $first = runFfmpegFrame($ffmpeg, $src, $dest, 3, $filter, $jpegQ);
    $second = null;
    $third = null;
    $fourth = null;
    if (!($first["ok"] ?? false) || !is_file($dest) || (int)@filesize($dest) <= 0) {
        $second = runFfmpegFrame($ffmpeg, $src, $dest, 1, $filter, $jpegQ);
    }
    if (!is_file($dest) || (int)@filesize($dest) <= 0) {
        $third = runFfmpegFrame($ffmpeg, $src, $dest, null, $filter, $jpegQ);
    }
    if (!is_file($dest) || (int)@filesize($dest) <= 0) {
        $fallbackFilter = "thumbnail=24," . $filter;
        $fourth = runFfmpegFrame($ffmpeg, $src, $dest, null, $fallbackFilter, $jpegQ);
    }

    if (!is_file($dest) || (int)filesize($dest) <= 0) {
        $dir = dirname($dest);
        $writable = is_dir($dir) && is_writable($dir) ? "yes" : "no";
        $err = trim((string)(
            (($fourth["stderr"] ?? ""))
            ?: (($third["stderr"] ?? ""))
            ?: (($second["stderr"] ?? ""))
            ?: ($first["stderr"] ?? "")
        ));
        throw new RuntimeException(
            "ffmpeg produced no output (dir_writable=" . $writable . ", dest=" . $dest . ", stderr=" . substr($err, 0, 220) . ")"
        );
    }

    overlayPlayIcon($dest, $quality);
}

function ffmpegJpegQ(int $quality): int
{
    $quality = max(1, min(100, $quality));
    return max(2, min(31, (int)round((100 - $quality) / 3)));
}

function runFfmpegFrame(string $ffmpeg, string $src, string $dest, ?int $seekSec, string $filter, int $jpegQ): array
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

    $descriptors = [1 => ["pipe", "w"], 2 => ["pipe", "w"]];
    $proc = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) {
        return ["ok" => false, "exit_code" => -1, "stderr" => "proc_open failed"];
    }

    $stderr = "";
    if (isset($pipes[1]) && is_resource($pipes[1])) {
        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
    }
    if (isset($pipes[2]) && is_resource($pipes[2])) {
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[2]);
    }

    $code = proc_close($proc);
    return ["ok" => $code === 0, "exit_code" => $code, "stderr" => $stderr];
}

function overlayPlayIcon(string $jpegPath, int $quality): void
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

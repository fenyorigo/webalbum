<?php

declare(strict_types=1);

use WebAlbum\Db\SqliteIndex;

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
    "db:",
    "photos-root:",
    "thumb-root:",
    "id:",
    "max::",
    "quality::",
    "regen::",
]);

$dbPath = (string)($opts["db"] ?? "");
$photosRoot = (string)($opts["photos-root"] ?? "");
$thumbRoot = (string)($opts["thumb-root"] ?? "");
$id = isset($opts["id"]) ? (int)$opts["id"] : 0;
$max = isset($opts["max"]) ? max(32, (int)$opts["max"]) : 256;
$quality = isset($opts["quality"]) ? max(1, min(100, (int)$opts["quality"])) : 75;
$regen = isset($opts["regen"]) ? (bool)((int)$opts["regen"]) : true;

if ($dbPath === "" || $photosRoot === "" || $thumbRoot === "") {
    fwrite(STDERR, "Usage: php backend/bin/video_thumb_smoke.php --db /path/index.db --photos-root /path/photos --thumb-root /path/thumbs [--id 123] [--max 256] [--quality 75] [--regen 1]\n");
    exit(1);
}

try {
    $db = new SqliteIndex($dbPath);
    if ($id > 0) {
        $rows = $db->query(
            "SELECT id, path, rel_path, type FROM files WHERE id = ?",
            [$id]
        );
    } else {
        $rows = $db->query(
            "SELECT id, path, rel_path, type FROM files WHERE type = 'video' ORDER BY id ASC LIMIT 1"
        );
    }

    if ($rows === []) {
        throw new RuntimeException("No video row found");
    }
    $row = $rows[0];
    if (($row["type"] ?? "") !== "video") {
        throw new RuntimeException("Selected id is not a video");
    }

    $src = resolveOriginalPath((string)$row["path"], (string)$row["rel_path"], $photosRoot);
    if ($src === null || !is_file($src)) {
        throw new RuntimeException("Source video not found");
    }
    $thumb = thumbPath($thumbRoot, (string)$row["rel_path"]);
    if ($thumb === null) {
        throw new RuntimeException("Invalid rel_path");
    }
    ensureDir(dirname($thumb));
    if ($regen && is_file($thumb)) {
        @unlink($thumb);
    }

    $tmp = $thumb . ".tmp";
    generateVideoThumb($src, $tmp, $max, $quality);
    rename($tmp, $thumb);

    $check = checkOverlay($thumb);
    echo "Video id: " . (int)$row["id"] . "\n";
    echo "Source: " . $src . "\n";
    echo "Thumb: " . $thumb . "\n";
    echo "Overlay check: " . ($check["ok"] ? "PASS" : "WARN") . "\n";
    echo "Circle pixel RGB: " . implode(",", $check["circle_rgb"]) . "\n";
    echo "Triangle pixel RGB: " . implode(",", $check["triangle_rgb"]) . "\n";
    exit($check["ok"] ? 0 : 2);
} catch (Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}

function generateVideoThumb(string $src, string $dest, int $max, int $quality): void
{
    $jpegQ = ffmpegJpegQ($quality);
    $filter = "scale=w=" . $max . ":h=" . $max . ":force_original_aspect_ratio=decrease," .
        "pad=" . $max . ":" . $max . ":(ow-iw)/2:(oh-ih)/2:color=white";

    $ok = runFfmpegFrame($src, $dest, 3, $filter, $jpegQ);
    if (!$ok) {
        $ok = runFfmpegFrame($src, $dest, 1, $filter, $jpegQ);
    }
    if (!$ok || !is_file($dest) || filesize($dest) === 0) {
        throw new RuntimeException("ffmpeg thumbnail generation failed");
    }

    overlayPlayIcon($dest, $quality);
}

function ffmpegJpegQ(int $quality): int
{
    $quality = max(1, min(100, $quality));
    return max(2, min(31, (int)round((100 - $quality) / 3)));
}

function runFfmpegFrame(string $src, string $dest, int $seekSec, string $filter, int $jpegQ): bool
{
    $cmd = "ffmpeg -v error -y " .
        "-ss " . (int)$seekSec . " " .
        "-i " . escapeshellarg($src) . " " .
        "-frames:v 1 " .
        "-vf " . escapeshellarg($filter) . " " .
        "-f image2 -vcodec mjpeg " .
        "-q:v " . (int)$jpegQ . " " .
        escapeshellarg($dest);

    $descriptors = [1 => ["pipe", "w"], 2 => ["pipe", "w"]];
    $proc = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) {
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
    return proc_close($proc) === 0;
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

function checkOverlay(string $jpegPath): array
{
    $img = @imagecreatefromjpeg($jpegPath);
    if ($img === false) {
        return ["ok" => false, "circle_rgb" => [0, 0, 0], "triangle_rgb" => [0, 0, 0]];
    }

    $w = imagesx($img);
    $h = imagesy($img);
    $short = min($w, $h);
    $diameter = max(24, (int)round($short * 0.35));
    $cx = (int)round($w / 2);
    $cy = (int)round($h / 2);
    $r = (int)round($diameter / 2);

    $circleX = max(0, min($w - 1, $cx - (int)round($r * 0.6)));
    $circleY = max(0, min($h - 1, $cy));
    $triX = max(0, min($w - 1, $cx + (int)round($r * 0.2)));
    $triY = max(0, min($h - 1, $cy));

    $circleRgb = rgbAt($img, $circleX, $circleY);
    $triRgb = rgbAt($img, $triX, $triY);
    $circleDark = avg($circleRgb) < 140;
    $triBright = avg($triRgb) > 190;
    return [
        "ok" => $circleDark && $triBright,
        "circle_rgb" => $circleRgb,
        "triangle_rgb" => $triRgb,
    ];
}

function rgbAt($img, int $x, int $y): array
{
    $idx = imagecolorat($img, $x, $y);
    return [
        ($idx >> 16) & 0xFF,
        ($idx >> 8) & 0xFF,
        $idx & 0xFF,
    ];
}

function avg(array $rgb): float
{
    return ($rgb[0] + $rgb[1] + $rgb[2]) / 3.0;
}

function resolveOriginalPath(string $path, string $relPath, string $photosRoot): ?string
{
    if ($path !== "" && is_file($path)) {
        return $path;
    }
    $fallback = safeJoin($photosRoot, $relPath);
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

function safeJoin(string $root, string $relPath): ?string
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

function thumbPath(string $thumbRoot, string $relPath): ?string
{
    $full = safeJoin($thumbRoot, $relPath);
    if ($full === null) {
        return null;
    }
    $info = pathinfo($full);
    return $info["dirname"] . DIRECTORY_SEPARATOR . $info["filename"] . ".jpg";
}

function ensureDir(string $dir): void
{
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException("Unable to create directory: " . $dir);
        }
    }
}

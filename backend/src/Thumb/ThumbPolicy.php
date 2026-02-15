<?php

declare(strict_types=1);

namespace WebAlbum\Thumb;

final class ThumbPolicy
{
    private const DEFAULT_IMAGE_MIN_BYTES = 1024;
    private const DEFAULT_VIDEO_MIN_BYTES = 2048;
    private const DEFAULT_PLACEHOLDER_MAX_BYTES = 12288;
    private const DEFAULT_PLACEHOLDER_TINY_BYTES = 2400;

    /** @var array<string, array<string, bool>> */
    private static array $legacyHashCache = [];

    public static function thumbPath(string $thumbRoot, string $relPath): ?string
    {
        $full = self::safeJoin($thumbRoot, $relPath);
        if ($full === null) {
            return null;
        }
        $info = pathinfo($full);
        $dirname = (string)($info["dirname"] ?? "");
        $filename = (string)($info["filename"] ?? "");
        if ($dirname === "" || $filename === "") {
            return null;
        }
        return $dirname . DIRECTORY_SEPARATOR . $filename . ".jpg";
    }

    public static function safeJoin(string $root, string $relPath): ?string
    {
        if ($root === "" || $relPath === "") {
            return null;
        }
        $rel = str_replace("\\", "/", $relPath);
        if ($rel === "" || $rel[0] === "/" || str_contains($rel, ":")) {
            return null;
        }
        foreach (explode("/", $rel) as $part) {
            if ($part === "..") {
                return null;
            }
        }
        return rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $rel;
    }

    public static function minBytes(string $type, array $config): int
    {
        $thumbCfg = $config["thumbs"] ?? [];
        $imageMin = (int)($thumbCfg["image_min_bytes"] ?? self::DEFAULT_IMAGE_MIN_BYTES);
        $videoMin = (int)($thumbCfg["video_min_bytes"] ?? self::DEFAULT_VIDEO_MIN_BYTES);
        if ($type === "video") {
            return max(256, $videoMin);
        }
        return max(256, $imageMin);
    }

    public static function isLikelyPlaceholderThumb(string $thumbPath, string $type, array $config): bool
    {
        if ($type !== "video" || !is_file($thumbPath)) {
            return false;
        }
        $size = (int)@filesize($thumbPath);
        if ($size <= 0) {
            return false;
        }

        $thumbCfg = $config["thumbs"] ?? [];
        $maxBytes = (int)($thumbCfg["placeholder_max_bytes"] ?? self::DEFAULT_PLACEHOLDER_MAX_BYTES);
        $tinyBytes = (int)($thumbCfg["placeholder_tiny_bytes"] ?? self::DEFAULT_PLACEHOLDER_TINY_BYTES);
        if ($size > max(1024, $maxBytes)) {
            return false;
        }
        if ($size <= max(512, $tinyBytes)) {
            return true;
        }

        $max = (int)($thumbCfg["max"] ?? 256);
        $quality = (int)($thumbCfg["quality"] ?? 75);
        $hash = @sha1_file($thumbPath);
        if (!is_string($hash) || $hash === "") {
            return false;
        }

        $legacy = self::legacyPlaceholderHashes($max, $quality);
        return isset($legacy[$hash]);
    }

    public static function validateGeneratedThumb(string $tmpPath, string $type, array $config): array
    {
        if (!is_file($tmpPath)) {
            return [false, "tmp_missing", null];
        }
        $size = (int)@filesize($tmpPath);
        if ($size <= 0) {
            return [false, "tmp_empty", null];
        }
        $min = self::minBytes($type, $config);
        if ($size < $min) {
            return [false, "tmp_too_small", ["size" => $size, "min" => $min]];
        }

        $dims = @getimagesize($tmpPath);
        if (!is_array($dims) || !isset($dims[0], $dims[1])) {
            return [false, "bad_image_header", null];
        }
        $w = (int)$dims[0];
        $h = (int)$dims[1];
        if ($w <= 0 || $h <= 0) {
            return [false, "bad_dimensions", ["width" => $w, "height" => $h]];
        }

        if ($type === "video" && self::isLikelyPlaceholderThumb($tmpPath, "video", $config)) {
            return [false, "placeholder_signature", null];
        }

        return [true, "ok", ["size" => $size, "width" => $w, "height" => $h]];
    }

    /**
     * @return array<string, bool>
     */
    private static function legacyPlaceholderHashes(int $max, int $quality): array
    {
        if (!function_exists("imagecreatetruecolor")) {
            return [];
        }
        $sizes = [64, 96, 120, 128, 160, 180, 200, 240, 256, 320, 400, max(64, $max)];
        $qualities = [60, 70, 75, 80, 85, 90, max(1, min(100, $quality))];
        $sizes = array_values(array_unique($sizes));
        $qualities = array_values(array_unique($qualities));
        sort($sizes);
        sort($qualities);

        $cacheKey = implode(",", $sizes) . "|" . implode(",", $qualities);
        if (isset(self::$legacyHashCache[$cacheKey])) {
            return self::$legacyHashCache[$cacheKey];
        }

        $hashes = [];
        foreach ($sizes as $size) {
            foreach ($qualities as $q) {
                $bytes = self::legacyPlaceholderBytes($size, $q);
                if ($bytes === null || $bytes === "") {
                    continue;
                }
                $hash = sha1($bytes);
                $hashes[$hash] = true;
            }
        }

        self::$legacyHashCache[$cacheKey] = $hashes;
        return $hashes;
    }

    private static function legacyPlaceholderBytes(int $size, int $quality): ?string
    {
        $size = max(64, $size);
        $quality = max(1, min(100, $quality));
        $img = @imagecreatetruecolor($size, $size);
        if ($img === false) {
            return null;
        }

        try {
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

            ob_start();
            imagejpeg($img, null, $quality);
            $raw = ob_get_clean();
            return is_string($raw) ? $raw : null;
        } finally {
            // No explicit resource cleanup needed on modern PHP/GD.
        }
    }
}

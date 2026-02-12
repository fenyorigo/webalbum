<?php

declare(strict_types=1);

namespace WebAlbum;

final class SystemTools
{
    private static ?array $memory = null;

    public static function checkExternalTools(array $config, bool $force = false): array
    {
        if (!$force && self::$memory !== null) {
            return self::$memory;
        }

        $cachePath = self::cachePath();
        if (!$force && is_file($cachePath)) {
            $raw = @file_get_contents($cachePath);
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded) && isset($decoded['tools'])) {
                    self::$memory = $decoded;
                    return $decoded;
                }
            }
        }

        $toolsCfg = $config['tools'] ?? [];
        $exifCfg = (string)($toolsCfg['exiftool'] ?? 'exiftool');
        $ffmpegCfg = (string)($toolsCfg['ffmpeg'] ?? 'ffmpeg');

        $exif = self::resolveBinary($exifCfg, ['exiftool']);
        $ffmpeg = self::resolveBinary($ffmpegCfg, ['ffmpeg']);

        $status = [
            'checked_at' => date('c'),
            'tools' => [
                'exiftool' => [
                    'available' => $exif !== null,
                    'path' => $exif,
                    'configured' => $exifCfg,
                ],
                'ffmpeg' => [
                    'available' => $ffmpeg !== null,
                    'path' => $ffmpeg,
                    'configured' => $ffmpegCfg,
                ],
            ],
        ];

        self::$memory = $status;
        self::writeCache($cachePath, $status);
        return $status;
    }

    public static function clearCache(): void
    {
        self::$memory = null;
        $cachePath = self::cachePath();
        if (is_file($cachePath)) {
            @unlink($cachePath);
        }
    }

    private static function writeCache(string $cachePath, array $status): void
    {
        $dir = dirname($cachePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents(
            $cachePath,
            json_encode($status, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    private static function cachePath(): string
    {
        return dirname(__DIR__) . '/var/external_tools_status.json';
    }

    private static function resolveBinary(string $configured, array $fallbackNames): ?string
    {
        $configured = trim($configured);
        if ($configured !== '') {
            // Absolute or relative path configured.
            if (str_contains($configured, '/') || str_contains($configured, '\\')) {
                return is_executable($configured) ? $configured : null;
            }
            $fromPath = self::findInPath($configured);
            if ($fromPath !== null) {
                return $fromPath;
            }
        }

        foreach ($fallbackNames as $name) {
            $fromPath = self::findInPath($name);
            if ($fromPath !== null) {
                return $fromPath;
            }
        }

        return null;
    }

    private static function findInPath(string $binary): ?string
    {
        if ($binary === '') {
            return null;
        }

        $path = getenv('PATH');
        if (!is_string($path) || $path === '') {
            return null;
        }

        $parts = explode(PATH_SEPARATOR, $path);
        foreach ($parts as $dir) {
            if ($dir === '') {
                continue;
            }
            $candidate = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $binary;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}

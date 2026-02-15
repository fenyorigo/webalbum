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

        $configured = self::configuredToolValues($config);
        $exifCfg = $configured['exiftool'];
        $ffmpegCfg = $configured['ffmpeg'];
        $ffprobeCfg = $configured['ffprobe'];

        $exif = self::resolveBinary($exifCfg, ['exiftool']);
        $ffmpeg = self::resolveBinary($ffmpegCfg, ['ffmpeg']);
        $ffprobe = self::resolveBinary($ffprobeCfg, ['ffprobe']);

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
                'ffprobe' => [
                    'available' => $ffprobe !== null,
                    'path' => $ffprobe,
                    'configured' => $ffprobeCfg,
                ],
            ],
            'overrides' => self::readOverrides(),
        ];

        self::$memory = $status;
        self::writeCache($cachePath, $status);
        return $status;
    }

    public static function setOverrides(array $overrides): array
    {
        $current = self::readOverrides();
        foreach (['exiftool', 'ffmpeg', 'ffprobe'] as $tool) {
            if (!array_key_exists($tool, $overrides)) {
                continue;
            }
            $value = trim((string)$overrides[$tool]);
            if ($value === '') {
                unset($current[$tool]);
                continue;
            }
            $current[$tool] = $value;
        }

        self::writeJson(self::overridePath(), $current);
        self::clearCache();
        return $current;
    }

    public static function getConfiguredToolValues(array $config): array
    {
        return self::configuredToolValues($config);
    }

    public static function clearCache(): void
    {
        self::$memory = null;
        $cachePath = self::cachePath();
        if (is_file($cachePath)) {
            @unlink($cachePath);
        }
    }

    private static function configuredToolValues(array $config): array
    {
        $toolsCfg = $config['tools'] ?? [];
        $values = [
            'exiftool' => trim((string)($toolsCfg['exiftool'] ?? 'exiftool')),
            'ffmpeg' => trim((string)($toolsCfg['ffmpeg'] ?? 'ffmpeg')),
            'ffprobe' => trim((string)($toolsCfg['ffprobe'] ?? 'ffprobe')),
        ];

        $overrides = self::readOverrides();
        foreach (['exiftool', 'ffmpeg', 'ffprobe'] as $tool) {
            if (!isset($overrides[$tool])) {
                continue;
            }
            $override = trim((string)$overrides[$tool]);
            if ($override === '') {
                continue;
            }
            $values[$tool] = $override;
        }

        if ($values['exiftool'] === '') {
            $values['exiftool'] = 'exiftool';
        }
        if ($values['ffmpeg'] === '') {
            $values['ffmpeg'] = 'ffmpeg';
        }
        if ($values['ffprobe'] === '') {
            $values['ffprobe'] = 'ffprobe';
        }

        return $values;
    }

    private static function readOverrides(): array
    {
        $path = self::overridePath();
        if (!is_file($path)) {
            return [];
        }

        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $clean = [];
        foreach (['exiftool', 'ffmpeg', 'ffprobe'] as $tool) {
            if (!isset($decoded[$tool])) {
                continue;
            }
            $value = trim((string)$decoded[$tool]);
            if ($value !== '') {
                $clean[$tool] = $value;
            }
        }

        return $clean;
    }

    private static function writeJson(string $path, array $payload): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        @file_put_contents(
            $path,
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    private static function writeCache(string $cachePath, array $status): void
    {
        self::writeJson($cachePath, $status);
    }

    private static function cachePath(): string
    {
        return dirname(__DIR__) . '/var/external_tools_status.json';
    }

    private static function overridePath(): string
    {
        return dirname(__DIR__) . '/var/external_tools_override.json';
    }

    private static function resolveBinary(string $configured, array $fallbackNames): ?string
    {
        $configured = trim($configured);
        if ($configured !== '') {
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

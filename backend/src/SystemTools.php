<?php

declare(strict_types=1);

namespace WebAlbum;

final class SystemTools
{
    private static ?array $memory = null;

    private const BINARY_TOOLS = ['exiftool', 'ffmpeg', 'ffprobe', 'soffice', 'gs', 'imagemagick', 'pecl'];

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

        $status = [
            'checked_at' => date('c'),
            'tools' => [],
            'overrides' => self::readOverrides(),
        ];

        foreach (self::BINARY_TOOLS as $tool) {
            $configuredValue = $configured[$tool] ?? $tool;
            $resolved = self::resolveBinary($configuredValue, self::fallbackNames($tool));
            $status['tools'][$tool] = [
                'available' => $resolved !== null,
                'path' => $resolved,
                'configured' => $configuredValue,
                'version' => $resolved !== null ? self::toolVersion($tool, $resolved) : null,
            ];
        }

        $imagickVersion = phpversion('imagick');
        $imagickAvailable = extension_loaded('imagick') && class_exists('Imagick');
        $status['tools']['imagick_ext'] = [
            'available' => $imagickAvailable,
            'path' => $imagickAvailable ? 'php extension' : null,
            'configured' => 'php extension',
            'version' => is_string($imagickVersion) && $imagickVersion !== '' ? $imagickVersion : null,
        ];

        self::$memory = $status;
        self::writeJson($cachePath, $status);
        return $status;
    }

    public static function setOverrides(array $overrides): array
    {
        $current = self::readOverrides();
        foreach (self::BINARY_TOOLS as $tool) {
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
            'soffice' => trim((string)($toolsCfg['soffice'] ?? 'soffice')),
            'gs' => trim((string)($toolsCfg['gs'] ?? 'gs')),
            'imagemagick' => trim((string)($toolsCfg['imagemagick'] ?? 'magick')),
            'pecl' => trim((string)($toolsCfg['pecl'] ?? 'pecl')),
        ];

        $overrides = self::readOverrides();
        foreach (self::BINARY_TOOLS as $tool) {
            if (!isset($overrides[$tool])) {
                continue;
            }
            $override = trim((string)$overrides[$tool]);
            if ($override !== '') {
                $values[$tool] = $override;
            }
        }

        foreach (self::BINARY_TOOLS as $tool) {
            if (($values[$tool] ?? '') === '') {
                $values[$tool] = $tool;
            }
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
        foreach (self::BINARY_TOOLS as $tool) {
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
                return is_file($configured) && is_executable($configured) ? $configured : null;
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

    private static function fallbackNames(string $tool): array
    {
        return match ($tool) {
            'imagemagick' => ['magick', 'convert'],
            default => [$tool],
        };
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

        foreach (explode(PATH_SEPARATOR, $path) as $dir) {
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

    private static function toolVersion(string $tool, string $path): ?string
    {
        $args = match ($tool) {
            'exiftool' => [$path, '-ver'],
            'ffmpeg', 'ffprobe' => [$path, '-version'],
            'soffice' => [$path, '--version'],
            'pecl' => [$path, 'version'],
            default => [$path, '--version'],
        };
        $output = self::runCommand($args);
        if ($output === null) {
            return null;
        }
        $line = trim((string)preg_split('/\R/', $output)[0]);
        return $line !== '' ? $line : null;
    }

    private static function runCommand(array $args): ?string
    {
        $cmd = implode(' ', array_map('escapeshellarg', $args));
        $out = @shell_exec($cmd . ' 2>&1');
        if (!is_string($out)) {
            return null;
        }
        return trim($out);
    }

    private static function writeJson(string $path, array $payload): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        @file_put_contents($path, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}

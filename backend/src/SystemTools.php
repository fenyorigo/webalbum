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

        $stamp = self::contextStamp();
        $cachePath = self::cachePath();
        if (!$force && is_file($cachePath)) {
            $raw = @file_get_contents($cachePath);
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded) && isset($decoded['tools']) && self::isStampMatch($decoded['stamp'] ?? null, $stamp)) {
                    self::$memory = $decoded;
                    return $decoded;
                }
                // Ignore stale cache from a different machine/environment.
                @unlink($cachePath);
            }
        }

        $configured = self::configuredToolValues($config);

        $status = [
            'checked_at' => date('c'),
            'stamp' => $stamp,
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

        $heic = self::imagemagickHeicSupport($status['tools']['imagemagick']['path'] ?? null);
        $status['tools']['imagemagick_heic'] = [
            'available' => $heic['available'],
            'path' => $status['tools']['imagemagick']['path'] ?? null,
            'configured' => 'ImageMagick HEIC delegate',
            'version' => $heic['version'],
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
        self::writeJson(self::overridePath(), [
            'stamp' => self::contextStamp(),
            'overrides' => $current,
        ]);
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

        $stamp = self::contextStamp();
        $source = $decoded;
        if (array_key_exists('overrides', $decoded)) {
            if (!self::isStampMatch($decoded['stamp'] ?? null, $stamp)) {
                // Ignore stale overrides copied from a different machine/environment.
                @unlink($path);
                return [];
            }
            $source = is_array($decoded['overrides']) ? $decoded['overrides'] : [];
        }

        $clean = [];
        foreach (self::BINARY_TOOLS as $tool) {
            if (!isset($source[$tool])) {
                continue;
            }
            $value = trim((string)$source[$tool]);
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
        self::ensureSofficeRuntimeDirs();
        $cmd = implode(' ', array_map('escapeshellarg', $args));
        $runtime = self::sofficeRuntimeEnvPrefix();
        if ($runtime !== '') {
            $cmd = $runtime . ' ' . $cmd;
        }
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

    private static function contextStamp(): array
    {
        $host = gethostname();
        if (!is_string($host) || $host === '') {
            $host = php_uname('n');
        }
        return [
            'host' => (string)$host,
            'os' => PHP_OS_FAMILY,
            'sapi' => PHP_SAPI,
            'code_root' => dirname(__DIR__),
        ];
    }

    private static function isStampMatch(mixed $value, array $expected): bool
    {
        if (!is_array($value)) {
            return false;
        }
        foreach ($expected as $key => $expectedValue) {
            $actual = $value[$key] ?? null;
            if (!is_string($actual) || $actual !== $expectedValue) {
                return false;
            }
        }
        return true;
    }

    private static function imagemagickHeicSupport(mixed $magickPath): array
    {
        $path = is_string($magickPath) ? trim($magickPath) : '';
        if ($path === '') {
            return ['available' => false, 'version' => null];
        }
        $out = self::runCommand([$path, '-list', 'format']);
        if (!is_string($out) || $out === '') {
            return ['available' => false, 'version' => null];
        }
        $line = null;
        foreach (preg_split('/\R/', $out) as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }
            $trimmed = trim($candidate);
            if ($trimmed === '') {
                continue;
            }
            if (preg_match('/\bHEIC\b/i', $trimmed)) {
                $line = $trimmed;
                break;
            }
        }
        return ['available' => $line !== null, 'version' => $line];
    }

    private static function sofficeRuntimeEnvPrefix(): string
    {
        $base = dirname(__DIR__) . '/var/worker-home';
        $cache = $base . '/.cache';
        $config = $base . '/.config';
        $tmp = dirname(__DIR__) . '/var/tmp';
        return 'HOME=' . escapeshellarg($base)
            . ' XDG_CACHE_HOME=' . escapeshellarg($cache)
            . ' XDG_CONFIG_HOME=' . escapeshellarg($config)
            . ' TMPDIR=' . escapeshellarg($tmp);
    }

    private static function ensureSofficeRuntimeDirs(): void
    {
        $paths = [
            dirname(__DIR__) . '/var/worker-home',
            dirname(__DIR__) . '/var/worker-home/.cache',
            dirname(__DIR__) . '/var/worker-home/.config',
            dirname(__DIR__) . '/var/tmp',
        ];
        foreach ($paths as $path) {
            if (!is_dir($path)) {
                @mkdir($path, 0775, true);
            }
        }
    }
}

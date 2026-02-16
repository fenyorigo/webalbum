<?php

declare(strict_types=1);

namespace WebAlbum\Assets;

final class AssetSupport
{
    /** @var array<string, array{type:string,mime:string}> */
    private const EXT_MAP = [
        'pdf' => ['type' => 'doc', 'mime' => 'application/pdf'],
        'txt' => ['type' => 'doc', 'mime' => 'text/plain'],
        'doc' => ['type' => 'doc', 'mime' => 'application/msword'],
        'docx' => ['type' => 'doc', 'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'xls' => ['type' => 'doc', 'mime' => 'application/vnd.ms-excel'],
        'xlsx' => ['type' => 'doc', 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'ppt' => ['type' => 'doc', 'mime' => 'application/vnd.ms-powerpoint'],
        'pptx' => ['type' => 'doc', 'mime' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
        'mp3' => ['type' => 'audio', 'mime' => 'audio/mpeg'],
        'm4a' => ['type' => 'audio', 'mime' => 'audio/mp4'],
        'flac' => ['type' => 'audio', 'mime' => 'audio/flac'],
    ];

    public static function extFromPath(string $path): string
    {
        return strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
    }

    public static function isSupportedExtension(string $ext): bool
    {
        return isset(self::EXT_MAP[strtolower($ext)]);
    }

    public static function typeFromExtension(string $ext): ?string
    {
        $ext = strtolower($ext);
        return self::EXT_MAP[$ext]['type'] ?? null;
    }

    public static function mimeFromExtension(string $ext): string
    {
        $ext = strtolower($ext);
        return self::EXT_MAP[$ext]['mime'] ?? 'application/octet-stream';
    }

    public static function isOfficeExtension(string $ext): bool
    {
        $ext = strtolower($ext);
        return in_array($ext, ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'], true);
    }

    public static function isConvertibleToPdf(string $ext): bool
    {
        $ext = strtolower($ext);
        return in_array($ext, ['txt', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'], true);
    }

    public static function isPdfExtension(string $ext): bool
    {
        return strtolower($ext) === 'pdf';
    }

    /** @return string[] */
    public static function supportedExtensions(): array
    {
        return array_keys(self::EXT_MAP);
    }
}

<?php

declare(strict_types=1);

namespace WebAlbum\Assets;

use WebAlbum\Security\PathGuard;

final class AssetPaths
{
    public static function normalizeRelPath(string $relPath): ?string
    {
        $relPath = trim(str_replace('\\', '/', $relPath), '/');
        if ($relPath === '') {
            return null;
        }
        foreach (explode('/', $relPath) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                return null;
            }
        }
        if (str_contains($relPath, ':')) {
            return null;
        }
        return $relPath;
    }

    public static function joinInside(string $root, string $relPath): ?string
    {
        $rel = self::normalizeRelPath($relPath);
        if ($rel === null) {
            return null;
        }

        $realRoot = realpath($root);
        if ($realRoot === false) {
            return null;
        }
        $rootWithSlash = rtrim($realRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        $joined = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);

        if (file_exists($joined)) {
            return PathGuard::assertInsideRoot($joined, $root);
        }

        $probe = dirname($joined);
        while (!is_dir($probe)) {
            $parent = dirname($probe);
            if ($parent === $probe) {
                return null;
            }
            $probe = $parent;
        }

        $realProbe = realpath($probe);
        if ($realProbe === false) {
            return null;
        }
        if ($realProbe !== $realRoot && strpos($realProbe . DIRECTORY_SEPARATOR, $rootWithSlash) !== 0) {
            return null;
        }

        return $joined;
    }

    public static function derivativePath(string $thumbRoot, string $relPath, string $suffix): ?string
    {
        $rel = self::normalizeRelPath($relPath);
        if ($rel === null) {
            return null;
        }
        $ext = strtolower((string)pathinfo($rel, PATHINFO_EXTENSION));
        $base = $ext === '' ? $rel : substr($rel, 0, -strlen($ext) - 1);
        $targetRel = $base . $suffix;
        return self::joinInside($thumbRoot, $targetRel);
    }
}

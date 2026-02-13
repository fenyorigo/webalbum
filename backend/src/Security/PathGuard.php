<?php

declare(strict_types=1);

namespace WebAlbum\Security;

final class PathGuard
{
    public static function assertInsideRoot(string $path, string $root): string
    {
        $realRoot = realpath($root);
        $realPath = realpath($path);

        if ($realRoot === false || $realPath === false) {
            throw new \RuntimeException('File not found');
        }

        $rootWithSlash = rtrim($realRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if ($realPath !== $realRoot && strpos($realPath, $rootWithSlash) !== 0) {
            throw new \RuntimeException('File outside configured photos root');
        }

        return $realPath;
    }
}

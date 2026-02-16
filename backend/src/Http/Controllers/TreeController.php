<?php

declare(strict_types=1);

namespace WebAlbum\Http\Controllers;

use WebAlbum\Db\Maria;
use WebAlbum\Db\SqliteIndex;
use WebAlbum\UserContext;

final class TreeController
{
    private string $configPath;

    public function __construct(string $configPath)
    {
        $this->configPath = $configPath;
    }

    public function roots(): void
    {
        try {
            [$sqlite, $maria] = $this->connections();
            if (!$this->ensureAuth($maria)) {
                return;
            }

            [$folders, $idsByRel] = $this->folderUniverse($sqlite, $maria);
            $roots = [];
            foreach ($folders as $relRaw) {
                $rel = (string)$relRaw;
                if (strpos($rel, '/') === false) {
                    $roots[] = $this->mapNodeFromRel($rel, null, $folders, $idsByRel);
                }
            }
            usort($roots, fn (array $a, array $b): int => strcasecmp((string)$a['rel_path'], (string)$b['rel_path']));
            $this->json($roots);
        } catch (\Throwable $e) {
            $this->json(["error" => $e->getMessage()], 400);
        }
    }

    public function children(): void
    {
        try {
            [$sqlite, $maria] = $this->connections();
            if (!$this->ensureAuth($maria)) {
                return;
            }

            [$folders, $idsByRel, $relById] = $this->folderUniverse($sqlite, $maria, true);
            $parentRel = trim(str_replace('\\', '/', (string)($_GET['parent_rel_path'] ?? '')), '/');
            if ($parentRel === '') {
                $parentId = isset($_GET['parent_id']) ? (int)$_GET['parent_id'] : 0;
                if ($parentId > 0 && isset($relById[$parentId])) {
                    $parentRel = $relById[$parentId];
                }
            }
            if ($parentRel === '') {
                $this->json(["error" => "parent_rel_path (or a known parent_id) is required"], 400);
                return;
            }

            $children = $this->childRelPaths($parentRel, $folders);
            $rows = [];
            foreach ($children as $relRaw) {
                $rel = (string)$relRaw;
                $rows[] = $this->mapNodeFromRel($rel, $parentRel, $folders, $idsByRel);
            }
            usort($rows, fn (array $a, array $b): int => strcasecmp((string)$a['rel_path'], (string)$b['rel_path']));
            $this->json($rows);
        } catch (\Throwable $e) {
            $this->json(["error" => $e->getMessage()], 400);
        }
    }

    private function mapNodeFromRel(string $relPath, ?string $parentRel, array $folders, array $idsByRel): array
    {
        $id = $idsByRel[$relPath] ?? null;
        $parentId = null;
        if ($parentRel !== null && $parentRel !== '') {
            $parentId = $idsByRel[$parentRel] ?? null;
        }

        return [
            'id' => $id !== null ? (int)$id : null,
            'key' => $relPath,
            'parent_id' => $parentId !== null ? (int)$parentId : null,
            'name' => $this->nameFromRelPath($relPath),
            'rel_path' => $relPath,
            'depth' => $this->depthFromRelPath($relPath),
            'has_children' => $this->hasChildren($relPath, $folders),
        ];
    }

    private function nameFromRelPath(string $relPath): string
    {
        $trimmed = trim(str_replace('\\', '/', $relPath), '/');
        if ($trimmed === '') {
            return '/';
        }
        $parts = explode('/', $trimmed);
        return (string)end($parts);
    }

    private function connections(): array
    {
        $config = require $this->configPath;
        $sqlite = new SqliteIndex($config['sqlite']['path']);
        $maria = new Maria(
            $config['mariadb']['dsn'],
            $config['mariadb']['user'],
            $config['mariadb']['pass']
        );
        return [$sqlite, $maria];
    }

    private function ensureAuth(Maria $maria): bool
    {
        $user = UserContext::currentUser($maria);
        if ($user === null) {
            $this->json(['error' => 'Not authenticated'], 401);
            return false;
        }
        return true;
    }

    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload);
    }

    private function folderUniverse(SqliteIndex $sqlite, Maria $maria, bool $withReverse = false): array
    {
        $folderSet = [];
        $idsByRel = [];
        $relById = [];

        $dirRows = $sqlite->query('SELECT id, rel_path FROM directories');
        foreach ($dirRows as $row) {
            $rel = trim(str_replace('\\', '/', (string)($row['rel_path'] ?? '')), '/');
            if ($rel === '') {
                continue;
            }
            $folderSet[$rel] = true;
            $id = (int)($row['id'] ?? 0);
            if ($id > 0) {
                $idsByRel[$rel] = $id;
                $relById[$id] = $rel;
            }
        }

        $assetRows = $maria->query('SELECT rel_path FROM wa_assets');
        foreach ($assetRows as $row) {
            $relPath = trim(str_replace('\\', '/', (string)($row['rel_path'] ?? '')), '/');
            if ($relPath === '') {
                continue;
            }
            $parts = explode('/', $relPath);
            array_pop($parts);
            $prefix = '';
            foreach ($parts as $part) {
                if ($part === '') {
                    continue;
                }
                $prefix = $prefix === '' ? $part : ($prefix . '/' . $part);
                $folderSet[$prefix] = true;
            }
        }

        if ($withReverse) {
            return [array_keys($folderSet), $idsByRel, $relById];
        }
        return [array_keys($folderSet), $idsByRel];
    }

    private function childRelPaths(string $parentRel, array $folders): array
    {
        $prefix = rtrim($parentRel, '/') . '/';
        $children = [];
        foreach ($folders as $relRaw) {
            $rel = (string)$relRaw;
            if (!str_starts_with($rel, $prefix)) {
                continue;
            }
            $rest = substr($rel, strlen($prefix));
            if ($rest === '' || strpos($rest, '/') !== false) {
                continue;
            }
            $children[$rel] = true;
        }
        return array_keys($children);
    }

    private function hasChildren(string $relPath, array $folders): bool
    {
        $prefix = rtrim($relPath, '/') . '/';
        foreach ($folders as $relRaw) {
            $rel = (string)$relRaw;
            if (str_starts_with($rel, $prefix)) {
                return true;
            }
        }
        return false;
    }

    private function depthFromRelPath(string $relPath): int
    {
        $trimmed = trim(str_replace('\\', '/', $relPath), '/');
        if ($trimmed === '') {
            return 0;
        }
        return count(explode('/', $trimmed));
    }
}

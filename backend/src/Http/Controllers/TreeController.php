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

            $rows = $sqlite->query(
                "SELECT d.id, d.parent_id, d.rel_path, d.depth, " .
                "EXISTS(SELECT 1 FROM directories c WHERE c.parent_id = d.id LIMIT 1) AS has_children " .
                "FROM directories d " .
                "WHERE d.depth = 1 " .
                "ORDER BY d.rel_path ASC"
            );

            $this->json(array_map(fn (array $row): array => $this->mapNode($row), $rows));
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

            $parentId = isset($_GET['parent_id']) ? (int)$_GET['parent_id'] : 0;
            if ($parentId < 1) {
                $this->json(["error" => "parent_id must be a positive integer"], 400);
                return;
            }

            $rows = $sqlite->query(
                "SELECT d.id, d.parent_id, d.rel_path, d.depth, " .
                "EXISTS(SELECT 1 FROM directories c WHERE c.parent_id = d.id LIMIT 1) AS has_children " .
                "FROM directories d " .
                "WHERE d.parent_id = ? " .
                "ORDER BY d.rel_path ASC",
                [$parentId]
            );

            $this->json(array_map(fn (array $row): array => $this->mapNode($row), $rows));
        } catch (\Throwable $e) {
            $this->json(["error" => $e->getMessage()], 400);
        }
    }

    private function mapNode(array $row): array
    {
        $relPath = (string)($row['rel_path'] ?? '');
        return [
            'id' => (int)$row['id'],
            'parent_id' => $row['parent_id'] !== null ? (int)$row['parent_id'] : null,
            'name' => $this->nameFromRelPath($relPath),
            'rel_path' => $relPath,
            'depth' => (int)($row['depth'] ?? 0),
            'has_children' => ((int)($row['has_children'] ?? 0)) === 1,
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
}

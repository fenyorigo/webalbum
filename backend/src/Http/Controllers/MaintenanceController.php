<?php

declare(strict_types=1);

namespace WebAlbum\Http\Controllers;

use WebAlbum\AuditLogMetaCache;
use WebAlbum\Db\Maria;
use WebAlbum\UserContext;

final class MaintenanceController
{
    private string $configPath;

    public function __construct(string $configPath)
    {
        $this->configPath = $configPath;
    }

    public function cleanStructure(): void
    {
        try {
            [$config, $maria, $user] = $this->authAdmin();
            if ($user === null) {
                return;
            }

            $photosRoot = $this->validateRoot((string)($config['photos']['root'] ?? ''));
            $thumbsRoot = $this->validateRoot((string)($config['thumbs']['root'] ?? ''));
            $trashRoot = $this->validateRoot((string)($config['trash']['root'] ?? ''));
            $trashThumbsRoot = $this->validateRoot((string)($config['trash']['thumbs_root'] ?? ''));

            $report = [
                'photos' => $this->cleanRootWithTrashBlocker($photosRoot, $trashRoot),
                'thumbs' => $this->cleanRootWithTrashBlocker($thumbsRoot, $trashRoot),
                'trash' => $this->cleanRootSimple($trashRoot),
                'trash_thumbs' => $this->cleanRootSimple($trashThumbsRoot),
            ];

            $this->logAudit($maria, (int)$user['id'], 'maintenance_clean_structure', $report);
            $this->json(['ok' => true, 'report' => $report]);
        } catch (\Throwable $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    private function authAdmin(): array
    {
        $config = require $this->configPath;
        $maria = new Maria(
            $config['mariadb']['dsn'],
            $config['mariadb']['user'],
            $config['mariadb']['pass']
        );
        $user = UserContext::currentUser($maria);
        if ($user === null) {
            $this->json(['error' => 'Not authenticated'], 401);
            return [$config, $maria, null];
        }
        if ((int)($user['is_admin'] ?? 0) !== 1) {
            $this->json(['error' => 'Forbidden'], 403);
            return [$config, $maria, null];
        }
        return [$config, $maria, $user];
    }

    private function validateRoot(string $root): string
    {
        $root = trim($root);
        if ($root === '' || !str_starts_with($root, '/')) {
            throw new \RuntimeException('Invalid root path');
        }
        if (!is_dir($root)) {
            throw new \RuntimeException('Root does not exist: ' . $root);
        }
        $real = realpath($root);
        if ($real === false || !str_starts_with($real, '/')) {
            throw new \RuntimeException('Invalid real root path');
        }
        return rtrim($real, DIRECTORY_SEPARATOR);
    }

    private function cleanRootSimple(string $root): array
    {
        $dirs = $this->collectDirsBottomUp($root);
        $deleted = 0;
        $deletedExamples = [];

        foreach ($dirs as $dir) {
            if ($dir === $root || is_link($dir)) {
                continue;
            }
            if ($this->isDirEmpty($dir) && @rmdir($dir)) {
                $deleted++;
                if (count($deletedExamples) < 50) {
                    $deletedExamples[] = $this->relativePath($root, $dir);
                }
            }
        }

        return [
            'root' => $root,
            'deleted' => $deleted,
            'deleted_examples' => $deletedExamples,
            'skipped_due_to_trash_blocker' => 0,
            'blocker_examples' => [],
        ];
    }

    private function cleanRootWithTrashBlocker(string $root, string $trashRoot): array
    {
        $dirs = $this->collectDirsBottomUp($root);
        $deleted = 0;
        $deletedExamples = [];
        $skippedBlocker = 0;
        $blockerExamples = [];

        foreach ($dirs as $dir) {
            if ($dir === $root || is_link($dir)) {
                continue;
            }
            if (!$this->isDirEmpty($dir)) {
                continue;
            }

            $rel = $this->relativePath($root, $dir);
            $trashDir = $trashRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if (is_dir($trashDir) && !$this->isDirEmpty($trashDir)) {
                $skippedBlocker++;
                if (count($blockerExamples) < 50) {
                    $blockerExamples[] = $rel;
                }
                continue;
            }

            if (@rmdir($dir)) {
                $deleted++;
                if (count($deletedExamples) < 50) {
                    $deletedExamples[] = $rel;
                }
            }
        }

        return [
            'root' => $root,
            'deleted' => $deleted,
            'deleted_examples' => $deletedExamples,
            'skipped_due_to_trash_blocker' => $skippedBlocker,
            'blocker_examples' => $blockerExamples,
        ];
    }

    private function collectDirsBottomUp(string $root): array
    {
        $dirs = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isLink()) {
                continue;
            }
            if ($file->isDir()) {
                $path = $file->getPathname();
                if (is_string($path) && $path !== '') {
                    $dirs[] = $path;
                }
            }
        }

        return $dirs;
    }

    private function isDirEmpty(string $dir): bool
    {
        $handle = @opendir($dir);
        if ($handle === false) {
            return false;
        }
        try {
            while (($entry = readdir($handle)) !== false) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                return false;
            }
            return true;
        } finally {
            closedir($handle);
        }
    }

    private function relativePath(string $root, string $path): string
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (str_starts_with($path, $root)) {
            return str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root)));
        }
        return str_replace(DIRECTORY_SEPARATOR, '/', $path);
    }

    private function logAudit(Maria $db, int $actorId, string $action, array $details): void
    {
        try {
            $source = 'web';
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            $db->exec(
                "INSERT INTO wa_audit_log (actor_user_id, target_user_id, action, source, ip_address, user_agent, details)\n" .
                "VALUES (?, ?, ?, ?, ?, ?, ?)",
                [
                    $actorId,
                    null,
                    $action,
                    $source,
                    $ip,
                    $agent,
                    json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]
            );
            AuditLogMetaCache::invalidateIfMissing($action, $source);
        } catch (\Throwable $e) {
            // must not block maintenance
        }
    }

    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload);
    }
}

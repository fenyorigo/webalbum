<?php

declare(strict_types=1);

namespace WebAlbum\Http\Controllers;

use WebAlbum\AuditLogMetaCache;
use WebAlbum\Db\Maria;
use WebAlbum\Db\SqliteIndex;
use WebAlbum\Thumb\ThumbPolicy;
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

    public function purgePlaceholderThumbs(): void
    {
        try {
            [$config, $maria, $user] = $this->authAdmin();
            if ($user === null) {
                return;
            }

            $raw = file_get_contents('php://input') ?: '{}';
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                $data = [];
            }
            $dryRun = (bool)($data['dry_run'] ?? false);
            $limit = max(1, min(500000, (int)($data['limit'] ?? 200000)));

            $thumbsRoot = $this->validateRoot((string)($config['thumbs']['root'] ?? ''));
            $sqlitePath = (string)($config['sqlite']['path'] ?? '');
            if ($sqlitePath === '') {
                throw new \RuntimeException('SQLite path not configured');
            }

            $db = new SqliteIndex($sqlitePath);
            $rows = $db->query(
                "SELECT rel_path FROM files WHERE type = 'video' AND rel_path IS NOT NULL AND rel_path <> '' ORDER BY id ASC LIMIT ?",
                [$limit]
            );

            $scanned = 0;
            $deleted = 0;
            $deletedBytes = 0;
            $examples = [];

            foreach ($rows as $row) {
                $relPath = trim((string)($row['rel_path'] ?? ''));
                if ($relPath === '') {
                    continue;
                }
                $thumbPath = ThumbPolicy::thumbPath($thumbsRoot, $relPath);
                if ($thumbPath === null || !is_file($thumbPath)) {
                    continue;
                }
                $scanned++;
                if (!ThumbPolicy::isLikelyPlaceholderThumb($thumbPath, 'video', $config)) {
                    continue;
                }

                $size = (int)@filesize($thumbPath);
                if (!$dryRun && @unlink($thumbPath)) {
                    $deleted++;
                    $deletedBytes += max(0, $size);
                    if (count($examples) < 50) {
                        $examples[] = str_replace(DIRECTORY_SEPARATOR, '/', $relPath);
                    }
                } elseif ($dryRun) {
                    $deleted++;
                    $deletedBytes += max(0, $size);
                    if (count($examples) < 50) {
                        $examples[] = str_replace(DIRECTORY_SEPARATOR, '/', $relPath);
                    }
                }
            }

            $report = [
                'dry_run' => $dryRun,
                'limit' => $limit,
                'scanned_existing_video_thumbs' => $scanned,
                'placeholder_matches' => $deleted,
                'bytes' => $deletedBytes,
                'examples' => $examples,
            ];

            $this->logAudit($maria, (int)$user['id'], 'maintenance_purge_placeholder_thumbs', $report);
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

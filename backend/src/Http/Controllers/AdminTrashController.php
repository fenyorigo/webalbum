<?php

declare(strict_types=1);

namespace WebAlbum\Http\Controllers;

use WebAlbum\AuditLogMetaCache;
use WebAlbum\Db\Maria;
use WebAlbum\Db\SqliteIndex;
use WebAlbum\UserContext;

final class AdminTrashController
{
    private const MAX_BATCH = 200;

    private string $configPath;

    public function __construct(string $configPath)
    {
        $this->configPath = $configPath;
    }

    public function move(): void
    {
        try {
            [$config, $maria, $user] = $this->authAdmin();
            if ($user === null) {
                return;
            }

            $body = file_get_contents('php://input');
            $data = json_decode($body ?: '', true, 512, JSON_THROW_ON_ERROR);
            $idRaw = $data['id'] ?? null;
            $type = strtolower(trim((string)($data['type'] ?? '')));
            if ((!is_int($idRaw) && !ctype_digit((string)$idRaw)) || !in_array($type, ['image', 'video'], true)) {
                $this->json(['error' => 'id (int) and type (image|video) are required'], 400);
                return;
            }
            $id = (int)$idRaw;
            if ($id < 1) {
                $this->json(['error' => 'Invalid id'], 400);
                return;
            }

            $sqlite = new SqliteIndex((string)$config['sqlite']['path']);
            $rows = $sqlite->query(
                "SELECT id, path, rel_path, type, taken_ts FROM files WHERE id = ?",
                [$id]
            );
            if ($rows === []) {
                $this->json(['error' => 'Not Found'], 404);
                return;
            }
            $file = $rows[0];
            $dbType = strtolower((string)($file['type'] ?? ''));
            if ($dbType !== $type) {
                $this->json(['error' => 'Type mismatch'], 400);
                return;
            }

            $relPath = $this->normalizeRelPath((string)($file['rel_path'] ?? ''));
            if ($relPath === null) {
                $this->json(['error' => 'Invalid rel_path'], 400);
                return;
            }

            $paths = $this->pathsForRelPath($config, $relPath);
            if ($paths === null) {
                $this->json(['error' => 'Invalid path config'], 500);
                return;
            }

            $photosRoot = (string)($config['photos']['root'] ?? '');
            $src = $this->resolveSourcePath((string)($file['path'] ?? ''), $relPath, $photosRoot);
            if ($src === null || !is_file($src)) {
                $this->json(['error' => 'Source file not found'], 404);
                return;
            }
            $dst = $paths['trash'];

            $existing = $maria->query(
                "SELECT id FROM wa_media_trash WHERE rel_path = ? AND status = 'trashed' LIMIT 1",
                [$relPath]
            );
            if ($existing !== []) {
                $this->json(['error' => 'Already trashed'], 409);
                return;
            }

            $this->ensureDir(dirname($dst));
            if (file_exists($dst)) {
                $this->json(['error' => 'Destination already exists in trash'], 409);
                return;
            }

            if (!$this->moveFile($src, $dst)) {
                $this->json(['error' => 'Failed to move file to trash'], 500);
                return;
            }

            $thumbSrcPath = $paths['thumbSrc'];
            $thumbTrashPath = $paths['thumbTrash'];
            if ($thumbSrcPath !== null && $thumbTrashPath !== null && is_file($thumbSrcPath)) {
                try {
                    $this->ensureDir(dirname($thumbTrashPath));
                    $this->moveFile($thumbSrcPath, $thumbTrashPath);
                } catch (\Throwable $e) {
                    error_log('webalbum trash thumb move failed: ' . $e->getMessage());
                }
            }

            $maria->exec(
                "DELETE FROM wa_media_trash WHERE rel_path = ? AND status IN ('restored', 'purged')",
                [$relPath]
            );

            $maria->exec(
                "INSERT INTO wa_media_trash (rel_path, type, src_path, trash_path, thumb_src_path, thumb_trash_path, taken_ts, deleted_by_user_id, status)\n" .
                "VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'trashed')",
                [
                    $relPath,
                    $dbType,
                    $src,
                    $dst,
                    $thumbSrcPath,
                    $thumbTrashPath,
                    isset($file['taken_ts']) ? (int)$file['taken_ts'] : null,
                    (int)$user['id'],
                ]
            );

            $this->logAudit(
                $maria,
                (int)$user['id'],
                'trash_move',
                [
                    'sqlite_file_id' => $id,
                    'rel_path' => $relPath,
                    'type' => $dbType,
                ]
            );

            $this->json([
                'ok' => true,
                'id' => $id,
                'rel_path' => $relPath,
                'status' => 'trashed',
            ]);
        } catch (\JsonException $e) {
            $this->json(['error' => 'Invalid JSON'], 400);
        } catch (\Throwable $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    public function list(): void
    {
        try {
            [$config, $maria, $user] = $this->authAdmin();
            if ($user === null) {
                return;
            }

            $status = strtolower(trim((string)($_GET['status'] ?? 'trashed')));
            if (!in_array($status, ['trashed', 'restored', 'purged'], true)) {
                $status = 'trashed';
            }

            $page = max(1, (int)($_GET['page'] ?? 1));
            $pageSize = (int)($_GET['page_size'] ?? 50);
            if (!in_array($pageSize, [25, 50, 100], true)) {
                $pageSize = 50;
            }
            $offset = ($page - 1) * $pageSize;

            $q = trim((string)($_GET['q'] ?? ''));
            $sort = strtolower(trim((string)($_GET['sort'] ?? 'deleted_at_desc')));
            $orderBy = $sort === 'deleted_at_asc' ? 't.deleted_at ASC, t.id ASC' : 't.deleted_at DESC, t.id DESC';

            $where = ['t.status = ?'];
            $params = [$status];
            if ($q !== '') {
                $where[] = "t.rel_path LIKE ? ESCAPE '\\\\'";
                $params[] = '%' . self::escapeLike($q) . '%';
            }
            $whereSql = implode(' AND ', $where);

            $totalRows = $maria->query(
                "SELECT COUNT(*) AS c FROM wa_media_trash t WHERE " . $whereSql,
                $params
            );
            $total = (int)($totalRows[0]['c'] ?? 0);

            $rows = $maria->query(
                "SELECT t.id, t.rel_path, t.type, t.src_path, t.trash_path, t.thumb_src_path, t.thumb_trash_path,\n" .
                "       t.taken_ts, t.deleted_at, t.deleted_at AS trashed_at, t.restored_at, t.purged_at, t.status, t.reason,\n" .
                "       t.deleted_by_user_id, t.restored_by_user_id, t.purged_by_user_id,\n" .
                "       du.username AS deleted_by_username, du.display_name AS deleted_by_display_name\n" .
                "FROM wa_media_trash t\n" .
                "LEFT JOIN wa_users du ON du.id = t.deleted_by_user_id\n" .
                "WHERE " . $whereSql . "\n" .
                "ORDER BY " . $orderBy . "\n" .
                "LIMIT " . (int)$pageSize . " OFFSET " . (int)$offset,
                $params
            );

            $items = [];
            foreach ($rows as $row) {
                $items[] = [
                    'trash_id' => (int)$row['id'],
                    'rel_path' => (string)$row['rel_path'],
                    'type' => (string)$row['type'],
                    'deleted_at' => (string)($row['deleted_at'] ?? ''),
                    'deleted_by' => (string)($row['deleted_by_display_name'] ?: $row['deleted_by_username'] ?: ''),
                    'thumb_url' => '/api/admin/trash/thumb?id=' . (int)$row['id'],
                ];
            }

            $payload = [
                'items' => $items,
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
                'total_pages' => max(1, (int)ceil($total / $pageSize)),
                'sort' => $sort,
            ];

            // backward compatible
            $payload['rows'] = $rows;

            $this->json($payload);
        } catch (\Throwable $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    public function thumb(int $id): void
    {
        try {
            [$config, $maria, $user] = $this->authAdmin();
            if ($user === null) {
                return;
            }
            if ($id < 1) {
                $this->json(['error' => 'Invalid id'], 400);
                return;
            }

            $row = $this->fetchTrashRow($maria, $id);
            if ($row === null || (string)($row['status'] ?? '') !== 'trashed') {
                $this->json(['error' => 'Not Found'], 404);
                return;
            }

            $relPath = $this->normalizeRelPath((string)($row['rel_path'] ?? ''));
            if ($relPath === null) {
                $this->json(['error' => 'Invalid rel_path'], 400);
                return;
            }

            $thumbPath = trim((string)($row['thumb_trash_path'] ?? ''));
            if ($thumbPath === '' || !is_file($thumbPath)) {
                $paths = $this->pathsForRelPath($config, $relPath);
                $thumbPath = $paths['thumbTrash'] ?? '';
            }
            if ($thumbPath === '' || !is_file($thumbPath)) {
                $this->json(['error' => 'Thumb not found'], 404);
                return;
            }

            header('Content-Type: image/jpeg');
            header('Cache-Control: private, max-age=3600');
            header('Content-Length: ' . (string)filesize($thumbPath));
            readfile($thumbPath);
        } catch (\Throwable $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    public function restore(): void
    {
        $this->restoreCore(false);
    }

    public function restoreBulk(): void
    {
        $this->restoreCore(true);
    }

    private function restoreCore(bool $bulk): void
    {
        try {
            [$config, $maria, $user] = $this->authAdmin();
            if ($user === null) {
                return;
            }
            [$ids, $singleMode] = $this->parseIds($bulk);
            if ($ids === []) {
                $this->json(['error' => 'ids are required'], 400);
                return;
            }

            $result = $this->restoreMany($config, $maria, (int)$user['id'], $ids);
            $this->logBulkAudit($maria, (int)$user['id'], 'media_restore_bulk', $result);

            if (!$bulk && $singleMode && count($ids) === 1) {
                if ($result['restored_count'] === 1) {
                    $this->json(['ok' => true, 'trash_id' => $ids[0], 'status' => 'restored']);
                } else {
                    $message = $result['errors'][0]['error'] ?? 'Restore failed';
                    $this->json(['error' => $message, 'result' => $result], 409);
                }
                return;
            }

            $status = $result['errors'] === [] ? 200 : 207;
            $this->json(['ok' => $result['restored_count'] > 0, 'result' => $result], $status);
        } catch (\JsonException $e) {
            $this->json(['error' => 'Invalid JSON'], 400);
        } catch (\Throwable $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    public function purge(): void
    {
        $this->purgeCore(false);
    }

    public function purgeBulk(): void
    {
        $this->purgeCore(true);
    }

    private function purgeCore(bool $bulk): void
    {
        try {
            [$config, $maria, $user] = $this->authAdmin();
            if ($user === null) {
                return;
            }
            [$ids, $singleMode] = $this->parseIds($bulk);
            if ($ids === []) {
                $this->json(['error' => 'ids are required'], 400);
                return;
            }

            $result = $this->purgeMany($config, $maria, (int)$user['id'], $ids);
            $this->logBulkAudit($maria, (int)$user['id'], 'media_purge_bulk', $result);

            if (!$bulk && $singleMode && count($ids) === 1) {
                if ($result['purged_count'] === 1) {
                    $this->json(['ok' => true, 'trash_id' => $ids[0], 'status' => 'purged']);
                } else {
                    $message = $result['errors'][0]['error'] ?? 'Purge failed';
                    $this->json(['error' => $message, 'result' => $result], 409);
                }
                return;
            }

            $status = $result['errors'] === [] ? 200 : 207;
            $this->json(['ok' => $result['purged_count'] > 0, 'result' => $result], $status);
        } catch (\JsonException $e) {
            $this->json(['error' => 'Invalid JSON'], 400);
        } catch (\Throwable $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    public function empty(): void
    {
        try {
            [$config, $maria, $user] = $this->authAdmin();
            if ($user === null) {
                return;
            }

            $rows = $maria->query("SELECT id FROM wa_media_trash WHERE status = 'trashed' ORDER BY deleted_at DESC, id DESC");
            $ids = array_map(static fn (array $r): int => (int)$r['id'], $rows);
            if ($ids === []) {
                $this->json(['ok' => true, 'result' => ['requested' => 0, 'purged_count' => 0, 'sample' => [], 'errors' => []]]);
                return;
            }
            if (count($ids) > self::MAX_BATCH) {
                $this->json(['error' => 'Too many items. Purge in batches of up to ' . self::MAX_BATCH], 400);
                return;
            }

            $result = $this->purgeMany($config, $maria, (int)$user['id'], $ids);
            $this->logBulkAudit($maria, (int)$user['id'], 'trash_empty', $result);
            $status = $result['errors'] === [] ? 200 : 207;
            $this->json(['ok' => $result['purged_count'] > 0, 'result' => $result], $status);
        } catch (\Throwable $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    public function isTrashed(Maria $maria, string $relPath): bool
    {
        $rows = $maria->query(
            "SELECT id FROM wa_media_trash WHERE rel_path = ? AND status = 'trashed' LIMIT 1",
            [$relPath]
        );
        return $rows !== [];
    }

    public static function activeTrashedRelPaths(Maria $maria): array
    {
        try {
            $rows = $maria->query("SELECT rel_path FROM wa_media_trash WHERE status = 'trashed'");
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $rel = trim((string)($row['rel_path'] ?? ''));
            if ($rel !== '') {
                $out[$rel] = true;
            }
        }
        return array_keys($out);
    }

    private function parseIds(bool $bulk): array
    {
        $body = file_get_contents('php://input');
        $data = json_decode($body ?: '', true, 512, JSON_THROW_ON_ERROR);

        $ids = [];
        $singleMode = false;

        if (isset($data['ids']) && is_array($data['ids'])) {
            foreach ($data['ids'] as $raw) {
                if (is_int($raw) || ctype_digit((string)$raw)) {
                    $id = (int)$raw;
                    if ($id > 0) {
                        $ids[$id] = true;
                    }
                }
            }
        } elseif (!$bulk && isset($data['trash_id']) && (is_int($data['trash_id']) || ctype_digit((string)$data['trash_id']))) {
            $id = (int)$data['trash_id'];
            if ($id > 0) {
                $ids[$id] = true;
                $singleMode = true;
            }
        }

        $list = array_keys($ids);
        if (count($list) > self::MAX_BATCH) {
            throw new \InvalidArgumentException('Max batch size is ' . self::MAX_BATCH);
        }

        return [$list, $singleMode];
    }

    private function restoreMany(array $config, Maria $maria, int $actorId, array $ids): array
    {
        $photosRoot = $this->mustRealDir((string)($config['photos']['root'] ?? ''));
        $thumbsRoot = $this->mustRealDir((string)($config['thumbs']['root'] ?? ''));
        $trashRoot = $this->mustRealDir((string)($config['trash']['root'] ?? ''));
        $trashThumbsRootRaw = (string)($config['trash']['thumbs_root'] ?? '');
        $trashThumbsRoot = $trashThumbsRootRaw !== '' ? $this->mustRealDir($trashThumbsRootRaw) : null;

        $restored = [];
        $errors = [];

        foreach ($ids as $id) {
            $row = $this->fetchTrashRow($maria, (int)$id);
            if ($row === null || (string)$row['status'] !== 'trashed') {
                $errors[] = ['id' => (int)$id, 'error' => 'Not found'];
                continue;
            }

            $relPath = $this->normalizeRelPath((string)($row['rel_path'] ?? ''));
            if ($relPath === null) {
                $errors[] = ['id' => (int)$id, 'error' => 'Invalid rel_path'];
                continue;
            }

            $src = $this->safeJoin($photosRoot, $relPath);
            $trash = $this->safeJoin($trashRoot, $relPath);
            if ($src === null || $trash === null) {
                $errors[] = ['id' => (int)$id, 'error' => 'Invalid path'];
                continue;
            }

            if (!is_file($trash)) {
                $errors[] = ['id' => (int)$id, 'error' => 'Trashed file missing'];
                continue;
            }
            if (is_file($src)) {
                $errors[] = ['id' => (int)$id, 'error' => 'Conflict: restore target exists'];
                continue;
            }

            $this->ensureDir(dirname($src));
            if (!$this->moveFile($trash, $src)) {
                $errors[] = ['id' => (int)$id, 'error' => 'Failed to restore file'];
                continue;
            }

            $thumbSrc = $this->thumbPath($thumbsRoot, $relPath);
            $thumbTrashRoot = $trashThumbsRoot ?? (rtrim($trashRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.thumbs');
            $thumbTrash = $this->thumbPath($thumbTrashRoot, $relPath);
            if ($thumbTrash !== null && $thumbSrc !== null && is_file($thumbTrash)) {
                try {
                    $this->ensureDir(dirname($thumbSrc));
                    $this->moveFile($thumbTrash, $thumbSrc);
                } catch (\Throwable $e) {
                    error_log('webalbum restore thumb move failed: ' . $e->getMessage());
                }
            }

            $maria->exec(
                "DELETE FROM wa_media_trash WHERE rel_path = ? AND status = 'restored' AND id <> ?",
                [$relPath, (int)$id]
            );
            $maria->exec(
                "UPDATE wa_media_trash SET status = 'restored', restored_at = NOW(), restored_by_user_id = ?, purged_at = NULL, purged_by_user_id = NULL WHERE id = ? AND status = 'trashed'",
                [$actorId, (int)$id]
            );
            $restored[] = $relPath;
        }

        return [
            'requested' => count($ids),
            'restored_count' => count($restored),
            'sample' => array_slice($restored, 0, 20),
            'errors' => array_slice($errors, 0, 10),
        ];
    }

    private function purgeMany(array $config, Maria $maria, int $actorId, array $ids): array
    {
        $trashRoot = $this->mustRealDir((string)($config['trash']['root'] ?? ''));
        $trashThumbsRootRaw = (string)($config['trash']['thumbs_root'] ?? '');
        $trashThumbsRoot = $trashThumbsRootRaw !== '' ? $this->mustRealDir($trashThumbsRootRaw) : null;

        $purged = [];
        $errors = [];

        foreach ($ids as $id) {
            $row = $this->fetchTrashRow($maria, (int)$id);
            if ($row === null || (string)$row['status'] !== 'trashed') {
                $errors[] = ['id' => (int)$id, 'error' => 'Not found'];
                continue;
            }

            $relPath = $this->normalizeRelPath((string)($row['rel_path'] ?? ''));
            if ($relPath === null) {
                $errors[] = ['id' => (int)$id, 'error' => 'Invalid rel_path'];
                continue;
            }

            $trash = $this->safeJoin($trashRoot, $relPath);
            if ($trash === null) {
                $errors[] = ['id' => (int)$id, 'error' => 'Invalid trash path'];
                continue;
            }

            $thumbTrashRoot = $trashThumbsRoot ?? (rtrim($trashRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.thumbs');
            $thumbTrash = $this->thumbPath($thumbTrashRoot, $relPath);

            if (is_file($trash) && !@unlink($trash)) {
                $errors[] = ['id' => (int)$id, 'error' => 'Failed to delete media file'];
                continue;
            }
            if ($thumbTrash !== null && is_file($thumbTrash) && !@unlink($thumbTrash)) {
                $errors[] = ['id' => (int)$id, 'error' => 'Failed to delete thumb'];
                continue;
            }

            $maria->exec(
                "DELETE FROM wa_media_trash WHERE rel_path = ? AND status = 'purged' AND id <> ?",
                [$relPath, (int)$id]
            );
            $maria->exec(
                "UPDATE wa_media_trash SET status = 'purged', purged_at = NOW(), purged_by_user_id = ?, restored_at = NULL, restored_by_user_id = NULL WHERE id = ? AND status = 'trashed'",
                [$actorId, (int)$id]
            );
            $purged[] = $relPath;
        }

        return [
            'requested' => count($ids),
            'purged_count' => count($purged),
            'sample' => array_slice($purged, 0, 20),
            'errors' => array_slice($errors, 0, 10),
        ];
    }

    private function logBulkAudit(Maria $maria, int $actorId, string $action, array $result): void
    {
        $details = [
            'count' => $result['restored_count'] ?? ($result['purged_count'] ?? 0),
            'sample' => $result['sample'] ?? [],
            'errors' => $result['errors'] ?? [],
        ];
        $this->logAudit($maria, $actorId, $action, $details);
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

    private function fetchTrashRow(Maria $maria, int $id): ?array
    {
        $rows = $maria->query(
            "SELECT * FROM wa_media_trash WHERE id = ? LIMIT 1",
            [$id]
        );
        return $rows[0] ?? null;
    }

    private function resolveSourcePath(string $path, string $relPath, string $photosRoot): ?string
    {
        $realRoot = realpath($photosRoot);
        if ($realRoot === false) {
            return null;
        }
        $rootPrefix = rtrim($realRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        if ($path !== '' && is_file($path)) {
            $realPath = realpath($path);
            if ($realPath !== false && str_starts_with($realPath, $rootPrefix)) {
                return $realPath;
            }
        }

        $fallback = $this->safeJoin($photosRoot, $relPath);
        if ($fallback === null) {
            return null;
        }
        $realFile = realpath($fallback);
        if ($realFile === false || !str_starts_with($realFile, $rootPrefix)) {
            return null;
        }
        return $realFile;
    }

    private function normalizeRelPath(string $relPath): ?string
    {
        $rel = str_replace('\\\\', '/', trim($relPath));
        if ($rel === '' || str_starts_with($rel, '/') || str_contains($rel, ':')) {
            return null;
        }
        foreach (explode('/', $rel) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                return null;
            }
        }
        return $rel;
    }

    private function safeJoin(string $root, string $relPath): ?string
    {
        if ($root === '') {
            return null;
        }
        $rel = $this->normalizeRelPath($relPath);
        if ($rel === null) {
            return null;
        }
        return rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    }

    private function pathsForRelPath(array $config, string $relPath): ?array
    {
        $photosRoot = (string)($config['photos']['root'] ?? '');
        $thumbsRoot = (string)($config['thumbs']['root'] ?? '');
        $trashRoot = (string)($config['trash']['root'] ?? '');
        $trashThumbsRoot = (string)($config['trash']['thumbs_root'] ?? '');
        if ($photosRoot === '' || $trashRoot === '') {
            return null;
        }

        $src = $this->safeJoin($photosRoot, $relPath);
        $trash = $this->safeJoin($trashRoot, $relPath);
        if ($src === null || $trash === null) {
            return null;
        }

        $thumbSrc = $thumbsRoot !== '' ? $this->thumbPath($thumbsRoot, $relPath) : null;
        $thumbTrashRoot = $trashThumbsRoot !== ''
            ? $trashThumbsRoot
            : (rtrim($trashRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.thumbs');
        $thumbTrash = $this->thumbPath($thumbTrashRoot, $relPath);

        return [
            'src' => $src,
            'trash' => $trash,
            'thumbSrc' => $thumbSrc,
            'thumbTrash' => $thumbTrash,
        ];
    }

    private function mustRealDir(string $path): string
    {
        $real = realpath($path);
        if ($real === false || !is_dir($real)) {
            throw new \RuntimeException('Invalid configured directory: ' . $path);
        }
        return rtrim($real, DIRECTORY_SEPARATOR);
    }

    private function thumbPath(string $root, string $relPath): ?string
    {
        $full = $this->safeJoin($root, $relPath);
        if ($full === null) {
            return null;
        }
        $info = pathinfo($full);
        $dir = (string)($info['dirname'] ?? '');
        $base = (string)($info['filename'] ?? '');
        if ($dir === '' || $base === '') {
            return null;
        }
        return $dir . DIRECTORY_SEPARATOR . $base . '.jpg';
    }

    private function ensureDir(string $dir): void
    {
        if ($dir === '') {
            throw new \RuntimeException('Invalid directory');
        }
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Failed to create directory');
        }
    }

    private function moveFile(string $src, string $dst): bool
    {
        if (@rename($src, $dst)) {
            return true;
        }
        if (!@copy($src, $dst)) {
            return false;
        }
        if (!@unlink($src)) {
            @unlink($dst);
            return false;
        }
        return true;
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(["\\", "%", "_"], ["\\\\", "\\%", "\\_"], $value);
    }

    private function logAudit(Maria $db, int $actorId, string $action, array $details = []): void
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
                    $details !== [] ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                ]
            );
            AuditLogMetaCache::invalidateIfMissing($action, $source);
        } catch (\Throwable $e) {
            // audit logging must not block operation
        }
    }

    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload);
    }
}

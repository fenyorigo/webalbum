<?php

declare(strict_types=1);

namespace WebAlbum\Http\Controllers;

use WebAlbum\Assets\AssetPaths;
use WebAlbum\Assets\AssetSupport;
use WebAlbum\Assets\Jobs;
use WebAlbum\Db\Maria;
use WebAlbum\UserContext;

final class AdminAssetsController
{
    private string $configPath;

    public function __construct(string $configPath)
    {
        $this->configPath = $configPath;
    }

    public function scan(): void
    {
        try {
            [$config, $maria, $admin] = $this->authAdmin();
            $root = (string)($config['photos']['root'] ?? '');
            $realRoot = realpath($root);
            if ($realRoot === false || !is_dir($realRoot)) {
                $this->json(['error' => 'Invalid WA_PHOTOS_ROOT'], 400);
                return;
            }

            $inserted = 0;
            $updated = 0;
            $queued = 0;
            $scanned = 0;
            $scannedDocs = 0;
            $scannedAudio = 0;

            $iter = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($realRoot, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iter as $fileInfo) {
                if (!$fileInfo instanceof \SplFileInfo || !$fileInfo->isFile()) {
                    continue;
                }
                $ext = AssetSupport::extFromPath($fileInfo->getFilename());
                if (!AssetSupport::isSupportedExtension($ext)) {
                    continue;
                }
                $scanned++;

                $absolute = $fileInfo->getPathname();
                $rel = ltrim(str_replace('\\', '/', substr($absolute, strlen($realRoot))), '/');
                $rel = AssetPaths::normalizeRelPath($rel);
                if ($rel === null) {
                    continue;
                }

                $type = AssetSupport::typeFromExtension($ext) ?? 'doc';
                if ($type === 'doc') {
                    $scannedDocs++;
                } elseif ($type === 'audio') {
                    $scannedAudio++;
                }
                $mime = $this->detectMime($absolute, $ext);
                $size = (int)$fileInfo->getSize();
                $mtime = (int)$fileInfo->getMTime();

                $existing = $maria->query('SELECT id, mtime, size FROM wa_assets WHERE rel_path = ? LIMIT 1', [$rel]);
                if ($existing === []) {
                    $maria->exec(
                        "INSERT INTO wa_assets (rel_path, type, ext, mime, size, mtime, sha256) VALUES (?, ?, ?, ?, ?, ?, NULL)",
                        [$rel, $type, $ext, $mime, $size, $mtime]
                    );
                    $assetRow = $maria->query('SELECT id FROM wa_assets WHERE rel_path = ? LIMIT 1', [$rel]);
                    if ($assetRow === []) {
                        continue;
                    }
                    $assetId = (int)$assetRow[0]['id'];
                    $inserted++;
                } else {
                    $assetId = (int)$existing[0]['id'];
                    $oldMtime = (int)$existing[0]['mtime'];
                    $oldSize = (int)$existing[0]['size'];
                    $maria->exec(
                        "UPDATE wa_assets SET type = ?, ext = ?, mime = ?, size = ?, mtime = ?, updated_at = NOW() WHERE id = ?",
                        [$type, $ext, $mime, $size, $mtime, $assetId]
                    );
                    if ($oldMtime !== $mtime || $oldSize !== $size) {
                        $updated++;
                        $maria->exec(
                            "UPDATE wa_asset_derivatives SET status = 'pending', error_text = NULL, updated_at = NOW() WHERE asset_id = ?",
                            [$assetId]
                        );
                    }
                }

                if ($type === 'doc') {
                    if (AssetSupport::isConvertibleToPdf($ext)) {
                        Jobs::enqueue($maria, 'doc_pdf_preview', ['asset_id' => $assetId]);
                        $queued++;
                        $maria->exec(
                            "INSERT INTO wa_asset_derivatives (asset_id, kind, status, updated_at) VALUES (?, 'pdf_preview', 'pending', NOW())\n" .
                            "ON DUPLICATE KEY UPDATE status = IF(status='ready', status, 'pending'), updated_at = NOW()",
                            [$assetId]
                        );
                    }
                    Jobs::enqueue($maria, 'doc_thumb', ['asset_id' => $assetId]);
                    $queued++;
                    $maria->exec(
                        "INSERT INTO wa_asset_derivatives (asset_id, kind, status, updated_at) VALUES (?, 'thumb', 'pending', NOW())\n" .
                        "ON DUPLICATE KEY UPDATE status = IF(status='ready', status, 'pending'), updated_at = NOW()",
                        [$assetId]
                    );
                }
            }

            $this->logAudit($maria, (int)$admin['id'], 'assets_scan', [
                'scanned' => $scanned,
                'inserted' => $inserted,
                'updated' => $updated,
                'jobs_enqueued' => $queued,
                'scanned_docs' => $scannedDocs,
                'scanned_audio' => $scannedAudio,
            ]);

            $this->json([
                'ok' => true,
                'scanned' => $scanned,
                'scanned_docs' => $scannedDocs,
                'scanned_audio' => $scannedAudio,
                'inserted' => $inserted,
                'updated' => $updated,
                'jobs_enqueued' => $queued,
                'jobs_note' => 'Derivative jobs are queued for documents only; audio files do not require derivatives.',
            ]);
        } catch (\Throwable $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    public function jobsStatus(): void
    {
        try {
            [$config, $maria] = $this->authAdmin();
            $counts = $maria->query("SELECT status, COUNT(*) AS c FROM wa_jobs GROUP BY status");
            $splitRows = $maria->query(
                "SELECT status, job_type, COUNT(*) AS c\n" .
                "FROM wa_jobs\n" .
                "WHERE status IN ('queued', 'running')\n" .
                "GROUP BY status, job_type"
            );
            $recentErrors = $maria->query(
                "SELECT id, job_type, attempts, last_error, updated_at FROM wa_jobs WHERE status = 'error' ORDER BY updated_at DESC LIMIT 20"
            );
            $running = $maria->query(
                "SELECT id, job_type, locked_by, locked_at, attempts FROM wa_jobs WHERE status = 'running' ORDER BY locked_at DESC LIMIT 20"
            );

            $map = ['queued' => 0, 'running' => 0, 'done' => 0, 'error' => 0];
            foreach ($counts as $row) {
                $status = (string)($row['status'] ?? '');
                if (isset($map[$status])) {
                    $map[$status] = (int)$row['c'];
                }
            }

            $split = [
                'queued' => ['doc_thumb' => 0, 'doc_pdf_preview' => 0, 'other' => 0],
                'running' => ['doc_thumb' => 0, 'doc_pdf_preview' => 0, 'other' => 0],
            ];
            foreach ($splitRows as $row) {
                $status = (string)($row['status'] ?? '');
                $jobType = (string)($row['job_type'] ?? '');
                $count = (int)($row['c'] ?? 0);
                if (!isset($split[$status])) {
                    continue;
                }
                if ($jobType === 'doc_thumb' || $jobType === 'doc_pdf_preview') {
                    $split[$status][$jobType] += $count;
                } else {
                    $split[$status]['other'] += $count;
                }
            }

            $this->json([
                'counts' => $map,
                'split' => $split,
                'recent_errors' => $recentErrors,
                'running' => $running,
            ]);
        } catch (\Throwable $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    public function listAssets(): void
    {
        try {
            [$config, $maria] = $this->authAdmin();

            $page = max(1, (int)($_GET['page'] ?? 1));
            $pageSize = max(10, min(200, (int)($_GET['page_size'] ?? 50)));
            $offset = ($page - 1) * $pageSize;
            $q = trim((string)($_GET['q'] ?? ''));
            $type = trim((string)($_GET['type'] ?? ''));
            $ext = strtolower(trim((string)($_GET['ext'] ?? '')));
            $status = strtolower(trim((string)($_GET['status'] ?? '')));
            $sortField = strtolower(trim((string)($_GET['sort_field'] ?? 'updated_at')));
            $sortDir = strtolower(trim((string)($_GET['sort_dir'] ?? 'desc')));
            if (!in_array($sortDir, ['asc', 'desc'], true)) {
                $sortDir = 'desc';
            }

            $where = [];
            $params = [];

            if ($q !== '') {
                $where[] = 'a.rel_path LIKE ?';
                $params[] = '%' . $this->escapeLike($q) . '%';
            }
            if (in_array($type, ['doc', 'audio'], true)) {
                $where[] = 'a.type = ?';
                $params[] = $type;
            }
            if ($ext !== '' && preg_match('/^[a-z0-9]{1,10}$/', $ext)) {
                $where[] = 'a.ext = ?';
                $params[] = $ext;
            }
            if (in_array($status, ['ready', 'pending', 'error'], true)) {
                $where[] = "(CASE WHEN a.type = 'audio' THEN 'na' ELSE COALESCE(d_thumb.status, 'pending') END = ? " .
                    "OR CASE WHEN a.type = 'audio' THEN 'na' WHEN a.ext = 'pdf' THEN 'ready' WHEN d_pdf.asset_id IS NULL THEN 'pending' ELSE COALESCE(d_pdf.status, 'pending') END = ?)";
                $params[] = $status;
                $params[] = $status;
            }

            $whereSql = $where === [] ? '1=1' : implode(' AND ', $where);
            $orderSql = $this->assetsOrderSql($sortField, $sortDir);

            $countRows = $maria->query(
                "SELECT COUNT(*) AS c
" .
                "FROM wa_assets a
" .
                "LEFT JOIN wa_asset_derivatives d_thumb ON d_thumb.asset_id = a.id AND d_thumb.kind = 'thumb'
" .
                "LEFT JOIN wa_asset_derivatives d_pdf ON d_pdf.asset_id = a.id AND d_pdf.kind = 'pdf_preview'
" .
                "WHERE {$whereSql}",
                $params
            );
            $total = (int)($countRows[0]['c'] ?? 0);

            $rows = $maria->query(
                "SELECT a.id, a.rel_path, a.type, a.ext, a.mime, a.size, a.mtime, a.updated_at,
" .
                "       CASE WHEN a.type = 'audio' THEN 'na' ELSE COALESCE(d_thumb.status, 'pending') END AS thumb_status,
" .
                "       d_thumb.updated_at AS thumb_updated_at,
" .
                "       CASE WHEN a.type = 'audio' THEN 'na' WHEN a.ext = 'pdf' THEN 'ready' WHEN d_pdf.asset_id IS NULL THEN 'pending' ELSE COALESCE(d_pdf.status, 'pending') END AS preview_status,
" .
                "       d_pdf.updated_at AS preview_updated_at
" .
                "FROM wa_assets a
" .
                "LEFT JOIN wa_asset_derivatives d_thumb ON d_thumb.asset_id = a.id AND d_thumb.kind = 'thumb'
" .
                "LEFT JOIN wa_asset_derivatives d_pdf ON d_pdf.asset_id = a.id AND d_pdf.kind = 'pdf_preview'
" .
                "WHERE {$whereSql}
" .
                "{$orderSql}
" .
                "LIMIT {$pageSize} OFFSET {$offset}",
                $params
            );

            $assetIds = [];
            foreach ($rows as $r) {
                $assetIds[] = (int)$r['id'];
            }
            $runningMap = [];
            if ($assetIds !== []) {
                $runRows = $maria->query(
                    "SELECT CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.asset_id')) AS UNSIGNED) AS asset_id, job_type
" .
                    "FROM wa_jobs
" .
                    "WHERE status = 'running'
" .
                    "  AND job_type IN ('doc_thumb', 'doc_pdf_preview')
" .
                    "  AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.asset_id')) AS UNSIGNED) IN (" .
                    implode(',', array_fill(0, count($assetIds), '?')) . ")",
                    $assetIds
                );
                foreach ($runRows as $rr) {
                    $aid = (int)($rr['asset_id'] ?? 0);
                    $jobType = (string)($rr['job_type'] ?? '');
                    if ($aid < 1) {
                        continue;
                    }
                    if (!isset($runningMap[$aid])) {
                        $runningMap[$aid] = ['thumb' => false, 'preview' => false];
                    }
                    if ($jobType === 'doc_thumb') {
                        $runningMap[$aid]['thumb'] = true;
                    } elseif ($jobType === 'doc_pdf_preview') {
                        $runningMap[$aid]['preview'] = true;
                    }
                }
            }

            foreach ($rows as &$row) {
                $typeVal = (string)($row['type'] ?? '');
                $extVal = strtolower((string)($row['ext'] ?? ''));
                $thumbApplicable = $typeVal === 'doc';
                $previewApplicable = $typeVal === 'doc' && in_array($extVal, ['pdf', 'txt', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'], true);
                $row['thumb_applicable'] = $thumbApplicable ? 1 : 0;
                $row['preview_applicable'] = $previewApplicable ? 1 : 0;
                if (!$thumbApplicable) {
                    $row['thumb_status'] = 'na';
                }
                if (!$previewApplicable) {
                    $row['preview_status'] = 'na';
                }
                $aid = (int)($row['id'] ?? 0);
                if (isset($runningMap[$aid])) {
                    if (($runningMap[$aid]['thumb'] ?? false) && ($row['thumb_status'] ?? '') === 'pending') {
                        $row['thumb_status'] = 'running';
                    }
                    if (($runningMap[$aid]['preview'] ?? false) && ($row['preview_status'] ?? '') === 'pending') {
                        $row['preview_status'] = 'running';
                    }
                }
            }
            unset($row);

            $this->json([
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
                'total_pages' => max(1, (int)ceil($total / $pageSize)),
                'sort_field' => $sortField,
                'sort_dir' => $sortDir,
                'items' => $rows,
            ]);
        } catch (\Throwable $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    public function requeue(): void
    {
        try {
            [$config, $maria, $admin] = $this->authAdmin();
            $raw = file_get_contents('php://input') ?: '{}';
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                $data = [];
            }
            $assetId = (int)($data['asset_id'] ?? 0);
            if ($assetId < 1) {
                $this->json(['error' => 'asset_id is required'], 400);
                return;
            }
            $kind = strtolower(trim((string)($data['kind'] ?? 'all')));
            if (!in_array($kind, ['all', 'thumb', 'pdf_preview'], true)) {
                $this->json(['error' => 'kind must be all|thumb|pdf_preview'], 400);
                return;
            }

            $rows = $maria->query('SELECT id, rel_path, type, ext FROM wa_assets WHERE id = ? LIMIT 1', [$assetId]);
            if ($rows === []) {
                $this->json(['error' => 'Asset not found'], 404);
                return;
            }
            $asset = $rows[0];
            if ((string)$asset['type'] !== 'doc') {
                $this->json(['error' => 'Only document assets have derivative jobs'], 400);
                return;
            }

            $ext = strtolower((string)$asset['ext']);
            $queued = [];

            if ($kind === 'all' || $kind === 'thumb') {
                Jobs::enqueue($maria, 'doc_thumb', ['asset_id' => $assetId]);
                $maria->exec(
                    "INSERT INTO wa_asset_derivatives (asset_id, kind, status, error_text, updated_at) VALUES (?, 'thumb', 'pending', NULL, NOW())\n" .
                    "ON DUPLICATE KEY UPDATE status = 'pending', error_text = NULL, updated_at = NOW()",
                    [$assetId]
                );
                $queued[] = 'thumb';
            }

            if (($kind === 'all' || $kind === 'pdf_preview') && AssetSupport::isConvertibleToPdf($ext)) {
                Jobs::enqueue($maria, 'doc_pdf_preview', ['asset_id' => $assetId]);
                $maria->exec(
                    "INSERT INTO wa_asset_derivatives (asset_id, kind, status, error_text, updated_at) VALUES (?, 'pdf_preview', 'pending', NULL, NOW())\n" .
                    "ON DUPLICATE KEY UPDATE status = 'pending', error_text = NULL, updated_at = NOW()",
                    [$assetId]
                );
                $queued[] = 'pdf_preview';
            }

            if ($queued === []) {
                $this->json(['error' => 'No applicable jobs for this asset/kind'], 400);
                return;
            }

            $this->logAudit($maria, (int)$admin['id'], 'assets_requeue', [
                'asset_id' => $assetId,
                'rel_path' => (string)$asset['rel_path'],
                'kind' => $kind,
                'queued' => $queued,
            ]);

            $this->json([
                'ok' => true,
                'asset_id' => $assetId,
                'queued' => $queued,
            ]);
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
            throw new \RuntimeException('auth');
        }
        if ((int)($user['is_admin'] ?? 0) !== 1) {
            $this->json(['error' => 'Forbidden'], 403);
            throw new \RuntimeException('admin');
        }
        return [$config, $maria, $user];
    }

    private function assetsOrderSql(string $field, string $dir): string
    {
        $direction = strtoupper($dir) === 'ASC' ? 'ASC' : 'DESC';

        return match ($field) {
            'rel_path' => "ORDER BY a.rel_path {$direction}, a.id DESC",
            'type' => "ORDER BY a.type {$direction}, a.rel_path ASC",
            'ext' => "ORDER BY a.ext {$direction}, a.rel_path ASC",
            'mtime' => "ORDER BY a.mtime {$direction}, a.id DESC",
            'thumb_status' => "ORDER BY CASE thumb_status WHEN 'error' THEN 0 WHEN 'pending' THEN 1 WHEN 'ready' THEN 2 ELSE 3 END {$direction}, a.updated_at DESC, a.id DESC",
            'preview_status' => "ORDER BY CASE preview_status WHEN 'error' THEN 0 WHEN 'pending' THEN 1 WHEN 'ready' THEN 2 ELSE 3 END {$direction}, a.updated_at DESC, a.id DESC",
            default => "ORDER BY a.updated_at {$direction}, a.id DESC",
        };
    }

    private function detectMime(string $path, string $ext): string
    {
        if (class_exists(\finfo::class)) {
            $fi = new \finfo(FILEINFO_MIME_TYPE);
            $mime = $fi->file($path);
            if (is_string($mime) && $mime !== '') {
                return $mime;
            }
        }
        return AssetSupport::mimeFromExtension($ext);
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function logAudit(Maria $db, int $actorId, string $action, array $details): void
    {
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            $db->exec(
                "INSERT INTO wa_audit_log (actor_user_id, target_user_id, action, source, ip_address, user_agent, details) VALUES (?, NULL, ?, 'web', ?, ?, ?)",
                [$actorId, $action, $ip, $agent, json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
            );
        } catch (\Throwable $e) {
            // non-blocking
        }
    }

    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload);
    }
}

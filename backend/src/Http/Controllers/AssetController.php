<?php

declare(strict_types=1);

namespace WebAlbum\Http\Controllers;

use WebAlbum\Assets\AssetPaths;
use WebAlbum\Assets\AssetSupport;
use WebAlbum\Assets\Jobs;
use WebAlbum\Db\Maria;
use WebAlbum\UserContext;

final class AssetController
{
    private string $configPath;

    public function __construct(string $configPath)
    {
        $this->configPath = $configPath;
    }

    public function get(int $id): void
    {
        try {
            [$config, $maria, $user] = $this->auth();
            $asset = $this->assetById($maria, $id);
            if ($asset === null) {
                $this->json(['error' => 'Not found'], 404);
                return;
            }

            $derivatives = $maria->query(
                "SELECT kind, path, status, error_text, updated_at FROM wa_asset_derivatives WHERE asset_id = ?",
                [$id]
            );

            $this->json([
                'id' => (int)$asset['id'],
                'rel_path' => (string)$asset['rel_path'],
                'type' => (string)$asset['type'],
                'ext' => (string)$asset['ext'],
                'mime' => (string)$asset['mime'],
                'size' => (int)$asset['size'],
                'mtime' => (int)$asset['mtime'],
                'derivatives' => $derivatives,
            ]);
        } catch (\Throwable $e) {
            $this->json(['error' => $e->getMessage()], 400);
        }
    }

    public function file(int $id): void
    {
        try {
            [$config, $maria, $user] = $this->auth();
            $asset = $this->assetById($maria, $id);
            if ($asset === null) {
                $this->json(['error' => 'Not found'], 404);
                return;
            }
            if ($this->isTrashed($maria, (string)$asset['rel_path'])) {
                $this->json(['error' => 'Trashed'], 410);
                return;
            }

            $path = AssetPaths::joinInside((string)$config['photos']['root'], (string)$asset['rel_path']);
            if ($path === null || !is_file($path)) {
                $this->json(['error' => 'File not found'], 404);
                return;
            }

            $mime = (string)$asset['mime'];
            if ($mime === '') {
                $mime = AssetSupport::mimeFromExtension((string)$asset['ext']);
            }
            $isAudio = (string)$asset['type'] === 'audio';
            $this->streamFile($path, $mime, true, $isAudio);
        } catch (\Throwable $e) {
            $this->json(['error' => $e->getMessage()], 400);
        }
    }

    public function view(int $id): void
    {
        try {
            [$config, $maria, $user] = $this->auth();
            $asset = $this->assetById($maria, $id);
            if ($asset === null) {
                $this->json(['error' => 'Not found'], 404);
                return;
            }
            if ((string)$asset['type'] !== 'doc') {
                $this->json(['error' => 'Only documents support viewer'], 400);
                return;
            }
            if ($this->isTrashed($maria, (string)$asset['rel_path'])) {
                $this->json(['error' => 'Trashed'], 410);
                return;
            }

            $ext = strtolower((string)$asset['ext']);
            if ($ext === 'pdf') {
                $path = AssetPaths::joinInside((string)$config['photos']['root'], (string)$asset['rel_path']);
                if ($path === null || !is_file($path)) {
                    $this->json(['error' => 'File not found'], 404);
                    return;
                }
                $this->streamFile($path, 'application/pdf', true, false);
                return;
            }

            $preview = $this->readyDerivative($maria, (int)$asset['id'], 'pdf_preview');
            if ($preview !== null) {
                $this->streamFile($preview, 'application/pdf', true, false);
                return;
            }

            $this->enqueueDocJobs($maria, $asset);
            $this->json(['error' => 'Preview is being generated', 'status' => 'pending'], 409);
        } catch (\Throwable $e) {
            $this->json(['error' => $e->getMessage()], 400);
        }
    }

    public function thumb(int $id): void
    {
        try {
            [$config, $maria, $user] = $this->auth();
            $asset = $this->assetById($maria, $id);
            if ($asset === null) {
                $this->json(['error' => 'Not found'], 404);
                return;
            }
            if ((string)$asset['type'] !== 'doc') {
                http_response_code(404);
                return;
            }

            $thumb = $this->readyDerivative($maria, (int)$asset['id'], 'thumb');
            if ($thumb !== null) {
                $this->streamFile($thumb, 'image/jpeg', true, false);
                return;
            }

            $this->enqueueDocJobs($maria, $asset);
            $this->serveThumbPlaceholder((string)$asset['ext']);
        } catch (\Throwable $e) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    private function auth(): array
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
        return [$config, $maria, $user];
    }

    private function assetById(Maria $maria, int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        $rows = $maria->query(
            "SELECT id, rel_path, type, ext, mime, size, mtime FROM wa_assets WHERE id = ?",
            [$id]
        );
        return $rows[0] ?? null;
    }

    private function readyDerivative(Maria $maria, int $assetId, string $kind): ?string
    {
        $rows = $maria->query(
            "SELECT path, status FROM wa_asset_derivatives WHERE asset_id = ? AND kind = ? LIMIT 1",
            [$assetId, $kind]
        );
        if ($rows === []) {
            return null;
        }

        $row = $rows[0];
        if ((string)($row['status'] ?? '') !== 'ready') {
            return null;
        }
        $path = trim((string)($row['path'] ?? ''));
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            $maria->exec(
                "UPDATE wa_asset_derivatives SET status = 'pending', error_text = 'Derivative file missing', updated_at = NOW() WHERE asset_id = ? AND kind = ?",
                [$assetId, $kind]
            );
            return null;
        }
        return $path;
    }

    private function enqueueDocJobs(Maria $maria, array $asset): void
    {
        $assetId = (int)($asset['id'] ?? 0);
        $ext = strtolower((string)($asset['ext'] ?? ''));

        if (AssetSupport::isConvertibleToPdf($ext)) {
            Jobs::enqueue($maria, 'doc_pdf_preview', ['asset_id' => $assetId]);
            $maria->exec(
                "INSERT INTO wa_asset_derivatives (asset_id, kind, status, updated_at) VALUES (?, 'pdf_preview', 'pending', NOW())\n" .
                "ON DUPLICATE KEY UPDATE status = IF(status='ready', status, 'pending'), updated_at = NOW()",
                [$assetId]
            );
        }

        Jobs::enqueue($maria, 'doc_thumb', ['asset_id' => $assetId]);
        $maria->exec(
            "INSERT INTO wa_asset_derivatives (asset_id, kind, status, updated_at) VALUES (?, 'thumb', 'pending', NOW())\n" .
            "ON DUPLICATE KEY UPDATE status = IF(status='ready', status, 'pending'), updated_at = NOW()",
            [$assetId]
        );
    }

    private function serveThumbPlaceholder(string $ext): void
    {
        $label = strtoupper($ext === '' ? 'DOC' : $ext);
        $svg = "<svg xmlns='http://www.w3.org/2000/svg' width='256' height='256'>"
            . "<rect width='100%' height='100%' fill='#f2efe8'/>"
            . "<rect x='28' y='28' width='200' height='200' rx='14' fill='#ffffff' stroke='#c6b89f'/>"
            . "<text x='128' y='126' text-anchor='middle' font-size='36' fill='#333' font-family='Arial,sans-serif'>" . htmlspecialchars($label, ENT_QUOTES) . "</text>"
            . "<text x='128' y='165' text-anchor='middle' font-size='14' fill='#666' font-family='Arial,sans-serif'>preview pending</text>"
            . "</svg>";
        http_response_code(200);
        header('Content-Type: image/svg+xml; charset=utf-8');
        header('Cache-Control: no-store');
        echo $svg;
    }

    private function streamFile(string $path, string $mime, bool $inline, bool $allowRange): void
    {
        $size = @filesize($path);
        if ($size === false) {
            throw new \RuntimeException('Failed to read file size');
        }

        header('Content-Type: ' . $mime);
        header('Cache-Control: private, max-age=3600');
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . basename($path) . '"');

        if (!$allowRange) {
            header('Content-Length: ' . (string)$size);
            readfile($path);
            return;
        }

        header('Accept-Ranges: bytes');
        $rangeHeader = $_SERVER['HTTP_RANGE'] ?? null;
        if (!is_string($rangeHeader) || $rangeHeader === '') {
            header('Content-Length: ' . (string)$size);
            $this->streamRange($path, 0, $size - 1);
            return;
        }

        [$start, $end] = $this->parseRange($rangeHeader, (int)$size);
        if ($start === null || $end === null) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            return;
        }

        $length = $end - $start + 1;
        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        header('Content-Length: ' . (string)$length);
        $this->streamRange($path, $start, $end);
    }

    private function parseRange(string $header, int $size): array
    {
        if (!preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $m)) {
            return [null, null];
        }
        $startStr = $m[1];
        $endStr = $m[2];
        if ($startStr === '' && $endStr === '') {
            return [null, null];
        }
        if ($startStr === '') {
            $suffix = (int)$endStr;
            if ($suffix <= 0) {
                return [null, null];
            }
            return [max(0, $size - $suffix), $size - 1];
        }

        $start = (int)$startStr;
        $end = $endStr === '' ? $size - 1 : (int)$endStr;
        if ($start < 0 || $end < $start || $start >= $size) {
            return [null, null];
        }
        return [$start, min($end, $size - 1)];
    }

    private function streamRange(string $path, int $start, int $end): void
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw new \RuntimeException('Failed to open file');
        }
        try {
            fseek($fh, $start);
            $remaining = $end - $start + 1;
            while ($remaining > 0 && !feof($fh)) {
                $chunk = (int)min(1024 * 1024, $remaining);
                $buf = fread($fh, $chunk);
                if (!is_string($buf) || $buf === '') {
                    break;
                }
                echo $buf;
                $remaining -= strlen($buf);
                if (function_exists('ob_flush')) {
                    @ob_flush();
                }
                flush();
            }
        } finally {
            fclose($fh);
        }
    }

    private function isTrashed(Maria $maria, string $relPath): bool
    {
        $rows = $maria->query(
            "SELECT id FROM wa_media_trash WHERE rel_path = ? AND status = 'trashed' LIMIT 1",
            [$relPath]
        );
        return $rows !== [];
    }

    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload);
    }
}

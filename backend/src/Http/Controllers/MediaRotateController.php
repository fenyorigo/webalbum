<?php

declare(strict_types=1);

namespace WebAlbum\Http\Controllers;

use WebAlbum\Db\Maria;
use WebAlbum\Db\SqliteIndex;
use WebAlbum\Security\PathGuard;
use WebAlbum\SystemTools;
use WebAlbum\Thumb\ThumbPolicy;
use WebAlbum\UserContext;

final class MediaRotateController
{
    private string $configPath;

    public function __construct(string $configPath)
    {
        $this->configPath = $configPath;
    }

    public function save(int $id): void
    {
        try {
            if ($id < 1) {
                throw new \InvalidArgumentException('Invalid id');
            }

            $config = require $this->configPath;
            $maria = new Maria(
                $config['mariadb']['dsn'],
                $config['mariadb']['user'],
                $config['mariadb']['pass']
            );
            $user = UserContext::currentUser($maria);
            if ($user === null) {
                $this->json(['error' => 'Not authenticated'], 401);
                return;
            }
            if ((int)($user['is_admin'] ?? 0) !== 1) {
                $this->json(['error' => 'Forbidden'], 403);
                return;
            }

            $raw = file_get_contents('php://input') ?: '{}';
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                $data = [];
            }

            $turns = (int)($data['quarter_turns'] ?? 0);
            $turns = $this->normalizeTurns($turns);
            if ($turns === 0) {
                $this->json(['error' => 'No rotation requested'], 400);
                return;
            }

            $sqlite = new SqliteIndex($config['sqlite']['path']);
            $rows = $sqlite->query('SELECT id, path, rel_path, type FROM files WHERE id = ?', [$id]);
            if ($rows === []) {
                $this->json(['error' => 'Not Found'], 404);
                return;
            }
            $row = $rows[0];
            $type = (string)($row['type'] ?? '');
            if ($type !== 'image' && $type !== 'video') {
                $this->json(['error' => 'Only image and video are supported'], 400);
                return;
            }

            $photosRoot = (string)($config['photos']['root'] ?? '');
            $path = $this->resolveOriginalPath(
                (string)($row['path'] ?? ''),
                (string)($row['rel_path'] ?? ''),
                $photosRoot
            );
            if ($path === null || !is_file($path)) {
                $this->json(['error' => 'File not found'], 404);
                return;
            }

            $toolStatus = SystemTools::checkExternalTools($config, true);
            $ffmpegTool = $toolStatus['tools']['ffmpeg'] ?? ['available' => false, 'path' => null];
            if (!(bool)($ffmpegTool['available'] ?? false) || empty($ffmpegTool['path'])) {
                throw new \RuntimeException('ffmpeg not available');
            }
            $ffmpeg = (string)$ffmpegTool['path'];

            $tmp = $path . '.rotate.' . getmypid() . '.' . bin2hex(random_bytes(4));
            $filter = $this->rotationFilter($turns);

            try {
                $this->runRotate($ffmpeg, $path, $tmp, $filter, $type);
                if (!is_file($tmp) || (int)@filesize($tmp) <= 0) {
                    throw new \RuntimeException('Rotation output is empty');
                }
                if (!@rename($tmp, $path)) {
                    throw new \RuntimeException('Failed to replace original file after rotation');
                }
            } finally {
                if (is_file($tmp)) {
                    @unlink($tmp);
                }
            }

            $thumbRoot = (string)($config['thumbs']['root'] ?? '');
            $relPath = (string)($row['rel_path'] ?? '');
            if ($thumbRoot !== '' && $relPath !== '') {
                $thumbPath = ThumbPolicy::thumbPath($thumbRoot, $relPath);
                if (is_string($thumbPath) && is_file($thumbPath)) {
                    @unlink($thumbPath);
                }
            }

            $this->json([
                'ok' => true,
                'id' => $id,
                'type' => $type,
                'quarter_turns' => $turns,
            ]);
        } catch (\Throwable $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    private function normalizeTurns(int $turns): int
    {
        $value = $turns % 4;
        if ($value < 0) {
            $value += 4;
        }
        return $value;
    }

    private function rotationFilter(int $turns): string
    {
        return match ($turns) {
            1 => 'transpose=1',
            2 => 'hflip,vflip',
            3 => 'transpose=2',
            default => '',
        };
    }

    private function runRotate(string $ffmpeg, string $src, string $dest, string $filter, string $type): void
    {
        $args = [
            $ffmpeg,
            '-v', 'error',
            '-y',
            '-i', $src,
            '-vf', $filter,
        ];

        if ($type === 'video') {
            $args = array_merge($args, [
                '-c:v', 'libx264',
                '-preset', 'veryfast',
                '-crf', '18',
                '-c:a', 'copy',
                '-movflags', '+faststart',
            ]);
        } else {
            $args = array_merge($args, ['-q:v', '2']);
        }

        $args[] = $dest;
        $cmd = implode(' ', array_map('escapeshellarg', $args));
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to start ffmpeg');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $start = microtime(true);
        $timeout = ($type === 'video') ? 180 : 60;

        while (true) {
            $stdout .= (string)stream_get_contents($pipes[1]);
            $stderr .= (string)stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if ((microtime(true) - $start) > $timeout) {
                proc_terminate($process, 9);
                throw new \RuntimeException('ffmpeg timeout during rotate');
            }
            usleep(20000);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        if ($exit !== 0) {
            $msg = trim($stderr !== '' ? $stderr : $stdout);
            if ($msg === '') {
                $msg = 'ffmpeg rotate failed';
            }
            throw new \RuntimeException($msg);
        }
    }

    private function resolveOriginalPath(string $path, string $relPath, string $photosRoot): ?string
    {
        if ($path !== '' && is_file($path)) {
            return PathGuard::assertInsideRoot($path, $photosRoot);
        }
        $fallback = $this->safeJoin($photosRoot, $relPath);
        if ($fallback === null) {
            return null;
        }
        return PathGuard::assertInsideRoot($fallback, $photosRoot);
    }

    private function safeJoin(string $root, string $relPath): ?string
    {
        if ($root === '' || $relPath === '') {
            return null;
        }
        $rel = str_replace('\\', '/', $relPath);
        if ($rel[0] === '/' || str_contains($rel, ':')) {
            return null;
        }
        foreach (explode('/', $rel) as $part) {
            if ($part === '..') {
                return null;
            }
        }
        return rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $rel;
    }

    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload);
    }
}

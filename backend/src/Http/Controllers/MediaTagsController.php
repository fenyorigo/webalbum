<?php

declare(strict_types=1);

namespace WebAlbum\Http\Controllers;

use WebAlbum\AuditLogMetaCache;
use WebAlbum\Db\Maria;
use WebAlbum\Db\SqliteIndex;
use WebAlbum\UserContext;

final class MediaTagsController
{
    private string $configPath;

    public function __construct(string $configPath)
    {
        $this->configPath = $configPath;
    }

    public function get(int $id): void
    {
        try {
            if ($id < 1) {
                $this->json(["error" => "Invalid id"], 400);
                return;
            }

            [$config, $maria, $user] = $this->auth();
            if ($user === null) {
                return;
            }

            $sqlite = new SqliteIndex($config["sqlite"]["path"]);
            $file = $this->fetchFile($sqlite, $id);
            if ($file === null) {
                $this->json(["error" => "Not Found"], 404);
                return;
            }

            $tags = $this->fetchDisplayTags($sqlite, $id);
            $this->json(["id" => $id, "tags" => $tags]);
        } catch (\Throwable $e) {
            $this->json(["error" => $e->getMessage()], 400);
        }
    }

    public function save(int $id): void
    {
        try {
            if ($id < 1) {
                $this->json(["error" => "Invalid id"], 400);
                return;
            }

            [$config, $maria, $user] = $this->auth();
            if ($user === null) {
                return;
            }
            if ((int)($user["is_admin"] ?? 0) !== 1) {
                $this->json(["error" => "Forbidden"], 403);
                return;
            }

            $body = file_get_contents("php://input");
            $data = json_decode($body ?: "", true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data) || !isset($data["tags"]) || !is_array($data["tags"])) {
                throw new \InvalidArgumentException("tags array is required");
            }
            $newTags = $this->normalizeTags($data["tags"]);

            $sqliteRo = new SqliteIndex($config["sqlite"]["path"]);
            $file = $this->fetchFile($sqliteRo, $id);
            if ($file === null) {
                $this->json(["error" => "Not Found"], 404);
                return;
            }

            $path = $this->resolveOriginalPath(
                (string)($file["path"] ?? ""),
                (string)($file["rel_path"] ?? ""),
                (string)($config["photos"]["root"] ?? "")
            );
            if ($path === null || !is_file($path)) {
                $this->json(["error" => "File not found"], 404);
                return;
            }

            $oldTags = $this->fetchDisplayTags($sqliteRo, $id);

            $exiftool = (string)($config["tools"]["exiftool"] ?? "exiftool");
            $this->writeTagsWithExiftool($exiftool, $path, $newTags);

            $this->updateSqliteTags((string)$config["sqlite"]["path"], $id, $newTags);

            $this->logAudit(
                $maria,
                (int)$user["id"],
                "media_tag_edit",
                "web",
                [
                    "media_id" => $id,
                    "path" => $path,
                    "old_tags" => $oldTags,
                    "new_tags" => $newTags,
                ]
            );

            $this->json([
                "ok" => true,
                "id" => $id,
                "path" => $path,
                "tags" => $newTags,
            ]);
        } catch (\JsonException $e) {
            $this->json(["error" => "Invalid JSON"], 400);
        } catch (\Throwable $e) {
            $this->json(["error" => $e->getMessage()], 400);
        }
    }

    private function auth(): array
    {
        $config = require $this->configPath;
        $maria = new Maria(
            $config["mariadb"]["dsn"],
            $config["mariadb"]["user"],
            $config["mariadb"]["pass"]
        );
        $user = UserContext::currentUser($maria);
        if ($user === null) {
            $this->json(["error" => "Not authenticated"], 401);
        }
        return [$config, $maria, $user];
    }

    private function fetchFile(SqliteIndex $sqlite, int $id): ?array
    {
        $rows = $sqlite->query(
            "SELECT id, path, rel_path, type FROM files WHERE id = ?",
            [$id]
        );
        return $rows[0] ?? null;
    }

    private function fetchDisplayTags(SqliteIndex $sqlite, int $id): array
    {
        $rows = $sqlite->query(
            "SELECT DISTINCT t.tag\n" .
            "FROM file_tags ft\n" .
            "JOIN tags t ON t.id = ft.tag_id\n" .
            "WHERE ft.file_id = ?\n" .
            "  AND t.tag <> 'People'\n" .
            "  AND t.tag NOT LIKE 'People|%'\n" .
            "ORDER BY LOWER(t.tag) ASC",
            [$id]
        );
        return array_map(fn (array $r): string => (string)$r["tag"], $rows);
    }

    private function normalizeTags(array $tags): array
    {
        $out = [];
        $seen = [];
        foreach ($tags as $raw) {
            if (!is_string($raw)) {
                throw new \InvalidArgumentException("tags must be strings");
            }
            $t = preg_replace('/\s+/u', ' ', trim($raw));
            $t = is_string($t) ? $t : '';
            if ($t === '') {
                throw new \InvalidArgumentException("tags cannot be empty");
            }
            $len = function_exists('mb_strlen') ? mb_strlen($t, 'UTF-8') : strlen($t);
            if ($len > 128) {
                throw new \InvalidArgumentException("tag too long (max 128)");
            }
            if (str_contains($t, '|')) {
                throw new \InvalidArgumentException("tag must not contain pipe character");
            }
            if (!isset($seen[$t])) {
                $seen[$t] = true;
                $out[] = $t;
            }
        }
        return $out;
    }

    private function resolveOriginalPath(string $path, string $relPath, string $photosRoot): ?string
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
        if ($realFile === false) {
            return null;
        }
        if (!str_starts_with($realFile, $rootPrefix)) {
            return null;
        }
        return $realFile;
    }

    private function safeJoin(string $root, string $relPath): ?string
    {
        if ($root === '' || $relPath === '') {
            return null;
        }
        $rel = str_replace('\\', '/', $relPath);
        if ($rel === '' || $rel[0] === '/' || str_contains($rel, ':')) {
            return null;
        }
        foreach (explode('/', $rel) as $part) {
            if ($part === '..') {
                return null;
            }
        }
        return rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $rel;
    }

    private function writeTagsWithExiftool(string $exiftoolPath, string $path, array $tags): void
    {
        $binary = $this->resolveExiftoolBinary($exiftoolPath);
        $cmd = [
            $binary,
            '-overwrite_original',
            '-charset',
            'filename=utf8',
            '-charset',
            'iptc=utf8',
            '-IPTC:Keywords=',
            '-XMP-dc:Subject=',
            '-XMP-lr:HierarchicalSubject=',
        ];

        foreach ($tags as $tag) {
            $cmd[] = '-IPTC:Keywords=' . $tag;
            $cmd[] = '-XMP-dc:Subject=' . $tag;
            $cmd[] = '-XMP-lr:HierarchicalSubject=People|' . $tag;
        }
        $cmd[] = $path;

        [$ok, $stdout, $stderr, $timedOut] = $this->runProcess($cmd, 15);
        if (!$ok) {
            if ($timedOut) {
                throw new \RuntimeException('ExifTool timeout while writing tags');
            }
            $msg = trim($stderr !== "" ? $stderr : $stdout);
            if ($msg === "") {
                $msg = "ExifTool failed to write tags";
            } else {
                $msg = str_replace($path, "<media>", $msg);
            }
            throw new \RuntimeException($msg);
        }
    }

    private function resolveExiftoolBinary(string $configured): string
    {
        $configured = trim($configured);
        if ($configured !== '') {
            if ($configured === 'exiftool') {
                foreach (['/opt/homebrew/bin/exiftool', '/usr/local/bin/exiftool', '/usr/bin/exiftool'] as $candidate) {
                    if (is_file($candidate) && is_executable($candidate)) {
                        return $candidate;
                    }
                }
                return 'exiftool';
            }
            if (is_file($configured) && is_executable($configured)) {
                return $configured;
            }
            throw new \RuntimeException('Configured exiftool binary not found or not executable');
        }

        foreach (['/opt/homebrew/bin/exiftool', '/usr/local/bin/exiftool', '/usr/bin/exiftool'] as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }
        return 'exiftool';
    }

    private function runProcess(array $cmd, int $timeoutSec): array
    {
        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = @proc_open($cmd, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            throw new \RuntimeException('Failed to start exiftool process. Set WA_EXIFTOOL_PATH to full exiftool path.');
        }

        $stdout = '';
        $stderr = '';
        $timedOut = false;
        foreach ([1, 2] as $idx) {
            if (isset($pipes[$idx]) && is_resource($pipes[$idx])) {
                stream_set_blocking($pipes[$idx], false);
            }
        }

        $start = microtime(true);
        while (true) {
            if (isset($pipes[1]) && is_resource($pipes[1])) {
                $chunk = stream_get_contents($pipes[1]);
                if (is_string($chunk) && $chunk !== '') {
                    $stdout .= $chunk;
                }
            }
            if (isset($pipes[2]) && is_resource($pipes[2])) {
                $chunk = stream_get_contents($pipes[2]);
                if (is_string($chunk) && $chunk !== '') {
                    $stderr .= $chunk;
                }
            }

            $status = proc_get_status($proc);
            if (!$status['running']) {
                break;
            }

            if ((microtime(true) - $start) > $timeoutSec) {
                $timedOut = true;
                proc_terminate($proc, 9);
                break;
            }
            usleep(100000);
        }

        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $exit = proc_close($proc);

        return [$exit === 0 && !$timedOut, $stdout, $stderr, $timedOut];
    }

    private function updateSqliteTags(string $sqlitePath, int $fileId, array $tags): void
    {
        $pdo = new \PDO('sqlite:' . $sqlitePath, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);

        $pdo->beginTransaction();
        try {
            $stmtDelete = $pdo->prepare('DELETE FROM file_tags WHERE file_id = ?');
            $stmtDelete->execute([$fileId]);

            foreach ($tags as $tag) {
                $tagIds = [
                    $this->ensureTagId($pdo, $tag, 'keyword', 'iptc'),
                    $this->ensureTagId($pdo, $tag, 'subject', 'xmp-dc'),
                    $this->ensureTagId($pdo, $tag, 'person', 'xmp-lr'),
                    $this->ensureTagId($pdo, 'People|' . $tag, 'hierarchical', 'xmp-lr'),
                ];
                foreach ($tagIds as $tagId) {
                    $this->linkFileTag($pdo, $fileId, $tagId);
                }
            }

            if ($tags !== []) {
                $peopleId = $this->ensureTagId($pdo, 'People', 'category', 'xmp-lr');
                $this->linkFileTag($pdo, $fileId, $peopleId);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function ensureTagId(\PDO $pdo, string $tag, string $kind, string $source): int
    {
        $insert = $pdo->prepare('INSERT OR IGNORE INTO tags (tag, kind, source) VALUES (?, ?, ?)');
        $insert->execute([$tag, $kind, $source]);

        $select = $pdo->prepare('SELECT id FROM tags WHERE tag = ? AND kind = ? AND source = ?');
        $select->execute([$tag, $kind, $source]);
        $row = $select->fetch();
        if (!is_array($row) || !isset($row['id'])) {
            throw new \RuntimeException('Failed to resolve tag id');
        }
        return (int)$row['id'];
    }

    private function linkFileTag(\PDO $pdo, int $fileId, int $tagId): void
    {
        $insert = $pdo->prepare('INSERT OR IGNORE INTO file_tags (file_id, tag_id) VALUES (?, ?)');
        $insert->execute([$fileId, $tagId]);
    }

    private function logAudit(Maria $db, int $actorId, string $action, string $source, ?array $details = null): void
    {
        try {
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
                    $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                ]
            );
            AuditLogMetaCache::invalidateIfMissing($action, $source);
        } catch (\Throwable $e) {
            // audit logging must not block editing
        }
    }

    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload);
    }
}

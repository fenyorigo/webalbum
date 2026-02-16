<?php

declare(strict_types=1);

namespace WebAlbum\Assets;

use WebAlbum\Db\Maria;

final class Jobs
{
    public static function enqueue(Maria $db, string $jobType, array $payload): void
    {
        $assetId = isset($payload['asset_id']) ? (int)$payload['asset_id'] : 0;
        if ($assetId > 0) {
            $existing = $db->query(
                "SELECT id FROM wa_jobs WHERE job_type = ? AND status IN ('queued','running') AND JSON_EXTRACT(payload_json, '$.asset_id') = ? LIMIT 1",
                [$jobType, $assetId]
            );
            if ($existing !== []) {
                return;
            }
        }

        $db->exec(
            "INSERT INTO wa_jobs (job_type, payload_json, status, run_after) VALUES (?, ?, 'queued', NOW())",
            [$jobType, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]
        );
    }

    public static function claimNext(Maria $db, string $workerId): ?array
    {
        $updated = $db->exec(
            "UPDATE wa_jobs SET status = 'running', locked_by = ?, locked_at = NOW(), attempts = attempts + 1, updated_at = NOW()\n" .
            "WHERE id = (\n" .
            "  SELECT q.id FROM (\n" .
            "    SELECT id FROM wa_jobs\n" .
            "    WHERE status = 'queued' AND run_after <= NOW()\n" .
            "    ORDER BY id ASC\n" .
            "    LIMIT 1\n" .
            "  ) q\n" .
            ")",
            [$workerId]
        );
        if ($updated < 1) {
            return null;
        }

        $rows = $db->query(
            "SELECT id, job_type, payload_json, status, attempts FROM wa_jobs WHERE status = 'running' AND locked_by = ? ORDER BY locked_at DESC, id DESC LIMIT 1",
            [$workerId]
        );
        if ($rows === []) {
            return null;
        }

        $row = $rows[0];
        $payload = json_decode((string)($row['payload_json'] ?? '{}'), true);
        if (!is_array($payload)) {
            $payload = [];
        }

        return [
            'id' => (int)$row['id'],
            'job_type' => (string)$row['job_type'],
            'payload' => $payload,
            'attempts' => (int)$row['attempts'],
        ];
    }

    public static function markDone(Maria $db, int $id): void
    {
        $db->exec(
            "UPDATE wa_jobs SET status = 'done', locked_by = NULL, locked_at = NULL, last_error = NULL, updated_at = NOW() WHERE id = ?",
            [$id]
        );
    }

    public static function markError(Maria $db, int $id, string $error, int $attempts): void
    {
        $delaySeconds = min(3600, max(30, (int)pow(2, min($attempts, 10)) * 15));
        $status = $attempts >= 8 ? 'error' : 'queued';
        $db->exec(
            "UPDATE wa_jobs\n" .
            "SET status = ?, locked_by = NULL, locked_at = NULL, last_error = ?,\n" .
            "    run_after = DATE_ADD(NOW(), INTERVAL ? SECOND), updated_at = NOW()\n" .
            "WHERE id = ?",
            [$status, mb_substr($error, 0, 2000), $delaySeconds, $id]
        );
    }

    public static function recoverStaleLocks(Maria $db, int $staleMinutes = 15): int
    {
        return $db->exec(
            "UPDATE wa_jobs SET status = 'queued', locked_by = NULL, locked_at = NULL, run_after = NOW(), updated_at = NOW()\n" .
            "WHERE status = 'running' AND locked_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)",
            [$staleMinutes]
        );
    }
}

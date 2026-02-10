<?php

declare(strict_types=1);

namespace WebAlbum;

use WebAlbum\Db\Maria;

final class UserContext
{
    public static function currentUser(?Maria $db): ?array
    {
        if ($db === null) {
            return null;
        }
        $id = self::sessionUserId();
        if ($id === null) {
            return null;
        }
        try {
            $rows = $db->query(
                "SELECT id, username, display_name, is_admin, is_active, force_password_change\n" .
                "FROM wa_users WHERE id = ? AND is_active = 1",
                [$id]
            );
            return $rows[0] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function sessionUserId(): ?int
    {
        if (!isset($_SESSION) || !isset($_SESSION["wa_user_id"])) {
            return null;
        }
        $raw = $_SESSION["wa_user_id"];
        if (!is_int($raw) && !is_string($raw)) {
            return null;
        }
        $id = (int)$raw;
        return $id > 0 ? $id : null;
    }
}

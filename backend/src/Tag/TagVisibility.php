<?php

declare(strict_types=1);

namespace WebAlbum\Tag;

final class TagVisibility
{
    public static function suppressPeopleVariantSql(string $alias = "t"): string
    {
        // Hide People|Name only when Name exists as a plain tag. Keep exact "People".
        return "NOT (" . $alias . ".tag LIKE 'People|%' AND EXISTS (" .
            "SELECT 1 FROM tags base_t WHERE base_t.tag = substr(" . $alias . ".tag, 8)" .
            "))";
    }
}

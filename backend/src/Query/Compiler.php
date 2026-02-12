<?php

declare(strict_types=1);

namespace WebAlbum\Query;

final class Compiler
{
    public static function compileWhere(array $where): array
    {
        $params = [];
        $sql = self::compileGroup($where, $params);
        return [$sql, $params];
    }

    private static function compileGroup(array $group, array &$params): string
    {
        $parts = [];
        foreach ($group["items"] as $item) {
            if (isset($item["group"])) {
                $parts[] = self::compileGroup($item, $params);
            } else {
                $parts[] = self::compileRule($item, $params);
            }
        }

        $glue = $group["group"] === "ALL" ? " AND " : " OR ";
        $sql = "(" . implode($glue, $parts) . ")";
        if ($group["not"]) {
            return "(NOT " . $sql . ")";
        }
        return $sql;
    }

    private static function compileRule(array $rule, array &$params): string
    {
        $field = $rule["field"];
        $op = $rule["op"];
        $value = $rule["value"];

        if ($field === "tag") {
            $params[] = $value;
            $exists = "EXISTS (SELECT 1 FROM file_tags ft JOIN tags t ON t.id = ft.tag_id WHERE ft.file_id = files.id AND t.tag = ?)";
            if ($op === "is_not") {
                return "(NOT " . $exists . ")";
            }
            return "(" . $exists . ")";
        }

        if ($field === "taken") {
            if ($op === "between") {
                [$start, $end] = self::dateRange($value[0], $value[1]);
                $params[] = $start;
                $params[] = $end;
                return "(files.taken_ts BETWEEN ? AND ?)";
            }

            if ($op === "before") {
                $end = self::dateEnd($value);
                $params[] = $end;
                return "(files.taken_ts <= ?)";
            }

            $start = self::dateStart($value);
            $params[] = $start;
            return "(files.taken_ts >= ?)";
        }

        if ($field === "type") {
            $params[] = $value;
            if ($op === "is_not") {
                return "(files.type <> ?)";
            }
            return "(files.type = ?)";
        }

        if ($field === "path") {
            $patterns = [];
            foreach (self::pathNeedles((string)$value) as $needle) {
                $escaped = self::escapeLike($needle);
                $patterns[] = $op === "contains" ? "%" . $escaped . "%" : $escaped . "%";
            }

            $parts = [];
            foreach ($patterns as $pattern) {
                $params[] = $pattern;
                $parts[] = "files.path LIKE ? ESCAPE '\\' COLLATE NOCASE";
            }
            return "(" . implode(" OR ", $parts) . ")";
        }

        throw new \RuntimeException("Unsupported rule");
    }

    private static function dateStart(string $date): int
    {
        $tz = new \DateTimeZone(date_default_timezone_get());
        $dt = \DateTimeImmutable::createFromFormat("Y-m-d H:i:s", $date . " 00:00:00", $tz);
        if ($dt === false) {
            throw new \RuntimeException("Invalid date: " . $date);
        }
        return $dt->getTimestamp();
    }

    private static function dateEnd(string $date): int
    {
        $tz = new \DateTimeZone(date_default_timezone_get());
        $dt = \DateTimeImmutable::createFromFormat("Y-m-d H:i:s", $date . " 23:59:59", $tz);
        if ($dt === false) {
            throw new \RuntimeException("Invalid date: " . $date);
        }
        return $dt->getTimestamp();
    }

    private static function dateRange(string $start, string $end): array
    {
        return [self::dateStart($start), self::dateEnd($end)];
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(["\\", "%", "_"], ["\\\\", "\\%", "\\_"], $value);
    }

    private static function pathNeedles(string $value): array
    {
        $variants = [$value];
        if (class_exists("\\Normalizer")) {
            $nfc = \Normalizer::normalize($value, \Normalizer::FORM_C);
            $nfd = \Normalizer::normalize($value, \Normalizer::FORM_D);
            if (is_string($nfc) && $nfc !== "") {
                $variants[] = $nfc;
            }
            if (is_string($nfd) && $nfd !== "") {
                $variants[] = $nfd;
            }
        }

        $seen = [];
        $out = [];
        foreach ($variants as $variant) {
            if (!isset($seen[$variant])) {
                $seen[$variant] = true;
                $out[] = $variant;
            }
        }
        return $out;
    }
}

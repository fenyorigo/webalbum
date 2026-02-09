<?php

declare(strict_types=1);

namespace WebAlbum\Query;

final class Model
{
    private const GROUPS = ["ALL", "ANY"];

    private const FIELD_OPS = [
        "tag" => ["is", "is_not"],
        "taken" => ["before", "after", "between"],
        "type" => ["is", "is_not"],
        "path" => ["contains", "starts_with"],
    ];

    private const TYPES = ["image", "video", "other"];

    public static function validateSearch(array $input): array
    {
        if (!isset($input["where"])) {
            throw new \InvalidArgumentException("Missing where clause");
        }

        $where = self::validateGroup($input["where"]);

        $sort = null;
        if (isset($input["sort"])) {
            $sort = self::validateSort($input["sort"]);
        }

        $limit = 200;
        if (isset($input["limit"])) {
            if (!is_int($input["limit"]) || $input["limit"] < 1 || $input["limit"] > 1000) {
                throw new \InvalidArgumentException("limit must be an integer between 1 and 1000");
            }
            $limit = $input["limit"];
        }

        $offset = 0;
        if (isset($input["offset"])) {
            if (!is_int($input["offset"]) || $input["offset"] < 0) {
                throw new \InvalidArgumentException("offset must be a non-negative integer");
            }
            $offset = $input["offset"];
        }

        return [
            "where" => $where,
            "sort" => $sort,
            "limit" => $limit,
            "offset" => $offset,
        ];
    }

    private static function validateSort(mixed $sort): array
    {
        if (!is_array($sort)) {
            throw new \InvalidArgumentException("sort must be an object");
        }
        $field = $sort["field"] ?? null;
        $dir = $sort["dir"] ?? null;
        if (!in_array($field, ["taken", "path"], true)) {
            throw new \InvalidArgumentException("sort.field must be taken or path");
        }
        if (!in_array($dir, ["asc", "desc"], true)) {
            throw new \InvalidArgumentException("sort.dir must be asc or desc");
        }
        return ["field" => $field, "dir" => $dir];
    }

    private static function validateGroup(mixed $group): array
    {
        if (!is_array($group)) {
            throw new \InvalidArgumentException("where must be an object");
        }

        $unknown = array_diff(array_keys($group), ["group", "items", "not"]);
        if ($unknown !== []) {
            throw new \InvalidArgumentException("Unknown where keys: " . implode(", ", $unknown));
        }

        $groupType = $group["group"] ?? null;
        if (!in_array($groupType, self::GROUPS, true)) {
            throw new \InvalidArgumentException("where.group must be ALL or ANY");
        }

        $items = $group["items"] ?? null;
        if (!is_array($items) || $items === []) {
            throw new \InvalidArgumentException("where.items must be a non-empty array");
        }

        $not = $group["not"] ?? false;
        if (!is_bool($not)) {
            throw new \InvalidArgumentException("where.not must be a boolean");
        }

        $normalized = [];
        foreach ($items as $item) {
            $normalized[] = self::validateItem($item);
        }

        return [
            "group" => $groupType,
            "items" => $normalized,
            "not" => $not,
        ];
    }

    private static function validateItem(mixed $item): array
    {
        if (!is_array($item)) {
            throw new \InvalidArgumentException("where.items must contain objects");
        }
        if (isset($item["group"])) {
            return self::validateGroup($item);
        }
        return self::validateRule($item);
    }

    private static function validateRule(array $rule): array
    {
        $unknown = array_diff(array_keys($rule), ["field", "op", "value"]);
        if ($unknown !== []) {
            throw new \InvalidArgumentException("Unknown rule keys: " . implode(", ", $unknown));
        }

        $field = $rule["field"] ?? null;
        $op = $rule["op"] ?? null;
        if (!is_string($field) || !isset(self::FIELD_OPS[$field])) {
            throw new \InvalidArgumentException("Unknown field: " . (string)$field);
        }
        if (!is_string($op) || !in_array($op, self::FIELD_OPS[$field], true)) {
            throw new \InvalidArgumentException("Unknown op for field " . $field . ": " . (string)$op);
        }

        if (!array_key_exists("value", $rule)) {
            throw new \InvalidArgumentException("Rule value is required");
        }

        $value = $rule["value"];
        if ($field === "tag") {
            if (!is_string($value) || $value === "") {
                throw new \InvalidArgumentException("tag value must be a non-empty string");
            }
        } elseif ($field === "taken") {
            if ($op === "between") {
                if (!is_array($value) || count($value) !== 2) {
                    throw new \InvalidArgumentException("taken between requires [start, end] dates");
                }
                foreach ($value as $date) {
                    if (!is_string($date) || !self::isDate($date)) {
                        throw new \InvalidArgumentException("taken date must be YYYY-MM-DD");
                    }
                }
            } else {
                if (!is_string($value) || !self::isDate($value)) {
                    throw new \InvalidArgumentException("taken date must be YYYY-MM-DD");
                }
            }
        } elseif ($field === "type") {
            if (!is_string($value) || !in_array($value, self::TYPES, true)) {
                throw new \InvalidArgumentException("type must be image, video, or other");
            }
        } elseif ($field === "path") {
            if (!is_string($value) || $value === "") {
                throw new \InvalidArgumentException("path value must be a non-empty string");
            }
        }

        return ["field" => $field, "op" => $op, "value" => $value];
    }

    private static function isDate(string $value): bool
    {
        $dt = \DateTimeImmutable::createFromFormat("Y-m-d", $value);
        return $dt !== false && $dt->format("Y-m-d") === $value;
    }
}

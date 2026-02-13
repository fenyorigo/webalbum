<?php

declare(strict_types=1);

namespace WebAlbum\Query;

use WebAlbum\Db\SqliteIndex;

final class Runner
{
    private SqliteIndex $db;

    public function __construct(SqliteIndex $db)
    {
        $this->db = $db;
    }

    public function run(array $query, ?array $restrictIds = null, array $excludeTags = [], array $excludeRelPaths = [], ?string $folderRelPath = null, ?int $folderId = null): array
    {
        [$whereSql, $params] = Compiler::compileWhere($query["where"]);

        $idClause = "";
        if (is_array($restrictIds)) {
            if ($restrictIds === []) {
                return [
                    "sql" => "",
                    "params" => [],
                    "rows" => [],
                    "total" => 0,
                ];
            }
            $placeholders = implode(",", array_fill(0, count($restrictIds), "?"));
            $idClause = " AND files.id IN (" . $placeholders . ")";
        }

        $excludeClause = "";
        $excludeParams = [];
        if ($excludeTags !== []) {
            $excludeTags = array_values(array_unique(array_filter(array_map("strval", $excludeTags), fn (string $t): bool => $t !== "")));
            if ($excludeTags !== []) {
                $tagPlaceholders = implode(",", array_fill(0, count($excludeTags), "?"));
                $excludeClause = " AND NOT EXISTS (" .
                    "SELECT 1 FROM file_tags ft_ex JOIN tags t_ex ON t_ex.id = ft_ex.tag_id " .
                    "WHERE ft_ex.file_id = files.id AND t_ex.tag IN (" . $tagPlaceholders . ")" .
                    ")";
                $excludeParams = $excludeTags;
            }
        }

        $excludeRelPathClause = "";
        $excludeRelPathParams = [];

        $folderClause = "";
        $folderParams = [];
        if (is_int($folderId) && $folderId > 0) {
            // Tree selection: direct folder only (no subtree).
            $folderClause = " AND files.directory_id = ?";
            $folderParams[] = $folderId;
        } elseif (is_string($folderRelPath) && $folderRelPath !== "") {
            $normalizedFolder = trim(str_replace("\\", "/", $folderRelPath), "/");
            if ($normalizedFolder !== "") {
                // Optional rel_path API filter: include subtree.
                $folderClause = " AND (files.rel_path = ? OR files.rel_path LIKE ? ESCAPE '\\')";
                $folderParams[] = $normalizedFolder;
                $folderParams[] = self::escapeLike($normalizedFolder) . "/%";
            }
        }
        if ($excludeRelPaths !== []) {
            $excludeRelPaths = array_values(array_unique(array_filter(array_map("strval", $excludeRelPaths), fn (string $p): bool => $p !== "")));
            if ($excludeRelPaths !== []) {
                $relPathPlaceholders = implode(",", array_fill(0, count($excludeRelPaths), "?"));
                $excludeRelPathClause = " AND files.rel_path NOT IN (" . $relPathPlaceholders . ")";
                $excludeRelPathParams = $excludeRelPaths;
            }
        }

         $countSql = "SELECT COUNT(*) AS c FROM files WHERE " . $whereSql . $idClause . $excludeClause . $excludeRelPathClause . $folderClause;
        $countParams = $params;
        if ($restrictIds) {
            $countParams = array_merge($countParams, $restrictIds);
        }
        if ($excludeParams !== []) {
            $countParams = array_merge($countParams, $excludeParams);
        }
        if ($excludeRelPathParams !== []) {
            $countParams = array_merge($countParams, $excludeRelPathParams);
        }
        if ($folderParams !== []) {
            $countParams = array_merge($countParams, $folderParams);
        }
        $countRow = $this->db->query($countSql, $countParams);
        $total = $countRow !== [] ? (int)$countRow[0]["c"] : 0;

        $sql = "SELECT id, path, taken_ts, type FROM files WHERE " . $whereSql . $idClause . $excludeClause . $excludeRelPathClause . $folderClause;
        $queryParams = $params;
        if ($restrictIds) {
            $queryParams = array_merge($queryParams, $restrictIds);
        }
        if ($excludeParams !== []) {
            $queryParams = array_merge($queryParams, $excludeParams);
        }
        if ($excludeRelPathParams !== []) {
            $queryParams = array_merge($queryParams, $excludeRelPathParams);
        }
        if ($folderParams !== []) {
            $queryParams = array_merge($queryParams, $folderParams);
        }

        if ($query["sort"]) {
            $field = $query["sort"]["field"];
            $dir = strtoupper($query["sort"]["dir"]);
            $column = $field === "taken" ? "files.taken_ts" : "files.path";
            $sql .= " ORDER BY " . $column . " " . $dir;
        }

        $sql .= " LIMIT " . (int)$query["limit"];
        if ($query["offset"] > 0) {
            $sql .= " OFFSET " . (int)$query["offset"];
        }

        $rows = $this->db->query($sql, $queryParams);

        return [
            "sql" => $sql,
            "params" => $queryParams,
            "rows" => $rows,
            "total" => $total,
        ];
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(["\\", "%", "_"], ["\\\\", "\\%", "\\_"], $value);
    }
}

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

    public function run(array $query, ?array $restrictIds = null): array
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

        $countSql = "SELECT COUNT(*) AS c FROM files WHERE " . $whereSql . $idClause;
        $countParams = $params;
        if ($restrictIds) {
            $countParams = array_merge($countParams, $restrictIds);
        }
        $countRow = $this->db->query($countSql, $countParams);
        $total = $countRow !== [] ? (int)$countRow[0]["c"] : 0;

        $sql = "SELECT id, path, taken_ts, type FROM files WHERE " . $whereSql . $idClause;
        $queryParams = $params;
        if ($restrictIds) {
            $queryParams = array_merge($queryParams, $restrictIds);
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
}

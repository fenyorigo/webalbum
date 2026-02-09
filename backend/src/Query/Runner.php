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

    public function run(array $query): array
    {
        [$whereSql, $params] = Compiler::compileWhere($query["where"]);

        $countSql = "SELECT COUNT(*) AS c FROM files WHERE " . $whereSql;
        $countRow = $this->db->query($countSql, $params);
        $total = $countRow !== [] ? (int)$countRow[0]["c"] : 0;

        $sql = "SELECT id, path, taken_ts, type FROM files WHERE " . $whereSql;

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

        $rows = $this->db->query($sql, $params);

        return [
            "sql" => $sql,
            "params" => $params,
            "rows" => $rows,
            "total" => $total,
        ];
    }
}

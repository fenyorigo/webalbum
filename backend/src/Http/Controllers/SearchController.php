<?php

declare(strict_types=1);

namespace WebAlbum\Http\Controllers;

use WebAlbum\Db\Maria;
use WebAlbum\Db\SqliteIndex;
use WebAlbum\Query\Model;
use WebAlbum\Query\Runner;
use WebAlbum\UserContext;

final class SearchController
{
    private string $configPath;

    public function __construct(string $configPath)
    {
        $this->configPath = $configPath;
    }

    public function handle(): void
    {
        try {
            $body = file_get_contents('php://input');
            $data = json_decode($body ?: '', true, 512, JSON_THROW_ON_ERROR);
            $query = Model::validateSearch($data);

            $config = require $this->configPath;
            $sqlite = new SqliteIndex($config['sqlite']['path']);
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

            $userId = (int)($user['id'] ?? 0);
            $isAdmin = (int)($user['is_admin'] ?? 0) === 1;
            $requestedType = $this->extractRequestedType($query['where']);
            $extFilters = $this->extractExtFilters($query['where']);

            // Extension filters apply only to MariaDB assets (docs/audio), so avoid mixed-source duplicates.
            if ($extFilters !== []) {
                if ($requestedType === 'image' || $requestedType === 'video' || $requestedType === 'other') {
                    $this->json([
                        'items' => [],
                        'total' => 0,
                        'offset' => (int)$query['offset'],
                        'limit' => (int)$query['limit'],
                    ]);
                    return;
                }
                $typeFilter = ($requestedType === 'doc' || $requestedType === 'audio') ? $requestedType : null;
                $assetResult = $this->searchAssetsOnly($maria, $sqlite, $query, $typeFilter, $extFilters);
                $this->json($assetResult);
                return;
            }

            if ($requestedType === 'doc' || $requestedType === 'audio') {
                $assetResult = $this->searchAssetsOnly($maria, $sqlite, $query, $requestedType, []);
                $this->json($assetResult);
                return;
            }

            $mediaResult = $this->searchMedia($sqlite, $maria, $query, $userId, $isAdmin);
            if ($requestedType === 'image' || $requestedType === 'video' || $requestedType === 'other') {
                $this->json($mediaResult);
                return;
            }

            $assetResult = $this->searchAssetsOnly($maria, $sqlite, $query, null, []);
            $merged = $this->mergeResultSets($mediaResult, $assetResult, $query);
            $this->json($merged);
        } catch (\JsonException $e) {
            $this->json(['error' => 'Invalid JSON'], 400);
        } catch (\Throwable $e) {
            $this->json(['error' => $e->getMessage()], 400);
        }
    }

    private function searchMedia(SqliteIndex $sqlite, Maria $maria, array $query, int $userId, bool $isAdmin): array
    {
        $runner = new Runner($sqlite);

        $restrictIds = null;
        if (!empty($query['only_favorites'])) {
            $favRows = $maria->query(
                'SELECT file_id FROM wa_favorites WHERE user_id = ?',
                [$userId]
            );
            $restrictIds = array_map(fn (array $row): int => (int)$row['file_id'], $favRows);
        }

        $excludeTags = $this->hiddenTagsForSearch($maria, $userId, $isAdmin);
        $excludeRelPaths = AdminTrashController::activeTrashedRelPaths($maria);

        $folderRelPath = null;
        $folderId = null;
        if ($query['folder_id'] !== null) {
            $folderId = (int)$query['folder_id'];
        } elseif ($query['folder_rel_path'] !== null) {
            $folderRelPath = trim(str_replace('\\', '/', (string)$query['folder_rel_path']), '/');
            if ($folderRelPath === '') {
                $folderRelPath = null;
            }
        }

        $result = $runner->run($query, $restrictIds, $excludeTags, $excludeRelPaths, $folderRelPath, $folderId);
        $items = $result['rows'];

        if ($items !== []) {
            $ids = array_map(fn (array $row): int => (int)$row['id'], $items);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $favRows = $maria->query(
                'SELECT file_id FROM wa_favorites WHERE user_id = ? AND file_id IN (' . $placeholders . ')',
                array_merge([$userId], $ids)
            );
            $favSet = [];
            foreach ($favRows as $fav) {
                $favSet[(int)$fav['file_id']] = true;
            }
            foreach ($items as &$row) {
                $row['entity'] = 'media';
                $row['asset_id'] = null;
                $row['is_favorite'] = isset($favSet[(int)$row['id']]);
            }
            unset($row);
        }

        return [
            'items' => $items,
            'total' => (int)$result['total'],
            'offset' => (int)$query['offset'],
            'limit' => (int)$query['limit'],
        ];
    }

    private function searchAssetsOnly(Maria $maria, SqliteIndex $sqlite, array $query, ?string $typeFilter, array $extFilters): array
    {
        $limit = (int)$query['limit'];
        $offset = (int)$query['offset'];

        $where = [];
        $params = [];

        $where[] = "NOT EXISTS (SELECT 1 FROM wa_media_trash mt WHERE mt.rel_path = a.rel_path AND mt.status = 'trashed')";

        if ($typeFilter !== null) {
            $where[] = 'a.type = ?';
            $params[] = $typeFilter;
        }

        if ($extFilters !== []) {
            $place = implode(',', array_fill(0, count($extFilters), '?'));
            $where[] = 'a.ext IN (' . $place . ')';
            foreach ($extFilters as $ext) {
                $params[] = $ext;
            }
        }

        $pathRules = $this->extractPathRules($query['where']);
        foreach ($pathRules as $rule) {
            $pattern = $rule['op'] === 'starts_with'
                ? $this->escapeLike($rule['value']) . '%'
                : '%' . $this->escapeLike($rule['value']) . '%';
            $where[] = 'a.rel_path LIKE ?';
            $params[] = $pattern;
        }

        $takenRules = $this->extractTakenRules($query['where']);
        foreach ($takenRules as $rule) {
            if ($rule['op'] === 'between') {
                [$start, $end] = $this->dateRange((string)$rule['value'][0], (string)$rule['value'][1]);
                $where[] = '(a.mtime BETWEEN ? AND ?)';
                $params[] = $start;
                $params[] = $end;
            } elseif ($rule['op'] === 'before') {
                $where[] = '(a.mtime <= ?)';
                $params[] = $this->dateEnd((string)$rule['value']);
            } else {
                $where[] = '(a.mtime >= ?)';
                $params[] = $this->dateStart((string)$rule['value']);
            }
        }

        $tagFilters = $this->extractTagFilters($query['where']);
        if ($tagFilters['include'] !== []) {
            $tagParts = [];
            foreach ($tagFilters['include'] as $tag) {
                $tagParts[] = "JSON_SEARCH(COALESCE(am.tags_json, JSON_ARRAY()), 'one', ?) IS NOT NULL";
                $params[] = $tag;
            }
            $glue = $tagFilters['mode'] === 'ANY' ? ' OR ' : ' AND ';
            $where[] = '(' . implode($glue, $tagParts) . ')';
        }
        foreach ($tagFilters['exclude'] as $tag) {
            $where[] = "JSON_SEARCH(COALESCE(am.tags_json, JSON_ARRAY()), 'one', ?) IS NULL";
            $params[] = $tag;
        }

        $folderClause = $this->assetFolderClause($query, $sqlite);
        if ($folderClause !== null) {
            $where[] = $folderClause['sql'];
            foreach ($folderClause['params'] as $p) {
                $params[] = $p;
            }
        }

        $whereSql = $where === [] ? '1=1' : implode(' AND ', $where);

        if (!empty($query['only_favorites'])) {
            return [
                'items' => [],
                'total' => 0,
                'offset' => $offset,
                'limit' => $limit,
            ];
        }

        $countRows = $maria->query(
            'SELECT COUNT(*) AS c FROM wa_assets a LEFT JOIN wa_asset_meta am ON am.asset_id = a.id WHERE ' . $whereSql,
            $params
        );
        $total = (int)($countRows[0]['c'] ?? 0);

        $orderSql = $this->assetOrder($query['sort'] ?? null);
        $rows = $maria->query(
            "SELECT a.id AS asset_id, a.rel_path, a.type, a.ext, a.mime, a.size, a.mtime, a.updated_at\n" .
            "FROM wa_assets a LEFT JOIN wa_asset_meta am ON am.asset_id = a.id\n" .
            'WHERE ' . $whereSql . ' ' . $orderSql . ' LIMIT ' . $limit . ' OFFSET ' . $offset,
            $params
        );

        $items = [];
        foreach ($rows as $row) {
            $assetId = (int)$row['asset_id'];
            $items[] = [
                'id' => -$assetId,
                'asset_id' => $assetId,
                'entity' => 'asset',
                'path' => (string)$row['rel_path'],
                'taken_ts' => (int)$row['mtime'],
                'type' => (string)$row['type'],
                'ext' => (string)$row['ext'],
                'mime' => (string)$row['mime'],
                'size' => (int)$row['size'],
                'is_favorite' => false,
            ];
        }

        return [
            'items' => $items,
            'total' => $total,
            'offset' => $offset,
            'limit' => $limit,
        ];
    }

    private function mergeResultSets(array $media, array $assets, array $query): array
    {
        $limit = (int)$query['limit'];
        $offset = (int)$query['offset'];

        $combined = array_merge($media['items'] ?? [], $assets['items'] ?? []);
        $sort = $query['sort'] ?? ['field' => 'path', 'dir' => 'asc'];
        $field = $sort['field'] ?? 'path';
        $dir = strtolower((string)($sort['dir'] ?? 'asc')) === 'desc' ? -1 : 1;

        usort($combined, function (array $a, array $b) use ($field, $dir): int {
            if ($field === 'taken') {
                $aa = (int)($a['taken_ts'] ?? 0);
                $bb = (int)($b['taken_ts'] ?? 0);
                if ($aa === $bb) {
                    return $dir * strcmp((string)($a['path'] ?? ''), (string)($b['path'] ?? ''));
                }
                return $dir * ($aa <=> $bb);
            }
            $cmp = strcasecmp((string)($a['path'] ?? ''), (string)($b['path'] ?? ''));
            if ($cmp !== 0) {
                return $dir * $cmp;
            }
            return $dir * (((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0)));
        });

        $paged = array_slice($combined, $offset, $limit);

        return [
            'items' => array_values($paged),
            'total' => (int)($media['total'] ?? 0) + (int)($assets['total'] ?? 0),
            'offset' => $offset,
            'limit' => $limit,
        ];
    }

    private function extractRequestedType(array $group): ?string
    {
        foreach ($this->flattenRules($group) as $rule) {
            if (($rule['field'] ?? null) === 'type' && ($rule['op'] ?? null) === 'is') {
                return (string)$rule['value'];
            }
        }
        return null;
    }

    private function extractExtFilters(array $group): array
    {
        $exts = [];
        foreach ($this->flattenRules($group) as $rule) {
            if (($rule['field'] ?? null) === 'ext' && ($rule['op'] ?? null) === 'is') {
                $ext = strtolower((string)($rule['value'] ?? ''));
                if ($ext !== '') {
                    $exts[$ext] = true;
                }
            }
        }
        return array_keys($exts);
    }

    private function extractPathRules(array $group): array
    {
        $rules = [];
        foreach ($this->flattenRules($group) as $rule) {
            if (($rule['field'] ?? null) === 'path' && in_array((string)($rule['op'] ?? ''), ['contains', 'starts_with'], true)) {
                $rules[] = ['op' => (string)$rule['op'], 'value' => (string)$rule['value']];
            }
        }
        return $rules;
    }

    private function extractTakenRules(array $group): array
    {
        $rules = [];
        foreach ($this->flattenRules($group) as $rule) {
            if (($rule['field'] ?? null) === 'taken') {
                $rules[] = $rule;
            }
        }
        return $rules;
    }

    private function extractTagFilters(array $where): array
    {
        $include = [];
        $exclude = [];
        $mode = 'ALL';
        $items = is_array($where['items'] ?? null) ? $where['items'] : [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (isset($item['group']) && is_array($item['items'] ?? null)) {
                $allTagIs = true;
                foreach ($item['items'] as $child) {
                    if (!is_array($child) || ($child['field'] ?? null) !== 'tag' || ($child['op'] ?? null) !== 'is') {
                        $allTagIs = false;
                        break;
                    }
                }
                if ($allTagIs) {
                    $mode = (($item['group'] ?? 'ALL') === 'ANY') ? 'ANY' : 'ALL';
                    foreach ($item['items'] as $child) {
                        $tag = trim((string)($child['value'] ?? ''));
                        if ($tag !== '') {
                            $include[] = $tag;
                        }
                    }
                }
                continue;
            }

            if (($item['field'] ?? null) === 'tag' && ($item['op'] ?? null) === 'is_not') {
                $tag = trim((string)($item['value'] ?? ''));
                if ($tag !== '') {
                    $exclude[] = $tag;
                }
            }
        }

        return [
            'mode' => $mode,
            'include' => array_values(array_unique($include)),
            'exclude' => array_values(array_unique($exclude)),
        ];
    }

    private function flattenRules(array $group): array
    {
        $out = [];
        $items = is_array($group['items'] ?? null) ? $group['items'] : [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (isset($item['group'])) {
                $out = array_merge($out, $this->flattenRules($item));
            } else {
                $out[] = $item;
            }
        }
        return $out;
    }

    private function assetFolderClause(array $query, SqliteIndex $sqlite): ?array
    {
        if (!empty($query['folder_id'])) {
            $rows = $sqlite->query('SELECT rel_path FROM directories WHERE id = ? LIMIT 1', [(int)$query['folder_id']]);
            if ($rows === []) {
                return ['sql' => '1=0', 'params' => []];
            }
            $folder = trim(str_replace('\\', '/', (string)$rows[0]['rel_path']), '/');
            if ($folder === '') {
                return null;
            }
            return [
                'sql' => '(a.rel_path LIKE ? AND a.rel_path NOT LIKE ?)',
                'params' => [
                    $this->escapeLike($folder) . '/%',
                    $this->escapeLike($folder) . '/%/%',
                ],
            ];
        }

        if (!empty($query['folder_rel_path'])) {
            $folder = trim(str_replace('\\', '/', (string)$query['folder_rel_path']), '/');
            if ($folder === '') {
                return null;
            }
            return [
                'sql' => '(a.rel_path = ? OR a.rel_path LIKE ?)',
                'params' => [$folder, $this->escapeLike($folder) . '/%'],
            ];
        }

        return null;
    }

    private function assetOrder(?array $sort): string
    {
        $field = (string)($sort['field'] ?? 'path');
        $dir = strtolower((string)($sort['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
        if ($field === 'taken') {
            return 'ORDER BY a.mtime ' . $dir . ', a.rel_path ' . $dir;
        }
        return 'ORDER BY a.rel_path ' . $dir;
    }

    private function hiddenTagsForSearch(Maria $maria, int $userId, bool $isAdmin): array
    {
        $tags = [];

        if (!$isAdmin && $this->hasGlobalHiddenColumn($maria)) {
            $globalRows = $maria->query(
                'SELECT tag FROM wa_tag_prefs_global WHERE is_hidden = 1'
            );
            foreach ($globalRows as $row) {
                $tag = (string)($row['tag'] ?? '');
                if ($tag !== '') {
                    $tags[$tag] = true;
                }
            }
        }

        $userRows = $maria->query(
            'SELECT tag FROM wa_tag_prefs_user WHERE user_id = ? AND is_hidden = 1',
            [$userId]
        );
        foreach ($userRows as $row) {
            $tag = (string)($row['tag'] ?? '');
            if ($tag !== '') {
                $tags[$tag] = true;
            }
        }

        return array_keys($tags);
    }

    private function hasGlobalHiddenColumn(Maria $maria): bool
    {
        try {
            $rows = $maria->query(
                "SELECT COUNT(*) AS c\n" .
                "FROM information_schema.columns\n" .
                "WHERE table_schema = DATABASE()\n" .
                "  AND table_name = 'wa_tag_prefs_global'\n" .
                "  AND column_name = 'is_hidden'"
            );
            return ((int)($rows[0]['c'] ?? 0)) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function dateStart(string $date): int
    {
        $tz = new \DateTimeZone(date_default_timezone_get());
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $date . ' 00:00:00', $tz);
        if ($dt === false) {
            throw new \RuntimeException('Invalid date: ' . $date);
        }
        return $dt->getTimestamp();
    }

    private function dateEnd(string $date): int
    {
        $tz = new \DateTimeZone(date_default_timezone_get());
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $date . ' 23:59:59', $tz);
        if ($dt === false) {
            throw new \RuntimeException('Invalid date: ' . $date);
        }
        return $dt->getTimestamp();
    }

    private function dateRange(string $start, string $end): array
    {
        return [$this->dateStart($start), $this->dateEnd($end)];
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload);
    }
}

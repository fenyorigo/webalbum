# Webalbum

Backend and frontend for browsing an indexer-produced SQLite database (read-only).

## Backend

- PHP 8.4, PDO, SQLite read-only
- Entry: `backend/public/index.php`
- Build output: `frontend/dist` -> `backend/public/dist`

### API

`POST /api/search`

`GET /api/health`

`GET /api/tags` (optional `?q=prefix` and `?limit=` query params)

`GET /api/tags/list` (admin list with `q`, `limit`, `offset`)

`POST /api/tags/prefs` (body: `{"tag":"...", "is_noise":0|1, "pinned":0|1}`)

Request body:

```json
{
  "where": {
    "group": "ALL",
    "items": [
      {"field": "tag", "op": "is", "value": "Alice"},
      {"field": "taken", "op": "between", "value": ["2020-01-01", "2020-12-31"]}
    ]
  },
  "sort": {"field": "taken", "dir": "desc"},
  "limit": 200
}
```

Response: array of rows with `id`, `path`, `taken_ts`, `type`.

Enable debug SQL output by appending `?debug=1` or setting `WEBALBUM_DEBUG_SQL=1`.

### CLI test

```bash
php backend/bin/search.php --db /path/to/index.db
```

More examples are in `docs/QUERY_MODEL.md`.

## Frontend (Vue 3 + Vite)

```bash
cd frontend
npm install
npm run dev
```

Build:

```bash
npm run build
```

## Config

Set `WEBALBUM_SQLITE_PATH` or edit `backend/config/config.php`.

## MySQL Tag Prefs

Run the migrations in `backend/sql/mysql/001_tag_prefs.sql` and `backend/sql/mysql/002_user_prefs.sql`.

# Webalbum

Backend and frontend for browsing an indexer-produced SQLite database (read-only).

## Release

- Current version: `1.1.2`
- See `CHANGELOG.md` for release notes.

## Backend

- PHP 8.4, PDO, SQLite read-only
- Entry: `backend/public/index.php`
- Build output: `frontend/dist` -> `backend/public/dist`

### API

`POST /api/search`

`GET /api/health`

`GET /api/tags` (optional `?q=prefix` and `?limit=` query params)

`GET /api/tags/list` (admin list with `q`, `limit`, `offset`)

`GET /api/tree/roots` (top-level indexed folders)

`GET /api/tree?parent_id=<id>` (child folders)

`POST /api/tags/prefs` (body: `{"tag":"...", "is_noise":0|1, "pinned":0|1}`)

`GET /api/media/{id}/tags`

`POST /api/media/{id}/tags` (admin only, body: `{"tags":["András","Gergely"]}`)

Request body:

```json
{
  "where": {
    "group": "ALL",
    "items": [
      {"field": "tag", "op": "is", "value": "Alice"},
      {"field": "taken", "op": "between", "value": ["2020-01-01", "2020-12-31"]}
    ],
    "folder_rel_path": "2020/2020-01-15 Budapest"
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

Set `WA_SQLITE_DB`, `WA_PHOTOS_ROOT`, `WA_THUMBS_ROOT`, `WA_TRASH_ROOT`, `WA_TRASH_THUMBS_ROOT`, `WA_THUMB_MAX`, `WA_THUMB_QUALITY`, `WA_EXIFTOOL_PATH` or edit `backend/config/config.php`.

Example:

```bash
export WA_SQLITE_DB="/Users/bajanp/Projects/images-1.db"
export WA_PHOTOS_ROOT="/Users/bajanp/Projects/indexer-test"
export WA_THUMBS_ROOT="/Users/bajanp/Projects/indexer-test-thumbs"
export WA_TRASH_ROOT="/Users/bajanp/Projects/indexer-test-trash"
export WA_TRASH_THUMBS_ROOT="/Users/bajanp/Projects/indexer-test-thumbs-trash"
export WA_THUMB_MAX="256"
export WA_THUMB_QUALITY="75"
export WA_EXIFTOOL_PATH="exiftool"
```


## System tool checks

- On backend startup/first use, Webalbum checks `ffmpeg` and `exiftool` availability and caches the result in `backend/var/external_tools_status.json`.
- Health endpoint includes tool status: `GET /api/health` (`tools` + `tools_checked_at`).
- Admin can force a refresh from UI (`Admin ▾` -> `Recheck system tools`) or via API: `POST /api/admin/tools/recheck`.
- If `ffmpeg` is missing, video thumbnail generation is disabled gracefully (placeholder thumbs are served).


## Security notes

- Media paths from SQLite are enforced to stay inside `WA_PHOTOS_ROOT` before any stream, thumbnail generation, or ZIP download.
- Requests that reference files outside `WA_PHOTOS_ROOT` are rejected.
- `GET /api/health` is admin-only.
- Login throttling is enabled on `/api/auth/login` (per IP + username) and emits `auth_throttle` audit events when blocked.
- Session cookie flags are hardened: `HttpOnly`, `SameSite=Lax`, and `Secure` is forced in production (`WEBALBUM_ENV=prod` or `APP_ENV=production`) or when HTTPS / `X-Forwarded-Proto=https` is detected.
- Deployment expectation: terminate TLS in front of app and forward `X-Forwarded-Proto` correctly when using reverse proxies.

## MySQL Tag Prefs

Run the migrations in `backend/sql/mysql/001_tag_prefs.sql` and `backend/sql/mysql/002_user_prefs.sql`.

## Users MVP

- Create users with `backend/migrations/001_users.sql` then apply `backend/sql/mysql/006_users_auth.sql` for auth fields.
- For first-time setup, visit `/setup` and create the admin account (only available when `wa_users` is empty).
- To add users, use the admin endpoints or insert manually into `wa_users`.
- Seeded users in `backend/sql/mysql/003_seed_users.sql` use the password `changeme1234`.

## Favorites

- Create table via `backend/migrations/002_favorites.sql` or `backend/sql/mysql/004_favorites.sql`.
- Favorites are per-user and require a logged-in user.

## Auth & Setup

- Login endpoints: `POST /api/auth/login`, `POST /api/auth/logout`, `GET /api/auth/me`.
- Setup endpoints: `GET /api/setup/status`, `POST /api/setup` (one-time).
- Setup creates `backend/var/setup.lock` to prevent re-running.
- User prefs: apply `backend/sql/mysql/007_user_prefs.sql` and edit in “My Profile”.
- Strong password rules: min 12 chars, upper/lower/number/special.
- Admin-set passwords can be 8+ chars and force a change on first login.
- Password changes require current password and clear the force-change flag.
- Admin user management: open the “Admin ▾” menu and use “User management”.
- Audit log: apply `backend/sql/mysql/008_audit_log.sql` and `backend/sql/mysql/009_audit_log_index.sql`.

## Saved searches

- Create table via `backend/sql/mysql/005_saved_searches.sql`.
- Use the Search page “Save search” button to store the current query.
- Manage saved searches from the “Saved searches” page (run, rename, delete).

## Trash

- Apply migration `backend/sql/mysql/011_media_trash.sql`.
- If upgrading an existing table, run `backend/sql/mysql/012_media_trash_hash.sql` (fallback: `backend/sql/mysql/012_media_trash_hash_fallback.sql`).
- Admin endpoints: `POST /api/admin/trash`, `GET /api/admin/trash`, `POST /api/admin/trash/restore`, `POST /api/admin/trash/purge`, `POST /api/admin/trash/empty`, `GET /api/admin/trash/thumb?id=...`.
- Bulk restore/purge accepts `{"trash_ids":[...]}` (single-item remains `{"trash_id":...}`).
- Maintenance endpoint: `POST /api/admin/maintenance/clean-structure` (removes empty folders with trash blocker rules).
- Admin UI: “Admin ▾” → “Trash”.
- Trashed media is excluded from search and favorites until restored.

## Audit logs

- Admins can open “Admin ▾” → “View logs”.
- Example API call:

```bash
curl -b cookies.txt "http://localhost:8445/api/admin/audit-logs?page=1&page_size=50"
```

## Media Tag Editing

Testing checklist:

- Admin can open viewer, click `Edit Tags`, add/remove tags, and save.
- Non-admin users do not see `Edit Tags`.
- Saving an empty list clears IPTC/XMP person tag fields.
- After save, searching by a newly added tag returns that media (SQLite update applied).
- Audit log contains `media_tags_update` with old/new tags.

## Thumbnails On Demand

Testing checklist:

- Run a search returning images.
- Confirm thumbs directory is created under `WA_THUMBS_ROOT` and mirrors `rel_path`.
- Confirm first request generates thumbs, subsequent requests reuse them.
- Confirm if an image is modified, thumb regenerates (mtime check).
- Confirm it works on mac and Fedora where absolute paths differ (fallback to `WA_PHOTOS_ROOT + rel_path`).


## Tree API examples

```bash
# Top-level folders
curl -b cookies.txt "http://localhost:8445/api/tree/roots"

# Children of a folder
curl -b cookies.txt "http://localhost:8445/api/tree?parent_id=42"

# Search filtered to a folder subtree
curl -b cookies.txt -H "Content-Type: application/json" \
  -X POST "http://localhost:8445/api/search" \
  --data '{"where":{"group":"ALL","items":[{"field":"type","op":"is","value":"image"}],"folder_rel_path":"2020/2020-01-15 Budapest"},"sort":{"field":"path","dir":"asc"},"limit":50,"offset":0}'
```


### Path containment regression check

```bash
php backend/bin/path_guard_check.php
```

This check verifies that an outside path such as `/etc/passwd` is rejected by the shared path guard used by file/thumb/download endpoints.

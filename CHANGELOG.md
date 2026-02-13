# Changelog

## 1.1.1 - 2026-02-13

- Enforce WA_PHOTOS_ROOT path containment in file/thumb/download endpoints; harden health endpoint; add login throttling and stricter session cookie handling.

## 1.1.0 - 2026-02-13

- Added folder tree view for browsing the gallery
- Folder selection filters media by subtree
- Folder browsing integrates with existing search builder
- Direct media display respects media type filter (Any / Image / Video)
- Improved navigation for large archives

## 1.0.0 - 2026-02-12

Production release.

### Added
- Session-based authentication and one-time setup/admin bootstrap.
- Admin user management and audit log viewer with filters and paging.
- Saved searches (save, replace, load, rename, delete).
- Per-user favorites and favorites-only search mode.
- Thumbnails on demand for images and videos.
- Grid/List result views, pagination, and image/video overlay viewers.
- Media tag editing (admin) with ExifTool + immediate SQLite sync.
- Admin trash workflow with move/restore/purge/empty, trash thumbnails, and bulk actions.
- Maintenance endpoint to clean empty directory structure with trash blocker rules.

### Changed
- Search API returns paged response (`items`, `total`, `offset`, `limit`).
- Default list limit set to 50.
- Admin/tag/media/user/trash/maintenance actions are logged to `wa_audit_log`.

### Security
- Enforced admin-only access for admin endpoints.
- Added path-safety checks for file, video, thumb, and trash operations.

# Changelog

## Unreleased

## 1.4.3 - 2026-02-19

- Tags admin: added `Images` column showing per-tag image count.
- Tags admin: added sorting for `Tag` and `Images` columns.

## 1.4.2 - 2026-02-18

- Added Tags CSV export for admins with People/People|* tags excluded.
- Moved tag export action to the Tags admin page and restored Tags in the Admin menu.
- Moved "Re-enable all tags" from Admin dropdown into the Tags admin page.
- Fixed tag export CSV formatting: no PHP deprecation output, always-quoted tag names, and Unix LF line breaks.

## 1.4.1 - 2026-02-16

- Fixed ambiguous duplicate results when filtering by `ext` (e.g. `pdf`) by treating extension-filtered searches as assets-only.
- Added Required tools checks/display for `imagemagick`, `pecl`, and PHP `imagick` extension.
- Added worker deployment templates under `backend/deploy/systemd` and documented Fedora timer/service setup.
- Updated assets worker batch mode so `--max-jobs=N` exits cleanly when the queue is empty.

## 1.4.0 - 2026-02-16

- Completed assets handling (video, documents) with Admin Assets workflow.
- Added documents/audio asset indexing, async derivative jobs, and worker processing.
- Added admin scan/status UX with pending/running/ready/no-processing/failed split and clear-list cleanup.
- Added queue split by job type (`doc_thumb` vs `doc_pdf_preview`) in admin status views.
- Added support for `ppt` and `pptx` document conversion/preview/thumbnail flow.
- Merged doc/audio folders into the sidebar folder tree for browsing/search.

## 1.3.0 - 2026-02-16

- Renamed UI branding from "Webalbum" to "Family memories" (login + main header/title).
- Improved Admin Assets page with automatic refresh and sortable Thumb/Preview status columns.
- Improved asset status display in Admin Assets (audio rows shown as N/A instead of pending).
- Enabled selecting and downloading audio/document assets from search results (still max 20 files per ZIP).

## 1.2.0 - 2026-02-15

- Robust video thumbnail generation
- Fixed a race condition and cache poisoning issue where interrupted video thumbnail generation could permanently store placeholders. Thumbnail generation is now atomic, validated, retry-safe, and resilient to user navigation and session termination.

## 1.1.2 - 2026-02-15

- Changed method of loading env variables.

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

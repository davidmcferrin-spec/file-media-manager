# Changelog

All notable changes to Media Manager are documented here.

Format follows [Keep a Changelog](https://keepachangelog.com/). Versioning follows [SemVer](https://semver.org/):

- **patch** (`0.1.x`) — bug fixes
- **minor** (`0.x.0`) — new features (backward compatible)
- **major** (`x.0.0`) — breaking / behavior-changing releases

## Release process

1. Bump the version in `VERSION`
2. Add a `## [x.y.z] — YYYY-MM-DD` section below (newest first)
3. Deploy

## [0.4.2] — 2026-07-30

### Added

- Catalog bulk edit: select files and set show, Clean/Program (media type), and/or date — proposed path/name rebuilds automatically; each file keeps its time

## [0.4.1] — 2026-07-30

### Added

- Export scan classification spreadsheet (XLSX) from a scan job — path, show, date/time, proposed names, confidence, etc.

## [0.4.0] — 2026-07-30

### Added

- Workflow-first Home with Setup → Scan → Catalog ↔ Gaps → Execute strip and readiness chips
- Top nav regroup: Setup dropdown, Pipeline labels (Catalog/Gaps/Scan/Execute), Admin dropdown (Audit/Rollback)
- Step chrome on Shows, Timeline, Scan, Catalog, Gaps, Execute
- Settings → Processing for split-flag duration (system_settings; .env is fallback only)
- Settings → Danger Zone wipe for scan/catalog/shows/timeline data + thumbnail/preview caches (keeps users/settings; requires `ALLOW_DB_WIPE=true`)

### Changed

- Library Analytics copy clarifies content hours and chart utility
- Dictionary / Program Schedule / Queue / Show Audit labels → Shows / Timeline / Catalog / Gaps

## [0.3.0] — 2026-07-30

### Added

- Full **Rescan** on finished scan jobs — re-walks the same source/path, reclassifies pending/flagged/rejected files, queues newly found files; approved/executed left unchanged

## [0.2.0] — 2026-07-30

### Added

- Queue unapprove (APPROVED → PENDING) for single files and batch selection
- Remove files from the review queue (PENDING / FLAGGED / REJECTED / APPROVED; disk untouched)
- Reclassify Files on finished scan jobs — re-runs classifier in place; leaves approved/executed alone
- Merge Shows on the Dictionary page (same merge as Program Schedule)

## [0.1.0] — 2026-07-30

### Added

- Review queue with approve / reject / edit, thumbnails, and on-demand video preview
- NAS scanner with job control, legacy rename map import/apply, and split flagging
- Execute and rollback for approved moves; audit log
- Show dictionary, program schedule (CSV and XLSX import with Excel serial date conversion)
- Show audit / completeness tooling against Timeline schedule expectations
- Dashboard library stats; settings for users, media types, and conversion rules
- App version display in the footer with a Versions page backed by this changelog

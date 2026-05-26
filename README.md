# file-media-manager

Media file sorting, renaming, and organization tool for NewsNation broadcast archives.
Processes legacy video/media files across NAS servers, classifies them against a
naming/folder policy, and moves/renames them only after human approval.

## Features

- **Scan** — crawl NAS mount points, extract metadata via FFprobe
- **Classify** — pattern-match filenames/paths against show dictionary and naming policy
- **Review Queue** — paginated approval workflow (approve / edit / reject / flag)
- **Thumbnail preview** — on-demand FFmpeg frame at 50s for any video file
- **Technical metadata** — codec, resolution, framerate, duration, filesize per file
- **Split flagging** — mark files > 1 hour for splitting with per-segment show annotations
- **Execute** — atomic rename + move of all approved files
- **Rollback** — per-file or batch undo of any executed rename/move
- **Audit log** — every action logged with user, IP, timestamp, before/after paths
- **Dictionary** — web-managed show abbreviations with multi-alias matching
- **User management** — admin and editor roles, bcrypt passwords

## Stack

- PHP 8.2+ / Apache 2.4 / Debian 13
- Bootstrap 5 UI (vendored under `public/vendor/`, no CDN dependency)
- PostgreSQL 14+
- FFmpeg + FFprobe

## Stack

- Apache HTTP Server or Nginx + PHP-FPM
- PHP 8.2+
- PostgreSQL 14+
- Bootstrap 5 UI (vendored under `public/vendor/`, no CDN dependency)

## Architecture

- `public/index.php` — front controller
- `public/vendor/` — pinned Bootstrap and FullCalendar bundles (see `public/vendor/VERSIONS.txt`)
- `src/bootstrap.php` — autoload + env + config bootstrap
- `src/App.php` — router and dependency wiring
- `src/Controllers` — route handlers
- `src/Repositories` — persistence layer
- `src/Services` — schedule/template/conflict/placeholder/export logic
- `src/Auth` — auth, LDAP, rate limiting
- `src/Views` — UI templates
- `sql/migrations` — versioned SQL migrations
- `sql/seed.postgresql.sql` — baseline seed data

## NAS Sources

| Name | Mount Path |
|------|-----------|
| NY Linear | `/mnt-smb/SNSEVO-NYL` |
| Chicago Linear | `/mnt-smb/SNSEVO-CHL` |

## Naming Policy

**Folder structure** (3 levels deep):
```
/SHOW_ABBR/YYYY/MM/MediaType/
```

**Filename format:**
```
SHOW_ABBR_YYYYMMDD_HHMM_MEDIATYPE.ext
```

**GISO files get a guest name field:**
```
OB_20240711_1300_GISO_Bill_Oreilly.mxf
```

Rules:
- No spaces — underscores only
- Date: `YYYYMMDD`
- Time: `HHMM` 24-hour Eastern
- MediaType: `Clean`, `Program`, `ISO`, `GISO`, `RAW`

## Quick Start

```bash
git clone git@github.com:YOUR_ORG/file-media-manager.git
cd file-media-manager
cp .env.example .env
# Edit .env with your settings
./setup.sh
```

## Roles

| Role | Capabilities |
|------|-------------|
| admin | Full access — scan, execute, rollback, audit, users, dictionary |
| editor | Queue review — approve, reject, edit, flag only |

## Project Structure

```
public/             Web root — index.php front controller + vendored assets
src/
  Auth/             Authentication and session management
  Controllers/      Route handlers (one per module)
  Repositories/     Database access layer
  Services/         Classifier, Executor, FFprobe, Thumbnail, Rollback
  Views/            PHP templates
sql/migrations/     Versioned SQLite schema migrations
storage/
  thumbnails/       FFmpeg-generated preview frames
  logs/             Application logs
  backups/          DB backups
data/               SQLite database
tests/              Unit tests (classifier, date normalizer)
```

## Progress

- [x] Phase 1 — Foundation (schema, auth, layout)
- [ ] Phase 2 — Dictionary + Sources
- [ ] Phase 3 — Scanner + Classifier
- [ ] Phase 4 — Review Queue + Execute
- [ ] Phase 5 — Audit + Admin + Split Queue

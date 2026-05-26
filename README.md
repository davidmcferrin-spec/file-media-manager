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

- Apache HTTP Server + PHP 8.2+ on Debian 13
- PostgreSQL 14+
- Bootstrap 5 UI (vendored under `public/vendor/`, no CDN dependency)
- FFmpeg + FFprobe

## Architecture

- `public/index.php` — front controller
- `src/bootstrap.php` — autoload + env + config bootstrap
- `src/Controllers/` — route handlers
- `src/Repositories/` — persistence layer
- `src/Services/` — classifier, executor, FFprobe, thumbnail, rollback
- `src/Auth/` — auth and rate limiting
- `src/Views/` — UI templates
- `scripts/migrate.php` — PostgreSQL migration runner
- `sql/migrations/` — versioned SQL migrations

Architecture and database patterns follow [studio-calendar](https://github.com/davidmcferrin-spec/studio-calendar).

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
# Edit .env — set DB_NAME, DB_USER, DB_PASSWORD
sudo ./setup.sh
```

For local development without full setup:

```bash
cp .env.example .env
# Provision PostgreSQL role/database, then:
php scripts/migrate.php
php scripts/test.php          # run unit tests
php scripts/scan.php --job-id=1  # run a scan job from CLI
```

**Dev scan mode:** On the Scanner page, check "Dev mode" to classify files from `example_file_trees/SNSEVO-NY_Legacy_files.txt` without a NAS mount. Use subpath `cuomo` for the pilot.

## Co-deploying with Studio Calendar

On a shared server, **Studio Calendar** typically listens on port **80** and **Media Manager** on port **81**. After setup, access Media Manager at `http://<server>:81/dashboard`. Ensure `APP_URL` in `.env` includes the port (e.g. `http://your-server:81`).

## Roles

| Role | Capabilities |
|------|-------------|
| admin | Full access — scan, execute, rollback, audit, users, dictionary |
| editor | Queue review — approve, reject, edit, flag only |

## Project Structure

```
public/             Web root — index.php front controller + vendored assets
scripts/            migrate.php — PostgreSQL migration runner
src/
  Auth/             Authentication and session management
  Controllers/      Route handlers (one per module)
  Repositories/     Database access layer
  Services/         Classifier, Executor, FFprobe, Thumbnail, Rollback
  Views/            PHP templates
sql/migrations/     Versioned PostgreSQL schema migrations
storage/
  thumbnails/       FFmpeg-generated preview frames
  logs/             Application logs
  backups/          DB backups
tests/              Unit tests (classifier, date normalizer)
example_file_trees/ Sample NAS directory listings for development
```

## Progress

- [x] Phase 1 — Foundation (schema, auth, layout)
- [x] Phase 2 — Dictionary + Sources
- [x] Phase 3 — Scanner + Classifier
- [x] Phase 4 — Review Queue + Execute
- [x] Phase 5 — Audit + Admin + Split Queue

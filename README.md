# file-media-manager

Media file sorting, renaming, and organization tool for NewsNation broadcast archives.
Processes legacy video/media files across NAS servers, classifies them against a
naming/folder policy, and moves/renames them only after human approval.

## Features

- **Scan** — crawl NAS mount points (or a dev file list), classify, FFprobe metadata; background systemd worker drains the queue
- **Classify** — show dictionary, timeline, conversion rules, continuity check (optional local LLM)
- **Catalog** — paginated review (approve / edit / reject / flag), thumbnails + WebM preview
- **Captions / CC** — detect caption streams; background extract to `.srt` sidecars; Catalog viewer; priority cue
- **Glue** — detect multipart sets, queue ffmpeg concat, QC, then delete source parts
- **Split** — flag long files; workbench mark in/out; caption-based segment suggest; export handle policy
- **Gaps / Show audit** — completeness vs Timeline
- **Execute / Rollback** — atomic rename+move of APPROVED files; per-file or batch undo
- **Audit log** — every action with user, IP, before/after paths
- **Dictionary / Timeline / Settings** — shows, schedule eras, LDAP or local users

## Stack

- Apache HTTP Server + PHP 8.2+ on Debian 13
- PostgreSQL 14+
- Bootstrap 5 UI (vendored under `public/vendor/`, no CDN)
- FFmpeg + FFprobe
- systemd workers for Scan + Caption extract

## Architecture

```
public/index.php              Front controller
src/bootstrap.php             Autoload + env
src/Controllers/              Route handlers
src/Repositories/             DB access (no inline SQL elsewhere)
src/Services/                 Scan, Classifier, Glue, Captions, Execute, …
src/Views/                    PHP templates
scripts/migrate.php           Migration runner
scripts/scan_worker.php       Long-running scan queue daemon
scripts/caption_extract_worker.php  Long-running CC extract daemon
deploy/systemd/               Unit files installed by setup.sh
sql/migrations/               Versioned schema
storage/media/                Derived assets by file ULID (thumbs, previews, split)
storage/logs/                 App + worker logs
```

Architecture patterns follow [studio-calendar](https://github.com/davidmcferrin-spec/studio-calendar).

## NAS Sources

| Name | Mount Path |
|------|-----------|
| NY Linear | `/mnt-smb/SNSEVO-NYL` |
| Chicago Linear | `/mnt-smb/SNSEVO-CHL` |

## Naming Policy

**Folder:** `/SHOW_ABBR/YYYY/MM/MediaType/`  
**File:** `SHOW_ABBR_YYYYMMDD_HHMM_MEDIATYPE.ext`  
**GISO:** `SHOW_ABBR_YYYYMMDD_HHMM_GISO_Guest_Name.ext`

No spaces (underscores only). Date `YYYYMMDD`, time `HHMM` Eastern.

## Quick Start

```bash
git clone git@github.com:YOUR_ORG/file-media-manager.git
cd file-media-manager
cp .env.example .env
# Edit .env — set DB_NAME, DB_USER, DB_PASSWORD
sudo ./setup.sh
```

`setup.sh` installs Apache, migrates the DB, and enables:

- `media-manager-scan.service`
- `media-manager-caption-extract.service`

For local development without systemd:

```bash
cp .env.example .env
# Set WORKER_MODE=spawn so the web UI forks one-shot workers
php scripts/migrate.php
php scripts/test.php
```

**Dev scan mode:** Scanner page → “Dev mode” uses `example_file_trees/` without a NAS mount.

## Background workers (Scan + CC extract)

These are **true daemons** — no CLI babysitting. The web UI only enqueues jobs (`PENDING`); workers poll and process.

| Unit | Script | What it drains |
|------|--------|----------------|
| `media-manager-scan` | `scripts/scan_worker.php` | Scan jobs (pending / paused / failed / orphaned) |
| `media-manager-caption-extract` | `scripts/caption_extract_worker.php` | Caption extract jobs |

```bash
# Status / logs
systemctl status media-manager-scan media-manager-caption-extract
journalctl -u media-manager-scan -f
journalctl -u media-manager-caption-extract -f

# Also written under the app:
#   storage/logs/scan-worker.log
#   storage/logs/caption-extract-worker.log
#   storage/logs/caption-extract-{jobId}.log   (per-job detail)
#   storage/logs/scan-{jobId}.log             (legacy one-shot spawn)
```

**Config (`.env`):**

| Key | Default | Meaning |
|-----|---------|---------|
| `WORKER_MODE` | `daemon` | `daemon` = systemd polls queue; `spawn` = web forks PHP per job |
| `WORKER_POLL_SECONDS` | `5` | Idle sleep between queue polls |
| `CAPTION_EXTRACT_TIMEOUT_SECONDS` | `900` | Per-file FFmpeg timeout |

**Operator flow**

1. **Scan:** Admin → Scan → Start → job appears PENDING → scan worker runs it  
2. **CC:** Admin → Captions → Start extract, or Catalog → Extract CC → caption worker runs it  
3. Cancel/stop uses cooperative `cancel_requested` (does **not** kill the systemd process)

**Manual one-shot (debug):**

```bash
php scripts/scan.php --job-id=N
php scripts/scan.php                 # next pending once
php scripts/caption_extract.php --job-id=N
```

**Bulk probe CC badges (one-time backfill, no SRT extract):**

```bash
php scripts/probe_captions.php --dry-run   # count unprobed files
php scripts/probe_captions.php             # enqueue for caption-extract worker
php scripts/probe_captions.php --run       # enqueue + run in this process
```

Watch progress at `/captions/{id}` or `storage/logs/caption-extract-{id}.log`.

## Co-deploying with Studio Calendar

Studio Calendar typically on port **80**, Media Manager on **81**. Set `APP_URL` including the port.

## Roles

| Role | Capabilities |
|------|-------------|
| admin | Scan, execute, rollback, audit, users, dictionary, captions, glue execute, split |
| editor | Catalog review — approve, reject, edit, flag; glue mark/clear |

## User workflow

1. Setup: Shows → Timeline (admin)  
2. Ingest: Scan  
3. Review: Catalog ↔ Gaps; Captions / Glue / Split as needed  
4. Commit: Execute  
5. Support: Settings; Admin: Audit / Rollback  

## Versioning

- `VERSION` — semver shown in the footer  
- `CHANGELOG.md` — release notes at `/versions`  

## Progress

- [x] Foundation, dictionary, scan, catalog, execute  
- [x] Audit, split queue, continuity, LDAP  
- [x] Glue concat + QC  
- [x] Captions extract (background) + priority cue  
- [x] Split workbench / Hybrid C media  
- [x] systemd scan + caption workers  

# file-media-manager — Claude Project Context

## Purpose
NewsNation media file sorting, renaming, and organization tool.
Processes thousands of legacy video/media files across two NAS servers,
classifies them against a naming/folder policy, and moves/renames them
only after human approval via a web-based review queue.

## Stack
- PHP 8.2+ (strict_types=1 everywhere)
- Bootstrap 5 vendored (no CDN — must work on isolated broadcast network)
- PostgreSQL 14+
- FFmpeg + FFprobe (metadata, thumbnails, previews, glue concat, caption extract)
- Apache 2.4 on Debian 13
- Session-based auth (bcrypt local + optional LDAP)
- systemd: `media-manager-scan`, `media-manager-caption-extract`

## Architecture
```
public/index.php        Front controller — all requests route through here
src/bootstrap.php       Autoload + env + config
src/Auth/               Auth, Session, rate limiting, LDAP
src/Controllers/        One controller per module
src/Repositories/       All DB access — no inline SQL in controllers/views
src/Services/           Scan, Classifier, Glue, Captions, Executor, Rollback,
                        FFprobe, Thumbnail, Preview, Continuity, Split media, …
src/Views/              PHP templates only — no logic beyond display
src/Support/            View helpers, AppVersion, WorkerMode
public/js/live-poll.js  Shared JSON status poller (job detail + queue list pages)
VERSION                 App semver (displayed in footer)
CHANGELOG.md            Release notes (shown on /versions)
scripts/migrate.php     Versioned PostgreSQL migration runner
scripts/scan_worker.php              Long-running scan queue daemon
scripts/caption_extract_worker.php   Long-running CC extract daemon
scripts/scan.php                     One-shot scan (CLI / legacy spawn)
scripts/caption_extract.php          One-shot caption job (CLI / legacy spawn)
deploy/systemd/         Unit files (installed by setup.sh)
deploy/sbin/            media-manager-svc allowlisted systemctl/journalctl helper
deploy/sudoers/         www-data NOPASSWD drop-in for Services UI
sql/migrations/         Versioned PostgreSQL migrations (001_, 002_, etc.)
storage/media/          Derived assets sharded by files.public_id (ULID): aa/bb/cc/{ulid}/
storage/thumbnails/     Legacy flat thumbs (read fallback; new writes use storage/media)
storage/tmp/            FFmpeg concat lists, short-lived temp
storage/logs/           Application + worker logs
```

## NAS Mount Points
- NY Linear: /mnt-smb/SNSEVO-NYL
- Chicago Linear: /mnt-smb/SNSEVO-CHL

## Naming Policy (target state)
- Folder: /SHOW_ABBR/YYYY/MM/MediaType/
- File: SHOW_ABBR_YYYYMMDD_HHMM_MEDIATYPE.ext
- GISO: SHOW_ABBR_YYYYMMDD_HHMM_GISO_Guest_Name.ext
- No spaces — underscores only
- Date: YYYYMMDD, Time: HHMM 24hr Eastern

## Roles
- admin — full access: scan, execute, rollback, audit, services, user mgmt, dictionary,
  captions jobs, glue execute, split
- editor — queue review only: approve, reject, edit proposed name/path, flag;
  glue mark/clear

## Key Rules
- NOTHING moves on disk without file status = APPROVED + explicit execute action
- Every disk operation is written to audit_log before AND after execution
- All thumbnails/previews generated on-demand (not at scan time)
- Files at or above the split flag duration (Settings → Processing; default 2 hours)
  are flagged needs_split = true; multi-hour schedule spans also flag
- Split workbench suggest: captions (SRT silence gaps) or audio (FFmpeg silencedetect;
  long quiet = program gaps; short dips = ads; continuous multi-hour → schedule hours)
- Split workbench audio lane: Quiet/Low/Dialog/Hot blocks from cached RMS map
  (`audio_levels.json`; Load audio levels or seeded by Suggest from audio)
- Bootstrap vendored under public/vendor/ — no external requests
- No inline SQL — use Repository classes
- No framework — vanilla PHP, PSR-4 autoloading
- Background Scan + Caption extract + Split audio: web enqueues only; systemd
  workers poll (`WORKER_MODE=daemon`). Do not SIGKILL the daemon PID on cancel —
  use cooperative `cancel_requested` (aborts Continuity HTTP mid-flight).
  UI Force stop may SIGTERM the job worker PID; systemd restarts the idle daemon.
  Local/dev may set `WORKER_MODE=spawn`.

## Background workers
| Unit | Drains |
|------|--------|
| media-manager-scan | scan_jobs PENDING/PAUSED/FAILED/orphaned RUNNING |
| media-manager-caption-extract | caption_extract_jobs same statuses |
| media-manager-split-audio | split_audio_jobs (levels / suggest) |

Logs: `journalctl -u <unit> -f` and `storage/logs/*-worker.log`.
Per caption job: `storage/logs/caption-extract-{id}.log`.
Per split-audio job: `storage/logs/split-audio-{id}.log`.
Env: `WORKER_MODE`, `WORKER_POLL_SECONDS`, `CAPTION_EXTRACT_TIMEOUT_SECONDS`.

Admin → **Services** (`/services`): live unit status + journal tail + start/stop/restart/enable/disable
for app workers (Apache/PostgreSQL: status + restart/reload only). Privileged ops go through
`/usr/local/sbin/media-manager-svc` + `/etc/sudoers.d/media-manager` (installed by `setup.sh`).

## Code Style
- strict_types=1 on every PHP file
- snake_case for DB columns and PHP variables
- PascalCase for classes
- Repository pattern for all DB access
- Views receive only pre-fetched data arrays — no DB calls in templates
- CSRF token on every POST form
- All user output escaped via View::e()

## Testing
- Run php -l on all files before committing
- Key classifier / glue / caption logic covered by unit tests in tests/

## App versioning
- Single source of truth: root `VERSION` (semver)
- Release notes: root `CHANGELOG.md` (`## [x.y.z] — YYYY-MM-DD`, newest first)
- Shown in the site footer as `vX.Y.Z` linking to `/versions`
- On each release-worthy change set: bump `VERSION`, add a changelog section, deploy
- Convention: patch = fixes; minor = features; major = breaking/behavior changes

## User workflow (IA)
- Setup: Eras (network windows) → Shows (identity + slots) → Timeline import/hygiene (admin)
- Queues dropdown: Catalog, Glue; admin also Scan, Captions, Split, Execute
- Ingest: Scan (worker processes queue)
- Review loop: Catalog ↔ Gaps; Captions / Glue / Split as needed
- Commit: Execute
- Support: Settings; Admin menu: Services / Audit / Rollback
- Queue list pages poll JSON (`/*/list-status`) — never full-page refresh while checkboxes are selected
- Split flag / strong durations: Settings → Processing (`system_settings`; default 2h / 3h);
  `.env` is fallback only

## Related Project
Architecture and PostgreSQL patterns follow `studio-calendar` (davidmcferrin-spec/studio-calendar).

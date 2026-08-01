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

## [0.15.0] — 2026-08-01

### Added

- **Scan job timing**: Started / Ended / Elapsed + live ETA (files/min) on job detail and list
- **Delete caption extract jobs** (job row + log); does not undo extracted SRTs
- **Force-delete hung jobs**: when status is `RUNNING` but worker PID is dead — Scan and Captions (`Force delete (hung)` / hung badge)
- PENDING scan/caption jobs can be deleted before a worker claims them

## [0.14.1] — 2026-08-01

### Added

- **`scripts/probe_captions.php`** — one-shot CLI to enqueue (or `--run`) a `probe_only` job that FFprobes all unprobed catalog files for Catalog CC badges without clicking each row

## [0.14.0] — 2026-08-01

### Added

- **systemd queue workers** for Scan and Caption extract (no CLI babysitting)
  - `media-manager-scan.service` → `scripts/scan_worker.php` (poll loop)
  - `media-manager-caption-extract.service` → `scripts/caption_extract_worker.php`
  - `setup.sh` installs, enables, and starts both units
  - Env: `WORKER_MODE=daemon` (default) / `spawn` for local one-shot forks; `WORKER_POLL_SECONDS`
  - Cooperative cancel only in daemon mode (does not kill the long-running process)
  - Scan full-rescan flag: migration `023_scan_force_rescan` (`force_rescan`) so the daemon can run rescans without `--rescan`
- README + CLAUDE.md brought current (workers, Captions, Glue, Split, Continuity, LDAP)

### Changed

- Web UI enqueues Scan / Caption jobs when `WORKER_MODE=daemon`; workers drain the queue
- Caption extract `claimNextPending` for daemon pickup (including orphaned RUNNING)

## [0.14.0] — 2026-08-01

### Added

- **Catalog CC badges always visible** with clear states:
  - Grey `CC?` — not probed yet
  - Orange `CC` — captions/subtitles detected
  - Green `CC` — SRT extracted
  - Muted struck `CC` — probed, no stream found
- `captions_probed` column + Captions job scope **Probe CC badges only** (fast FFprobe, no FFmpeg extract)
- Catalog legend + Extract CC available for any file missing SRT (not only already-flagged CC)
- Migration `024_captions_probed`

## [0.13.0] — 2026-08-01

### Added

- **File asset ULID (`public_id`)** for derived media cache pathing and UI
  - Migration `022_file_public_id`; assigned on scan/ingest; backfilled by `scripts/migrate.php`
  - Sharded app cache: `storage/media/aa/bb/cc/{ULID}/` (`thumb.jpg`, `preview.webm`, `split/…`)
  - Catalog, split, glue, and preview meta show Asset ID + cache path
  - NAS originals and deliverable video outputs are unchanged (still on NAS policy paths)
  - Env: `STORAGE_MEDIA` (default `storage/media`); legacy thumb/preview/split dirs remain as read fallback

## [0.12.0] — 2026-08-01

### Added

- **Caption extract cue priority**: select one or many clips and **Move to top**
  - Job page lists prioritized + upcoming candidates with multi-select
  - Worker drains the priority lane before normal id order (re-checks between files)
  - Catalog **Extract CC** while a job is active bumps selected clips to the top of that job
  - Migration `021_caption_extract_priority` (`priority_file_ids`)

## [0.11.0] — 2026-08-01

### Added

- **Split export handle policy**: export will include up to **5 minutes before Mark In** and **5 minutes after Mark Out** (clamped to file edges)
  - `SplitExportPolicy` encodes the rule for future cut/export (ffmpeg or Vantage)
  - Workbench callout + per-segment “Export will cut …” preview so operators mark the **show only** and do not pad manually

## [0.10.0] — 2026-08-01

### Added

- **Background caption extract** for large catalogs (~tens of thousands of files)
  - Admin **Captions** page: start jobs by scope (`missing_srt` or `has_captions`)
  - Catalog **Extract CC** enqueues a selected-scope job and opens the job page
  - Live progress with **duration-weighted ETA** (longer files weigh more)
  - Hang warning when a file runs >20 minutes
  - Detailed per-job log: `storage/logs/caption-extract-{id}.log` (START/OK/SKIP/FAIL with path, wall time, FFmpeg tail, stack traces)
  - CLI worker: `php scripts/caption_extract.php --job-id=N`
  - Per-file timeout via `CAPTION_EXTRACT_TIMEOUT_SECONDS` (default 900)
  - Migration `020_caption_extract_jobs`

## [0.9.0] — 2026-08-01

### Added

- **Split Hybrid C media**: workbench scrubber + play for the real library mix (MP4/H.264, TS, MXF, MPEG-2, DNxHD, ProRes)
  - Timeline click/drag → ffmpeg **frame peek** (cached under `storage/split-proxy/`)
  - **Set In / Set Out** (keys `I` / `O`) apply to the active segment
  - **Play** loads a ~45s window: stream-copy fast path for MP4/H.264, H.264/AAC proxy for TS/MXF/etc.
  - WMV explicitly unsupported
- Endpoints `/split/media/{jobId}/frame` and `/split/media/{jobId}/play` (Range-aware MP4)
- Settings wipe also clears split proxy cache

### Notes

- Cut execute (ffmpeg or Telestream Vantage) still deferred — this is review/marking only

## [0.8.0] — 2026-08-01

### Added

- **Split workbench**: redesign `/split/{id}` as a review workspace for mark in/out across N segments (one per show/output)
- Queue **Back / Next** navigation with position (`3 / 47`), optional status filter preserved from the Split Queue list
- **Save & Next** to keep reviewing without returning to the list; keyboard ← / → when not in a form field
- Per-segment derived **air date/time** from file clock + mark in; HH:MM:SS.mmm mark inputs; visual timeline of segments

## [0.7.0] — 2026-08-01

### Added

- **Glue concat execute**: queue multipart groups on `/glue`, run ffmpeg stream-copy concat (`*_GLUED.ext`), QC approve/reject with duration check, then delete source parts from disk + catalog
- `glue_queue` table (migration `019_glue_queue`) with job statuses through DONE; audit events for queue/run/QC/source delete
- Glue job detail page with per-step admin actions; Catalog still used to mark/clear groups
- **Captions / CC**: FFprobe detects subtitle/caption streams; Catalog shows orange **CC** (present) or green **CC** (SRT extracted)
- Extract captions to an `.srt` sidecar beside the media (Execute moves it with other sidecars); View SRT modal in Catalog
- Split job **Suggest from captions** — uses ≥5 min caption silence gaps (ignores commercial-length gaps) near hour boundaries to tighten in/out

### Changed

- Sidecar extensions now include `srt` and `vtt`

## [0.6.0] — 2026-07-31

### Added

- **XPMon-style LDAP users**: create a user with Auth = LDAP, pick admin/editor role; they authenticate via Active Directory (no app password)
- Settings → Users auth-type selector (Local vs LDAP); LDAP accounts keep the admin-assigned role across logins

### Changed

- LDAP login no longer overwrites a pre-created user’s role from AD group mappings (groups still apply only on first auto-provision)
- Inactive LDAP users are rejected at login; local accounts with the same email are not converted to LDAP on AD bind

## [0.5.16] — 2026-07-30

### Added

- Continuity engine seeds the **full active Timeline** (`schedule[]`, past + open-ended current); `to` null means still current; `at_air_time[]` highlights the proposal date/time
- Timeline **schedule hygiene** panel (`/schedule#hygiene`) — close open-ended eras, **Mark Timeline ready for Scan**
- Scan pre-check: banner + require ready mark or explicit “Start anyway” acknowledgment
- Classifier matches media type from **any path folder** (leaf→root), including `PGM` → Program

### Changed

- Continuity Lab seed counts show full schedule rows + at-air-time matches (not only the old timeline alias)

## [0.5.15] — 2026-07-30

### Changed

- Scan job detail sample list shows the **latest** 50 queued files (newest first) instead of the first 50

## [0.5.14] — 2026-07-30

### Added

- Continuity Lab **ETA** while a scan is running — uses observed decide rate when available, else avg decide ÷ parallel slots (`CONTINUITY_CHECK_CONCURRENCY` ∩ optional `OLLAMA_NUM_PARALLEL`)


## [0.5.13] — 2026-07-30

### Added

- Continuity Lab **Clear log** (admin) — truncates `continuity_check_log` only; typed `CLEAR` confirm + audit entry


## [0.5.12] — 2026-07-30

### Added

- Parallel continuity evals during Scan/Reclassify (`CONTINUITY_CHECK_CONCURRENCY`, default 4) via curl_multi
- `setup.sh` configures Ollama `OLLAMA_NUM_PARALLEL=4` so the engine can serve concurrent requests


## [0.5.11] — 2026-07-30

### Added

- **Glue groups** for multipart media (`Name.ext` + `Name_1.ext` + `Name_2.ext` …): auto-detect on Scan/Reclassify, Catalog badge/filter, manual mark/clear, Glue page
- Scan export columns for glue group key and part index

## [0.5.11] — 2026-07-30

### Fixed

- Scan confidence defaults to **UNEVALUATED** when no show or media type is matched (date/time alone no longer scores as LOW)

## [0.5.10] — 2026-07-30

### Added

- Continuity engine can fill/validate **media type** (Clean / Program / etc.) from filename/path — fills gaps, keeps rule on conflict, rebuilds proposed path when type changes
- Continuity Lab Type column + export fields for rule/engine/final media type

## [0.5.9] — 2026-07-30

### Added

- Catalog Meta column shows parsed air date/time (`YYYY-MM-DD HH:MM`)
- **Unevaluated** confidence level (default / zero-signal classifier score) with filter, badges, and dashboard breakdown
- Continuity engine can fill/validate air **date/time** from filename (fills gaps; keeps rule values on conflict)
- Continuity Lab shows parsed date/time and links each row to Catalog (`/queue?file_id=…`)

## [0.5.8] — 2026-07-30

### Added

- Continuity engine `keep_alive` baked into Ollama chat/generate calls (`CONTINUITY_CHECK_KEEP_ALIVE`, default `24h`) plus pack warm-up before Scan/Reclassify to reduce cold-starts

## [0.5.7] — 2026-07-30

### Changed

- Dark mode contrast and readability: clearer surface hierarchy, brighter secondary text, stronger borders, and Bootstrap muted/form/alert/button overrides for a cleaner professional UI

## [0.5.6] — 2026-07-30

### Fixed

- Continuity decide timeout floor raised to **60s** even when `.env` still has the old 8s value (cold pack loads were timing out)

## [0.5.5] — 2026-07-30

### Changed

- Continuity Lab export supports up to **60,000** rows (chunked lean SQL projection for memory)

## [0.5.4] — 2026-07-30

### Added

- Continuity Lab **Export XLSX** — dump filtered decision log (outcomes, reasons, seed summary, raw reply) for offline review

## [0.5.3] — 2026-07-30

### Fixed

- Continuity engine “No usable response” hardening: default timeout 60s, clearer transport errors, tolerate structured/fenced JSON replies, leaner engine payload
- Continuity Lab **Test engine** button + loaded pack list / mismatch warning

## [0.5.2] — 2026-07-30

### Added

- Continuity Lab expandable **Artifacts** per decision: seed packet (shows / timeline / approved examples / proposal), raw engine reply, HTTP/transport detail

## [0.5.1] — 2026-07-30

### Added

- Private Continuity Lab at `/continuity-lab` (admin only, not in nav) — engine status, decision log, reasons, confidence before/after, live refresh

## [0.5.0] — 2026-07-30

### Added

- Broadcast continuity check — quiet second pass during Scan / Rescan / Reclassify that cross-checks show mapping against the dictionary, timeline, and recently approved catalog items, and dials down overconfident hits
- Settings → Processing toggle for broadcast continuity check
- `setup.sh` installs the local continuity engine and loads the continuity pack

### Changed

- Classifier confidence weighting treats schedule/conversion show hits as weaker evidence
- Show token matching no longer substring-matches very short aliases

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

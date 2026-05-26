# file-media-manager — Claude Project Context

## Purpose
NewsNation media file sorting, renaming, and organization tool.
Processes thousands of legacy video/media files across two NAS servers,
classifies them against a naming/folder policy, and moves/renames them
only after human approval via a web-based review queue.

## Stack
- PHP 8.2+ (strict_types=1 everywhere)
- Bootstrap 5 vendored (no CDN — must work on isolated broadcast network)
- SQLite (data/media-manager.db)
- FFmpeg + FFprobe (metadata extraction, thumbnail generation)
- Apache 2.4 on Debian 13
- Session-based auth (bcrypt passwords)

## Architecture
```
public/index.php        Front controller — all requests route through here
src/bootstrap.php       Autoload + env + config
src/App.php             Router + dependency wiring
src/Auth/               Auth, Session, rate limiting
src/Controllers/        One controller per module
src/Repositories/       All DB access — no inline SQL in controllers/views
src/Services/           Classifier, Executor, Rollback, FFprobe, Thumbnail
src/Views/              PHP templates only — no logic beyond display
sql/migrations/         Versioned SQLite migrations (001_, 002_, etc.)
storage/thumbnails/     FFmpeg-generated JPGs cached by file_id
storage/logs/           Application logs
data/                   SQLite DB lives here
```

## NAS Mount Points
- NY Linear:      /mnt-smb/SNSEVO-NYL
- Chicago Linear: /mnt-smb/SNSEVO-CHL

## Naming Policy (target state)
- Folder: /SHOW_ABBR/YYYY/MM/MediaType/
- File:   SHOW_ABBR_YYYYMMDD_HHMM_MEDIATYPE.ext
- GISO:   SHOW_ABBR_YYYYMMDD_HHMM_GISO_Guest_Name.ext
- No spaces — underscores only
- Date: YYYYMMDD, Time: HHMM 24hr Eastern

## Roles
- admin  — full access: scan, execute, rollback, audit, user mgmt, dictionary
- editor — queue review only: approve, reject, edit proposed name/path, flag

## Key Rules
- NOTHING moves on disk without file status = APPROVED + explicit execute action
- Every disk operation is written to audit_log before AND after execution
- All thumbnails generated on-demand (not at scan time)
- Files > 3600s duration automatically flagged needs_split = true
- Bootstrap vendored under public/vendor/ — no external requests
- No inline SQL — use Repository classes
- No framework — vanilla PHP, PSR-4 autoloading

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
- Key classifier logic covered by unit tests in tests/
```

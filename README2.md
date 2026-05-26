# File Media Manager — Claude Project Context

## Purpose
NewsNation media file sorting, renaming, and organization tool.
PHP 8.2 + Bootstrap 5 + SQLite. Runs on Debian 13 / Apache.

## Architecture
See README.md for full spec.

## Key Rules
- No CDN dependencies — Bootstrap vendored under public/vendor/
- All file operations are logged to audit_log before execution
- Nothing moves on disk without status = APPROVED in the queue
- FFmpeg/FFprobe required for metadata and thumbnails

## NAS Mount Points
- NY:  /mnt-smb/SNSEVO-NYL
- CHI: /mnt-smb/SNSEVO-CHL

## Roles
- admin  — full access
- editor — queue review + approve only

## Code Style
- PHP 8.2 strict_types=1
- snake_case for DB columns
- PSR-4 autoloading under src/
- All views under src/Views/
- No inline SQL — use Repository classes



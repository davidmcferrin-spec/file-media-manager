-- ============================================================
-- Migration 001: Initial Schema
-- Media Manager — NewsNation
-- ============================================================

PRAGMA journal_mode = WAL;
PRAGMA foreign_keys = ON;

-- ── Users ────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    email         TEXT    NOT NULL UNIQUE COLLATE NOCASE,
    password_hash TEXT    NOT NULL,
    display_name  TEXT    NOT NULL DEFAULT '',
    role          TEXT    NOT NULL DEFAULT 'editor' CHECK (role IN ('admin', 'editor')),
    active        INTEGER NOT NULL DEFAULT 1,
    created_at    TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
    last_login_at TEXT    NULL
);

-- ── Auth rate limiting ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS auth_attempts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    ip_address TEXT    NOT NULL,
    attempted_at TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);
CREATE INDEX IF NOT EXISTS idx_auth_attempts_ip ON auth_attempts (ip_address, attempted_at);

-- ── Sessions ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS sessions (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token      TEXT    NOT NULL UNIQUE,
    ip_address TEXT    NOT NULL DEFAULT '',
    user_agent TEXT    NOT NULL DEFAULT '',
    expires_at TEXT    NOT NULL,
    created_at TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);
CREATE INDEX IF NOT EXISTS idx_sessions_token ON sessions (token);

-- ── NAS Sources ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS sources (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT    NOT NULL UNIQUE,
    mount_path  TEXT    NOT NULL,
    description TEXT    NOT NULL DEFAULT '',
    active      INTEGER NOT NULL DEFAULT 1,
    created_at  TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);

-- ── Shows (Dictionary) ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS shows (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    canonical_name TEXT    NOT NULL,
    abbreviation   TEXT    NOT NULL UNIQUE COLLATE NOCASE,
    aliases        TEXT    NOT NULL DEFAULT '[]', -- JSON array of strings
    active         INTEGER NOT NULL DEFAULT 1,
    notes          TEXT    NOT NULL DEFAULT '',
    created_at     TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
    updated_at     TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);

-- ── Media Types ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS media_types (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    name         TEXT    NOT NULL UNIQUE,
    abbreviation TEXT    NOT NULL UNIQUE COLLATE NOCASE,
    description  TEXT    NOT NULL DEFAULT '',
    active       INTEGER NOT NULL DEFAULT 1,
    created_at   TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);

-- ── Scan Jobs ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS scan_jobs (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    source_id       INTEGER NOT NULL REFERENCES sources(id),
    status          TEXT    NOT NULL DEFAULT 'PENDING'
                    CHECK (status IN ('PENDING','RUNNING','COMPLETED','FAILED')),
    total_files     INTEGER NOT NULL DEFAULT 0,
    processed_files INTEGER NOT NULL DEFAULT 0,
    error_message   TEXT    NULL,
    started_at      TEXT    NULL,
    completed_at    TEXT    NULL,
    created_by      INTEGER NOT NULL REFERENCES users(id),
    created_at      TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);

-- ── Files (Queue) ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS files (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    scan_job_id         INTEGER NOT NULL REFERENCES scan_jobs(id),
    source_id           INTEGER NOT NULL REFERENCES sources(id),

    -- Original location
    original_path       TEXT    NOT NULL,  -- full path including filename
    original_dir        TEXT    NOT NULL,  -- directory only
    original_filename   TEXT    NOT NULL,  -- filename only

    -- Proposed (classifier output)
    proposed_dir        TEXT    NULL,      -- target directory
    proposed_filename   TEXT    NULL,      -- target filename

    -- Classification
    show_id             INTEGER NULL REFERENCES shows(id),
    media_type_id       INTEGER NULL REFERENCES media_types(id),
    file_date           TEXT    NULL,      -- YYYYMMDD normalized
    file_time           TEXT    NULL,      -- HHMM normalized
    confidence          TEXT    NOT NULL DEFAULT 'LOW'
                        CHECK (confidence IN ('HIGH','MEDIUM','LOW')),
    classifier_notes    TEXT    NOT NULL DEFAULT '', -- JSON array of signal notes

    -- Workflow status
    status              TEXT    NOT NULL DEFAULT 'PENDING'
                        CHECK (status IN (
                            'PENDING','APPROVED','REJECTED',
                            'FLAGGED','EXECUTED','ROLLED_BACK'
                        )),
    reviewed_by         INTEGER NULL REFERENCES users(id),
    reviewed_at         TEXT    NULL,
    executed_by         INTEGER NULL REFERENCES users(id),
    executed_at         TEXT    NULL,

    -- Technical metadata (from FFprobe)
    duration_seconds    REAL    NULL,
    filesize_bytes      INTEGER NULL,
    container           TEXT    NULL,   -- mp4, mxf, ts, mov...
    codec_video         TEXT    NULL,   -- h264, mpeg2video...
    codec_audio         TEXT    NULL,   -- aac, pcm_s16le...
    resolution          TEXT    NULL,   -- 1920x1080
    framerate           TEXT    NULL,   -- 29.97, 59.94...
    metadata_extracted  INTEGER NOT NULL DEFAULT 0,

    -- Thumbnail
    thumbnail_path      TEXT    NULL,
    thumbnail_at        TEXT    NULL,

    -- Split flagging
    needs_split         INTEGER NOT NULL DEFAULT 0,
    split_notes         TEXT    NOT NULL DEFAULT '',

    created_at          TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);

CREATE INDEX IF NOT EXISTS idx_files_status     ON files (status);
CREATE INDEX IF NOT EXISTS idx_files_confidence ON files (confidence);
CREATE INDEX IF NOT EXISTS idx_files_source     ON files (source_id);
CREATE INDEX IF NOT EXISTS idx_files_show       ON files (show_id);
CREATE INDEX IF NOT EXISTS idx_files_original   ON files (original_path);

-- ── Split Queue ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS split_queue (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    file_id      INTEGER NOT NULL REFERENCES files(id),
    status       TEXT    NOT NULL DEFAULT 'PENDING'
                 CHECK (status IN ('PENDING','IN_PROGRESS','DONE','FAILED')),
    segments     TEXT    NOT NULL DEFAULT '[]', -- JSON: [{start,end,show_id,label}]
    notes        TEXT    NOT NULL DEFAULT '',
    created_by   INTEGER NOT NULL REFERENCES users(id),
    created_at   TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
    completed_at TEXT    NULL
);

-- ── Audit Log ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS audit_log (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id       INTEGER NULL REFERENCES users(id),
    user_email    TEXT    NOT NULL DEFAULT '',
    ip_address    TEXT    NOT NULL DEFAULT '',
    action        TEXT    NOT NULL,  -- SCAN_START, FILE_APPROVED, FILE_EXECUTED, ROLLBACK...
    entity_type   TEXT    NOT NULL DEFAULT '', -- file, show, user, source...
    entity_id     INTEGER NULL,
    original_path TEXT    NULL,
    new_path      TEXT    NULL,
    details       TEXT    NOT NULL DEFAULT '{}', -- JSON
    created_at    TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);

CREATE INDEX IF NOT EXISTS idx_audit_action     ON audit_log (action);
CREATE INDEX IF NOT EXISTS idx_audit_user       ON audit_log (user_id);
CREATE INDEX IF NOT EXISTS idx_audit_created    ON audit_log (created_at);
CREATE INDEX IF NOT EXISTS idx_audit_entity     ON audit_log (entity_type, entity_id);

-- ── System Settings ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS system_settings (
    key        TEXT PRIMARY KEY,
    value      TEXT NOT NULL DEFAULT '',
    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);

-- Default settings
INSERT OR IGNORE INTO system_settings (key, value) VALUES
    ('split_flag_threshold_seconds', '3600'),
    ('thumbnail_offset_seconds', '50'),
    ('app_name', 'Media Manager'),
    ('org_name', 'NewsNation'),
    ('logo_base64', '');

-- ── Seed: NAS Sources ─────────────────────────────────────────
INSERT OR IGNORE INTO sources (name, mount_path, description) VALUES
    ('NY Linear',      '/mnt-smb/SNSEVO-NYL', 'New York linear server'),
    ('Chicago Linear', '/mnt-smb/SNSEVO-CHL', 'Chicago linear server');

-- ── Seed: Media Types ─────────────────────────────────────────
INSERT OR IGNORE INTO media_types (name, abbreviation, description) VALUES
    ('Clean',   'Clean',   'Clean feed — no graphics or bugs'),
    ('Program', 'Program', 'Full program with graphics'),
    ('ISO',     'ISO',     'Isolated camera or source'),
    ('GISO',    'GISO',    'Guest ISO — isolated guest feed'),
    ('RAW',     'RAW',     'Raw unprocessed recording');

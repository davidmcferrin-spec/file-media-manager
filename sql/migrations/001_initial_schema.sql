-- ============================================================
-- Migration 001: Initial Schema
-- Media Manager — NewsNation (PostgreSQL)
-- ============================================================

CREATE TABLE IF NOT EXISTS schema_migrations (
    version    TEXT PRIMARY KEY,
    applied_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ── Users ────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
    id            SERIAL PRIMARY KEY,
    email         TEXT        NOT NULL,
    password_hash TEXT        NOT NULL,
    display_name  TEXT        NOT NULL DEFAULT '',
    role          TEXT        NOT NULL DEFAULT 'editor'
                  CHECK (role IN ('admin', 'editor')),
    active        BOOLEAN     NOT NULL DEFAULT true,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    last_login_at TIMESTAMPTZ NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS users_email_lower_key ON users (lower(email));

-- ── Auth rate limiting ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS auth_attempts (
    id           SERIAL PRIMARY KEY,
    ip_address   TEXT        NOT NULL,
    attempted_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_auth_attempts_ip ON auth_attempts (ip_address, attempted_at);

-- ── Sessions ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS sessions (
    id         SERIAL PRIMARY KEY,
    user_id    INTEGER     NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token      TEXT        NOT NULL UNIQUE,
    ip_address TEXT        NOT NULL DEFAULT '',
    user_agent TEXT        NOT NULL DEFAULT '',
    expires_at TIMESTAMPTZ NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_sessions_token ON sessions (token);

-- ── NAS Sources ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS sources (
    id          SERIAL PRIMARY KEY,
    name        TEXT        NOT NULL UNIQUE,
    mount_path  TEXT        NOT NULL,
    description TEXT        NOT NULL DEFAULT '',
    active      BOOLEAN     NOT NULL DEFAULT true,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ── Shows (Dictionary) ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS shows (
    id             SERIAL PRIMARY KEY,
    canonical_name TEXT        NOT NULL,
    abbreviation   TEXT        NOT NULL,
    aliases        TEXT        NOT NULL DEFAULT '[]',
    active         BOOLEAN     NOT NULL DEFAULT true,
    notes          TEXT        NOT NULL DEFAULT '',
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX IF NOT EXISTS shows_abbreviation_lower_key ON shows (lower(abbreviation));

-- ── Media Types ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS media_types (
    id           SERIAL PRIMARY KEY,
    name         TEXT        NOT NULL UNIQUE,
    abbreviation TEXT        NOT NULL,
    description  TEXT        NOT NULL DEFAULT '',
    active       BOOLEAN     NOT NULL DEFAULT true,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX IF NOT EXISTS media_types_abbreviation_lower_key ON media_types (lower(abbreviation));

-- ── Scan Jobs ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS scan_jobs (
    id              SERIAL PRIMARY KEY,
    source_id       INTEGER     NOT NULL REFERENCES sources(id),
    status          TEXT        NOT NULL DEFAULT 'PENDING'
                    CHECK (status IN ('PENDING','RUNNING','COMPLETED','FAILED')),
    total_files     INTEGER     NOT NULL DEFAULT 0,
    processed_files INTEGER     NOT NULL DEFAULT 0,
    error_message   TEXT        NULL,
    started_at      TIMESTAMPTZ NULL,
    completed_at    TIMESTAMPTZ NULL,
    created_by      INTEGER     NOT NULL REFERENCES users(id),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ── Files (Queue) ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS files (
    id                  SERIAL PRIMARY KEY,
    scan_job_id         INTEGER     NOT NULL REFERENCES scan_jobs(id),
    source_id           INTEGER     NOT NULL REFERENCES sources(id),
    original_path       TEXT        NOT NULL,
    original_dir        TEXT        NOT NULL,
    original_filename   TEXT        NOT NULL,
    proposed_dir        TEXT        NULL,
    proposed_filename   TEXT        NULL,
    show_id             INTEGER     NULL REFERENCES shows(id),
    media_type_id       INTEGER     NULL REFERENCES media_types(id),
    file_date           TEXT        NULL,
    file_time           TEXT        NULL,
    confidence          TEXT        NOT NULL DEFAULT 'UNEVALUATED'
                        CHECK (confidence IN ('HIGH','MEDIUM','LOW','UNEVALUATED')),
    classifier_notes    TEXT        NOT NULL DEFAULT '',
    status              TEXT        NOT NULL DEFAULT 'PENDING'
                        CHECK (status IN (
                            'PENDING','APPROVED','REJECTED',
                            'FLAGGED','EXECUTED','ROLLED_BACK'
                        )),
    reviewed_by         INTEGER     NULL REFERENCES users(id),
    reviewed_at         TIMESTAMPTZ NULL,
    executed_by         INTEGER     NULL REFERENCES users(id),
    executed_at         TIMESTAMPTZ NULL,
    duration_seconds    DOUBLE PRECISION NULL,
    filesize_bytes      BIGINT      NULL,
    container           TEXT        NULL,
    codec_video         TEXT        NULL,
    codec_audio         TEXT        NULL,
    resolution          TEXT        NULL,
    framerate           TEXT        NULL,
    metadata_extracted  BOOLEAN     NOT NULL DEFAULT false,
    thumbnail_path      TEXT        NULL,
    thumbnail_at        TIMESTAMPTZ NULL,
    needs_split         BOOLEAN     NOT NULL DEFAULT false,
    split_notes         TEXT        NOT NULL DEFAULT '',
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_files_status     ON files (status);
CREATE INDEX IF NOT EXISTS idx_files_confidence ON files (confidence);
CREATE INDEX IF NOT EXISTS idx_files_source     ON files (source_id);
CREATE INDEX IF NOT EXISTS idx_files_show       ON files (show_id);
CREATE INDEX IF NOT EXISTS idx_files_original   ON files (original_path);

-- ── Split Queue ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS split_queue (
    id           SERIAL PRIMARY KEY,
    file_id      INTEGER     NOT NULL REFERENCES files(id),
    status       TEXT        NOT NULL DEFAULT 'PENDING'
                 CHECK (status IN ('PENDING','IN_PROGRESS','DONE','FAILED')),
    segments     TEXT        NOT NULL DEFAULT '[]',
    notes        TEXT        NOT NULL DEFAULT '',
    created_by   INTEGER     NOT NULL REFERENCES users(id),
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    completed_at TIMESTAMPTZ NULL
);

-- ── Audit Log ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS audit_log (
    id            SERIAL PRIMARY KEY,
    user_id       INTEGER     NULL REFERENCES users(id),
    user_email    TEXT        NOT NULL DEFAULT '',
    ip_address    TEXT        NOT NULL DEFAULT '',
    action        TEXT        NOT NULL,
    entity_type   TEXT        NOT NULL DEFAULT '',
    entity_id     INTEGER     NULL,
    original_path TEXT        NULL,
    new_path      TEXT        NULL,
    details       TEXT        NOT NULL DEFAULT '{}',
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_audit_action  ON audit_log (action);
CREATE INDEX IF NOT EXISTS idx_audit_user    ON audit_log (user_id);
CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_log (created_at);
CREATE INDEX IF NOT EXISTS idx_audit_entity  ON audit_log (entity_type, entity_id);

-- ── System Settings ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS system_settings (
    key        TEXT PRIMARY KEY,
    value      TEXT        NOT NULL DEFAULT '',
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

INSERT INTO system_settings (key, value) VALUES
    ('split_flag_threshold_seconds', '3600'),
    ('thumbnail_offset_seconds', '50'),
    ('app_name', 'Media Manager'),
    ('org_name', 'NewsNation'),
    ('logo_base64', '')
ON CONFLICT (key) DO NOTHING;

INSERT INTO sources (name, mount_path, description) VALUES
    ('NY Linear',      '/mnt-smb/SNSEVO-NYL', 'New York linear server'),
    ('Chicago Linear', '/mnt-smb/SNSEVO-CHL', 'Chicago linear server')
ON CONFLICT (name) DO NOTHING;

INSERT INTO media_types (name, abbreviation, description) VALUES
    ('Clean',   'Clean',   'Clean feed — no graphics or bugs'),
    ('Program', 'Program', 'Full program with graphics'),
    ('ISO',     'ISO',     'Isolated camera or source'),
    ('GISO',    'GISO',    'Guest ISO — isolated guest feed'),
    ('RAW',     'RAW',     'Raw unprocessed recording')
ON CONFLICT (name) DO NOTHING;

INSERT INTO schema_migrations (version)
VALUES ('001_initial_schema')
ON CONFLICT (version) DO NOTHING;

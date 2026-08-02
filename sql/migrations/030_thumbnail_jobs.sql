-- ============================================================
-- Migration 030: Background queue for Catalog thumbnails
-- (FFmpeg off Apache — placeholder until worker finishes)
-- ============================================================

CREATE TABLE IF NOT EXISTS thumbnail_jobs (
    id                 SERIAL PRIMARY KEY,
    file_id            INTEGER     NOT NULL REFERENCES files(id) ON DELETE CASCADE,
    size               TEXT        NOT NULL DEFAULT 'default'
                       CHECK (size IN ('default', 'large')),
    status             TEXT        NOT NULL DEFAULT 'PENDING'
                       CHECK (status IN ('PENDING', 'RUNNING', 'COMPLETED', 'FAILED', 'CANCELLED')),
    cancel_requested   BOOLEAN     NOT NULL DEFAULT false,
    worker_pid         INTEGER     NULL,
    error_message      TEXT        NULL,
    created_by         INTEGER     NULL REFERENCES users(id),
    started_at         TIMESTAMPTZ NULL,
    completed_at       TIMESTAMPTZ NULL,
    created_at         TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_thumbnail_jobs_status
    ON thumbnail_jobs (status, created_at ASC);

CREATE INDEX IF NOT EXISTS idx_thumbnail_jobs_file
    ON thumbnail_jobs (file_id, created_at DESC);

-- One active job per file + size (default vs large).
CREATE UNIQUE INDEX IF NOT EXISTS thumbnail_jobs_active_file_size_key
    ON thumbnail_jobs (file_id, size)
    WHERE status IN ('PENDING', 'RUNNING');

INSERT INTO schema_migrations (version)
VALUES ('030_thumbnail_jobs')
ON CONFLICT (version) DO NOTHING;

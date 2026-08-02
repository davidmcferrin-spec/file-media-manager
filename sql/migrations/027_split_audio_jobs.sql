-- ============================================================
-- Migration 027: Background queue for split audio analysis
-- (silence / levels / suggest — no FFmpeg in Apache requests)
-- ============================================================

CREATE TABLE IF NOT EXISTS split_audio_jobs (
    id                 SERIAL PRIMARY KEY,
    split_queue_id     INTEGER     NOT NULL REFERENCES split_queue(id) ON DELETE CASCADE,
    file_id            INTEGER     NOT NULL REFERENCES files(id) ON DELETE CASCADE,
    kind               TEXT        NOT NULL
                       CHECK (kind IN ('levels', 'suggest')),
    status             TEXT        NOT NULL DEFAULT 'PENDING'
                       CHECK (status IN ('PENDING', 'RUNNING', 'COMPLETED', 'FAILED', 'CANCELLED')),
    cancel_requested   BOOLEAN     NOT NULL DEFAULT false,
    worker_pid         INTEGER     NULL,
    error_message      TEXT        NULL,
    result_summary     TEXT        NOT NULL DEFAULT '',
    created_by         INTEGER     NOT NULL REFERENCES users(id),
    started_at         TIMESTAMPTZ NULL,
    completed_at       TIMESTAMPTZ NULL,
    created_at         TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_split_audio_jobs_status
    ON split_audio_jobs (status, created_at ASC);

CREATE INDEX IF NOT EXISTS idx_split_audio_jobs_split_queue
    ON split_audio_jobs (split_queue_id, created_at DESC);

-- One active analysis at a time per source file (FFmpeg / NAS contention).
CREATE UNIQUE INDEX IF NOT EXISTS split_audio_jobs_active_file_key
    ON split_audio_jobs (file_id)
    WHERE status IN ('PENDING', 'RUNNING');

INSERT INTO schema_migrations (version)
VALUES ('027_split_audio_jobs')
ON CONFLICT (version) DO NOTHING;

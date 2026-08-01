-- Background caption extract jobs (probe + SRT sidecar for many files).

CREATE TABLE IF NOT EXISTS caption_extract_jobs (
    id                        SERIAL PRIMARY KEY,
    status                    TEXT        NOT NULL DEFAULT 'PENDING'
                              CHECK (status IN ('PENDING','RUNNING','PAUSED','COMPLETED','FAILED','CANCELLED')),
    scope                     TEXT        NOT NULL DEFAULT 'missing_srt'
                              CHECK (scope IN ('missing_srt','has_captions','selected')),
    file_ids                  JSONB       NULL,
    total_files               INTEGER     NOT NULL DEFAULT 0,
    processed_files           INTEGER     NOT NULL DEFAULT 0,
    ok_count                  INTEGER     NOT NULL DEFAULT 0,
    fail_count                INTEGER     NOT NULL DEFAULT 0,
    skip_count                INTEGER     NOT NULL DEFAULT 0,
    total_duration_seconds    DOUBLE PRECISION NOT NULL DEFAULT 0,
    processed_duration_seconds DOUBLE PRECISION NOT NULL DEFAULT 0,
    current_file_id           INTEGER     NULL REFERENCES files(id) ON DELETE SET NULL,
    current_filename          TEXT        NULL,
    current_started_at        TIMESTAMPTZ NULL,
    last_error                TEXT        NULL,
    error_message             TEXT        NULL,
    cancel_requested          BOOLEAN     NOT NULL DEFAULT false,
    worker_pid                INTEGER     NULL,
    started_at                TIMESTAMPTZ NULL,
    completed_at              TIMESTAMPTZ NULL,
    created_by                INTEGER     NOT NULL REFERENCES users(id),
    created_at                TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_caption_extract_jobs_status
    ON caption_extract_jobs (status, created_at DESC);

INSERT INTO schema_migrations (version)
VALUES ('020_caption_extract_jobs')
ON CONFLICT (version) DO NOTHING;

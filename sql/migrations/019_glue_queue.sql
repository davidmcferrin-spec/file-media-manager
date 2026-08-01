-- Glue queue: ffmpeg concat jobs with QC before source deletion

CREATE TABLE IF NOT EXISTS glue_queue (
    id                        SERIAL PRIMARY KEY,
    glue_group_key            TEXT        NOT NULL,
    status                    TEXT        NOT NULL DEFAULT 'PENDING'
                              CHECK (status IN (
                                  'PENDING',
                                  'RUNNING',
                                  'READY_FOR_QC',
                                  'APPROVED',
                                  'DONE',
                                  'FAILED',
                                  'CANCELLED'
                              )),
    source_file_ids           TEXT        NOT NULL DEFAULT '[]',
    output_path               TEXT        NULL,
    output_file_id            INTEGER     NULL REFERENCES files(id) ON DELETE SET NULL,
    expected_duration_seconds DOUBLE PRECISION NULL,
    output_duration_seconds   DOUBLE PRECISION NULL,
    output_filesize_bytes     BIGINT      NULL,
    error_message             TEXT        NOT NULL DEFAULT '',
    notes                     TEXT        NOT NULL DEFAULT '',
    created_by                INTEGER     NOT NULL REFERENCES users(id),
    created_at                TIMESTAMPTZ NOT NULL DEFAULT now(),
    started_at                TIMESTAMPTZ NULL,
    completed_at              TIMESTAMPTZ NULL,
    qc_by                     INTEGER     NULL REFERENCES users(id),
    qc_at                     TIMESTAMPTZ NULL,
    sources_deleted_at        TIMESTAMPTZ NULL
);

CREATE INDEX IF NOT EXISTS idx_glue_queue_status
    ON glue_queue (status);

CREATE INDEX IF NOT EXISTS idx_glue_queue_group
    ON glue_queue (glue_group_key);

CREATE UNIQUE INDEX IF NOT EXISTS glue_queue_active_group_key
    ON glue_queue (glue_group_key)
    WHERE status IN ('PENDING', 'RUNNING', 'READY_FOR_QC', 'APPROVED');

INSERT INTO schema_migrations (version)
VALUES ('019_glue_queue')
ON CONFLICT (version) DO NOTHING;

-- ============================================================
-- Migration 012: Show audit — expected gaps + file indexes
-- Media Manager
-- ============================================================

CREATE TABLE IF NOT EXISTS schedule_expected_gaps (
    id              SERIAL PRIMARY KEY,
    show_id         INTEGER     NOT NULL REFERENCES shows(id) ON DELETE CASCADE,
    air_date        DATE        NOT NULL,
    hour_start_et   TIME        NOT NULL,
    media_lane      TEXT        NOT NULL DEFAULT 'both'
                    CHECK (media_lane IN ('program', 'clean', 'both')),
    reason          TEXT        NOT NULL,
    notes           TEXT        NOT NULL DEFAULT '',
    created_by      INTEGER     NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT schedule_expected_gaps_unique
        UNIQUE (show_id, air_date, hour_start_et, media_lane)
);

CREATE INDEX IF NOT EXISTS idx_schedule_expected_gaps_date
    ON schedule_expected_gaps (air_date);

CREATE INDEX IF NOT EXISTS idx_schedule_expected_gaps_show
    ON schedule_expected_gaps (show_id, air_date);

CREATE INDEX IF NOT EXISTS idx_files_show_date_time
    ON files (show_id, file_date, file_time)
    WHERE show_id IS NOT NULL AND file_date IS NOT NULL AND file_time IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_files_media_type_date
    ON files (media_type_id, file_date)
    WHERE media_type_id IS NOT NULL AND file_date IS NOT NULL;

INSERT INTO schema_migrations (version)
VALUES ('012_show_audit')
ON CONFLICT (version) DO NOTHING;

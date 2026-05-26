-- ============================================================
-- Migration 009: Historical program schedule (hourly blocks)
-- Media Manager
-- ============================================================

CREATE TABLE IF NOT EXISTS program_schedule_entries (
    id              SERIAL PRIMARY KEY,
    show_id         INTEGER     NOT NULL REFERENCES shows(id) ON DELETE CASCADE,
    source_row_id   INTEGER     NULL,
    title           TEXT        NOT NULL,
    hour_start_et   TIME        NOT NULL,
    hour_end_et     TIME        NOT NULL,
    days_of_week    SMALLINT    NOT NULL,
    effective_from  DATE        NOT NULL,
    effective_to    DATE        NULL,
    era_name        TEXT        NOT NULL DEFAULT '',
    anchor_names    TEXT        NOT NULL DEFAULT '',
    show_type       TEXT        NOT NULL DEFAULT '',
    network_brand   TEXT        NOT NULL DEFAULT '',
    notes           TEXT        NOT NULL DEFAULT '',
    active          BOOLEAN     NOT NULL DEFAULT true,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_program_schedule_dates
    ON program_schedule_entries (effective_from, effective_to);

CREATE INDEX IF NOT EXISTS idx_program_schedule_show
    ON program_schedule_entries (show_id);

CREATE INDEX IF NOT EXISTS idx_program_schedule_hour
    ON program_schedule_entries (hour_start_et);

INSERT INTO schema_migrations (version)
VALUES ('009_program_schedule')
ON CONFLICT (version) DO NOTHING;

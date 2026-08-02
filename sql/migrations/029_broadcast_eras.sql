-- Broadcast eras: network on-air windows by date range + optional link from schedule rows

CREATE TABLE IF NOT EXISTS broadcast_eras (
    id              SERIAL PRIMARY KEY,
    name            TEXT        NOT NULL,
    effective_from  DATE        NOT NULL,
    effective_to    DATE        NULL,
    notes           TEXT        NOT NULL DEFAULT '',
    active          BOOLEAN     NOT NULL DEFAULT true,
    sort_order      INTEGER     NOT NULL DEFAULT 0,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_broadcast_eras_dates
    ON broadcast_eras (effective_from, effective_to);

CREATE TABLE IF NOT EXISTS broadcast_era_windows (
    id              SERIAL PRIMARY KEY,
    era_id          INTEGER     NOT NULL REFERENCES broadcast_eras(id) ON DELETE CASCADE,
    hour_start_et   TIME        NOT NULL,
    hour_end_et     TIME        NOT NULL,
    days_of_week    SMALLINT    NOT NULL,
    notes           TEXT        NOT NULL DEFAULT '',
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_broadcast_era_windows_era
    ON broadcast_era_windows (era_id);

ALTER TABLE program_schedule_entries
    ADD COLUMN IF NOT EXISTS broadcast_era_id INTEGER NULL
        REFERENCES broadcast_eras(id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS idx_program_schedule_broadcast_era
    ON program_schedule_entries (broadcast_era_id);

INSERT INTO schema_migrations (version)
VALUES ('029_broadcast_eras')
ON CONFLICT (version) DO NOTHING;

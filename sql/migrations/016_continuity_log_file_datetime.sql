-- Continuity log: Catalog file link + date/time fields

ALTER TABLE continuity_check_log
    ADD COLUMN IF NOT EXISTS file_id INTEGER NULL REFERENCES files(id) ON DELETE SET NULL,
    ADD COLUMN IF NOT EXISTS rule_file_date TEXT NULL,
    ADD COLUMN IF NOT EXISTS rule_file_time TEXT NULL,
    ADD COLUMN IF NOT EXISTS engine_file_date TEXT NULL,
    ADD COLUMN IF NOT EXISTS engine_file_time TEXT NULL,
    ADD COLUMN IF NOT EXISTS final_file_date TEXT NULL,
    ADD COLUMN IF NOT EXISTS final_file_time TEXT NULL;

CREATE INDEX IF NOT EXISTS idx_continuity_log_file_id
    ON continuity_check_log (file_id);

INSERT INTO schema_migrations (version)
VALUES ('016_continuity_log_file_datetime')
ON CONFLICT (version) DO NOTHING;

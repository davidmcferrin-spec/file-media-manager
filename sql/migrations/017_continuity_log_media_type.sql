-- Continuity log: media type (Clean / Program / etc.) fields

ALTER TABLE continuity_check_log
    ADD COLUMN IF NOT EXISTS rule_media_type_id INTEGER NULL,
    ADD COLUMN IF NOT EXISTS rule_media_type_abbr TEXT NULL,
    ADD COLUMN IF NOT EXISTS engine_media_type_id INTEGER NULL,
    ADD COLUMN IF NOT EXISTS engine_media_type_abbr TEXT NULL,
    ADD COLUMN IF NOT EXISTS final_media_type_id INTEGER NULL,
    ADD COLUMN IF NOT EXISTS final_media_type_abbr TEXT NULL;

INSERT INTO schema_migrations (version)
VALUES ('017_continuity_log_media_type')
ON CONFLICT (version) DO NOTHING;

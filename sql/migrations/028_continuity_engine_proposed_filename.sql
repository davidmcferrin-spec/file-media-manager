-- Continuity log: model-side proposed filename (for Lab / export triad)

ALTER TABLE continuity_check_log
    ADD COLUMN IF NOT EXISTS engine_proposed_filename TEXT NULL;

INSERT INTO schema_migrations (version)
VALUES ('028_continuity_engine_proposed_filename')
ON CONFLICT (version) DO NOTHING;

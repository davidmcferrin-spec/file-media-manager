-- ============================================================
-- Migration 013: Ensure strong split threshold setting exists
-- ============================================================

INSERT INTO system_settings (key, value) VALUES
    ('split_strong_threshold_seconds', '10800')
ON CONFLICT (key) DO NOTHING;

INSERT INTO schema_migrations (version)
VALUES ('013_split_strong_setting')
ON CONFLICT (version) DO NOTHING;

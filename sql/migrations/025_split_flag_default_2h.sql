-- ============================================================
-- Migration 025: Split flag default ≥ 2 hours (7200s)
-- Strong threshold remains 3 hours when not already set.
-- ============================================================

INSERT INTO system_settings (key, value) VALUES
    ('split_flag_threshold_seconds', '7200')
ON CONFLICT (key) DO UPDATE
    SET value = EXCLUDED.value, updated_at = now();

INSERT INTO system_settings (key, value) VALUES
    ('split_strong_threshold_seconds', '10800')
ON CONFLICT (key) DO NOTHING;

INSERT INTO schema_migrations (version)
VALUES ('025_split_flag_default_2h')
ON CONFLICT (version) DO NOTHING;

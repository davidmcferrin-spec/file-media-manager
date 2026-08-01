-- ============================================================
-- Migration 026: Audio-based split suggest thresholds
-- ============================================================

INSERT INTO system_settings (key, value) VALUES
    ('split_audio_content_gap_seconds', '1800'),
    ('split_audio_min_program_seconds', '540'),
    ('split_audio_ad_ignore_seconds', '300'),
    ('split_audio_silence_noise_db', '-35')
ON CONFLICT (key) DO NOTHING;

INSERT INTO schema_migrations (version)
VALUES ('026_split_audio_suggest')
ON CONFLICT (version) DO NOTHING;

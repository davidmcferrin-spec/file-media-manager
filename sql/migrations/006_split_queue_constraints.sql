-- ============================================================
-- Migration 006: Split queue constraints + system settings
-- Media Manager — Phase 5
-- ============================================================

CREATE UNIQUE INDEX IF NOT EXISTS split_queue_active_file_key
    ON split_queue (file_id)
    WHERE status IN ('PENDING', 'IN_PROGRESS');

INSERT INTO schema_migrations (version)
VALUES ('006_split_queue_constraints')
ON CONFLICT (version) DO NOTHING;

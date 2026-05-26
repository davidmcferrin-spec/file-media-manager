-- ============================================================
-- Migration 005: Executed path tracking for rollback
-- Media Manager — Phase 4
-- ============================================================

ALTER TABLE files
    ADD COLUMN IF NOT EXISTS executed_path TEXT NULL;

CREATE INDEX IF NOT EXISTS idx_files_executed_path ON files (executed_path)
    WHERE executed_path IS NOT NULL;

INSERT INTO schema_migrations (version)
VALUES ('005_execute_path')
ON CONFLICT (version) DO NOTHING;

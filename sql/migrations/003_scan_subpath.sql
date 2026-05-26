-- ============================================================
-- Migration 003: Scan job subpath + file list dev support
-- Media Manager — Phase 3
-- ============================================================

ALTER TABLE scan_jobs
    ADD COLUMN IF NOT EXISTS subpath TEXT NOT NULL DEFAULT '';

ALTER TABLE scan_jobs
    ADD COLUMN IF NOT EXISTS extract_metadata BOOLEAN NOT NULL DEFAULT true;

ALTER TABLE scan_jobs
    ADD COLUMN IF NOT EXISTS dev_file_list TEXT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS idx_files_original_path ON files (original_path);

INSERT INTO schema_migrations (version)
VALUES ('003_scan_subpath')
ON CONFLICT (version) DO NOTHING;

-- ============================================================
-- Migration 008: Scan worker PID for stop/kill
-- Media Manager
-- ============================================================

ALTER TABLE scan_jobs
    ADD COLUMN IF NOT EXISTS worker_pid INTEGER NULL;

INSERT INTO schema_migrations (version)
VALUES ('008_scan_worker_pid')
ON CONFLICT (version) DO NOTHING;

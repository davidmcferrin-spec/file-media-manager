-- ============================================================
-- Migration 011: Paused scan jobs (resumable via scan.php)
-- Media Manager
-- ============================================================

ALTER TABLE scan_jobs DROP CONSTRAINT IF EXISTS scan_jobs_status_check;

ALTER TABLE scan_jobs ADD CONSTRAINT scan_jobs_status_check
    CHECK (status IN ('PENDING', 'RUNNING', 'PAUSED', 'COMPLETED', 'FAILED', 'CANCELLED'));

INSERT INTO schema_migrations (version)
VALUES ('011_scan_job_paused')
ON CONFLICT (version) DO NOTHING;

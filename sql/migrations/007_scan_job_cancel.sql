-- ============================================================
-- Migration 007: Scan job cancellation
-- Media Manager
-- ============================================================

ALTER TABLE scan_jobs
    ADD COLUMN IF NOT EXISTS cancel_requested BOOLEAN NOT NULL DEFAULT false;

ALTER TABLE scan_jobs DROP CONSTRAINT IF EXISTS scan_jobs_status_check;

ALTER TABLE scan_jobs ADD CONSTRAINT scan_jobs_status_check
    CHECK (status IN ('PENDING', 'RUNNING', 'COMPLETED', 'FAILED', 'CANCELLED'));

INSERT INTO schema_migrations (version)
VALUES ('007_scan_job_cancel')
ON CONFLICT (version) DO NOTHING;

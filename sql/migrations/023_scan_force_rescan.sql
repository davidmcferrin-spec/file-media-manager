-- Flag so the scan daemon can pick up full-rescan jobs without a CLI --rescan flag.

ALTER TABLE scan_jobs
    ADD COLUMN IF NOT EXISTS force_rescan BOOLEAN NOT NULL DEFAULT false;

INSERT INTO schema_migrations (version)
VALUES ('023_scan_force_rescan')
ON CONFLICT (version) DO NOTHING;

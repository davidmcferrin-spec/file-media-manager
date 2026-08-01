-- Track whether caption detection has been attempted; expand extract job scopes.

ALTER TABLE files
    ADD COLUMN IF NOT EXISTS captions_probed BOOLEAN NOT NULL DEFAULT false;

UPDATE files
SET captions_probed = true
WHERE has_captions IS TRUE
   OR srt_path IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_files_captions_unprobed
    ON files (id)
    WHERE captions_probed IS FALSE;

ALTER TABLE caption_extract_jobs
    DROP CONSTRAINT IF EXISTS caption_extract_jobs_scope_check;

ALTER TABLE caption_extract_jobs
    ADD CONSTRAINT caption_extract_jobs_scope_check
    CHECK (scope IN ('missing_srt', 'has_captions', 'selected', 'probe_only'));

INSERT INTO schema_migrations (version)
VALUES ('024_captions_probed')
ON CONFLICT (version) DO NOTHING;

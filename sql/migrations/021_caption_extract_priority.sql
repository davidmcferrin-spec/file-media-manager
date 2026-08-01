-- Priority lane for caption extract jobs (process selected files first).

ALTER TABLE caption_extract_jobs
    ADD COLUMN IF NOT EXISTS priority_file_ids JSONB NOT NULL DEFAULT '[]'::jsonb;

INSERT INTO schema_migrations (version)
VALUES ('021_caption_extract_priority')
ON CONFLICT (version) DO NOTHING;

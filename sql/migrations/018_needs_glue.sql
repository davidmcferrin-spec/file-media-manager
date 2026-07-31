-- Multipart "glue" groups: Filename.ext + Filename_1.ext + Filename_2.ext …

ALTER TABLE files
    ADD COLUMN IF NOT EXISTS needs_glue BOOLEAN NOT NULL DEFAULT false,
    ADD COLUMN IF NOT EXISTS glue_group_key TEXT NULL,
    ADD COLUMN IF NOT EXISTS glue_part_index INTEGER NULL,
    ADD COLUMN IF NOT EXISTS glue_notes TEXT NOT NULL DEFAULT '';

CREATE INDEX IF NOT EXISTS idx_files_needs_glue
    ON files (needs_glue)
    WHERE needs_glue IS TRUE;

CREATE INDEX IF NOT EXISTS idx_files_glue_group_key
    ON files (glue_group_key)
    WHERE glue_group_key IS NOT NULL;

INSERT INTO schema_migrations (version)
VALUES ('018_needs_glue')
ON CONFLICT (version) DO NOTHING;

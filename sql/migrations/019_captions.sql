-- Closed captions / subtitle detection and extracted SRT sidecar path.

ALTER TABLE files
    ADD COLUMN IF NOT EXISTS has_captions BOOLEAN NOT NULL DEFAULT false,
    ADD COLUMN IF NOT EXISTS caption_stream_index INTEGER NULL,
    ADD COLUMN IF NOT EXISTS srt_path TEXT NULL;

CREATE INDEX IF NOT EXISTS idx_files_has_captions
    ON files (has_captions)
    WHERE has_captions IS TRUE;

INSERT INTO schema_migrations (version)
VALUES ('019_captions')
ON CONFLICT (version) DO NOTHING;

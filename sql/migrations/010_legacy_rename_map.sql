-- ============================================================
-- Migration 010: Legacy rename map + dual proposals + folder_name
-- Media Manager
-- ============================================================

ALTER TABLE media_types
    ADD COLUMN IF NOT EXISTS folder_name TEXT NULL;

UPDATE media_types SET folder_name = 'ISO'
WHERE folder_name IS NULL AND lower(name) IN ('iso', 'giso');

UPDATE media_types SET folder_name = name
WHERE folder_name IS NULL;

ALTER TABLE media_types
    ALTER COLUMN folder_name SET NOT NULL;

ALTER TABLE sources
    ADD COLUMN IF NOT EXISTS source_code TEXT NULL;

UPDATE sources SET source_code = 'NY'
WHERE source_code IS NULL AND mount_path ILIKE '%SNSEVO-NYL%';

UPDATE sources SET source_code = 'CHI'
WHERE source_code IS NULL AND mount_path ILIKE '%SNSEVO-CHL%';

CREATE TABLE IF NOT EXISTS legacy_rename_map (
    id                  SERIAL PRIMARY KEY,
    source_label        TEXT        NOT NULL,
    match_path          TEXT        NOT NULL,
    match_filename      TEXT        NOT NULL,
    target_dir          TEXT        NULL,
    target_filename     TEXT        NULL,
    show_id             INTEGER     NULL REFERENCES shows(id) ON DELETE SET NULL,
    show_abbr           TEXT        NOT NULL DEFAULT '',
    media_type_id       INTEGER     NULL REFERENCES media_types(id) ON DELETE SET NULL,
    media_type_label    TEXT        NOT NULL DEFAULT '',
    curator_confidence  SMALLINT    NOT NULL DEFAULT 5
                        CHECK (curator_confidence >= 1 AND curator_confidence <= 10),
    row_type            TEXT        NOT NULL DEFAULT 'concrete'
                        CHECK (row_type IN ('concrete', 'template')),
    notes               TEXT        NOT NULL DEFAULT '',
    active              BOOLEAN     NOT NULL DEFAULT true,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE UNIQUE INDEX IF NOT EXISTS legacy_rename_map_source_path_file_key
    ON legacy_rename_map (source_label, lower(match_path), lower(match_filename));

CREATE INDEX IF NOT EXISTS idx_legacy_rename_map_source
    ON legacy_rename_map (source_label);

ALTER TABLE files
    ADD COLUMN IF NOT EXISTS classifier_confidence TEXT NULL
        CHECK (classifier_confidence IS NULL OR classifier_confidence IN ('HIGH', 'MEDIUM', 'LOW')),
    ADD COLUMN IF NOT EXISTS classifier_proposed_dir TEXT NULL,
    ADD COLUMN IF NOT EXISTS classifier_proposed_filename TEXT NULL,
    ADD COLUMN IF NOT EXISTS alt_proposed_dir TEXT NULL,
    ADD COLUMN IF NOT EXISTS alt_proposed_filename TEXT NULL,
    ADD COLUMN IF NOT EXISTS proposed_source TEXT NULL
        CHECK (proposed_source IS NULL OR proposed_source IN ('classifier', 'legacy_map')),
    ADD COLUMN IF NOT EXISTS alt_source TEXT NULL
        CHECK (alt_source IS NULL OR alt_source IN ('classifier', 'legacy_map')),
    ADD COLUMN IF NOT EXISTS legacy_map_id INTEGER NULL REFERENCES legacy_rename_map(id) ON DELETE SET NULL,
    ADD COLUMN IF NOT EXISTS map_curator_confidence SMALLINT NULL,
    ADD COLUMN IF NOT EXISTS proposal_agreement TEXT NULL
        CHECK (proposal_agreement IS NULL OR proposal_agreement IN (
            'match', 'partial', 'conflict', 'map_only', 'classifier_only', 'template', 'none'
        ));

UPDATE files SET classifier_confidence = confidence
WHERE classifier_confidence IS NULL;

UPDATE files SET classifier_proposed_dir = proposed_dir,
                 classifier_proposed_filename = proposed_filename,
                 proposed_source = 'classifier'
WHERE proposed_source IS NULL AND proposed_dir IS NOT NULL;

INSERT INTO schema_migrations (version)
VALUES ('010_legacy_rename_map')
ON CONFLICT (version) DO NOTHING;

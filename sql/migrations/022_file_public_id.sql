-- Immutable public asset ID (ULID) for derived media cache pathing / UI.
-- Canonical NAS media paths are unchanged; outputs still deliver to NAS.

ALTER TABLE files
    ADD COLUMN IF NOT EXISTS public_id CHAR(26) NULL;

CREATE UNIQUE INDEX IF NOT EXISTS idx_files_public_id
    ON files (public_id)
    WHERE public_id IS NOT NULL;

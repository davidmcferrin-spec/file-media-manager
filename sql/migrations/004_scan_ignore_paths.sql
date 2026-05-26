-- ============================================================
-- Migration 004: Configurable scan ignore paths
-- Media Manager — admin-managed paths excluded from indexing
-- ============================================================

CREATE TABLE IF NOT EXISTS scan_ignore_paths (
    id          SERIAL PRIMARY KEY,
    source_id   INTEGER     NULL REFERENCES sources(id) ON DELETE CASCADE,
    path        TEXT        NOT NULL,
    notes       TEXT        NOT NULL DEFAULT '',
    active      BOOLEAN     NOT NULL DEFAULT true,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_scan_ignore_paths_source ON scan_ignore_paths (source_id);
CREATE INDEX IF NOT EXISTS idx_scan_ignore_paths_active ON scan_ignore_paths (active);

-- Relative to NY Legacy mount — entire SPECIAL PROGRAMMING tree
INSERT INTO scan_ignore_paths (source_id, path, notes)
SELECT id, 'SPECIAL PROGRAMMING', 'Special programming / raw ingest — outside linear archive policy'
FROM sources
WHERE mount_path LIKE '%SNSEVO-NYL%'
  AND NOT EXISTS (
      SELECT 1 FROM scan_ignore_paths sip
      WHERE sip.source_id = sources.id AND lower(sip.path) = 'special programming'
  );

INSERT INTO scan_ignore_paths (source_id, path, notes)
SELECT id, 'SPECIAL PROGRAMMING', 'Special programming / raw ingest — outside linear archive policy'
FROM sources
WHERE mount_path LIKE '%SNSEVO-CHL%'
  AND NOT EXISTS (
      SELECT 1 FROM scan_ignore_paths sip
      WHERE sip.source_id = sources.id AND lower(sip.path) = 'special programming'
  );

INSERT INTO schema_migrations (version)
VALUES ('004_scan_ignore_paths')
ON CONFLICT (version) DO NOTHING;

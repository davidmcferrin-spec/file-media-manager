-- ============================================================
-- Migration 002: Dictionary, conversion rules, LDAP, seeds
-- Media Manager — Phase 2
-- ============================================================

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS auth_source TEXT NOT NULL DEFAULT 'local'
    CHECK (auth_source IN ('local', 'ldap'));

-- ── Conversion rules (alias → show or media type) ─────────────
CREATE TABLE IF NOT EXISTS conversion_rules (
    id              SERIAL PRIMARY KEY,
    category        TEXT        NOT NULL CHECK (category IN ('show', 'media_type')),
    alias           TEXT        NOT NULL,
    show_id         INTEGER     NULL REFERENCES shows(id) ON DELETE CASCADE,
    media_type_id   INTEGER     NULL REFERENCES media_types(id) ON DELETE CASCADE,
    notes           TEXT        NOT NULL DEFAULT '',
    active          BOOLEAN     NOT NULL DEFAULT true,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (
        (category = 'show' AND show_id IS NOT NULL AND media_type_id IS NULL)
        OR (category = 'media_type' AND media_type_id IS NOT NULL AND show_id IS NULL)
    )
);

CREATE UNIQUE INDEX IF NOT EXISTS conversion_rules_category_alias_key
    ON conversion_rules (category, lower(alias));

CREATE INDEX IF NOT EXISTS idx_conversion_rules_show ON conversion_rules (show_id);
CREATE INDEX IF NOT EXISTS idx_conversion_rules_media_type ON conversion_rules (media_type_id);

-- ── LDAP (single-row config) ──────────────────────────────────
CREATE TABLE IF NOT EXISTS ldap_settings (
    id                 INTEGER PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    enabled            BOOLEAN     NOT NULL DEFAULT false,
    host               TEXT        NOT NULL DEFAULT '',
    port               INTEGER     NOT NULL DEFAULT 389,
    bind_dn_pattern    TEXT        NOT NULL DEFAULT '',
    search_base_dn     TEXT        NOT NULL DEFAULT '',
    user_search_filter TEXT        NOT NULL DEFAULT '(sAMAccountName={username})',
    updated_at         TIMESTAMPTZ NOT NULL DEFAULT now()
);

INSERT INTO ldap_settings (id, enabled)
VALUES (1, false)
ON CONFLICT (id) DO NOTHING;

CREATE TABLE IF NOT EXISTS ldap_group_roles (
    id         SERIAL PRIMARY KEY,
    ldap_group TEXT        NOT NULL UNIQUE,
    role       TEXT        NOT NULL CHECK (role IN ('admin', 'editor')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ── Split thresholds (1h 11m flag, 3h strong signal) ────────────
INSERT INTO system_settings (key, value) VALUES
    ('split_flag_threshold_seconds', '4260'),
    ('split_strong_threshold_seconds', '10800')
ON CONFLICT (key) DO UPDATE
    SET value = EXCLUDED.value, updated_at = now();

-- ── NAS source labels (NYL / CHL legacy) ──────────────────────
UPDATE sources SET
    name = 'NY Legacy',
    description = 'New York Legacy archive (SNSEVO-NYL)'
WHERE mount_path LIKE '%SNSEVO-NYL%';

UPDATE sources SET
    name = 'Chicago Legacy',
    description = 'Chicago Legacy archive (SNSEVO-CHL)'
WHERE mount_path LIKE '%SNSEVO-CHL%';

-- ── Pilot show: Cuomo ─────────────────────────────────────────
INSERT INTO shows (canonical_name, abbreviation, aliases, notes, active)
SELECT 'Cuomo', 'CUOMO', '["cuomo", "CUOMO", "Cuomo"]', 'Pilot show for media manager rollout', true
WHERE NOT EXISTS (SELECT 1 FROM shows WHERE lower(abbreviation) = 'cuomo');

-- ── Default media-type conversion aliases ───────────────────────
INSERT INTO conversion_rules (category, alias, media_type_id, notes)
SELECT 'media_type', 'live clean', id, 'Maps LIVE CLEAN feeds to Clean'
FROM media_types WHERE lower(name) = 'clean'
  AND NOT EXISTS (
      SELECT 1 FROM conversion_rules WHERE category = 'media_type' AND lower(alias) = 'live clean'
  );

INSERT INTO conversion_rules (category, alias, media_type_id, notes)
SELECT 'media_type', v.alias, mt.id, v.notes
FROM (VALUES
    ('pretape', 'Pre-recorded segment → Clean'),
    ('pre-tape', 'Pre-recorded segment → Clean'),
    ('pre tape', 'Pre-recorded segment → Clean'),
    ('pgm', 'Program shorthand → Program'),
    ('program feed', 'Program feed → Program')
) AS v(alias, notes)
CROSS JOIN media_types mt
WHERE lower(mt.name) = CASE
    WHEN v.alias IN ('pgm', 'program feed') THEN 'program'
    ELSE 'clean'
END
AND NOT EXISTS (
    SELECT 1 FROM conversion_rules cr
    WHERE cr.category = 'media_type' AND lower(cr.alias) = lower(v.alias)
);

INSERT INTO schema_migrations (version)
VALUES ('002_dictionary_setup')
ON CONFLICT (version) DO NOTHING;

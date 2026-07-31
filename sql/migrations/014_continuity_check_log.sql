-- Continuity check decision log (private lab / observability)

CREATE TABLE IF NOT EXISTS continuity_check_log (
    id                      BIGSERIAL PRIMARY KEY,
    original_path           TEXT        NOT NULL,
    original_filename       TEXT        NOT NULL DEFAULT '',
    rule_show_id            INTEGER     NULL,
    rule_show_abbr          TEXT        NULL,
    rule_confidence         TEXT        NOT NULL,
    rule_proposed_filename  TEXT        NULL,
    rule_signals            JSONB       NOT NULL DEFAULT '[]'::jsonb,
    engine_agree            BOOLEAN     NULL,
    engine_confidence       TEXT        NULL,
    engine_show_id          INTEGER     NULL,
    engine_reason           TEXT        NOT NULL DEFAULT '',
    final_confidence        TEXT        NOT NULL,
    final_show_id           INTEGER     NULL,
    final_show_abbr         TEXT        NULL,
    final_proposed_filename TEXT        NULL,
    signal                  TEXT        NOT NULL DEFAULT '',
    outcome                 TEXT        NOT NULL
                            CHECK (outcome IN (
                                'confirmed', 'conflict', 'review', 'error', 'unreachable'
                            )),
    duration_ms             INTEGER     NOT NULL DEFAULT 0,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_continuity_log_created
    ON continuity_check_log (created_at DESC);

CREATE INDEX IF NOT EXISTS idx_continuity_log_outcome
    ON continuity_check_log (outcome);

CREATE INDEX IF NOT EXISTS idx_continuity_log_path
    ON continuity_check_log (original_path);

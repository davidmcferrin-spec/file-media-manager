-- Store continuity seed packet + raw engine reply for Continuity Lab

ALTER TABLE continuity_check_log
    ADD COLUMN IF NOT EXISTS seed_packet JSONB NULL,
    ADD COLUMN IF NOT EXISTS engine_raw TEXT NOT NULL DEFAULT '',
    ADD COLUMN IF NOT EXISTS http_status INTEGER NULL,
    ADD COLUMN IF NOT EXISTS transport_error TEXT NOT NULL DEFAULT '';

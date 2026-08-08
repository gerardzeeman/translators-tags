-- ─────────────────────────────────────────────────────────────────────────────
-- Migration: insert the Statenvertaling (GBS-editie) translation record.
-- Text edition published by the Gereformeerde Bijbelstichting at
-- statenvertaling.nl — distinct from the Zefania-based 'SV' translation
-- (id 1). Same family ('SV') as SV and HSV, not the source-language
-- authority.
-- Safe to run multiple times (ON CONFLICT DO NOTHING).
-- Run on the live DB:
--   docker compose exec postgres psql -U bible -d bible_compare \
--     -f /docker-entrypoint-initdb.d/migrate_add_svgbs_translation.sql
-- ─────────────────────────────────────────────────────────────────────────────

INSERT INTO translations (id, code, name, language, direction, family, source_lang_authority)
VALUES (3, 'SV-GBS', 'Statenvertaling (GBS-editie)', 'nld', 'LTR', 'SV', FALSE)
ON CONFLICT (id) DO NOTHING;

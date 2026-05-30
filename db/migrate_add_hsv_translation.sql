-- ─────────────────────────────────────────────────────────────────────────────
-- Migration: insert the Herziene Statenvertaling (HSV) translation record.
-- Safe to run multiple times (ON CONFLICT DO NOTHING).
-- Run on the live DB:
--   docker compose exec postgres psql -U bible -d bible_compare \
--     -f /docker-entrypoint-initdb.d/migrate_add_hsv_translation.sql
-- ─────────────────────────────────────────────────────────────────────────────

INSERT INTO translations (id, code, name, language, direction)
VALUES (2, 'HSV', 'Herziene Statenvertaling', 'nld', 'LTR')
ON CONFLICT (id) DO NOTHING;

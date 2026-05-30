-- ─────────────────────────────────────────────────────────────────────────────
-- Migration: add translation_id to manual_empty_links,
--            rebuild partial unique indexes to include translation_id.
-- Run once on the live DB:
--   docker compose exec postgres psql -U bible -d bible_compare \
--     -f /docker-entrypoint-initdb.d/migrate_add_translation_to_mel.sql
-- Or paste into psql / TablePlus.
-- ─────────────────────────────────────────────────────────────────────────────

BEGIN;

-- ── 1. Add column with a temporary default so existing rows get translation 1 ─
ALTER TABLE manual_empty_links
    ADD COLUMN IF NOT EXISTS translation_id SMALLINT
        NOT NULL DEFAULT 1
        REFERENCES translations(id) ON DELETE CASCADE;

-- ── 2. Remove the temporary default (new rows must supply translation_id) ────
ALTER TABLE manual_empty_links
    ALTER COLUMN translation_id DROP DEFAULT;

-- ── 3. Rebuild unique indexes to cover (source_word_id, translation_id) ──────
DROP INDEX IF EXISTS idx_mel_he;
DROP INDEX IF EXISTS idx_mel_gr;

CREATE UNIQUE INDEX idx_mel_he
    ON manual_empty_links (hebrew_word_id, translation_id)
    WHERE hebrew_word_id IS NOT NULL;

CREATE UNIQUE INDEX idx_mel_gr
    ON manual_empty_links (greek_word_id, translation_id)
    WHERE greek_word_id IS NOT NULL;

COMMIT;

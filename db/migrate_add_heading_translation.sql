-- ─────────────────────────────────────────────────────────────────────────────
-- Migration: Dutch translation of chapter headings
--
-- segment.heading (the chapter title, e.g. "Dei notitiam et nostri res esse
-- coniunctas...") was never translated -- only the numbered body sections
-- were sent to the LLM. heading_nl holds the Dutch translation, populated by
-- scripts/translate_headings.py (translates each *distinct* heading once,
-- then applies it to every segment row sharing that heading -- heading is
-- already denormalized per segment row this way, so heading_nl follows the
-- same convention rather than introducing a join).
--
-- Apply:
--   docker cp db\migrate_add_heading_translation.sql bible_postgres:/tmp/migrate_add_heading_translation.sql
--   docker exec bible_postgres psql -U bible -d bible_compare -f /tmp/migrate_add_heading_translation.sql
-- ─────────────────────────────────────────────────────────────────────────────

BEGIN;

ALTER TABLE segment ADD COLUMN IF NOT EXISTS heading_nl TEXT;

COMMIT;

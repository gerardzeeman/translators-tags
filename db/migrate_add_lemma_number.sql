-- ─────────────────────────────────────────────────────────────────────────────
-- Migration: stable library numbers for lemma_gloss
--
-- lemma_gloss is already a single lexicon shared across every work (keyed
-- on `lemma` alone, not per-work) -- what it lacks is a stable, ordered
-- identifier a reader can cite, the way Strong's numbers work for the
-- Hebrew/Greek side of this app (see strongs_entries.strongs_id). `number`
-- fills that role: 1 = the most frequent lemma across the whole corpus
-- (currently all 4 works -- the 3 confessions plus the Institutio), 2 =
-- next, and so on. Assigned/recomputed by
-- scripts/build_lemma_library.py, not by this migration -- this only adds
-- the column. A lemma_gloss row whose lemma isn't actually used in any
-- currently-ingested work keeps number = NULL (excluded from the library
-- listing, harmless leftover data otherwise).
--
-- Apply:
--   docker cp db\migrate_add_lemma_number.sql bible_postgres:/tmp/migrate_add_lemma_number.sql
--   docker exec bible_postgres psql -U bible -d bible_compare -f /tmp/migrate_add_lemma_number.sql
-- ─────────────────────────────────────────────────────────────────────────────

BEGIN;

ALTER TABLE lemma_gloss ADD COLUMN IF NOT EXISTS number INT UNIQUE;

COMMIT;

-- ─────────────────────────────────────────────────────────────────────────────
-- Migration: Strong's numbers for the Hebrew and Greek words Calvin quotes
--
-- The Latin of the commentaries quotes Hebrew and Greek words (ברא, στερέωμα).
-- Their word popup shows the Strong's entry -- transliteration, number and
-- first meaning -- like a Latin word's popup shows its lemma and gloss.
-- The link is made by ingest/institutio/scripts/link_commentary_strongs.py:
-- per chapter of the work (0 = no chapter, the Argumentum) and per word as
-- it is printed (token.surface), so it outlives re-tokenizing.
--
-- Apply:
--   docker cp db\migrate_add_work_word_strongs.sql bible_postgres:/tmp/migrate_add_work_word_strongs.sql
--   docker exec bible_postgres psql -U bible -d bible_compare -f /tmp/migrate_add_work_word_strongs.sql
-- ─────────────────────────────────────────────────────────────────────────────

BEGIN;

CREATE TABLE IF NOT EXISTS work_word_strongs (
    work_id    INTEGER     NOT NULL REFERENCES work(id) ON DELETE CASCADE,
    chapter    SMALLINT    NOT NULL,
    surface    TEXT        NOT NULL,
    strongs_id VARCHAR(10) NOT NULL REFERENCES strongs_entries(strongs_id),
    method     TEXT        NOT NULL,
    PRIMARY KEY (work_id, chapter, surface)
);

COMMENT ON TABLE work_word_strongs IS
    'Strong''s entry of a Hebrew/Greek word quoted in a work, per chapter '
    '(0 = none) and printed form; method = how it was found (chapter, lexicon, '
    'book, forms, prefix -- see link_commentary_strongs.py).';

COMMIT;

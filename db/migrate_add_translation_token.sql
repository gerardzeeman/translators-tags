-- ─────────────────────────────────────────────────────────────────────────────
-- Migration: LatinCy tokens for Latin translation-table layers
--
-- `token` (db/migrate_add_institutio_schema.sql) holds LatinCy tokens for
-- segment.text_la only -- the one canonical Latin reading per segment. But
-- since the 'editio-princeps-1563' layer in `translation` is itself Latin
-- (a second, independently-worded reading of the HC, not a Dutch
-- translation -- see parse_hc_latin_1563.py), it needs its own lemmas for
-- word-hover too, wherever its wording actually differs from text_la.
--
-- This is deliberately a separate table rather than repurposing `token`
-- with a nullable segment_id/translation_id pair: `token` guarantees via
-- its schema that every row belongs to exactly one segment's canonical
-- text, which every other reader of that table (attachTokens, the
-- correction UI, corpus_stats) already relies on. A Latin translation
-- layer is the exception, not the rule -- every other layer (denheijer,
-- zwanepol-hsv) is Dutch and will never need this.
--
-- Apply:
--   docker cp db\migrate_add_translation_token.sql bible_postgres:/tmp/migrate_add_translation_token.sql
--   docker exec bible_postgres psql -U bible -d bible_compare -f /tmp/migrate_add_translation_token.sql
-- ─────────────────────────────────────────────────────────────────────────────

BEGIN;

CREATE TABLE IF NOT EXISTS translation_token (
    id              BIGSERIAL PRIMARY KEY,
    translation_id  INT NOT NULL REFERENCES translation(id) ON DELETE CASCADE,
    position        INT NOT NULL,
    surface         TEXT NOT NULL,
    norm            TEXT NOT NULL,
    lemma           TEXT,
    upos            TEXT,
    morph           TEXT,
    char_start      INT NOT NULL,
    char_end        INT NOT NULL,
    is_word         BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE (translation_id, position)
);

CREATE INDEX IF NOT EXISTS idx_translation_token_lemma
    ON translation_token (lemma) WHERE is_word;

COMMENT ON TABLE translation_token IS
    'LatinCy tokens for a Latin `translation` row (currently only the HC''s '
    '''editio-princeps-1563'' layer) -- same shape as `token`, which covers '
    'segment.text_la instead. Enables word-hover lemma/gloss on a Latin '
    'translation layer, not just the segment''s own canonical text.';

COMMIT;

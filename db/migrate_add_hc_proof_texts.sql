-- ─────────────────────────────────────────────────────────────────────────────
-- Migration: Scripture proof texts ("bewijsteksten") for confession segments
--
-- The classic Dutch Heidelberg Catechism carries a lettered apparatus:
-- markers (a), (b), ... in each answer, each with a list of Bible
-- references ("(a) Rom. 14:8. (b) 1 Kor. 6:19."). Ingested from the CGK
-- "klassieke versie" PDF by ingest/institutio/scripts/parse_hc_prooftexts_cgk.py
-- + load_hc_prooftexts.py.
--
-- Deliberately not stored in segment_annotation: those rows are anchored to
-- a character offset in segment.text_la (the Latin), while these letters
-- belong to the Dutch wording. They're anchored instead by a short phrase
-- of the Dutch (Den Heijer) text right before the letter, plus which
-- occurrence of that phrase it is -- looked up at render time, so a
-- correction of the Dutch text elsewhere in the answer doesn't shift the
-- letters (see the parse script's docblock).
--
--   segment_proof_text      — one row per letter per segment
--   segment_proof_text_ref  — one row per verse (range) under that letter,
--                             resolved to the app's `books` table so the
--                             verse text can be looked up (HSV)
--
-- Apply:
--   docker cp db\migrate_add_hc_proof_texts.sql bible_postgres:/tmp/migrate_add_hc_proof_texts.sql
--   docker exec bible_postgres psql -U bible -d bible_compare -f /tmp/migrate_add_hc_proof_texts.sql
-- ─────────────────────────────────────────────────────────────────────────────

BEGIN;

CREATE TABLE IF NOT EXISTS segment_proof_text (
    id                BIGSERIAL PRIMARY KEY,
    segment_id        INT NOT NULL REFERENCES segment(id) ON DELETE CASCADE,
    source            TEXT NOT NULL,           -- e.g. 'cgk-klassiek'
    glyph             TEXT NOT NULL,           -- 'a', 'b', ... as printed
    ordinal           SMALLINT NOT NULL,       -- order within the segment
    anchor            TEXT,                    -- normalized Dutch words right before the letter; NULL = list only
    anchor_occurrence SMALLINT,                -- which occurrence of `anchor` in the Dutch text (1-based)
    refs_text         TEXT NOT NULL,           -- the reference list as printed, e.g. '1 Joh. 1:7; 2:2, 12.'
    UNIQUE (segment_id, source, glyph)
);

CREATE INDEX IF NOT EXISTS idx_segment_proof_text_segment ON segment_proof_text (segment_id, ordinal);

CREATE TABLE IF NOT EXISTS segment_proof_text_ref (
    id             BIGSERIAL PRIMARY KEY,
    proof_text_id  BIGINT NOT NULL REFERENCES segment_proof_text(id) ON DELETE CASCADE,
    ordinal        SMALLINT NOT NULL,
    book_id        SMALLINT NOT NULL REFERENCES books(id),
    chapter        SMALLINT NOT NULL,
    verse_start    SMALLINT NOT NULL,
    verse_end      SMALLINT NOT NULL,
    label          TEXT NOT NULL,              -- e.g. '1 Petr. 1:18-19'
    UNIQUE (proof_text_id, ordinal),
    CHECK (verse_end >= verse_start)
);

COMMENT ON TABLE segment_proof_text IS
    'Lettered Scripture proof texts (bewijsteksten) of a confession segment, '
    'e.g. the classic Dutch Heidelberg Catechism apparatus. Anchored to the '
    'Dutch text by phrase (anchor + anchor_occurrence), not by offset.';

COMMIT;

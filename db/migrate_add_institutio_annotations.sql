-- ─────────────────────────────────────────────────────────────────────────────
-- Migration: Institutio critical-apparatus annotations
--
-- The calvin.reformation.nl source text has inline reference markers in the
-- running Latin text:
--   - letter markers (a, b, c, ...)  -> textual variant in another edition
--     of the Institutio (e.g. "b: 1536-54 cogn. Dei" means editions 1536-54
--     read "cogn. Dei" at this point instead of the 1559 text)
--   - digit markers (1, 2, 3, ...)   -> a citation, usually Scripture or
--     another theological/classical work (e.g. "2: Act. 17,28")
--
-- segment_annotation stores one row per marker, anchored to a character
-- offset into segment.text_la (not a token id) so it survives re-tokenisation
-- and doesn't depend on tokenisation having run first.
--
-- Apply:
--   docker cp db\migrate_add_institutio_annotations.sql bible_postgres:/tmp/migrate_add_institutio_annotations.sql
--   docker exec bible_postgres psql -U bible -d bible_compare -f /tmp/migrate_add_institutio_annotations.sql
-- ─────────────────────────────────────────────────────────────────────────────

BEGIN;

CREATE TABLE IF NOT EXISTS segment_annotation (
    id            BIGSERIAL PRIMARY KEY,
    segment_id    INT NOT NULL REFERENCES segment(id) ON DELETE CASCADE,
    char_position INT NOT NULL,               -- offset into segment.text_la
    glyph         TEXT NOT NULL,              -- the marker as shown in the source: 'a', 'b', '1', '2', ...
    kind          TEXT NOT NULL
                  CHECK (kind IN ('variant', 'citation')),
    note          TEXT NOT NULL,              -- variant reading / citation reference text
    UNIQUE (segment_id, char_position)
);

CREATE INDEX IF NOT EXISTS idx_segment_annotation_segment ON segment_annotation (segment_id);

COMMIT;

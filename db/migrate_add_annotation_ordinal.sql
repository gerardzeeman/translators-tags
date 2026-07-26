-- ─────────────────────────────────────────────────────────────────────────────
-- Migration: annotation ordinal, for annotations that legitimately share a
-- char_position
--
-- Calvin's own inline bracket citations (e.g. "[Gene. 18. d. 27]") are
-- extracted as their own citation annotation and the bracket text is
-- removed from segment.text_la. When an existing footnote marker (variant
-- or citation) happened to sit *inside* that bracket, both annotations now
-- collapse to the exact same char_position (nothing real is left between
-- them once the bracket text is gone). The old UNIQUE (segment_id,
-- char_position) constraint silently dropped every collision but the last
-- one via ON CONFLICT DO UPDATE during load -- confirmed: annotation counts
-- came out lower than the parser actually produced, with gaps in the
-- per-segment citation numbering. `ord` disambiguates same-position
-- annotations while preserving their relative order (0, 1, 2, ... in the
-- order they should render).
--
-- Apply:
--   docker cp db\migrate_add_annotation_ordinal.sql bible_postgres:/tmp/migrate_add_annotation_ordinal.sql
--   docker exec bible_postgres psql -U bible -d bible_compare -f /tmp/migrate_add_annotation_ordinal.sql
-- ─────────────────────────────────────────────────────────────────────────────

BEGIN;

ALTER TABLE segment_annotation ADD COLUMN IF NOT EXISTS ord INT NOT NULL DEFAULT 0;

ALTER TABLE segment_annotation
    DROP CONSTRAINT IF EXISTS segment_annotation_segment_id_char_position_key;

ALTER TABLE segment_annotation
    ADD CONSTRAINT segment_annotation_segment_id_char_position_ord_key
    UNIQUE (segment_id, char_position, ord);

COMMIT;

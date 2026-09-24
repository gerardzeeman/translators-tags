-- ─────────────────────────────────────────────────────────────────────────────
-- Migration: per-layer anchors for proof-text letters
--
-- segment_proof_text (db/migrate_add_hc_proof_texts.sql) anchored each
-- letter in one Dutch text only -- the Den Heijer layer -- through its own
-- anchor/anchor_occurrence columns. The letters are now also shown in the
-- Zwanepol-HSV column, whose wording differs, so a letter needs an anchor
-- per translation layer: segment_proof_text_anchor, one row per letter per
-- layer it can be placed in (no row = only listed under the answer there).
--
-- The existing Den Heijer anchors are copied over. segment_proof_text's own
-- anchor/anchor_occurrence columns are kept for now (the code deployed
-- before this change still reads them, and load_hc_prooftexts.py keeps
-- filling them with the Den Heijer anchor); drop them once this is deployed:
--   ALTER TABLE segment_proof_text DROP COLUMN anchor, DROP COLUMN anchor_occurrence;
--
-- Apply BEFORE deploying the code that reads it:
--   docker cp db\migrate_add_proof_text_layer_anchors.sql bible_postgres:/tmp/migrate_add_proof_text_layer_anchors.sql
--   docker exec bible_postgres psql -U bible -d bible_compare -f /tmp/migrate_add_proof_text_layer_anchors.sql
-- ─────────────────────────────────────────────────────────────────────────────

BEGIN;

CREATE TABLE IF NOT EXISTS segment_proof_text_anchor (
    proof_text_id     BIGINT NOT NULL REFERENCES segment_proof_text(id) ON DELETE CASCADE,
    layer             TEXT NOT NULL,           -- translation.layer, e.g. 'denheijer', 'zwanepol-hsv'
    anchor            TEXT NOT NULL,           -- normalized words of that layer's text right before the letter
    anchor_occurrence SMALLINT NOT NULL,       -- which occurrence of `anchor` in that text (1-based)
    PRIMARY KEY (proof_text_id, layer)
);

INSERT INTO segment_proof_text_anchor (proof_text_id, layer, anchor, anchor_occurrence)
SELECT id, 'denheijer', anchor, anchor_occurrence
FROM segment_proof_text
WHERE anchor IS NOT NULL AND anchor_occurrence IS NOT NULL
ON CONFLICT DO NOTHING;

COMMENT ON TABLE segment_proof_text_anchor IS
    'Where a proof-text letter goes in one translation layer''s text: the '
    'phrase right before it and which occurrence of it. Looked up at render '
    'time (ConfessionRepository::placeProofTextMarkers).';

COMMIT;

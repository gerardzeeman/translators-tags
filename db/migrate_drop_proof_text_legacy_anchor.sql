-- ─────────────────────────────────────────────────────────────────────────────
-- Migration: drop segment_proof_text's legacy anchor columns
--
-- segment_proof_text.anchor/anchor_occurrence (db/migrate_add_hc_proof_texts.sql)
-- anchored a proof-text letter in the Den Heijer text only. They were
-- superseded by the per-layer segment_proof_text_anchor table
-- (db/migrate_add_proof_text_layer_anchors.sql) and kept only while the
-- code deployed before that change still read them. Nothing reads or
-- writes them anymore: the app reads segment_proof_text_anchor, and
-- load_hc_prooftexts.py no longer fills them.
--
-- Safe to apply any time after the per-layer anchors are deployed.
--
-- Apply:
--   docker cp db\migrate_drop_proof_text_legacy_anchor.sql bible_postgres:/tmp/migrate_drop_proof_text_legacy_anchor.sql
--   docker exec bible_postgres psql -U bible -d bible_compare -f /tmp/migrate_drop_proof_text_legacy_anchor.sql
-- ─────────────────────────────────────────────────────────────────────────────

BEGIN;

ALTER TABLE segment_proof_text
    DROP COLUMN IF EXISTS anchor,
    DROP COLUMN IF EXISTS anchor_occurrence;

COMMENT ON TABLE segment_proof_text IS
    'Lettered Scripture proof texts (bewijsteksten) of a confession segment, '
    'e.g. the classic Dutch Heidelberg Catechism apparatus. Where each letter '
    'goes in a translation layer''s text is in segment_proof_text_anchor.';

COMMIT;

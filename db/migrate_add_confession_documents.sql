-- ─────────────────────────────────────────────────────────────────────────────
-- Migration: confessional-document support for the Institutio pipeline schema
--
-- The Institutio pipeline (db/migrate_add_institutio_schema.sql) already
-- generalizes across `work`s via work_id -- this migration only adds the two
-- things that pipeline didn't anticipate when the confessional documents
-- (Dordtse Leerregels / Canones, Nederlandse Geloofsbelijdenis, Heidelbergse
-- Catechismus) were planned as new works in that same structure:
--
--   segment.kind               — Canones Dordraceni interleaves numbered
--                                 "Articulus" paragraphs with a per-chapter
--                                 "Rejectio Errorum" block that has its own,
--                                 separately-numbered paragraphs. Neither
--                                 book/chapter/section (a plain numeric
--                                 hierarchy) nor `ref` alone can distinguish
--                                 the two without a text convention, so a
--                                 small nullable classifier column is added
--                                 instead. NULL (the default, and every
--                                 existing Institutio segment) means "not
--                                 applicable / ordinary numbered paragraph".
--   segment_text_correction     — Latin source text digitized from historical
--                                 OCR (unlike the Institutio's clean maintained
--                                 source website) is expected to need manual
--                                 correction after review. segment.text_la
--                                 itself has no versioning; this table is an
--                                 append-only audit trail of corrections made
--                                 through the small text-correction UI, so a
--                                 fix is traceable without re-ingesting.
--
-- Apply:
--   docker cp db\migrate_add_confession_documents.sql bible_postgres:/tmp/migrate_add_confession_documents.sql
--   docker exec bible_postgres psql -U bible -d bible_compare -f /tmp/migrate_add_confession_documents.sql
-- ─────────────────────────────────────────────────────────────────────────────

BEGIN;

ALTER TABLE segment
    ADD COLUMN IF NOT EXISTS kind TEXT
        CHECK (kind IS NULL OR kind IN ('article', 'rejection'));

COMMENT ON COLUMN segment.kind IS
    'Distinguishes a Canones-style "Rejectio Errorum" paragraph from an '
    'ordinary numbered "Articulus" paragraph, both of which otherwise share '
    'the same book/chapter/section numeric hierarchy. NULL for every other '
    'work (e.g. the Institutio), where it does not apply.';

CREATE TABLE IF NOT EXISTS segment_text_correction (
    id                  BIGSERIAL PRIMARY KEY,
    segment_id          INT NOT NULL REFERENCES segment(id) ON DELETE CASCADE,
    old_text            TEXT NOT NULL,
    new_text            TEXT NOT NULL,
    note                TEXT,
    corrected_by_user_id INT,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_segment_text_correction_segment
    ON segment_text_correction (segment_id, created_at);

COMMENT ON TABLE segment_text_correction IS
    'Append-only audit trail of manual corrections to segment.text_la (e.g. '
    'fixing OCR errors found after ingest). segment.text_la is updated '
    'in place; this table only records the history of what changed and why.';

COMMIT;

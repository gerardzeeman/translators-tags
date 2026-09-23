-- ─────────────────────────────────────────────────────────────────────────────
-- Migration: direct-write correction trail for confession-document Dutch
-- translations
--
-- Mirrors segment_text_correction (db/migrate_add_confession_documents.sql)
-- but for a `translation` row's text_nl instead of a segment's text_la --
-- same direct-write-with-audit-trail pattern as the existing belijdenis-
-- geschriften Latin-text corrector
-- (ConfessionController::editText/saveText), applied to the Dutch
-- translation layers instead (denheijer, zwanepol-hsv, traditioneel, ...).
--
-- Deliberately NOT the Institutio propose-then-review flow
-- (translation_proposal): that requires a second, distinct reviewer account
-- to approve a change, which doesn't fit a single-editor correction
-- workflow. Also deliberately NOT reusable for the HC's
-- 'editio-princeps-1563' layer, which is Latin, not a Dutch translation --
-- editing it would need to invalidate translation_token the way
-- saveTextCorrection() invalidates token, which this simpler flow doesn't
-- do; ConfessionRepository::getTranslationForEdit() refuses that layer.
--
-- Apply:
--   docker cp db\migrate_add_translation_text_correction.sql bible_postgres:/tmp/migrate_add_translation_text_correction.sql
--   docker exec bible_postgres psql -U bible -d bible_compare -f /tmp/migrate_add_translation_text_correction.sql
-- ─────────────────────────────────────────────────────────────────────────────

BEGIN;

CREATE TABLE IF NOT EXISTS translation_text_correction (
    id                    BIGSERIAL PRIMARY KEY,
    translation_id        INT NOT NULL REFERENCES translation(id) ON DELETE CASCADE,
    old_text              TEXT NOT NULL,
    new_text              TEXT NOT NULL,
    note                  TEXT,
    corrected_by_user_id  INT,
    created_at            TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_translation_text_correction_translation
    ON translation_text_correction (translation_id, created_at);

COMMENT ON TABLE translation_text_correction IS
    'Append-only audit trail of manual corrections to translation.text_nl '
    '(e.g. fixing a typo found in a confession document''s Dutch '
    'translation). translation.text_nl is updated in place; this table '
    'only records the history of what changed and why.';

COMMIT;

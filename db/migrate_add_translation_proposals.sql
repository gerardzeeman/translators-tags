-- ─────────────────────────────────────────────────────────────────────────────
-- Migration: translation proposal + review workflow
--
-- Replaces direct-editing on /institutie/bewerk/{id} with a propose-then-
-- review flow: a translator submits a change with a stated reason instead
-- of overwriting the text directly; another user (a distinct reviewer role,
-- see security.yaml's ROLE_REVIEW_INSTITUTIO_TRNL) approves or rejects it,
-- and only an approval actually applies the new text. Rejection isn't
-- final -- discussion can continue and the same proposal can still be
-- approved afterwards.
--
-- translation_proposal: one row per proposed change, either row-scoped
-- (sentence_alignment_id set, for an already sentence-aligned segment) or
-- whole-segment (sentence_alignment_id NULL, for a not-yet-aligned
-- segment's single textarea). word_diff is a JSON-encoded array produced
-- by App\Service\WordDiffer::diff() at creation time -- computed
-- automatically from old_text/new_text, never entered by hand. Stored as
-- plain TEXT (json_encode/json_decode in PHP), not JSONB: nothing in this
-- codebase ever queries *inside* a JSON column, and every other
-- structured value here (resolveAlignedRows, splitLatinSentences) is
-- already hand-rolled PHP-side rather than reaching for a Postgres-native
-- type.
--
-- sentence_alignment_id uses ON DELETE SET NULL rather than CASCADE:
-- InstitutioRepository::saveSegmentAlignment() (the drag-based alignment
-- editor) deletes and reinserts *all* sentence_alignment rows for a
-- translation on every save, with fresh ids. A CASCADE would silently
-- destroy an in-flight proposal's entire discussion history the next time
-- someone re-splits that segment's rows; SET NULL keeps the proposal (and
-- its old_text/new_text/reason/events) intact, just no longer pointing at
-- a specific row.
--
-- The two partial unique indexes enforce "at most one active (non-
-- approved) proposal per target at a time" at the database level --
-- pending and rejected both count as active (rejection isn't final), only
-- 'approved' frees up the target for a new proposal.
--
-- translation_proposal_event: the discussion/decision timeline. kind
-- 'comment' is a plain message; 'approve'/'reject' are decision events
-- (body required at the application level for 'reject' and 'comment',
-- optional for 'approve') -- the proposal's current status is simply
-- whichever of these was most recently applied, tracked redundantly on
-- translation_proposal.status for cheap lookups (e.g. "does this row have
-- an active proposal") without needing to re-scan the event log.
--
-- Apply:
--   docker cp db\migrate_add_translation_proposals.sql bible_postgres:/tmp/migrate_add_translation_proposals.sql
--   docker exec bible_postgres psql -U bible -d bible_compare -f /tmp/migrate_add_translation_proposals.sql
-- ─────────────────────────────────────────────────────────────────────────────

BEGIN;

CREATE TABLE IF NOT EXISTS translation_proposal (
    id                     SERIAL PRIMARY KEY,
    segment_id             INTEGER NOT NULL REFERENCES segment(id) ON DELETE CASCADE,
    translation_id         INTEGER NOT NULL REFERENCES translation(id) ON DELETE CASCADE,
    sentence_alignment_id  BIGINT NULL REFERENCES sentence_alignment(id) ON DELETE SET NULL,
    old_text               TEXT NOT NULL,
    new_text               TEXT NOT NULL,
    reason                 TEXT NOT NULL,
    word_diff              TEXT NOT NULL,
    status                 TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'rejected')),
    created_by_user_id     INTEGER NOT NULL REFERENCES users(id),
    created_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
    resolved_at            TIMESTAMPTZ NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_translation_proposal_active_row
    ON translation_proposal (sentence_alignment_id)
    WHERE status != 'approved' AND sentence_alignment_id IS NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uq_translation_proposal_active_segment
    ON translation_proposal (segment_id)
    WHERE status != 'approved' AND sentence_alignment_id IS NULL;

CREATE INDEX IF NOT EXISTS idx_translation_proposal_segment ON translation_proposal (segment_id);

CREATE TABLE IF NOT EXISTS translation_proposal_event (
    id           SERIAL PRIMARY KEY,
    proposal_id  INTEGER NOT NULL REFERENCES translation_proposal(id) ON DELETE CASCADE,
    user_id      INTEGER NOT NULL REFERENCES users(id),
    kind         TEXT NOT NULL CHECK (kind IN ('comment', 'approve', 'reject')),
    body         TEXT NULL,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_translation_proposal_event_proposal
    ON translation_proposal_event (proposal_id, created_at);

COMMIT;

-- ─────────────────────────────────────────────────────────────────────────────
-- Migration: drop pivot links, rename heuristic → specific methods,
--            update link_confidence CHECK constraint.
-- Run once on the live DB:
--   docker compose exec postgres psql -U bible -d bible_compare -f /docker-entrypoint-initdb.d/migrate_drop_pivot.sql
-- Or paste into psql / TablePlus.
-- ─────────────────────────────────────────────────────────────────────────────

BEGIN;

-- ── 1. Drop the old constraint (no replacement yet — table still has old rows) ─
ALTER TABLE link_confidence
    DROP CONSTRAINT IF EXISTS link_confidence_method_check;

-- ── 2. Delete all pivot-only word_links ──────────────────────────────────────
--    Cascade removes their link_confidence rows automatically.
DELETE FROM word_links wl
WHERE EXISTS (
    SELECT 1 FROM link_confidence lc
    WHERE lc.link_id = wl.id AND lc.method = 'pivot'
)
AND NOT EXISTS (
    SELECT 1 FROM link_confidence lc
    WHERE lc.link_id = wl.id AND lc.method IN ('manual', 'heuristic')
);

-- Remove any orphaned pivot rows (links that had both pivot + heuristic)
DELETE FROM link_confidence WHERE method = 'pivot';

-- ── 3. Migrate 'heuristic' rows to their specific method names ───────────────
--    notes LIKE 'manual_hint:%'  → method = 'manual_hint'
--    notes = 'proper_noun'       → method = 'proper_noun'
--    anything else               → method = 'positional'

INSERT INTO link_confidence (link_id, method, score, created_at, notes)
SELECT
    link_id,
    CASE
        WHEN notes LIKE 'manual_hint:%' THEN 'manual_hint'
        WHEN notes = 'proper_noun'      THEN 'proper_noun'
        ELSE                                 'positional'
    END AS method,
    score,
    created_at,
    notes
FROM link_confidence
WHERE method = 'heuristic'
ON CONFLICT (link_id, method) DO NOTHING;

DELETE FROM link_confidence WHERE method = 'heuristic';

-- ── 4. Add the new constraint now that the table is clean ────────────────────
ALTER TABLE link_confidence
    ADD CONSTRAINT link_confidence_method_check
    CHECK (method IN ('manual', 'manual_hint', 'proper_noun', 'positional'));

COMMIT;

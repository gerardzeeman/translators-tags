-- ─────────────────────────────────────────────────────────────────────────────
-- Migration: Calvin's commentaries as works in the Institutio schema
--
-- First commentary: Genesis (work 'calvijn-genesis', see
-- ingest/institutio/scripts/parse_calvin_genesis_la.py). Its segments are of
-- three kinds, next to the Canones' existing 'article'/'rejection':
--   argument    -- the Argumentum (Calvin's introduction), per paragraph
--   scripture   -- Calvin's own Latin rendering of one Bible verse
--   commentary  -- his comment on a verse (or a few verses)
--
-- segment.print_ref: where a segment starts in the printed edition, for
-- citation -- e.g. 'CO 23, 65' (Calvini Opera vol. 23, column 65). NULL for
-- works that don't need it (the Institutio is cited by its own numbering).
--
-- Apply:
--   docker cp db\migrate_add_commentary_segments.sql bible_postgres:/tmp/migrate_add_commentary_segments.sql
--   docker exec bible_postgres psql -U bible -d bible_compare -f /tmp/migrate_add_commentary_segments.sql
-- ─────────────────────────────────────────────────────────────────────────────

BEGIN;

ALTER TABLE segment DROP CONSTRAINT IF EXISTS segment_kind_check;
ALTER TABLE segment ADD CONSTRAINT segment_kind_check
    CHECK (kind IS NULL OR kind IN ('article', 'rejection', 'argument', 'scripture', 'commentary'));

ALTER TABLE segment ADD COLUMN IF NOT EXISTS print_ref TEXT;

COMMENT ON COLUMN segment.print_ref IS
    'Where the segment starts in the printed edition, for citation (e.g. '
    '''CO 23, 65''). NULL where the work has its own numbering.';

COMMIT;

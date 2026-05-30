-- ─────────────────────────────────────────────────────────────────────────────
-- Migration: add partial unique indexes to word_links to prevent duplicate
-- (source_word_id, translation_word_id) pairs.
-- Safe to run multiple times (IF NOT EXISTS).
--
-- NOTE: If duplicates already exist, remove them first:
--   DELETE FROM word_links wl
--   WHERE wl.id NOT IN (
--       SELECT MIN(id) FROM word_links
--       GROUP BY hebrew_word_id, translation_word_id
--       HAVING hebrew_word_id IS NOT NULL
--     UNION ALL
--       SELECT MIN(id) FROM word_links
--       GROUP BY greek_word_id, translation_word_id
--       HAVING greek_word_id IS NOT NULL
--   );
--
-- Run on the live DB:
--   docker compose exec postgres psql -U bible -d bible_compare \
--     -f /docker-entrypoint-initdb.d/migrate_add_word_links_unique.sql
-- ─────────────────────────────────────────────────────────────────────────────

-- Remove duplicate rows, keeping the lowest id in each group.
DELETE FROM word_links
WHERE id NOT IN (
    SELECT MIN(id)
    FROM word_links
    WHERE hebrew_word_id IS NOT NULL
    GROUP BY hebrew_word_id, translation_word_id
  UNION ALL
    SELECT MIN(id)
    FROM word_links
    WHERE greek_word_id IS NOT NULL
    GROUP BY greek_word_id, translation_word_id
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_wl_he_tw
    ON word_links (hebrew_word_id, translation_word_id)
    WHERE hebrew_word_id IS NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS idx_wl_gr_tw
    ON word_links (greek_word_id, translation_word_id)
    WHERE greek_word_id IS NOT NULL;

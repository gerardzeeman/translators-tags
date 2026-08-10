-- ─────────────────────────────────────────────────────────────────────────────
-- Migration: add cross_references (verse-level "zie ook"-verwijzingen).
-- Safe to run multiple times.
-- Run on the live DB:
--   docker compose exec postgres psql -U bible -d bible_compare \
--     -f /docker-entrypoint-initdb.d/migrate_add_cross_references.sql
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS cross_references (
    id              SERIAL   PRIMARY KEY,
    source          VARCHAR(20) NOT NULL,
    book_id         SMALLINT NOT NULL REFERENCES books(id),
    chapter         SMALLINT NOT NULL,
    verse           SMALLINT NOT NULL,
    ordinal         SMALLINT NOT NULL,
    target_book_id  SMALLINT NOT NULL REFERENCES books(id),
    target_chapter  SMALLINT NOT NULL,
    target_verse    SMALLINT NOT NULL,
    label           TEXT     NOT NULL,
    UNIQUE (source, book_id, chapter, verse, ordinal)
);
CREATE INDEX IF NOT EXISTS idx_cross_ref_verse ON cross_references (source, book_id, chapter, verse);

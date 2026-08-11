-- ─────────────────────────────────────────────────────────────────────────────
-- Migration: rework cross_references to word-position-anchored letter markers
-- (adds `letter` + `word_position`, drops the old verse-level rows -- the
-- ingest scripts fully repopulate the table on next run).
-- Safe to run multiple times.
-- Run on the live DB:
--   docker compose exec postgres psql -U bible -d bible_compare \
--     -f /docker-entrypoint-initdb.d/migrate_rework_cross_references_letters.sql
-- ─────────────────────────────────────────────────────────────────────────────

DROP TABLE IF EXISTS cross_references;

CREATE TABLE cross_references (
    id              SERIAL   PRIMARY KEY,
    source          VARCHAR(20) NOT NULL,
    book_id         SMALLINT NOT NULL REFERENCES books(id),
    chapter         SMALLINT NOT NULL,
    verse           SMALLINT NOT NULL,
    letter          VARCHAR(3) NOT NULL,
    word_position   SMALLINT NOT NULL,
    ordinal         SMALLINT NOT NULL,
    target_book_id  SMALLINT NOT NULL REFERENCES books(id),
    target_chapter  SMALLINT NOT NULL,
    target_verse    SMALLINT NOT NULL,
    label           TEXT     NOT NULL,
    UNIQUE (source, book_id, chapter, verse, letter, ordinal)
);
CREATE INDEX idx_cross_ref_verse ON cross_references (source, book_id, chapter, verse);

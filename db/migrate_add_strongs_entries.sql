-- Migration: add strongs_entries table
-- Stores one row per Strong's number (H1–H8674, G1–G5624).
-- Run once against a live database: psql ... -f migrate_add_strongs_entries.sql

CREATE TABLE IF NOT EXISTS strongs_entries (
    strongs_id      VARCHAR(10)  PRIMARY KEY,   -- e.g. 'H1', 'G1'
    lang            CHAR(2)      NOT NULL CHECK (lang IN ('HE', 'GR')),
    lemma           TEXT,                        -- pointed Hebrew / Greek Unicode
    transliteration TEXT,                        -- romanised form
    pronunciation   TEXT,                        -- phonetic guide
    pos             TEXT,                        -- part of speech (POS attribute)
    morph           TEXT,                        -- morphology code
    definition      TEXT,                        -- numbered definitions joined
    etymology       TEXT,                        -- origin / derivation note
    kjv_renderings  TEXT,                        -- KJV translation renderings
    short_def       TEXT                         -- brief gloss / explanation
);

CREATE INDEX IF NOT EXISTS idx_strongs_entries_lang ON strongs_entries (lang);

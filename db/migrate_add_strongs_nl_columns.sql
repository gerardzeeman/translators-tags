-- Migration: add Dutch translation columns to strongs_entries
ALTER TABLE strongs_entries
    ADD COLUMN IF NOT EXISTS definition_nl TEXT,
    ADD COLUMN IF NOT EXISTS etymology_nl  TEXT,
    ADD COLUMN IF NOT EXISTS short_def_nl  TEXT;

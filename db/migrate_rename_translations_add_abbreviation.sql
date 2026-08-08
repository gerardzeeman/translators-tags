-- ─────────────────────────────────────────────────────────────────────────────
-- Migration: add translations.abbreviation (short button/badge label) and
-- rename the SV and SV-GBS translation records:
--   - id 1: name 'Statenvertaling (Jongbloed)' -> 'Statenvertaling Jongbloed',
--           abbreviation 'SV(JB)'
--   - id 2: abbreviation 'HSV' (name unchanged)
--   - id 3: code 'SV-GBS' -> 'SVGBS' (no hyphen: keeps strtolower(code) a valid
--           Twig property name, e.g. word.links_svgbs / word.dutch_verse_svgbs),
--           name 'Statenvertaling (GBS-editie)' -> 'Statenvertaling (GBS)',
--           abbreviation 'SV(GBS)'
-- Safe to run multiple times.
-- Run on the live DB:
--   docker compose exec postgres psql -U bible -d bible_compare \
--     -f /docker-entrypoint-initdb.d/migrate_rename_translations_add_abbreviation.sql
-- ─────────────────────────────────────────────────────────────────────────────

ALTER TABLE translations ADD COLUMN IF NOT EXISTS abbreviation VARCHAR(20);

UPDATE translations SET code = 'SVGBS' WHERE code = 'SV-GBS';

UPDATE translations SET name = 'Statenvertaling Jongbloed', abbreviation = 'SV(JB)'  WHERE code = 'SV';
UPDATE translations SET abbreviation = 'HSV'                                         WHERE code = 'HSV';
UPDATE translations SET name = 'Statenvertaling (GBS)',    abbreviation = 'SV(GBS)'  WHERE code = 'SVGBS';

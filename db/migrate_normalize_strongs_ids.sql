-- Migration: normalize strongs_entries primary keys to canonical form
-- Canonical form: prefix letter (H or G) + integer with NO leading zeros
-- e.g. G00037 -> G37, H0853 -> H853
--
-- Safe to run multiple times (WHERE clause limits to rows that need updating).

UPDATE strongs_entries
SET strongs_id = left(strongs_id, 1) || ltrim(substring(strongs_id from 2), '0')
WHERE strongs_id ~ '^[HG]0';

-- Edge case: if ltrim removed all digits (strongs_id was e.g. 'H000'), restore a '0'
UPDATE strongs_entries
SET strongs_id = strongs_id || '0'
WHERE strongs_id IN ('H', 'G');

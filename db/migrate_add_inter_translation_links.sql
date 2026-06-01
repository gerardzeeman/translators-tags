-- ─────────────────────────────────────────────────────────────────────────────
-- Migration: inter-translation linking foundation
--
-- Adds:
--   translations.family                  — groups related translations (e.g. 'SV')
--   translations.source_lang_authority   — marks which translation is the anchor
--                                          for source-language (Hebrew/Greek) links
--   translation_words.is_filler          — marks words with no source-language
--                                          backing (HSV cursive / "add" words)
--   inter_translation_links              — links words across any two translations
--
-- Apply:
--   docker cp db\migrate_add_inter_translation_links.sql bible_postgres:/tmp/migrate_add_inter_translation_links.sql
--   docker exec bible_postgres psql -U bible -d bible_compare -f /tmp/migrate_add_inter_translation_links.sql
-- ─────────────────────────────────────────────────────────────────────────────

BEGIN;

-- ── translations: family + authority columns ──────────────────────────────────

ALTER TABLE translations
    ADD COLUMN IF NOT EXISTS family               VARCHAR(20),
    ADD COLUMN IF NOT EXISTS source_lang_authority BOOLEAN NOT NULL DEFAULT FALSE;

-- SV (Jongbloed) is the authority: its word_links to Hebrew/Greek are the
-- primary source and propagate to all other family members.
UPDATE translations SET family = 'SV', source_lang_authority = TRUE  WHERE code = 'SV';
UPDATE translations SET family = 'SV', source_lang_authority = FALSE WHERE code = 'HSV';

-- ── translation_words: filler flag ────────────────────────────────────────────

ALTER TABLE translation_words
    ADD COLUMN IF NOT EXISTS is_filler BOOLEAN NOT NULL DEFAULT FALSE;

COMMENT ON COLUMN translation_words.is_filler IS
    'True for words that have no source-language backing (e.g. HSV cursive/'
    '"add" words printed in italics/brackets in the printed edition). '
    'These are excluded from source-link propagation.';

-- ── inter_translation_links ───────────────────────────────────────────────────
-- Links a word in one translation to the semantically equivalent word in
-- another translation within the same verse.
--
-- word_a_id < word_b_id is enforced so (A,B) and (B,A) are the same row —
-- queries must check both directions (or use the two indexes).
--
-- method values:
--   auto_source_pivot  — matched because both words share the same source word
--   auto_sequence      — matched by dynamic-programming sequence alignment
--   auto_positional    — last-resort positional fallback
--   manual             — human-confirmed link
--   manual_empty       — human-confirmed: these two words do NOT correspond

CREATE TABLE IF NOT EXISTS inter_translation_links (
    id          SERIAL       PRIMARY KEY,
    word_a_id   INTEGER      NOT NULL REFERENCES translation_words(id) ON DELETE CASCADE,
    word_b_id   INTEGER      NOT NULL REFERENCES translation_words(id) ON DELETE CASCADE,
    method      VARCHAR(30)  NOT NULL DEFAULT 'auto_source_pivot'
                    CHECK (method IN (
                        'auto_source_pivot', 'auto_sequence', 'auto_positional',
                        'manual', 'manual_empty'
                    )),
    -- 0–100 confidence score; NULL means manually set (never auto-overwritten)
    confidence  SMALLINT     CHECK (confidence BETWEEN 0 AND 100),
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    CONSTRAINT itl_ordered CHECK (word_a_id < word_b_id),
    UNIQUE (word_a_id, word_b_id)
);

-- Both directions indexed so lookups starting from either side are fast
CREATE INDEX IF NOT EXISTS idx_itl_word_a ON inter_translation_links (word_a_id);
CREATE INDEX IF NOT EXISTS idx_itl_word_b ON inter_translation_links (word_b_id);

COMMENT ON TABLE inter_translation_links IS
    'Word-level alignment between any two translations (e.g. SV ↔ HSV). '
    'word_a_id < word_b_id is always enforced. '
    'Populated by the app:link:translations:auto Symfony command.';

COMMIT;

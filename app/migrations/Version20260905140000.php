<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the four "library" tables that let a manual link made in the 4-way
 * historical-alignment review UI be promoted into a reusable rule, feeding
 * back into HistoricalAlignmentService's matching pipeline for every future
 * verse -- without needing a code deploy.
 *
 * These mirror the shape of the service's hardcoded DEFAULT_LEXICON /
 * DEFAULT_SYNONYM_BRIDGE / DEFAULT_MULTI_SYNONYM_BRIDGE / DEFAULT_PHRASE_BRIDGE
 * constants exactly (see HistoricalAlignmentService) and start EMPTY: the
 * hardcoded constants remain the tested baseline, these tables only hold
 * user-contributed additions merged on top at construction time.
 *
 *  - alignment_lexicon: 1 historical spelling -> 1 modern form (same word).
 *    UNIQUE(source_form) -- a given historical form has exactly one
 *    normalisation.
 *  - alignment_synonym_bridge: 1 word -> 1 alternative word (real synonym).
 *    UNIQUE(source_form, target_form) -- a source word may have several
 *    acceptable alternatives (several rows), matching how bridgeSynonyms()
 *    picks the first matching one via in_array().
 *  - alignment_multi_synonym_bridge: 1 word -> a set of words that must ALL
 *    appear together (e.g. 'doodsloeg' -> 'sloeg'+'dood'). UNIQUE(source_form)
 *    -- one decomposition per historical word.
 *  - alignment_phrase_bridge: N source words <-> M target words (phrase-level).
 *    UNIQUE(source_forms, target_forms) via whole-array equality.
 *
 * source_link_id traces an entry back to the inter_translation_links row
 * that prompted it (nullable: that link can later be deleted independently).
 */
final class Version20260905140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add alignment_lexicon/synonym_bridge/multi_synonym_bridge/phrase_bridge library tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE IF NOT EXISTS alignment_lexicon (
                id                  SERIAL PRIMARY KEY,
                source_form         VARCHAR(100) NOT NULL,
                target_form         VARCHAR(100) NOT NULL,
                source_link_id      INTEGER REFERENCES inter_translation_links(id) ON DELETE SET NULL,
                created_by_user_id  INTEGER REFERENCES users(id),
                created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                UNIQUE (source_form)
            )
        ');

        $this->addSql('
            CREATE TABLE IF NOT EXISTS alignment_synonym_bridge (
                id                  SERIAL PRIMARY KEY,
                source_form         VARCHAR(100) NOT NULL,
                target_form         VARCHAR(100) NOT NULL,
                source_link_id      INTEGER REFERENCES inter_translation_links(id) ON DELETE SET NULL,
                created_by_user_id  INTEGER REFERENCES users(id),
                created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                UNIQUE (source_form, target_form)
            )
        ');

        $this->addSql('
            CREATE TABLE IF NOT EXISTS alignment_multi_synonym_bridge (
                id                  SERIAL PRIMARY KEY,
                source_form         VARCHAR(100) NOT NULL,
                target_forms        TEXT[] NOT NULL,
                source_link_id      INTEGER REFERENCES inter_translation_links(id) ON DELETE SET NULL,
                created_by_user_id  INTEGER REFERENCES users(id),
                created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                UNIQUE (source_form)
            )
        ');

        $this->addSql('
            CREATE TABLE IF NOT EXISTS alignment_phrase_bridge (
                id                  SERIAL PRIMARY KEY,
                source_forms        TEXT[] NOT NULL,
                target_forms        TEXT[] NOT NULL,
                source_link_id      INTEGER REFERENCES inter_translation_links(id) ON DELETE SET NULL,
                created_by_user_id  INTEGER REFERENCES users(id),
                created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                UNIQUE (source_forms, target_forms)
            )
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS alignment_phrase_bridge');
        $this->addSql('DROP TABLE IF EXISTS alignment_multi_synonym_bridge');
        $this->addSql('DROP TABLE IF EXISTS alignment_synonym_bridge');
        $this->addSql('DROP TABLE IF EXISTS alignment_lexicon');
    }
}

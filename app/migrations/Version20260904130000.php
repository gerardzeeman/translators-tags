<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds translation_words.alignment_note, marking SV1657 words that
 * HistoricalAlignmentService systematically excludes from the alignment
 * (double-negation particles, known dropped prefixes -- see
 * findNegationParticles()/dropKnownPrefixes() in HistoricalAlignmentService).
 *
 * Needed for the plan sectie 4 score formula ("systematisch weggelaten
 * telt NERGENS mee -- niet in de teller, niet in de maximaal haalbare
 * score") and the sectie 6 review UI (grey dashed line, informational).
 * There is no natural way to represent "this source word has no expected
 * target" as an inter_translation_links row (word_b_id is NOT NULL there,
 * by design -- a link always connects two real words), so this is tracked
 * directly on the word instead.
 */
final class Version20260904130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add translation_words.alignment_note for systematically-excluded alignment positions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE translation_words ADD COLUMN IF NOT EXISTS alignment_note VARCHAR(20)
                CHECK (alignment_note IN ('particle_drop', 'prefix_drop'))
            SQL);
        $this->addSql("COMMENT ON COLUMN translation_words.alignment_note IS 'Set by HistoricalAlignmentService on SV1657-side words: particle_drop (Middle Dutch en...niet double negation) or prefix_drop (known isolated-dropped prefix, e.g. op dat -> dat). Excluded from historical-alignment scoring, both numerator and denominator.'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE translation_words DROP COLUMN IF EXISTS alignment_note');
    }
}

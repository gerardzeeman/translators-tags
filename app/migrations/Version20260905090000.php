<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds translations.alignment_sequence: an alternative to the star/pivot
 * topology (is_alignment_pivot), letting the 4-way historical-alignment
 * pipeline instead chain adjacent editions together --
 * SV1657(1) <-> SV(2) <-> SVGBS(3) <-> HSV(4) -- rather than pivoting
 * everything through one central translation.
 *
 * Both topologies can coexist: is_alignment_pivot still selects the
 * star-mode pairs (fetchHistoricalAlignmentPairs), alignment_sequence
 * selects the chain-mode pairs (fetchChainAlignmentPairs), and
 * `app:link:translations:auto --engine=historical --topology=star|chain`
 * picks between them. Chronological order (oldest spelling to most
 * modern/paraphrased) was chosen for the chain since that's the axis the
 * alignment pipeline actually normalises across.
 */
final class Version20260905090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add translations.alignment_sequence for chain-topology historical alignment';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE translations ADD COLUMN IF NOT EXISTS alignment_sequence SMALLINT');
        $this->addSql("UPDATE translations SET alignment_sequence = 1 WHERE code = 'SV1657'");
        $this->addSql("UPDATE translations SET alignment_sequence = 2 WHERE code = 'SV'");
        $this->addSql("UPDATE translations SET alignment_sequence = 3 WHERE code = 'SVGBS'");
        $this->addSql("UPDATE translations SET alignment_sequence = 4 WHERE code = 'HSV'");
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS idx_translations_alignment_sequence ON translations (family, alignment_sequence) WHERE alignment_sequence IS NOT NULL');
        $this->addSql("COMMENT ON COLUMN translations.alignment_sequence IS 'Position in the chain-topology alignment order within this family (1=oldest spelling). NULL for translations not part of any chain. See is_alignment_pivot for the alternative star topology.'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_translations_alignment_sequence');
        $this->addSql('ALTER TABLE translations DROP COLUMN IF EXISTS alignment_sequence');
    }
}

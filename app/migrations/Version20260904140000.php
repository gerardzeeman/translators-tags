<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Moves the 4-way historical-alignment pivot from SV1657 to SV (Jongbloed):
 * all three other editions (SV1657, SVGBS, HSV) now align against SV as
 * their common source, not against SV1657. Existing inter_translation_links
 * rows computed under the old SV1657 pivot are not touched by this
 * migration -- they need a fresh `app:link:translations:auto
 * --engine=historical` run to be recomputed against the new pivot, since
 * flipping which side is "source" changes what the alignment pipeline
 * discovers (e.g. compound-detection groups multiple SOURCE words into
 * one target, which is a different pattern in each direction).
 */
final class Version20260904140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Move historical-alignment pivot from SV1657 to SV (Jongbloed)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE translations SET is_alignment_pivot = TRUE WHERE code = 'SV'");
        $this->addSql("UPDATE translations SET is_alignment_pivot = FALSE WHERE code = 'SV1657'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE translations SET is_alignment_pivot = TRUE WHERE code = 'SV1657'");
        $this->addSql("UPDATE translations SET is_alignment_pivot = FALSE WHERE code = 'SV'");
    }
}

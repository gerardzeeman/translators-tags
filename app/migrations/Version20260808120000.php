<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds translations.abbreviation (short button/badge label, e.g. 'SV(JB)').
 * Data (renames, the new SVGBS row) is seeded by db/schema.sql /
 * db/migrate_rename_translations_add_abbreviation.sql, not here.
 */
final class Version20260808120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add translations.abbreviation column';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE translations ADD COLUMN IF NOT EXISTS abbreviation VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE translations DROP abbreviation');
    }
}

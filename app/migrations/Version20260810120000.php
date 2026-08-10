<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds cross_references: verse-level "zie ook"-verwijzingen naar andere
 * bijbelverzen, gescraped uit de kanttekeningen/verwijsteksten-apparatuur
 * van HSV en SV(GBS). Verse-level (niet per-woord) en niet gekoppeld aan
 * een specifieke translation_id, zodat dezelfde SVGBS-verwijzingen ook
 * getoond kunnen worden bij SV Jongbloed (near-identieke brontekst, geen
 * eigen verwijzingenapparatuur).
 */
final class Version20260810120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add cross_references table (verse-level cross-references)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE cross_references (
                id              SERIAL   PRIMARY KEY,
                source          VARCHAR(20) NOT NULL,
                book_id         SMALLINT NOT NULL REFERENCES books(id),
                chapter         SMALLINT NOT NULL,
                verse           SMALLINT NOT NULL,
                ordinal         SMALLINT NOT NULL,
                target_book_id  SMALLINT NOT NULL REFERENCES books(id),
                target_chapter  SMALLINT NOT NULL,
                target_verse    SMALLINT NOT NULL,
                label           TEXT     NOT NULL,
                UNIQUE (source, book_id, chapter, verse, ordinal)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_cross_ref_verse ON cross_references (source, book_id, chapter, verse)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE cross_references');
    }
}

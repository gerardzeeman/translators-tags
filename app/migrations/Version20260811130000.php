<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the Statenvertaling 1657 edition:
 *  - widens books.testament to allow 'APOC' (the 1657 source includes the
 *    Apocriefe boeken, shown as a separate menu category)
 *  - inserts the 13 apocryphal book groups (id 67-79)
 *  - inserts the SV1657 translation row
 */
final class Version20260811130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add SV1657 translation + apocryphal books (testament APOC)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE books DROP CONSTRAINT books_testament_check');
        $this->addSql('ALTER TABLE books ALTER COLUMN testament TYPE VARCHAR(4)');
        $this->addSql("ALTER TABLE books ADD CONSTRAINT books_testament_check CHECK (testament IN ('OT', 'NT', 'APOC'))");

        $this->addSql(<<<'SQL'
            INSERT INTO books (id, usfm_code, testament, name_nl, chapter_count) VALUES
                (67, '1ES', 'APOC', '3 Ezra',                     9),
                (68, '2ES', 'APOC', '4 Ezra',                    16),
                (69, 'TOB', 'APOC', 'Tobias',                    14),
                (70, 'JDT', 'APOC', 'Judith',                    16),
                (71, 'WIS', 'APOC', 'Wijsheid van Salomo',       19),
                (72, 'SIR', 'APOC', 'Wijsheid van Jezus Sirach', 51),
                (73, 'BAR', 'APOC', 'Baruch',                     6),
                (74, 'ESG', 'APOC', 'Toevoegselen op Esther',    16),
                (75, 'DAT', 'APOC', 'Toevoegselen op Daniël',     4),
                (76, 'MAN', 'APOC', 'Gebed van Manasse',          1),
                (77, '1MA', 'APOC', '1 Makkabeeën',               16),
                (78, '2MA', 'APOC', '2 Makkabeeën',               15),
                (79, '3MA', 'APOC', '3 Makkabeeën',                7)
            ON CONFLICT (id) DO NOTHING
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO translations (id, code, name, abbreviation, language, direction, family, source_lang_authority) VALUES
                (4, 'SV1657', 'Statenvertaling (1657)', 'SV(1657)', 'nld', 'LTR', 'SV', FALSE)
            ON CONFLICT (id) DO NOTHING
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM translations WHERE code = \'SV1657\'');
        $this->addSql('DELETE FROM books WHERE testament = \'APOC\'');
        $this->addSql('ALTER TABLE books DROP CONSTRAINT books_testament_check');
        $this->addSql('ALTER TABLE books ALTER COLUMN testament TYPE CHAR(2)');
        $this->addSql("ALTER TABLE books ADD CONSTRAINT books_testament_check CHECK (testament IN ('OT', 'NT'))");
    }
}

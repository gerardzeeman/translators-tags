<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Reworks cross_references from verse-level to word-position-anchored:
 * adds `letter` (the a/b/c-style marker shown inline in the text) and
 * `word_position` (which word in the verse the marker attaches after).
 * Also drops rows sourced from numbered explanatory footnotes -- only
 * lettered, pure cross-reference footnotes remain.
 *
 * The old verse-level rows are structurally incompatible with the new
 * per-word display, so the table is dropped and recreated rather than
 * altered; the ingest scripts fully repopulate it on next run.
 */
final class Version20260811120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rework cross_references: add letter + word_position, drop old verse-level rows';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE cross_references');
        $this->addSql(<<<'SQL'
            CREATE TABLE cross_references (
                id              SERIAL   PRIMARY KEY,
                source          VARCHAR(20) NOT NULL,
                book_id         SMALLINT NOT NULL REFERENCES books(id),
                chapter         SMALLINT NOT NULL,
                verse           SMALLINT NOT NULL,
                letter          VARCHAR(3) NOT NULL,
                word_position   SMALLINT NOT NULL,
                ordinal         SMALLINT NOT NULL,
                target_book_id  SMALLINT NOT NULL REFERENCES books(id),
                target_chapter  SMALLINT NOT NULL,
                target_verse    SMALLINT NOT NULL,
                label           TEXT     NOT NULL,
                UNIQUE (source, book_id, chapter, verse, letter, ordinal)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_cross_ref_verse ON cross_references (source, book_id, chapter, verse)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE cross_references');
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
}

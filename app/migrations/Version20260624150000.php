<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260624150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Performance M-1: index on inter_translation_links(word_b_id) for OR-condition lookups';
    }

    public function up(Schema $schema): void
    {
        // The existing unique constraint covers (word_a_id, word_b_id) but not word_b_id alone.
        // Queries filtering on word_b_id IN (...) need their own index so the planner can
        // combine two Bitmap Index Scans instead of falling back to a sequential scan.
        $this->addSql('CREATE INDEX idx_itl_word_b ON inter_translation_links (word_b_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_itl_word_b');
    }
}

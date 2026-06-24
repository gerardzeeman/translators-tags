<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260624140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Performance: functional strongs indexes (H-3) + link_confidence covering index (H-2)';
    }

    public function up(Schema $schema): void
    {
        // H-3: Functional indexes on strongs columns so regexp_replace() queries use the index
        // instead of performing a full table scan on ~305k hebrew + ~137k greek rows.
        $this->addSql(
            "CREATE INDEX idx_hw_strongs_base ON hebrew_words (regexp_replace(strongs, '[A-Za-z]+\$', ''))"
        );
        $this->addSql(
            "CREATE INDEX idx_gw_strongs_base ON greek_words  (regexp_replace(strongs, '[A-Za-z]+\$', ''))"
        );

        // H-2: Covering index on link_confidence so ORDER BY score DESC LIMIT 1 lookups
        // per translation word become index-only scans instead of sequential scans.
        $this->addSql(
            'CREATE INDEX idx_lc_link_score ON link_confidence (link_id, score DESC)'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_hw_strongs_base');
        $this->addSql('DROP INDEX IF EXISTS idx_gw_strongs_base');
        $this->addSql('DROP INDEX IF EXISTS idx_lc_link_score');
    }
}

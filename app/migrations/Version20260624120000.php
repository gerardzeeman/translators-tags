<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260624120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add created_by_user_id audit FK to word_links, link_confidence, inter_translation_links; backfill manual records to user 1';
    }

    public function up(Schema $schema): void
    {
        // word_links
        $this->addSql('ALTER TABLE word_links ADD COLUMN created_by_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL');

        // link_confidence
        $this->addSql('ALTER TABLE link_confidence ADD COLUMN created_by_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL');

        // inter_translation_links
        $this->addSql('ALTER TABLE inter_translation_links ADD COLUMN created_by_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL');

        // Backfill: existing manual word_links → user 1
        $this->addSql(
            'UPDATE word_links wl SET created_by_user_id = 1
             WHERE EXISTS (
                 SELECT 1 FROM link_confidence lc
                 WHERE lc.link_id = wl.id AND lc.method IN (\'manual\', \'manual_hint\')
             )'
        );

        // Backfill: existing manual link_confidence rows → user 1
        $this->addSql(
            "UPDATE link_confidence SET created_by_user_id = 1 WHERE method IN ('manual', 'manual_hint')"
        );

        // Backfill: existing manual inter_translation_links → user 1
        $this->addSql(
            "UPDATE inter_translation_links SET created_by_user_id = 1 WHERE method IN ('manual', 'manual_empty')"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE word_links DROP COLUMN created_by_user_id');
        $this->addSql('ALTER TABLE link_confidence DROP COLUMN created_by_user_id');
        $this->addSql('ALTER TABLE inter_translation_links DROP COLUMN created_by_user_id');
    }
}

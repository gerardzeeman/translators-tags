<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260624130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove unused created_by text column from link_confidence (replaced by created_by_user_id FK)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE link_confidence DROP COLUMN created_by');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE link_confidence ADD COLUMN created_by TEXT');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260727120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add blogs table for the blog feature (draft/published, per-blog visibility)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE blogs (
                id            SERIAL PRIMARY KEY,
                title         VARCHAR(255) NOT NULL,
                slug          VARCHAR(255) NOT NULL,
                content_md    TEXT NOT NULL DEFAULT '',
                status        VARCHAR(20) NOT NULL DEFAULT 'draft',
                visibility    VARCHAR(20) NOT NULL DEFAULT 'public',
                author_id     INT NOT NULL REFERENCES users(id),
                created_at    TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT now(),
                updated_at    TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT now(),
                published_at  TIMESTAMP WITH TIME ZONE
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_blogs_slug ON blogs (slug)');
        $this->addSql('CREATE INDEX idx_blogs_author ON blogs (author_id)');
        $this->addSql('CREATE INDEX idx_blogs_status_visibility ON blogs (status, visibility, published_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE blogs');
    }
}

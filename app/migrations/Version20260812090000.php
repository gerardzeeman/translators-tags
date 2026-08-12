<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Renames the SV translation from 'Statenvertaling Jongbloed' to
 * 'Statenvertaling (Jongbloed)', matching the parenthesized style used by
 * the other SV-family editions (Statenvertaling (GBS), Statenvertaling (1657)).
 */
final class Version20260812090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Rename SV translation to 'Statenvertaling (Jongbloed)'";
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE translations SET name = 'Statenvertaling (Jongbloed)' WHERE code = 'SV'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE translations SET name = 'Statenvertaling Jongbloed' WHERE code = 'SV'");
    }
}

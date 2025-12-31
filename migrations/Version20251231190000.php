<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251231190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove legacy plaintext password reset token column.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP reset_token');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD reset_token VARCHAR(100) DEFAULT NULL');
    }
}

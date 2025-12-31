<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251222120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add selected_size to orders_details to persist user-selected size';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE orders_details ADD selected_size VARCHAR(50) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE orders_details DROP selected_size');
    }
}

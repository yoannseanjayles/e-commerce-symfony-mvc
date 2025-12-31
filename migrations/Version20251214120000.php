<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251214120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create site_settings table for customizable logos and site parameters';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE site_settings (id INT AUTO_INCREMENT NOT NULL, logo_header VARCHAR(255) DEFAULT NULL, logo_loader VARCHAR(255) DEFAULT NULL, site_name VARCHAR(255) DEFAULT NULL, site_email VARCHAR(255) DEFAULT NULL, site_phone VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE site_settings');
    }
}

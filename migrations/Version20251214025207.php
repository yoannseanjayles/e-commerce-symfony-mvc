<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251214025207 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE site_settings ADD site_address LONGTEXT DEFAULT NULL, ADD site_description LONGTEXT DEFAULT NULL, ADD facebook_url VARCHAR(255) DEFAULT NULL, ADD twitter_url VARCHAR(255) DEFAULT NULL, ADD instagram_url VARCHAR(255) DEFAULT NULL, ADD pinterest_url VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE site_settings DROP site_address, DROP site_description, DROP facebook_url, DROP twitter_url, DROP instagram_url, DROP pinterest_url');
    }
}

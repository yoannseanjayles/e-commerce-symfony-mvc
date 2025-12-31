<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251214191229 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE about_page (id INT AUTO_INCREMENT NOT NULL, section1_title VARCHAR(255) NOT NULL, section1_text LONGTEXT NOT NULL, section1_image VARCHAR(255) DEFAULT NULL, section2_title VARCHAR(255) NOT NULL, section2_text LONGTEXT NOT NULL, section2_image VARCHAR(255) DEFAULT NULL, section3_title VARCHAR(255) NOT NULL, section3_text LONGTEXT NOT NULL, service1_title VARCHAR(255) DEFAULT NULL, service1_text LONGTEXT DEFAULT NULL, service2_title VARCHAR(255) DEFAULT NULL, service2_text LONGTEXT DEFAULT NULL, service3_title VARCHAR(255) DEFAULT NULL, service3_text LONGTEXT DEFAULT NULL, service4_title VARCHAR(255) DEFAULT NULL, service4_text LONGTEXT DEFAULT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE contact_message (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, email VARCHAR(255) NOT NULL, subject VARCHAR(255) DEFAULT NULL, message LONGTEXT NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', is_read TINYINT(1) NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE popular_item (id INT AUTO_INCREMENT NOT NULL, category_id INT DEFAULT NULL, title VARCHAR(255) NOT NULL, image VARCHAR(255) DEFAULT NULL, position INT NOT NULL, is_active TINYINT(1) NOT NULL, INDEX IDX_B8BDDA1012469DE2 (category_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE popular_item ADD CONSTRAINT FK_B8BDDA1012469DE2 FOREIGN KEY (category_id) REFERENCES categories (id)');
        $this->addSql('ALTER TABLE site_settings ADD logo_footer VARCHAR(255) DEFAULT NULL, ADD site_title VARCHAR(255) DEFAULT NULL, ADD site_favicon VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE popular_item DROP FOREIGN KEY FK_B8BDDA1012469DE2');
        $this->addSql('DROP TABLE about_page');
        $this->addSql('DROP TABLE contact_message');
        $this->addSql('DROP TABLE popular_item');
        $this->addSql('ALTER TABLE site_settings DROP logo_footer, DROP site_title, DROP site_favicon');
    }
}

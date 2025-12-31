<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251214130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create hero table for hero slider management';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE hero (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, subtitle VARCHAR(255) DEFAULT NULL, button_text VARCHAR(255) DEFAULT NULL, button_link VARCHAR(255) DEFAULT NULL, background_image VARCHAR(255) DEFAULT NULL, position INT NOT NULL, is_active TINYINT(1) NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE hero');
    }
}

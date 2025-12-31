<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251221150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Images.color_tag to link images to a variant color';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE images ADD color_tag VARCHAR(100) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE images DROP color_tag');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251222160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add primary_image_id on product_variant to associate a specific image to a variant';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product_variant ADD primary_image_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE product_variant ADD CONSTRAINT FK_9F9B0B43716BAF0E FOREIGN KEY (primary_image_id) REFERENCES images (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_9F9B0B43716BAF0E ON product_variant (primary_image_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product_variant DROP CONSTRAINT FK_9F9B0B43716BAF0E');
        $this->addSql('DROP INDEX IDX_9F9B0B43716BAF0E');
        $this->addSql('ALTER TABLE product_variant DROP primary_image_id');
    }
}

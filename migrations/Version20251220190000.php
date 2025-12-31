<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251220190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add product variants table for AI-proposed variants';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE product_variant (id INT AUTO_INCREMENT NOT NULL, products_id INT NOT NULL, slug VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, color VARCHAR(100) DEFAULT NULL, size VARCHAR(50) DEFAULT NULL, sku VARCHAR(64) DEFAULT NULL, barcode VARCHAR(32) DEFAULT NULL, price INT DEFAULT NULL, stock INT DEFAULT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE INDEX UNIQ_5D6D80F83619F6B (sku), UNIQUE INDEX UNIQ_5D6D80F8C4747E05 (barcode), INDEX IDX_5D6D80F86C8A81A9 (products_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE product_variant ADD CONSTRAINT FK_5D6D80F86C8A81A9 FOREIGN KEY (products_id) REFERENCES products (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE product_variant');
    }
}

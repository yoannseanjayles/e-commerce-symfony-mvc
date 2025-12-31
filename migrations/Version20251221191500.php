<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251221191500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'OrdersDetails: add id primary key and optional product_variant_id to store chosen variant per order line';
    }

    public function up(Schema $schema): void
    {
        // Replace composite PK (orders_id, products_id) to allow multiple lines per product (variants)
        $this->addSql('ALTER TABLE orders_details DROP PRIMARY KEY');
        $this->addSql('ALTER TABLE orders_details ADD id INT AUTO_INCREMENT NOT NULL FIRST, ADD product_variant_id INT DEFAULT NULL, ADD PRIMARY KEY (id)');
        $this->addSql('CREATE INDEX IDX_835379F186B7B41B ON orders_details (product_variant_id)');
        $this->addSql('ALTER TABLE orders_details ADD CONSTRAINT FK_835379F186B7B41B FOREIGN KEY (product_variant_id) REFERENCES product_variant (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // Note: down() may fail if orders_details contains multiple rows for same (orders_id, products_id)
        $this->addSql('ALTER TABLE orders_details DROP FOREIGN KEY FK_835379F186B7B41B');
        $this->addSql('DROP INDEX IDX_835379F186B7B41B ON orders_details');
        $this->addSql('ALTER TABLE orders_details DROP PRIMARY KEY');
        $this->addSql('ALTER TABLE orders_details DROP id, DROP product_variant_id');
        $this->addSql('ALTER TABLE orders_details ADD PRIMARY KEY (orders_id, products_id)');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251221101000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Optician attributes + secondary categories + variant measurements';
    }

    public function up(Schema $schema): void
    {
        // Products: optician attributes
        $this->addSql('ALTER TABLE products ADD product_type VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE products ADD gender VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE products ADD frame_shape VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE products ADD frame_material VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE products ADD frame_style VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE products ADD lens_width_mm INT DEFAULT NULL');
        $this->addSql('ALTER TABLE products ADD bridge_width_mm INT DEFAULT NULL');
        $this->addSql('ALTER TABLE products ADD temple_length_mm INT DEFAULT NULL');
        $this->addSql('ALTER TABLE products ADD lens_height_mm INT DEFAULT NULL');
        $this->addSql('ALTER TABLE products ADD polarized TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE products ADD prescription_available TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE products ADD uv_protection VARCHAR(64) DEFAULT NULL');

        // ProductVariant: optician measurements per variant
        $this->addSql('ALTER TABLE product_variant ADD color_code VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE product_variant ADD lens_width_mm INT DEFAULT NULL');
        $this->addSql('ALTER TABLE product_variant ADD bridge_width_mm INT DEFAULT NULL');
        $this->addSql('ALTER TABLE product_variant ADD temple_length_mm INT DEFAULT NULL');

        // Secondary categories (ManyToMany)
        $this->addSql('CREATE TABLE product_secondary_categories (products_id INT NOT NULL, categories_id INT NOT NULL, INDEX IDX_DED18366C8A81A9 (products_id), INDEX IDX_DED1836A21214B7 (categories_id), PRIMARY KEY(products_id, categories_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE product_secondary_categories ADD CONSTRAINT FK_DED18366C8A81A9 FOREIGN KEY (products_id) REFERENCES products (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE product_secondary_categories ADD CONSTRAINT FK_DED1836A21214B7 FOREIGN KEY (categories_id) REFERENCES categories (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE products DROP product_type, DROP gender, DROP frame_shape, DROP frame_material, DROP frame_style, DROP lens_width_mm, DROP bridge_width_mm, DROP temple_length_mm, DROP lens_height_mm, DROP polarized, DROP prescription_available, DROP uv_protection');
        $this->addSql('ALTER TABLE product_variant DROP color_code, DROP lens_width_mm, DROP bridge_width_mm, DROP temple_length_mm');
        $this->addSql('DROP TABLE product_secondary_categories');
    }
}

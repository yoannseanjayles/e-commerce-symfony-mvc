<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251219120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Products.barcode unique + Images.source_url for idempotent barcode imports';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE products ADD barcode VARCHAR(32) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_B3BA5A5A2D7B5C61 ON products (barcode)');

        $this->addSql('ALTER TABLE images ADD source_url VARCHAR(2048) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_E01FBE6A1B1E2E62 ON images (products_id, source_url)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_B3BA5A5A2D7B5C61 ON products');
        $this->addSql('ALTER TABLE products DROP barcode');

        $this->addSql('DROP INDEX UNIQ_E01FBE6A1B1E2E62 ON images');
        $this->addSql('ALTER TABLE images DROP source_url');
    }
}

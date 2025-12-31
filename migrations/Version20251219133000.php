<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251219133000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Products external source/id for idempotent lookup imports without barcode';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE products ADD external_source VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE products ADD external_id VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_PRODUCTS_EXTERNAL ON products (external_source, external_id)');
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform()->getName();

        if ($platform === 'mysql') {
            $this->addSql('DROP INDEX UNIQ_PRODUCTS_EXTERNAL ON products');
        } else {
            $this->addSql('DROP INDEX UNIQ_PRODUCTS_EXTERNAL');
        }

        $this->addSql('ALTER TABLE products DROP external_source');
        $this->addSql('ALTER TABLE products DROP external_id');
    }
}

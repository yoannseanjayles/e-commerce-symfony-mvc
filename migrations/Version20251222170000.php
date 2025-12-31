<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251222170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Widen product_variant.color_code to avoid truncation when AI returns longer CSS values';
    }

    public function up(Schema $schema): void
    {
        // Postgres
        $this->addSql('ALTER TABLE product_variant ALTER COLUMN color_code TYPE VARCHAR(64)');
    }

    public function down(Schema $schema): void
    {
        // Reverting may fail if existing values exceed 16.
        $this->addSql('ALTER TABLE product_variant ALTER COLUMN color_code TYPE VARCHAR(16)');
    }
}

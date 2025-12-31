<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251220200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Stripe toggle to site_settings and payment fields to orders';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE site_settings ADD stripe_enabled TINYINT(1) NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE orders ADD payment_provider VARCHAR(30) DEFAULT NULL, ADD payment_status VARCHAR(30) DEFAULT NULL, ADD stripe_checkout_session_id VARCHAR(255) DEFAULT NULL, ADD stock_adjusted TINYINT(1) NOT NULL DEFAULT 0');
        $this->addSql('CREATE INDEX IDX_E52FFDEE2A3C1B7E ON orders (stripe_checkout_session_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_E52FFDEE2A3C1B7E ON orders');
        $this->addSql('ALTER TABLE orders DROP payment_provider, DROP payment_status, DROP stripe_checkout_session_id, DROP stock_adjusted');
        $this->addSql('ALTER TABLE site_settings DROP stripe_enabled');
    }
}

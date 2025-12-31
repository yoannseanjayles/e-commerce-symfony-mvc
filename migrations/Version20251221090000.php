<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251221090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow overriding API keys in site_settings (OpenAI/Stripe)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE site_settings ADD open_ai_api_key_override LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE site_settings ADD stripe_secret_key_override LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE site_settings ADD stripe_webhook_secret_override LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE site_settings DROP open_ai_api_key_override');
        $this->addSql('ALTER TABLE site_settings DROP stripe_secret_key_override');
        $this->addSql('ALTER TABLE site_settings DROP stripe_webhook_secret_override');
    }
}

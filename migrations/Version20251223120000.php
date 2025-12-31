<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251223120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add AI cost control overrides to site_settings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE site_settings ADD ai_guard_enabled_override TINYINT(1) DEFAULT NULL');
        $this->addSql('ALTER TABLE site_settings ADD ai_max_per_minute_override INT DEFAULT NULL');
        $this->addSql('ALTER TABLE site_settings ADD ai_max_per_day_override INT DEFAULT NULL');
        $this->addSql('ALTER TABLE site_settings ADD ai_max_web_search_per_day_override INT DEFAULT NULL');
        $this->addSql('ALTER TABLE site_settings ADD ai_cache_enabled_override TINYINT(1) DEFAULT NULL');
        $this->addSql('ALTER TABLE site_settings ADD ai_cache_ttl_seconds_override INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE site_settings DROP ai_guard_enabled_override');
        $this->addSql('ALTER TABLE site_settings DROP ai_max_per_minute_override');
        $this->addSql('ALTER TABLE site_settings DROP ai_max_per_day_override');
        $this->addSql('ALTER TABLE site_settings DROP ai_max_web_search_per_day_override');
        $this->addSql('ALTER TABLE site_settings DROP ai_cache_enabled_override');
        $this->addSql('ALTER TABLE site_settings DROP ai_cache_ttl_seconds_override');
    }
}

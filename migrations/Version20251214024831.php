<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251214024831 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE coupons ADD products_id INT NOT NULL');
        $this->addSql('ALTER TABLE coupons ADD CONSTRAINT FK_F56411186C8A81A9 FOREIGN KEY (products_id) REFERENCES products (id)');
        $this->addSql('CREATE INDEX IDX_F56411186C8A81A9 ON coupons (products_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE coupons DROP FOREIGN KEY FK_F56411186C8A81A9');
        $this->addSql('DROP INDEX IDX_F56411186C8A81A9 ON coupons');
        $this->addSql('ALTER TABLE coupons DROP products_id');
    }
}

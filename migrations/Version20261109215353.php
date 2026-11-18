<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Formation M2 + M3 : colonne « origin » sur sylius_product et table « app_supplier ».
 *
 * Migration relue et réduite à ces deux changements : le « doctrine:migrations:diff » avait aussi
 * généré des modifications sans rapport (plugins Mollie / Refund, table messenger_messages).
 */
final class Version20261109215353 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Formation : sylius_product.origin et table app_supplier';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sylius_product ADD origin VARCHAR(100) DEFAULT NULL');
        $this->addSql('CREATE TABLE app_supplier (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(64) NOT NULL, name VARCHAR(255) NOT NULL, contact_email VARCHAR(255) DEFAULT NULL, enabled TINYINT NOT NULL, UNIQUE INDEX UNIQ_93AA0CD377153098 (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE app_supplier');
        $this->addSql('ALTER TABLE sylius_product DROP origin');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tighten webhook inbox provider_code / event_reference CHECKs to BINARY regex.
 */
final class Version20260914120100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Use BINARY REGEXP for payment_webhook_inbox_events provider identity CHECKs.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->createSchemaManager()->tablesExist(['payment_webhook_inbox_events']),
            'payment_webhook_inbox_events must exist.',
        );

        $this->addSql('ALTER TABLE payment_webhook_inbox_events DROP CONSTRAINT chk_pwie_provider_code');
        $this->addSql('ALTER TABLE payment_webhook_inbox_events DROP CONSTRAINT chk_pwie_provider_event_reference');
        $this->addSql(<<<'SQL'
            ALTER TABLE payment_webhook_inbox_events
                ADD CONSTRAINT chk_pwie_provider_code CHECK (
                    provider_code REGEXP BINARY '^[a-z][a-z0-9_]{1,31}$'
                ),
                ADD CONSTRAINT chk_pwie_provider_event_reference CHECK (
                    provider_event_reference REGEXP BINARY '^[A-Za-z0-9._:-]{8,128}$'
                )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payment_webhook_inbox_events DROP CONSTRAINT chk_pwie_provider_code');
        $this->addSql('ALTER TABLE payment_webhook_inbox_events DROP CONSTRAINT chk_pwie_provider_event_reference');
        $this->addSql(<<<'SQL'
            ALTER TABLE payment_webhook_inbox_events
                ADD CONSTRAINT chk_pwie_provider_code CHECK (
                    provider_code REGEXP '^[a-z][a-z0-9_]{1,31}$'
                ),
                ADD CONSTRAINT chk_pwie_provider_event_reference CHECK (
                    provider_event_reference REGEXP '^[A-Za-z0-9._:-]{8,128}$'
                )
            SQL);
    }
}

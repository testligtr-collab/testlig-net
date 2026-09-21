<?php

declare(strict_types=1);

namespace App\Tests\Service;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * MariaDB shape for Stage 2.22.4a invitation / participation-code tables.
 */
final class InvitationCodeSchemaIntegrityTest extends KernelTestCase
{
    public function testPersonalInvitationsAndParticipationCodesExistWithDigestUniques(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $connection = $em->getConnection();

        $tables = $connection->fetchFirstColumn(
            "SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME IN ('personal_invitations', 'participation_codes')
             ORDER BY TABLE_NAME",
        );
        self::assertSame(['participation_codes', 'personal_invitations'], $tables);

        foreach (['personal_invitations' => 'uniq_personal_invitation_code_digest', 'participation_codes' => 'uniq_participation_code_digest'] as $table => $unique) {
            $index = $connection->fetchAssociative(
                'SELECT INDEX_NAME, NON_UNIQUE
                 FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
                 LIMIT 1',
                [$table, $unique],
            );
            self::assertIsArray($index);
            self::assertSame(0, (int) $index['NON_UNIQUE']);
        }

        $recipient = $connection->fetchAssociative(
            "SELECT COLUMN_NAME, IS_NULLABLE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'personal_invitations'
               AND COLUMN_NAME = 'intended_recipient_user_id'",
        );
        self::assertIsArray($recipient);
        self::assertSame('NO', $recipient['IS_NULLABLE']);

        $digest = $connection->fetchAssociative(
            "SELECT COLUMN_NAME, IS_NULLABLE, CHARACTER_MAXIMUM_LENGTH
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'personal_invitations'
               AND COLUMN_NAME = 'code_digest'",
        );
        self::assertIsArray($digest);
        self::assertSame('NO', $digest['IS_NULLABLE']);
        self::assertSame(64, (int) $digest['CHARACTER_MAXIMUM_LENGTH']);

        $checkNames = $connection->fetchFirstColumn(
            "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME IN ('personal_invitations', 'participation_codes')
               AND CONSTRAINT_TYPE = 'CHECK'
             ORDER BY CONSTRAINT_NAME",
        );
        foreach ([
            'chk_personal_inv_purpose_code',
            'chk_personal_inv_code_digest_hex',
            'chk_personal_inv_lifecycle',
            'chk_personal_inv_classroom_requires_institution',
            'chk_personal_inv_recipient_not_creator',
            'chk_part_code_scope',
            'chk_part_code_digest_hex',
            'chk_part_code_redemptions',
            'chk_part_code_lifecycle',
            'chk_part_code_scope_classroom',
        ] as $expected) {
            self::assertContains($expected, $checkNames);
        }

        $fkRecipient = $connection->fetchAssociative(
            "SELECT DELETE_RULE
             FROM information_schema.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND CONSTRAINT_NAME = 'FK_F5B163E12C5AE70D'",
        );
        self::assertIsArray($fkRecipient);
        self::assertSame('RESTRICT', $fkRecipient['DELETE_RULE']);
    }
}

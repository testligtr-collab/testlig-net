<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\AssessmentDeliveryAudienceType;
use App\Tests\Support\AssessmentDeliveryTestFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class AssessmentDeliveryConstraintTest extends KernelTestCase
{
    use AssessmentDeliveryTestFixtures;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->rebindDeliveryFixtures();
        $this->cleanupDeliveryFixtures();
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $this->cleanupDeliveryFixtures();
        }
        parent::tearDown();
    }

    public function testSchemaIndexesChecksAndCompositePublicationFk(): void
    {
        $conn = $this->em->getConnection();

        $hasApUnique = (int) $conn->fetchOne(<<<'SQL'
            SELECT COUNT(*) FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = 'assessment_publications'
              AND index_name = 'uniq_ap_id_assessment_number'
            SQL);
        self::assertSame(1, $hasApUnique > 0 ? 1 : 0);

        $hasAudienceCheck = (int) $conn->fetchOne(<<<'SQL'
            SELECT COUNT(*) FROM information_schema.check_constraints
            WHERE constraint_schema = DATABASE()
              AND constraint_name = 'chk_ad_audience_targets'
            SQL);
        self::assertGreaterThan(0, $hasAudienceCheck);

        $ctx = $this->publishedDeliveryContext('adfk');
        [$opens, $closes] = $this->defaultWindow();
        $delivery = $this->deliveries()->createDraft(
            $ctx['institution'],
            $ctx['publication'],
            AssessmentDeliveryAudienceType::Institution,
            null,
            null,
            $ctx['owner'],
            $opens,
            $closes,
            1,
            null,
            null,
            'create_fk',
        );

        $fakePublicationId = Uuid::v7()->toBinary();
        try {
            $conn->executeStatement(
                'UPDATE assessment_deliveries
                 SET assessment_publication_id = :pub
                 WHERE id = :id',
                [
                    'pub' => $fakePublicationId,
                    'id' => $delivery->getId()->toBinary(),
                ],
            );
            self::fail('identity mutation');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertNotSame('', $e->getMessage());
        }

        // Cross-assessment publication number spoof via DBAL insert should fail FK.
        $spoofId = Uuid::v7()->toBinary();
        try {
            $conn->insert('assessment_deliveries', [
                'id' => $spoofId,
                'institution_id' => $ctx['institution']->getId()->toBinary(),
                'assessment_id' => $ctx['assessment']->getId()->toBinary(),
                'assessment_publication_id' => $ctx['publication']->getId()->toBinary(),
                'publication_number' => 999,
                'audience_type' => 'institution',
                'classroom_id' => null,
                'student_membership_id' => null,
                'status' => 'draft',
                'opens_at' => $opens->format('Y-m-d H:i:s'),
                'closes_at' => $closes->format('Y-m-d H:i:s'),
                'max_attempts' => 1,
                'title_override' => null,
                'instructions_override' => null,
                'created_by_id' => $ctx['owner']->getId()->toBinary(),
                'activated_by_id' => null,
                'activated_at' => null,
                'closed_by_id' => null,
                'closed_at' => null,
                'cancelled_by_id' => null,
                'cancelled_at' => null,
                'cancellation_reason_code' => null,
                'created_at' => $opens->format('Y-m-d H:i:s'),
                'updated_at' => $opens->format('Y-m-d H:i:s'),
            ]);
            self::fail('spoof publication number');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertNotSame('', $e->getMessage());
        }
    }

    public function testAudienceCheckRejectsMismatchedTargets(): void
    {
        $ctx = $this->publishedDeliveryContext('adchk');
        [$opens, $closes] = $this->defaultWindow();
        $conn = $this->em->getConnection();
        $id = Uuid::v7()->toBinary();

        try {
            $conn->insert('assessment_deliveries', [
                'id' => $id,
                'institution_id' => $ctx['institution']->getId()->toBinary(),
                'assessment_id' => $ctx['assessment']->getId()->toBinary(),
                'assessment_publication_id' => $ctx['publication']->getId()->toBinary(),
                'publication_number' => $ctx['publication']->getPublicationNumber(),
                'audience_type' => 'institution',
                'classroom_id' => $ctx['classroom']->getId()->toBinary(),
                'student_membership_id' => null,
                'status' => 'draft',
                'opens_at' => $opens->format('Y-m-d H:i:s'),
                'closes_at' => $closes->format('Y-m-d H:i:s'),
                'max_attempts' => 1,
                'title_override' => null,
                'instructions_override' => null,
                'created_by_id' => $ctx['owner']->getId()->toBinary(),
                'activated_by_id' => null,
                'activated_at' => null,
                'closed_by_id' => null,
                'closed_at' => null,
                'cancelled_by_id' => null,
                'cancelled_at' => null,
                'cancellation_reason_code' => null,
                'created_at' => $opens->format('Y-m-d H:i:s'),
                'updated_at' => $opens->format('Y-m-d H:i:s'),
            ]);
            self::fail('audience check');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertNotSame('', $e->getMessage());
        }
    }

    public function testDeliveryTriggersHaveNoBypassOrSessionVariables(): void
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            "SELECT TRIGGER_NAME, ACTION_STATEMENT
             FROM information_schema.TRIGGERS
             WHERE TRIGGER_SCHEMA = DATABASE()
               AND TRIGGER_NAME LIKE 'trg_assessment_deliver%'
             ORDER BY TRIGGER_NAME",
        );
        self::assertNotEmpty($rows);
        foreach ($rows as $row) {
            $body = (string) $row['ACTION_STATEMENT'];
            self::assertStringNotContainsStringIgnoringCase('@testlig', $body);
            self::assertStringNotContainsStringIgnoringCase('bypass', $body);
            self::assertStringNotContainsStringIgnoringCase('FOREIGN_KEY_CHECKS', $body);
            self::assertStringNotContainsStringIgnoringCase('@', $body);
        }
    }
}

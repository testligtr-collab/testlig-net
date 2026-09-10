<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AssessmentDelivery;
use App\Entity\InstitutionMembership;
use App\Entity\User;
use App\Enum\AssessmentDeliveryAudienceType;
use App\Enum\AssessmentDeliveryFailureReason;
use App\Enum\InstitutionMembershipRole;
use App\Enum\SecurityAuditAction;
use App\Exception\AssessmentDeliveryException;
use App\Tests\Support\AssessmentDeliveryTestFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AssessmentDeliveryRecipientMaterializationTest extends KernelTestCase
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

    public function testInstitutionAudienceMaterializesOnlyActiveStudents(): void
    {
        $ctx = $this->publishedDeliveryContext('admat');
        $staff = $this->activeUser('admat-staff@example.com');
        $this->membershipManager()->addMember(
            $ctx['institution'],
            $ctx['owner'],
            $staff,
            InstitutionMembershipRole::Staff,
            'add_staff',
        );
        $student2 = $this->activeUser('admat-s2@example.com');
        $this->membershipManager()->addMember(
            $ctx['institution'],
            $ctx['owner'],
            $student2,
            InstitutionMembershipRole::Student,
            'add_s2',
        );
        $unverified = $this->activeUser('admat-uv@example.com');
        $this->membershipManager()->addMember(
            $ctx['institution'],
            $ctx['owner'],
            $unverified,
            InstitutionMembershipRole::Student,
            'add_uv',
        );
        // Simulate stale unverified state after membership existed.
        $this->em->getConnection()->executeStatement(
            'UPDATE users SET email_verified_at = NULL WHERE id = :id',
            ['id' => $unverified->getId()->toBinary()],
        );

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
            'create_inst',
        );
        $this->deliveries()->activate($delivery, $ctx['owner'], 'activate_inst');
        $count = (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM assessment_delivery_recipients WHERE delivery_id = :id',
            ['id' => $delivery->getId()->toBinary()],
        );
        self::assertSame(2, $count); // student + student2; not staff/teacher/unverified
        self::assertSame(1, $this->events->countByAction(SecurityAuditAction::AssessmentDeliveryActivated->value));
    }

    public function testClassroomAudienceAndAddEligibleRecipient(): void
    {
        $ctx = $this->publishedDeliveryContext('adcls');
        $late = $this->activeUser('adcls-late@example.com');
        $lateMembership = $this->membershipManager()->addMember(
            $ctx['institution'],
            $ctx['owner'],
            $late,
            InstitutionMembershipRole::Student,
            'add_late',
        );

        [$opens, $closes] = $this->defaultWindow();
        $delivery = $this->deliveries()->createDraft(
            $ctx['institution'],
            $ctx['publication'],
            AssessmentDeliveryAudienceType::Classroom,
            $ctx['classroom'],
            null,
            $ctx['owner'],
            $opens,
            $closes,
            1,
            null,
            null,
            'create_cls',
        );
        $this->deliveries()->activate($delivery, $ctx['owner'], 'activate_cls');
        self::assertSame(
            1,
            (int) $this->em->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM assessment_delivery_recipients WHERE delivery_id = :id',
                ['id' => $delivery->getId()->toBinary()],
            ),
        );

        // Late join without addEligibleRecipient does not auto-materialize.
        $this->enrollmentManager()->enroll($ctx['classroom'], $ctx['owner'], $lateMembership, 'enroll_late');
        self::assertSame(
            1,
            (int) $this->em->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM assessment_delivery_recipients WHERE delivery_id = :id',
                ['id' => $delivery->getId()->toBinary()],
            ),
        );

        $delivery = $this->em->find(AssessmentDelivery::class, $delivery->getId());
        $lateMembership = $this->em->find(InstitutionMembership::class, $lateMembership->getId());
        $owner = $this->users->find($ctx['owner']->getId());
        self::assertInstanceOf(AssessmentDelivery::class, $delivery);
        self::assertInstanceOf(InstitutionMembership::class, $lateMembership);
        self::assertInstanceOf(User::class, $owner);
        $this->deliveries()->addEligibleRecipient($delivery, $lateMembership, $owner, 'add_recip');
        self::assertSame(
            2,
            (int) $this->em->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM assessment_delivery_recipients WHERE delivery_id = :id',
                ['id' => $delivery->getId()->toBinary()],
            ),
        );
        self::assertSame(1, $this->events->countByAction(SecurityAuditAction::AssessmentDeliveryRecipientAdded->value));
    }

    public function testStudentAudienceAndZeroEligibleRollback(): void
    {
        $ctx = $this->publishedDeliveryContext('adstu');
        [$opens, $closes] = $this->defaultWindow();
        $delivery = $this->deliveries()->createDraft(
            $ctx['institution'],
            $ctx['publication'],
            AssessmentDeliveryAudienceType::Student,
            null,
            $ctx['studentMembership'],
            $ctx['owner'],
            $opens,
            $closes,
            1,
            null,
            null,
            'create_stu',
        );
        $this->deliveries()->activate($delivery, $ctx['owner'], 'activate_stu');
        self::assertSame(
            1,
            (int) $this->em->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM assessment_delivery_recipients WHERE delivery_id = :id',
                ['id' => $delivery->getId()->toBinary()],
            ),
        );

        // Empty classroom activation rolls back to draft with zero recipients.
        $emptyClassroom = $this->classroomManager()->create(
            $ctx['classroom']->getAcademicYear(),
            $ctx['owner'],
            'adstu empty',
            \App\Enum\GradeLevel::Grade9,
            'cls2',
            'B',
            20,
        );
        $delivery2 = $this->deliveries()->createDraft(
            $ctx['institution'],
            $ctx['publication'],
            AssessmentDeliveryAudienceType::Classroom,
            $emptyClassroom,
            null,
            $ctx['owner'],
            $opens,
            $closes,
            1,
            null,
            null,
            'create_empty',
        );
        try {
            $this->deliveries()->activate($delivery2, $ctx['owner'], 'activate_empty');
            self::fail('zero recipients');
        } catch (AssessmentDeliveryException $e) {
            self::assertSame(AssessmentDeliveryFailureReason::NoEligibleRecipients, $e->getReason());
        }
        $this->resetDoctrineDelivery();
        $delivery2 = $this->em->find(AssessmentDelivery::class, $delivery2->getId());
        self::assertInstanceOf(AssessmentDelivery::class, $delivery2);
        self::assertSame(\App\Enum\AssessmentDeliveryStatus::Draft, $delivery2->getStatus());
        self::assertSame(
            0,
            (int) $this->em->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM assessment_delivery_recipients WHERE delivery_id = :id',
                ['id' => $delivery2->getId()->toBinary()],
            ),
        );
    }
}

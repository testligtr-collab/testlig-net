<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\HomeworkDraftInput;
use App\Entity\Classroom;
use App\Entity\HomeworkDefinition;
use App\Entity\User;
use App\Enum\HomeworkDefinitionStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Exception\HomeworkDefinitionException;
use App\Tests\Support\HomeworkTestFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class HomeworkDefinitionManagerTest extends KernelTestCase
{
    use HomeworkTestFixtures;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->rebindDeliveryFixtures();
        $this->cleanupHomework();
    }

    protected function tearDown(): void
    {
        $this->resetClock();
        if (isset($this->em)) {
            $this->cleanupHomework();
        }
        parent::tearDown();
    }

    public function testTeacherCreatesDraftStoredInUtcWithMetadataOnlyAudit(): void
    {
        $ctx = $this->homeworkContext('hwm-create');
        $this->freezeClock('2026-11-10 09:00:00');

        $definition = $this->homeworkManager()->createDraft($ctx['teacher'], $this->draftInput($ctx['classroom']));

        self::assertSame(HomeworkDefinitionStatus::Draft, $definition->getStatus());
        self::assertSame(1, $definition->getRevisionNumber());
        self::assertSame('Kesirler çalışma kâğıdı', $definition->getTitle());
        self::assertTrue($definition->getCreatedByUser()->getId()->equals($ctx['teacher']->getId()));
        self::assertTrue($definition->getCreatedByMembership()->getId()->equals($ctx['teacherMembership']->getId()));
        self::assertTrue($definition->getInstitution()->getId()->equals($ctx['institution']->getId()));
        self::assertTrue($definition->getClassroom()->getId()->equals($ctx['classroom']->getId()));
        self::assertTrue($definition->getAcademicYear()->getId()->equals($ctx['year']->getId()));
        // 18:30 Europe/Istanbul (UTC+3) on 2026-11-12 is 15:30 UTC.
        self::assertSame('2026-11-12 15:30:00', $this->storedDueAt($definition));
        self::assertSame('2026-11-10 09:00:00', $definition->getCreatedAt()->format('Y-m-d H:i:s'));
        self::assertSame(1, $this->homeworkRowCount());

        $audit = $this->homeworkAuditRows();
        self::assertCount(1, $audit);
        self::assertSame('homework_definition_draft_created', $audit[0]['action']);
        $metadata = json_decode($audit[0]['metadata'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($metadata);
        self::assertSame($definition->getId()->toRfc4122(), $metadata['resource_id']);
        self::assertSame('draft', $metadata['status']);
        self::assertSame(1, $metadata['revision_number']);
        self::assertSame($ctx['classroom']->getId()->toRfc4122(), $metadata['classroom_id']);
        self::assertArrayNotHasKey('description', $metadata);
        self::assertArrayNotHasKey('title', $metadata);
        self::assertStringNotContainsString('Sayfa 12', $audit[0]['metadata']);
        self::assertStringNotContainsString('Kesirler', $audit[0]['metadata']);
    }

    public function testIstanbulConversionUsesTheZoneRulesNotAFixedOffset(): void
    {
        $ctx = $this->homeworkContext('hwm-zone');
        // Europe/Istanbul observed DST in 2015: UTC+3 in July, UTC+2 in December.
        $this->freezeClock('2014-01-01 00:00:00');

        $summer = $this->homeworkManager()->createDraft($ctx['teacher'], $this->draftInput($ctx['classroom'], dueDate: '2015-07-01', dueTime: '12:00'));
        $winter = $this->homeworkManager()->createDraft($ctx['teacher'], $this->draftInput($ctx['classroom'], dueDate: '2015-12-01', dueTime: '12:00'));

        self::assertSame('2015-07-01 09:00:00', $this->storedDueAt($summer));
        self::assertSame('2015-12-01 10:00:00', $this->storedDueAt($winter));
    }

    public function testDueMustBeStrictlyInTheFutureRelativeToTheInjectedClock(): void
    {
        $ctx = $this->homeworkContext('hwm-past');
        // Now is 12:00 Europe/Istanbul on 2026-11-10.
        $this->freezeClock('2026-11-10 09:00:00');

        foreach (['11:59', '12:00'] as $time) {
            $exception = $this->failure(fn () => $this->homeworkManager()->createDraft(
                $ctx['teacher'],
                $this->draftInput($ctx['classroom'], dueDate: '2026-11-10', dueTime: $time),
            ));
            self::assertSame(HomeworkDefinitionException::REASON_INVALID_INPUT, $exception->getReason());
            self::assertArrayHasKey('due', $exception->getFieldErrors());
        }
        self::assertSame(0, $this->homeworkRowCount());
        self::assertSame([], $this->homeworkAuditRows());

        $accepted = $this->homeworkManager()->createDraft(
            $ctx['teacher'],
            $this->draftInput($ctx['classroom'], dueDate: '2026-11-10', dueTime: '12:01'),
        );
        self::assertSame('2026-11-10 09:01:00', $this->storedDueAt($accepted));
    }

    public function testMalformedDueValuesAreRejected(): void
    {
        $ctx = $this->homeworkContext('hwm-due');
        $this->freezeClock();

        foreach ([['2026-02-31', '10:00'], ['2026-11-12', '25:00'], ['12.11.2026', '10:00'], ['', ''], ['2026-11-12', '']] as [$date, $time]) {
            $exception = $this->failure(fn () => $this->homeworkManager()->createDraft(
                $ctx['teacher'],
                $this->draftInput($ctx['classroom'], dueDate: $date, dueTime: $time),
            ));
            self::assertArrayHasKey('due', $exception->getFieldErrors(), $date.' '.$time);
        }
        self::assertSame(0, $this->homeworkRowCount());
    }

    public function testInvalidInputReportsEveryFieldAndWritesNothing(): void
    {
        $ctx = $this->homeworkContext('hwm-invalid');
        $this->freezeClock();

        $exception = $this->failure(fn () => $this->homeworkManager()->createDraft($ctx['teacher'], new HomeworkDraftInput(
            '   ',
            '<b>kalın</b>',
            'not-a-uuid',
            '2026-11-12',
            '18:30',
        )));

        self::assertSame(HomeworkDefinitionException::REASON_INVALID_INPUT, $exception->getReason());
        self::assertEqualsCanonicalizing(['title', 'description', 'classroom'], array_keys($exception->getFieldErrors()));
        self::assertSame(0, $this->homeworkRowCount());
        self::assertSame([], $this->homeworkAuditRows());
    }

    public function testUnicodeLengthsUseCodePointsAndRoundTripThroughMariaDb(): void
    {
        $ctx = $this->homeworkContext('hwm-unicode');
        $this->freezeClock();
        $emojiTitle = str_repeat("\u{1F600}", 150);
        $description = str_repeat('ğüşiöç 😀 ', 1000);
        self::assertLessThanOrEqual(10000, mb_strlen($description));

        $definition = $this->homeworkManager()->createDraft(
            $ctx['teacher'],
            $this->draftInput($ctx['classroom'], title: $emojiTitle, description: $description),
        );
        $this->em->clear();
        $stored = $this->em->find(HomeworkDefinition::class, $definition->getId());
        self::assertInstanceOf(HomeworkDefinition::class, $stored);
        self::assertSame($emojiTitle, $stored->getTitle());
        self::assertSame(trim($description), $stored->getDescription());

        $tooLong = $this->failure(fn () => $this->homeworkManager()->createDraft(
            $ctx['teacher'],
            $this->draftInput($ctx['classroom'], title: $emojiTitle."\u{1F600}"),
        ));
        self::assertArrayHasKey('title', $tooLong->getFieldErrors());

        $descriptionTooLong = $this->failure(fn () => $this->homeworkManager()->createDraft(
            $ctx['teacher'],
            $this->draftInput($ctx['classroom'], description: str_repeat("\u{1F4DA}", 10001)),
        ));
        self::assertArrayHasKey('description', $descriptionTooLong->getFieldErrors());
        self::assertSame(1, $this->homeworkRowCount());
    }

    public function testUpdateIncrementsRevisionAndStaleRevisionWritesNothing(): void
    {
        $ctx = $this->homeworkContext('hwm-stale');
        $this->freezeClock();
        $definition = $this->homeworkManager()->createDraft($ctx['teacher'], $this->draftInput($ctx['classroom']));
        $id = $definition->getId();

        $updated = $this->homeworkManager()->updateDraft(
            $ctx['teacher'],
            $id,
            $this->draftInput($ctx['classroom'], title: 'Yeni başlık', dueTime: '20:00', expectedRevision: 1),
        );
        self::assertSame(2, $updated->getRevisionNumber());
        self::assertSame('Yeni başlık', $updated->getTitle());
        self::assertSame('2026-11-12 17:00:00', $this->storedDueAt($updated));
        self::assertCount(2, $this->homeworkAuditRows());

        foreach ([1, null, 99] as $stale) {
            $exception = $this->failure(fn () => $this->homeworkManager()->updateDraft(
                $ctx['teacher'],
                $id,
                $this->draftInput($ctx['classroom'], title: 'Eski ekran', expectedRevision: $stale),
            ));
            self::assertSame(HomeworkDefinitionException::REASON_STALE_REVISION, $exception->getReason());
        }

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT title, revision_number FROM homework_definitions WHERE id = :id',
            ['id' => $id->toBinary()],
        );
        self::assertSame(['title' => 'Yeni başlık', 'revision_number' => 2], $row);
        $audit = $this->homeworkAuditRows();
        self::assertCount(2, $audit);
        self::assertSame('homework_definition_draft_updated', $audit[1]['action']);
        $metadata = json_decode($audit[1]['metadata'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($metadata);
        self::assertSame(2, $metadata['revision_number']);
        self::assertStringNotContainsString('Yeni başlık', $audit[1]['metadata']);
    }

    public function testInvalidUpdateDoesNotChangeRevisionOrAudit(): void
    {
        $ctx = $this->homeworkContext('hwm-badupd');
        $this->freezeClock();
        $definition = $this->homeworkManager()->createDraft($ctx['teacher'], $this->draftInput($ctx['classroom']));

        $exception = $this->failure(fn () => $this->homeworkManager()->updateDraft(
            $ctx['teacher'],
            $definition->getId(),
            $this->draftInput($ctx['classroom'], title: '', expectedRevision: 1),
        ));
        self::assertArrayHasKey('title', $exception->getFieldErrors());
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT revision_number FROM homework_definitions'));
        self::assertCount(1, $this->homeworkAuditRows());
    }

    public function testCoTeacherAndOtherTeachersCannotUpdateSomeoneElsesDraft(): void
    {
        $ctx = $this->homeworkContext('hwm-owner');
        $other = $this->homeworkContext('hwm-other');
        $co = $this->addCoTeacher($ctx, 'hwm-owner-co@example.com');
        $this->freezeClock();
        $definition = $this->homeworkManager()->createDraft($ctx['teacher'], $this->draftInput($ctx['classroom']));

        foreach ([$co['user'], $other['teacher']] as $intruder) {
            $exception = $this->failure(fn () => $this->homeworkManager()->updateDraft(
                $intruder,
                $definition->getId(),
                $this->draftInput($ctx['classroom'], title: 'Ele geçirildi', expectedRevision: 1),
            ));
            self::assertSame(HomeworkDefinitionException::REASON_NOT_FOUND, $exception->getReason());
        }
        self::assertSame('Kesirler çalışma kâğıdı', $this->em->getConnection()->fetchOne('SELECT title FROM homework_definitions'));
        self::assertCount(1, $this->homeworkAuditRows());

        // A co-teacher may prepare their own draft for the classroom they teach; it stays theirs.
        $own = $this->homeworkManager()->createDraft($co['user'], $this->draftInput($ctx['classroom'], title: 'Yardımcı ödevi'));
        self::assertTrue($own->getCreatedByUser()->getId()->equals($co['user']->getId()));
        self::assertSame(0, $this->homeworkQuery()->count($ctx['teacher'], 'Yardımcı'));
        self::assertSame(1, $this->homeworkQuery()->count($co['user']));
    }

    public function testForeignClassroomIsRejectedOnCreateAndUpdate(): void
    {
        $ctx = $this->homeworkContext('hwm-fa');
        $other = $this->homeworkContext('hwm-fb');
        $this->freezeClock();

        $exception = $this->failure(fn () => $this->homeworkManager()->createDraft(
            $ctx['teacher'],
            $this->draftInput($other['classroom']),
        ));
        self::assertArrayHasKey('classroom', $exception->getFieldErrors());
        self::assertSame(0, $this->homeworkRowCount());

        $definition = $this->homeworkManager()->createDraft($ctx['teacher'], $this->draftInput($ctx['classroom']));
        $move = $this->failure(fn () => $this->homeworkManager()->updateDraft(
            $ctx['teacher'],
            $definition->getId(),
            $this->draftInput($other['classroom'], expectedRevision: 1),
        ));
        self::assertArrayHasKey('classroom', $move->getFieldErrors());
        self::assertSame(
            $ctx['classroom']->getId()->toBinary(),
            $this->em->getConnection()->fetchOne('SELECT classroom_id FROM homework_definitions'),
        );
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT revision_number FROM homework_definitions'));
    }

    public function testAdminSuperAdminAndInstitutionOwnerHierarchyDoesNotBypass(): void
    {
        $ctx = $this->homeworkContext('hwm-hier');
        $admin = $this->activeUser('hwm-hier-admin@example.com');
        $admin->addGlobalRole(UserRole::Admin);
        $this->users->save($admin);
        $this->freezeClock();
        $definition = $this->homeworkManager()->createDraft($ctx['teacher'], $this->draftInput($ctx['classroom']));

        foreach ([$ctx['sa'], $admin, $ctx['owner']] as $actor) {
            $create = $this->failure(fn () => $this->homeworkManager()->createDraft($actor, $this->draftInput($ctx['classroom'], title: 'Yetkisiz')));
            self::assertArrayHasKey('classroom', $create->getFieldErrors());

            $update = $this->failure(fn () => $this->homeworkManager()->updateDraft(
                $actor,
                $definition->getId(),
                $this->draftInput($ctx['classroom'], title: 'Yetkisiz', expectedRevision: 1),
            ));
            self::assertSame(HomeworkDefinitionException::REASON_NOT_FOUND, $update->getReason());
            self::assertSame(0, $this->homeworkQuery()->count($actor));
        }
        self::assertSame(1, $this->homeworkRowCount());
    }

    public function testEndedAssignmentDeniesWritesAndHidesHistory(): void
    {
        $ctx = $this->homeworkContext('hwm-ended');
        $this->freezeClock();
        $definition = $this->homeworkManager()->createDraft($ctx['teacher'], $this->draftInput($ctx['classroom']));
        self::assertSame(1, $this->homeworkQuery()->count($ctx['teacher']));

        $this->teacherManager()->endAssignment($ctx['assignment'], $ctx['owner'], 'ended');

        $update = $this->failure(fn () => $this->homeworkManager()->updateDraft(
            $ctx['teacher'],
            $definition->getId(),
            $this->draftInput($ctx['classroom'], title: 'Atama bitti', expectedRevision: 1),
        ));
        self::assertSame(HomeworkDefinitionException::REASON_NOT_FOUND, $update->getReason());
        $create = $this->failure(fn () => $this->homeworkManager()->createDraft($ctx['teacher'], $this->draftInput($ctx['classroom'])));
        self::assertArrayHasKey('classroom', $create->getFieldErrors());
        self::assertSame(0, $this->homeworkQuery()->count($ctx['teacher']));
        self::assertNull($this->homeworkQuery()->findOwned($ctx['teacher'], $definition->getId()));
        self::assertSame(1, $this->homeworkRowCount());
        self::assertCount(1, $this->homeworkAuditRows());
    }

    public function testSuspendedMembershipDeniesWrites(): void
    {
        $ctx = $this->homeworkContext('hwm-susp');
        $this->freezeClock();
        $definition = $this->homeworkManager()->createDraft($ctx['teacher'], $this->draftInput($ctx['classroom']));
        $this->em->getConnection()->executeStatement(
            "UPDATE institution_memberships SET status = 'suspended' WHERE id = :id",
            ['id' => $ctx['teacherMembership']->getId()->toBinary()],
        );

        $update = $this->failure(fn () => $this->homeworkManager()->updateDraft(
            $ctx['teacher'],
            $definition->getId(),
            $this->draftInput($ctx['classroom'], title: 'Askıda', expectedRevision: 1),
        ));
        self::assertSame(HomeworkDefinitionException::REASON_NOT_FOUND, $update->getReason());
        $create = $this->failure(fn () => $this->homeworkManager()->createDraft($ctx['teacher'], $this->draftInput($ctx['classroom'])));
        self::assertArrayHasKey('classroom', $create->getFieldErrors());
        self::assertSame(0, $this->homeworkQuery()->count($ctx['teacher']));
    }

    public function testInactiveOrUnverifiedActorIsForbiddenEvenWithValidAssignment(): void
    {
        $ctx = $this->homeworkContext('hwm-actor');
        $this->freezeClock();
        $definition = $this->homeworkManager()->createDraft($ctx['teacher'], $this->draftInput($ctx['classroom']));

        $this->setUserStatusRaw($ctx['teacher'], UserStatus::Suspended);
        $this->assertForbidden($ctx['teacher'], $ctx['classroom'], $definition);

        $this->setUserStatusRaw($ctx['teacher'], UserStatus::Active);
        $this->clearEmailVerificationRaw($ctx['teacher']);
        $this->assertForbidden($ctx['teacher'], $ctx['classroom'], $definition);

        self::assertSame(1, $this->homeworkRowCount());
        self::assertCount(1, $this->homeworkAuditRows());
    }

    private function assertForbidden(User $actor, Classroom $classroom, HomeworkDefinition $definition): void
    {
        $create = $this->failure(fn () => $this->homeworkManager()->createDraft($actor, $this->draftInput($classroom)));
        self::assertSame(HomeworkDefinitionException::REASON_FORBIDDEN, $create->getReason());
        $update = $this->failure(fn () => $this->homeworkManager()->updateDraft(
            $actor,
            $definition->getId(),
            $this->draftInput($classroom, title: 'Pasif', expectedRevision: 1),
        ));
        self::assertSame(HomeworkDefinitionException::REASON_FORBIDDEN, $update->getReason());
    }

    private function failure(\Closure $action): HomeworkDefinitionException
    {
        try {
            $action();
        } catch (HomeworkDefinitionException $exception) {
            return $exception;
        }
        self::fail('Expected HomeworkDefinitionException.');
    }
}

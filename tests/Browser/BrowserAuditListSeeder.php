<?php

declare(strict_types=1);

namespace App\Tests\Browser;

use App\Entity\SecurityAuditEvent;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Synthetic security audit rows for browser acceptance of GET /yonetim/denetim.
 *
 * Persists SecurityAuditEvent::create only — no payment/provider/reconcile/webhook side effects.
 */
final class BrowserAuditListSeeder
{
    public const LONG_REASON = 'safe_reason_code_wrap_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array{
     *     list: string,
     *     longReason: string,
     *     longActionLabel: string,
     *     pagination: string
     * }
     */
    public function seed(User $superAdmin, Subject $subject): array
    {
        unset($superAdmin, $subject);

        $base = new \DateTimeImmutable('2026-09-13 16:00:00', new \DateTimeZone('UTC'));

        // Older fill rows keep pagination multi-page without pushing distinctive outcomes off page 1.
        for ($i = 1; $i <= 20; ++$i) {
            $this->persist(
                SecurityAuditAction::StatusChanged,
                SecurityAuditActorType::User,
                SecurityAuditOutcome::Success,
                $base->modify('+'.$i.' minutes'),
                ['source' => 'browser_audit_list', 'reason_code' => 'fill_'.$i],
            );
        }

        // Distinctive rows are newest so default page 1 (25/page) shows success + failure + long meta.
        $this->persist(
            SecurityAuditAction::LoginSucceeded,
            SecurityAuditActorType::User,
            SecurityAuditOutcome::Success,
            $base->modify('+30 minutes'),
            ['source' => 'browser_audit_list', 'reason_code' => 'login_ok'],
        );
        $this->persist(
            SecurityAuditAction::PaymentFailed,
            SecurityAuditActorType::System,
            SecurityAuditOutcome::Failure,
            $base->modify('+31 minutes'),
            ['source' => 'browser_audit_list', 'failure_code' => 'provider_declined'],
        );
        $this->persist(
            SecurityAuditAction::RoleChanged,
            SecurityAuditActorType::Cli,
            SecurityAuditOutcome::Success,
            $base->modify('+32 minutes'),
            ['source' => 'browser_audit_list', 'previous_roles' => 'ROLE_TEACHER', 'new_roles' => 'ROLE_ADMIN'],
        );
        $this->persist(
            SecurityAuditAction::HomeworkEvaluationRecorded,
            SecurityAuditActorType::User,
            SecurityAuditOutcome::Success,
            $base->modify('+33 minutes'),
            ['source' => 'browser_audit_list', 'label' => 'reviewed'],
        );
        // Long action/meta stays slightly older than the newest row so mixed and uzun shots differ.
        $this->persist(
            SecurityAuditAction::PaymentReconciliationDiscrepancyFound,
            SecurityAuditActorType::System,
            SecurityAuditOutcome::Failure,
            $base->modify('+34 minutes'),
            [
                'source' => 'browser_audit_list',
                'reason_code' => self::LONG_REASON,
                'reconciliation_run_id' => 'browser-run-long',
            ],
        );
        $this->persist(
            SecurityAuditAction::ModulePermissionChanged,
            SecurityAuditActorType::User,
            SecurityAuditOutcome::Success,
            $base->modify('+35 minutes'),
            ['source' => 'browser_audit_list', 'reason_code' => 'newest_anchor'],
        );

        $this->entityManager->flush();

        return [
            'list' => '/yonetim/denetim',
            'longReason' => self::LONG_REASON,
            'longActionLabel' => 'Ödeme uzlaştırma tutarsızlığı bulundu',
            'pagination' => '/yonetim/denetim?page_size=2&page=1',
        ];
    }

    /**
     * @param array<string, bool|int|string|null> $metadata
     */
    private function persist(
        SecurityAuditAction $action,
        SecurityAuditActorType $actorType,
        SecurityAuditOutcome $outcome,
        \DateTimeImmutable $occurredAt,
        array $metadata,
    ): void {
        $this->entityManager->persist(SecurityAuditEvent::create(
            action: $action,
            actorType: $actorType,
            outcome: $outcome,
            occurredAt: $occurredAt,
            metadata: $metadata,
        ));
    }
}

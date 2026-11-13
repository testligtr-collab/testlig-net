<?php

declare(strict_types=1);

namespace App\Tests\Browser;

use App\Entity\PaymentAttempt;
use App\Entity\PaymentReconciliationItem;
use App\Entity\PaymentReconciliationRun;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\AccessPackageCatalogResourceKind;
use App\Enum\AccessPackageTargetType;
use App\Enum\CommercialOfferBillingType;
use App\Enum\GradeLevel;
use App\Enum\PaymentAttemptStatus;
use App\Enum\PaymentProviderEnvironment;
use App\Enum\PaymentProviderTransactionStatus;
use App\Enum\PaymentReconciliationItemAction;
use App\Enum\PaymentReconciliationItemOutcome;
use App\Enum\PaymentReconciliationMode;
use App\Enum\PaymentReconciliationRunStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Service\AccessPackageManager;
use App\Service\AccessPackageVersionManager;
use App\Service\CommerceOrderManager;
use App\Service\CommercialOfferManager;
use App\Service\PaymentAttemptManager;
use App\Service\UserFactory;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Synthetic reconciliation detail fixtures for GET /yonetim/uzlastirma/{runId}.
 *
 * Domain constructors + PaymentReconciliationItem::record only — no reconcile CLI/provider.
 */
final class BrowserReconciliationDetailSeeder
{
    public const LONG_PROVIDER = 'sandbox_provider_long_code_xx';
    public const LONG_REASON = 'ops_manual_reconciliation_long_reason_code_x';

    public function __construct(
        private readonly UserFactory $userFactory,
        private readonly UserRepository $users,
        private readonly AccessPackageManager $packages,
        private readonly AccessPackageVersionManager $versions,
        private readonly CommercialOfferManager $offers,
        private readonly CommerceOrderManager $orders,
        private readonly PaymentAttemptManager $attempts,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array{
     *     completedEmpty: string,
     *     mixed: string,
     *     failedEmpty: string,
     *     runningEmpty: string,
     *     long: string,
     *     longProvider: string,
     *     longReason: string,
     *     pagination: string
     * }
     */
    public function seed(User $superAdmin, Subject $subject): array
    {
        $base = new \DateTimeImmutable('2026-09-13 16:00:00', new \DateTimeZone('UTC'));

        $completedEmpty = $this->persistRun(
            'sandbox_provider',
            PaymentProviderEnvironment::Sandbox,
            $base,
            PaymentReconciliationRunStatus::Completed,
            'manual_reconciliation',
            2,
            2,
            0,
            0,
        );
        $this->recordItem(
            $completedEmpty,
            $this->start($superAdmin, $subject, 'brd_matched'),
            PaymentAttemptStatus::Captured,
            PaymentProviderTransactionStatus::Captured,
            PaymentReconciliationItemOutcome::Matched,
            PaymentReconciliationItemAction::None,
            $base->modify('+5 seconds'),
            null,
        );

        $mixed = $this->persistRun(
            'sandbox_provider',
            PaymentProviderEnvironment::Sandbox,
            $base->modify('+10 minutes'),
            PaymentReconciliationRunStatus::CompletedWithDiscrepancies,
            'manual_reconciliation',
            4,
            0,
            4,
            0,
        );
        $this->recordItem($mixed, $this->start($superAdmin, $subject, 'brd_m1'), PaymentAttemptStatus::Authorized, PaymentProviderTransactionStatus::Captured, PaymentReconciliationItemOutcome::LocalBehind, PaymentReconciliationItemAction::WebhookRequeued, $base->modify('+11 minutes'), 'local_behind_capture');
        $this->recordItem($mixed, $this->start($superAdmin, $subject, 'brd_m2'), PaymentAttemptStatus::Captured, PaymentProviderTransactionStatus::Authorized, PaymentReconciliationItemOutcome::ProviderBehind, PaymentReconciliationItemAction::ManualReviewRequired, $base->modify('+12 minutes'), 'provider_behind');
        $this->recordItem($mixed, $this->start($superAdmin, $subject, 'brd_m3'), PaymentAttemptStatus::Captured, PaymentProviderTransactionStatus::Captured, PaymentReconciliationItemOutcome::AmountMismatch, PaymentReconciliationItemAction::ManualReviewRequired, $base->modify('+13 minutes'), 'amount_mismatch');
        $this->recordItem($mixed, $this->start($superAdmin, $subject, 'brd_m4'), PaymentAttemptStatus::Initiated, null, PaymentReconciliationItemOutcome::Failed, PaymentReconciliationItemAction::ManualReviewRequired, $base->modify('+14 minutes'), 'provider_query_failed');

        $failedEmpty = $this->persistRun(
            'sandbox_provider',
            PaymentProviderEnvironment::Sandbox,
            $base->modify('+20 minutes'),
            PaymentReconciliationRunStatus::Failed,
            'manual_reconciliation',
            1,
            0,
            0,
            1,
        );

        $runningEmpty = $this->persistRun(
            'sandbox_provider',
            PaymentProviderEnvironment::Sandbox,
            $base->modify('+30 minutes'),
            PaymentReconciliationRunStatus::Running,
            'manual_reconciliation',
            0,
            0,
            0,
            0,
        );

        $long = $this->persistRun(
            self::LONG_PROVIDER,
            PaymentProviderEnvironment::Production,
            $base->modify('+40 minutes'),
            PaymentReconciliationRunStatus::CompletedWithDiscrepancies,
            self::LONG_REASON,
            1,
            0,
            1,
            0,
            PaymentReconciliationMode::Scheduled,
        );
        $this->recordItem($long, $this->start($superAdmin, $subject, 'brd_long'), PaymentAttemptStatus::Captured, null, PaymentReconciliationItemOutcome::MissingAtProvider, PaymentReconciliationItemAction::None, $base->modify('+41 minutes'), 'missing_at_provider');

        $paged = $this->persistRun(
            'sandbox_provider',
            PaymentProviderEnvironment::Sandbox,
            $base->modify('+50 minutes'),
            PaymentReconciliationRunStatus::CompletedWithDiscrepancies,
            'manual_reconciliation',
            6,
            0,
            6,
            0,
        );
        for ($i = 1; $i <= 6; ++$i) {
            $this->recordItem(
                $paged,
                $this->start($superAdmin, $subject, 'brd_p'.$i),
                PaymentAttemptStatus::Authorized,
                PaymentProviderTransactionStatus::Authorized,
                PaymentReconciliationItemOutcome::Unsupported,
                PaymentReconciliationItemAction::ManualReviewRequired,
                $base->modify('+'.(50 + $i).' minutes'),
                'reconciliation_unsupported',
            );
        }

        $this->entityManager->flush();

        return [
            'completedEmpty' => '/yonetim/uzlastirma/'.$completedEmpty->getId()->toRfc4122(),
            'mixed' => '/yonetim/uzlastirma/'.$mixed->getId()->toRfc4122(),
            'failedEmpty' => '/yonetim/uzlastirma/'.$failedEmpty->getId()->toRfc4122(),
            'runningEmpty' => '/yonetim/uzlastirma/'.$runningEmpty->getId()->toRfc4122(),
            'long' => '/yonetim/uzlastirma/'.$long->getId()->toRfc4122(),
            'longProvider' => self::LONG_PROVIDER,
            'longReason' => self::LONG_REASON,
            'pagination' => '/yonetim/uzlastirma/'.$paged->getId()->toRfc4122().'?page_size=2&page=1',
        ];
    }

    private function persistRun(
        string $providerCode,
        PaymentProviderEnvironment $environment,
        \DateTimeImmutable $startedAt,
        PaymentReconciliationRunStatus $status,
        string $reasonCode,
        int $checked,
        int $matched,
        int $discrepancy,
        int $failed,
        PaymentReconciliationMode $mode = PaymentReconciliationMode::Manual,
    ): PaymentReconciliationRun {
        $run = PaymentReconciliationRun::start(
            providerCode: $providerCode,
            environment: $environment,
            mode: $mode,
            reasonCode: $reasonCode,
            startedAt: $startedAt,
            createdBy: null,
        );
        if (PaymentReconciliationRunStatus::Running !== $status) {
            $run->complete(
                $status,
                $checked,
                $matched,
                $discrepancy,
                $failed,
                $startedAt->modify('+30 seconds'),
            );
        }
        $this->entityManager->persist($run);

        return $run;
    }

    private function recordItem(
        PaymentReconciliationRun $run,
        PaymentAttempt $attempt,
        PaymentAttemptStatus $expected,
        ?PaymentProviderTransactionStatus $providerState,
        PaymentReconciliationItemOutcome $outcome,
        PaymentReconciliationItemAction $action,
        \DateTimeImmutable $checkedAt,
        ?string $reasonCode,
    ): void {
        $this->entityManager->persist(PaymentReconciliationItem::record(
            $run,
            $attempt,
            $expected,
            $providerState,
            $outcome,
            $action,
            null,
            $checkedAt,
            $reasonCode,
        ));
    }

    private function start(User $superAdmin, Subject $subject, string $suffix): PaymentAttempt
    {
        $unique = $suffix.'_'.bin2hex(random_bytes(2));
        $package = $this->packages->create(
            $superAdmin,
            $unique.'_pkg',
            'Pkg '.$unique,
            null,
            AccessPackageTargetType::Individual,
            30,
            null,
            'create_pkg',
        );
        $version = $this->versions->createDraftVersion($package, $superAdmin, 30, null, 'create_version');
        $this->versions->addCatalogGrant(
            $version,
            AccessPackageCatalogResourceKind::LearningContent,
            $subject,
            GradeLevel::Grade9,
            $superAdmin,
            'add_catalog_grant',
        );
        $version = $this->versions->activate($version, $superAdmin, 'activate_version');
        $offer = $this->offers->createDraft(
            $superAdmin,
            $version,
            $unique.'_code',
            'Offer '.$unique,
            null,
            CommercialOfferBillingType::OneTime,
            null,
            25990,
            'TRY',
            0,
            'create_offer',
        );
        $offer = $this->offers->activate($offer, $superAdmin, 'activate_offer');
        $buyer = $this->activeUser($unique.'_buyer@example.test', UserRole::Student);
        $order = $this->orders->createUserOrder(
            $buyer,
            $buyer,
            [['offer' => $offer, 'quantity' => 1]],
            'create_order',
        );

        return $this->attempts->start(
            $order,
            $buyer,
            'sandbox_provider',
            PaymentProviderEnvironment::Sandbox,
            $unique.'-key-0000000000000000',
            'start_payment',
        );
    }

    private function activeUser(string $email, UserRole $role): User
    {
        $initial = $role->isPrivilegedBootstrapRole() ? UserRole::Teacher : $role;
        $user = $this->userFactory->createAndPersist($email, 'Guclu-Parola-123!', 'A', 'U', $initial);
        $user->markEmailVerified(new \DateTimeImmutable('2026-09-01 00:00:00'));
        $user->transitionTo(UserStatus::Active);
        if ($initial !== $role) {
            $user->addGlobalRole($role);
        }
        $this->users->save($user);

        return $user;
    }
}

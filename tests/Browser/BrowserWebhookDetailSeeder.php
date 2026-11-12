<?php

declare(strict_types=1);

namespace App\Tests\Browser;

use App\Commerce\VerifiedPaymentWebhook;
use App\Entity\PaymentAttempt;
use App\Entity\PaymentWebhookInboxEvent;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\AccessPackageCatalogResourceKind;
use App\Enum\AccessPackageTargetType;
use App\Enum\CommercialOfferBillingType;
use App\Enum\GradeLevel;
use App\Enum\PaymentEventType;
use App\Enum\PaymentProviderEnvironment;
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
use Symfony\Component\Uid\Uuid;

/**
 * Synthetic webhook inbox detail rows for browser acceptance of GET /yonetim/webhook/{id}.
 */
final class BrowserWebhookDetailSeeder
{
    public const LONG_FAILURE = 'provider_timeout_insufficient_funds_retry_later';

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
     *     received: string,
     *     deadLetter: string,
     *     processed: string,
     *     rejected: string,
     *     staleLease: string,
     *     longFailure: string,
     *     longFailureCode: string,
     *     paymentAttemptPath: string
     * }
     */
    public function seed(User $superAdmin, Subject $subject): array
    {
        $now = new \DateTimeImmutable('2026-09-13 12:00:00', new \DateTimeZone('UTC'));

        // Reuse the browser SuperAdmin for package ownership so teacher-directory fixtures stay stable.
        $received = $this->persist($superAdmin, $subject, 'br_whd_recv', PaymentEventType::Authorized, 'received', $now);
        $dead = $this->persist($superAdmin, $subject, 'br_whd_dead', PaymentEventType::Failed, 'dead_letter', $now->modify('+1 minute'));
        $processed = $this->persist($superAdmin, $subject, 'br_whd_proc', PaymentEventType::Captured, 'processed', $now->modify('+2 minutes'));
        $rejected = $this->persist($superAdmin, $subject, 'br_whd_rej', PaymentEventType::Cancelled, 'rejected', $now->modify('+3 minutes'));
        $stale = $this->persist($superAdmin, $subject, 'br_whd_stale', PaymentEventType::Authorized, 'stale', $now->modify('+4 minutes'));
        $long = $this->persist(
            $superAdmin,
            $subject,
            'br_whd_long',
            PaymentEventType::RefundSucceeded,
            'dead_letter',
            $now->modify('+5 minutes'),
            self::LONG_FAILURE,
        );

        $this->entityManager->flush();

        $attemptId = $long->getPaymentAttempt()?->getId();
        if (!$attemptId instanceof Uuid) {
            throw new \LogicException('Webhook detail long fixture must keep a payment attempt link.');
        }

        return [
            'received' => '/yonetim/webhook/'.$received->getId()->toRfc4122(),
            'deadLetter' => '/yonetim/webhook/'.$dead->getId()->toRfc4122(),
            'processed' => '/yonetim/webhook/'.$processed->getId()->toRfc4122(),
            'rejected' => '/yonetim/webhook/'.$rejected->getId()->toRfc4122(),
            'staleLease' => '/yonetim/webhook/'.$stale->getId()->toRfc4122(),
            'longFailure' => '/yonetim/webhook/'.$long->getId()->toRfc4122(),
            'longFailureCode' => self::LONG_FAILURE,
            'paymentAttemptPath' => '/yonetim/odemeler/'.$attemptId->toRfc4122(),
        ];
    }

    private function persist(
        User $superAdmin,
        Subject $subject,
        string $suffix,
        PaymentEventType $eventType,
        string $mode,
        \DateTimeImmutable $receivedAt,
        ?string $failureCode = null,
    ): PaymentWebhookInboxEvent {
        $attempt = $this->start($superAdmin, $suffix, PaymentProviderEnvironment::Sandbox, $subject);
        $ref = 'br-whd-'.$suffix.'-ref';
        $verified = new VerifiedPaymentWebhook(
            providerCode: 'sandbox_provider',
            environment: PaymentProviderEnvironment::Sandbox,
            providerEventReference: $ref,
            eventType: $eventType,
            payloadHash: hash('sha256', 'br-whd-body-'.$ref),
            signatureFingerprint: hash('sha256', 'br-whd-sig-'.$ref),
            providerOccurredAt: $receivedAt,
            receivedAt: $receivedAt,
            paymentAttemptId: $attempt->getId(),
            orderPublicReference: $attempt->getOrder()->getPublicReference(),
            amount: $attempt->getAmount(),
            sanitizedMetadata: [
                'provider_code' => 'sandbox_provider',
                'provider_environment' => 'sandbox',
                'event_source' => 'webhook',
                'amount_minor' => $attempt->getAmountMinor(),
                'currency' => $attempt->getCurrency(),
            ],
        );
        $event = PaymentWebhookInboxEvent::receiveVerified($verified, $attempt);
        $this->entityManager->persist($event);
        $this->entityManager->flush();

        if ('received' === $mode) {
            return $event;
        }

        if ('stale' === $mode) {
            $claim = Uuid::v7();
            $event->applyClaim($claim, $receivedAt, 60);
            $this->entityManager->flush();

            return $event;
        }

        $claim = Uuid::v7();
        $event->applyClaim($claim, $receivedAt);
        $this->entityManager->flush();
        $reason = $failureCode ?? 'retry_exhausted';
        if ('dead_letter' === $mode) {
            $event->markDeadLetter($reason, $receivedAt, $claim);
        } elseif ('processed' === $mode) {
            $event->markProcessed($claim, $receivedAt);
        } elseif ('rejected' === $mode) {
            $event->markRejected($reason, $receivedAt, $claim);
        }
        $this->entityManager->flush();

        return $event;
    }

    private function start(User $superAdmin, string $suffix, PaymentProviderEnvironment $environment, Subject $subject): PaymentAttempt
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
            $environment,
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

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
 * Synthetic webhook inbox rows for browser acceptance of GET /yonetim/webhook.
 *
 * Reuses an existing subject so curriculum screens are not polluted with fixture subjects.
 */
final class BrowserWebhookListSeeder
{
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
     *     list: string,
     *     deadLetter: string,
     *     longEventTypeLabel: string,
     *     longEventDetail: string
     * }
     */
    public function seed(User $superAdmin, Subject $subject): array
    {
        unset($superAdmin);

        $now = new \DateTimeImmutable('2026-09-13 12:00:00', new \DateTimeZone('UTC'));

        $this->persist($subject, 'br_wh_due_a', PaymentEventType::Authorized, false, $now);
        $this->persist($subject, 'br_wh_due_b', PaymentEventType::Captured, false, $now->modify('+1 minute'));
        $long = $this->persist($subject, 'br_wh_due_long', PaymentEventType::RefundRequested, false, $now->modify('+2 minutes'));
        $this->persist($subject, 'br_wh_due_c', PaymentEventType::Failed, false, $now->modify('+3 minutes'));
        $this->persist($subject, 'br_wh_due_d', PaymentEventType::Cancelled, false, $now->modify('+4 minutes'));

        $this->persist($subject, 'br_wh_dead', PaymentEventType::Failed, true, $now->modify('+5 minutes'));
        $this->persist($subject, 'br_wh_dead_b', PaymentEventType::Authorized, true, $now->modify('+6 minutes'));

        $this->entityManager->flush();

        return [
            'list' => '/yonetim/webhook',
            'deadLetter' => '/yonetim/webhook?tab=dead_letter',
            'longEventTypeLabel' => 'İade talebi',
            'longEventDetail' => '/yonetim/webhook/'.$long->getId()->toRfc4122(),
        ];
    }

    private function persist(
        Subject $subject,
        string $suffix,
        PaymentEventType $eventType,
        bool $deadLetter,
        \DateTimeImmutable $receivedAt,
    ): PaymentWebhookInboxEvent {
        $attempt = $this->start($suffix, PaymentProviderEnvironment::Sandbox, $subject);
        $ref = 'br-wh-'.$suffix.'-ref';
        $verified = new VerifiedPaymentWebhook(
            providerCode: 'sandbox_provider',
            environment: PaymentProviderEnvironment::Sandbox,
            providerEventReference: $ref,
            eventType: $eventType,
            payloadHash: hash('sha256', 'br-body-'.$ref),
            signatureFingerprint: hash('sha256', 'br-sig-'.$ref),
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

        if ($deadLetter) {
            // Flush Processing first: MariaDB status-transition trigger rejects Received → DeadLetter.
            $claim = Uuid::v7();
            $event->applyClaim($claim, $receivedAt);
            $this->entityManager->flush();
            $event->markDeadLetter('retry_exhausted', $receivedAt, $claim);
            $this->entityManager->flush();
        }

        return $event;
    }

    private function start(string $suffix, PaymentProviderEnvironment $environment, Subject $subject): PaymentAttempt
    {
        $unique = $suffix.'_'.bin2hex(random_bytes(2));
        $sa = $this->activeUser($unique.'_pkg_sa@example.test', UserRole::SuperAdmin);
        $package = $this->packages->create(
            $sa,
            $unique.'_pkg',
            'Pkg '.$unique,
            null,
            AccessPackageTargetType::Individual,
            30,
            null,
            'create_pkg',
        );
        $version = $this->versions->createDraftVersion($package, $sa, 30, null, 'create_version');
        $this->versions->addCatalogGrant(
            $version,
            AccessPackageCatalogResourceKind::LearningContent,
            $subject,
            GradeLevel::Grade9,
            $sa,
            'add_catalog_grant',
        );
        $version = $this->versions->activate($version, $sa, 'activate_version');
        $offer = $this->offers->createDraft(
            $sa,
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
        $offer = $this->offers->activate($offer, $sa, 'activate_offer');
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

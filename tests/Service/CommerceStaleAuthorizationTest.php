<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\CommerceOrder;
use App\Entity\Institution;
use App\Entity\User;
use App\Enum\AccessPackageTargetType;
use App\Enum\CommerceFailureReason;
use App\Enum\InstitutionMembershipRole;
use App\Enum\PaymentProviderEnvironment;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Service\CommerceOrderManager;
use App\Service\CommercialOfferManager;
use App\Service\InstitutionMembershipManager;
use App\Service\InstitutionStatusManager;
use App\Service\PaymentAttemptManager;
use App\Tests\Support\CommerceTestFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\NativeClock;

/**
 * Authorization is always re-proved against freshly loaded rows, never against the entity
 * the caller happens to hold. These tests mutate state after an order exists and expect
 * the next commerce mutation to refuse it.
 */
final class CommerceStaleAuthorizationTest extends KernelTestCase
{
    use CommerceTestFixtures;

    protected function setUp(): void
    {
        $this->bootCommerce();
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        try {
            $this->cleanupCommerce();
        } catch (\Throwable) {
            self::ensureKernelShutdown();
        }
        parent::tearDown();
    }

    public function testSuspendedPurchaserCannotStartAPaymentForAnExistingOrder(): void
    {
        $sa = $this->scenario->superAdmin('stale1-sa@example.com');
        $version = $this->scenario->activeVersion($sa, 'stale1');
        $offer = $this->scenario->activeOffer($sa, $version, 'stale1_code');
        $buyer = $this->scenario->activeUser('stale1-buyer@example.com');
        $order = $this->orders()->createUserOrder(
            $buyer,
            $buyer,
            [['offer' => $offer, 'quantity' => 1]],
            'create_order',
        );

        $buyer->transitionTo(UserStatus::Suspended);
        $this->scenario->service(UserRepository::class)->save($buyer);

        $this->expectCommerceFailure(
            CommerceFailureReason::Unauthorized,
            function () use ($order, $buyer): void {
                $this->attempts()->start(
                    $order,
                    $buyer,
                    'sandbox_provider',
                    PaymentProviderEnvironment::Sandbox,
                    'stale1-key-000000000000',
                    'start_payment',
                );
            },
        );
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM payment_attempts'));
    }

    public function testUnverifiedEmailAfterOrderCreationBlocksPayment(): void
    {
        $sa = $this->scenario->superAdmin('stale2-sa@example.com');
        $version = $this->scenario->activeVersion($sa, 'stale2');
        $offer = $this->scenario->activeOffer($sa, $version, 'stale2_code');
        $buyer = $this->scenario->activeUser('stale2-buyer@example.com');
        $order = $this->orders()->createUserOrder(
            $buyer,
            $buyer,
            [['offer' => $offer, 'quantity' => 1]],
            'create_order',
        );

        $this->em->getConnection()->executeStatement(
            'UPDATE users SET email_verified_at = NULL WHERE id = ?',
            [$buyer->getId()->toBinary()],
        );
        $this->em->clear();

        $this->expectCommerceFailure(
            CommerceFailureReason::Unauthorized,
            function () use ($order, $buyer): void {
                $this->attempts()->start(
                    $this->scenario->refresh(CommerceOrder::class, $order->getId()),
                    $this->scenario->refresh(User::class, $buyer->getId()),
                    'sandbox_provider',
                    PaymentProviderEnvironment::Sandbox,
                    'stale2-key-000000000000',
                    'start_payment',
                );
            },
        );
    }

    public function testRevokedOwnershipBlocksInstitutionPayments(): void
    {
        $sa = $this->scenario->superAdmin('stale3-sa@example.com');
        [$institution, $owner] = $this->scenario->activeInstitution('stale3', $sa);
        $sa = $this->scenario->refresh(User::class, $sa->getId());
        $version = $this->scenario->activeVersion(
            $sa,
            'stale3',
            AccessPackageTargetType::Institution,
            365,
            5,
            365,
        );
        $offer = $this->scenario->activeOffer($sa, $version, 'stale3_code', 50000, 2000);
        $order = $this->orders()->createInstitutionOrder(
            $owner,
            $institution,
            [['offer' => $offer, 'quantity' => 1]],
            'create_order',
        );

        // Demoting the owner to a seat-only Manager must immediately close the payment path.
        $this->em->getConnection()->executeStatement(
            'UPDATE institution_memberships SET role = ? WHERE institution_id = ? AND user_id = ?',
            [
                InstitutionMembershipRole::Manager->value,
                $institution->getId()->toBinary(),
                $owner->getId()->toBinary(),
            ],
        );
        $this->em->clear();

        $this->expectCommerceFailure(
            CommerceFailureReason::Unauthorized,
            function () use ($order, $owner): void {
                $this->attempts()->start(
                    $this->scenario->refresh(CommerceOrder::class, $order->getId()),
                    $this->scenario->refresh(User::class, $owner->getId()),
                    'sandbox_provider',
                    PaymentProviderEnvironment::Sandbox,
                    'stale3-key-000000000000',
                    'start_payment',
                );
            },
        );
    }

    public function testDeactivatedInstitutionBlocksPaymentsForItsOrders(): void
    {
        $sa = $this->scenario->superAdmin('stale4-sa@example.com');
        [$institution, $owner] = $this->scenario->activeInstitution('stale4', $sa);
        $sa = $this->scenario->refresh(User::class, $sa->getId());
        $version = $this->scenario->activeVersion(
            $sa,
            'stale4',
            AccessPackageTargetType::Institution,
            365,
            5,
            365,
        );
        $offer = $this->scenario->activeOffer($sa, $version, 'stale4_code', 50000, 2000);
        $order = $this->orders()->createInstitutionOrder(
            $owner,
            $institution,
            [['offer' => $offer, 'quantity' => 1]],
            'create_order',
        );

        $this->scenario->service(InstitutionStatusManager::class)->suspend(
            $this->scenario->refresh(Institution::class, $institution->getId()),
            $this->scenario->refresh(User::class, $sa->getId()),
            'suspend_inst',
        );
        $this->em->clear();

        $this->expectCommerceFailure(
            CommerceFailureReason::NotFound,
            function () use ($order, $owner): void {
                $this->attempts()->start(
                    $this->scenario->refresh(CommerceOrder::class, $order->getId()),
                    $this->scenario->refresh(User::class, $owner->getId()),
                    'sandbox_provider',
                    PaymentProviderEnvironment::Sandbox,
                    'stale4-key-000000000000',
                    'start_payment',
                );
            },
        );
    }

    public function testRetiringAnOfferDoesNotBreakAnAlreadySealedOrder(): void
    {
        $sa = $this->scenario->superAdmin('stale5-sa@example.com');
        $version = $this->scenario->activeVersion($sa, 'stale5');
        $offer = $this->scenario->activeOffer($sa, $version, 'stale5_code', 10000, 2000);
        $buyer = $this->scenario->activeUser('stale5-buyer@example.com');
        $order = $this->orders()->createUserOrder(
            $buyer,
            $buyer,
            [['offer' => $offer, 'quantity' => 1]],
            'create_order',
        );

        $this->offers()->retire(
            $this->scenario->refresh(\App\Entity\CommercialOffer::class, $offer->getId()),
            $this->scenario->refresh(User::class, $sa->getId()),
            'retire_offer',
        );

        // The order keeps its frozen snapshot, so an in-flight purchase can still be paid.
        $attempt = $this->attempts()->start(
            $this->scenario->refresh(CommerceOrder::class, $order->getId()),
            $this->scenario->refresh(User::class, $buyer->getId()),
            'sandbox_provider',
            PaymentProviderEnvironment::Sandbox,
            'stale5-key-000000000000',
            'start_payment',
        );
        self::assertSame(12000, $attempt->getAmountMinor());
    }

    public function testAMemberWhoIsNotTheOwnerCanNeverStartAnInstitutionPayment(): void
    {
        $sa = $this->scenario->superAdmin('stale6-sa@example.com');
        [$institution, $owner] = $this->scenario->activeInstitution('stale6', $sa);
        $sa = $this->scenario->refresh(User::class, $sa->getId());
        $version = $this->scenario->activeVersion(
            $sa,
            'stale6',
            AccessPackageTargetType::Institution,
            365,
            5,
            365,
        );
        $offer = $this->scenario->activeOffer($sa, $version, 'stale6_code', 50000, 2000);
        $order = $this->orders()->createInstitutionOrder(
            $owner,
            $institution,
            [['offer' => $offer, 'quantity' => 1]],
            'create_order',
        );

        $manager = $this->scenario->activeUser('stale6-manager@example.com');
        $this->scenario->service(InstitutionMembershipManager::class)->addMember(
            $this->scenario->refresh(Institution::class, $institution->getId()),
            $this->scenario->refresh(User::class, $owner->getId()),
            $manager,
            InstitutionMembershipRole::Manager,
            'add_manager',
        );
        $this->em->clear();

        $this->expectCommerceFailure(
            CommerceFailureReason::Unauthorized,
            function () use ($order, $manager): void {
                $this->attempts()->start(
                    $this->scenario->refresh(CommerceOrder::class, $order->getId()),
                    $this->scenario->refresh(User::class, $manager->getId()),
                    'sandbox_provider',
                    PaymentProviderEnvironment::Sandbox,
                    'stale6-key-000000000000',
                    'start_payment',
                );
            },
        );
    }

    private function offers(): CommercialOfferManager
    {
        return $this->scenario->service(CommercialOfferManager::class);
    }

    private function orders(): CommerceOrderManager
    {
        return $this->scenario->service(CommerceOrderManager::class);
    }

    private function attempts(): PaymentAttemptManager
    {
        return $this->scenario->service(PaymentAttemptManager::class);
    }
}

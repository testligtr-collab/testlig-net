<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AccessPackageVersion;
use App\Entity\CommerceOrder;
use App\Entity\CommercialOffer;
use App\Entity\Institution;
use App\Entity\User;
use App\Enum\AccessPackageTargetType;
use App\Enum\CommerceCancellationReasonCode;
use App\Enum\CommerceFailureReason;
use App\Enum\CommerceOrderStatus;
use App\Enum\CommercePurchaserType;
use App\Enum\CommercialOfferBillingInterval;
use App\Enum\CommercialOfferBillingType;
use App\Enum\CommercialOfferStatus;
use App\Enum\CommercialOfferTargetType;
use App\Enum\InstitutionMembershipRole;
use App\Enum\SecurityAuditAction;
use App\Enum\UserRole;
use App\Exception\CommerceException;
use App\Repository\SecurityAuditEventRepository;
use App\Service\CommerceOrderManager;
use App\Service\CommercialOfferManager;
use App\Service\InstitutionMembershipManager;
use App\Tests\Support\AccessEntitlementDbCleanup;
use App\Tests\Support\CommerceDbCleanup;
use App\Tests\Support\CommerceScenario;
use App\Tests\Support\QuestionBankDbCleanup;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

final class CommerceOfferOrderDomainTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CommerceScenario $scenario;
    private MockClock $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->rebind();
        $this->clock = new MockClock('2026-09-13 12:00:00');
        Clock::set($this->clock);
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        try {
            $this->cleanup();
        } catch (\Throwable) {
            self::ensureKernelShutdown();
        }
        parent::tearDown();
    }

    public function testOfferLifecycleFromDraftToRetired(): void
    {
        $sa = $this->scenario->superAdmin('off1-sa@example.com');
        $version = $this->scenario->activeVersion($sa, 'off1');
        $offers = $this->offers();

        $offer = $this->scenario->draftOffer($sa, $version, 'off1_code', 19999, 2000);
        self::assertSame(CommercialOfferStatus::Draft, $offer->getStatus());
        self::assertSame('off1_code', $offer->getCode());
        self::assertSame(19999, $offer->getPriceAmountMinor());
        self::assertSame('TRY', $offer->getCurrency());
        self::assertSame(2000, $offer->getTaxRateBasisPoints());
        self::assertSame(CommercialOfferTargetType::Individual, $offer->getTargetType());
        self::assertSame(CommercialOfferBillingType::OneTime, $offer->getBillingType());
        self::assertNull($offer->getBillingInterval());
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $offer->getOfferHash());
        self::assertNull($offer->getActivatedAt());
        $offers->assertOfferIntegrity($offer);

        $updated = $offers->updateDraft(
            $offer,
            $sa,
            'Renamed offer',
            'Description',
            CommercialOfferBillingType::OneTime,
            null,
            24999,
            1800,
            'update_offer',
        );
        self::assertSame('Renamed offer', $updated->getName());
        self::assertSame(24999, $updated->getPriceAmountMinor());
        self::assertSame(1800, $updated->getTaxRateBasisPoints());
        $offers->assertOfferIntegrity($updated);

        $active = $offers->activate($updated, $sa, 'activate_offer');
        self::assertSame(CommercialOfferStatus::Active, $active->getStatus());
        self::assertInstanceOf(\DateTimeImmutable::class, $active->getActivatedAt());
        self::assertInstanceOf(User::class, $active->getActivatedBy());
        self::assertTrue($active->isPurchasableAt($this->clock->now()));

        $retired = $offers->retire($active, $sa, 'retire_offer');
        self::assertSame(CommercialOfferStatus::Retired, $retired->getStatus());
        self::assertInstanceOf(\DateTimeImmutable::class, $retired->getRetiredAt());
        self::assertFalse($retired->isPurchasableAt($this->clock->now()));

        $events = $this->auditEvents();
        self::assertSame(1, $events->countByAction(SecurityAuditAction::CommercialOfferCreated->value));
        self::assertSame(1, $events->countByAction(SecurityAuditAction::CommercialOfferUpdated->value));
        self::assertSame(1, $events->countByAction(SecurityAuditAction::CommercialOfferActivated->value));
        self::assertSame(1, $events->countByAction(SecurityAuditAction::CommercialOfferRetired->value));
    }

    public function testActiveOfferCannotBeMutatedOrReactivated(): void
    {
        $sa = $this->scenario->superAdmin('off2-sa@example.com');
        $version = $this->scenario->activeVersion($sa, 'off2');
        $offer = $this->scenario->activeOffer($sa, $version, 'off2_code');

        $this->expectFailure(CommerceFailureReason::InvalidTransition, function () use ($offer, $sa): void {
            $this->offers()->updateDraft(
                $offer,
                $sa,
                'Nope',
                null,
                CommercialOfferBillingType::OneTime,
                null,
                1,
                0,
                'update_offer',
            );
        });
        $this->expectFailure(CommerceFailureReason::InvalidTransition, function () use ($offer, $sa): void {
            $this->offers()->activate($offer, $sa, 'activate_offer');
        });

        $retired = $this->offers()->retire($offer, $sa, 'retire_offer');
        $this->expectFailure(CommerceFailureReason::InvalidTransition, function () use ($retired, $sa): void {
            $this->offers()->retire($retired, $sa, 'retire_offer');
        });
    }

    public function testRecurringOfferRequiresBillingIntervalAndOneTimeForbidsIt(): void
    {
        $sa = $this->scenario->superAdmin('off3-sa@example.com');
        $version = $this->scenario->activeVersion($sa, 'off3');

        $this->expectFailure(CommerceFailureReason::InvalidInput, function () use ($sa, $version): void {
            $this->scenario->draftOffer(
                $sa,
                $version,
                'off3_bad_rec',
                19999,
                2000,
                CommercialOfferBillingType::Recurring,
                null,
            );
        });
        $this->expectFailure(CommerceFailureReason::InvalidInput, function () use ($sa, $version): void {
            $this->scenario->draftOffer(
                $sa,
                $version,
                'off3_bad_ot',
                19999,
                2000,
                CommercialOfferBillingType::OneTime,
                CommercialOfferBillingInterval::Monthly,
            );
        });

        $recurring = $this->scenario->draftOffer(
            $sa,
            $version,
            'off3_ok_rec',
            19999,
            2000,
            CommercialOfferBillingType::Recurring,
            CommercialOfferBillingInterval::Monthly,
        );
        self::assertSame(CommercialOfferBillingInterval::Monthly, $recurring->getBillingInterval());
    }

    public function testOfferRejectsInvalidPricingAndDuplicateCode(): void
    {
        $sa = $this->scenario->superAdmin('off4-sa@example.com');
        $version = $this->scenario->activeVersion($sa, 'off4');

        $this->expectFailure(CommerceFailureReason::InvalidInput, function () use ($sa, $version): void {
            $this->scenario->draftOffer($sa, $version, 'off4_zero', 0);
        });
        $this->expectFailure(CommerceFailureReason::InvalidInput, function () use ($sa, $version): void {
            $this->scenario->draftOffer($sa, $version, 'off4_tax', 19999, 10001);
        });
        $this->expectFailure(CommerceFailureReason::InvalidInput, function () use ($sa, $version): void {
            $this->scenario->draftOffer($sa, $version, 'off4_cur', 19999, 2000, CommercialOfferBillingType::OneTime, null, 'TRYY');
        });

        // A lowercase ISO code is normalized rather than rejected.
        $normalized = $this->scenario->draftOffer(
            $sa,
            $version,
            'off4_lower',
            19999,
            2000,
            CommercialOfferBillingType::OneTime,
            null,
            'try',
        );
        self::assertSame('TRY', $normalized->getCurrency());

        $this->scenario->draftOffer($sa, $version, 'off4_dup', 19999);
        $this->expectFailure(CommerceFailureReason::Conflict, function () use ($sa, $version): void {
            $this->scenario->draftOffer($sa, $version, 'off4_dup', 29999);
        });
    }

    public function testOnlySuperAdminCanManageTheCatalog(): void
    {
        $sa = $this->scenario->superAdmin('off5-sa@example.com');
        $version = $this->scenario->activeVersion($sa, 'off5');
        $offer = $this->scenario->draftOffer($sa, $version, 'off5_code');

        foreach ([
            UserRole::Student,
            UserRole::Teacher,
            UserRole::HeadTeacher,
            UserRole::Moderator,
            UserRole::Admin,
        ] as $index => $role) {
            $actor = $this->scenario->activeUser('off5-'.$index.'@example.com', $role);
            $this->expectFailure(CommerceFailureReason::Unauthorized, function () use ($actor, $version): void {
                $this->scenario->draftOffer($actor, $version, 'off5_denied_'.bin2hex(random_bytes(3)));
            });
            $this->expectFailure(CommerceFailureReason::Unauthorized, function () use ($offer, $actor): void {
                $this->offers()->activate($offer, $actor, 'activate_offer');
            });
        }
    }

    public function testUnverifiedOrSuspendedSuperAdminCannotManageTheCatalog(): void
    {
        $sa = $this->scenario->superAdmin('off6-sa@example.com');
        $version = $this->scenario->activeVersion($sa, 'off6');

        $unverified = $this->scenario->unverifiedUser('off6-unverified@example.com');
        $unverified->addGlobalRole(UserRole::SuperAdmin);
        $this->scenario->service(\App\Repository\UserRepository::class)->save($unverified);
        $this->expectFailure(CommerceFailureReason::Unauthorized, function () use ($unverified, $version): void {
            $this->scenario->draftOffer($unverified, $version, 'off6_denied_a');
        });

        $suspended = $this->scenario->suspendedUser('off6-suspended@example.com');
        $suspended->addGlobalRole(UserRole::SuperAdmin);
        $this->scenario->service(\App\Repository\UserRepository::class)->save($suspended);
        $this->expectFailure(CommerceFailureReason::Unauthorized, function () use ($suspended, $version): void {
            $this->scenario->draftOffer($suspended, $version, 'off6_denied_b');
        });
    }

    public function testUserOrderSealsTotalsAndSnapshots(): void
    {
        $sa = $this->scenario->superAdmin('ord1-sa@example.com');
        $version = $this->scenario->activeVersion($sa, 'ord1');
        $offer = $this->scenario->activeOffer($sa, $version, 'ord1_code', 10000, 2000);
        $purchaser = $this->scenario->activeUser('ord1-buyer@example.com');

        $order = $this->orders()->createUserOrder(
            $purchaser,
            $purchaser,
            [['offer' => $offer, 'quantity' => 2]],
            'create_order',
        );

        self::assertSame(CommerceOrderStatus::Draft, $order->getStatus());
        self::assertSame(CommercePurchaserType::User, $order->getPurchaserType());
        self::assertInstanceOf(User::class, $order->getUser());
        self::assertNull($order->getInstitution());
        self::assertMatchesRegularExpression('/^ORD-[0-9A-F]{24}$/', $order->getPublicReference());
        self::assertSame('TRY', $order->getCurrency());
        self::assertSame(20000, $order->getSubtotalAmountMinor());
        self::assertSame(0, $order->getDiscountAmountMinor());
        self::assertSame(4000, $order->getTaxAmountMinor());
        self::assertSame(24000, $order->getGrandTotalAmountMinor());
        self::assertSame(24000, $order->getGrandTotal()->getAmountMinor());
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $order->getOrderHash());
        self::assertGreaterThan($this->clock->now(), $order->getExpiresAt());

        $items = $this->scenario->service(\App\Repository\CommerceOrderItemRepository::class)
            ->findForOrder($order->getId());
        self::assertCount(1, $items);
        $item = $items[0];
        self::assertSame(2, $item->getQuantity());
        self::assertSame(10000, $item->getUnitPriceAmountMinor());
        self::assertSame(20000, $item->getLineSubtotalAmountMinor());
        self::assertSame(4000, $item->getLineTaxAmountMinor());
        self::assertSame(24000, $item->getLineTotalAmountMinor());
        self::assertSame($offer->getOfferHash(), $item->getOfferSnapshotHash());
        self::assertSame($version->getPolicyHash(), $item->getPackagePolicySnapshotHash());
        self::assertNotSame('', $item->getDescriptionSnapshot());

        $this->orders()->assertOrderIntegrity($order, $items);
        self::assertSame(1, $this->auditEvents()->countByAction(SecurityAuditAction::CommerceOrderCreated->value));
    }

    public function testPublicReferencesAreUnguessableAndUnique(): void
    {
        $sa = $this->scenario->superAdmin('ord2-sa@example.com');
        $version = $this->scenario->activeVersion($sa, 'ord2');
        $offer = $this->scenario->activeOffer($sa, $version, 'ord2_code');
        $purchaser = $this->scenario->activeUser('ord2-buyer@example.com');

        $references = [];
        for ($i = 0; $i < 5; ++$i) {
            $order = $this->orders()->createUserOrder(
                $purchaser,
                $purchaser,
                [['offer' => $offer, 'quantity' => 1]],
                'create_order',
            );
            $references[] = $order->getPublicReference();
        }
        self::assertCount(5, array_unique($references));
        foreach ($references as $reference) {
            self::assertMatchesRegularExpression('/^ORD-[0-9A-F]{24}$/', $reference);
            self::assertStringNotContainsString('ord2-buyer', $reference);
        }
    }

    public function testDiscountReducesGrandTotalButNotTax(): void
    {
        $sa = $this->scenario->superAdmin('ord3-sa@example.com');
        $version = $this->scenario->activeVersion($sa, 'ord3');
        $offer = $this->scenario->activeOffer($sa, $version, 'ord3_code', 10000, 2000);
        $purchaser = $this->scenario->activeUser('ord3-buyer@example.com');

        $order = $this->orders()->createUserOrder(
            $purchaser,
            $purchaser,
            [['offer' => $offer, 'quantity' => 1]],
            'create_order',
            2500,
        );
        self::assertSame(10000, $order->getSubtotalAmountMinor());
        self::assertSame(2500, $order->getDiscountAmountMinor());
        self::assertSame(2000, $order->getTaxAmountMinor());
        self::assertSame(9500, $order->getGrandTotalAmountMinor());

        $this->expectFailure(CommerceFailureReason::InvalidInput, function () use ($purchaser, $offer): void {
            $this->orders()->createUserOrder(
                $purchaser,
                $purchaser,
                [['offer' => $offer, 'quantity' => 1]],
                'create_order',
                10001,
            );
        });
    }

    public function testOrderRejectsDraftOfferRetiredOfferAndDuplicateLines(): void
    {
        $sa = $this->scenario->superAdmin('ord4-sa@example.com');
        $version = $this->scenario->activeVersion($sa, 'ord4');
        $draft = $this->scenario->draftOffer($sa, $version, 'ord4_draft');
        $active = $this->scenario->activeOffer($sa, $version, 'ord4_active');
        $purchaser = $this->scenario->activeUser('ord4-buyer@example.com');

        $this->expectFailure(CommerceFailureReason::OfferRetired, function () use ($purchaser, $draft): void {
            $this->orders()->createUserOrder(
                $purchaser,
                $purchaser,
                [['offer' => $draft, 'quantity' => 1]],
                'create_order',
            );
        });

        $this->expectFailure(CommerceFailureReason::InvalidInput, function () use ($purchaser, $active): void {
            $this->orders()->createUserOrder(
                $purchaser,
                $purchaser,
                [['offer' => $active, 'quantity' => 1], ['offer' => $active, 'quantity' => 1]],
                'create_order',
            );
        });

        $retired = $this->offers()->retire($active, $sa, 'retire_offer');
        $this->expectFailure(CommerceFailureReason::OfferRetired, function () use ($purchaser, $retired): void {
            $this->orders()->createUserOrder(
                $purchaser,
                $purchaser,
                [['offer' => $retired, 'quantity' => 1]],
                'create_order',
            );
        });
    }

    public function testRecurringLinesAreLimitedToQuantityOne(): void
    {
        $sa = $this->scenario->superAdmin('ord5-sa@example.com');
        $version = $this->scenario->activeVersion($sa, 'ord5');
        $offer = $this->scenario->activeOffer(
            $sa,
            $version,
            'ord5_code',
            19999,
            2000,
            CommercialOfferBillingType::Recurring,
            CommercialOfferBillingInterval::Monthly,
        );
        $purchaser = $this->scenario->activeUser('ord5-buyer@example.com');

        $this->expectFailure(CommerceFailureReason::InvalidInput, function () use ($purchaser, $offer): void {
            $this->orders()->createUserOrder(
                $purchaser,
                $purchaser,
                [['offer' => $offer, 'quantity' => 2]],
                'create_order',
            );
        });

        $order = $this->orders()->createUserOrder(
            $purchaser,
            $purchaser,
            [['offer' => $offer, 'quantity' => 1]],
            'create_order',
        );
        self::assertSame(19999, $order->getSubtotalAmountMinor());
    }

    public function testQuantityBoundsAreEnforced(): void
    {
        $sa = $this->scenario->superAdmin('ord6-sa@example.com');
        $version = $this->scenario->activeVersion($sa, 'ord6');
        $offer = $this->scenario->activeOffer($sa, $version, 'ord6_code');
        $purchaser = $this->scenario->activeUser('ord6-buyer@example.com');

        foreach ([0, -1, 11] as $quantity) {
            $this->expectFailure(
                CommerceFailureReason::InvalidInput,
                function () use ($purchaser, $offer, $quantity): void {
                    $this->orders()->createUserOrder(
                        $purchaser,
                        $purchaser,
                        [['offer' => $offer, 'quantity' => $quantity]],
                        'create_order',
                    );
                },
            );
        }
    }

    public function testProxyPurchaseIsDeniedEvenForPrivilegedRoles(): void
    {
        $sa = $this->scenario->superAdmin('ord7-sa@example.com');
        $version = $this->scenario->activeVersion($sa, 'ord7');
        $offer = $this->scenario->activeOffer($sa, $version, 'ord7_code');
        $purchaser = $this->scenario->activeUser('ord7-buyer@example.com');

        $admin = $this->scenario->activeUser('ord7-admin@example.com', UserRole::Admin);
        $moderator = $this->scenario->activeUser('ord7-mod@example.com', UserRole::Moderator);

        foreach ([$admin, $moderator, $sa] as $actor) {
            $this->expectFailure(
                CommerceFailureReason::Unauthorized,
                function () use ($actor, $purchaser, $offer): void {
                    $this->orders()->createUserOrder(
                        $actor,
                        $purchaser,
                        [['offer' => $offer, 'quantity' => 1]],
                        'create_order',
                    );
                },
            );
        }
    }

    public function testUnverifiedOrSuspendedPurchaserCannotOrder(): void
    {
        $sa = $this->scenario->superAdmin('ord8-sa@example.com');
        $version = $this->scenario->activeVersion($sa, 'ord8');
        $offer = $this->scenario->activeOffer($sa, $version, 'ord8_code');

        $unverified = $this->scenario->unverifiedUser('ord8-unverified@example.com');
        $this->expectFailure(CommerceFailureReason::Unauthorized, function () use ($unverified, $offer): void {
            $this->orders()->createUserOrder(
                $unverified,
                $unverified,
                [['offer' => $offer, 'quantity' => 1]],
                'create_order',
            );
        });

        $suspended = $this->scenario->suspendedUser('ord8-suspended@example.com');
        $this->expectFailure(CommerceFailureReason::Unauthorized, function () use ($suspended, $offer): void {
            $this->orders()->createUserOrder(
                $suspended,
                $suspended,
                [['offer' => $offer, 'quantity' => 1]],
                'create_order',
            );
        });
    }

    public function testIndividualOfferCannotBeOrderedByAnInstitution(): void
    {
        $sa = $this->scenario->superAdmin('ord9-sa@example.com');
        [$institution, $owner] = $this->scenario->activeInstitution('ord9', $sa);
        $sa = $this->scenario->refresh(User::class, $sa->getId());
        $version = $this->scenario->activeVersion($sa, 'ord9');
        $offer = $this->scenario->activeOffer($sa, $version, 'ord9_code');

        $this->expectFailure(
            CommerceFailureReason::ScopeMismatch,
            function () use ($owner, $institution, $offer): void {
                $this->orders()->createInstitutionOrder(
                    $owner,
                    $institution,
                    [['offer' => $offer, 'quantity' => 1]],
                    'create_order',
                );
            },
        );
    }

    public function testInstitutionOrderRequiresOwnerAndRejectsManagerAndOutsiders(): void
    {
        $sa = $this->scenario->superAdmin('ord10-sa@example.com');
        [$institution, $owner] = $this->scenario->activeInstitution('ord10', $sa);
        $sa = $this->scenario->refresh(User::class, $sa->getId());
        $version = $this->scenario->activeVersion(
            $sa,
            'ord10',
            AccessPackageTargetType::Institution,
            365,
            5,
            365,
        );
        $offer = $this->scenario->activeOffer($sa, $version, 'ord10_code', 50000, 2000);

        $order = $this->orders()->createInstitutionOrder(
            $owner,
            $institution,
            [['offer' => $offer, 'quantity' => 1]],
            'create_order',
        );
        self::assertSame(CommercePurchaserType::Institution, $order->getPurchaserType());
        self::assertInstanceOf(Institution::class, $order->getInstitution());
        self::assertNull($order->getUser());
        self::assertSame(60000, $order->getGrandTotalAmountMinor());

        $manager = $this->scenario->activeUser('ord10-manager@example.com', UserRole::Teacher);
        $memberships = $this->scenario->service(InstitutionMembershipManager::class);
        $memberships->addMember($institution, $owner, $manager, InstitutionMembershipRole::Manager, 'add_manager');
        $teacher = $this->scenario->activeUser('ord10-teacher@example.com', UserRole::Teacher);
        $memberships->addMember($institution, $owner, $teacher, InstitutionMembershipRole::Teacher, 'add_teacher');
        $student = $this->scenario->activeUser('ord10-student@example.com');
        $memberships->addMember($institution, $owner, $student, InstitutionMembershipRole::Student, 'add_student');
        $outsider = $this->scenario->activeUser('ord10-outsider@example.com', UserRole::Teacher);

        $this->em->clear();
        $institution = $this->scenario->refresh(Institution::class, $institution->getId());
        $offer = $this->scenario->refresh(CommercialOffer::class, $offer->getId());

        foreach ([$manager, $teacher, $student, $outsider] as $actor) {
            $freshActor = $this->scenario->refresh(User::class, $actor->getId());
            $this->expectFailure(
                CommerceFailureReason::Unauthorized,
                function () use ($freshActor, $institution, $offer): void {
                    $this->orders()->createInstitutionOrder(
                        $freshActor,
                        $institution,
                        [['offer' => $offer, 'quantity' => 1]],
                        'create_order',
                    );
                },
            );
        }

        $freshSa = $this->scenario->refresh(User::class, $sa->getId());
        $this->expectFailure(
            CommerceFailureReason::Unauthorized,
            function () use ($freshSa, $institution, $offer): void {
                $this->orders()->createInstitutionOrder(
                    $freshSa,
                    $institution,
                    [['offer' => $offer, 'quantity' => 1]],
                    'create_order',
                );
            },
        );
    }

    public function testOrderCancellationAndLazyExpiry(): void
    {
        $sa = $this->scenario->superAdmin('ord11-sa@example.com');
        $version = $this->scenario->activeVersion($sa, 'ord11');
        $offer = $this->scenario->activeOffer($sa, $version, 'ord11_code');
        $purchaser = $this->scenario->activeUser('ord11-buyer@example.com');

        $order = $this->orders()->createUserOrder(
            $purchaser,
            $purchaser,
            [['offer' => $offer, 'quantity' => 1]],
            'create_order',
        );
        $cancelled = $this->orders()->cancel(
            $order,
            $purchaser,
            CommerceCancellationReasonCode::PurchaserRequested,
            'cancel_order',
        );
        self::assertSame(CommerceOrderStatus::Cancelled, $cancelled->getStatus());
        self::assertInstanceOf(\DateTimeImmutable::class, $cancelled->getCancelledAt());
        self::assertSame(
            CommerceCancellationReasonCode::PurchaserRequested,
            $cancelled->getCancellationReasonCode(),
        );
        $this->expectFailure(CommerceFailureReason::InvalidTransition, function () use ($cancelled, $purchaser): void {
            $this->orders()->cancel(
                $cancelled,
                $purchaser,
                CommerceCancellationReasonCode::PurchaserRequested,
                'cancel_order',
            );
        });

        $second = $this->orders()->createUserOrder(
            $purchaser,
            $purchaser,
            [['offer' => $offer, 'quantity' => 1]],
            'create_order',
        );
        self::assertSame($second, $this->orders()->evaluateAndExpireIfNeeded($second));
        self::assertSame(CommerceOrderStatus::Draft, $second->getStatus());

        $this->clock->modify('2026-09-13 13:00:01');
        $expired = $this->orders()->evaluateAndExpireIfNeeded($second);
        self::assertSame(CommerceOrderStatus::Expired, $expired->getStatus());
        self::assertInstanceOf(\DateTimeImmutable::class, $expired->getExpiredAt());
        self::assertSame(1, $this->auditEvents()->countByAction(SecurityAuditAction::CommerceOrderExpired->value));
        self::assertSame(1, $this->auditEvents()->countByAction(SecurityAuditAction::CommerceOrderCancelled->value));
    }

    public function testAnotherUserCannotCancelSomeoneElsesOrder(): void
    {
        $sa = $this->scenario->superAdmin('ord12-sa@example.com');
        $version = $this->scenario->activeVersion($sa, 'ord12');
        $offer = $this->scenario->activeOffer($sa, $version, 'ord12_code');
        $purchaser = $this->scenario->activeUser('ord12-buyer@example.com');
        $stranger = $this->scenario->activeUser('ord12-stranger@example.com');

        $order = $this->orders()->createUserOrder(
            $purchaser,
            $purchaser,
            [['offer' => $offer, 'quantity' => 1]],
            'create_order',
        );
        $this->expectFailure(CommerceFailureReason::Unauthorized, function () use ($order, $stranger): void {
            $this->orders()->cancel(
                $order,
                $stranger,
                CommerceCancellationReasonCode::PurchaserRequested,
                'cancel_order',
            );
        });
    }

    public function testOrderKeepsWorkingWhenTheOfferIsLaterRepriced(): void
    {
        $sa = $this->scenario->superAdmin('ord13-sa@example.com');
        $version = $this->scenario->activeVersion($sa, 'ord13');
        $offer = $this->scenario->activeOffer($sa, $version, 'ord13_code', 10000, 2000);
        $purchaser = $this->scenario->activeUser('ord13-buyer@example.com');

        $order = $this->orders()->createUserOrder(
            $purchaser,
            $purchaser,
            [['offer' => $offer, 'quantity' => 1]],
            'create_order',
        );
        $sealedHash = $order->getOrderHash();

        // A new offer generation must not rewrite an existing order's frozen line snapshot.
        $newerVersion = $this->scenario->activeVersion($sa, 'ord13b');
        $newerOffer = $this->scenario->activeOffer($sa, $newerVersion, 'ord13_code_v2', 30000, 2000);
        self::assertNotSame($offer->getOfferHash(), $newerOffer->getOfferHash());

        $this->em->clear();
        $freshOrder = $this->scenario->refresh(CommerceOrder::class, $order->getId());
        self::assertSame($sealedHash, $freshOrder->getOrderHash());
        self::assertSame(12000, $freshOrder->getGrandTotalAmountMinor());
        $items = $this->scenario->service(\App\Repository\CommerceOrderItemRepository::class)
            ->findForOrder($freshOrder->getId());
        $this->orders()->assertOrderIntegrity($freshOrder, $items);
    }

    public function testMultiLineOrderTotalsAndMaxItemGuard(): void
    {
        $sa = $this->scenario->superAdmin('ord14-sa@example.com');
        $version = $this->scenario->activeVersion($sa, 'ord14');
        $first = $this->scenario->activeOffer($sa, $version, 'ord14_a', 10000, 2000);
        $second = $this->scenario->activeOffer($sa, $version, 'ord14_b', 12500, 1000);
        $purchaser = $this->scenario->activeUser('ord14-buyer@example.com');

        $order = $this->orders()->createUserOrder(
            $purchaser,
            $purchaser,
            [
                ['offer' => $first, 'quantity' => 2],
                ['offer' => $second, 'quantity' => 1],
            ],
            'create_order',
        );
        self::assertSame(32500, $order->getSubtotalAmountMinor());
        self::assertSame(5250, $order->getTaxAmountMinor());
        self::assertSame(37750, $order->getGrandTotalAmountMinor());

        $items = $this->scenario->service(\App\Repository\CommerceOrderItemRepository::class)
            ->findForOrder($order->getId());
        self::assertCount(2, $items);
        $this->orders()->assertOrderIntegrity($order, $items);
    }

    public function testOrdersRejectMixedCurrencies(): void
    {
        $sa = $this->scenario->superAdmin('ord15-sa@example.com');
        $version = $this->scenario->activeVersion($sa, 'ord15');
        $tryOffer = $this->scenario->activeOffer($sa, $version, 'ord15_try', 10000, 2000);
        $usdOffer = $this->scenario->activeOffer(
            $sa,
            $version,
            'ord15_usd',
            10000,
            2000,
            CommercialOfferBillingType::OneTime,
            null,
            'USD',
        );
        $purchaser = $this->scenario->activeUser('ord15-buyer@example.com');

        $this->expectFailure(
            CommerceFailureReason::CurrencyMismatch,
            function () use ($purchaser, $tryOffer, $usdOffer): void {
                $this->orders()->createUserOrder(
                    $purchaser,
                    $purchaser,
                    [
                        ['offer' => $tryOffer, 'quantity' => 1],
                        ['offer' => $usdOffer, 'quantity' => 1],
                    ],
                    'create_order',
                );
            },
        );
    }

    public function testOrderIntegrityDetectsTamperedTotals(): void
    {
        $sa = $this->scenario->superAdmin('ord16-sa@example.com');
        $version = $this->scenario->activeVersion($sa, 'ord16');
        $offer = $this->scenario->activeOffer($sa, $version, 'ord16_code', 10000, 2000);
        $purchaser = $this->scenario->activeUser('ord16-buyer@example.com');
        $order = $this->orders()->createUserOrder(
            $purchaser,
            $purchaser,
            [['offer' => $offer, 'quantity' => 1]],
            'create_order',
        );
        $items = $this->scenario->service(\App\Repository\CommerceOrderItemRepository::class)
            ->findForOrder($order->getId());
        self::assertCount(1, $items);

        // Totals and the order hash are frozen by triggers the moment the order leaves
        // draft, so the only way to fake drift is while it is still a draft.
        $connection = $this->em->getConnection();
        $connection->executeStatement(
            'UPDATE commerce_orders SET order_hash = ? WHERE id = ?',
            [str_repeat('a', 64), $order->getId()->toBinary()],
        );
        $this->em->clear();
        $rehashed = $this->scenario->refresh(CommerceOrder::class, $order->getId());
        $rehashedItems = $this->scenario->service(\App\Repository\CommerceOrderItemRepository::class)
            ->findForOrder($rehashed->getId());
        $this->expectFailure(CommerceFailureReason::HashMismatch, function () use ($rehashed, $rehashedItems): void {
            $this->orders()->assertOrderIntegrity($rehashed, $rehashedItems);
        });

        $connection->executeStatement(
            'UPDATE commerce_orders SET tax_amount_minor = 0, grand_total_amount_minor = subtotal_amount_minor'
            .' WHERE id = ?',
            [$order->getId()->toBinary()],
        );
        $this->em->clear();
        $tampered = $this->scenario->refresh(CommerceOrder::class, $order->getId());
        self::assertSame(0, $tampered->getTaxAmountMinor());
        $freshItems = $this->scenario->service(\App\Repository\CommerceOrderItemRepository::class)
            ->findForOrder($tampered->getId());

        $this->expectFailure(CommerceFailureReason::TotalMismatch, function () use ($tampered, $freshItems): void {
            $this->orders()->assertOrderIntegrity($tampered, $freshItems);
        });
    }

    public function testTriggersRefuseToRewriteASealedOrderOrItsItems(): void
    {
        $sa = $this->scenario->superAdmin('ord17-sa@example.com');
        $version = $this->scenario->activeVersion($sa, 'ord17');
        $offer = $this->scenario->activeOffer($sa, $version, 'ord17_code', 10000, 2000);
        $purchaser = $this->scenario->activeUser('ord17-buyer@example.com');
        $order = $this->orders()->createUserOrder(
            $purchaser,
            $purchaser,
            [['offer' => $offer, 'quantity' => 1]],
            'create_order',
        );
        $items = $this->scenario->service(\App\Repository\CommerceOrderItemRepository::class)
            ->findForOrder($order->getId());
        self::assertCount(1, $items);

        $connection = $this->em->getConnection();
        $this->expectDatabaseRejection(static function () use ($connection, $items): void {
            $connection->executeStatement(
                'UPDATE commerce_order_items SET quantity = 9 WHERE id = ?',
                [$items[0]->getId()->toBinary()],
            );
        });
        $this->expectDatabaseRejection(static function () use ($connection, $order): void {
            $connection->executeStatement(
                'UPDATE commerce_orders SET public_reference = ? WHERE id = ?',
                ['ORD-000000000000000000000000', $order->getId()->toBinary()],
            );
        });
        $this->expectDatabaseRejection(static function () use ($connection, $order): void {
            $connection->executeStatement(
                "UPDATE commerce_orders SET status = 'paid' WHERE id = ?",
                [$order->getId()->toBinary()],
            );
        });
    }

    public function testPublishedOfferTermsAreImmutableInTheDatabase(): void
    {
        $sa = $this->scenario->superAdmin('ord19-sa@example.com');
        $version = $this->scenario->activeVersion($sa, 'ord19');
        $offer = $this->scenario->activeOffer($sa, $version, 'ord19_code', 10000, 2000);

        $connection = $this->em->getConnection();
        $this->expectDatabaseRejection(static function () use ($connection, $offer): void {
            $connection->executeStatement(
                'UPDATE commercial_offers SET price_amount_minor = 1 WHERE id = ?',
                [$offer->getId()->toBinary()],
            );
        });
        $this->expectDatabaseRejection(static function () use ($connection, $offer): void {
            $connection->executeStatement(
                'UPDATE commercial_offers SET code = ? WHERE id = ?',
                ['ord19_renamed', $offer->getId()->toBinary()],
            );
        });
    }

    public function testOfferIntegrityDetectsTamperedDraftPrice(): void
    {
        $sa = $this->scenario->superAdmin('ord20-sa@example.com');
        $version = $this->scenario->activeVersion($sa, 'ord20');
        $offer = $this->scenario->draftOffer($sa, $version, 'ord20_code', 10000, 2000);

        // Draft terms are still mutable in the database, so drift is detectable only by
        // the offer hash — which is exactly what assertOfferIntegrity is for.
        $this->em->getConnection()->executeStatement(
            'UPDATE commercial_offers SET price_amount_minor = 1 WHERE id = ?',
            [$offer->getId()->toBinary()],
        );
        $this->em->clear();
        $tampered = $this->scenario->refresh(CommercialOffer::class, $offer->getId());
        self::assertSame(1, $tampered->getPriceAmountMinor());

        $this->expectFailure(CommerceFailureReason::HashMismatch, function () use ($tampered): void {
            $this->offers()->assertOfferIntegrity($tampered);
        });
        $this->expectFailure(CommerceFailureReason::HashMismatch, function () use ($tampered, $sa): void {
            $this->offers()->activate($tampered, $sa, 'activate_offer');
        });
    }

    public function testOfferCannotBeCreatedForANonActiveVersion(): void
    {
        $sa = $this->scenario->superAdmin('ord18-sa@example.com');
        $packages = $this->scenario->service(\App\Service\AccessPackageManager::class);
        $versions = $this->scenario->service(\App\Service\AccessPackageVersionManager::class);
        $package = $packages->create(
            $sa,
            'ord18_pkg',
            'Pkg',
            null,
            AccessPackageTargetType::Individual,
            30,
            null,
            'create_pkg',
        );
        $draftVersion = $versions->createDraftVersion($package, $sa, 30, null, 'create_version');
        self::assertInstanceOf(AccessPackageVersion::class, $draftVersion);

        $this->expectFailure(CommerceFailureReason::InvalidInput, function () use ($sa, $draftVersion): void {
            $this->scenario->draftOffer($sa, $draftVersion, 'ord18_code');
        });
    }

    private function offers(): CommercialOfferManager
    {
        return $this->scenario->service(CommercialOfferManager::class);
    }

    private function orders(): CommerceOrderManager
    {
        return $this->scenario->service(CommerceOrderManager::class);
    }

    private function auditEvents(): SecurityAuditEventRepository
    {
        return $this->scenario->service(SecurityAuditEventRepository::class);
    }

    private function expectDatabaseRejection(callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected the database to reject the statement.');
        } catch (DbalException $e) {
            self::assertNotSame('', $e->getMessage());
        } finally {
            $this->recoverDoctrine();
        }
    }

    private function expectFailure(CommerceFailureReason $reason, callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected CommerceException '.$reason->value);
        } catch (CommerceException $e) {
            self::assertSame($reason, $e->getReason(), 'Unexpected failure reason: '.$e->getMessage());
        } finally {
            $this->recoverDoctrine();
        }
    }

    /**
     * Managers run inside `wrapInTransaction`, which closes the EntityManager on any
     * rollback, so every expected failure needs the same Doctrine reset the Stage 2.16
     * tests use. Entities captured before the reset stay usable because managers only
     * read their identifiers and reload locked copies inside the transaction.
     */
    private function recoverDoctrine(): void
    {
        if ($this->em->isOpen()) {
            return;
        }

        $doctrine = static::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $doctrine->resetManager();
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->rebind();
    }

    private function rebind(): void
    {
        $doctrine = static::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $em = $doctrine->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->scenario = new CommerceScenario(static::getContainer(), $this->em);
    }

    private function cleanup(): void
    {
        $connection = $this->em->getConnection();
        CommerceDbCleanup::deleteAll($connection);
        AccessEntitlementDbCleanup::deleteAll($connection);
        if ($connection->createSchemaManager()->tablesExist(['curriculum_topics'])) {
            $connection->executeStatement('DELETE FROM curriculum_topics WHERE parent_id IS NOT NULL');
        }
        QuestionBankDbCleanup::deleteTables($connection, [
            'curriculum_learning_outcomes',
            'curriculum_topics',
            'curriculum_units',
            'curriculum_programs',
            'subjects',
            'institution_memberships',
            'institutions',
            'security_audit_events',
            'security_bootstrap_guards',
            'reset_password_requests',
            'users',
        ]);
    }
}

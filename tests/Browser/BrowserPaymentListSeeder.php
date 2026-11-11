<?php

declare(strict_types=1);

namespace App\Tests\Browser;

use App\Entity\PaymentAttempt;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\AccessPackageCatalogResourceKind;
use App\Enum\AccessPackageTargetType;
use App\Enum\CommercialOfferBillingType;
use App\Enum\GradeLevel;
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

/**
 * Synthetic payment attempts for browser acceptance of /yonetim/odemeler and detail.
 *
 * Reuses an existing subject so curriculum screens are not polluted with fixture subjects.
 */
final class BrowserPaymentListSeeder
{
    public const LONG_FAILURE_CODE = 'provider_timeout_insufficient_funds_retry_later';

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
     *     longReference: string,
     *     failedReference: string,
     *     detailInitiated: string,
     *     detailAuthorized: string,
     *     detailCaptured: string,
     *     detailFailed: string,
     *     detailLongFailure: string,
     *     detailLiveAuthorized: string,
     *     longFailureCode: string
     * }
     */
    public function seed(User $superAdmin, Subject $subject): array
    {
        unset($superAdmin);

        $initiated = $this->start('br_pay_init', PaymentProviderEnvironment::Sandbox, $subject);
        $failed = $this->start('br_pay_fail', PaymentProviderEnvironment::Sandbox, $subject);
        $failed->markFailed('card_declined', new \DateTimeImmutable('2026-09-13 12:01:00', new \DateTimeZone('UTC')));
        $authorized = $this->start('br_pay_auth', PaymentProviderEnvironment::Sandbox, $subject);
        $authorized->markAuthorized('br_pay_auth_ref', 'br_pay_auth_auth', new \DateTimeImmutable('2026-09-13 12:02:00', new \DateTimeZone('UTC')));
        $captured = $this->start('br_pay_cap', PaymentProviderEnvironment::Sandbox, $subject);
        $captured->markAuthorized('br_pay_cap_ref', 'br_pay_cap_auth', new \DateTimeImmutable('2026-09-13 12:03:00', new \DateTimeZone('UTC')));
        $captured->markCaptured('br_pay_cap_ref', new \DateTimeImmutable('2026-09-13 12:04:00', new \DateTimeZone('UTC')));
        $live = $this->start('br_pay_live', PaymentProviderEnvironment::Production, $subject);
        $live->markAuthorized('br_pay_live_ref', 'br_pay_live_auth', new \DateTimeImmutable('2026-09-13 12:05:00', new \DateTimeZone('UTC')));
        $this->start('br_pay_extra', PaymentProviderEnvironment::Sandbox, $subject);
        // Domain publicReference is fixed-length ORD- + 24 hex (CommerceOrder::PUBLIC_REFERENCE_RANDOM_BYTES).
        $longRefAttempt = $this->start('br_pay_long', PaymentProviderEnvironment::Sandbox, $subject);
        $longFailed = $this->start('br_pay_longfail', PaymentProviderEnvironment::Sandbox, $subject);
        $longFailed->markFailed(self::LONG_FAILURE_CODE, new \DateTimeImmutable('2026-09-13 12:06:00', new \DateTimeZone('UTC')));

        $this->entityManager->flush();

        $longReference = $longRefAttempt->getOrder()->getPublicReference();

        return [
            'list' => '/yonetim/odemeler',
            'longReference' => $longReference,
            'failedReference' => $failed->getOrder()->getPublicReference(),
            'detailInitiated' => '/yonetim/odemeler/'.$initiated->getId()->toRfc4122(),
            'detailAuthorized' => '/yonetim/odemeler/'.$authorized->getId()->toRfc4122(),
            'detailCaptured' => '/yonetim/odemeler/'.$captured->getId()->toRfc4122(),
            'detailFailed' => '/yonetim/odemeler/'.$failed->getId()->toRfc4122(),
            'detailLongFailure' => '/yonetim/odemeler/'.$longFailed->getId()->toRfc4122(),
            'detailLiveAuthorized' => '/yonetim/odemeler/'.$live->getId()->toRfc4122(),
            'longFailureCode' => self::LONG_FAILURE_CODE,
        ];
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

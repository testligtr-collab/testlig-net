<?php

declare(strict_types=1);

namespace App\Tests\Browser;

use App\Entity\PaymentAttempt;
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
use App\Service\SubjectManager;
use App\Service\UserFactory;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Synthetic payment attempts for browser acceptance of /yonetim/odemeler.
 */
final class BrowserPaymentListSeeder
{
    public function __construct(
        private readonly UserFactory $userFactory,
        private readonly UserRepository $users,
        private readonly AccessPackageManager $packages,
        private readonly AccessPackageVersionManager $versions,
        private readonly SubjectManager $subjects,
        private readonly CommercialOfferManager $offers,
        private readonly CommerceOrderManager $orders,
        private readonly PaymentAttemptManager $attempts,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array{list: string, longReference: string, failedReference: string}
     */
    public function seed(User $superAdmin): array
    {
        unset($superAdmin);

        $initiated = $this->start('br_pay_init', PaymentProviderEnvironment::Sandbox);
        $failed = $this->start('br_pay_fail', PaymentProviderEnvironment::Sandbox);
        $failed->markFailed('card_declined', new \DateTimeImmutable('2026-09-13 12:01:00', new \DateTimeZone('UTC')));
        $authorized = $this->start('br_pay_auth', PaymentProviderEnvironment::Sandbox);
        $authorized->markAuthorized('br_pay_auth_ref', 'br_pay_auth_auth', new \DateTimeImmutable('2026-09-13 12:02:00', new \DateTimeZone('UTC')));
        $captured = $this->start('br_pay_cap', PaymentProviderEnvironment::Sandbox);
        $captured->markAuthorized('br_pay_cap_ref', 'br_pay_cap_auth', new \DateTimeImmutable('2026-09-13 12:03:00', new \DateTimeZone('UTC')));
        $captured->markCaptured('br_pay_cap_ref', new \DateTimeImmutable('2026-09-13 12:04:00', new \DateTimeZone('UTC')));
        $live = $this->start('br_pay_live', PaymentProviderEnvironment::Production);
        $live->markAuthorized('br_pay_live_ref', 'br_pay_live_auth', new \DateTimeImmutable('2026-09-13 12:05:00', new \DateTimeZone('UTC')));
        $this->start('br_pay_extra', PaymentProviderEnvironment::Sandbox);

        $this->entityManager->flush();

        return [
            'list' => '/yonetim/odemeler',
            'longReference' => $initiated->getOrder()->getPublicReference(),
            'failedReference' => $failed->getOrder()->getPublicReference(),
        ];
    }

    private function start(string $suffix, PaymentProviderEnvironment $environment): PaymentAttempt
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
            $this->subjects->create($sa, $unique.'_subj', 'S '.$unique, 'create_subject'),
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

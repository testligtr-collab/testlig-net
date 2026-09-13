<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\AccessPackageVersion;
use App\Entity\CommercialOffer;
use App\Entity\Institution;
use App\Entity\User;
use App\Enum\AccessPackageCatalogResourceKind;
use App\Enum\AccessPackageTargetType;
use App\Enum\CommercialOfferBillingInterval;
use App\Enum\CommercialOfferBillingType;
use App\Enum\CommercialOfferTargetType;
use App\Enum\GradeLevel;
use App\Enum\InstitutionType;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Service\AccessPackageManager;
use App\Service\AccessPackageVersionManager;
use App\Service\CommercialOfferManager;
use App\Service\InstitutionCreator;
use App\Service\InstitutionStatusManager;
use App\Service\SubjectManager;
use App\Service\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Assert;
use Psr\Container\ContainerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Shared commerce fixture builder for Stage 2.17 integration tests.
 *
 * Package versions get a single catalog grant because that is the cheapest fixture that
 * satisfies the "an active version needs at least one grant" rule from Stage 2.16;
 * commerce itself only cares about the version's policy hash and validity days.
 */
final class CommerceScenario
{
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function superAdmin(string $email): User
    {
        return $this->activeUser($email, UserRole::SuperAdmin);
    }

    /**
     * ADMIN and SUPER_ADMIN cannot be assigned at creation, so they are granted right
     * after the user exists — exactly how the Stage 2.16 fixtures do it.
     */
    public function activeUser(string $email, UserRole $role = UserRole::Student): User
    {
        $initialRole = $role->isPrivilegedBootstrapRole() ? UserRole::Teacher : $role;
        $user = $this->userFactory()->createAndPersist($email, 'Guclu-Parola-123!', 'A', 'U', $initialRole);
        $user->markEmailVerified(new \DateTimeImmutable('2026-09-01 00:00:00'));
        $user->transitionTo(UserStatus::Active);
        if ($initialRole !== $role) {
            $user->addGlobalRole($role);
        }
        $this->users()->save($user);

        return $user;
    }

    public function unverifiedUser(string $email): User
    {
        $user = $this->userFactory()->createAndPersist($email, 'Guclu-Parola-123!', 'A', 'U', UserRole::Student);
        $this->users()->save($user);

        return $user;
    }

    public function suspendedUser(string $email): User
    {
        $user = $this->activeUser($email);
        $user->transitionTo(UserStatus::Suspended);
        $this->users()->save($user);

        return $user;
    }

    /**
     * @return array{0: Institution, 1: User} the active institution and its owner
     */
    public function activeInstitution(string $suffix, User $superAdmin): array
    {
        $owner = $this->activeUser($suffix.'-owner@example.com', UserRole::Teacher);
        $institution = $this->service(InstitutionCreator::class)->create(
            $superAdmin,
            $owner,
            'School '.$suffix,
            InstitutionType::School,
            'create_i',
        );
        $this->service(InstitutionStatusManager::class)->activate($institution, $superAdmin, 'activate_inst');

        return [
            $this->refresh(Institution::class, $institution->getId()),
            $this->refresh(User::class, $owner->getId()),
        ];
    }

    public function activeVersion(
        User $superAdmin,
        string $suffix,
        AccessPackageTargetType $targetType = AccessPackageTargetType::Individual,
        ?int $validityDays = 30,
        ?int $seatLimit = null,
        ?int $packageDefaultValidityDays = 30,
    ): AccessPackageVersion {
        $package = $this->service(AccessPackageManager::class)->create(
            $superAdmin,
            $suffix.'_pkg',
            'Pkg '.$suffix,
            null,
            $targetType,
            $packageDefaultValidityDays,
            $seatLimit,
            'create_pkg',
        );
        $versions = $this->service(AccessPackageVersionManager::class);
        $version = $versions->createDraftVersion($package, $superAdmin, $validityDays, $seatLimit, 'create_version');

        // An active version needs at least one grant; a catalog grant is the cheapest
        // fixture because it only needs a subject and a grade level.
        $versions->addCatalogGrant(
            $version,
            AccessPackageCatalogResourceKind::LearningContent,
            $this->service(SubjectManager::class)->create($superAdmin, $suffix.'_subj', 'S '.$suffix, 'create_subject'),
            GradeLevel::Grade9,
            $superAdmin,
            'add_catalog_grant',
        );

        return $versions->activate($version, $superAdmin, 'activate_version');
    }

    public function activeOffer(
        User $superAdmin,
        AccessPackageVersion $version,
        string $code,
        int $priceAmountMinor = 19999,
        int $taxRateBasisPoints = 2000,
        CommercialOfferBillingType $billingType = CommercialOfferBillingType::OneTime,
        ?CommercialOfferBillingInterval $billingInterval = null,
        string $currency = 'TRY',
    ): CommercialOffer {
        $offer = $this->draftOffer(
            $superAdmin,
            $version,
            $code,
            $priceAmountMinor,
            $taxRateBasisPoints,
            $billingType,
            $billingInterval,
            $currency,
        );

        return $this->service(CommercialOfferManager::class)->activate($offer, $superAdmin, 'activate_offer');
    }

    public function draftOffer(
        User $superAdmin,
        AccessPackageVersion $version,
        string $code,
        int $priceAmountMinor = 19999,
        int $taxRateBasisPoints = 2000,
        CommercialOfferBillingType $billingType = CommercialOfferBillingType::OneTime,
        ?CommercialOfferBillingInterval $billingInterval = null,
        string $currency = 'TRY',
    ): CommercialOffer {
        return $this->service(CommercialOfferManager::class)->createDraft(
            $superAdmin,
            $version,
            $code,
            'Offer '.$code,
            null,
            $billingType,
            $billingInterval,
            $priceAmountMinor,
            $currency,
            $taxRateBasisPoints,
            'create_offer',
        );
    }

    public function targetTypeFor(CommercialOfferTargetType $targetType): AccessPackageTargetType
    {
        return CommercialOfferTargetType::Institution === $targetType
            ? AccessPackageTargetType::Institution
            : AccessPackageTargetType::Individual;
    }

    /**
     * Re-reads an entity after `EntityManager::clear()` so tests never keep stale references.
     *
     * @template T of object
     *
     * @param class-string<T> $className
     *
     * @return T
     */
    public function refresh(string $className, Uuid $id): object
    {
        $fresh = $this->entityManager->find($className, $id);
        Assert::assertInstanceOf($className, $fresh);

        return $fresh;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $serviceId
     *
     * @return T
     */
    public function service(string $serviceId): object
    {
        $service = $this->container->get($serviceId);
        Assert::assertInstanceOf($serviceId, $service);

        return $service;
    }

    private function users(): UserRepository
    {
        return $this->service(UserRepository::class);
    }

    private function userFactory(): UserFactory
    {
        return $this->service(UserFactory::class);
    }
}

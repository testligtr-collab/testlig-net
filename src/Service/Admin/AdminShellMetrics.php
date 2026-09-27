<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\Assessment;
use App\Entity\CatalogSubject;
use App\Entity\Institution;
use App\Entity\InstitutionApplication;
use App\Entity\LearningContent;
use App\Entity\Question;
use App\Entity\User;
use App\Enum\CatalogPublicationStatus;
use App\Enum\InstitutionStatus;
use App\Enum\LearningContentStatus;
use App\Enum\OnboardingApplicationStatus;
use App\Enum\UserStatus;
use App\Security\AdminAuthorization;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Dashboard counts for surfaces the actor may already open.
 *
 * Each figure is a single COUNT. Cards link only to an existing authorized list.
 */
final class AdminShellMetrics
{
    public function __construct(
        private readonly AdminAuthorization $adminAuthorization,
        private readonly EntityManagerInterface $entityManager,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @return list<array{label: string, value: int, href: string|null}>
     */
    public function forActor(User $actor): array
    {
        if (!$this->adminAuthorization->canAccessAdminShell($actor)) {
            return [];
        }

        $cards = [];

        if ($this->adminAuthorization->canViewUsers($actor)) {
            $users = $this->urlGenerator->generate('app_admin_users');
            $cards[] = [
                'label' => 'Aktif kullanıcı',
                'value' => $this->countStatus(User::class, UserStatus::Active),
                'href' => $users,
            ];
            $cards[] = [
                'label' => 'Doğrulama bekleyen',
                'value' => $this->countStatus(User::class, UserStatus::PendingVerification),
                'href' => $users,
            ];
        }

        if ($this->adminAuthorization->canViewInstitutions($actor)) {
            $cards[] = [
                'label' => 'Aktif kurum',
                'value' => $this->countStatus(Institution::class, InstitutionStatus::Active),
                'href' => $this->urlGenerator->generate('app_admin_institutions'),
            ];
            $cards[] = [
                'label' => 'Bekleyen kurum başvurusu',
                'value' => $this->countStatus(InstitutionApplication::class, OnboardingApplicationStatus::Pending),
                'href' => null,
            ];
        }

        if ($this->adminAuthorization->canViewCatalog($actor)) {
            $cards[] = [
                'label' => 'Yayımlanmış katalog dersi',
                'value' => $this->countStatus(CatalogSubject::class, CatalogPublicationStatus::Published),
                'href' => $this->urlGenerator->generate('app_admin_catalog'),
            ];
        }

        if ($this->adminAuthorization->canViewLearningContentWorkspace($actor)) {
            $cards[] = [
                'label' => 'İçerik inceleme kuyruğu',
                'value' => $this->countStatus(LearningContent::class, LearningContentStatus::InReview),
                'href' => $this->urlGenerator->generate('app_admin_learning_contents'),
            ];
        }

        if ($this->adminAuthorization->canViewQuestionBank($actor)) {
            $cards[] = [
                'label' => 'Soru',
                'value' => $this->countStatus(Question::class, null),
                'href' => $this->urlGenerator->generate('app_admin_questions'),
            ];
        }

        if ($this->adminAuthorization->canViewTestBank($actor)) {
            $cards[] = [
                'label' => 'Test',
                'value' => $this->countStatus(Assessment::class, null),
                'href' => $this->urlGenerator->generate('app_admin_tests'),
            ];
        }

        return $cards;
    }

    /**
     * @param class-string $class
     */
    private function countStatus(string $class, ?\BackedEnum $status): int
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from($class, 'e');
        if (null !== $status) {
            $qb->andWhere('e.status = :status')->setParameter('status', $status);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}

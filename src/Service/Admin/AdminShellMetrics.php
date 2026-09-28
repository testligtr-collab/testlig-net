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
use App\Presentation\AdminIconCatalog;
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
        private readonly AdminIconCatalog $icons,
    ) {
    }

    /**
     * @return list<array{label: string, value: int, href: string|null, icon: string}>
     */
    public function forActor(User $actor): array
    {
        if (!$this->adminAuthorization->canAccessAdminShell($actor)) {
            return [];
        }

        $cards = [];

        if ($this->adminAuthorization->canViewUsers($actor)) {
            $users = $this->urlGenerator->generate('app_admin_users');
            $cards[] = $this->card('Aktif kullanıcı', $this->countStatus(User::class, UserStatus::Active), $users, 'users');
            $cards[] = $this->card('Doğrulama bekleyen', $this->countStatus(User::class, UserStatus::PendingVerification), $users, 'users');
        }

        if ($this->adminAuthorization->canViewInstitutions($actor)) {
            $cards[] = $this->card('Aktif kurum', $this->countStatus(Institution::class, InstitutionStatus::Active), $this->urlGenerator->generate('app_admin_institutions'), 'institutions');
            $cards[] = $this->card('Bekleyen kurum başvurusu', $this->countStatus(InstitutionApplication::class, OnboardingApplicationStatus::Pending), null, 'institutions');
        }

        if ($this->adminAuthorization->canViewCatalog($actor)) {
            $cards[] = $this->card('Yayımlanmış katalog dersi', $this->countStatus(CatalogSubject::class, CatalogPublicationStatus::Published), $this->urlGenerator->generate('app_admin_catalog'), 'catalog');
        }

        if ($this->adminAuthorization->canViewLearningContentWorkspace($actor)) {
            $cards[] = $this->card('İçerik inceleme kuyruğu', $this->countStatus(LearningContent::class, LearningContentStatus::InReview), $this->urlGenerator->generate('app_admin_learning_contents'), 'learning_contents');
        }

        if ($this->adminAuthorization->canViewQuestionBank($actor)) {
            $cards[] = $this->card('Soru', $this->countStatus(Question::class, null), $this->urlGenerator->generate('app_admin_questions'), 'questions');
        }

        if ($this->adminAuthorization->canViewTestBank($actor)) {
            $cards[] = $this->card('Test', $this->countStatus(Assessment::class, null), $this->urlGenerator->generate('app_admin_tests'), 'tests');
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

    /**
     * @return array{label: string, value: int, href: string|null, icon: string}
     */
    private function card(string $label, int $value, ?string $href, string $icon): array
    {
        return [
            'label' => $label,
            'value' => $value,
            'href' => $href,
            'icon' => $this->icons->resolve($icon),
        ];
    }
}

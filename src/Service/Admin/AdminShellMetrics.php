<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Dto\ContentWorkspaceSummaryView;
use App\Entity\Assessment;
use App\Entity\CatalogSubject;
use App\Entity\Institution;
use App\Entity\LearningContent;
use App\Entity\Question;
use App\Entity\User;
use App\Enum\CatalogPublicationStatus;
use App\Enum\InstitutionStatus;
use App\Enum\LearningContentStatus;
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
     * @return list<array{key: string, label: string, value: int, href: string|null, icon: string, tone: string}>
     */
    public function forActor(User $actor): array
    {
        if (!$this->adminAuthorization->canAccessAdminShell($actor)) {
            return [];
        }

        $cards = [];

        if ($this->adminAuthorization->canViewUsers($actor)) {
            $cards[] = $this->card('users', 'Aktif kullanıcı', $this->countStatus(User::class, UserStatus::Active), $this->urlGenerator->generate('app_admin_users'), 'users', 'users');
        }

        if ($this->adminAuthorization->canViewInstitutions($actor)) {
            $cards[] = $this->card('institutions', 'Aktif kurum', $this->countStatus(Institution::class, InstitutionStatus::Active), $this->urlGenerator->generate('app_admin_institutions'), 'institutions', 'institutions');
        }

        if ($this->adminAuthorization->canViewCatalog($actor)) {
            $cards[] = $this->card('lessons', 'Yayımlanmış ders', $this->countStatus(CatalogSubject::class, CatalogPublicationStatus::Published), $this->urlGenerator->generate('app_admin_catalog'), 'catalog', 'lessons');
        }

        if ($this->adminAuthorization->canViewLearningContentWorkspace($actor)) {
            $cards[] = $this->card('review', 'İnceleme bekleyen', $this->countStatus(LearningContent::class, LearningContentStatus::InReview), $this->urlGenerator->generate('app_admin_learning_contents'), 'learning_contents', 'review');
        }

        if ($this->adminAuthorization->canViewQuestionBank($actor)) {
            $cards[] = $this->card('questions', 'Sorular', $this->countStatus(Question::class, null), $this->urlGenerator->generate('app_admin_questions'), 'questions', 'questions');
        }

        if ($this->adminAuthorization->canViewTestBank($actor)) {
            $cards[] = $this->card('tests', 'Testler', $this->countStatus(Assessment::class, null), $this->urlGenerator->generate('app_admin_tests'), 'tests', 'tests');
        }

        return $cards;
    }

    /**
     * @param list<array{key: string, label: string, value: int, href: string|null, icon: string, tone: string}> $cards
     *
     * @return array{can_create_institution: bool, published_contents: int, tasks: list<array{label: string, hint: string, href: string, icon: string}>}
     */
    public function overview(User $actor, ?ContentWorkspaceSummaryView $workspace, array $cards, int $publishedContents): array
    {
        $values = [];
        foreach ($cards as $card) {
            $values[$card['key']] = $card['value'];
        }

        $tasks = [];
        if (null !== $workspace && 0 === ($values['questions'] ?? -1) && $workspace->canCreateQuestion) {
            $tasks[] = [
                'label' => 'İlk soruyu oluştur',
                'hint' => 'Testlerin için soru havuzunu oluşturmaya başla.',
                'href' => $this->urlGenerator->generate('app_admin_question_new'),
                'icon' => $this->icons->resolve('questions'),
            ];
        }
        if (null !== $workspace && 0 === ($values['tests'] ?? -1) && $workspace->canCreateTest) {
            $tasks[] = [
                'label' => 'İlk testi hazırla',
                'hint' => 'Oluşturduğun sorulardan bir test hazırlamaya başla.',
                'href' => $this->urlGenerator->generate('app_admin_test_new'),
                'icon' => $this->icons->resolve('tests'),
            ];
        }
        if ($this->adminAuthorization->canViewInstitutions($actor)) {
            $tasks[] = [
                'label' => 'Kurumları yönet',
                'hint' => $this->adminAuthorization->canCreateInstitutions($actor)
                    ? 'Okul ve kurumları ekleyerek platformu büyüt.'
                    : 'Kayıtlı kurumları buradan görebilirsin.',
                'href' => $this->urlGenerator->generate('app_admin_institutions'),
                'icon' => $this->icons->resolve('institutions'),
            ];
        }

        return [
            'can_create_institution' => $this->adminAuthorization->canCreateInstitutions($actor),
            'published_contents' => max(0, $publishedContents),
            'tasks' => $tasks,
        ];
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
     * @return array{key: string, label: string, value: int, href: string|null, icon: string, tone: string}
     */
    private function card(string $key, string $label, int $value, ?string $href, string $icon, string $tone): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'href' => $href,
            'icon' => $this->icons->resolve($icon),
            'tone' => $tone,
        ];
    }
}

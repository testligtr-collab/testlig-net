<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Dto\ContentWorkspaceSummaryView;
use App\Dto\WorkspaceDashboardAction;
use App\Dto\WorkspaceDashboardCard;
use App\Dto\WorkspaceDashboardItem;
use App\Dto\WorkspaceDashboardView;
use App\Entity\Assessment;
use App\Entity\ClassroomTeacherAssignment;
use App\Entity\LearningContent;
use App\Entity\Question;
use App\Entity\User;
use App\Enum\AssessmentScope;
use App\Enum\ClassroomStatus;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionMembershipStatus;
use App\Enum\LearningContentStatus;
use App\Enum\QuestionScope;
use App\Enum\TeacherAssignmentStatus;
use App\Enum\UserRole;
use App\Presentation\ContentWorkflowLabels;
use App\Repository\AssessmentRepository;
use App\Repository\QuestionRepository;
use App\Security\AdminAuthorization;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Cheap, permission-scoped counts. Teachers see only their own content totals.
 */
final class ContentWorkspaceSummary
{
    private int $statementCount = 0;

    /** @var array<string, int> */
    private array $classroomCounts = [];

    public function __construct(
        private readonly AdminAuthorization $adminAuthorization,
        private readonly AdminLearningContentQuery $contents,
        private readonly QuestionRepository $questions,
        private readonly AssessmentRepository $assessments,
        private readonly EntityManagerInterface $entityManager,
        private readonly UrlGeneratorInterface $urls,
        private readonly ContentWorkflowLabels $labels,
    ) {
    }

    public function statementCount(): int
    {
        return $this->statementCount;
    }

    public function forActor(User $actor): ?ContentWorkspaceSummaryView
    {
        if (!$this->adminAuthorization->canViewLearningContentWorkspace($actor)) {
            return null;
        }

        $actorId = $actor->getId();

        return new ContentWorkspaceSummaryView(
            draftContents: $this->contents->listContents($actorId, [
                'status' => 'draft',
                'page' => 1,
                'page_size' => 1,
            ])->totalCount,
            reviewContents: $this->contents->listContents($actorId, [
                'status' => 'in_review',
                'page' => 1,
                'page_size' => 1,
            ])->totalCount,
            myQuestions: $this->questions->countCreatedBy($actor),
            myTests: $this->assessments->countCreatedBy($actor),
            ownContentCounts: $this->seesOnlyOwnContent($actor),
            canCreateContent: $this->adminAuthorization->canManageLearningContentWorkspace($actor),
            canCreateQuestion: $this->adminAuthorization->canAuthorQuestions($actor),
            canCreateTest: $this->adminAuthorization->canAuthorTests($actor),
            canOpenAdminHome: $this->adminAuthorization->canAccessAdminShell($actor),
        );
    }

    public function activeClassroomCount(User $actor): int
    {
        $key = $actor->getId()->toRfc4122();
        if (isset($this->classroomCounts[$key])) {
            return $this->classroomCounts[$key];
        }

        ++$this->statementCount;
        $count = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(assignment.id)')
            ->from(ClassroomTeacherAssignment::class, 'assignment')
            ->innerJoin('assignment.classroom', 'classroom')
            ->innerJoin('assignment.teacherMembership', 'membership')
            ->andWhere('membership.user = :actor')
            ->andWhere('membership.role = :role')
            ->andWhere('membership.status = :membershipStatus')
            ->andWhere('assignment.status = :assignmentStatus')
            ->andWhere('classroom.status = :classroomStatus')
            ->setParameter('actor', $actor->getId(), 'uuid')
            ->setParameter('role', InstitutionMembershipRole::Teacher)
            ->setParameter('membershipStatus', InstitutionMembershipStatus::Active)
            ->setParameter('assignmentStatus', TeacherAssignmentStatus::Active)
            ->setParameter('classroomStatus', ClassroomStatus::Active)
            ->getQuery()
            ->getSingleScalarResult();
        $this->classroomCounts[$key] = $count;

        return $count;
    }

    public function workspaceHome(User $actor): WorkspaceDashboardView
    {
        $this->statementCount = 0;
        $canContent = $this->adminAuthorization->canViewLearningContentWorkspace($actor);
        $canReview = $this->adminAuthorization->canReviewLearningContent($actor);
        $classrooms = $this->activeClassroomCount($actor);
        $cards = [];
        $actions = [];
        $recent = [];
        $pending = [];

        if ($canContent) {
            $content = $this->statusCounts(LearningContent::class, $actor, null);
            $questions = $this->statusCounts(Question::class, $actor, QuestionScope::Platform);
            $tests = $this->statusCounts(Assessment::class, $actor, AssessmentScope::Platform);
            $cards[] = new WorkspaceDashboardCard(
                'drafts',
                'Taslak içeriklerim',
                $content['draft'],
                'Sahibi olduğunuz taslaklar.',
                $this->urls->generate('app_admin_learning_contents', ['status' => 'draft']),
            );
            if ($canReview) {
                $cards[] = new WorkspaceDashboardCard(
                    'review',
                    'İnceleme kuyruğu',
                    $this->reviewQueueCount(),
                    'İnceleme veya iade yetkiniz olan kayıtlar.',
                    $this->urls->generate('app_admin_learning_contents', ['status' => 'in_review']),
                );
            }
            $cards[] = new WorkspaceDashboardCard(
                'questions',
                'Sorularım',
                $questions['total'],
                \sprintf('Taslak %d, incelemede %d.', $questions['draft'], $questions['in_review']),
                $this->urls->generate('app_admin_questions'),
            );
            $cards[] = new WorkspaceDashboardCard(
                'tests',
                'Testlerim',
                $tests['total'],
                \sprintf('Taslak %d, incelemede %d. Platform kapsamı.', $tests['draft'], $tests['in_review']),
                $this->urls->generate('app_admin_tests'),
            );
            $recent = $this->recentContents($actor);
            $pending = $canReview
                ? $this->pendingReview()
                : $this->ownDrafts($actor);
        }

        $cards[] = new WorkspaceDashboardCard(
            'classrooms',
            'Aktif sınıflarım',
            $classrooms,
            'Aktif öğretmen ataması.',
            $classrooms > 0 ? $this->urls->generate('app_teacher_classrooms') : null,
        );

        if ($this->adminAuthorization->canManageLearningContentWorkspace($actor)) {
            $actions[] = new WorkspaceDashboardAction('Yeni içerik', $this->urls->generate('app_admin_learning_content_new'));
        }
        if ($this->adminAuthorization->canAuthorQuestions($actor)) {
            $actions[] = new WorkspaceDashboardAction('Yeni soru', $this->urls->generate('app_admin_question_new'));
        }
        if ($this->adminAuthorization->canAuthorTests($actor)) {
            $actions[] = new WorkspaceDashboardAction('Yeni test', $this->urls->generate('app_admin_test_new'));
        }
        if ($classrooms > 0) {
            $actions[] = new WorkspaceDashboardAction('Sınıflarım', $this->urls->generate('app_teacher_classrooms'));
        }

        return new WorkspaceDashboardView(
            intro: $this->intro($actor, $canContent, $canReview),
            cards: $cards,
            actions: $actions,
            recent: $recent,
            pending: $pending,
            showContent: $canContent,
            canReview: $canReview,
        );
    }

    /**
     * @param class-string $class
     *
     * @return array{draft: int, in_review: int, published: int, archived: int, total: int}
     */
    private function statusCounts(string $class, User $actor, AssessmentScope|QuestionScope|null $scope): array
    {
        ++$this->statementCount;
        $qb = $this->entityManager->createQueryBuilder()
            ->select('entity.status AS status', 'COUNT(entity.id) AS total')
            ->from($class, 'entity')
            ->andWhere('IDENTITY(entity.createdBy) = :actorId')
            ->setParameter('actorId', $actor->getId(), 'uuid')
            ->groupBy('entity.status');
        if (null !== $scope) {
            $qb->andWhere('entity.scope = :scope')->setParameter('scope', $scope);
        }
        /** @var list<array{status: mixed, total: mixed}> $rows */
        $rows = $qb->getQuery()->getArrayResult();
        $counts = ['draft' => 0, 'in_review' => 0, 'published' => 0, 'archived' => 0, 'total' => 0];
        foreach ($rows as $row) {
            $key = $this->scalarStatus($row['status']);
            $value = (int) $row['total'];
            if (isset($counts[$key]) && 'total' !== $key) {
                $counts[$key] = $value;
            }
            $counts['total'] += $value;
        }

        return $counts;
    }

    private function reviewQueueCount(): int
    {
        ++$this->statementCount;

        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(content.id)')
            ->from(LearningContent::class, 'content')
            ->andWhere('content.status = :status')
            ->setParameter('status', LearningContentStatus::InReview)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<WorkspaceDashboardItem>
     */
    private function recentContents(User $actor): array
    {
        ++$this->statementCount;
        /** @var list<array{title: mixed, contentType: mixed, status: mixed, updatedAt: mixed, id: mixed}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('c.title AS title', 'c.contentType AS contentType', 'c.status AS status', 'c.updatedAt AS updatedAt', 'c.id AS id')
            ->from(LearningContent::class, 'c')
            ->andWhere('IDENTITY(c.createdBy) = :actorId')
            ->setParameter('actorId', $actor->getId(), 'uuid')
            ->orderBy('c.updatedAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setMaxResults(5)
            ->getQuery()
            ->getArrayResult();

        return $this->items($rows);
    }

    /**
     * @return list<WorkspaceDashboardItem>
     */
    private function pendingReview(): array
    {
        ++$this->statementCount;
        /** @var list<array{title: mixed, contentType: mixed, status: mixed, updatedAt: mixed, id: mixed}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('c.title AS title', 'c.contentType AS contentType', 'c.status AS status', 'c.updatedAt AS updatedAt', 'c.id AS id')
            ->from(LearningContent::class, 'c')
            ->andWhere('c.status = :status')
            ->setParameter('status', LearningContentStatus::InReview)
            ->orderBy('c.updatedAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setMaxResults(5)
            ->getQuery()
            ->getArrayResult();

        return $this->items($rows);
    }

    /**
     * @return list<WorkspaceDashboardItem>
     */
    private function ownDrafts(User $actor): array
    {
        ++$this->statementCount;
        /** @var list<array{title: mixed, contentType: mixed, status: mixed, updatedAt: mixed, id: mixed}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('c.title AS title', 'c.contentType AS contentType', 'c.status AS status', 'c.updatedAt AS updatedAt', 'c.id AS id')
            ->from(LearningContent::class, 'c')
            ->andWhere('IDENTITY(c.createdBy) = :actorId')
            ->andWhere('c.status = :status')
            ->setParameter('actorId', $actor->getId(), 'uuid')
            ->setParameter('status', LearningContentStatus::Draft)
            ->orderBy('c.updatedAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setMaxResults(5)
            ->getQuery()
            ->getArrayResult();

        return $this->items($rows);
    }

    /**
     * @param list<array{title: mixed, contentType: mixed, status: mixed, updatedAt: mixed, id: mixed}> $rows
     *
     * @return list<WorkspaceDashboardItem>
     */
    private function items(array $rows): array
    {
        $items = [];
        foreach ($rows as $row) {
            $id = $row['id'];
            if (!$id instanceof Uuid) {
                continue;
            }
            $title = \is_string($row['title']) ? trim($row['title']) : '';
            $updatedAt = $row['updatedAt'] instanceof \DateTimeInterface ? $row['updatedAt'] : null;
            $items[] = new WorkspaceDashboardItem(
                '' !== $title ? $title : 'Adsız içerik',
                $this->safeLabel('type', $this->scalarStatus($row['contentType'])),
                $this->safeLabel('status', $this->scalarStatus($row['status'])),
                null !== $updatedAt ? $updatedAt->format('d.m.Y H:i') : '',
                $this->urls->generate('app_admin_learning_content', ['id' => $id->toRfc4122()]),
            );
        }

        return $items;
    }

    private function safeLabel(string $kind, string $value): string
    {
        $label = $this->labels->label($kind, $value);

        return $label === $value ? 'Kayıt' : $label;
    }

    private function scalarStatus(mixed $status): string
    {
        if ($status instanceof \BackedEnum) {
            return (string) $status->value;
        }

        return \is_string($status) ? $status : '';
    }

    private function intro(User $actor, bool $canContent, bool $canReview): string
    {
        if ($this->adminAuthorization->canAccessAdminShell($actor)) {
            return 'Bu sayfa platform yetkinizin açtığı kayıtları gösterir. Yönetim kökü ayrıdır.';
        }
        if (!$canContent) {
            return 'Atandığınız aktif sınıflar.';
        }
        if ($canReview && \in_array(UserRole::Moderator->value, $actor->getRoles(), true)) {
            return 'İnceleme kuyruğu sizin kapsamdadır. Yeni kayıt oluşturma bu rolde kapalıdır.';
        }
        if ($canReview) {
            return 'İnceleme ve sahibi olduğunuz içerik.';
        }

        return 'Sahibi olduğunuz taslak içerik, soru ve testler.';
    }

    private function seesOnlyOwnContent(User $actor): bool
    {
        $roles = $actor->getRoles();
        if (!\in_array(UserRole::Teacher->value, $roles, true)) {
            return false;
        }

        return !$this->adminAuthorization->canMapCatalogCanonical($actor)
            && !\in_array(UserRole::Moderator->value, $roles, true);
    }
}

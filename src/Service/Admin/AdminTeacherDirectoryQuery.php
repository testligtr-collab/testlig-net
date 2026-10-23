<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Dto\AdminPagedResult;
use App\Dto\AdminTeacherDirectoryDetail;
use App\Dto\AdminTeacherDirectoryList;
use App\Dto\AdminTeacherDirectoryRow;
use App\Dto\AdminTeacherDirectorySummary;
use App\Entity\ClassroomTeacherAssignment;
use App\Entity\InstitutionMembership;
use App\Entity\Location;
use App\Entity\TeacherProfessionalProfile;
use App\Entity\User;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionMembershipStatus;
use App\Enum\LocationLevel;
use App\Enum\TeacherAssignmentRole;
use App\Enum\TeacherAssignmentStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Exception\CommerceException;
use App\Security\AdminAuthorization;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Read model for teacher accounts. A teacher is a user whose stored role
 * reaches ROLE_TEACHER through the existing role hierarchy. Each account is
 * one row. This query does not grant roles, memberships, or profile approval.
 */
final class AdminTeacherDirectoryQuery
{
    public const MISSING = 'Belirtilmemiş';

    private const ALLOWED_FILTER_KEYS = [
        'q',
        'status',
        'role',
        'subject',
        'page',
        'page_size',
    ];

    /** @var list<string>|null */
    private ?array $teacherRoles = null;

    public function __construct(
        private readonly AdminActorGuard $actorGuard,
        private readonly AdminAuthorization $adminAuthorization,
        private readonly EntityManagerInterface $em,
        private readonly RoleHierarchyInterface $roleHierarchy,
    ) {
    }

    /**
     * @param array<string, mixed> $rawFilters
     */
    public function list(Uuid $actorId, array $rawFilters = []): AdminTeacherDirectoryList
    {
        $this->actorGuard->requireUsersView($actorId);
        $filters = $this->normalizeFilters($rawFilters);
        $page = AdminPagination::normalizePage((int) ($filters['page'] ?? 1));
        $pageSize = AdminPagination::normalizePageSize((int) ($filters['page_size'] ?? AdminPagination::DEFAULT_PAGE_SIZE));
        $qb = $this->teachersQuery();
        $this->applyFilters($qb, $filters);

        $countQb = clone $qb;
        $total = (int) $countQb
            ->resetDQLPart('select')
            ->resetDQLPart('orderBy')
            ->select('COUNT(u.id)')
            ->getQuery()
            ->getSingleScalarResult();

        /** @var list<User> $rows */
        $rows = $qb
            ->orderBy('u.createdAt', 'DESC')
            ->addOrderBy('u.id', 'ASC')
            ->setFirstResult(AdminPagination::offset($page, $pageSize))
            ->setMaxResults($pageSize)
            ->getQuery()
            ->getResult();

        $profiles = $this->profilesFor($rows);
        $items = [];
        foreach ($rows as $user) {
            $profile = $profiles[$user->getId()->toRfc4122()] ?? null;
            $items[] = $this->row($user, $profile);
        }

        return new AdminTeacherDirectoryList(
            new AdminPagedResult($items, $page, $pageSize, $total),
            [
                'q' => \is_string($filters['q'] ?? null) ? $filters['q'] : '',
                'status' => \is_string($filters['status'] ?? null) ? $filters['status'] : '',
                'role' => \is_string($filters['role'] ?? null) ? $filters['role'] : '',
                'subject' => \is_string($filters['subject'] ?? null) ? $filters['subject'] : '',
            ],
            $this->subjectChoices(),
            $this->roleChoices(),
        );
    }

    public function summary(Uuid $actorId): AdminTeacherDirectorySummary
    {
        $this->actorGuard->requireUsersView($actorId);
        $result = $this->teachersQuery()
            ->select('COUNT(u.id) AS total')
            ->addSelect('SUM(CASE WHEN u.status = :activeStatus THEN 1 ELSE 0 END) AS activeCount')
            ->addSelect('SUM(CASE WHEN u.emailVerifiedAt IS NOT NULL THEN 1 ELSE 0 END) AS verifiedCount')
            ->setParameter('activeStatus', UserStatus::Active)
            ->getQuery()
            ->getSingleResult();
        if (!\is_array($result)) {
            throw new \RuntimeException('Teacher summary query did not return a row.');
        }

        $withProfile = (int) $this->teachersQuery()
            ->select('COUNT(profile.id)')
            ->innerJoin(TeacherProfessionalProfile::class, 'profile', 'WITH', 'profile.user = u')
            ->getQuery()
            ->getSingleScalarResult();

        return new AdminTeacherDirectorySummary(
            $this->countValue($result['total'] ?? 0),
            $this->countValue($result['activeCount'] ?? 0),
            $this->countValue($result['verifiedCount'] ?? 0),
            $withProfile,
        );
    }

    public function detail(Uuid $actorId, Uuid $teacherId): AdminTeacherDirectoryDetail
    {
        $actor = $this->actorGuard->requireUsersView($actorId);
        $user = $this->em->find(User::class, $teacherId);
        if (!$user instanceof User || !$this->holdsTeacherRole($user)) {
            throw CommerceException::notFound();
        }

        $profiles = $this->profilesFor([$user]);
        $profile = $profiles[$user->getId()->toRfc4122()] ?? null;
        $labels = $this->profileLabels($profile);
        $membershipsVisible = $this->adminAuthorization->canViewMemberships($actor);

        return new AdminTeacherDirectoryDetail(
            id: $user->getId(),
            shortRef: AdminShortRef::fromUuid($user->getId()),
            firstName: $user->getFirstName(),
            lastName: $user->getLastName(),
            email: $user->getEmail(),
            status: $user->getStatus(),
            globalRoles: [] === $user->getGlobalRoleEnums() ? [] : array_map(
                static fn (UserRole $role): string => $role->value,
                $user->getGlobalRoleEnums(),
            ),
            emailVerified: null !== $user->getEmailVerifiedAt(),
            createdAt: $user->getCreatedAt(),
            subjectLabel: $labels['subject'],
            gradeLabel: $labels['grades'],
            schoolLabel: $labels['school'],
            membershipsVisible: $membershipsVisible,
            memberships: $membershipsVisible ? $this->memberships($user) : [],
            assignments: $membershipsVisible ? $this->assignments($user) : [],
        );
    }

    /**
     * @return list<string>
     */
    public function teacherRoleValues(): array
    {
        if (null !== $this->teacherRoles) {
            return $this->teacherRoles;
        }

        $values = [];
        foreach (UserRole::cases() as $role) {
            if (UserRole::User === $role) {
                continue;
            }
            $reachable = $this->roleHierarchy->getReachableRoleNames([$role->value]);
            if (\in_array(UserRole::Teacher->value, $reachable, true)) {
                $values[] = $role->value;
            }
        }
        sort($values);
        $this->teacherRoles = $values;

        return $values;
    }

    private function teachersQuery(): QueryBuilder
    {
        $qb = $this->em->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u');
        $parts = [];
        foreach ($this->teacherRoleValues() as $index => $value) {
            $name = 'teacherRole'.$index;
            $parts[] = 'u.globalRoles LIKE :'.$name;
            $qb->setParameter($name, '%"'.$value.'"%');
        }
        if ([] === $parts) {
            $qb->andWhere('1 = 0');

            return $qb;
        }
        $qb->andWhere('('.implode(' OR ', $parts).')');

        return $qb;
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function applyFilters(QueryBuilder $qb, array $filters): void
    {
        if (isset($filters['q']) && \is_string($filters['q']) && '' !== $filters['q']) {
            try {
                $term = AdminLikeEscape::normalizeSearch($filters['q']);
            } catch (\InvalidArgumentException) {
                throw CommerceException::invalidInput('Search query is too long.');
            }
            if (null !== $term) {
                $qb->andWhere(
                    "(u.firstName LIKE :q ESCAPE '!' OR u.lastName LIKE :q ESCAPE '!' OR u.email LIKE :q ESCAPE '!' OR u.normalizedEmail LIKE :q ESCAPE '!')",
                )->setParameter('q', AdminLikeEscape::containsPattern($term));
            }
        }

        if (isset($filters['status']) && \is_string($filters['status']) && '' !== $filters['status']) {
            $status = UserStatus::tryFrom($filters['status']);
            if (!$status instanceof UserStatus) {
                throw CommerceException::invalidInput('Invalid user status filter.');
            }
            $qb->andWhere('u.status = :status')->setParameter('status', $status);
        }

        if (isset($filters['role']) && \is_string($filters['role']) && '' !== $filters['role']) {
            if (!\in_array($filters['role'], $this->teacherRoleValues(), true)) {
                throw CommerceException::invalidInput('Invalid teacher role filter.');
            }
            $qb->andWhere('u.globalRoles LIKE :roleLike')
                ->setParameter('roleLike', '%"'.$filters['role'].'"%');
        }

        if (isset($filters['subject']) && \is_string($filters['subject']) && '' !== $filters['subject']) {
            if (!Uuid::isValid($filters['subject'])) {
                throw CommerceException::invalidInput('Invalid subject filter.');
            }
            $qb->innerJoin(
                TeacherProfessionalProfile::class,
                'profileFilter',
                'WITH',
                'profileFilter.user = u AND profileFilter.subject = :subjectId',
            )->setParameter('subjectId', Uuid::fromString($filters['subject']), 'uuid');
        }
    }

    /**
     * @param list<User> $users
     *
     * @return array<string, TeacherProfessionalProfile>
     */
    private function profilesFor(array $users): array
    {
        if ([] === $users) {
            return [];
        }

        /** @var list<TeacherProfessionalProfile> $profiles */
        $profiles = $this->em->createQueryBuilder()
            ->select('profile', 'subject', 'school', 'district', 'province')
            ->from(TeacherProfessionalProfile::class, 'profile')
            ->innerJoin('profile.subject', 'subject')
            ->leftJoin('profile.schoolLocation', 'school')
            ->leftJoin('school.parent', 'district')
            ->leftJoin('district.parent', 'province')
            ->where('profile.user IN (:users)')
            ->setParameter('users', $users)
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($profiles as $profile) {
            $indexed[$profile->getUser()->getId()->toRfc4122()] = $profile;
        }

        return $indexed;
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    private function subjectChoices(): array
    {
        /** @var list<array{id: mixed, name: mixed}> $rows */
        $rows = $this->teachersQuery()
            ->select('DISTINCT subject.id AS id, subject.name AS name')
            ->innerJoin(TeacherProfessionalProfile::class, 'profile', 'WITH', 'profile.user = u')
            ->innerJoin('profile.subject', 'subject')
            ->orderBy('subject.name', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $choices = [];
        foreach ($rows as $row) {
            $id = $row['id'];
            $name = $row['name'];
            if ($id instanceof Uuid) {
                $idValue = $id->toRfc4122();
            } elseif (\is_string($id) && Uuid::isValid($id)) {
                $idValue = $id;
            } else {
                continue;
            }
            if (!\is_string($name) || '' === $name) {
                continue;
            }
            $choices[] = ['id' => $idValue, 'name' => $name];
        }

        return $choices;
    }

    /**
     * @return list<UserRole>
     */
    private function roleChoices(): array
    {
        $choices = [];
        foreach ($this->teacherRoleValues() as $value) {
            $role = UserRole::tryFrom($value);
            if ($role instanceof UserRole) {
                $choices[] = $role;
            }
        }

        return $choices;
    }

    private function row(User $user, ?TeacherProfessionalProfile $profile): AdminTeacherDirectoryRow
    {
        $labels = $this->profileLabels($profile);

        return new AdminTeacherDirectoryRow(
            id: $user->getId(),
            shortRef: AdminShortRef::fromUuid($user->getId()),
            firstName: $user->getFirstName(),
            lastName: $user->getLastName(),
            email: $user->getEmail(),
            status: $user->getStatus(),
            globalRoles: array_map(
                static fn (UserRole $role): string => $role->value,
                $user->getGlobalRoleEnums(),
            ),
            emailVerified: null !== $user->getEmailVerifiedAt(),
            subjectLabel: $labels['subject'],
            gradeLabel: $labels['grades'],
            schoolLabel: $labels['school'],
        );
    }

    /**
     * @return array{subject: string, grades: string, school: string}
     */
    private function profileLabels(?TeacherProfessionalProfile $profile): array
    {
        if (!$profile instanceof TeacherProfessionalProfile) {
            return [
                'subject' => self::MISSING,
                'grades' => self::MISSING,
                'school' => self::MISSING,
            ];
        }

        $grades = [];
        foreach ($profile->getGradeLevels() as $level) {
            $grades[] = $level->value.'. sınıf';
        }

        return [
            'subject' => $profile->getSubject()->getName(),
            'grades' => [] === $grades ? self::MISSING : implode(', ', $grades),
            'school' => $this->schoolLabel($profile->getSchoolLocation()),
        ];
    }

    private function schoolLabel(?Location $school): string
    {
        if (!$school instanceof Location) {
            return self::MISSING;
        }

        $parts = [];
        $district = $school->getParent();
        $province = $district?->getParent();
        if ($province instanceof Location && LocationLevel::Province === $province->getLevel()) {
            $parts[] = $province->getName();
        }
        if ($district instanceof Location && LocationLevel::District === $district->getLevel()) {
            $parts[] = $district->getName();
        }
        $parts[] = $school->getName();

        return implode(' / ', $parts);
    }

    /**
     * @return list<array{institution: string, role: string, status: string}>
     */
    private function memberships(User $user): array
    {
        /** @var list<InstitutionMembership> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('membership', 'institution')
            ->from(InstitutionMembership::class, 'membership')
            ->innerJoin('membership.institution', 'institution')
            ->where('membership.user = :user')
            ->setParameter('user', $user)
            ->orderBy('institution.name', 'ASC')
            ->addOrderBy('membership.id', 'ASC')
            ->getQuery()
            ->getResult();

        $items = [];
        foreach ($rows as $membership) {
            $items[] = [
                'institution' => $membership->getInstitution()->getName(),
                'role' => $this->membershipRoleLabel($membership->getRole()),
                'status' => $this->membershipStatusLabel($membership->getStatus()),
            ];
        }

        return $items;
    }

    /**
     * @return list<array{classroom: string, institution: string, role: string}>
     */
    private function assignments(User $user): array
    {
        /** @var list<ClassroomTeacherAssignment> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('assignment', 'classroom', 'institution')
            ->from(ClassroomTeacherAssignment::class, 'assignment')
            ->innerJoin('assignment.classroom', 'classroom')
            ->innerJoin('assignment.institution', 'institution')
            ->innerJoin('assignment.teacherMembership', 'membership')
            ->where('membership.user = :user')
            ->andWhere('assignment.status = :activeAssignment')
            ->setParameter('user', $user)
            ->setParameter('activeAssignment', TeacherAssignmentStatus::Active)
            ->orderBy('classroom.name', 'ASC')
            ->addOrderBy('assignment.id', 'ASC')
            ->getQuery()
            ->getResult();

        $items = [];
        foreach ($rows as $assignment) {
            $items[] = [
                'classroom' => $assignment->getClassroom()->getName(),
                'institution' => $assignment->getInstitution()->getName(),
                'role' => $this->assignmentRoleLabel($assignment->getRole()),
            ];
        }

        return $items;
    }

    private function holdsTeacherRole(User $user): bool
    {
        foreach ($user->getGlobalRoleEnums() as $role) {
            $reachable = $this->roleHierarchy->getReachableRoleNames([$role->value]);
            if (\in_array(UserRole::Teacher->value, $reachable, true)) {
                return true;
            }
        }

        return false;
    }

    private function membershipRoleLabel(InstitutionMembershipRole $role): string
    {
        return match ($role) {
            InstitutionMembershipRole::Owner => 'Kurum sahibi',
            InstitutionMembershipRole::Manager => 'Kurum yöneticisi',
            InstitutionMembershipRole::Teacher => 'Öğretmen',
            InstitutionMembershipRole::Staff => 'Personel',
            InstitutionMembershipRole::Student => 'Öğrenci',
        };
    }

    private function membershipStatusLabel(InstitutionMembershipStatus $status): string
    {
        return match ($status) {
            InstitutionMembershipStatus::Pending => 'Beklemede',
            InstitutionMembershipStatus::Active => 'Aktif',
            InstitutionMembershipStatus::Suspended => 'Askıda',
            InstitutionMembershipStatus::Ended => 'Sona erdi',
        };
    }

    private function assignmentRoleLabel(TeacherAssignmentRole $role): string
    {
        return match ($role) {
            TeacherAssignmentRole::HomeroomTeacher => 'Sınıf öğretmeni',
            TeacherAssignmentRole::AssistantTeacher => 'Yardımcı öğretmen',
        };
    }

    /**
     * @param array<string, mixed> $rawFilters
     *
     * @return array<string, mixed>
     */
    private function normalizeFilters(array $rawFilters): array
    {
        $filters = [];
        foreach (self::ALLOWED_FILTER_KEYS as $key) {
            if (!\array_key_exists($key, $rawFilters)) {
                continue;
            }
            $value = $rawFilters[$key];
            if (\is_string($value)) {
                $filters[$key] = trim($value);
                continue;
            }
            if (\is_int($value)) {
                $filters[$key] = $value;
            }
        }

        return $filters;
    }

    private function countValue(mixed $value): int
    {
        if (\is_int($value)) {
            return $value;
        }
        if (\is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return 0;
    }
}

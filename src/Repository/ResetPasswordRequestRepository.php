<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ResetPasswordRequest;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordRequestInterface;
use SymfonyCasts\Bundle\ResetPassword\Persistence\ResetPasswordRequestRepositoryInterface;

/**
 * @extends ServiceEntityRepository<ResetPasswordRequest>
 */
class ResetPasswordRequestRepository extends ServiceEntityRepository implements ResetPasswordRequestRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ResetPasswordRequest::class);
    }

    public function createResetPasswordRequest(object $user, \DateTimeInterface $expiresAt, string $selector, string $hashedToken): ResetPasswordRequestInterface
    {
        \assert($user instanceof User);

        return new ResetPasswordRequest($user, $expiresAt, $selector, $hashedToken);
    }

    public function getUserIdentifier(object $user): string
    {
        \assert($user instanceof User);

        return $user->getId()->toRfc4122();
    }

    public function persistResetPasswordRequest(ResetPasswordRequestInterface $resetPasswordRequest): void
    {
        $this->getEntityManager()->persist($resetPasswordRequest);
        $this->getEntityManager()->flush();
    }

    public function findResetPasswordRequest(string $selector): ?ResetPasswordRequestInterface
    {
        return $this->findOneBy(['selector' => $selector]);
    }

    public function getMostRecentNonExpiredRequestDate(object $user): ?\DateTimeInterface
    {
        $request = $this->findOneByUser($user);
        if (null !== $request && !$request->isExpired()) {
            return $request->getRequestedAt();
        }

        return null;
    }

    public function removeResetPasswordRequest(ResetPasswordRequestInterface $resetPasswordRequest): void
    {
        $this->removeRequests($resetPasswordRequest->getUser());
    }

    public function removeExpiredResetPasswordRequests(): int
    {
        $time = new \DateTimeImmutable('-1 week');
        $query = $this->createQueryBuilder('t')
            ->delete()
            ->where('t.expiresAt <= :time')
            ->setParameter('time', $time)
            ->getQuery();

        return $query->execute();
    }

    /**
     * UUID binary associations do not always bind reliably with `t.user = :user`.
     * Compare via IDENTITY(user) and UuidType instead.
     */
    private function findOneByUser(object $user): ?ResetPasswordRequestInterface
    {
        \assert($user instanceof User);
        $userId = $this->resolveUserId($user);

        /** @var ResetPasswordRequestInterface|null $request */
        $request = $this->createQueryBuilder('t')
            ->where('IDENTITY(t.user) = :userId')
            ->setParameter('userId', $userId, 'uuid')
            ->orderBy('t.requestedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $request;
    }

    public function removeRequests(object $user): void
    {
        \assert($user instanceof User);
        $userId = $this->resolveUserId($user);

        $this->createQueryBuilder('t')
            ->delete()
            ->where('IDENTITY(t.user) = :userId')
            ->setParameter('userId', $userId, 'uuid')
            ->getQuery()
            ->execute();
    }

    private function resolveUserId(User $user): Uuid
    {
        $em = $this->getEntityManager();
        if (!$em->contains($user)) {
            $managed = $em->find(User::class, $user->getId());
            if ($managed instanceof User) {
                return $managed->getId();
            }
        }

        return $user->getId();
    }
}

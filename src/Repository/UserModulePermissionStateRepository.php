<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\UserModulePermissionState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<UserModulePermissionState>
 */
class UserModulePermissionStateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserModulePermissionState::class);
    }

    public function versionFor(Uuid $userId): int
    {
        $value = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT version FROM user_module_permission_states WHERE user_id = :id',
            ['id' => $userId->toBinary()],
            ['id' => ParameterType::BINARY],
        );
        if (false === $value || null === $value) {
            return 0;
        }

        return (int) $value;
    }
}

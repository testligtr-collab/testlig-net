<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\UserModulePermission;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<UserModulePermission>
 */
class UserModulePermissionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserModulePermission::class);
    }

    /**
     * @return list<string>
     */
    public function codesFor(Uuid $userId): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT permission_code FROM user_module_permissions WHERE user_id = :id ORDER BY permission_code ASC',
            ['id' => $userId->toBinary()],
            ['id' => ParameterType::BINARY],
        );
        $codes = [];
        foreach ($rows as $code) {
            if (\is_string($code) && '' !== $code) {
                $codes[] = $code;
            }
        }

        return $codes;
    }
}

<?php

declare(strict_types=1);

namespace App\Entity;

use App\Exception\AccessEntitlementException;
use App\Repository\AccessPackageActiveVersionGuardRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;

/**
 * Exactly one active AccessPackageVersion per package (DB trigger owned).
 */
#[ORM\Entity(repositoryClass: AccessPackageActiveVersionGuardRepository::class)]
#[ORM\Table(name: 'access_package_active_version_guards')]
#[ORM\UniqueConstraint(name: 'uniq_apavg_version', columns: ['version_id'])]
class AccessPackageActiveVersionGuard
{
    #[ORM\Id]
    #[ORM\OneToOne]
    #[ORM\JoinColumn(name: 'package_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AccessPackage $package;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(name: 'version_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AccessPackageVersion $version;

    private function __construct(AccessPackage $package, AccessPackageVersion $version)
    {
        if (!$version->getPackage()->getId()->equals($package->getId())) {
            throw AccessEntitlementException::scopeMismatch();
        }
        $this->package = $package;
        $this->version = $version;
    }

    /**
     * @internal prefer DB trigger ownership
     */
    public static function bind(AccessPackage $package, AccessPackageVersion $version): self
    {
        return new self($package, $version);
    }

    public function getPackageId(): Uuid
    {
        return $this->package->getId();
    }

    #[Ignore]
    public function getPackage(): AccessPackage
    {
        return $this->package;
    }

    #[Ignore]
    public function getVersion(): AccessPackageVersion
    {
        return $this->version;
    }
}

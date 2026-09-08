<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\InstitutionActiveAcademicYearGuardRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;

/**
 * Ensures at most one active academic year per institution.
 * Composite FK (academic_year_id, institution_id) is enforced in DB (+ schema listener).
 */
#[ORM\Entity(repositoryClass: InstitutionActiveAcademicYearGuardRepository::class)]
#[ORM\Table(name: 'institution_active_academic_year_guards')]
#[ORM\UniqueConstraint(name: 'uniq_active_academic_year_id', columns: ['academic_year_id'])]
class InstitutionActiveAcademicYearGuard
{
    #[ORM\Id]
    #[ORM\OneToOne]
    #[ORM\JoinColumn(name: 'institution_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Institution $institution;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(name: 'academic_year_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AcademicYear $academicYear;

    private function __construct(Institution $institution, AcademicYear $academicYear)
    {
        $this->institution = $institution;
        $this->academicYear = $academicYear;
    }

    /**
     * @internal prefer AcademicYearManager
     */
    public static function bind(Institution $institution, AcademicYear $academicYear): self
    {
        return new self($institution, $academicYear);
    }

    public function getInstitutionId(): Uuid
    {
        return $this->institution->getId();
    }

    #[Ignore]
    public function getInstitution(): Institution
    {
        return $this->institution;
    }

    #[Ignore]
    public function getAcademicYear(): AcademicYear
    {
        return $this->academicYear;
    }

    #[Ignore]
    public function swapTo(AcademicYear $academicYear): void
    {
        $this->academicYear = $academicYear;
    }
}

<?php

declare(strict_types=1);

namespace App\Entity;

use App\Exception\AssessmentScoringException;
use App\Repository\AssessmentResultActiveReleaseGuardRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;

/**
 * Operational sync row for one active released result per attempt.
 * Canonical create/delete ownership is DB AFTER INSERT/UPDATE triggers on assessment_result_releases.
 */
#[ORM\Entity(repositoryClass: AssessmentResultActiveReleaseGuardRepository::class)]
#[ORM\Table(name: 'assessment_result_active_release_guards')]
#[ORM\UniqueConstraint(name: 'uniq_ararg_release', columns: ['release_id'])]
class AssessmentResultActiveReleaseGuard
{
    #[ORM\Id]
    #[ORM\OneToOne]
    #[ORM\JoinColumn(name: 'attempt_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AssessmentAttempt $attempt;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(name: 'release_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AssessmentResultRelease $release;

    private function __construct(
        AssessmentAttempt $attempt,
        AssessmentResultRelease $release,
    ) {
        if (!$release->getAttempt()->getId()->equals($attempt->getId())) {
            throw AssessmentScoringException::scopeMismatch();
        }
        if (!$release->getStatus()->isActiveRelease()) {
            throw AssessmentScoringException::invalidInput('Active release guard requires released status.');
        }

        $this->attempt = $attempt;
        $this->release = $release;
    }

    /**
     * @internal prefer DB trigger ownership; application bind is for tests/hydration only
     */
    public static function bind(AssessmentAttempt $attempt, AssessmentResultRelease $release): self
    {
        return new self($attempt, $release);
    }

    public function getAttemptId(): Uuid
    {
        return $this->attempt->getId();
    }

    #[Ignore]
    public function getAttempt(): AssessmentAttempt
    {
        return $this->attempt;
    }

    #[Ignore]
    public function getRelease(): AssessmentResultRelease
    {
        return $this->release;
    }
}

<?php

declare(strict_types=1);

namespace App\Entity;

use App\Exception\AssessmentResultReviewException;
use App\Repository\AssessmentResultActiveReviewPolicyGuardRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;

/**
 * Operational sync row for one active review policy per delivery.
 * Canonical create/delete ownership is DB AFTER INSERT/UPDATE triggers on
 * assessment_result_review_policies.
 */
#[ORM\Entity(repositoryClass: AssessmentResultActiveReviewPolicyGuardRepository::class)]
#[ORM\Table(name: 'assessment_result_active_review_policy_guards')]
#[ORM\UniqueConstraint(name: 'uniq_ararpg_policy', columns: ['policy_id'])]
class AssessmentResultActiveReviewPolicyGuard
{
    #[ORM\Id]
    #[ORM\OneToOne]
    #[ORM\JoinColumn(name: 'delivery_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AssessmentDelivery $delivery;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(name: 'policy_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AssessmentResultReviewPolicy $policy;

    private function __construct(
        AssessmentDelivery $delivery,
        AssessmentResultReviewPolicy $policy,
    ) {
        if (!$policy->getDelivery()->getId()->equals($delivery->getId())) {
            throw AssessmentResultReviewException::scopeMismatch();
        }
        if (!$policy->getStatus()->isActivePolicy()) {
            throw AssessmentResultReviewException::invalidInput('Active review policy guard requires active status.');
        }

        $this->delivery = $delivery;
        $this->policy = $policy;
    }

    /**
     * @internal prefer DB trigger ownership; application bind is for tests/hydration only
     */
    public static function bind(AssessmentDelivery $delivery, AssessmentResultReviewPolicy $policy): self
    {
        return new self($delivery, $policy);
    }

    public function getDeliveryId(): Uuid
    {
        return $this->delivery->getId();
    }

    #[Ignore]
    public function getDelivery(): AssessmentDelivery
    {
        return $this->delivery;
    }

    #[Ignore]
    public function getPolicy(): AssessmentResultReviewPolicy
    {
        return $this->policy;
    }
}

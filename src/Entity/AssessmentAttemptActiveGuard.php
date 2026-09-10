<?php

declare(strict_types=1);

namespace App\Entity;

use App\Exception\AssessmentAttemptException;
use App\Repository\AssessmentAttemptActiveGuardRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;

/**
 * Ensures at most one in-progress attempt per delivery recipient.
 * Clear by removing the row from the entity manager.
 */
#[ORM\Entity(repositoryClass: AssessmentAttemptActiveGuardRepository::class)]
#[ORM\Table(name: 'assessment_attempt_active_guards')]
#[ORM\UniqueConstraint(name: 'uniq_aaag_attempt', columns: ['attempt_id'])]
class AssessmentAttemptActiveGuard
{
    #[ORM\Id]
    #[ORM\OneToOne]
    #[ORM\JoinColumn(name: 'recipient_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AssessmentDeliveryRecipient $recipient;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(name: 'attempt_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AssessmentAttempt $attempt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'delivery_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AssessmentDelivery $delivery;

    private function __construct(
        AssessmentDeliveryRecipient $recipient,
        AssessmentAttempt $attempt,
    ) {
        if (!$attempt->getRecipient()->getId()->equals($recipient->getId())) {
            throw AssessmentAttemptException::scopeMismatch();
        }
        if (!$attempt->getDelivery()->getId()->equals($recipient->getDelivery()->getId())) {
            throw AssessmentAttemptException::scopeMismatch();
        }

        $this->recipient = $recipient;
        $this->attempt = $attempt;
        $this->delivery = $attempt->getDelivery();
    }

    /**
     * @internal prefer AssessmentAttemptManager
     */
    public static function bind(AssessmentDeliveryRecipient $recipient, AssessmentAttempt $attempt): self
    {
        return new self($recipient, $attempt);
    }

    public function getRecipientId(): Uuid
    {
        return $this->recipient->getId();
    }

    #[Ignore]
    public function getRecipient(): AssessmentDeliveryRecipient
    {
        return $this->recipient;
    }

    #[Ignore]
    public function getAttempt(): AssessmentAttempt
    {
        return $this->attempt;
    }

    #[Ignore]
    public function getDelivery(): AssessmentDelivery
    {
        return $this->delivery;
    }
}

<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\HomeworkSubmissionStatus;
use App\Exception\HomeworkSubmissionException;
use App\Repository\HomeworkSubmissionRepository;
use App\Time\UtcInstant;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Student text draft/submit for one homework recipient. Not an evaluation.
 *
 * @internal prefer HomeworkSubmissionManager
 */
#[ORM\Entity(repositoryClass: HomeworkSubmissionRepository::class)]
#[ORM\Table(name: 'homework_submissions')]
#[ORM\UniqueConstraint(name: 'uniq_homework_sub_recipient', columns: ['recipient_id'])]
#[ORM\UniqueConstraint(name: 'uniq_homework_sub_assignment_student', columns: ['assignment_id', 'student_user_id'])]
#[ORM\Index(name: 'idx_homework_sub_student', columns: ['student_user_id'])]
#[ORM\Index(name: 'idx_homework_sub_assignment', columns: ['assignment_id'])]
class HomeworkSubmission
{
    public const BODY_MAX = 20000;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'assignment_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private HomeworkAssignment $assignment;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'recipient_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private HomeworkRecipient $recipient;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'student_user_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private User $studentUser;

    #[ORM\Column(type: Types::TEXT, length: 16777215)]
    private string $body;

    #[ORM\Column(length: 32, enumType: HomeworkSubmissionStatus::class)]
    private HomeworkSubmissionStatus $status;

    #[ORM\Column(name: 'submitted_at', type: Types::DATETIME_IMMUTABLE, nullable: true, options: ['comment' => 'UTC'])]
    private ?\DateTimeImmutable $submittedAt = null;

    #[ORM\Column(name: 'revision_number', type: Types::INTEGER, options: ['unsigned' => true, 'default' => 1])]
    private int $revisionNumber = 1;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct(
        HomeworkRecipient $recipient,
        string $body,
        \DateTimeImmutable $now,
    ) {
        $assignment = $recipient->getAssignment();
        $this->id = new UuidV7();
        $this->assignment = $assignment;
        $this->recipient = $recipient;
        $this->studentUser = $recipient->getStudentUser();
        $this->body = $body;
        $this->status = HomeworkSubmissionStatus::Draft;
        $this->createdAt = UtcInstant::ensure($now);
        $this->updatedAt = UtcInstant::ensure($now);
    }

    /**
     * @internal prefer HomeworkSubmissionManager
     */
    public static function openDraft(HomeworkRecipient $recipient, string $body, \DateTimeImmutable $now): self
    {
        return new self($recipient, $body, $now);
    }

    /**
     * @internal prefer HomeworkSubmissionManager
     */
    public function reviseDraft(string $body, int $expectedRevision, \DateTimeImmutable $now): void
    {
        if (HomeworkSubmissionStatus::Draft !== $this->status) {
            throw HomeworkSubmissionException::alreadySubmitted();
        }
        if ($expectedRevision !== $this->revisionNumber) {
            throw HomeworkSubmissionException::staleRevision();
        }
        $this->body = $body;
        $this->updatedAt = UtcInstant::ensure($now);
        ++$this->revisionNumber;
    }

    /**
     * @internal prefer HomeworkSubmissionManager
     */
    public function markSubmitted(string $body, int $expectedRevision, \DateTimeImmutable $now): void
    {
        if (HomeworkSubmissionStatus::Submitted === $this->status) {
            throw HomeworkSubmissionException::alreadySubmitted();
        }
        if ($expectedRevision !== $this->revisionNumber) {
            throw HomeworkSubmissionException::staleRevision();
        }
        $this->body = $body;
        $this->status = HomeworkSubmissionStatus::Submitted;
        $this->submittedAt = UtcInstant::ensure($now);
        $this->updatedAt = UtcInstant::ensure($now);
        ++$this->revisionNumber;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    #[Ignore]
    public function getAssignment(): HomeworkAssignment
    {
        return $this->assignment;
    }

    #[Ignore]
    public function getRecipient(): HomeworkRecipient
    {
        return $this->recipient;
    }

    #[Ignore]
    public function getStudentUser(): User
    {
        return $this->studentUser;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getStatus(): HomeworkSubmissionStatus
    {
        return $this->status;
    }

    public function getSubmittedAt(): ?\DateTimeImmutable
    {
        return null === $this->submittedAt ? null : UtcInstant::ensure($this->submittedAt);
    }

    public function getRevisionNumber(): int
    {
        return $this->revisionNumber;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return UtcInstant::ensure($this->createdAt);
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return UtcInstant::ensure($this->updatedAt);
    }
}

<?php

declare(strict_types=1);

namespace App\Entity;

use App\Exception\AssessmentAttemptException;
use App\Repository\AssessmentAttemptAnswerRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Encrypted student answer for one attempt item. Never stores plaintext answers.
 */
#[ORM\Entity(repositoryClass: AssessmentAttemptAnswerRepository::class)]
#[ORM\Table(name: 'assessment_attempt_answers')]
#[ORM\UniqueConstraint(name: 'uniq_aaa_attempt_item', columns: ['attempt_id', 'attempt_item_id'])]
#[ORM\Index(name: 'idx_aaa_attempt', columns: ['attempt_id'])]
class AssessmentAttemptAnswer
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'attempt_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AssessmentAttempt $attempt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'attempt_item_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AssessmentAttemptItem $attemptItem;

    #[ORM\Column(name: 'answer_ciphertext', type: Types::BLOB)]
    private mixed $answerCiphertext;

    #[ORM\Column(name: 'answer_nonce', type: Types::BLOB)]
    private mixed $answerNonce;

    #[ORM\Column(name: 'encryption_version')]
    private int $encryptionVersion;

    #[ORM\Column(name: 'client_revision')]
    private int $clientRevision;

    #[ORM\Column(name: 'answered_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $answeredAt;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct(
        AssessmentAttempt $attempt,
        AssessmentAttemptItem $attemptItem,
        string $answerCiphertext,
        string $answerNonce,
        int $encryptionVersion,
        \DateTimeImmutable $answeredAt,
        ?Uuid $id = null,
    ) {
        if (!$attemptItem->getAttempt()->getId()->equals($attempt->getId())) {
            throw AssessmentAttemptException::scopeMismatch();
        }
        $this->assertEncryptedPayload($answerCiphertext, $answerNonce, $encryptionVersion);

        $this->id = $id ?? new UuidV7();
        $this->attempt = $attempt;
        $this->attemptItem = $attemptItem;
        $this->answerCiphertext = $answerCiphertext;
        $this->answerNonce = $answerNonce;
        $this->encryptionVersion = $encryptionVersion;
        $this->clientRevision = 1;
        $this->answeredAt = $answeredAt;
        $this->createdAt = $answeredAt;
        $this->updatedAt = $answeredAt;
    }

    /**
     * @internal prefer AssessmentAttemptManager
     */
    public static function createEncrypted(
        AssessmentAttempt $attempt,
        AssessmentAttemptItem $attemptItem,
        string $answerCiphertext,
        string $answerNonce,
        int $encryptionVersion,
        \DateTimeImmutable $answeredAt,
        ?Uuid $id = null,
    ): self {
        return new self(
            $attempt,
            $attemptItem,
            $answerCiphertext,
            $answerNonce,
            $encryptionVersion,
            $answeredAt,
            $id,
        );
    }

    public function updateEncrypted(
        string $answerCiphertext,
        string $answerNonce,
        int $encryptionVersion,
        int $expectedVersion,
        \DateTimeImmutable $answeredAt,
    ): void {
        $this->bumpRevision($expectedVersion);
        $this->assertEncryptedPayload($answerCiphertext, $answerNonce, $encryptionVersion);
        $this->answerCiphertext = $answerCiphertext;
        $this->answerNonce = $answerNonce;
        $this->encryptionVersion = $encryptionVersion;
        $this->answeredAt = $answeredAt;
        $this->updatedAt = $answeredAt;
    }

    public function bumpRevision(int $expectedVersion): void
    {
        if ($this->clientRevision !== $expectedVersion) {
            throw AssessmentAttemptException::staleAnswerVersion();
        }
        ++$this->clientRevision;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    #[Ignore]
    public function getAttempt(): AssessmentAttempt
    {
        return $this->attempt;
    }

    #[Ignore]
    public function getAttemptItem(): AssessmentAttemptItem
    {
        return $this->attemptItem;
    }

    #[Ignore]
    public function getAnswerCiphertext(): string
    {
        return $this->normalizeBinary($this->answerCiphertext);
    }

    #[Ignore]
    public function getAnswerNonce(): string
    {
        return $this->normalizeBinary($this->answerNonce);
    }

    public function getEncryptionVersion(): int
    {
        return $this->encryptionVersion;
    }

    public function getClientRevision(): int
    {
        return $this->clientRevision;
    }

    public function getAnsweredAt(): \DateTimeImmutable
    {
        return $this->answeredAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function assertEncryptedPayload(string $ciphertext, string $nonce, int $encryptionVersion): void
    {
        if ('' === $ciphertext || '' === $nonce) {
            throw AssessmentAttemptException::invalidInput('Encrypted answer ciphertext and nonce are required.');
        }
        if ($encryptionVersion < 1) {
            throw AssessmentAttemptException::invalidInput('encryptionVersion must be >= 1.');
        }
    }

    private function normalizeBinary(mixed $value): string
    {
        if (\is_string($value)) {
            return $value;
        }
        if (\is_resource($value)) {
            $contents = stream_get_contents($value);
            if (false === $contents) {
                throw AssessmentAttemptException::answerIntegrityFailed();
            }

            return $contents;
        }

        throw AssessmentAttemptException::answerIntegrityFailed();
    }
}

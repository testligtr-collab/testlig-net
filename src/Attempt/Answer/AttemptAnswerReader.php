<?php

declare(strict_types=1);

namespace App\Attempt\Answer;

use App\Entity\AssessmentAttemptAnswer;
use App\Entity\User;
use App\Exception\AssessmentAttemptException;

/**
 * Decrypts attempt answers for authorized internal/test use only (attempt owner).
 * Do not expose from HTTP controllers without an explicit authorization layer.
 */
final class AttemptAnswerReader
{
    public function __construct(
        private readonly AttemptAnswerEncryptor $encryptor,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function readForOwner(AssessmentAttemptAnswer $answer, User $student): array
    {
        if (!$answer->getAttempt()->getUser()->getId()->equals($student->getId())) {
            throw AssessmentAttemptException::unauthorized();
        }

        return $this->encryptor->decrypt(
            $answer->getAnswerCiphertext(),
            $answer->getAnswerNonce(),
            $answer->getEncryptionVersion(),
            $answer->getAttempt()->getId()->toRfc4122(),
            $answer->getAttemptItem()->getId()->toRfc4122(),
            $student->getId()->toRfc4122(),
        );
    }
}

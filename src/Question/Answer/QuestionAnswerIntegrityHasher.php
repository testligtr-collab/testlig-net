<?php

declare(strict_types=1);

namespace App\Question\Answer;

use App\Enum\QuestionType;
use App\Question\Content\QuestionContentCanonicalEncoder;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * HMAC-SHA256 integrity tag for isolated answer keys.
 *
 * Input is canonical JSON of answer payload + answer type + revision id.
 * Never log, audit, or put the resulting HMAC in exception messages.
 */
final class QuestionAnswerIntegrityHasher
{
    public function __construct(
        #[Autowire('%env(QUESTION_ANSWER_INTEGRITY_KEY)%')]
        private readonly string $integrityKey,
        private readonly QuestionContentCanonicalEncoder $encoder,
    ) {
    }

    /**
     * @param array<string, mixed> $answerPayload
     */
    public function hash(array $answerPayload, QuestionType $answerType, Uuid $revisionId): string
    {
        $canonical = $this->encoder->encode([
            'answerPayload' => $answerPayload,
            'answerType' => $answerType->value,
            'revisionId' => $revisionId->toRfc4122(),
        ]);

        return hash_hmac('sha256', $canonical, $this->integrityKey);
    }
}

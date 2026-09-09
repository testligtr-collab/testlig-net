<?php

declare(strict_types=1);

namespace App\Question\Answer;

use App\Enum\QuestionType;
use App\Exception\QuestionException;
use App\Question\Content\QuestionContentCanonicalEncoder;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * HMAC-SHA256 integrity for isolated answer keys (not encryption).
 *
 * Input is canonical JSON of answer payload + answer type + revision id.
 * Never log, audit, or put the resulting HMAC / key in exception messages.
 */
final class QuestionAnswerIntegrityHasher
{
    public const HMAC_HEX_LENGTH = 64;

    private const MIN_KEY_BYTES = 32;

    /**
     * @var list<string>
     */
    private const PRODUCTION_FORBIDDEN_SUBSTRINGS = [
        'change-me',
        'change_me',
        'not-for-production',
        'not_for_production',
        'generate_a_unique',
        'test_question_answer_integrity',
        'ci_question_answer_integrity',
    ];

    private readonly string $integrityKey;

    public function __construct(
        #[Autowire('%env(QUESTION_ANSWER_INTEGRITY_KEY)%')]
        string $integrityKey,
        private readonly QuestionContentCanonicalEncoder $encoder,
        #[Autowire('%kernel.environment%')]
        string $environment,
    ) {
        $this->integrityKey = $this->assertUsableKey($integrityKey, $environment);
    }

    /**
     * @param array<string, mixed> $answerPayload
     */
    public function hash(array $answerPayload, QuestionType $answerType, Uuid $revisionId): string
    {
        return hash_hmac('sha256', $this->canonicalMessage($answerPayload, $answerType, $revisionId), $this->integrityKey);
    }

    /**
     * @param array<string, mixed> $answerPayload
     */
    public function verify(
        string $storedHmac,
        array $answerPayload,
        QuestionType $answerType,
        Uuid $revisionId,
    ): void {
        if ('' === $storedHmac || 1 !== preg_match('/^[0-9a-f]{'.self::HMAC_HEX_LENGTH.'}$/', $storedHmac)) {
            throw QuestionException::answerIntegrityFailed();
        }

        $expected = $this->hash($answerPayload, $answerType, $revisionId);
        if (!hash_equals($expected, $storedHmac)) {
            throw QuestionException::answerIntegrityFailed();
        }
    }

    /**
     * @param array<string, mixed> $answerPayload
     */
    private function canonicalMessage(array $answerPayload, QuestionType $answerType, Uuid $revisionId): string
    {
        return $this->encoder->encode([
            'answerPayload' => $answerPayload,
            'answerType' => $answerType->value,
            'revisionId' => $revisionId->toRfc4122(),
        ]);
    }

    private function assertUsableKey(string $integrityKey, string $environment): string
    {
        if ('' === $integrityKey || '' === trim($integrityKey)) {
            throw new \InvalidArgumentException('QUESTION_ANSWER_INTEGRITY_KEY must not be empty.');
        }

        if (\strlen($integrityKey) < self::MIN_KEY_BYTES) {
            throw new \InvalidArgumentException('QUESTION_ANSWER_INTEGRITY_KEY must be at least 32 bytes.');
        }

        if ('prod' === $environment) {
            $normalized = strtolower($integrityKey);
            foreach (self::PRODUCTION_FORBIDDEN_SUBSTRINGS as $needle) {
                if (str_contains($normalized, $needle)) {
                    throw new \InvalidArgumentException('QUESTION_ANSWER_INTEGRITY_KEY is not valid for production.');
                }
            }
        }

        return $integrityKey;
    }
}

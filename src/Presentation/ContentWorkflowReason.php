<?php

declare(strict_types=1);

namespace App\Presentation;

use App\Exception\LearningContentException;

/**
 * Backend-owned snake_case audit codes. Optional prose never becomes the reason code.
 */
final class ContentWorkflowReason
{
    public const SUBMIT_REVIEW = 'ready_for_review';
    public const RETURN_DRAFT = 'needs_revision';
    public const PUBLISH = 'publish_approved';
    public const ARCHIVE = 'archive_obsolete';
    public const PLACEMENT_CREATE = 'admin_placement_create';
    public const PLACEMENT_PUBLISH = 'admin_placement_publish';
    public const PLACEMENT_ARCHIVE = 'admin_placement_archive';

    private const MAX_NOTE = 500;

    /**
     * @return array{code: string, operator_note: ?string}
     */
    public function resolve(string $defaultCode, mixed $postedNote, mixed $operatorNote): array
    {
        if (1 !== preg_match('/^[a-z][a-z0-9_]{1,63}$/', $defaultCode)) {
            throw LearningContentException::invalidInput('reason_code format is invalid.');
        }

        $postedRaw = \is_string($postedNote) ? $postedNote : '';
        $operatorRaw = \is_string($operatorNote) ? $operatorNote : '';
        $posted = strtolower(trim($postedRaw));
        $postedIsCode = '' !== $posted && 1 === preg_match('/^[a-z][a-z0-9_]{1,63}$/', $posted);
        $noteSource = '' !== trim($operatorRaw) ? $operatorRaw : '';
        if ('' === $noteSource && !$postedIsCode && '' !== $posted) {
            $noteSource = $postedRaw;
        }
        if (!$postedIsCode) {
            $posted = '';
        }

        return [
            'code' => '' === $posted ? $defaultCode : $posted,
            'operator_note' => $this->normalizeNote($noteSource),
        ];
    }

    private function normalizeNote(string $note): ?string
    {
        $length = \strlen($note);
        for ($i = 0; $i < $length; ++$i) {
            $byte = \ord($note[$i]);
            if ($byte < 32 && 9 !== $byte && 10 !== $byte && 13 !== $byte) {
                throw LearningContentException::invalidInput('İşlem notu geçersiz karakter içeriyor.');
            }
        }
        $note = trim(strip_tags($note));
        $note = preg_replace('/\s+/u', ' ', $note) ?? '';
        if ('' === $note) {
            return null;
        }
        if (mb_strlen($note) > self::MAX_NOTE) {
            $note = mb_substr($note, 0, self::MAX_NOTE);
        }

        return $note;
    }
}

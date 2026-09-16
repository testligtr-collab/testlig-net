<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Dead-letter webhook requeue form input (not entity-bound).
 */
final class AdminWebhookDeadLetterRequeueRequest
{
    public const REASON_MANUAL_REQUEUE_REVIEW = 'manual_requeue_review';
    public const REASON_OPERATOR_RETRY_AFTER_FIX = 'operator_retry_after_fix';
    public const REASON_PROVIDER_SIDE_CONFIRMED = 'provider_side_confirmed';

    /**
     * @return list<string>
     */
    public static function allowedReasonCodes(): array
    {
        return [
            self::REASON_MANUAL_REQUEUE_REVIEW,
            self::REASON_OPERATOR_RETRY_AFTER_FIX,
            self::REASON_PROVIDER_SIDE_CONFIRMED,
        ];
    }

    #[Assert\NotBlank(message: 'Yeniden deneme gerekçesi zorunludur.')]
    #[Assert\Choice(callback: 'allowedReasonCodes', message: 'Geçersiz yeniden deneme gerekçesi.')]
    public string $reasonCode = '';

    #[Assert\IsTrue(message: 'Yeniden denemeyi onaylamanız gerekir.')]
    public bool $confirm = false;
}

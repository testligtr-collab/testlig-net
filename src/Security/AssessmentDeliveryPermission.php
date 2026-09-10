<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Assessment delivery authorization attributes for AssessmentDeliveryVoter.
 */
final class AssessmentDeliveryPermission
{
    public const VIEW = 'ASSESSMENT_DELIVERY_VIEW';
    public const CREATE = 'ASSESSMENT_DELIVERY_CREATE';
    public const UPDATE_DRAFT = 'ASSESSMENT_DELIVERY_UPDATE_DRAFT';
    public const ACTIVATE = 'ASSESSMENT_DELIVERY_ACTIVATE';
    public const CLOSE = 'ASSESSMENT_DELIVERY_CLOSE';
    public const CANCEL = 'ASSESSMENT_DELIVERY_CANCEL';
    public const RECIPIENTS_VIEW = 'ASSESSMENT_DELIVERY_RECIPIENTS_VIEW';
    public const RECIPIENTS_MANAGE = 'ASSESSMENT_DELIVERY_RECIPIENTS_MANAGE';
    public const ACCESS_SELF = 'ASSESSMENT_DELIVERY_ACCESS_SELF';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::VIEW,
            self::CREATE,
            self::UPDATE_DRAFT,
            self::ACTIVATE,
            self::CLOSE,
            self::CANCEL,
            self::RECIPIENTS_VIEW,
            self::RECIPIENTS_MANAGE,
            self::ACCESS_SELF,
        ];
    }

    /**
     * @return list<string>
     */
    public static function manageAttributes(): array
    {
        return [
            self::VIEW,
            self::UPDATE_DRAFT,
            self::ACTIVATE,
            self::CLOSE,
            self::CANCEL,
            self::RECIPIENTS_VIEW,
            self::RECIPIENTS_MANAGE,
        ];
    }
}

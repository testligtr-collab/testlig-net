<?php

declare(strict_types=1);

namespace App\Validator\Constraints;

use App\Security\PasswordPolicy;
use Attribute;
use Symfony\Component\Validator\Constraints\Compound;

/**
 * Reusable password policy attribute for DTO properties.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
final class UserPassword extends Compound
{
    public function __construct(
        public readonly string $notBlankMessage = 'Parola zorunludur.',
        mixed $options = null,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct($options, $groups, $payload);
    }

    protected function getConstraints(array $options): array
    {
        return PasswordPolicy::constraints($this->notBlankMessage);
    }
}

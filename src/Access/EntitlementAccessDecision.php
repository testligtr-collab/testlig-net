<?php

declare(strict_types=1);

namespace App\Access;

use App\Enum\EntitlementAccessDecisionReason;
use App\Enum\EntitlementGrantSource;

/**
 * Typed entitlement decision for published learning content / assessment access.
 * Public consumers must not expose internal package/license identifiers to end users.
 */
final readonly class EntitlementAccessDecision
{
    private function __construct(
        public bool $granted,
        public EntitlementAccessDecisionReason $reason,
        public ?EntitlementGrantSource $grantSource,
        public ?string $licenseId,
        public ?string $packageId,
        public ?string $packageVersionId,
        public ?int $packageVersionNumber,
        public \DateTimeImmutable $evaluatedAt,
        public ?\DateTimeImmutable $expiresAt,
        public ?string $resourceType,
        public ?string $resourceId,
    ) {
    }

    public static function allowed(
        EntitlementGrantSource $grantSource,
        \DateTimeImmutable $evaluatedAt,
        ?string $resourceType = null,
        ?string $resourceId = null,
        ?string $licenseId = null,
        ?string $packageId = null,
        ?string $packageVersionId = null,
        ?int $packageVersionNumber = null,
        ?\DateTimeImmutable $expiresAt = null,
    ): self {
        return new self(
            granted: true,
            reason: EntitlementAccessDecisionReason::Allowed,
            grantSource: $grantSource,
            licenseId: $licenseId,
            packageId: $packageId,
            packageVersionId: $packageVersionId,
            packageVersionNumber: $packageVersionNumber,
            evaluatedAt: $evaluatedAt,
            expiresAt: $expiresAt,
            resourceType: $resourceType,
            resourceId: $resourceId,
        );
    }

    public static function denied(
        EntitlementAccessDecisionReason $reason,
        \DateTimeImmutable $evaluatedAt,
        ?string $resourceType = null,
        ?string $resourceId = null,
    ): self {
        return new self(
            granted: false,
            reason: $reason,
            grantSource: null,
            licenseId: null,
            packageId: null,
            packageVersionId: null,
            packageVersionNumber: null,
            evaluatedAt: $evaluatedAt,
            expiresAt: null,
            resourceType: $resourceType,
            resourceId: $resourceId,
        );
    }

    /**
     * Safe message for API/UI — never leaks license/package internals.
     */
    public function publicMessage(): string
    {
        return match ($this->reason) {
            EntitlementAccessDecisionReason::Allowed => 'Access granted.',
            EntitlementAccessDecisionReason::AuthenticationRequired => 'Authentication is required.',
            EntitlementAccessDecisionReason::UserNotActive,
            EntitlementAccessDecisionReason::EmailNotVerified => 'Account is not eligible for access.',
            EntitlementAccessDecisionReason::ResourceNotFound,
            EntitlementAccessDecisionReason::ResourceNotPublished => 'Resource is not available.',
            EntitlementAccessDecisionReason::EntitlementRequired,
            EntitlementAccessDecisionReason::SeatRequired,
            EntitlementAccessDecisionReason::LicenseNotActive,
            EntitlementAccessDecisionReason::LicenseNotStarted,
            EntitlementAccessDecisionReason::LicenseExpired,
            EntitlementAccessDecisionReason::LicenseSuspended,
            EntitlementAccessDecisionReason::LicenseRevoked,
            EntitlementAccessDecisionReason::SeatRevoked => 'A valid entitlement is required.',
            default => 'Access denied.',
        };
    }
}

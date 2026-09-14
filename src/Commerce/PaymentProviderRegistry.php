<?php

declare(strict_types=1);

namespace App\Commerce;

use App\Enum\PaymentProviderEnvironment;
use App\Exception\CommerceException;

/**
 * Resolves provider adapters / webhook verifiers / parsers by stable provider code.
 *
 * Duplicate codes fail at construction. Unknown or disabled providers fail closed.
 */
final class PaymentProviderRegistry
{
    /**
     * @var array<string, PaymentProviderRegistration>
     */
    private array $byCode = [];

    /**
     * @param iterable<PaymentProviderRegistration> $registrations
     */
    public function __construct(iterable $registrations)
    {
        foreach ($registrations as $registration) {
            $code = $registration->getProviderCode();
            if (isset($this->byCode[$code])) {
                throw new \InvalidArgumentException(\sprintf(
                    'Duplicate payment provider registration for "%s".',
                    $code,
                ));
            }
            $this->byCode[$code] = $registration;
        }
    }

    public function has(string $providerCode): bool
    {
        return isset($this->byCode[$providerCode]);
    }

    public function get(string $providerCode): PaymentProviderRegistration
    {
        $registration = $this->byCode[$providerCode] ?? null;
        if (!$registration instanceof PaymentProviderRegistration) {
            throw CommerceException::providerMismatch();
        }
        if (!$registration->enabled) {
            throw CommerceException::providerMismatch();
        }

        return $registration;
    }

    public function getAdapter(string $providerCode): PaymentProviderAdapterInterface
    {
        return $this->get($providerCode)->adapter;
    }

    public function getVerifier(string $providerCode): PaymentWebhookSignatureVerifierInterface
    {
        return $this->get($providerCode)->verifier;
    }

    public function getParser(string $providerCode): PaymentWebhookParserInterface
    {
        return $this->get($providerCode)->parser;
    }

    public function assertEnvironment(string $providerCode, PaymentProviderEnvironment $environment): void
    {
        if ($this->get($providerCode)->getEnvironment() !== $environment) {
            throw CommerceException::providerMismatch();
        }
    }

    /**
     * @return list<string>
     */
    public function enabledProviderCodes(): array
    {
        $codes = [];
        foreach ($this->byCode as $code => $registration) {
            if ($registration->enabled) {
                $codes[] = $code;
            }
        }

        return $codes;
    }
}

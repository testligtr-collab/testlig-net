<?php

declare(strict_types=1);

namespace App\Commerce;

use App\Enum\PaymentProviderEnvironment;

/**
 * One registered payment provider: adapter + webhook verifier + parser (+ optional reconciliation).
 */
final class PaymentProviderRegistration
{
    public function __construct(
        public readonly PaymentProviderAdapterInterface $adapter,
        public readonly PaymentWebhookSignatureVerifierInterface $verifier,
        public readonly PaymentWebhookParserInterface $parser,
        public readonly bool $enabled = true,
        public readonly ?PaymentProviderReconciliationAdapterInterface $reconciliationAdapter = null,
    ) {
        $code = $adapter->getProviderCode();
        if ($verifier->getProviderCode() !== $code || $parser->getProviderCode() !== $code) {
            throw new \InvalidArgumentException('Provider registration codes must match.');
        }
        if ($reconciliationAdapter instanceof PaymentProviderReconciliationAdapterInterface
            && $reconciliationAdapter->getProviderCode() !== $code
        ) {
            throw new \InvalidArgumentException('Reconciliation adapter provider code must match.');
        }
    }

    public function getProviderCode(): string
    {
        return $this->adapter->getProviderCode();
    }

    public function getEnvironment(): PaymentProviderEnvironment
    {
        return $this->adapter->getEnvironment();
    }

    public function getReconciliationAdapter(): ?PaymentProviderReconciliationAdapterInterface
    {
        return $this->reconciliationAdapter;
    }
}

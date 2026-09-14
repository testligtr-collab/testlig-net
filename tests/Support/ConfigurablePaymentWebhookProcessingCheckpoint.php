<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Commerce\PaymentWebhookProcessingCheckpointInterface;
use App\Exception\CommerceException;

/**
 * Deterministic failure injection for webhook recovery tests (test env only).
 */
final class ConfigurablePaymentWebhookProcessingCheckpoint implements PaymentWebhookProcessingCheckpointInterface
{
    private ?string $failOnceAt = null;

    /**
     * @var list<string>
     */
    private array $seen = [];

    public function failOnceAt(string $checkpoint): void
    {
        $this->failOnceAt = $checkpoint;
    }

    public function clear(): void
    {
        $this->failOnceAt = null;
        $this->seen = [];
    }

    /**
     * @return list<string>
     */
    public function getSeen(): array
    {
        return $this->seen;
    }

    public function before(string $checkpoint): void
    {
        $this->seen[] = $checkpoint;
        if ($checkpoint === $this->failOnceAt) {
            $this->failOnceAt = null;
            throw CommerceException::conflict();
        }
    }
}

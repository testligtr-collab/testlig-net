<?php

declare(strict_types=1);

namespace App\LearningContent\Document;

use App\Exception\LearningContentException;

/**
 * R2 settings from the environment. Values are not written to the database or logs.
 */
final class R2Configuration
{
    public function __construct(
        private readonly string $accountId,
        private readonly string $accessKeyId,
        private readonly string $secretAccessKey,
        private readonly string $bucket,
    ) {
    }

    public function assertReady(): void
    {
        if (!$this->isReady()) {
            throw LearningContentException::assetInvalid('R2 yapılandırması eksik.');
        }
    }

    public function isReady(): bool
    {
        return 1 === preg_match('/^[a-f0-9]{32}$/', $this->accountId)
            && '' !== $this->accessKeyId
            && !str_contains($this->accessKeyId, ' ')
            && '' !== $this->secretAccessKey
            && 1 === preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/', $this->bucket);
    }

    public function euEndpoint(): string
    {
        $this->assertReady();

        return 'https://'.$this->accountId.'.eu.r2.cloudflarestorage.com';
    }

    public function bucket(): string
    {
        $this->assertReady();

        return $this->bucket;
    }

    public function accountId(): string
    {
        $this->assertReady();

        return $this->accountId;
    }

    public function accessKeyId(): string
    {
        $this->assertReady();

        return $this->accessKeyId;
    }

    public function secretAccessKey(): string
    {
        $this->assertReady();

        return $this->secretAccessKey;
    }
}

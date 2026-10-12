<?php

declare(strict_types=1);

namespace App\LearningContent\Document;

use App\Exception\LearningContentException;

/**
 * Bunny Storage settings from the environment. Values are not written to the database or logs.
 */
final class BunnyStorageConfiguration
{
    public const ENDPOINT = 'https://storage.bunnycdn.com';

    public function __construct(
        private readonly string $zone,
        private readonly string $accessKey,
        private readonly string $endpoint,
    ) {
    }

    public function assertReady(): void
    {
        if (!$this->isReady()) {
            throw LearningContentException::assetInvalid('Bunny yapılandırması eksik.');
        }
    }

    public function isReady(): bool
    {
        return self::ENDPOINT === $this->endpoint
            && 1 === preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $this->zone)
            && '' !== $this->accessKey
            && !preg_match('/\s/', $this->accessKey);
    }

    public function zone(): string
    {
        $this->assertReady();

        return $this->zone;
    }

    public function accessKey(): string
    {
        $this->assertReady();

        return $this->accessKey;
    }

    public function endpoint(): string
    {
        $this->assertReady();

        return self::ENDPOINT;
    }
}

<?php

declare(strict_types=1);

namespace App\LearningContent\Document;

use App\Exception\LearningContentException;
use Aws\S3\S3Client;
use Psr\Http\Message\StreamInterface;

/**
 * Private Cloudflare R2 objects through the AWS SDK for PHP.
 * The endpoint is the EU jurisdiction hostname. Objects are not made public.
 */
final class CloudflareR2ObjectClient implements R2ObjectClient
{
    private ?S3Client $client = null;

    public function __construct(
        private readonly R2Configuration $configuration,
    ) {
    }

    /**
     * @return array{Bucket: string, Key: string, Body: string, ContentType: string}
     */
    public function privateObjectCommand(string $key, string $bytes): array
    {
        return [
            'Bucket' => $this->configuration->bucket(),
            'Key' => $key,
            'Body' => $bytes,
            'ContentType' => 'application/pdf',
        ];
    }

    public function putPrivate(string $key, string $bytes): void
    {
        try {
            $this->sdk()->putObject($this->privateObjectCommand($key, $bytes));
        } catch (LearningContentException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw LearningContentException::assetInvalid('Doküman kaydedilemedi.');
        }
    }

    public function readPrivate(string $key, int $maxBytes): iterable
    {
        try {
            $result = $this->sdk()->getObject([
                'Bucket' => $this->configuration->bucket(),
                'Key' => $key,
            ]);
        } catch (LearningContentException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw LearningContentException::notFound();
        }

        $length = $result->get('ContentLength');
        if (is_numeric($length) && (int) $length > $maxBytes) {
            throw LearningContentException::notFound();
        }

        $body = $result->get('Body');
        if (!$body instanceof StreamInterface) {
            throw LearningContentException::notFound();
        }

        while (!$body->eof()) {
            $chunk = $body->read(8192);
            if ('' === $chunk) {
                break;
            }
            yield $chunk;
        }
    }

    public function delete(string $key): void
    {
        $this->sdk()->deleteObject([
            'Bucket' => $this->configuration->bucket(),
            'Key' => $key,
        ]);
    }

    private function sdk(): S3Client
    {
        if ($this->client instanceof S3Client) {
            return $this->client;
        }

        $this->client = new S3Client([
            'version' => 'latest',
            'region' => 'auto',
            'endpoint' => $this->configuration->euEndpoint(),
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key' => $this->configuration->accessKeyId(),
                'secret' => $this->configuration->secretAccessKey(),
            ],
        ]);

        return $this->client;
    }
}

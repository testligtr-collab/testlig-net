<?php

declare(strict_types=1);

namespace App\LearningContent\Document;

use App\Exception\LearningContentException;
use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Psr\Http\Message\ResponseInterface;
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
        private readonly ?\Closure $httpHandler = null,
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
        $body = null;
        try {
            $result = $this->sdk()->getObject([
                'Bucket' => $this->configuration->bucket(),
                'Key' => $key,
                '@http' => ['stream' => true],
            ]);
            $candidate = $result->get('Body');
            if (!$candidate instanceof StreamInterface) {
                throw LearningContentException::notFound();
            }
            $body = $candidate;
            $length = $result->get('ContentLength');
            if (is_numeric($length) && (int) $length > $maxBytes) {
                throw LearningContentException::notFound();
            }

            $read = 0;
            while (!$body->eof()) {
                $chunk = $body->read(min(8192, $maxBytes - $read + 1));
                if ('' === $chunk) {
                    break;
                }
                $read += \strlen($chunk);
                if ($read > $maxBytes) {
                    throw LearningContentException::notFound();
                }
                yield $chunk;
            }
        } catch (LearningContentException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $this->closeErrorStream($exception);
            throw LearningContentException::notFound();
        } finally {
            if ($body instanceof StreamInterface) {
                $body->close();
            }
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

        $options = [
            'version' => 'latest',
            'region' => 'auto',
            'endpoint' => $this->configuration->euEndpoint(),
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key' => $this->configuration->accessKeyId(),
                'secret' => $this->configuration->secretAccessKey(),
            ],
        ];
        if ($this->httpHandler instanceof \Closure) {
            $options['http_handler'] = $this->httpHandler;
        }
        $this->client = new S3Client($options);

        return $this->client;
    }

    private function closeErrorStream(\Throwable $exception): void
    {
        if (!$exception instanceof AwsException) {
            return;
        }
        $response = $exception->getResponse();
        if ($response instanceof ResponseInterface) {
            $response->getBody()->close();
        }
    }
}

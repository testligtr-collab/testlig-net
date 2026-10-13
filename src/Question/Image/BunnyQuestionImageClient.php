<?php

declare(strict_types=1);

namespace App\Question\Image;

use App\Exception\QuestionException;
use App\LearningContent\Document\BunnyStorageConfiguration;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Private question images on Bunny Storage. This is not the PDF object contract.
 */
final class BunnyQuestionImageClient implements QuestionImageByteStore
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly BunnyStorageConfiguration $configuration,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function write(string $storageKey, string $bytes, string $mimeType, string $sha256): void
    {
        $this->configuration->assertReady();
        if (!\in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw QuestionException::imageRejected('question_image_type');
        }
        if (!hash_equals(hash('sha256', $bytes), $sha256)) {
            throw QuestionException::imageRejected('question_image_store');
        }
        $response = null;
        try {
            $response = $this->request('PUT', $this->objectUrl($storageKey), [
                'AccessKey' => $this->configuration->accessKey(),
                'Content-Type' => $mimeType,
                'Checksum' => strtoupper($sha256),
            ], $bytes);
            if (201 !== $response->getStatusCode()) {
                throw QuestionException::imageRejected('question_image_store');
            }
        } catch (QuestionException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw QuestionException::imageRejected('question_image_store');
        } finally {
            $this->close($response);
        }
    }

    public function deleteQuietly(string $storageKey): void
    {
        $response = null;
        try {
            $response = $this->request('DELETE', $this->objectUrl($storageKey), [
                'AccessKey' => $this->configuration->accessKey(),
            ], null);
            if (200 !== $response->getStatusCode()) {
                $this->logger->error('question_image.cleanup_failed');
            }
        } catch (\Throwable) {
            $this->logger->error('question_image.cleanup_failed');
        } finally {
            $this->close($response);
        }
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(string $method, string $url, array $headers, ?string $body): ResponseInterface
    {
        $options = [
            'headers' => $headers,
            'max_redirects' => 0,
            'buffer' => false,
        ];
        if (null !== $body) {
            $options['body'] = $body;
        }

        return $this->httpClient->request($method, $url, $options);
    }

    private function objectUrl(string $storageKey): string
    {
        QuestionImageObjectKey::assert($storageKey);
        $this->configuration->assertReady();
        $zone = $this->configuration->zone();
        $url = BunnyStorageConfiguration::ENDPOINT.'/'.$zone.'/'.$storageKey;
        $parts = parse_url($url);
        $path = \is_array($parts) ? (string) ($parts['path'] ?? '') : '';
        $prefix = '/'.$zone.'/';
        if (
            !\is_array($parts)
            || 'https' !== ($parts['scheme'] ?? '')
            || 'storage.bunnycdn.com' !== ($parts['host'] ?? '')
            || isset($parts['query'])
            || isset($parts['user'])
            || !str_starts_with($path, $prefix)
            || substr($path, \strlen($prefix)) !== $storageKey
        ) {
            throw QuestionException::imageRejected('question_image_store');
        }

        return $url;
    }

    private function close(?ResponseInterface $response): void
    {
        if (!$response instanceof ResponseInterface) {
            return;
        }
        try {
            $response->cancel();
        } catch (\Throwable) {
        }
    }
}

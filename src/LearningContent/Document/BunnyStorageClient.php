<?php

declare(strict_types=1);

namespace App\LearningContent\Document;

use App\Exception\LearningContentException;
use Symfony\Component\HttpClient\Response\StreamableInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Private Bunny Storage files through the documented HTTPS API.
 * Requests stay on https://storage.bunnycdn.com and do not follow redirects.
 */
final class BunnyStorageClient
{
    public const PREFIX = 'pdf';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly BunnyStorageConfiguration $configuration,
    ) {
    }

    public function putPrivate(string $key, string $bytes): void
    {
        $response = null;
        try {
            $response = $this->request('PUT', $this->objectUrl($key), [
                'AccessKey' => $this->configuration->accessKey(),
                'Content-Type' => 'application/pdf',
                'Checksum' => strtoupper(hash('sha256', $bytes)),
            ], $bytes);
            if (201 !== $response->getStatusCode()) {
                throw LearningContentException::assetInvalid('Doküman kaydedilemedi.');
            }
        } catch (LearningContentException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw LearningContentException::assetInvalid('Doküman kaydedilemedi.');
        } finally {
            $this->closeResponse($response);
        }
    }

    /**
     * @return iterable<string>
     */
    public function readPrivate(string $key, int $maxBytes): iterable
    {
        $response = null;
        $stream = null;
        try {
            $response = $this->request('GET', $this->objectUrl($key), [
                'AccessKey' => $this->configuration->accessKey(),
            ], null);
            if (200 !== $response->getStatusCode()) {
                throw LearningContentException::notFound();
            }
            $headers = $response->getHeaders(false);
            $length = $headers['content-length'][0] ?? null;
            if (is_numeric($length) && (int) $length > $maxBytes) {
                throw LearningContentException::notFound();
            }
            if (!$response instanceof StreamableInterface) {
                throw LearningContentException::notFound();
            }
            $stream = $response->toStream(false);
            if (!\is_resource($stream)) {
                throw LearningContentException::notFound();
            }
            $read = 0;
            while (!feof($stream)) {
                $remaining = $maxBytes - $read + 1;
                if ($remaining < 1) {
                    throw LearningContentException::notFound();
                }
                $chunk = fread($stream, min(8192, $remaining));
                if (!\is_string($chunk) || '' === $chunk) {
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
        } catch (\Throwable) {
            throw LearningContentException::notFound();
        } finally {
            if (\is_resource($stream)) {
                fclose($stream);
            }
            $this->closeResponse($response);
        }
    }

    public function delete(string $key): void
    {
        $response = null;
        try {
            $response = $this->request('DELETE', $this->objectUrl($key), [
                'AccessKey' => $this->configuration->accessKey(),
            ], null);
            if (200 !== $response->getStatusCode()) {
                throw LearningContentException::assetInvalid('Doküman kaydedilemedi.');
            }
        } catch (LearningContentException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw LearningContentException::assetInvalid('Doküman kaydedilemedi.');
        } finally {
            $this->closeResponse($response);
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

    private function objectUrl(string $key): string
    {
        $this->configuration->assertReady();
        if (1 !== preg_match('/^[a-f0-9]{32}$/', $key)) {
            throw LearningContentException::notFound();
        }
        $zone = $this->configuration->zone();
        $url = BunnyStorageConfiguration::ENDPOINT.'/'.$zone.'/'.self::PREFIX.'/'.$key;
        $parts = parse_url($url);
        $path = \is_array($parts) ? (string) ($parts['path'] ?? '') : '';
        $segments = explode('/', trim($path, '/'));
        if (
            !\is_array($parts)
            || 'https' !== ($parts['scheme'] ?? '')
            || 'storage.bunnycdn.com' !== ($parts['host'] ?? '')
            || isset($parts['query'])
            || isset($parts['user'])
            || 3 !== \count($segments)
            || $segments[0] !== $zone
            || self::PREFIX !== $segments[1]
            || $segments[2] !== $key
        ) {
            throw LearningContentException::assetInvalid('Doküman kaydedilemedi.');
        }

        return $url;
    }

    private function closeResponse(?ResponseInterface $response): void
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

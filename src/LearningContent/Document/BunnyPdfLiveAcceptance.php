<?php

declare(strict_types=1);

namespace App\LearningContent\Document;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * One isolated Bunny PDF check. It does not boot the Symfony kernel or open a database.
 * The caller supplies the storage zone password only through the process environment.
 */
final class BunnyPdfLiveAcceptance
{
    public const ZONE = 'testlig-pdf-test';

    public function __construct(
        private readonly BunnyLearningDocumentStorage $storage,
        private readonly BunnyStorageClient $client,
    ) {
    }

    /**
     * @return array{result: string, range: int, anonymous: int, cleanup: int}
     */
    public function run(): array
    {
        $pdf = "%PDF-1.4\n%testlig-bunny-live\n";
        $sha = hash('sha256', $pdf);
        $key = null;
        $failure = null;
        $range = 0;
        $anonymous = 0;
        try {
            $key = $this->storage->write($pdf);
            $read = $this->storage->read($key, $sha);
            if (!hash_equals($sha, hash('sha256', $read))) {
                $failure = 'sha256';
            }
            $path = $this->storage->absolutePath($key);
            $response = new BinaryFileResponse($path);
            $response->prepare(Request::create('/pdf', 'GET', server: ['HTTP_RANGE' => 'bytes=0-4']));
            $range = $response->getStatusCode();
            ob_start();
            $response->sendContent();
            $prefix = ob_get_clean();
            if (206 !== $range || '%PDF-' !== $prefix) {
                $failure ??= 'range';
            }
            $anonymous = $this->client->anonymousGetStatus($key);
            if (401 !== $anonymous) {
                $failure ??= 'anonymous';
            }
        } catch (\Throwable) {
            $failure ??= 'step';
        } finally {
            if (\is_string($key)) {
                try {
                    $this->client->delete($key);
                } catch (\Throwable) {
                }
                $this->storage->releaseVerifiedCopies();
            }
        }

        $cleanup = 0;
        if (\is_string($key)) {
            try {
                $cleanup = $this->client->authorizedGetStatus($key);
            } catch (\Throwable) {
                $cleanup = 0;
            }
        }

        return [
            'result' => $this->result($failure, $key, $cleanup),
            'range' => $range,
            'anonymous' => $anonymous,
            'cleanup' => $cleanup,
        ];
    }

    private function result(?string $failure, ?string $key, int $cleanup): string
    {
        if (\is_string($key) && 404 !== $cleanup) {
            return 'cleanup_unverified';
        }
        if (null !== $failure || !\is_string($key)) {
            return 'failed';
        }

        return 'accepted';
    }
}

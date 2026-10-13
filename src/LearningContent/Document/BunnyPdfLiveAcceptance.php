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
        private readonly ?\Closure $releaseLocal = null,
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
        $localPath = null;
        $failure = null;
        $range = 0;
        $anonymous = 0;
        $localVerified = true;
        try {
            $key = $this->storage->write($pdf);
            $read = $this->storage->read($key, $sha);
            if (!hash_equals($sha, hash('sha256', $read))) {
                $failure = 'sha256';
            } else {
                $localPath = $this->storage->absolutePath($key);
                $sample = $this->rangeSample($localPath);
                $range = $sample['status'];
                if (206 !== $range || '%PDF-' !== $sample['prefix']) {
                    $failure = 'range';
                } else {
                    $anonymous = $this->client->anonymousGetStatus($key);
                    if (401 !== $anonymous) {
                        $failure = 'anonymous';
                    }
                }
            }
        } catch (\Throwable) {
            $failure = 'step';
        } finally {
            if (\is_string($key)) {
                try {
                    $this->client->delete($key);
                } catch (\Throwable) {
                }
            }
            if (\is_string($key) || \is_string($localPath)) {
                try {
                    $this->releaseLocal();
                } catch (\Throwable) {
                    $localVerified = false;
                }
            }
            if (\is_string($localPath)) {
                try {
                    if (is_file($localPath)) {
                        $localVerified = false;
                    }
                } catch (\Throwable) {
                    $localVerified = false;
                }
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
            'result' => $this->result($failure, $key, $cleanup, $localVerified),
            'range' => $range,
            'anonymous' => $anonymous,
            'cleanup' => $cleanup,
        ];
    }

    /**
     * @return array{status: int, prefix: string}
     */
    public function rangeSample(string $path): array
    {
        $level = ob_get_level();
        ob_start();
        try {
            $response = new BinaryFileResponse($path);
            $response->prepare(Request::create('/pdf', 'GET', server: ['HTTP_RANGE' => 'bytes=0-4']));
            $response->sendContent();
            $prefix = ob_get_clean();
        } catch (\Throwable $exception) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $exception;
        }
        if (ob_get_level() > $level) {
            ob_end_clean();
        }

        return [
            'status' => $response->getStatusCode(),
            'prefix' => \is_string($prefix) ? $prefix : '',
        ];
    }

    private function releaseLocal(): void
    {
        if ($this->releaseLocal instanceof \Closure) {
            ($this->releaseLocal)();

            return;
        }
        $this->storage->releaseVerifiedCopies();
    }

    private function result(?string $failure, ?string $key, int $cleanup, bool $localVerified): string
    {
        if (\is_string($key) && (404 !== $cleanup || !$localVerified)) {
            return 'cleanup_unverified';
        }
        if (null !== $failure || !\is_string($key)) {
            return 'failed';
        }

        return 'accepted';
    }
}

<?php

declare(strict_types=1);

namespace App\Homework\Pdf;

use App\Question\Image\Scan\ClamavLocalDatabaseVersions;
use App\Question\Image\Scan\ClamavPublishedDatabaseVersion;
use App\Question\Image\Scan\ClamdInstreamTransport;
use App\Question\Image\Scan\UnixClamdSocketPath;

/**
 * Fail-closed runtime readiness for homework PDF uploads.
 * Requires explicit readiness opt-in, safe non-release storage roots, valid Unix socket,
 * readable CVD/CLD files, live clamd PONG, and loaded daily version matching disk.
 */
final class RuntimeHomeworkPdfScanReadiness implements HomeworkPdfScanReadiness
{
    public const CLAMD_PROBE_TIMEOUT_SECONDS = 1.5;
    public const CLAMD_PROBE_MAX_RESPONSE_BYTES = 256;

    public function __construct(
        private readonly bool $scanReadyFlag,
        private readonly string $socketPath,
        private readonly HomeworkPdfStoragePaths $storagePaths,
        private readonly ClamavLocalDatabaseVersions $localDatabaseVersions,
        private readonly ClamdInstreamTransport $clamdTransport,
    ) {
    }

    public function isReady(): bool
    {
        if (!$this->scanReadyFlag) {
            return false;
        }
        if (!$this->storagePaths->isSafeDurableDirectoryReady() || !$this->storagePaths->isSafeQuarantineDirectoryReady()) {
            return false;
        }
        try {
            UnixClamdSocketPath::assert($this->socketPath);
        } catch (\Throwable) {
            return false;
        }
        $localVersions = $this->localDatabaseVersions->read();
        if (null === $localVersions) {
            return false;
        }
        try {
            $ping = $this->clamdTransport->exchange(
                ["nPING\n"],
                self::CLAMD_PROBE_TIMEOUT_SECONDS,
                self::CLAMD_PROBE_MAX_RESPONSE_BYTES,
            );
            if ("PONG\n" !== $ping) {
                return false;
            }
            $versionReply = $this->clamdTransport->exchange(
                ["nVERSION\n"],
                self::CLAMD_PROBE_TIMEOUT_SECONDS,
                self::CLAMD_PROBE_MAX_RESPONSE_BYTES,
            );
        } catch (\Throwable) {
            return false;
        }
        if (1 !== preg_match(
            '/^ClamAV [0-9]+(?:\.[0-9]+){1,3}\/(0|[1-9][0-9]*)\/[A-Za-z]{3} [A-Za-z]{3} [ 0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2} [0-9]{4}\n$/',
            $versionReply,
            $matches,
        )) {
            return false;
        }
        $loadedDaily = ClamavPublishedDatabaseVersion::canonical($matches[1]);

        return null !== $loadedDaily && $loadedDaily === $localVersions['daily'];
    }
}

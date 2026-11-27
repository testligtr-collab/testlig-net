<?php

declare(strict_types=1);

namespace App\Homework\Pdf;

use App\LearningContent\Document\PdfDocumentInspector;
use App\Question\Image\Scan\ClamavLocalDatabaseVersions;
use App\Question\Image\Scan\ClamavPublishedDatabaseVersion;
use App\Question\Image\Scan\ClamdInstreamTransport;
use App\Question\Image\Scan\UnixClamdSocketPath;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;

/**
 * Fail-closed runtime readiness for homework PDF uploads.
 * Requires explicit readiness opt-in, safe non-release storage roots, an operator-declared clamd StreamMaxLength that
 * covers the application PDF limit, valid Unix socket, readable CVD/CLD files, live clamd PONG, a loaded daily version
 * matching disk, and a signature build date that is not older than the freshness threshold.
 *
 * The declared StreamMaxLength is a configuration mirror of clamd.conf, not a live measurement: the live value (and
 * OLS/lsphp request limits) still needs separate evidence before the upload gate may be opened.
 */
final class RuntimeHomeworkPdfScanReadiness implements HomeworkPdfScanReadiness
{
    public const CLAMD_PROBE_TIMEOUT_SECONDS = 1.5;
    public const CLAMD_PROBE_MAX_RESPONSE_BYTES = 256;
    /** Signature builds older than this are treated as stale (freshclam normally publishes daily). */
    public const MAX_SIGNATURE_AGE_SECONDS = 259_200;
    /** A build date this far ahead of the server clock is not trusted either. */
    public const MAX_SIGNATURE_FUTURE_SKEW_SECONDS = 3_600;

    public function __construct(
        private readonly bool $scanReadyFlag,
        private readonly string $socketPath,
        private readonly HomeworkPdfStoragePaths $storagePaths,
        private readonly ClamavLocalDatabaseVersions $localDatabaseVersions,
        private readonly ClamdInstreamTransport $clamdTransport,
        private readonly int $clamdStreamMaxBytes = 0,
        private readonly ?ClockInterface $clock = null,
    ) {
    }

    public function isReady(): bool
    {
        if (!$this->scanReadyFlag) {
            return false;
        }
        if ($this->clamdStreamMaxBytes < PdfDocumentInspector::MAX_BYTES) {
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
            '/^ClamAV [0-9]+(?:\.[0-9]+){1,3}\/(0|[1-9][0-9]*)\/([A-Za-z]{3} [A-Za-z]{3} [ 0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2} [0-9]{4})\n$/',
            $versionReply,
            $matches,
        )) {
            return false;
        }
        $loadedDaily = ClamavPublishedDatabaseVersion::canonical($matches[1]);
        if (null === $loadedDaily || $loadedDaily !== $localVersions['daily']) {
            return false;
        }

        return $this->isSignatureFresh($matches[2]);
    }

    private function isSignatureFresh(string $buildDate): bool
    {
        $normalized = (string) preg_replace('/\s+/', ' ', trim($buildDate));
        $built = \DateTimeImmutable::createFromFormat('!D M j H:i:s Y', $normalized, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$built instanceof \DateTimeImmutable || (\is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return false;
        }
        $age = ($this->clock ?? Clock::get())->now()->getTimestamp() - $built->getTimestamp();

        return $age <= self::MAX_SIGNATURE_AGE_SECONDS && $age >= -self::MAX_SIGNATURE_FUTURE_SKEW_SECONDS;
    }
}

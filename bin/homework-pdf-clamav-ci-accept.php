<?php

declare(strict_types=1);

/**
 * Isolated runner acceptance for X01 homework PDF + real clamd (Unix INSTREAM).
 * Test-only open gate. Does not change production ClosedHomeworkPdfScanReadiness or upload flags.
 * Emits key=value lines only — never sample bodies, paths under work, or raw scanner replies.
 */

use App\Homework\Pdf\ClamdHomeworkPdfScanAdapter;
use App\Homework\Pdf\HomeworkPdfQuarantine;
use App\Homework\Pdf\HomeworkPdfQuarantineFile;
use App\Homework\Pdf\HomeworkPdfScanException;
use App\Homework\Pdf\HomeworkPdfScanPipeline;
use App\Homework\Pdf\HomeworkPdfScanReadiness;
use App\Homework\Pdf\HomeworkPdfScanVerdict;
use App\Homework\Pdf\HomeworkPdfUploadGate;
use App\LearningContent\Document\PdfDocumentInspector;
use App\Question\Image\Scan\ClamavPublishedDatabaseVersion;
use App\Question\Image\Scan\UnixClamdTransport;

if (\PHP_SAPI !== 'cli') {
    fwrite(\STDERR, "accept_error=cli_only\n");
    exit(1);
}

$root = dirname(__DIR__);
$autoload = $root.'/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(\STDERR, "accept_error=autoload_missing\n");
    exit(1);
}
require $autoload;

$socket = getenv('HOMEWORK_PDF_CLAMAV_SOCKET') ?: '';
$dbDir = getenv('HOMEWORK_PDF_CLAMAV_DB') ?: '';
$workRoot = getenv('HOMEWORK_PDF_CLAMAV_WORK') ?: '';
if ($socket === '' || $dbDir === '' || $workRoot === '') {
    emit('accept_error=env_missing');
    exit(1);
}

$failed = false;
$quarantineRoot = $workRoot.'/quarantine';
if (!is_dir($quarantineRoot) && !mkdir($quarantineRoot, 0700, true) && !is_dir($quarantineRoot)) {
    emit('accept_error=quarantine_root');
    exit(1);
}

try {
    $signatureOk = runSignatureGate($dbDir);
    $pingOk = runClamdPing($socket);
    if ($signatureOk && $pingOk) {
        emit('scenario_signature=pass');
    } else {
        emit('scenario_signature=fail');
        $failed = true;
    }
    $failed = !runCleanPdf($socket, $quarantineRoot) || $failed;
    $failed = !runNearLimitPdf($socket, $quarantineRoot) || $failed;
    $failed = !runAvSampleAdapter($socket, $quarantineRoot) || $failed;
    $failed = !runMissingSocket($quarantineRoot) || $failed;
} finally {
    cleanupTree($quarantineRoot);
    emit('quarantine_final_empty='.(is_dir($quarantineRoot) && [] === (glob($quarantineRoot.'/*') ?: []) ? 'yes' : 'no'));
}

emit($failed ? 'summary_accept=failed' : 'summary_accept=ok');
exit($failed ? 1 : 0);

function emit(string $line): void
{
    echo $line."\n";
}

function runSignatureGate(string $dbDir): bool
{
    // Criterion (documented): disk main/daily/bytecode integer versions must equal
    // the consensus published TXT from current.cvd.clamav.net. freshclam download
    // success alone is not enough; clamd must also answer PING after loading that DB.
    $records = @dns_get_record('current.cvd.clamav.net', \DNS_TXT);
    if (!\is_array($records) || [] === $records) {
        emit('signature_dns=unavailable');
        emit('scenario_signature=fail');

        return false;
    }
    $published = ClamavPublishedDatabaseVersion::published($records);
    if ('ok' !== $published['status'] || !\is_array($published['versions'])) {
        emit('signature_dns='.$published['status']);
        emit('scenario_signature=fail');

        return false;
    }
    emit('signature_dns=ok');

    $local = [];
    foreach (['main', 'daily', 'bytecode'] as $name) {
        $version = signatureVersionFromDisk($dbDir, $name);
        if (null === $version) {
            emit('signature_disk=missing_'.$name);
            emit('scenario_signature=fail');

            return false;
        }
        $local[$name] = $version;
    }
    if ($local !== $published['versions']) {
        emit('signature_disk=mismatch');
        emit('scenario_signature=fail');

        return false;
    }
    emit('signature_disk=match');

    return true;
}

function runClamdPing(string $socket): bool
{
    // Loaded-daemon liveness after DB files matched DNS. Not a substitute for disk↔DNS match.
    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client('unix://'.$socket, $errno, $errstr, 5.0);
    if (!\is_resource($fp)) {
        emit('clamd_ping=failed');

        return false;
    }
    try {
        fwrite($fp, "nPING\n");
        $reply = stream_get_contents($fp, 32);
        $ok = \is_string($reply) && str_starts_with($reply, 'PONG');
        emit($ok ? 'clamd_ping=pong' : 'clamd_ping=unexpected');

        return $ok;
    } finally {
        fclose($fp);
    }
}

function signatureVersionFromDisk(string $directory, string $name): ?int
{
    foreach (['cvd', 'cld'] as $extension) {
        $path = $directory.'/'.$name.'.'.$extension;
        if (!is_file($path)) {
            continue;
        }
        $info = shell_exec('sigtool --info '.escapeshellarg($path).' 2>/dev/null');
        if (!\is_string($info) || 1 !== preg_match('/^Version: ([0-9]+)$/m', $info, $matches)) {
            return null;
        }

        return ClamavPublishedDatabaseVersion::canonical($matches[1]);
    }

    return null;
}

function openPipeline(string $socket, string $quarantineRoot): HomeworkPdfScanPipeline
{
    $ready = new class implements HomeworkPdfScanReadiness {
        public function isReady(): bool
        {
            return true;
        }
    };

    return new HomeworkPdfScanPipeline(
        new HomeworkPdfUploadGate(true, $ready),
        new PdfDocumentInspector(),
        new HomeworkPdfQuarantine($quarantineRoot),
        ClamdHomeworkPdfScanAdapter::withTransport(new UnixClamdTransport($socket)),
    );
}

function quarantineEmpty(string $quarantineRoot): bool
{
    return [] === (glob($quarantineRoot.\DIRECTORY_SEPARATOR.'*') ?: []);
}

function runCleanPdf(string $socket, string $quarantineRoot): bool
{
    $pipeline = openPipeline($socket, $quarantineRoot);
    try {
        $staging = $pipeline->stageCleanCandidate(minimalPdf(), 'clean-sample.pdf');
        $ok = true;
        emit('scenario_clean_verdict=clean');
        $staging->abandon();
        if (!quarantineEmpty($quarantineRoot)) {
            emit('scenario_clean_quarantine=dirty');
            $ok = false;
        } else {
            emit('scenario_clean_quarantine=empty');
        }
        emit($ok ? 'scenario_clean=pass' : 'scenario_clean=fail');

        return $ok;
    } catch (HomeworkPdfScanException $exception) {
        emit('scenario_clean_verdict='.$exception->getMessage());
        emit('scenario_clean_quarantine='.(quarantineEmpty($quarantineRoot) ? 'empty' : 'dirty'));
        emit('scenario_clean=fail');

        return false;
    }
}

function runNearLimitPdf(string $socket, string $quarantineRoot): bool
{
    $appMax = PdfDocumentInspector::MAX_BYTES;
    $bytes = nearLimitPdf($appMax);
    $byteCount = \strlen($bytes);
    emit('scenario_near_limit_bytes='.$byteCount);
    emit('scenario_near_limit_app_max='.$appMax);

    $confStream = getenv('HOMEWORK_PDF_CLAMAV_STREAM_MAX_BYTES') ?: '';
    $confFile = getenv('HOMEWORK_PDF_CLAMAV_MAX_FILE_BYTES') ?: '';
    emit('scenario_near_limit_stream_max_bytes='.($confStream !== '' ? $confStream : 'unset'));
    emit('scenario_near_limit_max_file_bytes='.($confFile !== '' ? $confFile : 'unset'));

    $pipeline = openPipeline($socket, $quarantineRoot);
    $transferComplete = 'no';
    $verdictLabel = 'error';
    try {
        $staging = $pipeline->stageCleanCandidate($bytes, 'near-limit.pdf');
        $transferComplete = 'yes';
        $verdictLabel = 'clean';
        $staging->abandon();
    } catch (HomeworkPdfScanException $exception) {
        $verdictLabel = $exception->getMessage();
        // Size-limit from clamd after a full client send still means transfer reached the daemon path;
        // connection failures do not.
        if (str_starts_with($exception->getMessage(), 'homework_pdf_scan_')) {
            $transferComplete = 'yes';
        }
    }
    emit('scenario_near_limit_transfer_complete='.$transferComplete);
    emit('scenario_near_limit_verdict='.$verdictLabel);
    emit('scenario_near_limit_quarantine='.(quarantineEmpty($quarantineRoot) ? 'empty' : 'dirty'));

    $streamOk = $confStream !== '' && ctype_digit($confStream) && (int) $confStream >= $byteCount;
    $fileOk = $confFile !== '' && ctype_digit($confFile) && (int) $confFile >= $byteCount;
    $coverage = 'unproven';
    if ($transferComplete === 'yes' && $verdictLabel === 'clean' && $streamOk && $fileOk) {
        // clean+limits-above-size still cannot alone prove no internal truncation; mark full only
        // when a trailing AV marker is also detected at the same size class (adapter probe).
        $coverage = probeTrailingDetection($socket, $byteCount) ? 'full' : 'unproven';
    }
    emit('scenario_near_limit_coverage='.$coverage);
    if ('full' !== $coverage) {
        emit('scenario_near_limit_note=tam_kapsam_dogrulanmadi');
    }

    // Pass requires completed near-limit PDF INSTREAM clean + empty quarantine; coverage may stay unproven.
    $ok = 'yes' === $transferComplete && 'clean' === $verdictLabel && quarantineEmpty($quarantineRoot);
    emit($ok ? 'scenario_near_limit=pass' : 'scenario_near_limit=fail');

    return $ok;
}

function probeTrailingDetection(string $socket, int $targetSize): bool
{
    $marker = standardAvSampleBytes();
    if ($targetSize <= \strlen($marker) + 64) {
        return false;
    }
    $payload = str_repeat('A', $targetSize - \strlen($marker)).$marker;
    $adapter = ClamdHomeworkPdfScanAdapter::withTransport(new UnixClamdTransport($socket));
    $file = lockedTempFile($payload);
    try {
        return HomeworkPdfScanVerdict::Infected === $adapter->scan($file);
    } finally {
        $file->deleteQuietly();
    }
}

function runAvSampleAdapter(string $socket, string $quarantineRoot): bool
{
    // Outside PdfDocumentInspector: raw adapter scan of the standard harmless AV sample.
    $adapter = ClamdHomeworkPdfScanAdapter::withTransport(new UnixClamdTransport($socket));
    $file = lockedTempFile(standardAvSampleBytes());
    try {
        $verdict = $adapter->scan($file);
        $code = $verdict->value;
        emit('scenario_av_sample_verdict='.$code);
        // Never emit raw clamd reply or sample body.
        $ok = HomeworkPdfScanVerdict::Infected === $verdict;
        emit('scenario_av_sample_quarantine='.(quarantineEmpty($quarantineRoot) ? 'empty' : 'dirty'));
        emit($ok ? 'scenario_av_sample=pass' : 'scenario_av_sample=fail');

        return $ok;
    } finally {
        $file->deleteQuietly();
    }
}

function runMissingSocket(string $quarantineRoot): bool
{
    $missing = $quarantineRoot.'/missing-clamd.sock';
    $pipeline = openPipeline($missing, $quarantineRoot);
    try {
        $pipeline->stageCleanCandidate(minimalPdf(), 'missing-socket.pdf');
        emit('scenario_missing_socket_verdict=unexpected_clean');
        emit('scenario_missing_socket_quarantine='.(quarantineEmpty($quarantineRoot) ? 'empty' : 'dirty'));
        emit('scenario_missing_socket=fail');

        return false;
    } catch (HomeworkPdfScanException $exception) {
        $ok = 'homework_pdf_scan_unavailable' === $exception->getMessage() && quarantineEmpty($quarantineRoot);
        emit('scenario_missing_socket_verdict='.$exception->getMessage());
        emit('scenario_missing_socket_quarantine='.(quarantineEmpty($quarantineRoot) ? 'empty' : 'dirty'));
        emit($ok ? 'scenario_missing_socket=pass' : 'scenario_missing_socket=fail');

        return $ok;
    }
}

function minimalPdf(): string
{
    return "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
}

function nearLimitPdf(int $maxBytes): string
{
    $prefix = "%PDF-1.4\n";
    $suffix = "\n%%EOF\n";
    $fill = $maxBytes - \strlen($prefix) - \strlen($suffix);
    if ($fill < 1) {
        throw new \RuntimeException('near_limit_size');
    }

    return $prefix.str_repeat('0', $fill).$suffix;
}

function standardAvSampleBytes(): string
{
    // Standard harmless antivirus test file body (public test string). Not logged.
    return 'X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';
}

function lockedTempFile(string $bytes): HomeworkPdfQuarantineFile
{
    $path = tempnam(sys_get_temp_dir(), 'hwpdfci');
    if (!\is_string($path)) {
        throw new \RuntimeException('temp');
    }
    file_put_contents($path, $bytes);
    $handle = fopen($path, 'r');
    if (!\is_resource($handle)) {
        @unlink($path);
        throw new \RuntimeException('open');
    }
    if (!flock($handle, \LOCK_EX | \LOCK_NB)) {
        fclose($handle);
        @unlink($path);
        throw new \RuntimeException('lock');
    }

    return new HomeworkPdfQuarantineFile($path, $handle, \strlen($bytes), hash('sha256', $bytes));
}

function cleanupTree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (glob($dir.\DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
        if (is_file($path) || is_link($path)) {
            @unlink($path);
        }
    }
}

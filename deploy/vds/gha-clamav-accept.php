<?php

declare(strict_types=1);

use App\Question\Image\Scan\ClamdInstreamClient;
use App\Question\Image\Scan\QuestionImageScanVerdict;
use App\Question\Image\Scan\UnixClamdTransport;

if ($argc < 2) {
    fwrite(\STDERR, "accept_usage=invalid\n");
    exit(1);
}

if ('dns' === $argv[1]) {
    exit(checkSignatures($argv[2] ?? ''));
}
if ('scan' === $argv[1]) {
    exit(runScans($argv[2] ?? '', $argv[3] ?? '', $argv[4] ?? ''));
}

fwrite(\STDERR, "accept_usage=invalid\n");
exit(1);

function checkSignatures(string $databaseDirectory): int
{
    if (1 !== preg_match('#^/(?:[A-Za-z0-9._-]{1,64}/){0,16}[A-Za-z0-9._-]{1,64}$#', $databaseDirectory)) {
        echo "signature_dns=unreadable\n";

        return 1;
    }
    $records = dns_get_record('current.cvd.clamav.net', \DNS_TXT);
    if (!is_array($records) || [] === $records) {
        echo "signature_dns=unavailable\n";

        return 1;
    }
    $published = '';
    foreach ($records as $record) {
        if (isset($record['txt']) && is_string($record['txt'])) {
            $published .= ' '.$record['txt'];
        }
    }
    if ('' === trim($published)) {
        echo "signature_dns=unavailable\n";

        return 1;
    }
    $versions = [];
    foreach (['main', 'daily', 'bytecode'] as $name) {
        $version = signatureVersion($databaseDirectory, $name);
        if (null === $version) {
            echo "signature_dns=missing\n";

            return 1;
        }
        $versions[] = $version;
    }
    foreach ($versions as $version) {
        if (!str_contains($published, $version)) {
            echo "signature_dns=mismatch\n";

            return 1;
        }
    }
    echo "signature_dns=match\n";

    return 0;
}

function signatureVersion(string $directory, string $name): ?string
{
    foreach (['cvd', 'cld'] as $extension) {
        $path = $directory.'/'.$name.'.'.$extension;
        if (!is_file($path)) {
            continue;
        }
        $info = shell_exec('sigtool --info '.escapeshellarg($path).' 2>/dev/null');
        if (!is_string($info) || 1 !== preg_match('/^Version: ([0-9]+)$/m', $info, $matches)) {
            return null;
        }

        return $matches[1];
    }

    return null;
}

function runScans(string $autoload, string $socket, string $work): int
{
    if (!is_file($autoload) || !is_dir($work)) {
        echo "accept_setup=unreadable\n";

        return 1;
    }
    require $autoload;
    $cleanPath = $work.'/clean.bin';
    $samplePath = $work.'/sample.bin';
    $stallPath = $work.'/stall.sock';
    try {
        if (false === file_put_contents($cleanPath, random_bytes(32))) {
            echo "accept_setup=unreadable\n";

            return 1;
        }
        if (!downloadSample($samplePath)) {
            echo "accept_sample=unavailable\n";

            return 1;
        }
        $clean = scanFile($socket, $cleanPath);
        echo 'accept_clean='.$clean['verdict'].' matched='.$clean['matched']."\n";
        $infected = scanFile($socket, $samplePath);
        echo 'accept_infected='.$infected['verdict'].' matched='.$infected['matched']."\n";
        $missing = scanFile(dirname($socket).'/missing.sock', $cleanPath);
        echo 'accept_missing='.$missing['verdict'].' matched='.$missing['matched']."\n";
        $stall = scanStalled($stallPath, $cleanPath);
        echo 'accept_timeout='.$stall['verdict'].' matched='.$stall['matched']."\n";
        $ok = QuestionImageScanVerdict::Clean->value === $clean['verdict'] && '1' === $clean['matched']
            && QuestionImageScanVerdict::Infected->value === $infected['verdict'] && '1' === $infected['matched']
            && QuestionImageScanVerdict::ConnectionFailed->value === $missing['verdict'] && '0' === $missing['matched']
            && QuestionImageScanVerdict::TimedOut->value === $stall['verdict'] && '0' === $stall['matched'];
        echo $ok ? "summary_accept=ok\n" : "summary_accept=failed\n";

        return $ok ? 0 : 1;
    } finally {
        foreach ([$cleanPath, $samplePath, $stallPath] as $path) {
            if (is_file($path) || is_link($path)) {
                unlink($path);
            }
        }
    }
}

function downloadSample(string $path): bool
{
    $context = stream_context_create([
        'http' => ['timeout' => 30, 'follow_location' => 0],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $body = file_get_contents('https://secure.eicar.org/eicar.com.txt', false, $context);
    if (!is_string($body) || '' === $body || strlen($body) > 128) {
        return false;
    }

    return false !== file_put_contents($path, $body);
}

/**
 * @return array{verdict: string, matched: string}
 */
function scanFile(string $socket, string $path): array
{
    $size = filesize($path);
    $hash = hash_file('sha256', $path);
    if (!is_int($size) || $size < 1 || !is_string($hash)) {
        return ['verdict' => 'unreadable', 'matched' => '0'];
    }
    $result = (new ClamdInstreamClient(new UnixClamdTransport($socket)))->scan($path, $size, $hash);

    return [
        'verdict' => $result->verdict->value,
        'matched' => $result->streamMatched ? '1' : '0',
    ];
}

/**
 * @return array{verdict: string, matched: string}
 */
function scanStalled(string $stallPath, string $cleanPath): array
{
    $server = stream_socket_server('unix://'.$stallPath, $errno, $error);
    if (!is_resource($server)) {
        return ['verdict' => 'unreadable', 'matched' => '0'];
    }
    try {
        return scanFile($stallPath, $cleanPath);
    } finally {
        fclose($server);
    }
}

<?php

declare(strict_types=1);

$mode = $argv[1] ?? '';
$unixPath = $argv[2] ?? '';
$errno = 0;
$errstr = '';
$unix = str_starts_with($unixPath, '/');
if ($unix && file_exists($unixPath)) {
    unlink($unixPath);
}
$server = $unix
    ? stream_socket_server('unix://'.$unixPath, $errno, $errstr)
    : stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (!is_resource($server)) {
    fwrite(\STDERR, "fixture_server=unavailable\n");
    exit(1);
}
if ($unix) {
    echo "ready\n";
} else {
    $name = stream_socket_get_name($server, false);
    if (!is_string($name) || '' === $name) {
        exit(1);
    }
    echo $name, "\n";
}
flush();

if ('split' === $mode) {
    $ping = probe_fixture_accept($server);
    if ('nPING'."\n" !== probe_fixture_read($ping)) {
        exit(2);
    }
    fwrite($ping, "PONG\n");
    fclose($ping);
    $version = probe_fixture_accept($server);
    if ('nVERSION'."\n" !== probe_fixture_read($version)) {
        exit(3);
    }
    fwrite($version, "ClamAV 1.4.3/59/Wed Oct 15 12:00:00 2026\n");
    fclose($version);
    exit(0);
}

if ('partial' === $mode) {
    $ping = probe_fixture_accept($server);
    probe_fixture_read($ping);
    fwrite($ping, 'PO');
    fclose($ping);
    $version = probe_fixture_accept($server);
    probe_fixture_read($version);
    fwrite($version, "ClamAV 1.4.3/59/Wed Oct 15 12:00:00 2026\n");
    fclose($version);
    exit(0);
}

if ('eof' === $mode) {
    $ping = probe_fixture_accept($server);
    probe_fixture_read($ping);
    fclose($ping);
    $version = probe_fixture_accept($server);
    probe_fixture_read($version);
    fwrite($version, "ClamAV 1.4.3/59/Wed Oct 15 12:00:00 2026\n");
    fclose($version);
    exit(0);
}

if ('timeout' === $mode) {
    $ping = probe_fixture_accept($server);
    fclose($server);
    probe_fixture_read($ping);
    $hold = $argv[3] ?? '2';
    sleep(1 === preg_match('/^[1-9][0-9]?$/', $hold) ? (int) $hold : 2);
    fclose($ping);
    exit(0);
}

exit(1);

/**
 * @param resource $server
 *
 * @return resource
 */
function probe_fixture_accept($server)
{
    $client = stream_socket_accept($server, 3);
    if (!is_resource($client)) {
        exit(4);
    }
    stream_set_blocking($client, true);

    return $client;
}

/**
 * @param resource $client
 */
function probe_fixture_read($client): string
{
    $buffer = '';
    while (!str_contains($buffer, "\n") && strlen($buffer) < 64) {
        $piece = fread($client, 32);
        if (!is_string($piece) || '' === $piece) {
            break;
        }
        $buffer .= $piece;
    }

    return $buffer;
}

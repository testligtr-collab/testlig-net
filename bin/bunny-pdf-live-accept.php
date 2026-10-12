<?php

declare(strict_types=1);

use App\LearningContent\Document\BunnyLearningDocumentStorage;
use App\LearningContent\Document\BunnyPdfLiveAcceptance;
use App\LearningContent\Document\BunnyStorageClient;
use App\LearningContent\Document\BunnyStorageConfiguration;
use Symfony\Component\HttpClient\HttpClient;

if ('cli' !== \PHP_SAPI) {
    fwrite(\STDERR, "cli-only\n");
    exit(2);
}

$arguments = $_SERVER['argv'] ?? [];
if (1 !== count($arguments)) {
    fwrite(\STDERR, "no-arguments\n");
    exit(2);
}

if ('1' !== getenv('BUNNY_PDF_LIVE_ACCEPT')) {
    fwrite(\STDERR, "live-accept-not-armed\n");
    exit(2);
}

$accessKey = getenv('BUNNY_STORAGE_ACCESS_KEY');
if (!is_string($accessKey) || '' === $accessKey || 1 === preg_match('/\s/', $accessKey)) {
    fwrite(\STDERR, "missing-access-key\n");
    exit(2);
}

require dirname(__DIR__).'/vendor/autoload.php';

$configuration = new BunnyStorageConfiguration(
    BunnyPdfLiveAcceptance::ZONE,
    $accessKey,
    BunnyStorageConfiguration::ENDPOINT,
);
$accessKey = '';
$http = HttpClient::create([
    'max_redirects' => 0,
    'timeout' => 10,
]);
$client = new BunnyStorageClient($http, $configuration);
$temp = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'bunny-pdf-live-'.bin2hex(random_bytes(8));
mkdir($temp, 0700, true);
$storage = new BunnyLearningDocumentStorage($client, $temp);
$report = (new BunnyPdfLiveAcceptance($storage, $client))->run();
fwrite(\STDOUT, 'result='.$report['result']."\n");
fwrite(\STDOUT, 'range='.$report['range']."\n");
fwrite(\STDOUT, 'anonymous='.$report['anonymous']."\n");
fwrite(\STDOUT, 'cleanup='.$report['cleanup']."\n");
exit('accepted' === $report['result'] ? 0 : 1);

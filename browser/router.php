<?php

declare(strict_types=1);

/**
 * Router for the PHP built-in server used by the browser suite.
 *
 * public/index.php returns a Symfony Runtime closure, so it cannot be the
 * document front controller by itself. This file stays outside public/.
 */
$public = dirname(__DIR__).DIRECTORY_SEPARATOR.'public';
$uri = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
if (str_contains($uri, '..')) {
    http_response_code(400);
    echo 'Bad request';

    return;
}

$file = $public.str_replace('/', DIRECTORY_SEPARATOR, $uri);
if ('/' !== $uri && is_file($file)) {
    return false;
}

$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $public.DIRECTORY_SEPARATOR.'index.php';

require dirname(__DIR__).DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload_runtime.php';

<?php

declare(strict_types=1);

require dirname(__DIR__) . '/functions.php';

if (!is_installed() || !indieauth_server_available()) {
    http_response_code(503);
    exit('Webmention endpoint unavailable.');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Webmention endpoint requires POST.');
}

try {
    webmention_receive(
        (string) ($_POST['source'] ?? ''),
        (string) ($_POST['target'] ?? ''),
        (string) ($_SERVER['REMOTE_ADDR'] ?? '')
    );
    http_response_code(202);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Webmention accepted for verification.';
} catch (WebmentionProtocolException $error) {
    http_response_code($error->httpStatus);
    header('Content-Type: text/plain; charset=utf-8');
    echo $error->getMessage();
} catch (Throwable) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Unable to accept Webmention.';
}

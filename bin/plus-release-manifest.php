<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/functions.php';
require dirname(__DIR__) . '/includes/updater.php';

try {
    $manifest = build_pureblog_plus_release_manifest(PUREBLOG_BASE_PATH, detect_current_pureblog_version());
    echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Unable to build Plus release manifest: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

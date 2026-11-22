<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/functions.php';

try {
    echo json_encode(
        ['published' => publish_scheduled_posts()],
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Pure Blog scheduled publication failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

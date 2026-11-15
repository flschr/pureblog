<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/functions.php';

$limit = isset($argv[1]) ? filter_var($argv[1], FILTER_VALIDATE_INT) : 10;
if (!is_int($limit) || $limit < 1 || $limit > 1000) {
    fwrite(STDERR, "Usage: php bin/plus-jobs.php [limit: 1-1000]\n");
    exit(2);
}

$host = preg_replace('/[^a-z0-9]+/i', '-', gethostname() ?: 'localhost');
$host = strtolower(trim((string) $host, '-'));
$workerId = 'worker-' . ($host !== '' ? $host : 'localhost') . '-' . getmypid();

try {
    $result = plus_run_due_jobs($workerId, $limit);
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit($result['failed'] > 0 ? 1 : 0);
} catch (Throwable $error) {
    fwrite(STDERR, 'Pure Blog Plus jobs failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

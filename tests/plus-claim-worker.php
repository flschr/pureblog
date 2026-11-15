<?php

declare(strict_types=1);

if ($argc !== 3) {
    exit(2);
}

define('PUREBLOG_PLUS_DATA_PATH', $argv[1]);
require dirname(__DIR__) . '/functions.php';

$job = plus_claim_job($argv[2], 30, 2000);
echo json_encode($job === null ? null : $job['id'], JSON_THROW_ON_ERROR) . PHP_EOL;

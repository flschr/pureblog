<?php

declare(strict_types=1);

if ($argc !== 3) {
    exit(2);
}

define('PUREBLOG_PLUS_DATA_PATH', $argv[1]);
require dirname(__DIR__) . '/functions.php';

$state = record_login_failure($argv[2]);
echo json_encode($state['failures'], JSON_THROW_ON_ERROR) . PHP_EOL;

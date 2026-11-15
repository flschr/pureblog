<?php

declare(strict_types=1);

if ($argc !== 3 || !in_array($argv[2], ['fail', 'succeed'], true)) {
    exit(2);
}

define('PUREBLOG_PLUS_DATA_PATH', $argv[1]);
require dirname(__DIR__) . '/functions.php';

$shouldFail = $argv[2] === 'fail';
plus_register_migration('0002_transaction_probe', static function (PDO $database) use ($shouldFail): void {
    $database->exec('CREATE TABLE plus_transaction_probe (id INTEGER PRIMARY KEY)');
    if ($shouldFail) {
        throw new RuntimeException('Expected migration failure.');
    }
});

try {
    $database = plus_database();
    if ($shouldFail) {
        exit(3);
    }
    $recorded = (int) $database->query(
        "SELECT COUNT(*) FROM plus_migrations WHERE id='0002_transaction_probe'"
    )->fetchColumn();
    $table = (int) $database->query(
        "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='plus_transaction_probe'"
    )->fetchColumn();
    if ($recorded !== 1 || $table !== 1) {
        exit(4);
    }
    echo "applied\n";
} catch (RuntimeException $error) {
    if (!$shouldFail || $error->getMessage() !== 'Expected migration failure.') {
        throw $error;
    }

    $database = new PDO('sqlite:' . plus_database_path());
    $recorded = (int) $database->query(
        "SELECT COUNT(*) FROM plus_migrations WHERE id='0002_transaction_probe'"
    )->fetchColumn();
    $table = (int) $database->query(
        "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='plus_transaction_probe'"
    )->fetchColumn();
    if ($recorded !== 0 || $table !== 0) {
        exit(5);
    }
    echo "rolled-back\n";
}

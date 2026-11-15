<?php

declare(strict_types=1);

function test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function test_remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
}

function test_process(string $worker, string $dataPath): array
{
    $command = [PHP_BINARY, __DIR__ . '/plus-claim-worker.php', $dataPath, $worker];
    $pipes = [];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start claim worker.');
    }
    return [$process, $pipes];
}

function test_finish_process(array $entry): mixed
{
    [$process, $pipes] = $entry;
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    test_assert($status === 0, 'Claim worker failed: ' . $error);
    return json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);
}

$root = sys_get_temp_dir() . '/pureblog-plus-' . bin2hex(random_bytes(8));
$dataPath = $root . '/plus';
define('PUREBLOG_PLUS_DATA_PATH', $dataPath);

try {
    $cliSessionName = session_name();
    require dirname(__DIR__) . '/functions.php';

    test_assert(session_name() === $cliSessionName, 'Pure Blog bootstrap changed the CLI session name.');
    test_assert(!is_dir($dataPath), 'Pure Blog bootstrap must not create Plus data.');
    test_assert(plus_has_capability('plus.runtime', 1, 0), 'Runtime capability missing.');
    test_assert(!plus_has_capability('plus.runtime', 2, 0), 'Unknown capability major accepted.');
    plus_validate_job_name('worker-123-host-456', 'worker id');
    try {
        plus_require_capability('connector.events', 1, 0);
        throw new RuntimeException('Unknown capability did not fail closed.');
    } catch (RuntimeException $error) {
        test_assert(str_contains($error->getMessage(), 'connector.events'), 'Wrong capability failure.');
    }

    mkdir($dataPath, 0777, true);
    $migrationRuns = 0;
    plus_register_migration('0002_test_probe', static function (PDO $database) use (&$migrationRuns): void {
        $migrationRuns++;
        $database->exec('CREATE TABLE plus_test_probe (id INTEGER PRIMARY KEY)');
    });
    $database = plus_database();
    test_assert(is_file(plus_database_path()), 'Plus database was not created on demand.');
    test_assert((fileperms($dataPath) & 0777) === 0700, 'Plus data directory is not private.');
    test_assert((fileperms(plus_database_path()) & 0777) === 0600, 'Plus database is not private.');
    foreach (glob(plus_database_path() . '-*') ?: [] as $sidecar) {
        test_assert((fileperms($sidecar) & 0077) === 0, 'Plus database sidecar is not private.');
    }
    test_assert($migrationRuns === 1, 'Migration did not run exactly once.');
    plus_database();
    test_assert($migrationRuns === 1, 'Migration was not idempotent.');

    test_assert(request_ip_matches_network('192.0.2.9', '192.0.2.0/24'), 'IPv4 trusted proxy network did not match.');
    test_assert(!request_ip_matches_network('192.0.3.9', '192.0.2.0/24'), 'IPv4 trusted proxy network overmatched.');
    test_assert(request_ip_matches_network('2001:db8::9', '2001:db8::/32'), 'IPv6 trusted proxy network did not match.');
    for ($failure = 1; $failure <= 5; $failure++) {
        $failureState = record_login_failure('192.0.2.44');
        test_assert($failureState['failures'] === $failure, 'Login failure increment was not atomic.');
    }
    test_assert($failureState['lockout_until'] > time(), 'Login failure threshold did not lock out.');
    clear_login_failures('192.0.2.44');
    test_assert(get_login_failure_state('192.0.2.44')['failures'] === 0, 'Login failure state was not cleared.');
    set_remember_me_cookie();
    test_assert((int) $database->query('SELECT COUNT(*) FROM plus_remember_tokens')->fetchColumn() === 1, 'Remember token was not stored transactionally.');
    clear_all_remember_me_tokens();
    test_assert((int) $database->query('SELECT COUNT(*) FROM plus_remember_tokens')->fetchColumn() === 0, 'Remember tokens were not cleared.');

    $first = plus_enqueue_job('test.once', ['value' => 1], 1000, 2, 'same-event');
    $duplicate = plus_enqueue_job('test.once', ['value' => 2], 1000, 2, 'same-event');
    test_assert($first['id'] === $duplicate['id'], 'Idempotent enqueue created a duplicate.');
    plus_enqueue_job('test.external', [], time() + 3600, 1, 'https://social.example/@author/123');

    $claimed = plus_claim_job('worker-a', 30, 1000);
    test_assert($claimed !== null && $claimed['attempts'] === 1, 'Due job was not claimed.');
    test_assert(plus_claim_job('worker-b', 30, 1000) === null, 'Running job was claimed twice.');
    try {
        plus_complete_job((int) $claimed['id'], 'worker-b', 1001);
        throw new RuntimeException('Foreign worker completed a job.');
    } catch (RuntimeException $error) {
        test_assert(str_contains($error->getMessage(), 'not owned'), 'Wrong worker ownership failure.');
    }
    plus_fail_job((int) $claimed['id'], 'worker-a', 'retry', 1010, 1001);
    test_assert(plus_claim_job('worker-b', 30, 1009) === null, 'Job retried before due time.');
    $retried = plus_claim_job('worker-b', 30, 1010);
    test_assert($retried !== null && $retried['attempts'] === 2, 'Due retry was not claimed.');
    plus_fail_job((int) $retried['id'], 'worker-b', 'final', 1020, 1011);
    test_assert(plus_get_job((int) $retried['id'])['state'] === 'failed', 'Final failure was requeued.');

    $lease = plus_enqueue_job('test.lease', [], 1500, 2);
    test_assert(plus_claim_job('worker-a', 10, 1500)['id'] === $lease['id'], 'Lease job not claimed.');
    plus_extend_job_lease((int) $lease['id'], 'worker-a', 20, 1505);
    test_assert(plus_claim_job('worker-b', 10, 1511) === null, 'Extended lease was ignored.');
    $reclaimed = plus_claim_job('worker-b', 10, 1526);
    test_assert($reclaimed !== null && $reclaimed['id'] === $lease['id'], 'Expired lease not reclaimed.');
    plus_complete_job((int) $reclaimed['id'], 'worker-b', 1527);
    test_assert(plus_get_job((int) $reclaimed['id'])['state'] === 'succeeded', 'Job not completed.');

    $parallel = plus_enqueue_job('test.parallel', [], 2000, 1);
    $workerA = test_process('parallel-a', $dataPath);
    $workerB = test_process('parallel-b', $dataPath);
    $results = [test_finish_process($workerA), test_finish_process($workerB)];
    sort($results);
    test_assert($results === [null, $parallel['id']], 'Parallel workers did not claim exactly once.');

    plus_register_job_handler('test.handler', static function (array $payload, array $job): void {
        test_assert($payload['ok'] === true, 'Handler payload mismatch.');
        plus_database()->prepare('UPDATE plus_jobs SET lease_until=? WHERE id=?')->execute([time() - 1, $job['id']]);
        plus_job_heartbeat();
        test_assert((int) plus_get_job((int) $job['id'])['lease_until'] > time(), 'Handler heartbeat did not renew the active lease.');
    });
    plus_enqueue_job('test.handler', ['ok' => true], time(), 1);
    $run = plus_run_due_jobs('runner', 5);
    test_assert($run === ['claimed' => 1, 'succeeded' => 1, 'failed' => 0], 'Job runner result mismatch.');
    $missing = plus_enqueue_job('test.missing', [], time(), 1);
    $missingRun = plus_run_due_jobs('runner-missing', 1);
    test_assert($missingRun === ['claimed' => 1, 'succeeded' => 0, 'failed' => 1], 'Missing handler was not isolated.');
    test_assert(plus_get_job((int) $missing['id'])['state'] === 'failed', 'Missing handler job did not stop after its final attempt.');

    $oldSucceeded = plus_enqueue_job('test.old-succeeded', [], 1000, 1);
    $oldClaim = plus_claim_job('old-worker', 30, 1000);
    test_assert($oldClaim !== null && $oldClaim['id'] === $oldSucceeded['id'], 'Old succeeded job was not claimed.');
    plus_complete_job((int) $oldSucceeded['id'], 'old-worker', 1001);
    $oldFailed = plus_enqueue_job('test.old-failed', [], 1002, 1);
    $failedClaim = plus_claim_job('failed-worker', 30, 1002);
    test_assert($failedClaim !== null && $failedClaim['id'] === $oldFailed['id'], 'Old failed job was not claimed.');
    plus_fail_job((int) $oldFailed['id'], 'failed-worker', 'old failure', null, 1003);
    $pruned = plus_prune_jobs(20000000, 86400, 172800);
    test_assert($pruned === ['succeeded' => 1, 'failed' => 1], 'Terminal job retention did not prune bounded history.');

    $migrationPath = $root . '/migration';
    $failedMigration = test_process_command([
        PHP_BINARY,
        __DIR__ . '/plus-migration-worker.php',
        $migrationPath,
        'fail',
    ]);
    test_assert($failedMigration === 'rolled-back', 'Failed migration did not roll back.');
    $completedMigration = test_process_command([
        PHP_BINARY,
        __DIR__ . '/plus-migration-worker.php',
        $migrationPath,
        'succeed',
    ]);
    test_assert($completedMigration === 'applied', 'Rolled-back migration could not be retried.');

    echo "PASS: lazy runtime, capabilities, idempotent migrations, atomic claims, retries, leases and handlers\n";
} finally {
    test_remove_tree($root);
}

function test_process_command(array $command): string
{
    $pipes = [];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start test process.');
    }
    $output = trim(stream_get_contents($pipes[1]));
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    test_assert($status === 0, 'Test process failed: ' . $error);
    return $output;
}

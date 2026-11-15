<?php

declare(strict_types=1);

/** @var array<string, callable(array, array):void> */
$GLOBALS['_pureblog_plus_job_handlers'] ??= [];
$GLOBALS['_pureblog_plus_job_heartbeat'] ??= null;

function plus_register_job_handler(string $type, callable $handler): void
{
    plus_validate_job_name($type, 'type');
    if (isset($GLOBALS['_pureblog_plus_job_handlers'][$type])) {
        throw new LogicException('Plus job handler already registered: ' . $type);
    }
    $GLOBALS['_pureblog_plus_job_handlers'][$type] = $handler;
}

/**
 * @return array{claimed:int,succeeded:int,failed:int}
 */
function plus_job_heartbeat(): void
{
    $heartbeat = $GLOBALS['_pureblog_plus_job_heartbeat'] ?? null;
    if (is_callable($heartbeat)) $heartbeat();
}

function plus_run_due_jobs(string $workerId, int $limit = 10, int $leaseSeconds = 300): array
{
    if ($limit < 1 || $limit > 1000) {
        throw new InvalidArgumentException('Plus job limit must be between 1 and 1000.');
    }

    $result = ['claimed' => 0, 'succeeded' => 0, 'failed' => 0];
    while ($result['claimed'] < $limit) {
        $job = plus_claim_job($workerId, $leaseSeconds);
        if ($job === null) {
            break;
        }
        $result['claimed']++;
        try {
            $handler = $GLOBALS['_pureblog_plus_job_handlers'][$job['type']] ?? null;
            if (!is_callable($handler)) {
                throw new RuntimeException('No Plus job handler registered for: ' . $job['type']);
            }
            $heartbeat = static function () use ($job, $workerId, $leaseSeconds): void {
                plus_extend_job_lease((int) $job['id'], $workerId, $leaseSeconds);
            };
            $GLOBALS['_pureblog_plus_job_heartbeat'] = $heartbeat;
            $heartbeat();
            $handler($job['payload'], $job);
            $heartbeat();
            plus_complete_job((int) $job['id'], $workerId);
            $result['succeeded']++;
        } catch (Throwable $error) {
            $delay = min(3600, 30 * (2 ** max(0, (int) $job['attempts'] - 1)));
            plus_fail_job((int) $job['id'], $workerId, $error->getMessage(), time() + $delay);
            $result['failed']++;
        } finally {
            $GLOBALS['_pureblog_plus_job_heartbeat'] = null;
        }
    }
    plus_prune_jobs();
    return $result;
}

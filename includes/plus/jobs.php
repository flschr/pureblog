<?php

declare(strict_types=1);

function plus_validate_job_name(string $value, string $field): void
{
    if (preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/', $value) !== 1) {
        throw new InvalidArgumentException('Invalid Plus job ' . $field . '.');
    }
}

/**
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function plus_enqueue_job(
    string $type,
    array $payload,
    ?int $availableAt = null,
    int $maxAttempts = 5,
    ?string $uniqueKey = null
): array {
    plus_validate_job_name($type, 'type');
    if ($uniqueKey !== null) {
        plus_validate_unique_key($uniqueKey);
    }
    if ($maxAttempts < 1 || $maxAttempts > 100) {
        throw new InvalidArgumentException('Plus job max attempts must be between 1 and 100.');
    }

    $database = plus_database();
    $now = time();
    $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $insert = $database->prepare(
        "INSERT OR IGNORE INTO plus_jobs "
        . "(type,payload,state,available_at,attempts,max_attempts,unique_key,created_at,updated_at) "
        . "VALUES (?,?,'queued',?,0,?,?,?,?)"
    );
    $insert->execute([$type, $encoded, $availableAt ?? $now, $maxAttempts, $uniqueKey, $now, $now]);

    if ($insert->rowCount() === 1) {
        return plus_get_job((int) $database->lastInsertId());
    }
    if ($uniqueKey === null) {
        throw new RuntimeException('Unable to enqueue Plus job.');
    }

    $existing = $database->prepare('SELECT id FROM plus_jobs WHERE type = ? AND unique_key = ?');
    $existing->execute([$type, $uniqueKey]);
    $id = $existing->fetchColumn();
    if ($id === false) {
        throw new RuntimeException('Unable to resolve idempotent Plus job.');
    }
    return plus_get_job((int) $id);
}

function plus_validate_unique_key(string $uniqueKey): void
{
    if ($uniqueKey === '' || strlen($uniqueKey) > 255 || preg_match('/[\x00-\x1F\x7F]/', $uniqueKey) === 1) {
        throw new InvalidArgumentException('Invalid Plus job unique key.');
    }
}

/**
 * @return array<string, mixed>
 */
function plus_get_job(int $id): array
{
    $query = plus_database()->prepare('SELECT * FROM plus_jobs WHERE id = ?');
    $query->execute([$id]);
    $job = $query->fetch();
    if (!is_array($job)) {
        throw new OutOfBoundsException('Unknown Plus job.');
    }
    return plus_decode_job($job);
}

/**
 * @return array<string, mixed>|null
 */
function plus_claim_job(string $workerId, int $leaseSeconds = 60, ?int $now = null): ?array
{
    plus_validate_job_name($workerId, 'worker id');
    if ($leaseSeconds < 1 || $leaseSeconds > 3600) {
        throw new InvalidArgumentException('Plus job lease must be between 1 and 3600 seconds.');
    }

    $database = plus_database();
    $now ??= time();
    $database->exec('BEGIN IMMEDIATE');
    try {
        $expire = $database->prepare(
            "UPDATE plus_jobs SET state='failed',worker_id=NULL,lease_until=NULL,"
            . "last_error=COALESCE(last_error,'Job lease expired after final attempt'),updated_at=? "
            . "WHERE state='running' AND lease_until <= ? AND attempts >= max_attempts"
        );
        $expire->execute([$now, $now]);

        $select = $database->prepare(
            "SELECT id FROM plus_jobs WHERE attempts < max_attempts AND ("
            . "(state='queued' AND available_at <= ?) OR "
            . "(state='running' AND lease_until <= ?)) "
            . "ORDER BY available_at ASC, id ASC LIMIT 1"
        );
        $select->execute([$now, $now]);
        $id = $select->fetchColumn();
        if ($id === false) {
            $database->exec('COMMIT');
            return null;
        }

        $claim = $database->prepare(
            "UPDATE plus_jobs SET state='running',attempts=attempts+1,worker_id=?,"
            . "lease_until=?,updated_at=? WHERE id=?"
        );
        $claim->execute([$workerId, $now + $leaseSeconds, $now, $id]);
        $database->exec('COMMIT');
        return plus_get_job((int) $id);
    } catch (Throwable $error) {
        if ($database->inTransaction()) {
            $database->exec('ROLLBACK');
        }
        throw $error;
    }
}

function plus_complete_job(int $id, string $workerId, ?int $now = null): void
{
    plus_finish_job($id, $workerId, true, null, null, $now ?? time());
}

function plus_extend_job_lease(
    int $id,
    string $workerId,
    int $leaseSeconds = 60,
    ?int $now = null
): void {
    plus_validate_job_name($workerId, 'worker id');
    if ($leaseSeconds < 1 || $leaseSeconds > 3600) {
        throw new InvalidArgumentException('Plus job lease must be between 1 and 3600 seconds.');
    }

    $now ??= time();
    $update = plus_database()->prepare(
        "UPDATE plus_jobs SET lease_until=?,updated_at=? WHERE id=? AND state='running' AND worker_id=?"
    );
    $update->execute([$now + $leaseSeconds, $now, $id, $workerId]);
    if ($update->rowCount() !== 1) {
        throw new RuntimeException('Plus job is not owned by this worker.');
    }
}

function plus_fail_job(
    int $id,
    string $workerId,
    string $error,
    ?int $retryAt = null,
    ?int $now = null
): void {
    plus_finish_job($id, $workerId, false, $error, $retryAt, $now ?? time());
}

function plus_finish_job(
    int $id,
    string $workerId,
    bool $succeeded,
    ?string $error,
    ?int $retryAt,
    int $now
): void {
    plus_validate_job_name($workerId, 'worker id');
    $database = plus_database();
    $database->exec('BEGIN IMMEDIATE');
    try {
        $query = $database->prepare('SELECT attempts,max_attempts FROM plus_jobs WHERE id=? AND state=\'running\' AND worker_id=?');
        $query->execute([$id, $workerId]);
        $job = $query->fetch();
        if (!is_array($job)) {
            throw new RuntimeException('Plus job is not owned by this worker.');
        }

        $state = $succeeded ? 'succeeded' : ((int) $job['attempts'] >= (int) $job['max_attempts'] ? 'failed' : 'queued');
        $availableAt = $state === 'queued' ? ($retryAt ?? $now) : $now;
        $update = $database->prepare(
            'UPDATE plus_jobs SET state=?,available_at=?,worker_id=NULL,lease_until=NULL,last_error=?,updated_at=? WHERE id=?'
        );
        $update->execute([
            $state,
            $availableAt,
            $succeeded ? null : mb_substr(trim((string) $error), 0, 2000),
            $now,
            $id,
        ]);
        $database->exec('COMMIT');
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->exec('ROLLBACK');
        }
        throw $exception;
    }
}

/**
 * @param array<string, mixed> $job
 * @return array<string, mixed>
 */
function plus_decode_job(array $job): array
{
    $payload = json_decode((string) $job['payload'], true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) {
        throw new RuntimeException('Invalid Plus job payload.');
    }
    $job['id'] = (int) $job['id'];
    $job['attempts'] = (int) $job['attempts'];
    $job['max_attempts'] = (int) $job['max_attempts'];
    $job['available_at'] = (int) $job['available_at'];
    $job['lease_until'] = $job['lease_until'] === null ? null : (int) $job['lease_until'];
    $job['payload'] = $payload;
    return $job;
}

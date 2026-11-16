<?php

declare(strict_types=1);

/** @var array<string, callable(PDO):void> */
$GLOBALS['_pureblog_plus_migrations'] ??= [];

function plus_register_migration(string $id, callable $migration): void
{
    if (preg_match('/^[0-9]{4}_[a-z0-9_]+$/', $id) !== 1) {
        throw new InvalidArgumentException('Invalid Plus migration identifier.');
    }
    if (isset($GLOBALS['_pureblog_plus_migrations'][$id])) {
        throw new LogicException('Plus migration already registered: ' . $id);
    }

    $GLOBALS['_pureblog_plus_migrations'][$id] = $migration;
    ksort($GLOBALS['_pureblog_plus_migrations'], SORT_STRING);
}

function plus_database_path(): string
{
    return PUREBLOG_PLUS_DATA_PATH . '/plus.sqlite';
}

function plus_database(): PDO
{
    static $database = null;
    static $appliedMigrationSignature = null;
    static $applyingMigrations = false;

    if ($database === null) {
        if (!class_exists(PDO::class) || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            throw new RuntimeException('Pure Blog Plus requires the PDO SQLite extension.');
        }

        if (!is_dir(PUREBLOG_PLUS_DATA_PATH)
            && !mkdir(PUREBLOG_PLUS_DATA_PATH, 0700, true)
            && !is_dir(PUREBLOG_PLUS_DATA_PATH)) {
            throw new RuntimeException('Unable to create the Pure Blog Plus data directory.');
        }
        @chmod(PUREBLOG_PLUS_DATA_PATH, 0700);
        $directoryPermissions = fileperms(PUREBLOG_PLUS_DATA_PATH);
        if ($directoryPermissions === false || ($directoryPermissions & 0077) !== 0) {
            throw new RuntimeException('Pure Blog Plus data directory must not be accessible to other users.');
        }

        $database = new PDO('sqlite:' . plus_database_path(), null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $database->exec('PRAGMA foreign_keys = ON');
        $database->exec('PRAGMA busy_timeout = 5000');
        $database->exec('PRAGMA journal_mode = WAL');
        @chmod(plus_database_path(), 0600);
    }

    $migrationSignature = implode("\n", array_keys($GLOBALS['_pureblog_plus_migrations']));
    if ($migrationSignature !== $appliedMigrationSignature) {
        if ($applyingMigrations) {
            throw new LogicException('Plus migrations must use the provided database connection.');
        }
        $applyingMigrations = true;
        try {
            plus_apply_migrations($database);
            $appliedMigrationSignature = $migrationSignature;
        } finally {
            $applyingMigrations = false;
        }
    }
    return $database;
}

function plus_apply_migrations(PDO $database): void
{
    $registered = array_keys($GLOBALS['_pureblog_plus_migrations']);
    $tableExists = (int) $database->query(
        "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='plus_migrations'"
    )->fetchColumn() === 1;
    if (!$tableExists) {
        $database->exec(
            'CREATE TABLE IF NOT EXISTS plus_migrations ('
            . 'id TEXT PRIMARY KEY, applied_at INTEGER NOT NULL)'
        );
    }
    $applied = $database->query('SELECT id FROM plus_migrations')->fetchAll(PDO::FETCH_COLUMN);
    if (array_diff($registered, $applied) === []) {
        return;
    }

    plus_begin_immediate($database);
    try {
        $query = $database->prepare('SELECT 1 FROM plus_migrations WHERE id = ?');
        $record = $database->prepare(
            'INSERT INTO plus_migrations (id, applied_at) VALUES (?, ?)'
        );
        foreach ($GLOBALS['_pureblog_plus_migrations'] as $id => $migration) {
            $query->execute([$id]);
            if ($query->fetchColumn() === false) {
                $migration($database);
                $record->execute([$id, time()]);
            }
        }
        $database->exec('COMMIT');
    } catch (Throwable $error) {
        if ($database->inTransaction()) {
            $database->exec('ROLLBACK');
        }
        throw $error;
    }
}

function plus_begin_immediate(PDO $database): void
{
    for ($attempt = 0; ; $attempt++) {
        try {
            $database->exec('BEGIN IMMEDIATE');
            return;
        } catch (PDOException $error) {
            $isLocked = str_contains(strtolower($error->getMessage()), 'database is locked');
            if (!$isLocked || $attempt >= 9) {
                throw $error;
            }
            usleep(50000 * ($attempt + 1));
        }
    }
}

plus_register_migration('0001_runtime_jobs', static function (PDO $database): void {
    $database->exec(
        "CREATE TABLE plus_jobs ("
        . "id INTEGER PRIMARY KEY AUTOINCREMENT,"
        . "type TEXT NOT NULL,"
        . "payload TEXT NOT NULL,"
        . "state TEXT NOT NULL CHECK (state IN ('queued','running','succeeded','failed')),"
        . "available_at INTEGER NOT NULL,"
        . "attempts INTEGER NOT NULL DEFAULT 0 CHECK (attempts >= 0),"
        . "max_attempts INTEGER NOT NULL CHECK (max_attempts > 0),"
        . "lease_until INTEGER,"
        . "worker_id TEXT,"
        . "unique_key TEXT,"
        . "last_error TEXT,"
        . "created_at INTEGER NOT NULL,"
        . "updated_at INTEGER NOT NULL"
        . ")"
    );
    $database->exec(
        'CREATE INDEX plus_jobs_due_idx '
        . 'ON plus_jobs (state, available_at, lease_until, id)'
    );
    $database->exec(
        'CREATE UNIQUE INDEX plus_jobs_unique_idx '
        . 'ON plus_jobs (type, unique_key) WHERE unique_key IS NOT NULL'
    );
});

plus_register_migration('0007_auth_runtime', static function (PDO $database): void {
    $database->exec(
        'CREATE TABLE plus_login_failures ('
        . 'client_key TEXT PRIMARY KEY,'
        . 'failures INTEGER NOT NULL DEFAULT 0 CHECK (failures >= 0),'
        . 'lockout_until INTEGER NOT NULL DEFAULT 0,'
        . 'updated_at INTEGER NOT NULL)'
    );
    $database->exec(
        'CREATE INDEX plus_login_failures_expiry_idx '
        . 'ON plus_login_failures (lockout_until,updated_at)'
    );
    $database->exec(
        'CREATE TABLE plus_remember_tokens ('
        . 'selector TEXT PRIMARY KEY,'
        . 'validator_hash TEXT NOT NULL,'
        . 'expires_at INTEGER NOT NULL,'
        . 'created_at INTEGER NOT NULL)'
    );
    $database->exec(
        'CREATE INDEX plus_remember_tokens_expiry_idx '
        . 'ON plus_remember_tokens (expires_at)'
    );

    $legacyDataPath = dirname(PUREBLOG_PLUS_DATA_PATH) === PUREBLOG_DATA_PATH ? PUREBLOG_DATA_PATH : '';
    $failuresPath = $legacyDataPath !== '' ? $legacyDataPath . '/login-failures.json' : '';
    $legacyFailures = is_file($failuresPath) ? json_decode((string) @file_get_contents($failuresPath), true) : null;
    if (is_array($legacyFailures)) {
        $insertFailure = $database->prepare(
            'INSERT OR REPLACE INTO plus_login_failures (client_key,failures,lockout_until,updated_at) VALUES (?,?,?,?)'
        );
        foreach ($legacyFailures as $clientKey => $entry) {
            if (!is_string($clientKey) || !is_array($entry)) continue;
            $insertFailure->execute([$clientKey, max(0, (int) ($entry['failures'] ?? 0)),
                max(0, (int) ($entry['lockout_until'] ?? 0)), time()]);
        }
    }

    $tokensPath = $legacyDataPath !== '' ? $legacyDataPath . '/remember-me.json' : '';
    $legacyTokens = is_file($tokensPath) ? json_decode((string) @file_get_contents($tokensPath), true) : null;
    if (is_array($legacyTokens)) {
        $insertToken = $database->prepare(
            'INSERT OR REPLACE INTO plus_remember_tokens (selector,validator_hash,expires_at,created_at) VALUES (?,?,?,?)'
        );
        foreach ($legacyTokens as $selector => $entry) {
            if (!is_string($selector) || !is_array($entry)
                || preg_match('/^[a-f0-9]{32}$/', $selector) !== 1
                || preg_match('/^[a-f0-9]{64}$/', (string) ($entry['validator_hash'] ?? '')) !== 1) continue;
            $insertToken->execute([$selector, (string) $entry['validator_hash'],
                max(0, (int) ($entry['expires'] ?? 0)), time()]);
        }
    }
    if (is_file($failuresPath)) @unlink($failuresPath);
    if (is_file($tokensPath)) @unlink($tokensPath);
});

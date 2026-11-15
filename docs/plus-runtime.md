# Plus runtime

Pure Blog Plus keeps editorial content in the existing Markdown files. Optional protocols
and connectors use one lazy local SQLite runtime for operational state only.

Requiring `functions.php` loads function definitions and registers the `plus.runtime@1.0`
capability, but it does not create a directory or database. The first call that needs
operational state opens `data/plus/plus.sqlite`, applies registered migrations in order,
and enables WAL mode. The directory and database are private to the web-service user.

## Capabilities

Modules register a stable capability name with an integer major and minor version. Consumers
require the major version they understand and a minimum minor version. An unavailable or
unknown major fails only the dependent integration; it does not prevent the blog from loading.

## Migrations

Migrations have ordered identifiers such as `0001_runtime_jobs`. They run once inside an
immediate SQLite transaction and record completion in `plus_migrations`. A failed migration
rolls back without recording success. Modules must register migrations before requesting the
database.

Migration callbacks use the `PDO` connection passed to them. Calling `plus_database()` from
inside a migration is rejected so recursive migration application cannot partially initialize
the runtime.

## Jobs

The `plus_jobs` queue provides:

- JSON payloads and optional per-type idempotency keys
- scheduled availability
- atomic claims with bounded leases
- explicit worker ownership for completion and failure
- renewable leases for handlers that intentionally run longer than one lease window
- bounded attempts and exponential retry scheduling
- permanent failure after the configured final attempt

Job handlers are ordinary local PHP callables. A scheduler invokes:

```sh
php bin/plus-jobs.php
```

The command processes at most ten jobs by default; an optional integer argument changes the
limit. It exits non-zero when a claimed job fails, so systemd or cron can expose unhealthy
work without introducing a hosted queue.

## Verification

Run the isolated runtime contract with:

```sh
php tests/plus-runtime.php
```

It uses temporary databases and subprocesses to cover lazy initialization, private file
permissions, capabilities, migration rollback and retry, idempotent enqueueing, simultaneous
claims, renewable and expired leases, bounded retries, missing-handler isolation, and successful
handler execution. It does not need an installed blog or modify repository content.

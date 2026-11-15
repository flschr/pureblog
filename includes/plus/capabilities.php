<?php

declare(strict_types=1);

/** @var array<string, array{major:int, minor:int}> */
$GLOBALS['_pureblog_plus_capabilities'] ??= [];

function plus_register_capability(string $name, int $major, int $minor = 0): void
{
    if (preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/', $name) !== 1) {
        throw new InvalidArgumentException('Invalid Plus capability name.');
    }
    if ($major < 1 || $minor < 0) {
        throw new InvalidArgumentException('Invalid Plus capability version.');
    }

    $version = ['major' => $major, 'minor' => $minor];
    $existing = $GLOBALS['_pureblog_plus_capabilities'][$name] ?? null;
    if ($existing !== null && $existing !== $version) {
        throw new LogicException('Plus capability already registered with a different version: ' . $name);
    }

    $GLOBALS['_pureblog_plus_capabilities'][$name] = $version;
}

/**
 * @return array{major:int, minor:int}|null
 */
function plus_capability(string $name): ?array
{
    return $GLOBALS['_pureblog_plus_capabilities'][$name] ?? null;
}

function plus_has_capability(string $name, int $requiredMajor, int $minimumMinor = 0): bool
{
    $version = plus_capability($name);
    return $version !== null
        && $version['major'] === $requiredMajor
        && $version['minor'] >= $minimumMinor;
}

function plus_require_capability(string $name, int $requiredMajor, int $minimumMinor = 0): void
{
    if (!plus_has_capability($name, $requiredMajor, $minimumMinor)) {
        throw new RuntimeException(sprintf(
            'Required Plus capability is unavailable or incompatible: %s@%d.%d',
            $name,
            $requiredMajor,
            $minimumMinor
        ));
    }
}

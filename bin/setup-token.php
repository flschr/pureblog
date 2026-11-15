<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$dataPath = dirname(__DIR__) . '/data';
if (!is_dir($dataPath) && !mkdir($dataPath, 0700, true) && !is_dir($dataPath)) {
    fwrite(STDERR, "Unable to create the data directory.\n");
    exit(1);
}
@chmod($dataPath, 0700);

$token = bin2hex(random_bytes(24));
$path = $dataPath . '/setup-token';
$temporaryPath = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
if (file_put_contents($temporaryPath, hash('sha256', $token) . "\n", LOCK_EX) === false) {
    fwrite(STDERR, "Unable to write the setup token.\n");
    exit(1);
}
@chmod($temporaryPath, 0600);
if (!rename($temporaryPath, $path)) {
    @unlink($temporaryPath);
    fwrite(STDERR, "Unable to activate the setup token.\n");
    exit(1);
}

echo $token . PHP_EOL;

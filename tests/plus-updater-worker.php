<?php

declare(strict_types=1);

if ($argc !== 3 || !in_array($argv[1], ['success', 'rollback'], true)) {
    exit(2);
}

require dirname(__DIR__) . '/functions.php';
require dirname(__DIR__) . '/includes/updater.php';

$download = static function (string $url, string $destination) use ($argv): ?string {
    return copy($argv[2], $destination) ? null : 'Unable to copy fixture.';
};
$copyPath = static function (string $source, string $destination) use ($argv): void {
    if ($argv[1] === 'rollback' && basename($source) === 'tests') {
        throw new RuntimeException('Expected copy failure.');
    }
    copy_path_recursive($source, $destination);
};

$beforeFunctions = hash_file('sha256', PUREBLOG_BASE_PATH . '/functions.php');
$result = apply_release_update(
    'https://api.github.com/repos/flschr/pureblog/zipball/v3.9.2-plus.1',
    'v3.9.2-plus.1',
    $download,
    $copyPath
);

$state = [
    'result' => $result,
    'version' => trim((string) file_get_contents(PUREBLOG_BASE_PATH . '/VERSION')),
    'probe' => is_file(PUREBLOG_BASE_PATH . '/release-probe.txt'),
    'functions_unchanged' => hash_equals($beforeFunctions, (string) hash_file('sha256', PUREBLOG_BASE_PATH . '/functions.php')),
    'config_preserved' => is_file(PUREBLOG_BASE_PATH . '/config/update-test-sentinel'),
    'content_preserved' => is_file(PUREBLOG_BASE_PATH . '/content/update-test-sentinel'),
    'data_preserved' => is_file(PUREBLOG_BASE_PATH . '/data/update-test-sentinel'),
    'htaccess_preserved' => (string) file_get_contents(PUREBLOG_BASE_PATH . '/.htaccess') === "test htaccess\n",
];
echo json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;

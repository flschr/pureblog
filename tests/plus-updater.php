<?php

declare(strict_types=1);

function updater_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function updater_test_remove_tree(string $path): void
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

function updater_test_copy_tree(string $source, string $destination): void
{
    mkdir($destination, 0700, true);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    $prefixLength = strlen(rtrim($source, DIRECTORY_SEPARATOR)) + 1;
    foreach ($iterator as $item) {
        $relative = substr($item->getPathname(), $prefixLength);
        $normalized = str_replace('\\', '/', $relative);
        $top = explode('/', $normalized, 2)[0];
        if (in_array($top, ['.git', 'backup', 'cache'], true) || str_starts_with($normalized, 'data/plus/')) {
            continue;
        }
        $target = $destination . '/' . $relative;
        if ($item->isDir()) {
            if (!is_dir($target)) {
                mkdir($target, 0700, true);
            }
        } elseif ($item->isFile()) {
            $parent = dirname($target);
            if (!is_dir($parent)) {
                mkdir($parent, 0700, true);
            }
            copy($item->getPathname(), $target);
        }
    }
}

function updater_test_zip_tree(string $source, string $zipPath, string $rootName): void
{
    $zip = new ZipArchive();
    updater_test_assert($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'Unable to create fixture zip.');
    $zip->addEmptyDir($rootName);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    $prefixLength = strlen(rtrim($source, DIRECTORY_SEPARATOR)) + 1;
    foreach ($iterator as $item) {
        $relative = str_replace('\\', '/', substr($item->getPathname(), $prefixLength));
        $archivePath = $rootName . '/' . $relative;
        if ($item->isDir()) {
            $zip->addEmptyDir($archivePath);
        } elseif ($item->isFile()) {
            $zip->addFile($item->getPathname(), $archivePath);
        }
    }
    updater_test_assert($zip->close(), 'Unable to close fixture zip.');
}

function updater_test_worker(string $installRoot, string $mode, string $zipPath): array
{
    $command = [PHP_BINARY, $installRoot . '/tests/plus-updater-worker.php', $mode, $zipPath];
    $pipes = [];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $installRoot);
    updater_test_assert(is_resource($process), 'Unable to start updater worker.');
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    updater_test_assert($status === 0, 'Updater worker failed: ' . $error);
    $decoded = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);
    updater_test_assert(is_array($decoded), 'Updater worker returned invalid state.');
    return $decoded;
}

require dirname(__DIR__) . '/functions.php';
require dirname(__DIR__) . '/includes/updater.php';

updater_test_assert(parse_pureblog_plus_version('v3.9.2-plus.1')['version'] === '3.9.2-plus.1', 'Stable Plus version not parsed.');
updater_test_assert(parse_pureblog_plus_version('3.9.2-plus.2-RC.3')['version'] === '3.9.2-plus.2-rc.3', 'Preview Plus version not normalized.');
updater_test_assert(parse_pureblog_plus_version('v3.9.2') === null, 'Upstream version accepted as Plus release.');
updater_test_assert(compare_pureblog_plus_versions('3.9.2-plus.1-beta.2', '3.9.2-plus.1') < 0, 'Stable release did not sort after preview.');
updater_test_assert(compare_pureblog_plus_versions('3.9.2-plus.2', '3.9.2-plus.1') > 0, 'Plus release order is wrong.');
updater_test_assert(compare_pureblog_plus_versions('3.10.0-plus.1', '3.9.9-plus.9') > 0, 'Upstream base order is wrong.');
updater_test_assert(pureblog_plus_update_available('3.9.2-plus.0', 'v3.9.2-plus.1'), 'Available Plus update not detected.');
updater_test_assert(!pureblog_plus_update_available('3.9.2', 'v3.9.2-plus.1'), 'Non-Plus installation accepted for automatic update.');
updater_test_assert(pureblog_plus_release_download_url_is_allowed('https://api.github.com/repos/flschr/pureblog/zipball/v3.9.2-plus.1'), 'Expected GitHub download URL rejected.');
updater_test_assert(!pureblog_plus_release_download_url_is_allowed('https://api.github.com/repos/kevquirk/pureblog/zipball/v3.9.2'), 'Upstream download URL accepted.');

$releasePayload = json_encode([
    ['tag_name' => 'v3.9.2-plus.1', 'name' => 'Stable', 'html_url' => 'https://github.com/flschr/pureblog/releases/tag/v3.9.2-plus.1', 'zipball_url' => 'https://api.github.com/repos/flschr/pureblog/zipball/v3.9.2-plus.1', 'published_at' => '2026-11-15T00:00:00Z', 'draft' => false, 'prerelease' => false],
    ['tag_name' => 'v3.9.2-plus.2-beta.1', 'name' => 'Preview', 'html_url' => 'https://github.com/flschr/pureblog/releases/tag/v3.9.2-plus.2-beta.1', 'zipball_url' => 'https://api.github.com/repos/flschr/pureblog/zipball/v3.9.2-plus.2-beta.1', 'published_at' => '2026-11-16T00:00:00Z', 'draft' => false, 'prerelease' => true],
    ['tag_name' => 'v9.0.0-plus.1', 'zipball_url' => 'https://example.com/package.zip', 'draft' => false, 'prerelease' => false],
    ['tag_name' => 'v3.9.2', 'zipball_url' => 'https://api.github.com/repos/kevquirk/pureblog/zipball/v3.9.2', 'draft' => false, 'prerelease' => false],
    ['tag_name' => 'v4.0.0-plus.1', 'zipball_url' => 'https://api.github.com/repos/flschr/pureblog/zipball/v4.0.0-plus.1', 'draft' => true, 'prerelease' => false],
], JSON_THROW_ON_ERROR);
$releaseHttp = static fn(string $url, int $timeout, array $headers): array => ['ok' => true, 'body' => $releasePayload];
updater_test_assert(fetch_latest_pureblog_release($releaseHttp, false)['tag'] === 'v3.9.2-plus.1', 'Stable release selection failed.');
updater_test_assert(fetch_latest_pureblog_release($releaseHttp, true)['tag'] === 'v3.9.2-plus.2-beta.1', 'Preview release selection failed.');
$emptyHttp = static fn(string $url, int $timeout, array $headers): array => ['ok' => true, 'body' => '[]'];
updater_test_assert(fetch_latest_pureblog_release($emptyHttp, false)['ok'] === false, 'Missing Plus release did not fail closed.');
$upstreamHttp = static fn(string $url, int $timeout, array $headers): array => ['ok' => true, 'body' => '{"tag_name":"v3.9.3","html_url":"https://github.com/kevquirk/pureblog/releases/tag/v3.9.3"}'];
updater_test_assert(fetch_latest_upstream_release($upstreamHttp)['tag'] === 'v3.9.3', 'Upstream integration notice failed.');

$root = sys_get_temp_dir() . '/pureblog-plus-updater-' . bin2hex(random_bytes(8));
$releaseRoot = $root . '/release';
$successInstall = $root . '/success-install';
$rollbackInstall = $root . '/rollback-install';
$zipPath = $root . '/release.zip';

try {
    updater_test_copy_tree(PUREBLOG_BASE_PATH, $releaseRoot);
    file_put_contents($releaseRoot . '/VERSION', "3.9.2-plus.1\n");
    file_put_contents($releaseRoot . '/release-probe.txt', "installed\n");
    $manifest = build_pureblog_plus_release_manifest($releaseRoot, '3.9.2-plus.1');
    file_put_contents($releaseRoot . '/plus-release.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    updater_test_assert(validate_pureblog_plus_package($releaseRoot, 'v3.9.2-plus.1')['ok'], 'Valid Plus package rejected.');

    $originalJobs = file_get_contents($releaseRoot . '/includes/plus/jobs.php');
    file_put_contents($releaseRoot . '/includes/plus/jobs.php', $originalJobs . "\n");
    updater_test_assert(!validate_pureblog_plus_package($releaseRoot, 'v3.9.2-plus.1')['ok'], 'Tampered protected file accepted.');
    file_put_contents($releaseRoot . '/includes/plus/jobs.php', $originalJobs);

    updater_test_zip_tree($releaseRoot, $zipPath, 'flschr-pureblog-release');
    $download = static fn(string $url, string $destination): ?string => copy($zipPath, $destination) ? null : 'Fixture copy failed.';
    $plan = build_package_upgrade_plan('https://api.github.com/repos/flschr/pureblog/zipball/v3.9.2-plus.1', 'v3.9.2-plus.1', $download);
    updater_test_assert($plan['ok'] ?? false, 'Valid Plus package plan failed.');

    updater_test_copy_tree(PUREBLOG_BASE_PATH, $successInstall);
    updater_test_copy_tree(PUREBLOG_BASE_PATH, $rollbackInstall);
    foreach ([$successInstall, $rollbackInstall] as $install) {
        if (!is_dir($install . '/config')) {
            mkdir($install . '/config', 0700, true);
        }
        if (!is_dir($install . '/data')) {
            mkdir($install . '/data', 0700, true);
        }
        file_put_contents($install . '/config/update-test-sentinel', 'preserve');
        file_put_contents($install . '/content/update-test-sentinel', 'preserve');
        file_put_contents($install . '/data/update-test-sentinel', 'preserve');
        file_put_contents($install . '/.htaccess', "test htaccess\n");
    }

    $success = updater_test_worker($successInstall, 'success', $zipPath);
    updater_test_assert($success['result']['ok'] === true, 'Isolated Plus update failed.');
    updater_test_assert($success['version'] === '3.9.2-plus.1' && $success['probe'] === true, 'Plus update did not install the release.');
    updater_test_assert($success['config_preserved'] && $success['content_preserved'] && $success['data_preserved'] && $success['htaccess_preserved'], 'Preserved site data changed during update.');

    $rollback = updater_test_worker($rollbackInstall, 'rollback', $zipPath);
    updater_test_assert($rollback['result']['ok'] === false, 'Injected update failure unexpectedly succeeded.');
    updater_test_assert($rollback['version'] === '3.9.2-plus.0' && $rollback['probe'] === false, 'Failed update did not restore the previous version.');
    updater_test_assert($rollback['functions_unchanged'] && $rollback['config_preserved'] && $rollback['content_preserved'] && $rollback['data_preserved'] && $rollback['htaccess_preserved'], 'Rollback did not restore the installation.');

    $unsafeZip = new ZipArchive();
    $unsafePath = $root . '/unsafe.zip';
    updater_test_assert($unsafeZip->open($unsafePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'Unable to create unsafe archive fixture.');
    $unsafeZip->addFromString('../escape.php', '<?php');
    updater_test_assert(!pureblog_release_archive_is_safe($unsafeZip), 'Unsafe archive path accepted.');
    $unsafeZip->close();

    echo "PASS: Plus release identity, discovery, package validation, update, and rollback\n";
} finally {
    updater_test_remove_tree($root);
}

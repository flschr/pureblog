<?php

declare(strict_types=1);

const PUREBLOG_PLUS_RELEASES_API = 'https://api.github.com/repos/flschr/pureblog/releases';
const PUREBLOG_PLUS_RELEASES_URL = 'https://github.com/flschr/pureblog/releases';
const PUREBLOG_UPSTREAM_RELEASE_API = 'https://api.github.com/repos/kevquirk/pureblog/releases/latest';
const PUREBLOG_UPSTREAM_RELEASES_URL = 'https://github.com/kevquirk/pureblog/releases';
const PUREBLOG_PLUS_RELEASE_MANIFEST = 'plus-release.json';
const PUREBLOG_PLUS_LICENSE_SHA256 = '68f47aa87e0fa1ff6d422fc0de760aee6aa06955678aae568234fe6f6f1d2b78';

/**
 * @return array{version:string,upstream:string,upstream_parts:list<int>,release:int,stage:?string,stage_number:?int}|null
 */
function parse_pureblog_plus_version(string $version): ?array
{
    $value = trim($version);
    if (preg_match('/^v?(\d+)\.(\d+)\.(\d+)-plus\.(\d+)(?:-(alpha|beta|rc)\.(\d+))?$/i', $value, $matches) !== 1) {
        return null;
    }

    $upstreamParts = [(int) $matches[1], (int) $matches[2], (int) $matches[3]];
    $stage = isset($matches[5]) && $matches[5] !== '' ? strtolower($matches[5]) : null;
    $stageNumber = isset($matches[6]) && $matches[6] !== '' ? (int) $matches[6] : null;
    if (($stage === null) !== ($stageNumber === null)) {
        return null;
    }

    $normalized = implode('.', $upstreamParts) . '-plus.' . (int) $matches[4];
    if ($stage !== null) {
        $normalized .= '-' . $stage . '.' . $stageNumber;
    }

    return [
        'version' => $normalized,
        'upstream' => implode('.', $upstreamParts),
        'upstream_parts' => $upstreamParts,
        'release' => (int) $matches[4],
        'stage' => $stage,
        'stage_number' => $stageNumber,
    ];
}

function compare_pureblog_plus_versions(string $left, string $right): int
{
    $a = parse_pureblog_plus_version($left);
    $b = parse_pureblog_plus_version($right);
    if ($a === null || $b === null) {
        throw new InvalidArgumentException('Invalid Pure Blog Plus version.');
    }

    foreach ([0, 1, 2] as $index) {
        $comparison = $a['upstream_parts'][$index] <=> $b['upstream_parts'][$index];
        if ($comparison !== 0) {
            return $comparison;
        }
    }
    $comparison = $a['release'] <=> $b['release'];
    if ($comparison !== 0) {
        return $comparison;
    }
    if ($a['stage'] === null || $b['stage'] === null) {
        return $a['stage'] === $b['stage'] ? 0 : ($a['stage'] === null ? 1 : -1);
    }

    $stageOrder = ['alpha' => 0, 'beta' => 1, 'rc' => 2];
    $comparison = $stageOrder[$a['stage']] <=> $stageOrder[$b['stage']];
    return $comparison !== 0 ? $comparison : ($a['stage_number'] <=> $b['stage_number']);
}

function pureblog_plus_update_available(string $current, string $latest): bool
{
    if (parse_pureblog_plus_version($current) === null || parse_pureblog_plus_version($latest) === null) {
        return false;
    }
    return compare_pureblog_plus_versions($current, $latest) < 0;
}

function pureblog_plus_release_download_url_is_allowed(string $url): bool
{
    $parts = parse_url($url);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'api.github.com') {
        return false;
    }
    $path = (string) ($parts['path'] ?? '');
    return preg_match('#^/repos/flschr/pureblog/(?:zipball|tarball)/[^/]+$#', $path) === 1;
}

/**
 * @return list<string>
 */
function pureblog_plus_protected_release_files(): array
{
    return [
        'LICENSE.md',
        'includes/plus/runtime.php',
        'includes/plus/migrations.php',
        'includes/plus/jobs.php',
        'includes/plus/job-runner.php',
        'includes/plus/capabilities.php',
        'includes/plus/plugins.php',
        'includes/plus/media.php',
        'includes/plus/photo-gallery.php',
        'includes/plugins/local-media.php',
        'includes/plugins/s3-media.php',
        'includes/plugins/openai-alt-text.php',
        'includes/plugins/personal-photo-gallery.php',
        'includes/plus/indieauth.php',
        'includes/plus/indieauth-client.php',
        'includes/plus/indieauth-tokens.php',
        'includes/plus/micropub.php',
        'includes/plus/webmentions.php',
        'includes/plus/webmention-sender.php',
        'includes/plus/social-connector.php',
        'indieauth/authorize.php',
        'indieauth/metadata.php',
        'indieauth/token.php',
        'micropub/index.php',
        'micropub/media.php',
        'webmention/index.php',
        'admin/webmentions.php',
        'admin/gallery.php',
        'admin/settings-plugins.php',
        'admin/settings-social.php',
        'admin/social-callback.php',
        'social.php',
        'admin/media-alt-text.php',
        'admin/update-media-alt.php',
        'admin/delete-media.php',
        'admin/partials/editor-media.php',
    ];
}

/**
 * @return array{schema:int,product:string,version:string,upstream:string,protected_files:array<string,string>}
 */
function build_pureblog_plus_release_manifest(string $sourceRoot, string $version): array
{
    $parsed = parse_pureblog_plus_version($version);
    if ($parsed === null) {
        throw new InvalidArgumentException('Invalid Pure Blog Plus version.');
    }
    $checksums = [];
    foreach (pureblog_plus_protected_release_files() as $relative) {
        $checksum = @hash_file('sha256', $sourceRoot . '/' . $relative);
        if (!is_string($checksum)) {
            throw new RuntimeException('Missing protected release file: ' . $relative);
        }
        $checksums[$relative] = $checksum;
    }
    return [
        'schema' => 1,
        'product' => 'pureblog-plus',
        'version' => $parsed['version'],
        'upstream' => $parsed['upstream'],
        'protected_files' => $checksums,
    ];
}

/**
 * @return array{ok:bool,error?:string,manifest?:array<string,mixed>}
 */
function validate_pureblog_plus_package(string $sourceRoot, string $releaseTag): array
{
    $release = parse_pureblog_plus_version($releaseTag);
    if ($release === null || $release['release'] < 1) {
        return ['ok' => false, 'error' => t('admin.settings.updates.error_plus_version')];
    }

    $requiredPaths = [
        'LICENSE.md',
        'VERSION',
        'functions.php',
        'admin',
        'includes/plus/runtime.php',
        'includes/plus/migrations.php',
        'includes/plus/jobs.php',
        'includes/plus/job-runner.php',
        'includes/plus/capabilities.php',
        'includes/plus/plugins.php',
        'includes/plus/media.php',
        'includes/plugins',
        PUREBLOG_PLUS_RELEASE_MANIFEST,
    ];
    foreach ($requiredPaths as $relative) {
        if (!file_exists($sourceRoot . '/' . $relative)) {
            return ['ok' => false, 'error' => t('admin.settings.updates.error_plus_package_path', ['path' => $relative])];
        }
    }

    $manifestRaw = @file_get_contents($sourceRoot . '/' . PUREBLOG_PLUS_RELEASE_MANIFEST);
    $manifest = is_string($manifestRaw) ? json_decode($manifestRaw, true) : null;
    if (!is_array($manifest)
        || ($manifest['schema'] ?? null) !== 1
        || ($manifest['product'] ?? null) !== 'pureblog-plus'
        || !is_array($manifest['protected_files'] ?? null)) {
        return ['ok' => false, 'error' => t('admin.settings.updates.error_plus_manifest')];
    }

    $manifestVersion = parse_pureblog_plus_version((string) ($manifest['version'] ?? ''));
    $versionFile = parse_pureblog_plus_version((string) @file_get_contents($sourceRoot . '/VERSION'));
    if ($manifestVersion === null
        || $versionFile === null
        || $manifestVersion['version'] !== $release['version']
        || $versionFile['version'] !== $release['version']
        || (string) ($manifest['upstream'] ?? '') !== $release['upstream']) {
        return ['ok' => false, 'error' => t('admin.settings.updates.error_plus_identity')];
    }

    foreach (pureblog_plus_protected_release_files() as $relative) {
        $expected = strtolower((string) ($manifest['protected_files'][$relative] ?? ''));
        $actual = @hash_file('sha256', $sourceRoot . '/' . $relative);
        if (preg_match('/^[a-f0-9]{64}$/', $expected) !== 1 || !is_string($actual) || !hash_equals($expected, $actual)) {
            return ['ok' => false, 'error' => t('admin.settings.updates.error_plus_checksum', ['path' => $relative])];
        }
    }
    if (!hash_equals(PUREBLOG_PLUS_LICENSE_SHA256, (string) hash_file('sha256', $sourceRoot . '/LICENSE.md'))) {
        return ['ok' => false, 'error' => t('admin.settings.updates.error_plus_license')];
    }

    return ['ok' => true, 'manifest' => $manifest];
}

/**
 * @return list<string>
 */
function preserved_top_level_paths(): array
{
    return [
        'backup',
        'cache',
        'config',
        'content',
        'data',
        '.htaccess',
        'VERSION',
    ];
}

/**
 * Load ignore patterns from config/update-ignore.
 * Lines starting with '#' and blank lines are skipped.
 * A leading '/' is stripped so patterns match internal relative paths.
 *
 * @return list<string>
 */
function load_update_ignore_patterns(): array
{
    $path = PUREBLOG_BASE_PATH . '/config/update-ignore';
    if (!is_file($path)) {
        return [];
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return [];
    }
    $patterns = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $patterns[] = ltrim($line, '/');
    }
    return $patterns;
}

/**
 * Return true if $relative matches any of the given ignore patterns.
 * Patterns support fnmatch() globs (e.g. lang/*).
 *
 * @param list<string> $patterns
 */
function is_path_ignored(string $relative, array $patterns): bool
{
    return matched_ignore_pattern($relative, $patterns) !== null;
}

/**
 * Returns the matched pattern (exact) or the pattern with '/*' appended
 * (directory prefix match). Returns null if no pattern matches.
 */
function matched_ignore_pattern(string $relative, array $patterns): ?string
{
    foreach ($patterns as $pattern) {
        if (fnmatch($pattern, $relative)) {
            return $pattern;
        }
        if (str_starts_with($relative, rtrim($pattern, '/') . '/')) {
            return rtrim($pattern, '/') . '/*';
        }
    }
    return null;
}

function remove_directory_recursive(string $path): void
{
    if (!is_dir($path)) {
        if (is_file($path)) {
            @unlink($path);
        }
        return;
    }

    $items = scandir($path);
    if (!is_array($items)) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $itemPath = $path . '/' . $item;
        if (is_dir($itemPath)) {
            remove_directory_recursive($itemPath);
        } else {
            @unlink($itemPath);
        }
    }
    @rmdir($path);
}

function download_url_to_file(string $url, string $destination): ?string
{
    return pureblog_http_download(
        $url,
        $destination,
        20,
        ['User-Agent: Pureblog-Upgrader', 'Accept: */*']
    );
}

function is_htaccess_path(string $relativePath): bool
{
    return basename(str_replace('\\', '/', $relativePath)) === '.htaccess';
}

/**
 * Capture all existing .htaccess files so they can be restored after update.
 *
 * @return array<string,string> Map of relative path => file contents
 */
function collect_existing_htaccess_files(): array
{
    $files = [];
    $preserveTop = array_fill_keys(preserved_top_level_paths(), true);
    $all = collect_relative_files(PUREBLOG_BASE_PATH);
    foreach ($all as $relative) {
        if (!is_htaccess_path($relative)) {
            continue;
        }
        $top = (string) strtok($relative, '/');
        if (isset($preserveTop[$top]) || $top === 'backup') {
            continue;
        }
        $fullPath = PUREBLOG_BASE_PATH . '/' . $relative;
        $content = @file_get_contents($fullPath);
        if (!is_string($content)) {
            continue;
        }
        $files[$relative] = $content;
    }

    return $files;
}

/**
 * @param array<string,string> $files
 */
function restore_htaccess_files(array $files): void
{
    foreach ($files as $relative => $content) {
        $target = PUREBLOG_BASE_PATH . '/' . $relative;
        if (is_file($target)) {
            $existing = @file_get_contents($target);
            if ($existing === $content) {
                continue;
            }
        }
        $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException(t('admin.settings.updates.error_htaccess_dir', ['path' => $relative]));
        }
        if (@file_put_contents($target, $content) === false) {
            throw new RuntimeException(t('admin.settings.updates.error_htaccess_restore', ['path' => $relative]));
        }
    }
}

/**
 * Remove any .htaccess files that were not present before update.
 *
 * @param array<string,string> $preservedFiles
 */
function remove_non_preserved_htaccess(array $preservedFiles): void
{
    $preservedSet = array_fill_keys(array_keys($preservedFiles), true);
    $preserveTop = array_fill_keys(preserved_top_level_paths(), true);
    $all = collect_relative_files(PUREBLOG_BASE_PATH);
    foreach ($all as $relative) {
        if (!is_htaccess_path($relative)) {
            continue;
        }
        if (isset($preservedSet[$relative])) {
            continue;
        }
        $top = (string) strtok($relative, '/');
        // Never delete .htaccess inside preserved paths or the backup directory.
        if (isset($preserveTop[$top]) || $top === 'backup') {
            continue;
        }
        @unlink(PUREBLOG_BASE_PATH . '/' . $relative);
    }
}

function pureblog_release_archive_is_safe(ZipArchive $zip): bool
{
    if ($zip->numFiles < 1 || $zip->numFiles > 10000) {
        return false;
    }
    $totalSize = 0;
    for ($index = 0; $index < $zip->numFiles; $index++) {
        $name = $zip->getNameIndex($index);
        if (!is_string($name)) {
            return false;
        }
        $normalized = str_replace('\\', '/', $name);
        if ($normalized === ''
            || str_starts_with($normalized, '/')
            || preg_match('/^[a-zA-Z]:\//', $normalized) === 1
            || in_array('..', explode('/', $normalized), true)) {
            return false;
        }
        $stat = $zip->statIndex($index);
        $size = is_array($stat) ? (int) ($stat['size'] ?? 0) : 0;
        $totalSize += max(0, $size);
        if ($size > 64 * 1024 * 1024 || $totalSize > 256 * 1024 * 1024) {
            return false;
        }
        if (method_exists($zip, 'getExternalAttributesIndex')) {
            $operatingSystem = 0;
            $attributes = 0;
            if ($zip->getExternalAttributesIndex($index, $operatingSystem, $attributes)
                && (($attributes >> 16) & 0170000) === 0120000) {
                return false;
            }
        }
    }
    return true;
}

/**
 * Build a file-level, read-only plan from a downloaded release package.
 *
 * @return array{ok:bool,error?:string,counts?:array<string,int>,will_add?:list<string>,will_replace?:list<string>,unchanged?:list<string>,will_skip?:list<string>,local_only?:list<string>}
 */
function build_package_upgrade_plan(string $zipballUrl, string $releaseTag, ?callable $download = null): array
{
    if ($zipballUrl === '' || !pureblog_plus_release_download_url_is_allowed($zipballUrl)) {
        return ['ok' => false, 'error' => t('admin.settings.updates.error_zip_url')];
    }
    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'error' => t('admin.settings.updates.error_ziparchive')];
    }

    $tmpBase = rtrim(sys_get_temp_dir(), '/') . '/pureblog-upgrader-' . bin2hex(random_bytes(6));
    $tmpZip = $tmpBase . '.zip';
    $tmpExtract = $tmpBase . '-extract';
    @mkdir($tmpExtract, 0700, true);

    try {
        $downloadError = $download !== null
            ? $download($zipballUrl, $tmpZip)
            : download_url_to_file($zipballUrl, $tmpZip);
        if ($downloadError !== null) {
            return ['ok' => false, 'error' => $downloadError];
        }

        $zip = new ZipArchive();
        if ($zip->open($tmpZip) !== true) {
            return ['ok' => false, 'error' => t('admin.settings.updates.error_zip_open')];
        }
        if (!pureblog_release_archive_is_safe($zip)) {
            $zip->close();
            return ['ok' => false, 'error' => t('admin.settings.updates.error_zip_unsafe')];
        }
        if (!$zip->extractTo($tmpExtract)) {
            $zip->close();
            return ['ok' => false, 'error' => t('admin.settings.updates.error_zip_extract')];
        }
        $zip->close();

        $entries = array_values(array_filter(scandir($tmpExtract) ?: [], fn(string $e): bool => $e !== '.' && $e !== '..'));
        $sourceRoot = $tmpExtract;
        if (count($entries) === 1 && is_dir($tmpExtract . '/' . $entries[0])) {
            $sourceRoot = $tmpExtract . '/' . $entries[0];
        }

        $sourceFiles = collect_relative_files($sourceRoot);
        if (!$sourceFiles) {
            return ['ok' => false, 'error' => t('admin.settings.updates.error_zip_empty')];
        }
        $validation = validate_pureblog_plus_package($sourceRoot, $releaseTag);
        if (!($validation['ok'] ?? false)) {
            return $validation;
        }

        // Check for a breaking-upgrade notice bundled in the release zip.
        $breakingInstructions = null;
        $noticeFile = $sourceRoot . '/upgrade-notice.json';
        if (is_file($noticeFile)) {
            $noticeRaw = @file_get_contents($noticeFile);
            if ($noticeRaw !== false) {
                $notice = json_decode($noticeRaw, true);
                if (is_array($notice) && !empty($notice['breaking'])) {
                    $breakingInstructions = trim((string) ($notice['instructions'] ?? ''));
                }
            }
        }

        $preserveTop = preserved_top_level_paths();
        $ignorePatterns = load_update_ignore_patterns();
        $willAdd = [];
        $willReplace = [];
        $willIgnore = [];  // keyed by display label to collapse directory patterns
        $unchanged = [];
        $willSkip = [];
        $sourceCoreSet = [];

        foreach ($sourceFiles as $relative) {
            if (is_htaccess_path($relative)) {
                $willSkip[] = '/' . $relative;
                continue;
            }
            $top = strtok($relative, '/');
            if (in_array($top, $preserveTop, true)) {
                $willSkip[] = '/' . $relative;
                continue;
            }

            $sourceCoreSet[$relative] = true;
            $sourcePath = $sourceRoot . '/' . $relative;
            $targetPath = PUREBLOG_BASE_PATH . '/' . $relative;

            if (is_file($targetPath)) {
                $same = @sha1_file($sourcePath) === @sha1_file($targetPath);
                if ($same) {
                    $unchanged[] = '/' . $relative;
                } elseif (($matchedPattern = matched_ignore_pattern($relative, $ignorePatterns)) !== null) {
                    $willIgnore['/' . $matchedPattern] = '/' . $matchedPattern;
                } else {
                    $willReplace[] = '/' . $relative;
                }
            } else {
                $willAdd[] = '/' . $relative;
            }
        }

        // Build the set of top-level paths the update will actually replace.
        // The apply step only removes/replaces top-level items that exist in the release,
        // so files in directories not present in the release are never touched.
        $sourceTopItems = [];
        foreach (array_keys($sourceCoreSet) as $coreRelative) {
            $sourceTopItems[(string) strtok($coreRelative, '/')] = true;
        }

        $localOnly = [];
        $localFiles = collect_relative_files(PUREBLOG_BASE_PATH);
        foreach ($localFiles as $relative) {
            $top = (string) strtok($relative, '/');
            if (is_htaccess_path($relative)) {
                continue;
            }
            if (in_array($top, $preserveTop, true)) {
                continue;
            }
            // Ignored files always surface in will_ignore regardless of whether
            // they were actually at risk, so the plan reflects the full ignore list.
            if (!isset($sourceCoreSet[$relative])) {
                if (($matchedPattern = matched_ignore_pattern($relative, $ignorePatterns)) !== null) {
                    $willIgnore['/' . $matchedPattern] = '/' . $matchedPattern;
                } elseif (isset($sourceTopItems[$top])) {
                    // Only flag as deleted if inside a directory the update will wipe.
                    $localOnly[] = '/' . $relative;
                }
            }
        }

        sort($willAdd);
        sort($willReplace);
        sort($willIgnore);
        sort($unchanged);
        sort($willSkip);
        sort($localOnly);

        return [
            'ok' => true,
            'breaking' => $breakingInstructions !== null,
            'breaking_instructions' => $breakingInstructions ?? '',
            'counts' => [
                'add' => count($willAdd),
                'replace' => count($willReplace),
                'ignore' => count($willIgnore),
                'unchanged' => count($unchanged),
                'skip' => count($willSkip),
                'local_only' => count($localOnly),
            ],
            'will_add' => $willAdd,
            'will_replace' => $willReplace,
            'will_ignore' => array_values($willIgnore),
            'unchanged' => $unchanged,
            'will_skip' => $willSkip,
            'local_only' => $localOnly,
        ];
    } finally {
        @unlink($tmpZip);
        remove_directory_recursive($tmpExtract);
    }
}

function copy_path_recursive(string $source, string $destination): void
{
    if (is_file($source)) {
        $parent = dirname($destination);
        if (!is_dir($parent)) {
            error_clear_last();
            if (!@mkdir($parent, 0755, true) && !is_dir($parent)) {
                $phpError = error_get_last();
                $detail = $phpError !== null ? ' (' . $phpError['message'] . ')' : '';
                throw new RuntimeException(t('admin.settings.updates.error_dir_create', ['path' => $parent]) . $detail);
            }
        }
        error_clear_last();
        if (!@copy($source, $destination)) {
            $phpError = error_get_last();
            $detail = $phpError !== null ? ' (' . $phpError['message'] . ')' : '';
            throw new RuntimeException(t('admin.settings.updates.error_file_copy', ['path' => $source]) . $detail);
        }
        return;
    }

    if (!is_dir($source)) {
        throw new RuntimeException(t('admin.settings.updates.error_source_missing', ['path' => $source]));
    }

    if (!is_dir($destination)) {
        error_clear_last();
        if (!@mkdir($destination, 0755, true) && !is_dir($destination)) {
            $phpError = error_get_last();
            $detail = $phpError !== null ? ' (' . $phpError['message'] . ')' : '';
            throw new RuntimeException(t('admin.settings.updates.error_dir_create', ['path' => $destination]) . $detail);
        }
    }

    $items = scandir($source);
    if (!is_array($items)) {
        throw new RuntimeException(t('admin.settings.updates.error_dir_read', ['path' => $source]));
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $src = $source . '/' . $item;
        $dst = $destination . '/' . $item;
        if (is_dir($src)) {
            copy_path_recursive($src, $dst);
        } else {
            error_clear_last();
            if (!@copy($src, $dst)) {
                $phpError = error_get_last();
                $detail = $phpError !== null ? ' (' . $phpError['message'] . ')' : '';
                throw new RuntimeException(t('admin.settings.updates.error_file_copy', ['path' => $src]) . $detail);
            }
        }
    }
}

/**
 * @param list<string> $items Top-level item names (from the release zip) to back up.
 *                            Only backing up what the update will overwrite prevents
 *                            third-party directories (e.g. a FreshRSS install at /rss/)
 *                            from being included in the backup and subsequently deleted
 *                            during a rollback restore.
 */
function backup_core_paths(string $backupRoot, array $items): void
{
    foreach ($items as $item) {
        $src = PUREBLOG_BASE_PATH . '/' . $item;
        if (!file_exists($src)) {
            continue;
        }
        copy_path_recursive($src, $backupRoot . '/' . $item);
    }
    $versionFile = PUREBLOG_BASE_PATH . '/VERSION';
    if (file_exists($versionFile)) {
        @copy($versionFile, $backupRoot . '/VERSION');
    }
}

/**
 * @param list<string> $replaceItems
 */
function restore_core_paths_from_backup(string $backupRoot, array $replaceItems = []): void
{
    foreach ($replaceItems as $item) {
        $target = PUREBLOG_BASE_PATH . '/' . $item;
        if (file_exists($target)) {
            remove_directory_recursive($target);
        }
    }

    $items = scandir($backupRoot) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $target = PUREBLOG_BASE_PATH . '/' . $item;
        if (file_exists($target)) {
            remove_directory_recursive($target);
        }
        $backup = $backupRoot . '/' . $item;
        if (file_exists($backup)) {
            copy_path_recursive($backup, $target);
        }
    }

    // If the backup folder does not contain a VERSION file (e.g. created by earlier releases),
    // extract and restore the previous version from the backup directory name if available.
    $versionTarget = PUREBLOG_BASE_PATH . '/VERSION';
    if (!file_exists($backupRoot . '/VERSION')) {
        $backupDirName = basename($backupRoot);
        if (preg_match('/^pureblog-backup-\d{8}-\d{6}-([a-zA-Z0-9._-]+)-[a-f0-9]+$/', $backupDirName, $m)) {
            $recoveredVersion = str_replace('-', '.', $m[1]);
            @file_put_contents($versionTarget, $recoveredVersion . PHP_EOL);
        }
    }

    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }
    @unlink(PUREBLOG_BASE_PATH . '/content/.version-cache');
}

/**
 * @return list<string>
 */
function list_available_backups(): array
{
    $backupBase = PUREBLOG_BASE_PATH . '/backup';
    if (!is_dir($backupBase)) {
        return [];
    }

    $entries = scandir($backupBase);
    if (!is_array($entries)) {
        return [];
    }

    $backups = [];
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (!str_starts_with($entry, 'pureblog-backup-')) {
            continue;
        }
        $fullPath = $backupBase . '/' . $entry;
        if (is_dir($fullPath)) {
            $backups[] = $entry;
        }
    }

    rsort($backups);
    return $backups;
}

function format_backup_timestamp(string $backupName): string
{
    if (preg_match('/^pureblog-backup-(\d{8})-(\d{6})-/', $backupName, $matches) !== 1) {
        return '';
    }

    $dt = DateTime::createFromFormat('Ymd His', $matches[1] . ' ' . $matches[2]);
    if (!$dt) {
        return '';
    }

    return $dt->format('d M Y H:i:s');
}

function restore_named_backup(string $backupName): array
{
    if ($backupName === '' || $backupName !== basename($backupName)) {
        return ['ok' => false, 'error' => t('admin.settings.updates.error_backup_invalid_name')];
    }

    $backupBase = PUREBLOG_BASE_PATH . '/backup';
    $backupBaseReal = realpath($backupBase);
    if ($backupBaseReal === false) {
        return ['ok' => false, 'error' => t('admin.settings.updates.error_backup_dir_missing')];
    }

    $backupPath = $backupBaseReal . '/' . $backupName;
    $backupPathReal = realpath($backupPath);
    if ($backupPathReal === false || !is_dir($backupPathReal)) {
        return ['ok' => false, 'error' => t('admin.settings.updates.error_backup_not_found')];
    }
    if (!str_starts_with($backupPathReal, $backupBaseReal . '/')) {
        return ['ok' => false, 'error' => t('admin.settings.updates.error_backup_invalid_path')];
    }

    try {
        restore_core_paths_from_backup($backupPathReal);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => t('admin.settings.updates.error_restore_failed', ['error' => $e->getMessage()])];
    }

    return [
        'ok' => true,
        'message' => t('admin.settings.updates.notice_backup_restored'),
        'backup_path' => $backupPathReal,
    ];
}

function delete_named_backup(string $backupName): array
{
    if ($backupName === '' || $backupName !== basename($backupName)) {
        return ['ok' => false, 'error' => t('admin.settings.updates.error_backup_invalid_name')];
    }

    $backupBase = PUREBLOG_BASE_PATH . '/backup';
    $backupBaseReal = realpath($backupBase);
    if ($backupBaseReal === false) {
        return ['ok' => false, 'error' => t('admin.settings.updates.error_backup_dir_missing')];
    }

    $backupPath = $backupBaseReal . '/' . $backupName;
    $backupPathReal = realpath($backupPath);
    if ($backupPathReal === false || !is_dir($backupPathReal)) {
        return ['ok' => false, 'error' => t('admin.settings.updates.error_backup_not_found')];
    }
    if (!str_starts_with($backupPathReal, $backupBaseReal . '/')) {
        return ['ok' => false, 'error' => t('admin.settings.updates.error_backup_invalid_path')];
    }

    try {
        remove_directory_recursive($backupPathReal);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => t('admin.settings.updates.error_delete_backup_failed', ['error' => $e->getMessage()])];
    }

    return [
        'ok' => true,
        'message' => t('admin.settings.updates.notice_backup_deleted'),
    ];
}

function apply_release_update(
    string $zipballUrl,
    string $releaseTag = '',
    ?callable $download = null,
    ?callable $copyPath = null
): array
{
    if ($zipballUrl === '' || !pureblog_plus_release_download_url_is_allowed($zipballUrl)) {
        return ['ok' => false, 'error' => t('admin.settings.updates.error_zip_url')];
    }
    if (parse_pureblog_plus_version($releaseTag) === null) {
        return ['ok' => false, 'error' => t('admin.settings.updates.error_plus_version')];
    }
    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'error' => t('admin.settings.updates.error_ziparchive')];
    }

    $tmpBase = rtrim(sys_get_temp_dir(), '/') . '/pureblog-upgrader-' . bin2hex(random_bytes(6));
    $tmpZip = $tmpBase . '.zip';
    $tmpExtract = $tmpBase . '-extract';
    $backupBase = PUREBLOG_BASE_PATH . '/backup';
    $versionRaw = detect_current_pureblog_version();
    $versionSlug = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $versionRaw);
    if (!is_string($versionSlug) || $versionSlug === '') {
        $versionSlug = 'unknown';
    }
    $tmpBackup = $backupBase . '/pureblog-backup-' . date('Ymd-His') . '-' . $versionSlug . '-' . bin2hex(random_bytes(4));
    $mutationStarted = false;
    $copyPath ??= 'copy_path_recursive';
    $coreItems = [];
    @mkdir($tmpExtract, 0700, true);

    try {
        $downloadError = $download !== null
            ? $download($zipballUrl, $tmpZip)
            : download_url_to_file($zipballUrl, $tmpZip);
        if ($downloadError !== null) {
            return ['ok' => false, 'error' => $downloadError];
        }

        $zip = new ZipArchive();
        if ($zip->open($tmpZip) !== true) {
            return ['ok' => false, 'error' => t('admin.settings.updates.error_zip_open')];
        }
        if (!pureblog_release_archive_is_safe($zip)) {
            $zip->close();
            return ['ok' => false, 'error' => t('admin.settings.updates.error_zip_unsafe')];
        }
        if (!$zip->extractTo($tmpExtract)) {
            $zip->close();
            return ['ok' => false, 'error' => t('admin.settings.updates.error_zip_extract')];
        }
        $zip->close();

        $entries = array_values(array_filter(scandir($tmpExtract) ?: [], fn(string $e): bool => $e !== '.' && $e !== '..'));
        $sourceRoot = $tmpExtract;
        if (count($entries) === 1 && is_dir($tmpExtract . '/' . $entries[0])) {
            $sourceRoot = $tmpExtract . '/' . $entries[0];
        }

        $validation = validate_pureblog_plus_package($sourceRoot, $releaseTag);
        if (!($validation['ok'] ?? false)) {
            return $validation;
        }

        if (!is_dir($backupBase) && !@mkdir($backupBase, 0755, true) && !is_dir($backupBase)) {
            return ['ok' => false, 'error' => t('admin.settings.updates.error_backup_dir')];
        }
        if (!@mkdir($tmpBackup, 0700, true) && !is_dir($tmpBackup)) {
            return ['ok' => false, 'error' => t('admin.settings.updates.error_backup_dir')];
        }

        // Determine which top-level items the release zip will overwrite.
        // This list drives both the backup (so only PB-owned paths are saved)
        // and the copy loop below, avoiding any contact with third-party
        // directories that happen to share the webroot.
        $preserveTop = preserved_top_level_paths();
        $coreItems = array_values(array_filter(
            scandir($sourceRoot) ?: [],
            fn(string $item): bool =>
                $item !== '.' && $item !== '..' &&
                !is_htaccess_path($item) &&
                !in_array($item, $preserveTop, true)
        ));

        // Save content of files the user has opted to ignore so they can be
        // restored after the wipe-and-copy. Only files that already exist
        // locally are saved; new files from the release are always written.
        $ignorePatterns = load_update_ignore_patterns();
        $savedIgnoredFiles = [];
        if ($ignorePatterns) {
            foreach (collect_relative_files(PUREBLOG_BASE_PATH) as $relative) {
                if (is_path_ignored($relative, $ignorePatterns)) {
                    $content = @file_get_contents(PUREBLOG_BASE_PATH . '/' . $relative);
                    if (is_string($content)) {
                        $savedIgnoredFiles[$relative] = $content;
                    }
                }
            }
        }

        $preservedHtaccessFiles = collect_existing_htaccess_files();
        backup_core_paths($tmpBackup, $coreItems);
        $mutationStarted = true;

        foreach ($coreItems as $item) {
            $source = $sourceRoot . '/' . $item;
            $target = PUREBLOG_BASE_PATH . '/' . $item;

            if (file_exists($target)) {
                remove_directory_recursive($target);
            }
            $copyPath($source, $target);
        }

        // Set /VERSION from the release tag (zipballs may omit /VERSION).
        $versionFile = PUREBLOG_BASE_PATH . '/VERSION';
        $versionFromTag = normalize_version_label($releaseTag);
        if ($versionFromTag !== 'unknown') {
            @file_put_contents($versionFile, $versionFromTag . PHP_EOL);
        }

        restore_htaccess_files($preservedHtaccessFiles);
        remove_non_preserved_htaccess($preservedHtaccessFiles);

        // Restore files the user has opted to ignore.
        foreach ($savedIgnoredFiles as $relative => $content) {
            $target = PUREBLOG_BASE_PATH . '/' . $relative;
            $dir = dirname($target);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            @file_put_contents($target, $content);
        }

        // Flush the opcode cache so the next request immediately picks up
        // the newly written files rather than the pre-update cached bytecode.
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        @unlink(PUREBLOG_BASE_PATH . '/content/.version-cache');

        return [
            'ok' => true,
            'message' => t('admin.settings.updates.notice_update_applied'),
            'backup_path' => $tmpBackup,
        ];
    } catch (Throwable $e) {
        if (!$mutationStarted) {
            if (is_dir($tmpBackup)) {
                remove_directory_recursive($tmpBackup);
            }
            return [
                'ok' => false,
                'error' => t('admin.settings.updates.error_update_not_applied', ['error' => $e->getMessage()]),
            ];
        }
        try {
            if (is_dir($tmpBackup)) {
                restore_core_paths_from_backup($tmpBackup, $coreItems);
            }
        } catch (Throwable $restoreError) {
            return [
                'ok' => false,
                'error' => t('admin.settings.updates.error_update_rollback_fail', ['error' => $restoreError->getMessage()]),
            ];
        }
        return [
            'ok' => false,
            'error' => t('admin.settings.updates.error_update_rolled_back', ['error' => $e->getMessage()]),
        ];
    } finally {
        @unlink($tmpZip);
        remove_directory_recursive($tmpExtract);
    }
}

function fetch_latest_pureblog_release(?callable $httpGet = null, ?bool $includePrereleases = null): array
{
    $config = function_exists('load_config') ? load_config() : [];
    $includePrereleases ??= !empty($config['beta_updates']);
    $httpGet ??= 'pureblog_http_get';
    $result = $httpGet(
        PUREBLOG_PLUS_RELEASES_API . '?per_page=100',
        5,
        [
            'User-Agent: PureBlog-Plus-Updates',
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
        ]
    );
    if (!is_array($result) || !($result['ok'] ?? false)) {
        return ['ok' => false, 'error' => (string) ($result['error'] ?? t('admin.settings.updates.error_release_metadata'))];
    }

    $json = json_decode((string) ($result['body'] ?? ''), true);
    if (!is_array($json)) {
        return ['ok' => false, 'error' => t('admin.settings.updates.error_github_json')];
    }

    $releases = [];
    foreach ($json as $release) {
        if (!is_array($release) || !empty($release['draft'])) {
            continue;
        }
        $tag = (string) ($release['tag_name'] ?? '');
        $parsed = parse_pureblog_plus_version($tag);
        $zipballUrl = (string) ($release['zipball_url'] ?? '');
        $isPrerelease = !empty($release['prerelease']) || ($parsed['stage'] ?? null) !== null;
        if ($parsed === null
            || $parsed['release'] < 1
            || (!$includePrereleases && $isPrerelease)
            || !pureblog_plus_release_download_url_is_allowed($zipballUrl)) {
            continue;
        }
        $releases[] = [
            'ok' => true,
            'tag' => $tag,
            'name' => (string) ($release['name'] ?? ''),
            'url' => (string) ($release['html_url'] ?? PUREBLOG_PLUS_RELEASES_URL),
            'zipball_url' => $zipballUrl,
            'published_at' => (string) ($release['published_at'] ?? ''),
            'upstream' => $parsed['upstream'],
            'prerelease' => $isPrerelease,
        ];
    }
    if ($releases === []) {
        return ['ok' => false, 'error' => t('admin.settings.updates.error_no_plus_release')];
    }
    usort($releases, static fn(array $a, array $b): int => compare_pureblog_plus_versions($b['tag'], $a['tag']));
    return $releases[0];
}

function fetch_latest_upstream_release(?callable $httpGet = null): array
{
    $httpGet ??= 'pureblog_http_get';
    $result = $httpGet(
        PUREBLOG_UPSTREAM_RELEASE_API,
        5,
        [
            'User-Agent: PureBlog-Plus-Updates',
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
        ]
    );
    if (!is_array($result) || !($result['ok'] ?? false)) {
        return ['ok' => false, 'error' => (string) ($result['error'] ?? t('admin.settings.updates.error_release_metadata'))];
    }
    $json = json_decode((string) ($result['body'] ?? ''), true);
    if (!is_array($json) || preg_match('/^v?\d+\.\d+\.\d+$/', (string) ($json['tag_name'] ?? '')) !== 1) {
        return ['ok' => false, 'error' => t('admin.settings.updates.error_github_json')];
    }
    return [
        'ok' => true,
        'tag' => (string) $json['tag_name'],
        'url' => (string) ($json['html_url'] ?? PUREBLOG_UPSTREAM_RELEASES_URL),
        'published_at' => (string) ($json['published_at'] ?? ''),
    ];
}

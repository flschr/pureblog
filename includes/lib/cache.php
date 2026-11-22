<?php

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Page cache
// ---------------------------------------------------------------------------

function cache_should_bypass(array $config): bool
{
    if (empty($config['cache']['enabled'])) {
        return true;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        return true;
    }
    if (isset($_GET['q']) || isset($_GET['partial'])) {
        return true;
    }
    $hasSession = !empty($_COOKIE[session_name()]);
    $hasRememberMe = !empty($_COOKIE[get_remember_me_cookie_name()]);

    if ($hasSession || $hasRememberMe) {
        start_admin_session();
        maybe_restore_admin_from_cookie();
        if (is_admin_logged_in()) {
            return true;
        }
    }
    return false;
}

function get_cache_file_path(string $key, string $ext = 'html'): string
{
    return PUREBLOG_CACHE_PATH . '/' . md5($key) . '.' . $ext;
}

function cache_read(string $key, int $ttl = 0, string $ext = 'html'): ?string
{
    $path = get_cache_file_path($key, $ext);
    if (!is_file($path)) {
        return null;
    }
    if ($ttl > 0 && (time() - filemtime($path)) > $ttl) {
        @unlink($path);
        return null;
    }
    $content = file_get_contents($path);
    return $content !== false ? $content : null;
}

function cache_write(string $key, string $content, string $ext = 'html'): void
{
    if (!is_dir(PUREBLOG_CACHE_PATH)) {
        mkdir(PUREBLOG_CACHE_PATH, 0755, true);
    }
    $timestamp = gmdate('Y-m-d H:i:s') . ' UTC';
    if ($ext === 'xml') {
        $content = str_replace('</rss>', '<!-- Cached at ' . $timestamp . " -->\n</rss>", $content);
    } else {
        $content .= "\n<!-- Cached at " . $timestamp . ' -->';
    }
    file_put_contents(get_cache_file_path($key, $ext), $content, LOCK_EX);
}

function cache_clear(): void
{
    if (!is_dir(PUREBLOG_CACHE_PATH)) {
        return;
    }
    $files = glob(PUREBLOG_CACHE_PATH . '/*') ?: [];
    foreach ($files as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
    $ogDir = PUREBLOG_CACHE_PATH . '/og';
    if (is_dir($ogDir)) {
        $ogFiles = glob($ogDir . '/*') ?: [];
        foreach ($ogFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }
}

// ---------------------------------------------------------------------------
// Version helpers
// ---------------------------------------------------------------------------

function detect_current_pureblog_version(): string
{
    $versionFile = PUREBLOG_BASE_PATH . '/VERSION';
    if (is_file($versionFile)) {
        $raw = @file_get_contents($versionFile);
        if (is_string($raw)) {
            $fromFile = trim($raw);
            if ($fromFile !== '') {
                return $fromFile;
            }
        }
    }

    if (defined('PUREBLOG_VERSION') && is_string(PUREBLOG_VERSION) && PUREBLOG_VERSION !== '' && strtolower(PUREBLOG_VERSION) !== 'unknown') {
        return PUREBLOG_VERSION;
    }

    return 'unknown';
}


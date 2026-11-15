<?php

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Session, CSRF, and login helpers
// ---------------------------------------------------------------------------

function start_admin_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => base_path() ?: '/',
            'secure' => request_is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function request_is_https(): bool
{
    $config = load_config();
    $baseUrl = trim((string) ($config['base_url'] ?? ''));
    if ($baseUrl !== '') {
        return strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME)) === 'https';
    }

    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
}

function request_ip_matches_network(string $address, string $network): bool
{
    [$networkAddress, $prefixText] = array_pad(explode('/', trim($network), 2), 2, '');
    $addressBytes = @inet_pton($address);
    $networkBytes = @inet_pton($networkAddress);
    if ($addressBytes === false || $networkBytes === false || strlen($addressBytes) !== strlen($networkBytes)) {
        return false;
    }
    $maximumBits = strlen($addressBytes) * 8;
    $prefix = $prefixText === '' ? $maximumBits : filter_var($prefixText, FILTER_VALIDATE_INT);
    if ($prefix === false || $prefix < 0 || $prefix > $maximumBits) {
        return false;
    }
    $wholeBytes = intdiv((int) $prefix, 8);
    $remainingBits = (int) $prefix % 8;
    if ($wholeBytes > 0 && substr($addressBytes, 0, $wholeBytes) !== substr($networkBytes, 0, $wholeBytes)) {
        return false;
    }
    if ($remainingBits === 0) {
        return true;
    }
    $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
    return (ord($addressBytes[$wholeBytes]) & $mask) === (ord($networkBytes[$wholeBytes]) & $mask);
}

function request_client_ip(): string
{
    $remoteAddress = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    $configured = load_config()['trusted_proxies'] ?? [];
    if (is_string($configured)) {
        $configured = preg_split('/[\s,]+/', $configured, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
    $networks = is_array($configured) ? $configured : [];
    $isTrusted = static function (string $address) use ($networks): bool {
        foreach ($networks as $network) {
            if (request_ip_matches_network($address, (string) $network)) return true;
        }
        return false;
    };
    if (!$isTrusted($remoteAddress)) {
        return filter_var($remoteAddress, FILTER_VALIDATE_IP) !== false ? $remoteAddress : 'unknown';
    }

    $chain = array_values(array_filter(array_map('trim', explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))),
        static fn(string $candidate): bool => filter_var($candidate, FILTER_VALIDATE_IP) !== false));
    $chain[] = $remoteAddress;
    for ($index = count($chain) - 1; $index >= 0; $index--) {
        if (!$isTrusted($chain[$index])) return $chain[$index];
    }
    return $chain[0] ?? 'unknown';
}

function csrf_token(): string
{
    start_admin_session();
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    $token = csrf_token();
    return '<input type="hidden" name="csrf_token" value="' . e($token) . '">';
}

function verify_csrf(): void
{
    start_admin_session();
    $token = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';

    $sessionToken = $_SESSION['csrf_token'] ?? '';
    if ($token === '' || !is_string($sessionToken) || !hash_equals($sessionToken, $token)) {
        http_response_code(403);
        exit('Invalid CSRF token.');
    }
}

function is_admin_logged_in(): bool
{
    return !empty($_SESSION['is_admin']);
}

function set_admin_login_return_path(string $path): void
{
    start_admin_session();
    $base = base_path();
    $expectedPrefix = ($base !== '' ? $base : '') . '/';
    if ($path === ''
        || !str_starts_with($path, $expectedPrefix)
        || str_starts_with($path, '//')
        || parse_url($path, PHP_URL_HOST) !== null) {
        throw new InvalidArgumentException('Invalid admin login return path.');
    }
    $_SESSION['admin_login_return_path'] = $path;
}

function admin_login_destination(array $config): string
{
    start_admin_session();
    $returnPath = $_SESSION['admin_login_return_path'] ?? '';
    unset($_SESSION['admin_login_return_path']);
    if (is_string($returnPath) && $returnPath !== '') {
        return $returnPath;
    }
    $blogPostsEnabled = $config['enable_blog_posts'] ?? true;
    $adminLanding = (!$blogPostsEnabled || ($config['admin_homepage'] ?? 'dashboard') === 'content')
        ? 'content.php'
        : 'dashboard.php';
    return base_path() . '/admin/' . $adminLanding;
}

function get_login_failure_state(string $ip): array
{
    $query = plus_database()->prepare(
        'SELECT failures,lockout_until FROM plus_login_failures WHERE client_key=?'
    );
    $query->execute([$ip]);
    $row = $query->fetch();
    if (!is_array($row)) {
        return ['failures' => 0, 'lockout_until' => 0];
    }
    return [
        'failures'      => (int) $row['failures'],
        'lockout_until' => (int) $row['lockout_until'],
    ];
}

function record_login_failure(string $ip): array
{
    $database = plus_database();
    $now = time();
    plus_begin_immediate($database);
    try {
        $database->prepare(
            'DELETE FROM plus_login_failures WHERE lockout_until>0 AND lockout_until<?'
        )->execute([$now - 3600]);
        $query = $database->prepare(
            'SELECT failures FROM plus_login_failures WHERE client_key=?'
        );
        $query->execute([$ip]);
        $failures = (int) ($query->fetchColumn() ?: 0) + 1;
        $lockoutUntil = $failures >= 5 ? $now + (5 * 60) : 0;
        $database->prepare(
            'INSERT INTO plus_login_failures (client_key,failures,lockout_until,updated_at) '
            . 'VALUES (?,?,?,?) ON CONFLICT(client_key) DO UPDATE SET '
            . 'failures=excluded.failures,lockout_until=excluded.lockout_until,updated_at=excluded.updated_at'
        )->execute([$ip, $failures, $lockoutUntil, $now]);
        $database->exec('COMMIT');
        return ['failures' => $failures, 'lockout_until' => $lockoutUntil];
    } catch (Throwable $error) {
        if ($database->inTransaction()) {
            $database->exec('ROLLBACK');
        }
        throw $error;
    }
}

function clear_login_failures(string $ip): void
{
    plus_database()->prepare('DELETE FROM plus_login_failures WHERE client_key=?')->execute([$ip]);
}

// Derive unique remember-me cookie name based on site URL to prevent collisions.
function get_remember_me_cookie_name(): string
{
    return 'pb_remember_' . substr(hash('sha256', get_base_url()), 0, 8);
}

function set_remember_me_cookie(): void
{
    $selector  = bin2hex(random_bytes(16));
    $validator = bin2hex(random_bytes(32));
    $expires   = time() + (90 * 24 * 60 * 60);

    $now = time();
    $database = plus_database();
    plus_begin_immediate($database);
    try {
        $database->prepare('DELETE FROM plus_remember_tokens WHERE expires_at<?')->execute([$now]);
        $database->prepare(
            'INSERT INTO plus_remember_tokens (selector,validator_hash,expires_at,created_at) VALUES (?,?,?,?)'
        )->execute([$selector, hash('sha256', $validator), $expires, $now]);
        $database->exec('COMMIT');
    } catch (Throwable $error) {
        if ($database->inTransaction()) {
            $database->exec('ROLLBACK');
        }
        throw $error;
    }

    setcookie(get_remember_me_cookie_name(), $selector . ':' . $validator, [
        'expires'  => $expires,
        'path'     => base_path() ?: '/',
        'secure'   => request_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function clear_remember_me_cookie(): void
{
    $cookieName = get_remember_me_cookie_name();
    $cookie = $_COOKIE[$cookieName] ?? '';
    if ($cookie !== '') {
        $parts = explode(':', $cookie, 2);
        if (count($parts) === 2) {
            plus_database()->prepare('DELETE FROM plus_remember_tokens WHERE selector=?')->execute([$parts[0]]);
        }
    }

    setcookie($cookieName, '', [
        'expires'  => time() - 3600,
        'path'     => base_path() ?: '/',
        'secure'   => request_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function clear_all_remember_me_tokens(): void
{
    plus_database()->exec('DELETE FROM plus_remember_tokens');
}

function maybe_restore_admin_from_cookie(): void
{
    if (is_admin_logged_in()) {
        return;
    }
    $cookieName = get_remember_me_cookie_name();
    $cookie = $_COOKIE[$cookieName] ?? '';
    if ($cookie === '') {
        return;
    }
    $parts = explode(':', $cookie, 2);
    if (count($parts) !== 2) {
        return;
    }
    [$selector, $validator] = $parts;

    $query = plus_database()->prepare(
        'SELECT validator_hash,expires_at FROM plus_remember_tokens WHERE selector=?'
    );
    $query->execute([$selector]);
    $entry = $query->fetch();
    if (!is_array($entry)) {
        return;
    }
    if ((int) $entry['expires_at'] < time()) {
        plus_database()->prepare('DELETE FROM plus_remember_tokens WHERE selector=?')->execute([$selector]);
        return;
    }

    if (!hash_equals((string) ($entry['validator_hash'] ?? ''), hash('sha256', $validator))) {
        return;
    }

    $newSelector = bin2hex(random_bytes(16));
    $newValidator = bin2hex(random_bytes(32));
    $database = plus_database();
    plus_begin_immediate($database);
    try {
        $delete = $database->prepare('DELETE FROM plus_remember_tokens WHERE selector=? AND validator_hash=?');
        $delete->execute([$selector, (string) $entry['validator_hash']]);
        if ($delete->rowCount() !== 1) {
            $database->exec('ROLLBACK');
            return;
        }
        $database->prepare(
            'INSERT INTO plus_remember_tokens (selector,validator_hash,expires_at,created_at) VALUES (?,?,?,?)'
        )->execute([$newSelector, hash('sha256', $newValidator), (int) $entry['expires_at'], time()]);
        $database->exec('COMMIT');
    } catch (Throwable $error) {
        if ($database->inTransaction()) $database->exec('ROLLBACK');
        throw $error;
    }
    setcookie(get_remember_me_cookie_name(), $newSelector . ':' . $newValidator, [
        'expires' => (int) $entry['expires_at'], 'path' => base_path() ?: '/',
        'secure' => request_is_https(), 'httponly' => true, 'samesite' => 'Lax',
    ]);

    session_regenerate_id(true);
    $_SESSION['is_admin'] = true;
}

function require_admin_login(): void
{
    maybe_restore_admin_from_cookie();
    if (!is_admin_logged_in()) {
        header('Location: ' . base_path() . '/admin/index.php');
        exit;
    }
}

// Set site-specific session name to prevent collisions across multiple pureblog installations.
if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    session_name('pb_session_' . substr(hash('sha256', get_base_url()), 0, 8));
}

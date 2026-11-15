<?php

declare(strict_types=1);

function indieauth_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function indieauth_test_throws(callable $operation, string $oauthError): void
{
    try {
        $operation();
    } catch (IndieAuthProtocolException $error) {
        indieauth_test_assert($error->oauthError === $oauthError, 'Unexpected protocol error: ' . $error->oauthError);
        return;
    }
    throw new RuntimeException('Expected IndieAuth protocol error: ' . $oauthError);
}

function indieauth_test_remove_tree(string $path): void
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

$root = sys_get_temp_dir() . '/pureblog-plus-indieauth-' . bin2hex(random_bytes(8));
define('PUREBLOG_PLUS_DATA_PATH', $root . '/plus');

try {
    require dirname(__DIR__) . '/functions.php';

    indieauth_test_assert(plus_has_capability('indieauth.server', 1, 0), 'IndieAuth capability missing.');
    indieauth_test_assert(!is_dir(PUREBLOG_PLUS_DATA_PATH), 'IndieAuth registration initialized storage eagerly.');
    indieauth_test_assert(indieauth_canonical_me() === 'http://localhost/', 'Canonical profile URL is wrong.');
    indieauth_test_assert(indieauth_server_available(), 'Local development origin was not available.');
    indieauth_test_throws(static fn(): string => indieauth_validate_client_id('https://client.example'), 'invalid_request');
    indieauth_test_throws(static fn(): string => indieauth_validate_redirect_uri('javascript:alert(1)'), 'invalid_request');

    start_admin_session();
    set_admin_login_return_path('/indieauth/authorize.php');
    $_SESSION['mfa_pending'] = true;
    indieauth_test_assert($_SESSION['admin_login_return_path'] === '/indieauth/authorize.php', 'MFA did not preserve the authorization return path.');
    unset($_SESSION['mfa_pending']);
    indieauth_test_assert(admin_login_destination(default_config()) === '/indieauth/authorize.php', 'Admin login did not return to authorization.');
    indieauth_test_assert(admin_login_destination(default_config()) === '/admin/dashboard.php', 'Admin login return path was not one-time.');
    $unsafeReturnRejected = false;
    try {
        set_admin_login_return_path('//client.example/callback');
    } catch (InvalidArgumentException) {
        $unsafeReturnRejected = true;
    }
    indieauth_test_assert($unsafeReturnRejected, 'External admin return path was accepted.');

    $verifier = str_repeat('A', 43);
    $challenge = indieauth_base64url(hash('sha256', $verifier, true));
    $fetchCalled = false;
    $sameOrigin = indieauth_validate_authorization_request([
        'response_type' => 'code',
        'client_id' => 'https://client.example/app',
        'redirect_uri' => 'https://client.example/callback',
        'state' => 'same-origin-state',
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
        'scope' => 'profile create update delete',
        'me' => 'http://localhost/',
    ], static function () use (&$fetchCalled): array {
        $fetchCalled = true;
        return ['ok' => false];
    });
    indieauth_test_assert(!$fetchCalled, 'Same-origin redirect unnecessarily fetched client metadata.');
    indieauth_test_assert($sameOrigin['scope'] === ['profile', 'create', 'update', 'delete'], 'Scopes were not normalized.');

    $metadataFetcher = static fn(string $url): array => [
        'ok' => true,
        'body' => json_encode([
            'client_id' => 'https://native.example/app',
            'client_name' => 'Native Editor',
            'client_uri' => 'https://native.example/',
            'redirect_uris' => ['editor-app:/callback', '/web-callback'],
        ], JSON_THROW_ON_ERROR),
        'content_type' => 'application/json',
    ];
    $native = indieauth_validate_authorization_request([
        'response_type' => 'code',
        'client_id' => 'https://native.example/app',
        'redirect_uri' => 'editor-app:/callback',
        'state' => 'native-state',
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
        'scope' => 'create media',
    ], $metadataFetcher);
    indieauth_test_assert($native['client_name'] === 'Native Editor', 'Client metadata name was not used.');
    indieauth_test_throws(static function () use ($challenge, $metadataFetcher): void {
        indieauth_validate_authorization_request([
            'response_type' => 'code',
            'client_id' => 'https://native.example/app',
            'redirect_uri' => 'other-app:/callback',
            'state' => 'bad-redirect',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'scope' => 'create',
        ], $metadataFetcher);
    }, 'invalid_request');
    indieauth_test_throws(static function () use ($challenge): void {
        indieauth_validate_authorization_request([
            'response_type' => 'code',
            'client_id' => 'https://client.example/app',
            'redirect_uri' => 'https://client.example/callback',
            'state' => 'plain-pkce',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'plain',
        ]);
    }, 'invalid_request');
    indieauth_test_throws(static fn(): string => indieauth_validate_client_id('https://192.168.1.10/app'), 'invalid_request');
    indieauth_test_assert(indieauth_resolve_public_ips('localhost') === [], 'Localhost was considered a public metadata host.');

    $code = indieauth_issue_authorization_code(
        (string) $sameOrigin['client_id'],
        (string) $sameOrigin['redirect_uri'],
        (string) $sameOrigin['me'],
        ['create', 'update', 'delete'],
        $challenge,
        1000
    );
    $database = plus_database();
    $storedCode = (string) $database->query('SELECT code_hash FROM plus_indieauth_codes LIMIT 1')->fetchColumn();
    indieauth_test_assert($storedCode === hash('sha256', $code) && $storedCode !== $code, 'Authorization code was stored in plaintext.');
    indieauth_test_throws(static function () use ($code, $sameOrigin): void {
        indieauth_exchange_authorization_code(
            $code,
            (string) $sameOrigin['client_id'],
            (string) $sameOrigin['redirect_uri'],
            str_repeat('B', 43),
            true,
            1001
        );
    }, 'invalid_grant');

    $tokenResponse = indieauth_exchange_authorization_code(
        $code,
        (string) $sameOrigin['client_id'],
        (string) $sameOrigin['redirect_uri'],
        $verifier,
        true,
        1002
    );
    $token = (string) ($tokenResponse['access_token'] ?? '');
    indieauth_test_assert($token !== '' && $tokenResponse['token_type'] === 'Bearer', 'Access token was not issued.');
    $storedToken = (string) $database->query('SELECT token_hash FROM plus_indieauth_tokens LIMIT 1')->fetchColumn();
    indieauth_test_assert($storedToken === hash('sha256', $token) && $storedToken !== $token, 'Access token was stored in plaintext.');
    indieauth_test_throws(static function () use ($code, $sameOrigin, $verifier): void {
        indieauth_exchange_authorization_code(
            $code,
            (string) $sameOrigin['client_id'],
            (string) $sameOrigin['redirect_uri'],
            $verifier,
            true,
            1003
        );
    }, 'invalid_grant');

    $verified = indieauth_verify_access_token($token, 'create', 1004);
    indieauth_test_assert(is_array($verified) && in_array('delete', $verified['scope'], true), 'Valid access token was not verified.');
    indieauth_test_assert(indieauth_verify_access_token($token, 'media', 1005) === null, 'Token was accepted for an ungranted scope.');
    indieauth_test_assert(indieauth_revoke_access_token((int) $verified['id'], 1006), 'Token was not revoked.');
    indieauth_test_assert(indieauth_verify_access_token($token, 'create', 1007) === null, 'Revoked token was accepted.');

    $expired = indieauth_issue_authorization_code('https://client.example/app', 'https://client.example/callback', indieauth_canonical_me(), ['create'], $challenge, 2000);
    indieauth_test_throws(static function () use ($expired, $verifier): void {
        indieauth_exchange_authorization_code($expired, 'https://client.example/app', 'https://client.example/callback', $verifier, true, 2301);
    }, 'invalid_grant');

    $loginCode = indieauth_issue_authorization_code('https://client.example/app', 'https://client.example/callback', indieauth_canonical_me(), [], $challenge, 3000);
    $loginResponse = indieauth_exchange_authorization_code($loginCode, 'https://client.example/app', 'https://client.example/callback', $verifier, false, 3001);
    indieauth_test_assert($loginResponse['me'] === indieauth_canonical_me() && !isset($loginResponse['access_token']), 'Profile-only exchange returned the wrong response.');

    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer header-token';
    $_POST['access_token'] = 'form-token';
    indieauth_test_assert(indieauth_bearer_token() === 'header-token', 'Bearer header did not take precedence.');
    unset($_SERVER['HTTP_AUTHORIZATION']);
    $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer forwarded-token';
    indieauth_test_assert(indieauth_bearer_token() === 'forwarded-token', 'Forwarded bearer header was not accepted.');
    unset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    indieauth_test_assert(indieauth_bearer_token() === 'form-token', 'Form bearer token was not accepted.');

    echo "PASS: IndieAuth discovery contract, clients, PKCE, one-time codes, hashed tokens, scopes, and revocation\n";
} finally {
    indieauth_test_remove_tree($root);
}

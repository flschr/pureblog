<?php

declare(strict_types=1);

final class IndieAuthProtocolException extends RuntimeException
{
    public function __construct(
        public readonly string $oauthError,
        string $message,
        public readonly int $httpStatus = 400
    ) {
        parent::__construct($message);
    }
}

require_once __DIR__ . '/indieauth-client.php';
require_once __DIR__ . '/indieauth-tokens.php';

function indieauth_base_url(): string
{
    return rtrim(get_base_url(), '/');
}

function indieauth_issuer(): string
{
    return indieauth_base_url() . '/';
}

function indieauth_server_available(): bool
{
    $parts = parse_url(indieauth_base_url());
    if (!is_array($parts)) {
        return false;
    }
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower((string) ($parts['host'] ?? ''));
    if ($host === ''
        || isset($parts['user'])
        || isset($parts['pass'])
        || isset($parts['query'])
        || isset($parts['fragment'])) {
        return false;
    }
    if ($scheme === 'http') {
        return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }
    return $scheme === 'https'
        && !isset($parts['port'])
        && filter_var($host, FILTER_VALIDATE_IP) === false;
}

function indieauth_canonical_me(): string
{
    return indieauth_issuer();
}

function indieauth_endpoint_url(string $path): string
{
    return indieauth_base_url() . '/' . ltrim($path, '/');
}

function indieauth_send_interaction_headers(): void
{
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
    header('X-Frame-Options: DENY');
}

/**
 * @return list<string>
 */
function indieauth_supported_scopes(): array
{
    return ['profile', 'create', 'update', 'delete', 'media'];
}

/**
 * @return list<string>
 */
function indieauth_parse_scopes(string $scope): array
{
    $scopes = preg_split('/\s+/', trim($scope), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $normalized = [];
    foreach ($scopes as $value) {
        $value = strtolower($value);
        if (preg_match('/^[a-z][a-z0-9:_-]{0,63}$/', $value) !== 1) {
            throw new IndieAuthProtocolException('invalid_scope', 'The requested scope is invalid.');
        }
        $normalized[$value] = true;
    }
    return array_keys($normalized);
}

/**
 * @param array<string,mixed> $input
 * @return array<string,mixed>
 */
function indieauth_validate_authorization_request(array $input, ?callable $clientFetcher = null): array
{
    if ((string) ($input['response_type'] ?? '') !== 'code') {
        throw new IndieAuthProtocolException('unsupported_response_type', 'Only the authorization code flow is supported.');
    }
    $state = (string) ($input['state'] ?? '');
    if ($state === '' || strlen($state) > 512) {
        throw new IndieAuthProtocolException('invalid_request', 'A valid state parameter is required.');
    }
    $challenge = (string) ($input['code_challenge'] ?? '');
    if ((string) ($input['code_challenge_method'] ?? '') !== 'S256'
        || preg_match('/^[A-Za-z0-9_-]{43}$/', $challenge) !== 1) {
        throw new IndieAuthProtocolException('invalid_request', 'PKCE with the S256 method is required.');
    }

    $clientId = indieauth_validate_client_id((string) ($input['client_id'] ?? ''));
    $redirectUri = indieauth_validate_redirect_uri((string) ($input['redirect_uri'] ?? ''));
    $client = indieauth_resolve_client($clientId, $redirectUri, $clientFetcher);
    $scopes = indieauth_parse_scopes((string) ($input['scope'] ?? ''));
    $me = trim((string) ($input['me'] ?? ''));
    if ($me !== '') {
        $me = indieauth_canonicalize_http_url($me, false);
        if ($me !== indieauth_canonical_me()) {
            throw new IndieAuthProtocolException('invalid_request', 'The requested profile does not belong to this site.');
        }
    }

    return [
        'client_id' => $clientId,
        'client_name' => $client['name'],
        'client_uri' => $client['uri'],
        'redirect_uri' => $redirectUri,
        'state' => $state,
        'scope' => $scopes,
        'code_challenge' => $challenge,
        'me' => indieauth_canonical_me(),
        'created_at' => time(),
    ];
}

/**
 * @param array<string,string> $parameters
 */
function indieauth_append_query(string $url, array $parameters): string
{
    $separator = str_contains($url, '?') ? '&' : '?';
    return $url . $separator . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
}

function indieauth_base64url(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

function indieauth_send_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('Pragma: no-cache');
    header('Referrer-Policy: no-referrer');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

function indieauth_send_error(IndieAuthProtocolException $error): never
{
    indieauth_send_json([
        'error' => $error->oauthError,
        'error_description' => $error->getMessage(),
    ], $error->httpStatus);
}

plus_register_capability('indieauth.server', 1, 0);

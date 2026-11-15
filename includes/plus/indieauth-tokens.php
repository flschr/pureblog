<?php

declare(strict_types=1);

plus_register_migration('0002_indieauth', static function (PDO $database): void {
    $database->exec(
        'CREATE TABLE plus_indieauth_codes ('
        . 'id INTEGER PRIMARY KEY AUTOINCREMENT,'
        . 'code_hash TEXT NOT NULL UNIQUE,'
        . 'client_id TEXT NOT NULL,'
        . 'redirect_uri TEXT NOT NULL,'
        . 'me TEXT NOT NULL,'
        . 'scope TEXT NOT NULL,'
        . 'code_challenge TEXT NOT NULL,'
        . 'created_at INTEGER NOT NULL,'
        . 'expires_at INTEGER NOT NULL,'
        . 'used_at INTEGER)'
    );
    $database->exec('CREATE INDEX plus_indieauth_codes_expiry_idx ON plus_indieauth_codes (expires_at, used_at)');
    $database->exec(
        'CREATE TABLE plus_indieauth_tokens ('
        . 'id INTEGER PRIMARY KEY AUTOINCREMENT,'
        . 'token_hash TEXT NOT NULL UNIQUE,'
        . 'client_id TEXT NOT NULL,'
        . 'me TEXT NOT NULL,'
        . 'scope TEXT NOT NULL,'
        . 'created_at INTEGER NOT NULL,'
        . 'last_used_at INTEGER,'
        . 'revoked_at INTEGER)'
    );
    $database->exec('CREATE INDEX plus_indieauth_tokens_active_idx ON plus_indieauth_tokens (token_hash, revoked_at)');
});

/**
 * @param list<string> $scopes
 */
function indieauth_issue_authorization_code(
    string $clientId,
    string $redirectUri,
    string $me,
    array $scopes,
    string $codeChallenge,
    ?int $now = null
): string {
    $now ??= time();
    $code = indieauth_base64url(random_bytes(32));
    $database = plus_database();
    $database->prepare('DELETE FROM plus_indieauth_codes WHERE expires_at < ?')->execute([$now - 3600]);
    $insert = $database->prepare(
        'INSERT INTO plus_indieauth_codes '
        . '(code_hash,client_id,redirect_uri,me,scope,code_challenge,created_at,expires_at) '
        . 'VALUES (?,?,?,?,?,?,?,?)'
    );
    $insert->execute([
        hash('sha256', $code),
        $clientId,
        $redirectUri,
        $me,
        implode(' ', $scopes),
        $codeChallenge,
        $now,
        $now + 300,
    ]);
    return $code;
}

/**
 * @return array{me:string,scope:string,access_token?:string,token_type?:string}
 */
function indieauth_exchange_authorization_code(
    string $code,
    string $clientId,
    string $redirectUri,
    string $codeVerifier,
    bool $issueAccessToken,
    ?int $now = null
): array {
    if ($code === '' || strlen($code) > 512
        || preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $codeVerifier) !== 1) {
        throw new IndieAuthProtocolException('invalid_grant', 'Authorization code or PKCE verifier is invalid.');
    }
    $now ??= time();
    $database = plus_database();
    $database->exec('BEGIN IMMEDIATE');
    try {
        $query = $database->prepare('SELECT * FROM plus_indieauth_codes WHERE code_hash=?');
        $query->execute([hash('sha256', $code)]);
        $grant = $query->fetch();
        if (!is_array($grant)
            || $grant['used_at'] !== null
            || (int) $grant['expires_at'] < $now
            || !hash_equals((string) $grant['client_id'], $clientId)
            || !hash_equals((string) $grant['redirect_uri'], $redirectUri)) {
            throw new IndieAuthProtocolException('invalid_grant', 'Authorization code is invalid or expired.');
        }
        $challenge = indieauth_base64url(hash('sha256', $codeVerifier, true));
        if (!hash_equals((string) $grant['code_challenge'], $challenge)) {
            throw new IndieAuthProtocolException('invalid_grant', 'PKCE verification failed.');
        }
        $scope = trim((string) $grant['scope']);
        if ($issueAccessToken && $scope === '') {
            throw new IndieAuthProtocolException('invalid_scope', 'An access token requires at least one scope.');
        }
        if (!$issueAccessToken && $scope !== '') {
            throw new IndieAuthProtocolException('invalid_grant', 'Scoped authorization codes must be exchanged at the token endpoint.');
        }

        $database->prepare('UPDATE plus_indieauth_codes SET used_at=? WHERE id=?')->execute([$now, $grant['id']]);
        $response = ['me' => (string) $grant['me'], 'scope' => $scope];
        if ($issueAccessToken) {
            $token = indieauth_base64url(random_bytes(32));
            $insert = $database->prepare(
                'INSERT INTO plus_indieauth_tokens (token_hash,client_id,me,scope,created_at) VALUES (?,?,?,?,?)'
            );
            $insert->execute([hash('sha256', $token), $clientId, $grant['me'], $scope, $now]);
            $response['access_token'] = $token;
            $response['token_type'] = 'Bearer';
        }
        $database->exec('COMMIT');
        return $response;
    } catch (Throwable $error) {
        if ($database->inTransaction()) {
            $database->exec('ROLLBACK');
        }
        throw $error;
    }
}

/**
 * @return array<string,mixed>|null
 */
function indieauth_verify_access_token(string $token, ?string $requiredScope = null, ?int $now = null): ?array
{
    if ($token === '' || strlen($token) > 512) {
        return null;
    }
    $database = plus_database();
    $query = $database->prepare('SELECT * FROM plus_indieauth_tokens WHERE token_hash=? AND revoked_at IS NULL');
    $query->execute([hash('sha256', $token)]);
    $record = $query->fetch();
    if (!is_array($record)) {
        return null;
    }
    $scopes = indieauth_parse_scopes((string) $record['scope']);
    if ($requiredScope !== null && !in_array($requiredScope, $scopes, true)) {
        return null;
    }
    $database->prepare('UPDATE plus_indieauth_tokens SET last_used_at=? WHERE id=?')->execute([$now ?? time(), $record['id']]);
    $record['id'] = (int) $record['id'];
    $record['scope'] = $scopes;
    return $record;
}

function indieauth_revoke_access_token(int $id, ?int $now = null): bool
{
    $update = plus_database()->prepare('UPDATE plus_indieauth_tokens SET revoked_at=? WHERE id=? AND revoked_at IS NULL');
    $update->execute([$now ?? time(), $id]);
    return $update->rowCount() === 1;
}

/**
 * @return list<array<string,mixed>>
 */
function indieauth_list_access_tokens(): array
{
    $rows = plus_database()->query(
        'SELECT id,client_id,me,scope,created_at,last_used_at,revoked_at '
        . 'FROM plus_indieauth_tokens ORDER BY created_at DESC, id DESC'
    )->fetchAll();
    return is_array($rows) ? $rows : [];
}

function indieauth_bearer_token(): string
{
    $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/^Bearer\s+([^\s]+)$/i', $header, $matches) === 1) {
        return $matches[1];
    }
    if (isset($_POST['access_token']) && is_string($_POST['access_token'])) {
        return $_POST['access_token'];
    }
    return '';
}

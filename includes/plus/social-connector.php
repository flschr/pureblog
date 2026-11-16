<?php

declare(strict_types=1);

plus_register_migration('0006_social_connector', static function (PDO $database): void {
    $database->exec(
        'CREATE TABLE plus_social_connection ('
        . 'id INTEGER PRIMARY KEY CHECK (id=1),instance_url TEXT NOT NULL DEFAULT \'\','
        . 'client_id TEXT NOT NULL DEFAULT \'\',client_secret TEXT NOT NULL DEFAULT \'\','
        . 'access_token TEXT NOT NULL DEFAULT \'\',oauth_state_hash TEXT NOT NULL DEFAULT \'\','
        . 'oauth_state_expires_at INTEGER,account_id TEXT NOT NULL DEFAULT \'\','
        . 'account_url TEXT NOT NULL DEFAULT \'\',account_name TEXT NOT NULL DEFAULT \'\','
        . 'public_enabled INTEGER NOT NULL DEFAULT 0,last_sync_at INTEGER,last_error TEXT NOT NULL DEFAULT \'\','
        . 'connected_at INTEGER,disconnected_at INTEGER,updated_at INTEGER NOT NULL)'
    );
    $database->exec(
        'CREATE TABLE plus_social_statuses ('
        . 'remote_id TEXT PRIMARY KEY,uri TEXT NOT NULL DEFAULT \'\',url TEXT NOT NULL,'
        . 'content_text TEXT NOT NULL DEFAULT \'\',spoiler_text TEXT NOT NULL DEFAULT \'\','
        . 'visibility TEXT NOT NULL,media_json TEXT NOT NULL DEFAULT \'[]\','
        . 'published_at TEXT NOT NULL DEFAULT \'\',edited_at TEXT NOT NULL DEFAULT \'\',updated_at INTEGER NOT NULL)'
    );
    $database->exec('CREATE INDEX plus_social_statuses_published_idx ON plus_social_statuses (published_at DESC,remote_id DESC)');
    $database->exec(
        'CREATE TABLE plus_social_media ('
        . 'remote_url TEXT PRIMARY KEY,local_url TEXT NOT NULL,created_at INTEGER NOT NULL)'
    );
    $database->exec(
        'CREATE TABLE plus_social_announcements ('
        . 'post_slug TEXT PRIMARY KEY,remote_id TEXT NOT NULL DEFAULT \'\',remote_url TEXT NOT NULL DEFAULT \'\','
        . 'content_hash TEXT NOT NULL DEFAULT \'\',updated_at INTEGER NOT NULL)'
    );
});

plus_register_migration('0008_social_archives', static function (PDO $database): void {
    $database->exec(
        'CREATE TABLE plus_social_archives ('
        . 'id INTEGER PRIMARY KEY AUTOINCREMENT,instance_url TEXT NOT NULL,account_id TEXT NOT NULL,'
        . 'account_url TEXT NOT NULL DEFAULT \'\',account_name TEXT NOT NULL DEFAULT \'\','
        . 'created_at INTEGER NOT NULL,frozen_at INTEGER,UNIQUE(instance_url,account_id))'
    );
    $connection = $database->query('SELECT * FROM plus_social_connection WHERE id=1')->fetch();
    $archiveId = 0;
    if (is_array($connection) && trim((string) $connection['instance_url']) !== '' && trim((string) $connection['account_id']) !== '') {
        $database->prepare(
            'INSERT INTO plus_social_archives (instance_url,account_id,account_url,account_name,created_at,frozen_at) VALUES (?,?,?,?,?,?)'
        )->execute([(string) $connection['instance_url'], (string) $connection['account_id'], (string) $connection['account_url'],
            (string) $connection['account_name'], (int) ($connection['connected_at'] ?: time()),
            empty($connection['access_token']) ? (int) ($connection['disconnected_at'] ?: time()) : null]);
        $archiveId = (int) $database->lastInsertId();
    }

    $database->exec('ALTER TABLE plus_social_connection ADD COLUMN active_archive_id INTEGER');
    $database->exec('ALTER TABLE plus_social_connection ADD COLUMN pending_instance_url TEXT NOT NULL DEFAULT \'\'');
    $database->exec('ALTER TABLE plus_social_connection ADD COLUMN pending_client_id TEXT NOT NULL DEFAULT \'\'');
    $database->exec('ALTER TABLE plus_social_connection ADD COLUMN pending_client_secret TEXT NOT NULL DEFAULT \'\'');
    $database->exec('ALTER TABLE plus_social_connection ADD COLUMN pending_state_hash TEXT NOT NULL DEFAULT \'\'');
    $database->exec('ALTER TABLE plus_social_connection ADD COLUMN pending_state_expires_at INTEGER');
    if ($archiveId > 0) {
        $database->prepare('UPDATE plus_social_connection SET active_archive_id=? WHERE id=1')->execute([$archiveId]);
    }

    $database->exec('ALTER TABLE plus_social_statuses RENAME TO plus_social_statuses_legacy');
    $database->exec(
        'CREATE TABLE plus_social_statuses ('
        . 'archive_id INTEGER NOT NULL,remote_id TEXT NOT NULL,uri TEXT NOT NULL DEFAULT \'\',url TEXT NOT NULL,'
        . 'content_text TEXT NOT NULL DEFAULT \'\',spoiler_text TEXT NOT NULL DEFAULT \'\','
        . 'visibility TEXT NOT NULL,media_json TEXT NOT NULL DEFAULT \'[]\','
        . 'published_at TEXT NOT NULL DEFAULT \'\',edited_at TEXT NOT NULL DEFAULT \'\',updated_at INTEGER NOT NULL,'
        . 'PRIMARY KEY(archive_id,remote_id))'
    );
    $database->prepare(
        'INSERT INTO plus_social_statuses (archive_id,remote_id,uri,url,content_text,spoiler_text,visibility,media_json,published_at,edited_at,updated_at) '
        . 'SELECT ?,remote_id,uri,url,content_text,spoiler_text,visibility,media_json,published_at,edited_at,updated_at FROM plus_social_statuses_legacy'
    )->execute([$archiveId]);
    $database->exec('DROP TABLE plus_social_statuses_legacy');
    $database->exec('CREATE INDEX plus_social_statuses_published_idx ON plus_social_statuses (published_at DESC,archive_id,remote_id DESC)');

    $database->exec('ALTER TABLE plus_social_media RENAME TO plus_social_media_legacy');
    $database->exec(
        'CREATE TABLE plus_social_media ('
        . 'archive_id INTEGER NOT NULL,remote_url TEXT NOT NULL,local_url TEXT NOT NULL,created_at INTEGER NOT NULL,'
        . 'PRIMARY KEY(archive_id,remote_url))'
    );
    $database->prepare(
        'INSERT INTO plus_social_media (archive_id,remote_url,local_url,created_at) SELECT ?,remote_url,local_url,created_at FROM plus_social_media_legacy'
    )->execute([$archiveId]);
    $database->exec('DROP TABLE plus_social_media_legacy');

    $database->exec('ALTER TABLE plus_social_announcements RENAME TO plus_social_announcements_legacy');
    $database->exec(
        'CREATE TABLE plus_social_announcements ('
        . 'archive_id INTEGER NOT NULL,post_slug TEXT NOT NULL,remote_id TEXT NOT NULL DEFAULT \'\','
        . 'remote_url TEXT NOT NULL DEFAULT \'\',content_hash TEXT NOT NULL DEFAULT \'\',updated_at INTEGER NOT NULL,'
        . 'PRIMARY KEY(archive_id,post_slug))'
    );
    $database->prepare(
        'INSERT INTO plus_social_announcements (archive_id,post_slug,remote_id,remote_url,content_hash,updated_at) '
        . 'SELECT ?,post_slug,remote_id,remote_url,content_hash,updated_at FROM plus_social_announcements_legacy'
    )->execute([$archiveId]);
    $database->exec('DROP TABLE plus_social_announcements_legacy');
});

plus_register_migration('0010_social_revocations', static function (PDO $database): void {
    $database->exec(
        'CREATE TABLE plus_social_revocations ('
        . 'id INTEGER PRIMARY KEY AUTOINCREMENT,instance_url TEXT NOT NULL,client_id TEXT NOT NULL,'
        . 'client_secret TEXT NOT NULL,access_token TEXT NOT NULL,created_at INTEGER NOT NULL)'
    );
});

function social_connection(): array
{
    if (!is_file(plus_database_path())) return [];
    $connection = plus_database()->query('SELECT * FROM plus_social_connection WHERE id=1')->fetch();
    return is_array($connection) ? $connection : [];
}

function social_connected(?array $connection = null): bool
{
    $connection ??= social_connection();
    return trim((string) ($connection['instance_url'] ?? '')) !== ''
        && trim((string) ($connection['access_token'] ?? '')) !== ''
        && trim((string) ($connection['account_id'] ?? '')) !== '';
}

function social_active_archive_id(?array $connection = null): int
{
    $connection ??= social_connection();
    return max(0, (int) ($connection['active_archive_id'] ?? 0));
}

function social_instance_url(string $value): string
{
    $value = rtrim(trim($value), '/');
    $parts = parse_url($value);
    if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
        || empty($parts['host']) || isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])
        || ((string) ($parts['path'] ?? '') !== '' && (string) ($parts['path'] ?? '') !== '/')) {
        throw new InvalidArgumentException('The social server must be an HTTPS origin.');
    }
    $host = strtolower((string) $parts['host']);
    if (filter_var($host, FILTER_VALIDATE_IP) !== false || social_public_ips($host) === []) {
        throw new InvalidArgumentException('The social server must resolve to a public address.');
    }
    $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
    return 'https://' . $host . $port;
}

function social_public_ips(string $host): array
{
    $override = $GLOBALS['_pureblog_social_public_ips'] ?? null;
    return is_callable($override) ? (array) $override($host) : webmention_public_ips($host);
}

function social_https_url(string $value): string
{
    $parts = parse_url(trim($value));
    if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])
        || isset($parts['user'], $parts['pass']) || preg_match('/[\x00-\x20\x7f]/', $value) === 1) return '';
    return trim($value);
}

/** @return array{ok:bool,status:int,body:string,headers:array<string,string>,error:string} */
function social_http_request(string $method, string $url, array $options = []): array
{
    $override = $GLOBALS['_pureblog_social_requester'] ?? null;
    if (is_callable($override)) return $override($method, $url, $options);
    if (!function_exists('curl_init')) return ['ok' => false, 'status' => 0, 'body' => '', 'headers' => [], 'error' => 'Social connector requires curl.'];
    $parts = parse_url($url);
    $host = strtolower((string) ($parts['host'] ?? ''));
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || $host === '' || isset($parts['user'], $parts['pass'])) {
        return ['ok' => false, 'status' => 0, 'body' => '', 'headers' => [], 'error' => 'Unsafe social request URL.'];
    }
    $ips = social_public_ips($host);
    if ($ips === []) return ['ok' => false, 'status' => 0, 'body' => '', 'headers' => [], 'error' => 'Social request host is not public.'];
    $port = (int) ($parts['port'] ?? 443);
    $resolved = str_contains($ips[0], ':') ? '[' . $ips[0] . ']' : $ips[0];
    $responseHeaders = [];
    $body = '';
    $destination = isset($options['destination']) ? (string) $options['destination'] : '';
    $destinationStream = $destination !== '' ? fopen($destination, 'wb') : null;
    if ($destination !== '' && $destinationStream === false) {
        return ['ok' => false, 'status' => 0, 'body' => '', 'headers' => [], 'error' => 'Unable to open social response destination.'];
    }
    $receivedBytes = 0;
    $maximumBytes = max(1024, min(20971520, (int) ($options['maximum_bytes'] ?? 4194304)));
    $tooLarge = false;
    $headers = ['Accept: ' . (string) ($options['accept'] ?? 'application/json'), 'User-Agent: PureBlog-Plus-Social'];
    if (!empty($options['token'])) $headers[] = 'Authorization: Bearer ' . (string) $options['token'];
    if (!empty($options['idempotency_key'])) $headers[] = 'Idempotency-Key: ' . (string) $options['idempotency_key'];
    $payload = null;
    if (isset($options['json'])) {
        $payload = json_encode($options['json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $headers[] = 'Content-Type: application/json';
    } elseif (isset($options['multipart'])) {
        $payload = $options['multipart'];
    } elseif (isset($options['form'])) {
        $payload = http_build_query($options['form'], '', '&', PHP_QUERY_RFC3986);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    }
    $handle = curl_init($url);
    if ($handle === false) return ['ok' => false, 'status' => 0, 'body' => '', 'headers' => [], 'error' => 'Unable to initialize social request.'];
    curl_setopt_array($handle, [
        CURLOPT_CUSTOMREQUEST => strtoupper($method), CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_PROXY => '',
        CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $resolved], CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
            $position = strpos($line, ':');
            if ($position !== false) $responseHeaders[strtolower(trim(substr($line, 0, $position)))] = trim(substr($line, $position + 1));
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooLarge, &$receivedBytes, $maximumBytes, $destinationStream): int {
            $receivedBytes += strlen($chunk);
            if ($receivedBytes > $maximumBytes) { $tooLarge = true; return 0; }
            if (is_resource($destinationStream)) {
                $written = fwrite($destinationStream, $chunk);
                return $written === false ? 0 : $written;
            }
            $body .= $chunk;
            return strlen($chunk);
        },
    ]);
    $executed = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = $executed === false ? curl_error($handle) : '';
    unset($handle);
    if (is_resource($destinationStream)) fclose($destinationStream);
    if ($tooLarge) $error = 'Social response exceeded the size limit.';
    return ['ok' => $executed !== false && !$tooLarge && $status >= 200 && $status < 300, 'status' => $status, 'body' => $body, 'headers' => $responseHeaders, 'error' => $error];
}

function social_api_request(string $method, string $path, array $options = [], ?array $connection = null): array
{
    $connection ??= social_connection();
    $origin = social_instance_url((string) ($connection['instance_url'] ?? ''));
    if (!str_starts_with($path, '/') || str_starts_with($path, '//')) throw new InvalidArgumentException('Invalid social API path.');
    if (!array_key_exists('token', $options) && !empty($connection['access_token'])) $options['token'] = (string) $connection['access_token'];
    return social_http_request($method, $origin . $path, $options);
}

function social_decode_response(array $response, string $operation): array
{
    if (empty($response['ok'])) throw new RuntimeException($operation . ' failed' . (!empty($response['status']) ? ' (HTTP ' . (int) $response['status'] . ')' : '') . '.');
    $decoded = json_decode((string) ($response['body'] ?? ''), true);
    if (!is_array($decoded)) throw new RuntimeException($operation . ' returned invalid JSON.');
    return $decoded;
}

function social_callback_url(): string
{
    return indieauth_endpoint_url('admin/social-callback.php');
}

function social_begin_connection(string $instanceUrl, ?int $now = null): string
{
    $now ??= time();
    $instanceUrl = social_instance_url($instanceUrl);
    $registration = social_decode_response(social_http_request('POST', $instanceUrl . '/api/v1/apps', ['form' => [
        'client_name' => 'Pure Blog Plus', 'redirect_uris' => social_callback_url(),
        'scopes' => 'read:accounts read:statuses write:statuses', 'website' => indieauth_base_url(),
    ]]), 'App registration');
    $clientId = trim((string) ($registration['client_id'] ?? ''));
    $clientSecret = trim((string) ($registration['client_secret'] ?? ''));
    if ($clientId === '' || $clientSecret === '') throw new RuntimeException('App registration did not return credentials.');
    $state = indieauth_base64url(random_bytes(32));
    plus_database()->prepare(
        'INSERT INTO plus_social_connection (id,pending_instance_url,pending_client_id,pending_client_secret,pending_state_hash,pending_state_expires_at,updated_at) '
        . 'VALUES (1,?,?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET pending_instance_url=excluded.pending_instance_url,'
        . 'pending_client_id=excluded.pending_client_id,pending_client_secret=excluded.pending_client_secret,'
        . 'pending_state_hash=excluded.pending_state_hash,pending_state_expires_at=excluded.pending_state_expires_at,'
        . 'last_error=\'\',updated_at=excluded.updated_at'
    )->execute([$instanceUrl, $clientId, $clientSecret, hash('sha256', $state), $now + 600, $now]);
    return $instanceUrl . '/oauth/authorize?' . http_build_query([
        'client_id' => $clientId, 'scope' => 'read:accounts read:statuses write:statuses',
        'redirect_uri' => social_callback_url(), 'response_type' => 'code', 'state' => $state,
    ], '', '&', PHP_QUERY_RFC3986);
}

function social_complete_connection(string $code, string $state, ?int $now = null): array
{
    $now ??= time();
    $connection = social_connection();
    if ($code === '' || $state === '' || empty($connection['pending_state_hash'])
        || (int) ($connection['pending_state_expires_at'] ?? 0) < $now
        || !hash_equals((string) $connection['pending_state_hash'], hash('sha256', $state))) {
        throw new RuntimeException('The social authorization request is missing or expired.');
    }
    $pending = [
        'instance_url' => (string) $connection['pending_instance_url'],
        'client_id' => (string) $connection['pending_client_id'],
        'client_secret' => (string) $connection['pending_client_secret'],
        'access_token' => '',
    ];
    $token = social_decode_response(social_api_request('POST', '/oauth/token', ['token' => '', 'form' => [
        'grant_type' => 'authorization_code', 'code' => $code,
        'client_id' => (string) $pending['client_id'], 'client_secret' => (string) $pending['client_secret'],
        'redirect_uri' => social_callback_url(), 'scope' => 'read:accounts read:statuses write:statuses',
    ]], $pending), 'Token exchange');
    $accessToken = trim((string) ($token['access_token'] ?? ''));
    if ($accessToken === '') throw new RuntimeException('Token exchange did not return an access token.');
    $pending['access_token'] = $accessToken;
    $account = social_decode_response(social_api_request('GET', '/api/v1/accounts/verify_credentials', [], $pending), 'Account verification');
    $accountId = trim((string) ($account['id'] ?? ''));
    if ($accountId === '') throw new RuntimeException('Account verification did not return an account.');
    if (social_connected($connection) && !social_revoke_credentials($connection)) {
        social_revoke_credentials($pending);
        throw new RuntimeException('The previous social token could not be revoked; the connection was not replaced.');
    }
    $database = plus_database();
    plus_begin_immediate($database);
    try {
        $database->prepare(
            'INSERT INTO plus_social_archives (instance_url,account_id,account_url,account_name,created_at,frozen_at) '
            . 'VALUES (?,?,?,?,?,NULL) ON CONFLICT(instance_url,account_id) DO UPDATE SET '
            . 'account_url=excluded.account_url,account_name=excluded.account_name,frozen_at=NULL'
        )->execute([(string) $pending['instance_url'], $accountId, social_https_url((string) ($account['url'] ?? '')),
            (string) ($account['acct'] ?? $account['username'] ?? ''), $now]);
        $archiveQuery = $database->prepare('SELECT id FROM plus_social_archives WHERE instance_url=? AND account_id=?');
        $archiveQuery->execute([(string) $pending['instance_url'], $accountId]);
        $archiveId = (int) $archiveQuery->fetchColumn();
        $oldArchiveId = social_active_archive_id($connection);
        if ($oldArchiveId > 0 && $oldArchiveId !== $archiveId) {
            $database->prepare('UPDATE plus_social_archives SET frozen_at=? WHERE id=?')->execute([$now, $oldArchiveId]);
        }
        $database->prepare(
            'UPDATE plus_social_connection SET instance_url=?,client_id=?,client_secret=?,access_token=?,'
            . 'account_id=?,account_url=?,account_name=?,active_archive_id=?,pending_instance_url=\'\','
            . 'pending_client_id=\'\',pending_client_secret=\'\',pending_state_hash=\'\',pending_state_expires_at=NULL,'
            . 'oauth_state_hash=\'\',oauth_state_expires_at=NULL,connected_at=?,disconnected_at=NULL,last_error=\'\',updated_at=? WHERE id=1'
        )->execute([(string) $pending['instance_url'], (string) $pending['client_id'], (string) $pending['client_secret'],
            $accessToken, $accountId, social_https_url((string) ($account['url'] ?? '')),
            (string) ($account['acct'] ?? $account['username'] ?? ''), $archiveId, $now, $now]);
        $database->exec('COMMIT');
    } catch (Throwable $error) {
        if ($database->inTransaction()) $database->exec('ROLLBACK');
        social_revoke_credentials($pending);
        throw $error;
    }
    social_schedule_sync($now, true);
    return social_connection();
}

function social_revoke_credentials(array $credentials): bool
{
    if (empty($credentials['instance_url']) || empty($credentials['client_id'])
        || empty($credentials['client_secret']) || empty($credentials['access_token'])) return true;
    try {
        $response = social_api_request('POST', '/oauth/revoke', ['token' => '', 'multipart' => [
            'client_id' => (string) $credentials['client_id'], 'client_secret' => (string) $credentials['client_secret'],
            'token' => (string) $credentials['access_token'],
        ]], $credentials);
        return !empty($response['ok']);
    } catch (Throwable) {
        return false;
    }
}

function social_disconnect(?int $now = null): void
{
    if (!is_file(plus_database_path())) return;
    $now ??= time();
    $connection = social_connection();
    $revocationError = '';
    if (social_connected($connection) && !empty($connection['client_id']) && !empty($connection['client_secret'])) {
        try {
            if (!social_revoke_credentials($connection)) $revocationError = 'Remote token revocation could not be confirmed.';
        } catch (Throwable) {
            $revocationError = 'Remote token revocation could not be confirmed.';
        }
    }
    $database = plus_database();
    $archiveId = social_active_archive_id($connection);
    plus_begin_immediate($database);
    try {
        if ($revocationError !== '' && social_connected($connection)) {
            $database->prepare(
                'INSERT INTO plus_social_revocations (instance_url,client_id,client_secret,access_token,created_at) VALUES (?,?,?,?,?)'
            )->execute([(string) $connection['instance_url'], (string) $connection['client_id'],
                (string) $connection['client_secret'], (string) $connection['access_token'], $now]);
            $revocationId = (int) $database->lastInsertId();
            plus_enqueue_job('social.revoke', ['revocation_id' => $revocationId], $now + 60, 20, 'social-revoke-' . $revocationId);
        }
        $database->prepare(
            'UPDATE plus_social_connection SET client_id=\'\',client_secret=\'\',access_token=\'\','
            . 'oauth_state_hash=\'\',oauth_state_expires_at=NULL,pending_instance_url=\'\',pending_client_id=\'\','
            . 'pending_client_secret=\'\',pending_state_hash=\'\',pending_state_expires_at=NULL,last_error=?,disconnected_at=?,updated_at=? WHERE id=1'
        )->execute([$revocationError, $now, $now]);
        if ($archiveId > 0) $database->prepare('UPDATE plus_social_archives SET frozen_at=? WHERE id=?')->execute([$now, $archiveId]);
        $database->exec('COMMIT');
    } catch (Throwable $error) {
        if ($database->inTransaction()) $database->exec('ROLLBACK');
        throw $error;
    }
}

function social_retry_revocation(int $id): void
{
    $query = plus_database()->prepare('SELECT * FROM plus_social_revocations WHERE id=?');
    $query->execute([$id]);
    $credentials = $query->fetch();
    if (!is_array($credentials)) return;
    if (!social_revoke_credentials($credentials)) throw new RuntimeException('Remote token revocation could not be confirmed.');
    plus_database()->prepare('DELETE FROM plus_social_revocations WHERE id=?')->execute([$id]);
}

function social_set_public_enabled(bool $enabled, ?int $now = null): void
{
    $now ??= time();
    plus_database()->prepare(
        'INSERT INTO plus_social_connection (id,public_enabled,updated_at) VALUES (1,?,?) '
        . 'ON CONFLICT(id) DO UPDATE SET public_enabled=excluded.public_enabled,updated_at=excluded.updated_at'
    )->execute([$enabled ? 1 : 0, $now]);
    cache_clear();
}

function social_syndication_targets(): array
{
    $connection = social_connection();
    if (!social_connected($connection)) return [];
    return [[
        'uid' => (string) $connection['instance_url'],
        'name' => (string) ($connection['account_name'] !== '' ? '@' . $connection['account_name'] : $connection['instance_url']),
    ]];
}

function social_schedule_sync(?int $availableAt = null, bool $force = false): array
{
    $availableAt ??= time();
    $archiveId = social_active_archive_id();
    if ($archiveId <= 0 || !social_connected()) throw new RuntimeException('Social synchronization requires an active connection.');
    $key = $force ? bin2hex(random_bytes(12)) : (string) intdiv($availableAt, 300);
    return plus_enqueue_job('social.sync', ['archive_id' => $archiveId], $availableAt, 5, 'social-sync-' . $archiveId . '-' . $key);
}

function social_plain_text(string $html): string
{
    $html = preg_replace('#<(?:br\s*/?|/p|/div)>#i', "\n", $html) ?? $html;
    return trim(preg_replace('/\n{3,}/', "\n\n", html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
}

function social_archive_media(string $remoteUrl, string $description = '', string $kind = 'any', ?int $archiveId = null): string
{
    $override = $GLOBALS['_pureblog_social_media_archiver'] ?? null;
    if (is_callable($override)) return (string) $override($remoteUrl, $description, $kind);
    $archiveId ??= social_active_archive_id();
    if ($archiveId <= 0) throw new RuntimeException('Social media requires an active archive.');
    $existing = plus_database()->prepare('SELECT local_url FROM plus_social_media WHERE archive_id=? AND remote_url=?');
    $existing->execute([$archiveId, $remoteUrl]);
    $localUrl = $existing->fetchColumn();
    if (is_string($localUrl) && $localUrl !== '') return $localUrl;
    $temporary = tempnam(sys_get_temp_dir(), 'pureblog-social-');
    if ($temporary === false) throw new RuntimeException('Unable to stage social media.');
    try {
        $response = social_http_request('GET', $remoteUrl, [
            'maximum_bytes' => 20971520, 'accept' => 'image/*, video/*, audio/*, application/octet-stream',
            'destination' => $temporary,
        ]);
        if (empty($response['ok']) || !is_file($temporary) || filesize($temporary) === 0) throw new RuntimeException('Unable to archive social media.');
        $name = basename((string) (parse_url($remoteUrl, PHP_URL_PATH) ?? 'social-media')) ?: 'social-media';
        $media = plus_store_uploaded_media(['name' => $name, 'tmp_name' => $temporary, 'error' => UPLOAD_ERR_OK], false, null, $kind);
        plus_database()->prepare('INSERT OR REPLACE INTO plus_social_media (archive_id,remote_url,local_url,created_at) VALUES (?,?,?,?)')
            ->execute([$archiveId, $remoteUrl, (string) $media['url'], time()]);
        return (string) $media['url'];
    } finally {
        if (is_file($temporary)) @unlink($temporary);
    }
}

function social_status_media(array $attachments, ?int $archiveId = null): array
{
    $result = [];
    foreach ($attachments as $attachment) {
        plus_job_heartbeat();
        if (!is_array($attachment) || !in_array((string) ($attachment['type'] ?? ''), ['image', 'video', 'gifv', 'audio'], true)) continue;
        $remoteUrl = trim((string) ($attachment['url'] ?? $attachment['preview_url'] ?? ''));
        if ($remoteUrl === '') continue;
        try {
            $type = (string) $attachment['type'];
            $kind = $type === 'image' ? 'image' : ($type === 'audio' ? 'audio' : 'video');
            $result[] = ['type' => $type, 'url' => social_archive_media($remoteUrl, (string) ($attachment['description'] ?? ''), $kind, $archiveId), 'alt' => (string) ($attachment['description'] ?? '')];
        } catch (Throwable $error) {
            error_log('Unable to archive social media: ' . $error->getMessage());
        }
    }
    return $result;
}

function social_sync(?int $now = null): int
{
    if (!is_dir(PUREBLOG_PLUS_DATA_PATH) && !mkdir(PUREBLOG_PLUS_DATA_PATH, 0700, true) && !is_dir(PUREBLOG_PLUS_DATA_PATH)) {
        throw new RuntimeException('Unable to create social synchronization lock directory.');
    }
    $handle = fopen(PUREBLOG_PLUS_DATA_PATH . '/social-sync.lock', 'c');
    if ($handle === false) throw new RuntimeException('Unable to create social synchronization lock.');
    @chmod(PUREBLOG_PLUS_DATA_PATH . '/social-sync.lock', 0600);
    if (!flock($handle, LOCK_EX | LOCK_NB)) { fclose($handle); return 0; }
    try {
        return social_sync_locked($now);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function social_sync_locked(?int $now = null): int
{
    $now ??= time();
    $connection = social_connection();
    if (!social_connected($connection)) return 0;
    $archiveId = social_active_archive_id($connection);
    if ($archiveId <= 0) throw new RuntimeException('The social connection has no archive identity.');
    $seen = [];
    $maxId = '';
    for ($page = 0; $page < 100; $page++) {
        plus_job_heartbeat();
        $query = ['limit' => 40, 'exclude_reblogs' => 'true', 'exclude_replies' => 'true'];
        if ($maxId !== '') $query['max_id'] = $maxId;
        $statuses = social_decode_response(social_api_request(
            'GET', '/api/v1/accounts/' . rawurlencode((string) $connection['account_id']) . '/statuses?' . http_build_query($query), [], $connection
        ), 'Status synchronization');
        if ($statuses === []) break;
        foreach ($statuses as $status) {
            plus_job_heartbeat();
            if (!is_array($status)) continue;
            $remoteId = trim((string) ($status['id'] ?? ''));
            if ($remoteId === '') continue;
            $maxId = $remoteId;
            if (!in_array((string) ($status['visibility'] ?? ''), ['public', 'unlisted'], true)) continue;
            $announcement = plus_database()->prepare('SELECT 1 FROM plus_social_announcements WHERE archive_id=? AND remote_id=?');
            $announcement->execute([$archiveId, $remoteId]);
            if ($announcement->fetchColumn() !== false) continue;
            $seen[$remoteId] = true;
            $media = social_status_media((array) ($status['media_attachments'] ?? []), $archiveId);
            $statusUrl = social_https_url((string) ($status['url'] ?? ''));
            if ($statusUrl === '') continue;
            plus_database()->prepare(
                'INSERT INTO plus_social_statuses (archive_id,remote_id,uri,url,content_text,spoiler_text,visibility,media_json,published_at,edited_at,updated_at) '
                . 'VALUES (?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT(archive_id,remote_id) DO UPDATE SET uri=excluded.uri,url=excluded.url,'
                . 'content_text=excluded.content_text,spoiler_text=excluded.spoiler_text,visibility=excluded.visibility,'
                . 'media_json=excluded.media_json,published_at=excluded.published_at,edited_at=excluded.edited_at,updated_at=excluded.updated_at'
            )->execute([$archiveId, $remoteId, (string) ($status['uri'] ?? ''), $statusUrl,
                social_plain_text((string) ($status['content'] ?? '')), (string) ($status['spoiler_text'] ?? ''),
                (string) $status['visibility'], json_encode($media, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                (string) ($status['created_at'] ?? ''), (string) ($status['edited_at'] ?? ''), $now]);
        }
        if (count($statuses) < 40) break;
    }
    if ($page >= 100) throw new RuntimeException('Status synchronization exceeded the page limit.');
    if ($seen === []) {
        plus_database()->prepare('DELETE FROM plus_social_statuses WHERE archive_id=?')->execute([$archiveId]);
    } else {
        $placeholders = implode(',', array_fill(0, count($seen), '?'));
        plus_database()->prepare('DELETE FROM plus_social_statuses WHERE archive_id=? AND remote_id NOT IN (' . $placeholders . ')')
            ->execute(array_merge([$archiveId], array_keys($seen)));
    }
    social_prune_unreferenced_media($archiveId);
    plus_database()->prepare('UPDATE plus_social_connection SET last_sync_at=?,last_error=\'\',updated_at=? WHERE id=1')->execute([$now, $now]);
    social_schedule_sync($now + 300);
    cache_clear();
    return count($seen);
}

function social_prune_unreferenced_media(int $archiveId): void
{
    $query = plus_database()->prepare('SELECT remote_url,local_url FROM plus_social_media WHERE archive_id=?');
    $query->execute([$archiveId]);
    foreach ($query->fetchAll() ?: [] as $mapping) {
        $localUrl = (string) ($mapping['local_url'] ?? '');
        if ($localUrl === '' || social_media_is_referenced($localUrl)) continue;
        $media = plus_media_by_url($localUrl);
        try {
            if (is_array($media)) plus_delete_media((string) $media['id']);
            plus_database()->prepare('DELETE FROM plus_social_media WHERE archive_id=? AND remote_url=?')
                ->execute([$archiveId, (string) $mapping['remote_url']]);
        } catch (Throwable $error) {
            error_log('Unable to evict social media: ' . $error->getMessage());
        }
    }
}

function social_statuses(int $limit = 100, int $offset = 0): array
{
    if (!is_file(plus_database_path())) return [];
    $query = plus_database()->prepare('SELECT * FROM plus_social_statuses ORDER BY published_at DESC,remote_id DESC LIMIT ? OFFSET ?');
    $query->bindValue(1, max(1, min(200, $limit)), PDO::PARAM_INT);
    $query->bindValue(2, max(0, $offset), PDO::PARAM_INT);
    $query->execute();
    return $query->fetchAll() ?: [];
}

function social_media_is_referenced(string $url): bool
{
    if (!is_file(plus_database_path())) return false;
    $query = plus_database()->query('SELECT media_json FROM plus_social_statuses');
    foreach ($query->fetchAll(PDO::FETCH_COLUMN) ?: [] as $json) {
        $media = json_decode((string) $json, true);
        if (!is_array($media)) continue;
        foreach ($media as $item) {
            if (is_array($item) && hash_equals((string) ($item['url'] ?? ''), $url)) return true;
        }
    }
    return false;
}

function social_announcement_record(string $slug): ?array
{
    if (!is_file(plus_database_path())) return null;
    $archiveId = social_active_archive_id();
    if ($archiveId <= 0) return null;
    $query = plus_database()->prepare('SELECT * FROM plus_social_announcements WHERE archive_id=? AND post_slug=?');
    $query->execute([$archiveId, $slug]);
    $record = $query->fetch();
    return is_array($record) ? $record : null;
}

function social_schedule_announcement(string $slug, string $action = 'publish', ?int $now = null): array
{
    if (!in_array($action, ['publish', 'delete'], true)) throw new InvalidArgumentException('Invalid announcement action.');
    $now ??= time();
    $archiveId = social_active_archive_id();
    if ($archiveId <= 0 || !social_connected()) throw new RuntimeException('Social announcement requires an active connection.');
    return plus_enqueue_job('social.announce', ['slug' => $slug, 'action' => $action, 'archive_id' => $archiveId], $now, 5,
        'social-announce-' . hash('sha256', $archiveId . "\0" . $slug . "\0" . $action . "\0" . $now));
}

function social_content_saved(array $post, ?string $originalSlug, ?string $originalStatus): void
{
    try {
        if (!social_connected()) return;
        $slug = (string) ($post['slug'] ?? '');
        if ($originalSlug !== null && $originalSlug !== '' && $originalSlug !== $slug && social_announcement_record($originalSlug) !== null) {
            social_schedule_announcement($originalSlug, 'delete');
        }
        if (($post['status'] ?? '') === 'published' && !empty($post['social_announce'])) {
            social_schedule_announcement($slug);
        } elseif (social_announcement_record($slug) !== null) {
            social_schedule_announcement($slug, 'delete');
        }
    } catch (Throwable $error) {
        error_log('Unable to queue social announcement: ' . $error->getMessage());
    }
}

function social_content_deleted(string $slug): void
{
    try {
        if (social_connected() && social_announcement_record($slug) !== null) social_schedule_announcement($slug, 'delete');
    } catch (Throwable $error) {
        error_log('Unable to queue deleted social announcement: ' . $error->getMessage());
    }
}

function social_run_announcement(string $slug, string $action, ?int $now = null, ?int $expectedArchiveId = null): void
{
    $now ??= time();
    $connection = social_connection();
    if (!social_connected($connection)) return;
    $archiveId = social_active_archive_id($connection);
    if ($archiveId <= 0) return;
    if ($expectedArchiveId === null || $expectedArchiveId !== $archiveId) return;
    $record = social_announcement_record($slug);
    if ($action === 'delete') {
        if ($record !== null && (string) $record['remote_id'] !== '') {
            $response = social_api_request('DELETE', '/api/v1/statuses/' . rawurlencode((string) $record['remote_id']), [], $connection);
            if (empty($response['ok']) && (int) ($response['status'] ?? 0) !== 404) throw new RuntimeException('Blog announcement deletion failed.');
        }
        plus_database()->prepare('DELETE FROM plus_social_announcements WHERE archive_id=? AND post_slug=?')->execute([$archiveId, $slug]);
        return;
    }
    $post = get_post_by_slug($slug, true);
    if ($post === null || ($post['status'] ?? '') !== 'published' || empty($post['social_announce'])) {
        if ($record !== null) social_run_announcement($slug, 'delete', $now, $archiveId);
        return;
    }
    social_publish_announcement($slug, trim((string) $post['title']), $record, $connection, $now);
}

function social_publish_announcement(string $slug, string $title, ?array $record = null, ?array $connection = null, ?int $now = null): void
{
    $now ??= time();
    $connection ??= social_connection();
    if (!social_connected($connection)) return;
    $archiveId = social_active_archive_id($connection);
    if ($archiveId <= 0) return;
    $record ??= social_announcement_record($slug);
    $text = $title . "\n\n" . webmention_sender_content_url('post', $slug);
    $hash = hash('sha256', $text);
    if ($record !== null && hash_equals((string) $record['content_hash'], $hash)) return;
    $options = ['form' => ['status' => $text, 'visibility' => 'public'], 'idempotency_key' => hash('sha256', 'pureblog:' . $slug . ':' . $hash)];
    $method = $record !== null && (string) $record['remote_id'] !== '' ? 'PUT' : 'POST';
    $path = $method === 'PUT' ? '/api/v1/statuses/' . rawurlencode((string) $record['remote_id']) : '/api/v1/statuses';
    $remote = social_decode_response(social_api_request($method, $path, $options, $connection), 'Blog announcement');
    plus_database()->prepare(
        'INSERT INTO plus_social_announcements (archive_id,post_slug,remote_id,remote_url,content_hash,updated_at) VALUES (?,?,?,?,?,?) '
        . 'ON CONFLICT(archive_id,post_slug) DO UPDATE SET remote_id=excluded.remote_id,remote_url=excluded.remote_url,'
        . 'content_hash=excluded.content_hash,updated_at=excluded.updated_at'
    )->execute([$archiveId, $slug, (string) ($remote['id'] ?? $record['remote_id'] ?? ''), (string) ($remote['url'] ?? $record['remote_url'] ?? ''), $hash, $now]);
    $remoteId = (string) ($remote['id'] ?? $record['remote_id'] ?? '');
    if ($remoteId !== '') plus_database()->prepare('DELETE FROM plus_social_statuses WHERE archive_id=? AND remote_id=?')->execute([$archiveId, $remoteId]);
}

plus_register_job_handler('social.sync', static function (array $payload): void {
    try {
        $expectedArchiveId = (int) ($payload['archive_id'] ?? 0);
        if ($expectedArchiveId <= 0 || $expectedArchiveId !== social_active_archive_id()) return;
        social_sync();
    } catch (Throwable $error) {
        if (is_file(plus_database_path())) {
            plus_database()->prepare('UPDATE plus_social_connection SET last_error=?,updated_at=? WHERE id=1')
                ->execute([mb_substr($error->getMessage(), 0, 2000), time()]);
        }
        throw $error;
    }
});
plus_register_job_handler('social.announce', static function (array $payload): void {
    social_run_announcement((string) ($payload['slug'] ?? ''), (string) ($payload['action'] ?? 'publish'), null,
        (int) ($payload['archive_id'] ?? 0));
});
plus_register_job_handler('social.revoke', static function (array $payload): void {
    social_retry_revocation((int) ($payload['revocation_id'] ?? 0));
});
plus_register_capability('social.connector', 1, 0);

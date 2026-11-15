<?php

declare(strict_types=1);

function indieauth_canonicalize_http_url(string $url, bool $allowPort = true): string
{
    if ($url === '' || strlen($url) > 2048) {
        throw new IndieAuthProtocolException('invalid_request', 'URL is missing or too long.');
    }
    $parts = parse_url($url);
    if (!is_array($parts)
        || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
        || empty($parts['host'])
        || isset($parts['user'])
        || isset($parts['pass'])
        || isset($parts['fragment'])
        || (!$allowPort && isset($parts['port']))) {
        throw new IndieAuthProtocolException('invalid_request', 'URL is not valid for IndieAuth.');
    }
    $path = (string) ($parts['path'] ?? '/');
    if ($path === '') {
        $path = '/';
    }
    if (in_array('.', explode('/', $path), true) || in_array('..', explode('/', $path), true)) {
        throw new IndieAuthProtocolException('invalid_request', 'URL contains an unsafe path.');
    }
    $host = strtolower((string) $parts['host']);
    if (filter_var($host, FILTER_VALIDATE_IP) !== false
        && !in_array($host, ['127.0.0.1', '::1'], true)) {
        throw new IndieAuthProtocolException('invalid_request', 'IP address is not allowed here.');
    }
    $scheme = strtolower((string) $parts['scheme']);
    $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
    $query = isset($parts['query']) ? '?' . $parts['query'] : '';
    $hostForUrl = str_contains($host, ':') ? '[' . $host . ']' : $host;
    return $scheme . '://' . $hostForUrl . $port . $path . $query;
}

function indieauth_validate_client_id(string $clientId): string
{
    $clientId = trim($clientId);
    $parts = parse_url($clientId);
    if (!is_array($parts) || !isset($parts['path']) || $parts['path'] === '') {
        throw new IndieAuthProtocolException('invalid_request', 'Client ID must contain a path.');
    }
    return indieauth_canonicalize_http_url($clientId);
}

function indieauth_validate_redirect_uri(string $redirectUri): string
{
    $redirectUri = trim($redirectUri);
    $parts = parse_url($redirectUri);
    if ($redirectUri === ''
        || strlen($redirectUri) > 2048
        || preg_match('/[\x00-\x20\x7f]/', $redirectUri) === 1
        || !is_array($parts)
        || isset($parts['fragment'])) {
        throw new IndieAuthProtocolException('invalid_request', 'Redirect URI is invalid.');
    }
    if (empty($parts['scheme'])) {
        throw new IndieAuthProtocolException('invalid_request', 'Redirect URI is invalid.');
    }
    $scheme = strtolower((string) $parts['scheme']);
    if (in_array($scheme, ['http', 'https'], true)) {
        return indieauth_canonicalize_http_url($redirectUri);
    }
    if (preg_match('/^[a-z][a-z0-9+.-]*$/', $scheme) !== 1) {
        throw new IndieAuthProtocolException('invalid_request', 'Redirect URI scheme is invalid.');
    }
    if (in_array($scheme, ['about', 'blob', 'data', 'file', 'javascript', 'vbscript'], true)) {
        throw new IndieAuthProtocolException('invalid_request', 'Redirect URI scheme is not allowed.');
    }
    return $redirectUri;
}

function indieauth_resolve_url_reference(string $baseUrl, string $reference): string
{
    if (parse_url($reference, PHP_URL_SCHEME) !== null) {
        return $reference;
    }
    $base = parse_url($baseUrl);
    if (!is_array($base) || empty($base['scheme']) || empty($base['host'])) {
        return $reference;
    }
    $origin = indieauth_url_origin($baseUrl);
    if (str_starts_with($reference, '/')) {
        return $origin . $reference;
    }
    $path = (string) ($base['path'] ?? '/');
    $directory = rtrim(str_replace('\\', '/', dirname($path)), '/');
    return $origin . ($directory === '' ? '' : $directory) . '/' . $reference;
}

function indieauth_url_origin(string $url): string
{
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return '';
    }
    $scheme = strtolower((string) $parts['scheme']);
    $host = strtolower((string) $parts['host']);
    $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
    return $scheme . '://' . $host . $port;
}

function indieauth_is_public_ip(string $ip): bool
{
    return filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) !== false;
}

/**
 * @return list<string>
 */
function indieauth_resolve_public_ips(string $host): array
{
    if (filter_var($host, FILTER_VALIDATE_IP) !== false || strtolower($host) === 'localhost') {
        return [];
    }
    $records = @dns_get_record($host, DNS_A | DNS_AAAA);
    if (!is_array($records)) {
        return [];
    }
    $ips = [];
    foreach ($records as $record) {
        $ip = (string) ($record['ip'] ?? $record['ipv6'] ?? '');
        if ($ip === '' || !indieauth_is_public_ip($ip)) {
            return [];
        }
        $ips[$ip] = true;
    }
    return array_keys($ips);
}

/**
 * @return array{ok:bool,status?:int,body?:string,content_type?:string,location?:string,error?:string}
 */
function indieauth_safe_http_get(string $url): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'Client metadata requires the curl extension.'];
    }
    $current = $url;
    for ($redirects = 0; $redirects <= 3; $redirects++) {
        try {
            $current = indieauth_canonicalize_http_url($current);
        } catch (IndieAuthProtocolException $error) {
            return ['ok' => false, 'error' => $error->getMessage()];
        }
        $parts = parse_url($current);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (($parts['scheme'] ?? '') !== 'https') {
            return ['ok' => false, 'error' => 'Client metadata must use HTTPS.'];
        }
        $ips = indieauth_resolve_public_ips($host);
        if ($ips === []) {
            return ['ok' => false, 'error' => 'Client metadata host is not public.'];
        }
        $port = (int) ($parts['port'] ?? 443);
        $resolvedIp = str_contains($ips[0], ':') ? '[' . $ips[0] . ']' : $ips[0];
        $headers = [];
        $body = '';
        $tooLarge = false;
        $ch = curl_init($current);
        if ($ch === false) {
            return ['ok' => false, 'error' => 'Unable to initialize client metadata request.'];
        }
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $resolvedIp],
            CURLOPT_HTTPHEADER => ['Accept: application/json, text/html;q=0.8', 'User-Agent: PureBlog-Plus-IndieAuth'],
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
                $position = strpos($line, ':');
                if ($position !== false) {
                    $headers[strtolower(trim(substr($line, 0, $position)))] = trim(substr($line, $position + 1));
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooLarge): int {
                if (strlen($body) + strlen($chunk) > 262144) {
                    $tooLarge = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $completed = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($tooLarge) {
            return ['ok' => false, 'error' => 'Client metadata response is too large.'];
        }
        if ($completed !== true) {
            return ['ok' => false, 'error' => $error !== '' ? $error : 'Client metadata request failed.'];
        }
        if ($status >= 300 && $status < 400 && isset($headers['location'])) {
            $location = $headers['location'];
            if (!preg_match('#^https://#i', $location)) {
                return ['ok' => false, 'error' => 'Client metadata redirect is not HTTPS.'];
            }
            $current = $location;
            continue;
        }
        if ($status < 200 || $status >= 300) {
            return ['ok' => false, 'status' => $status, 'error' => 'Client metadata request returned HTTP ' . $status . '.'];
        }
        return [
            'ok' => true,
            'status' => $status,
            'body' => $body,
            'content_type' => (string) ($headers['content-type'] ?? ''),
        ];
    }
    return ['ok' => false, 'error' => 'Client metadata redirected too many times.'];
}

/**
 * @return array{name:string,uri:string,redirect_uris:list<string>}
 */
function indieauth_parse_client_metadata(string $clientId, array $response): array
{
    $name = parse_url($clientId, PHP_URL_HOST) ?: $clientId;
    $uri = $clientId;
    $redirectUris = [];
    $body = (string) ($response['body'] ?? '');
    $contentType = strtolower((string) ($response['content_type'] ?? ''));
    if (str_contains($contentType, 'json')) {
        $json = json_decode($body, true);
        if (is_array($json) && (string) ($json['client_id'] ?? '') === $clientId) {
            $candidateUri = indieauth_resolve_url_reference($clientId, (string) ($json['client_uri'] ?? $clientId));
            try {
                $candidateUri = indieauth_canonicalize_http_url($candidateUri);
                if (str_starts_with($clientId, $candidateUri)) {
                    $uri = $candidateUri;
                }
            } catch (IndieAuthProtocolException) {
            }
            $name = trim((string) ($json['client_name'] ?? $name)) ?: $name;
            foreach ((array) ($json['redirect_uris'] ?? []) as $redirectUri) {
                if (is_string($redirectUri)) {
                    $redirectUris[] = indieauth_resolve_url_reference($clientId, $redirectUri);
                }
            }
        }
    } elseif (class_exists(DOMDocument::class)) {
        $document = new DOMDocument();
        if (@$document->loadHTML($body)) {
            $titles = $document->getElementsByTagName('title');
            if ($titles->length > 0 && trim($titles->item(0)?->textContent ?? '') !== '') {
                $name = trim((string) $titles->item(0)?->textContent);
            }
            foreach ($document->getElementsByTagName('link') as $link) {
                $rels = preg_split('/\s+/', strtolower($link->getAttribute('rel')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                if (in_array('redirect_uri', $rels, true)) {
                    $href = trim($link->getAttribute('href'));
                    if ($href !== '') {
                        $redirectUris[] = indieauth_resolve_url_reference($clientId, $href);
                    }
                }
            }
        }
    }
    return ['name' => mb_substr($name, 0, 120), 'uri' => $uri, 'redirect_uris' => array_values(array_unique($redirectUris))];
}

/**
 * @return array{name:string,uri:string}
 */
function indieauth_resolve_client(string $clientId, string $redirectUri, ?callable $fetcher = null): array
{
    $sameOrigin = indieauth_url_origin($clientId) !== ''
        && indieauth_url_origin($clientId) === indieauth_url_origin($redirectUri);
    $fallback = [
        'name' => (string) (parse_url($clientId, PHP_URL_HOST) ?: $clientId),
        'uri' => $clientId,
    ];
    if ($sameOrigin) {
        return $fallback;
    }
    $host = strtolower((string) parse_url($clientId, PHP_URL_HOST));
    if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
        throw new IndieAuthProtocolException('invalid_request', 'Loopback client metadata cannot authorize a cross-origin redirect.');
    }
    $fetcher ??= 'indieauth_safe_http_get';
    $response = $fetcher($clientId);
    if (!is_array($response) || !($response['ok'] ?? false)) {
        throw new IndieAuthProtocolException('invalid_request', 'Unable to verify the client redirect URI.');
    }
    $metadata = indieauth_parse_client_metadata($clientId, $response);
    foreach ($metadata['redirect_uris'] as $allowed) {
        if (hash_equals($redirectUri, (string) $allowed)) {
            return ['name' => $metadata['name'], 'uri' => $metadata['uri']];
        }
    }
    throw new IndieAuthProtocolException('invalid_request', 'Redirect URI is not registered by the client.');
}

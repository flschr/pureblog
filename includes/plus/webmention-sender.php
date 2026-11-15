<?php

declare(strict_types=1);

plus_register_migration('0004_webmention_sender', static function (PDO $database): void {
    $database->exec(
        'CREATE TABLE plus_webmention_deliveries ('
        . 'id INTEGER PRIMARY KEY AUTOINCREMENT,'
        . 'source_url TEXT NOT NULL,'
        . 'target_url TEXT NOT NULL,'
        . 'endpoint_url TEXT NOT NULL DEFAULT \'\','
        . 'status TEXT NOT NULL CHECK (status IN (\'queued\',\'sent\',\'unsupported\',\'failed\')),'
        . 'generation INTEGER NOT NULL DEFAULT 1,'
        . 'last_http_status INTEGER,'
        . 'last_error TEXT NOT NULL DEFAULT \'\','
        . 'last_attempt_at INTEGER,'
        . 'delivered_at INTEGER,'
        . 'updated_at INTEGER NOT NULL,'
        . 'UNIQUE(source_url,target_url))'
    );
    $database->exec('CREATE INDEX plus_webmention_deliveries_status_idx ON plus_webmention_deliveries (status,updated_at)');
});

function webmention_sender_content_url(string $type, string $slug): string
{
    if (!in_array($type, ['post', 'page'], true) || trim($slug) === '') {
        throw new InvalidArgumentException('Invalid Webmention source.');
    }
    return indieauth_base_url() . '/' . rawurlencode($slug);
}

function webmention_sender_extract_targets(string $type, array $content, string $sourceUrl): array
{
    $html = $type === 'post'
        ? render_post_media($content) . render_post_content($content)
        : render_markdown((string) ($content['content'] ?? ''), ['page_title' => (string) ($content['title'] ?? '')]);
    if (!class_exists(DOMDocument::class) || trim($html) === '') {
        return [];
    }
    $document = new DOMDocument();
    if (!@$document->loadHTML('<!doctype html><html><body>' . $html . '</body></html>')) {
        return [];
    }
    $sourceHost = strtolower((string) (parse_url($sourceUrl, PHP_URL_HOST) ?? ''));
    $targets = [];
    foreach (['a', 'link'] as $tagName) {
        foreach ($document->getElementsByTagName($tagName) as $element) {
            if (!$element->hasAttribute('href')) {
                continue;
            }
            $url = indieauth_resolve_url_reference($sourceUrl, $element->getAttribute('href'));
            try {
                $url = webmention_validate_url($url);
            } catch (WebmentionProtocolException) {
                continue;
            }
            if (strtolower((string) (parse_url($url, PHP_URL_HOST) ?? '')) === $sourceHost) {
                continue;
            }
            $targets[$url] = true;
        }
    }
    return array_keys($targets);
}

function webmention_sender_content_saved(string $type, array $content, ?string $originalSlug, ?string $originalStatus): void
{
    try {
        $slug = (string) ($content['slug'] ?? '');
        $published = (string) ($content['status'] ?? '') === 'published';
        $wasPublished = $originalStatus === 'published';
        if ($published) {
            if ($wasPublished && $originalSlug !== null && $originalSlug !== '' && $originalSlug !== $slug) {
                webmention_sender_schedule_deleted($type, $originalSlug);
            }
            webmention_sender_schedule_content($type, $content);
        } elseif ($wasPublished) {
            webmention_sender_schedule_deleted($type, $originalSlug ?: $slug);
        }
    } catch (Throwable $error) {
        error_log('Unable to queue Webmentions: ' . $error->getMessage());
    }
}

function webmention_sender_content_deleted(string $type, string $slug): void
{
    try {
        webmention_sender_schedule_deleted($type, $slug);
    } catch (Throwable $error) {
        error_log('Unable to queue deleted-source Webmentions: ' . $error->getMessage());
    }
}

function webmention_sender_previous_targets(string $sourceUrl): array
{
    if (!is_file(plus_database_path())) {
        return [];
    }
    $query = plus_database()->prepare('SELECT target_url FROM plus_webmention_deliveries WHERE source_url=? ORDER BY id');
    $query->execute([$sourceUrl]);
    return array_values(array_map('strval', $query->fetchAll(PDO::FETCH_COLUMN) ?: []));
}

function webmention_sender_schedule_content(string $type, array $content, ?int $now = null): array
{
    $slug = (string) ($content['slug'] ?? '');
    $sourceUrl = webmention_sender_content_url($type, $slug);
    $currentTargets = webmention_sender_extract_targets($type, $content, $sourceUrl);
    $targets = array_values(array_unique(array_merge($currentTargets, webmention_sender_previous_targets($sourceUrl))));
    return webmention_sender_schedule_targets($sourceUrl, $targets, $now);
}

function webmention_sender_schedule_deleted(string $type, string $slug, ?int $now = null): array
{
    $sourceUrl = webmention_sender_content_url($type, $slug);
    return webmention_sender_schedule_targets($sourceUrl, webmention_sender_previous_targets($sourceUrl), $now);
}

function webmention_sender_schedule_targets(string $sourceUrl, array $targets, ?int $now = null): array
{
    $targets = array_values(array_unique(array_filter($targets, 'is_string')));
    if ($targets === []) {
        return [];
    }
    $now ??= time();
    $database = plus_database();
    $upsert = $database->prepare(
        'INSERT INTO plus_webmention_deliveries (source_url,target_url,status,updated_at) VALUES (?,?,\'queued\',?) '
        . 'ON CONFLICT(source_url,target_url) DO UPDATE SET status=\'queued\',generation=generation+1,last_error=\'\',updated_at=excluded.updated_at'
    );
    $jobs = [];
    foreach ($targets as $targetUrl) {
        $sourceUrl = webmention_validate_url($sourceUrl);
        $targetUrl = webmention_validate_url($targetUrl);
        if (hash_equals($sourceUrl, $targetUrl)) {
            continue;
        }
        $upsert->execute([$sourceUrl, $targetUrl, $now]);
        $query = $database->prepare('SELECT id,generation FROM plus_webmention_deliveries WHERE source_url=? AND target_url=?');
        $query->execute([$sourceUrl, $targetUrl]);
        $delivery = $query->fetch();
        if (!is_array($delivery)) {
            throw new RuntimeException('Unable to store Webmention delivery.');
        }
        $deliveryId = (int) $delivery['id'];
        $generation = (int) $delivery['generation'];
        $jobs[] = plus_enqueue_job(
            'webmention.send',
            ['delivery_id' => $deliveryId, 'generation' => $generation],
            $now,
            4,
            'webmention-send-' . hash('sha256', $sourceUrl . "\0" . $targetUrl . "\0" . $generation)
        );
    }
    return $jobs;
}

function webmention_sender_get(int $id): ?array
{
    $query = plus_database()->prepare('SELECT * FROM plus_webmention_deliveries WHERE id=?');
    $query->execute([$id]);
    $delivery = $query->fetch();
    return is_array($delivery) ? $delivery : null;
}

function webmention_sender_list(?string $status = null): array
{
    if ($status !== null) {
        $query = plus_database()->prepare('SELECT * FROM plus_webmention_deliveries WHERE status=? ORDER BY updated_at DESC,id DESC');
        $query->execute([$status]);
        return $query->fetchAll() ?: [];
    }
    return plus_database()->query('SELECT * FROM plus_webmention_deliveries ORDER BY updated_at DESC,id DESC')->fetchAll() ?: [];
}

function webmention_sender_retry(int $id, ?int $now = null): bool
{
    $delivery = webmention_sender_get($id);
    if ($delivery === null) {
        return false;
    }
    $now ??= time();
    plus_database()->prepare('UPDATE plus_webmention_deliveries SET status=\'queued\',generation=generation+1,last_error=\'\',updated_at=? WHERE id=?')->execute([$now, $id]);
    $generation = (int) webmention_sender_get($id)['generation'];
    plus_enqueue_job('webmention.send', ['delivery_id' => $id, 'generation' => $generation], $now, 4, 'webmention-retry-' . $id . '-' . $generation);
    return true;
}

function webmention_sender_safe_request(string $url, string $method = 'GET', array $form = [], int $maximumBytes = 1048576): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'Webmention delivery requires curl.'];
    }
    try {
        $url = webmention_validate_url($url);
    } catch (WebmentionProtocolException $error) {
        return ['ok' => false, 'error' => $error->getMessage()];
    }
    $parts = parse_url($url);
    $host = trim(strtolower((string) ($parts['host'] ?? '')), '[]');
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $ips = webmention_public_ips($host);
    if ($ips === []) {
        return ['ok' => false, 'error' => 'Webmention destination host is not public.'];
    }
    $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    $resolvedIp = str_contains($ips[0], ':') ? '[' . $ips[0] . ']' : $ips[0];
    $headers = [];
    $body = '';
    $tooLarge = false;
    $handle = curl_init($url);
    if ($handle === false) {
        return ['ok' => false, 'error' => 'Unable to initialize Webmention request.'];
    }
    $requestHeaders = ['Accept: text/html, application/xhtml+xml;q=0.9, */*;q=0.1', 'User-Agent: PureBlog-Plus-Webmention'];
    $options = [
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_PROXY => '',
        CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $resolvedIp],
        CURLOPT_HTTPHEADER => $requestHeaders,
        CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
            $position = strpos($line, ':');
            if ($position !== false) {
                $name = strtolower(trim(substr($line, 0, $position)));
                $value = trim(substr($line, $position + 1));
                $headers[$name] = isset($headers[$name]) ? $headers[$name] . ', ' . $value : $value;
            }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooLarge, $maximumBytes): int {
            if (strlen($body) + strlen($chunk) > $maximumBytes) {
                $tooLarge = true;
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        },
    ];
    if (strtoupper($method) === 'POST') {
        $encoded = http_build_query($form, '', '&', PHP_QUERY_RFC3986);
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = $encoded;
        $options[CURLOPT_HTTPHEADER] = array_merge($requestHeaders, ['Content-Type: application/x-www-form-urlencoded']);
    }
    curl_setopt_array($handle, $options);
    $completed = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_error($handle);
    curl_close($handle);
    if ($tooLarge) {
        return ['ok' => false, 'status' => $status, 'error' => 'Webmention response is too large.'];
    }
    if ($completed !== true) {
        return ['ok' => false, 'status' => $status, 'error' => $error ?: 'Webmention request failed.'];
    }
    return [
        'ok' => $status >= 200 && $status < 300,
        'status' => $status,
        'body' => $body,
        'headers' => $headers,
        'content_type' => strtolower((string) ($headers['content-type'] ?? '')),
        'url' => $url,
        'error' => $status >= 200 && $status < 300 ? '' : 'Destination returned HTTP ' . $status . '.',
    ];
}

function webmention_sender_fetch_target(string $targetUrl, ?callable $requester = null): array
{
    $requester ??= 'webmention_sender_safe_request';
    $current = $targetUrl;
    for ($redirects = 0; $redirects <= 5; $redirects++) {
        $response = $requester($current, 'GET', [], 1048576);
        if (!is_array($response)) {
            throw new RuntimeException('Invalid Webmention discovery response.');
        }
        $status = (int) ($response['status'] ?? 0);
        $location = (string) (($response['headers']['location'] ?? ''));
        if ($status >= 300 && $status < 400 && $location !== '') {
            $current = indieauth_resolve_url_reference($current, $location);
            $current = webmention_validate_url($current);
            continue;
        }
        $response['url'] = $current;
        return $response;
    }
    return ['ok' => false, 'error' => 'Webmention target redirected too many times.', 'url' => $current];
}

function webmention_sender_split_link_header(string $header): array
{
    $entries = [];
    $start = 0;
    $quoted = false;
    $angleDepth = 0;
    $escaped = false;
    $length = strlen($header);
    for ($index = 0; $index < $length; $index++) {
        $character = $header[$index];
        if ($quoted && $character === '\\' && !$escaped) {
            $escaped = true;
            continue;
        }
        if ($character === '"' && !$escaped) {
            $quoted = !$quoted;
        } elseif (!$quoted && $character === '<') {
            $angleDepth++;
        } elseif (!$quoted && $character === '>' && $angleDepth > 0) {
            $angleDepth--;
        } elseif (!$quoted && $angleDepth === 0 && $character === ',') {
            $entries[] = trim(substr($header, $start, $index - $start));
            $start = $index + 1;
        }
        $escaped = false;
    }
    $entries[] = trim(substr($header, $start));
    return array_values(array_filter($entries, static fn(string $entry): bool => $entry !== ''));
}

function webmention_sender_link_header_endpoint(string $header, string $baseUrl): ?string
{
    foreach (webmention_sender_split_link_header($header) as $entry) {
        if (preg_match('/^\s*<([^>]+)>(.*)$/s', $entry, $match) !== 1
            || preg_match('/;\s*rel\s*=\s*(?:"([^"]*)"|([^;\s,]+))/i', (string) $match[2], $relMatch) !== 1) {
            continue;
        }
        $relValue = trim((string) ($relMatch[1] ?? '')) !== '' ? (string) $relMatch[1] : (string) ($relMatch[2] ?? '');
        $relations = preg_split('/\s+/', strtolower($relValue), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (in_array('webmention', $relations, true)) {
            return indieauth_resolve_url_reference($baseUrl, (string) $match[1]);
        }
    }
    return null;
}

function webmention_sender_html_endpoint(string $html, string $baseUrl): ?string
{
    if (!class_exists(DOMDocument::class)) {
        return null;
    }
    $document = new DOMDocument();
    if (!@$document->loadHTML($html)) {
        return null;
    }
    $xpath = new DOMXPath($document);
    foreach ($xpath->query('//link[@href] | //a[@href]') ?: [] as $element) {
        if (!$element instanceof DOMElement) {
            continue;
        }
        $relations = preg_split('/\s+/', strtolower($element->getAttribute('rel')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (in_array('webmention', $relations, true)) {
            return indieauth_resolve_url_reference($baseUrl, $element->getAttribute('href'));
        }
    }
    return null;
}

function webmention_sender_discover_endpoint(string $targetUrl, ?callable $requester = null): ?string
{
    $response = webmention_sender_fetch_target($targetUrl, $requester);
    if (!($response['ok'] ?? false)) {
        throw new RuntimeException((string) ($response['error'] ?? 'Unable to fetch Webmention target.'));
    }
    $baseUrl = (string) ($response['url'] ?? $targetUrl);
    $headers = is_array($response['headers'] ?? null) ? $response['headers'] : [];
    $endpoint = webmention_sender_link_header_endpoint((string) ($headers['link'] ?? ''), $baseUrl);
    if ($endpoint === null && (str_contains((string) ($response['content_type'] ?? ''), 'html') || str_contains((string) ($response['content_type'] ?? ''), 'xhtml'))) {
        $endpoint = webmention_sender_html_endpoint((string) ($response['body'] ?? ''), $baseUrl);
    }
    return $endpoint === null ? null : webmention_validate_url($endpoint);
}

function webmention_sender_update_delivery(int $id, string $status, string $endpoint, ?int $httpStatus, string $error, int $now): void
{
    $deliveredAt = $status === 'sent' ? $now : null;
    plus_database()->prepare(
        'UPDATE plus_webmention_deliveries SET endpoint_url=?,status=?,last_http_status=?,last_error=?,last_attempt_at=?,delivered_at=?,updated_at=? WHERE id=?'
    )->execute([$endpoint, $status, $httpStatus, mb_substr(trim($error), 0, 1000), $now, $deliveredAt, $now, $id]);
}

function webmention_sender_deliver(int $id, ?callable $requester = null, ?callable $poster = null, ?int $now = null, ?int $expectedGeneration = null): void
{
    $delivery = webmention_sender_get($id);
    if ($delivery === null) {
        return;
    }
    if ($expectedGeneration !== null && (int) $delivery['generation'] !== $expectedGeneration) {
        return;
    }
    $now ??= time();
    try {
        $endpoint = webmention_sender_discover_endpoint((string) $delivery['target_url'], $requester);
        if ($endpoint === null) {
            webmention_sender_update_delivery($id, 'unsupported', '', null, '', $now);
            return;
        }
        $poster ??= static fn(string $url, array $form): array => webmention_sender_safe_request($url, 'POST', $form, 65536);
        $response = $poster($endpoint, ['source' => (string) $delivery['source_url'], 'target' => (string) $delivery['target_url']]);
        if (!is_array($response) || !($response['ok'] ?? false)) {
            $status = is_array($response) ? (int) ($response['status'] ?? 0) : 0;
            $error = is_array($response) ? (string) ($response['error'] ?? 'Webmention endpoint rejected the request.') : 'Invalid Webmention delivery response.';
            webmention_sender_update_delivery($id, 'failed', $endpoint, $status > 0 ? $status : null, $error, $now);
            throw new RuntimeException($error);
        }
        webmention_sender_update_delivery($id, 'sent', $endpoint, (int) ($response['status'] ?? 0), '', $now);
    } catch (Throwable $error) {
        $latest = webmention_sender_get($id);
        if ($latest !== null && $latest['status'] !== 'failed') {
            webmention_sender_update_delivery($id, 'failed', (string) ($latest['endpoint_url'] ?? ''), null, $error->getMessage(), $now);
        }
        throw $error;
    }
}

plus_register_job_handler('webmention.send', static function (array $payload): void {
    webmention_sender_deliver(
        (int) ($payload['delivery_id'] ?? 0),
        null,
        null,
        null,
        (int) ($payload['generation'] ?? 0)
    );
});
plus_register_capability('webmention.sender', 1, 0);

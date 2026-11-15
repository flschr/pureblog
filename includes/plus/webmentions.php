<?php

declare(strict_types=1);

final class WebmentionProtocolException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 400)
    {
        parent::__construct($message);
    }
}

plus_register_migration('0003_webmentions', static function (PDO $database): void {
    $database->exec(
        'CREATE TABLE plus_webmentions ('
        . 'id INTEGER PRIMARY KEY AUTOINCREMENT,'
        . 'source_url TEXT NOT NULL,'
        . 'target_url TEXT NOT NULL,'
        . 'target_type TEXT NOT NULL CHECK (target_type IN (\'post\',\'page\')),'
        . 'target_slug TEXT NOT NULL,'
        . 'status TEXT NOT NULL CHECK (status IN (\'pending\',\'verified\',\'approved\',\'rejected\',\'invalid\',\'deleted\')),'
        . 'mention_type TEXT NOT NULL DEFAULT \'mention\','
        . 'author_name TEXT NOT NULL DEFAULT \'\','
        . 'author_url TEXT NOT NULL DEFAULT \'\','
        . 'author_photo TEXT NOT NULL DEFAULT \'\','
        . 'source_title TEXT NOT NULL DEFAULT \'\','
        . 'excerpt TEXT NOT NULL DEFAULT \'\','
        . 'received_at INTEGER NOT NULL,'
        . 'verified_at INTEGER,'
        . 'moderated_at INTEGER,'
        . 'updated_at INTEGER NOT NULL,'
        . 'UNIQUE(source_url,target_url))'
    );
    $database->exec('CREATE INDEX plus_webmentions_target_idx ON plus_webmentions (target_type,target_slug,status,verified_at)');
    $database->exec(
        'CREATE TABLE plus_webmention_requests ('
        . 'id INTEGER PRIMARY KEY AUTOINCREMENT,'
        . 'sender_ip TEXT NOT NULL,'
        . 'received_at INTEGER NOT NULL)'
    );
    $database->exec('CREATE INDEX plus_webmention_requests_rate_idx ON plus_webmention_requests (sender_ip,received_at)');
});

function webmention_endpoint_url(): string
{
    return indieauth_endpoint_url('webmention/index.php');
}

function webmention_validate_url(string $url): string
{
    $url = trim($url);
    if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
        throw new WebmentionProtocolException('Source and target must be valid HTTP URLs.');
    }
    $parts = parse_url($url);
    if (!is_array($parts)
        || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
        || empty($parts['host'])
        || isset($parts['user'])
        || isset($parts['pass'])) {
        throw new WebmentionProtocolException('Source and target must be valid HTTP URLs.');
    }
    return $url;
}

function webmention_target_resource(string $target): array
{
    $target = webmention_validate_url($target);
    $parts = parse_url($target);
    $base = parse_url(indieauth_base_url());
    if (!is_array($parts) || !is_array($base)
        || indieauth_url_origin($target) !== indieauth_url_origin(indieauth_base_url())
        || isset($parts['query'])) {
        throw new WebmentionProtocolException('The target is not a supported resource on this site.');
    }
    $basePath = rtrim((string) ($base['path'] ?? ''), '/');
    $path = rawurldecode((string) ($parts['path'] ?? ''));
    if ($basePath !== '' && str_starts_with($path, $basePath . '/')) {
        $path = substr($path, strlen($basePath));
    }
    $slug = trim($path, '/');
    if ($slug === '' || str_contains($slug, '/')) {
        throw new WebmentionProtocolException('The target is not a supported resource on this site.');
    }
    $post = get_post_by_slug($slug, false);
    $page = $post === null ? get_page_by_slug($slug, false) : null;
    if ($post === null && $page === null) {
        throw new WebmentionProtocolException('The target is not a published post or page on this site.');
    }
    $canonical = indieauth_base_url() . '/' . rawurlencode($slug);
    $withoutFragment = preg_replace('/#.*$/', '', $target) ?? $target;
    if (!hash_equals($canonical, $withoutFragment)) {
        throw new WebmentionProtocolException('The target URL is not canonical for this site.');
    }
    return ['type' => $post !== null ? 'post' : 'page', 'slug' => $slug, 'url' => $target];
}

function webmention_check_rate_limit(string $senderIp, ?int $now = null): void
{
    $now ??= time();
    $database = plus_database();
    $database->prepare('DELETE FROM plus_webmention_requests WHERE received_at < ?')->execute([$now - 86400]);
    $query = $database->prepare('SELECT COUNT(*) FROM plus_webmention_requests WHERE sender_ip=? AND received_at>=?');
    $query->execute([$senderIp, $now - 3600]);
    if ((int) $query->fetchColumn() >= 30) {
        throw new WebmentionProtocolException('Too many Webmention requests. Try again later.', 429);
    }
    $database->prepare('INSERT INTO plus_webmention_requests (sender_ip,received_at) VALUES (?,?)')->execute([$senderIp, $now]);
}

function webmention_receive(string $source, string $target, string $senderIp = '', ?int $now = null): array
{
    $source = webmention_validate_url($source);
    $resource = webmention_target_resource($target);
    $sourceResource = preg_replace('/#.*$/', '', $source) ?? $source;
    $targetResource = preg_replace('/#.*$/', '', $target) ?? $target;
    if (hash_equals($sourceResource, $targetResource)) {
        throw new WebmentionProtocolException('Source and target must be different URLs.');
    }
    $now ??= time();
    webmention_check_rate_limit($senderIp !== '' ? $senderIp : 'unknown', $now);
    $database = plus_database();
    $insert = $database->prepare(
        'INSERT INTO plus_webmentions (source_url,target_url,target_type,target_slug,status,received_at,updated_at) '
        . 'VALUES (?,?,?,?,\'pending\',?,?) '
        . 'ON CONFLICT(source_url,target_url) DO UPDATE SET target_type=excluded.target_type,'
        . 'target_slug=excluded.target_slug,received_at=excluded.received_at,updated_at=excluded.updated_at'
    );
    $insert->execute([$source, $target, $resource['type'], $resource['slug'], $now, $now]);
    $query = $database->prepare('SELECT * FROM plus_webmentions WHERE source_url=? AND target_url=?');
    $query->execute([$source, $target]);
    $mention = $query->fetch();
    if (!is_array($mention)) {
        throw new RuntimeException('Unable to store Webmention.');
    }
    webmention_enqueue_verification((int) $mention['id'], $now);
    return $mention;
}

function webmention_enqueue_verification(int $id, ?int $now = null): array
{
    $now ??= time();
    return plus_enqueue_job(
        'webmention.verify',
        ['webmention_id' => $id],
        $now,
        4,
        'webmention-' . $id . '-' . $now
    );
}

function webmention_public_ips(string $host): array
{
    $host = trim($host, '[]');
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        return indieauth_is_public_ip($host) ? [$host] : [];
    }
    return indieauth_resolve_public_ips($host);
}

function webmention_safe_fetch(string $url): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'Webmention verification requires curl.'];
    }
    $current = webmention_validate_url($url);
    for ($redirects = 0; $redirects <= 5; $redirects++) {
        $parts = parse_url($current);
        $host = trim(strtolower((string) ($parts['host'] ?? '')), '[]');
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $ips = webmention_public_ips($host);
        if ($ips === []) {
            return ['ok' => false, 'error' => 'Source host is not public.'];
        }
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $resolvedIp = str_contains($ips[0], ':') ? '[' . $ips[0] . ']' : $ips[0];
        $headers = [];
        $body = '';
        $tooLarge = false;
        $handle = curl_init($current);
        if ($handle === false) {
            return ['ok' => false, 'error' => 'Unable to initialize source request.'];
        }
        curl_setopt_array($handle, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $resolvedIp],
            CURLOPT_HTTPHEADER => ['Accept: text/html, application/json;q=0.8, text/plain;q=0.5', 'User-Agent: PureBlog-Plus-Webmention'],
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
                $position = strpos($line, ':');
                if ($position !== false) {
                    $headers[strtolower(trim(substr($line, 0, $position)))] = trim(substr($line, $position + 1));
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooLarge): int {
                if (strlen($body) + strlen($chunk) > 1048576) {
                    $tooLarge = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $completed = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if ($tooLarge) {
            return ['ok' => false, 'status' => $status, 'error' => 'Source response is too large.'];
        }
        if ($completed !== true) {
            return ['ok' => false, 'status' => $status, 'error' => $error ?: 'Source request failed.'];
        }
        if ($status >= 300 && $status < 400 && isset($headers['location'])) {
            $current = indieauth_resolve_url_reference($current, $headers['location']);
            try {
                $current = webmention_validate_url($current);
            } catch (WebmentionProtocolException $exception) {
                return ['ok' => false, 'error' => $exception->getMessage()];
            }
            continue;
        }
        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'body' => $body,
            'content_type' => strtolower((string) ($headers['content-type'] ?? '')),
            'url' => $current,
            'error' => $status >= 200 && $status < 300 ? '' : 'Source returned HTTP ' . $status . '.',
        ];
    }
    return ['ok' => false, 'error' => 'Source redirected too many times.'];
}

function webmention_resolve_reference(string $base, string $reference): string
{
    return indieauth_resolve_url_reference($base, trim($reference));
}

function webmention_extract(string $sourceUrl, string $targetUrl, array $response): ?array
{
    $body = (string) ($response['body'] ?? '');
    $contentType = (string) ($response['content_type'] ?? '');
    $finalUrl = (string) ($response['url'] ?? $sourceUrl);
    $data = ['mention_type' => 'mention', 'author_name' => '', 'author_url' => '', 'author_photo' => '', 'source_title' => '', 'excerpt' => ''];
    if (str_contains($contentType, 'html') && class_exists(DOMDocument::class)) {
        $document = new DOMDocument();
        if (!@$document->loadHTML($body)) {
            return null;
        }
        $linked = false;
        foreach ($document->getElementsByTagName('*') as $element) {
            foreach (['href', 'src', 'poster', 'cite', 'action'] as $attribute) {
                if (!$element->hasAttribute($attribute)) {
                    continue;
                }
                $resolved = webmention_resolve_reference($finalUrl, $element->getAttribute($attribute));
                if (hash_equals($targetUrl, $resolved)) {
                    $linked = true;
                    $classes = preg_split('/\s+/', strtolower($element->getAttribute('class')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                    if (in_array('u-like-of', $classes, true)) {
                        $data['mention_type'] = 'like';
                    } elseif (in_array('u-repost-of', $classes, true)) {
                        $data['mention_type'] = 'repost';
                    } elseif (in_array('u-in-reply-to', $classes, true)) {
                        $data['mention_type'] = 'reply';
                    }
                }
            }
        }
        if (!$linked) {
            return null;
        }
        $titles = $document->getElementsByTagName('title');
        $data['source_title'] = trim((string) ($titles->item(0)?->textContent ?? ''));
        $xpath = new DOMXPath($document);
        $author = $xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," p-author ")]')->item(0);
        $content = $xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," e-content ") or contains(concat(" ",normalize-space(@class)," ")," p-content ")]')->item(0);
        if ($author !== null) {
            $data['author_name'] = trim((string) $author->textContent);
            if ($author instanceof DOMElement && $author->hasAttribute('href')) {
                $data['author_url'] = webmention_resolve_reference($finalUrl, $author->getAttribute('href'));
            }
        }
        if ($content !== null) {
            $data['excerpt'] = trim((string) $content->textContent);
        } else {
            $descriptions = $xpath->query('//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="description"]/@content');
            $data['excerpt'] = trim((string) ($descriptions->item(0)?->nodeValue ?? ''));
        }
    } elseif (str_contains($contentType, 'json')) {
        $linked = str_contains($body, json_encode($targetUrl, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        if (!$linked) {
            return null;
        }
    } elseif (!str_contains($body, $targetUrl)) {
        return null;
    }
    if ($data['author_name'] === '') {
        $data['author_name'] = (string) (parse_url($sourceUrl, PHP_URL_HOST) ?: $sourceUrl);
    }
    if ($data['author_url'] !== '') {
        $authorParts = parse_url($data['author_url']);
        if (!is_array($authorParts) || !in_array(strtolower((string) ($authorParts['scheme'] ?? '')), ['http', 'https'], true)) {
            $data['author_url'] = '';
        }
    }
    foreach (['author_name' => 120, 'source_title' => 200, 'excerpt' => 500] as $field => $limit) {
        $data[$field] = mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags((string) $data[$field])) ?? ''), 0, $limit);
    }
    return $data;
}

function webmention_mark_invalid(int $id, bool $deleted, ?int $now = null): void
{
    $now ??= time();
    $database = plus_database();
    $query = $database->prepare('SELECT status FROM plus_webmentions WHERE id=?');
    $query->execute([$id]);
    $previous = (string) $query->fetchColumn();
    $status = $deleted || in_array($previous, ['approved', 'verified'], true) ? 'deleted' : 'invalid';
    $database->prepare('UPDATE plus_webmentions SET status=?,verified_at=?,updated_at=? WHERE id=?')->execute([$status, $now, $now, $id]);
    cache_clear();
}

function webmention_get(int $id): ?array
{
    $query = plus_database()->prepare('SELECT * FROM plus_webmentions WHERE id=?');
    $query->execute([$id]);
    $mention = $query->fetch();
    return is_array($mention) ? $mention : null;
}

function webmention_verify(int $id, ?callable $fetcher = null, ?int $now = null): void
{
    $now ??= time();
    $database = plus_database();
    $mention = webmention_get($id);
    if ($mention === null) {
        return;
    }
    $fetcher ??= 'webmention_safe_fetch';
    $response = $fetcher((string) $mention['source_url']);
    if (!is_array($response)) {
        throw new RuntimeException('Invalid source fetch response.');
    }
    $statusCode = (int) ($response['status'] ?? 0);
    if (!($response['ok'] ?? false)) {
        if (in_array($statusCode, [404, 410], true)) {
            webmention_mark_invalid($id, true, $now);
            return;
        }
        throw new RuntimeException((string) ($response['error'] ?? 'Unable to fetch Webmention source.'));
    }
    $data = webmention_extract((string) $mention['source_url'], (string) $mention['target_url'], $response);
    if ($data === null) {
        webmention_mark_invalid($id, false, $now);
        return;
    }
    $status = in_array((string) $mention['status'], ['approved', 'rejected'], true) ? $mention['status'] : 'verified';
    $update = $database->prepare(
        'UPDATE plus_webmentions SET status=?,mention_type=?,author_name=?,author_url=?,author_photo=?,source_title=?,excerpt=?,verified_at=?,updated_at=? WHERE id=?'
    );
    $update->execute([$status, $data['mention_type'], $data['author_name'], $data['author_url'], $data['author_photo'], $data['source_title'], $data['excerpt'], $now, $now, $id]);
    cache_clear();
}

function webmention_list(?string $status = null): array
{
    if ($status !== null) {
        $query = plus_database()->prepare('SELECT * FROM plus_webmentions WHERE status=? ORDER BY received_at DESC,id DESC');
        $query->execute([$status]);
        return $query->fetchAll() ?: [];
    }
    return plus_database()->query('SELECT * FROM plus_webmentions ORDER BY received_at DESC,id DESC')->fetchAll() ?: [];
}

function webmention_moderate(int $id, string $status, ?int $now = null): bool
{
    if (!in_array($status, ['approved', 'rejected'], true)) {
        throw new InvalidArgumentException('Invalid Webmention moderation status.');
    }
    $update = plus_database()->prepare("UPDATE plus_webmentions SET status=?,moderated_at=?,updated_at=? WHERE id=? AND status IN ('verified','approved','rejected')");
    $timestamp = $now ?? time();
    $update->execute([$status, $timestamp, $timestamp, $id]);
    if ($update->rowCount() === 1) {
        cache_clear();
        return true;
    }
    return false;
}

function webmention_approved_for(string $type, string $slug): array
{
    if (!is_file(plus_database_path())) {
        return [];
    }
    $query = plus_database()->prepare("SELECT * FROM plus_webmentions WHERE target_type=? AND target_slug=? AND status='approved' ORDER BY verified_at ASC,id ASC");
    $query->execute([$type, $slug]);
    return $query->fetchAll() ?: [];
}

function render_webmentions(string $type, string $slug): string
{
    $mentions = webmention_approved_for($type, $slug);
    if ($mentions === []) {
        return '';
    }
    $items = [];
    foreach ($mentions as $mention) {
        $author = e((string) ($mention['author_name'] ?: parse_url((string) $mention['source_url'], PHP_URL_HOST)));
        $source = e((string) $mention['source_url']);
        $label = match ((string) $mention['mention_type']) {
            'like' => t('frontend.webmention_liked'),
            'repost' => t('frontend.webmention_reposted'),
            'reply' => t('frontend.webmention_replied'),
            default => t('frontend.webmention_mentioned'),
        };
        $excerpt = trim((string) $mention['excerpt']);
        $items[] = '<li><a href="' . $source . '" rel="nofollow ugc noopener noreferrer">' . $author . '</a> ' . e($label)
            . ($excerpt !== '' ? '<blockquote>' . e($excerpt) . '</blockquote>' : '') . '</li>';
    }
    return '<section class="webmentions"><h2>' . e(t('frontend.webmentions_heading')) . '</h2><ul>' . implode('', $items) . '</ul></section>';
}

plus_register_job_handler('webmention.verify', static function (array $payload): void {
    webmention_verify((int) ($payload['webmention_id'] ?? 0));
});
plus_register_capability('webmention.receiver', 1, 0);

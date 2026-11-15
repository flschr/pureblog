<?php

declare(strict_types=1);

function webmention_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function webmention_test_remove_tree(string $path): void
{
    if (!is_dir($path)) return;
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
}

$root = sys_get_temp_dir() . '/pureblog-plus-webmentions-' . bin2hex(random_bytes(8));
define('PUREBLOG_PLUS_DATA_PATH', $root . '/plus');

try {
    require dirname(__DIR__) . '/functions.php';

    webmention_test_assert(plus_has_capability('webmention.receiver', 1, 0), 'Webmention capability missing.');
    webmention_test_assert(!is_dir(PUREBLOG_PLUS_DATA_PATH), 'Webmention storage initialized eagerly.');
    webmention_test_assert(webmention_public_ips('127.0.0.1') === [], 'Loopback address accepted for verification.');
    webmention_test_assert(webmention_public_ips('10.0.0.1') === [], 'Private address accepted for verification.');
    webmention_test_assert(webmention_public_ips('[::1]') === [], 'IPv6 loopback address accepted for verification.');

    foreach (['https://user:secret@example.test/post', 'file:///etc/passwd'] as $unsafeUrl) {
        try {
            webmention_validate_url($unsafeUrl);
            throw new RuntimeException('Unsafe URL accepted.');
        } catch (WebmentionProtocolException) {
        }
    }

    $target = webmention_target_resource('http://localhost/demo');
    webmention_test_assert($target['type'] === 'page' && $target['slug'] === 'demo', 'Published local target was not resolved.');
    try {
        webmention_target_resource('http://localhost/demo/');
        throw new RuntimeException('Non-canonical target URL accepted.');
    } catch (WebmentionProtocolException) {
    }
    try {
        webmention_receive('http://localhost/demo#source', 'http://localhost/demo#target', '192.0.2.10', 900);
        throw new RuntimeException('Source and target for the same resource were accepted.');
    } catch (WebmentionProtocolException) {
    }

    $received = webmention_receive('https://social.example/notification', 'http://localhost/demo', '192.0.2.10', 950);
    webmention_test_assert($received['status'] === 'pending' && $received['target_slug'] === 'demo', 'Webmention request was not accepted for verification.');

    $database = plus_database();
    $database->prepare(
        "INSERT INTO plus_webmentions (source_url,target_url,target_type,target_slug,status,received_at,updated_at) VALUES (?,?,?,?, 'pending',?,?)"
    )->execute(['https://social.example/post/1', 'https://blog.example/article', 'post', 'article', 1000, 1000]);
    $id = (int) $database->lastInsertId();

    $response = [
        'ok' => true,
        'status' => 200,
        'url' => 'https://social.example/post/1',
        'content_type' => 'text/html; charset=utf-8',
        'body' => '<!doctype html><html><head><title>A response</title></head><body>'
            . '<a class="u-in-reply-to" href="https://blog.example/article">Original</a>'
            . '<a class="p-author" href="/person">Example Author</a>'
            . '<div class="e-content">Hello &lt;script&gt;alert(1)&lt;/script&gt;</div>'
            . '</body></html>',
    ];
    webmention_verify($id, static fn(string $url): array => $response, 1010);
    $verified = webmention_get($id);
    webmention_test_assert($verified !== null && $verified['status'] === 'verified', 'Valid Webmention was not verified.');
    webmention_test_assert($verified['mention_type'] === 'reply', 'Reply type was not recognized.');
    webmention_test_assert($verified['author_name'] === 'Example Author', 'Author name was not extracted.');
    webmention_test_assert($verified['author_url'] === 'https://social.example/person', 'Relative author URL was not resolved.');

    webmention_test_assert(webmention_review($id, 'rejected', 1020), 'Verified Webmention could not be ignored.');
    webmention_test_assert(webmention_get($id)['status'] === 'rejected', 'Ignored Webmention was not retained in the inbox.');

    webmention_verify($id, static fn(string $url): array => $response, 1030);
    webmention_test_assert(webmention_get($id)['status'] === 'rejected', 'Reverification lost review state.');
    webmention_test_assert(webmention_review($id, 'verified', 1035), 'Ignored Webmention could not be restored.');
    $withoutLink = $response;
    $withoutLink['body'] = '<!doctype html><html><body>No link remains.</body></html>';
    webmention_verify($id, static fn(string $url): array => $withoutLink, 1040);
    webmention_test_assert(webmention_get($id)['status'] === 'deleted', 'Removed verified Webmention was not marked deleted.');

    $database->prepare(
        "INSERT INTO plus_webmentions (source_url,target_url,target_type,target_slug,status,received_at,updated_at) VALUES (?,?,?,?, 'pending',?,?)"
    )->execute(['https://social.example/post/2', 'https://blog.example/article', 'post', 'article', 1050, 1050]);
    $invalidId = (int) $database->lastInsertId();
    webmention_verify($invalidId, static fn(string $url): array => $withoutLink, 1060);
    webmention_test_assert(webmention_get($invalidId)['status'] === 'invalid', 'New source without a link was not marked invalid.');
    webmention_test_assert(count(webmention_list(null, 1)) === 1, 'Webmention inbox list limit was not applied.');

    $firstJob = webmention_enqueue_verification($invalidId, 1070);
    $duplicateJob = webmention_enqueue_verification($invalidId, 1070);
    webmention_test_assert($firstJob['id'] === $duplicateJob['id'], 'Duplicate verification job was created.');

    for ($request = 0; $request < 30; $request++) {
        webmention_check_rate_limit('203.0.113.10', 2000);
    }
    try {
        webmention_check_rate_limit('203.0.113.10', 2000);
        throw new RuntimeException('Webmention rate limit was not enforced.');
    } catch (WebmentionProtocolException $error) {
        webmention_test_assert($error->httpStatus === 429, 'Rate limit returned the wrong status.');
    }

    echo "PASS: Webmention verification, private inbox review, deletion, SSRF boundaries, jobs, and rate limits\n";
} finally {
    webmention_test_remove_tree($root);
}

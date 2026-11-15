<?php

declare(strict_types=1);

function sender_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function sender_test_remove_tree(string $path): void
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

$root = sys_get_temp_dir() . '/pureblog-plus-webmention-sender-' . bin2hex(random_bytes(8));
define('PUREBLOG_PLUS_DATA_PATH', $root . '/plus');

try {
    require dirname(__DIR__) . '/functions.php';

    sender_test_assert(plus_has_capability('webmention.sender', 1, 0), 'Webmention sender capability missing.');
    sender_test_assert(!is_dir(PUREBLOG_PLUS_DATA_PATH), 'Webmention sender initialized storage eagerly.');

    $post = [
        'title' => 'Source',
        'slug' => 'source',
        'status' => 'published',
        'content' => '[External](https://target.example/article#part) [Local](/demo)',
        'micropub_in_reply_to' => json_encode(['https://reply.example/original'], JSON_THROW_ON_ERROR),
    ];
    $relationships = render_post_relationships($post);
    sender_test_assert(str_contains($relationships, 'class="u-in-reply-to"'), 'Micropub reply relationship was not rendered.');
    sender_test_assert(str_contains($relationships, 'https://reply.example/original'), 'Micropub reply target was not rendered.');
    $targets = webmention_sender_extract_targets('post', $post, 'http://localhost/source');
    sort($targets);
    sender_test_assert($targets === ['https://reply.example/original', 'https://target.example/article#part'], 'Published external targets were not extracted correctly.');

    $headerResponse = static fn(string $url, string $method, array $form, int $limit): array => [
        'ok' => true,
        'status' => 200,
        'headers' => ['link' => '</header-endpoint?token=1>; rel="alternate webmention"'],
        'content_type' => 'text/html',
        'body' => '<link rel="webmention" href="/html-endpoint">',
        'url' => $url,
    ];
    sender_test_assert(
        webmention_sender_discover_endpoint('https://target.example/article', $headerResponse) === 'https://target.example/header-endpoint?token=1',
        'HTTP Link header did not take precedence during discovery.'
    );
    sender_test_assert(
        webmention_sender_link_header_endpoint('</endpoint>; rel=webmention', 'https://target.example/article') === 'https://target.example/endpoint',
        'Unquoted Webmention relation was not discovered.'
    );
    sender_test_assert(
        webmention_sender_link_header_endpoint('</alternate>; rel=alternate; title="One, two", </wm>; rel=webmention', 'https://target.example/article') === 'https://target.example/wm',
        'Webmention relation after a quoted comma was not discovered.'
    );

    $requested = [];
    $redirectResponse = static function (string $url, string $method, array $form, int $limit) use (&$requested): array {
        $requested[] = $url;
        if (count($requested) === 1) {
            return ['ok' => false, 'status' => 302, 'headers' => ['location' => '/canonical'], 'body' => '', 'content_type' => 'text/html'];
        }
        return [
            'ok' => true,
            'status' => 200,
            'headers' => [],
            'content_type' => 'text/html',
            'body' => '<a rel="alternate webmention" href="wm">Endpoint</a>',
        ];
    };
    sender_test_assert(
        webmention_sender_discover_endpoint('https://target.example/original', $redirectResponse) === 'https://target.example/wm',
        'HTML endpoint was not resolved against the redirected target.'
    );
    sender_test_assert($requested === ['https://target.example/original', 'https://target.example/canonical'], 'Discovery redirect was not followed safely.');

    $jobs = webmention_sender_schedule_targets('http://localhost/source', ['https://target.example/article'], 1000);
    sender_test_assert(count($jobs) === 1, 'Webmention delivery job was not queued.');
    $staleGeneration = (int) $jobs[0]['payload']['generation'];
    webmention_sender_schedule_targets('http://localhost/source', ['https://target.example/article'], 1001);
    $delivery = webmention_sender_list()[0] ?? null;
    sender_test_assert(is_array($delivery) && $delivery['status'] === 'queued', 'Queued delivery was not stored.');
    sender_test_assert(count(webmention_sender_list(null, 1)) === 1, 'Delivery list limit was not applied.');

    $posted = [];
    $poster = static function (string $endpoint, array $form) use (&$posted): array {
        $posted = ['endpoint' => $endpoint, 'form' => $form];
        return ['ok' => true, 'status' => 202];
    };
    webmention_sender_deliver((int) $delivery['id'], $headerResponse, $poster, 1005, $staleGeneration);
    sender_test_assert($posted === [], 'Superseded delivery job was not ignored.');
    webmention_sender_deliver((int) $delivery['id'], $headerResponse, $poster, 1010);
    $sent = webmention_sender_get((int) $delivery['id']);
    sender_test_assert($sent !== null && $sent['status'] === 'sent' && (int) $sent['last_http_status'] === 202, 'Successful Webmention was not recorded.');
    sender_test_assert($posted['endpoint'] === 'https://target.example/header-endpoint?token=1', 'Discovered endpoint query was not preserved.');
    sender_test_assert($posted['form'] === ['source' => 'http://localhost/source', 'target' => 'https://target.example/article'], 'Webmention form fields were incorrect.');

    webmention_sender_schedule_targets('http://localhost/source', ['https://no-endpoint.example/post'], 1020);
    $unsupported = array_values(array_filter(webmention_sender_list(), static fn(array $item): bool => $item['target_url'] === 'https://no-endpoint.example/post'))[0];
    $noEndpointResponse = static fn(string $url, string $method, array $form, int $limit): array => [
        'ok' => true, 'status' => 200, 'headers' => [], 'content_type' => 'text/html', 'body' => '<p>No endpoint</p>',
    ];
    webmention_sender_deliver((int) $unsupported['id'], $noEndpointResponse, $poster, 1030);
    sender_test_assert(webmention_sender_get((int) $unsupported['id'])['status'] === 'unsupported', 'Missing endpoint was not recorded.');

    webmention_sender_schedule_targets('http://localhost/source', ['https://private-endpoint.example/post'], 1035);
    $privateDelivery = array_values(array_filter(webmention_sender_list(), static fn(array $item): bool => $item['target_url'] === 'https://private-endpoint.example/post'))[0];
    $privateEndpointResponse = static fn(string $url, string $method, array $form, int $limit): array => [
        'ok' => true,
        'status' => 200,
        'headers' => ['link' => '<http://127.0.0.1/webmention>; rel=webmention'],
        'content_type' => 'text/html',
        'body' => '',
    ];
    try {
        webmention_sender_deliver((int) $privateDelivery['id'], $privateEndpointResponse, null, 1036);
        throw new RuntimeException('Private Webmention endpoint was contacted.');
    } catch (RuntimeException) {
    }
    sender_test_assert(webmention_sender_get((int) $privateDelivery['id'])['status'] === 'failed', 'Rejected private endpoint was not recorded as failed.');

    $rescheduled = webmention_sender_schedule_content('post', [
        'title' => 'Source', 'slug' => 'source', 'status' => 'published', 'content' => 'No links remain.',
    ], 1040);
    sender_test_assert(count($rescheduled) === 3, 'Previously notified targets were not rescheduled after link removal.');
    sender_test_assert(count(webmention_sender_schedule_deleted('post', 'source', 1045)) === 3, 'Deleted source did not notify previous targets.');
    sender_test_assert(webmention_sender_retry((int) $unsupported['id'], 1050), 'Manual delivery retry failed.');
    sender_test_assert(webmention_sender_get((int) $unsupported['id'])['status'] === 'queued', 'Manual retry did not reset delivery state.');

    $privateResponse = webmention_sender_safe_request('http://127.0.0.1/webmention');
    sender_test_assert(!($privateResponse['ok'] ?? false) && str_contains((string) $privateResponse['error'], 'not public'), 'Private delivery endpoint was not rejected.');

    echo "PASS: Webmention target extraction, discovery, redirects, delivery, updates, retries, and network boundaries\n";
} finally {
    sender_test_remove_tree($root);
}

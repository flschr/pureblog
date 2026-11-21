<?php

declare(strict_types=1);

require dirname(__DIR__) . '/functions.php';

if (!is_installed() || !indieauth_server_available()) {
    micropub_send_json(['error' => 'temporarily_unavailable'], 503);
}

try {
    $method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method === 'GET') {
        micropub_require_token();
        $query = (string) ($_GET['q'] ?? '');
        if ($query === 'config') {
            micropub_send_json(micropub_config());
        }
        if ($query === 'syndicate-to') {
            micropub_send_json(['syndicate-to' => social_syndication_targets()]);
        }
        if ($query === 'source') {
            micropub_require_token('update');
            $url = trim((string) ($_GET['url'] ?? ''));
            if ($url === '') {
                [$limit, $offset] = micropub_query_window($_GET['limit'] ?? null, $_GET['offset'] ?? null);
                micropub_send_json(micropub_source_list($limit, $offset));
            }
            $post = micropub_post_from_url($url);
            $requested = $_GET['properties'] ?? [];
            $requested = is_array($requested) ? array_values(array_filter($requested, 'is_string')) : [(string) $requested];
            micropub_send_json(micropub_source($post, $requested));
        }
        if ($query === 'category') {
            micropub_require_any_scope(['create', 'update']);
            [$limit, $offset] = micropub_query_window($_GET['limit'] ?? null, $_GET['offset'] ?? null, 100);
            $filter = $_GET['filter'] ?? '';
            if (is_array($filter)) {
                throw new MicropubProtocolException('invalid_request', 'The filter value must be a string.');
            }
            micropub_send_json(['categories' => micropub_categories(trim((string) $filter), $limit, $offset)]);
        }
        throw new MicropubProtocolException('invalid_request', 'The query is not supported.');
    }
    if ($method !== 'POST') {
        header('Allow: GET, POST');
        throw new MicropubProtocolException('invalid_request', 'The Micropub endpoint accepts GET and POST.', 405);
    }

    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    $isJson = str_contains($contentType, 'application/json');
    $input = $_POST;
    if ($isJson) {
        $decoded = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($decoded)) {
            throw new MicropubProtocolException('invalid_request', 'The JSON request body is invalid.');
        }
        $input = $decoded;
    }
    $action = (string) ($input['action'] ?? '');
    if ($action === 'update') {
        if (!$isJson) {
            throw new MicropubProtocolException('invalid_request', 'Update requests must use JSON.');
        }
        micropub_require_token('update');
        $post = micropub_post_from_url((string) ($input['url'] ?? ''));
        micropub_apply_update($post, $input);
        http_response_code(204);
        exit;
    }
    if ($action === 'delete') {
        micropub_require_token('delete');
        $post = micropub_post_from_url((string) ($input['url'] ?? ''));
        if (!delete_post_by_slug((string) $post['slug'])) {
            throw new MicropubProtocolException('invalid_request', 'The post could not be deleted.');
        }
        http_response_code(204);
        exit;
    }
    if ($action !== '') {
        throw new MicropubProtocolException('invalid_request', 'The requested action is not supported.');
    }
    micropub_require_token('create');
    [$properties, $commands] = micropub_parse_create($input, $isJson);
    $post = micropub_create($properties, $commands);
    http_response_code(201);
    header('Location: ' . micropub_post_url($post));
    exit;
} catch (MicropubProtocolException $error) {
    micropub_send_error($error);
} catch (Throwable) {
    micropub_send_json(['error' => 'server_error', 'error_description' => 'The Micropub request could not be completed.'], 500);
}

<?php

declare(strict_types=1);

final class MicropubProtocolException extends RuntimeException
{
    public function __construct(
        public readonly string $micropubError,
        string $message,
        public readonly int $httpStatus = 400,
        public readonly ?string $requiredScope = null
    ) {
        parent::__construct($message);
    }
}

function micropub_endpoint_url(): string
{
    return indieauth_endpoint_url('micropub/index.php');
}

function micropub_media_endpoint_url(): string
{
    return indieauth_endpoint_url('micropub/media.php');
}

function micropub_send_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

function micropub_send_error(MicropubProtocolException $error): never
{
    $payload = ['error' => $error->micropubError, 'error_description' => $error->getMessage()];
    if ($error->requiredScope !== null) {
        $payload['scope'] = $error->requiredScope;
    }
    micropub_send_json($payload, $error->httpStatus);
}

function micropub_require_token(?string $requiredScope = null): array
{
    $token = indieauth_bearer_token();
    if ($token === '') {
        throw new MicropubProtocolException('unauthorized', 'A bearer token is required.', 401);
    }
    $record = indieauth_verify_access_token($token);
    if ($record === null) {
        throw new MicropubProtocolException('unauthorized', 'The bearer token is invalid or revoked.', 401);
    }
    if ($requiredScope !== null && !in_array($requiredScope, (array) $record['scope'], true)) {
        throw new MicropubProtocolException('insufficient_scope', 'The token does not grant the required scope.', 401, $requiredScope);
    }
    return $record;
}

function micropub_require_any_scope(array $requiredScopes): array
{
    $record = micropub_require_token();
    foreach ($requiredScopes as $scope) {
        if (is_string($scope) && in_array($scope, (array) $record['scope'], true)) {
            return $record;
        }
    }
    throw new MicropubProtocolException(
        'insufficient_scope',
        'The token does not grant a required scope.',
        401,
        (string) ($requiredScopes[0] ?? '')
    );
}

function micropub_normalize_properties(array $properties): array
{
    $normalized = [];
    foreach ($properties as $name => $values) {
        if (!is_string($name) || preg_match('/^[a-z][a-z0-9-]*$/', $name) !== 1 || str_starts_with($name, 'mp-')) {
            continue;
        }
        $normalized[$name] = is_array($values) ? array_values($values) : [$values];
    }
    return $normalized;
}

function micropub_parse_create(array $input, bool $json): array
{
    if ($json) {
        $types = (array) ($input['type'] ?? ['h-entry']);
        if (!in_array('h-entry', $types, true) || !is_array($input['properties'] ?? null)) {
            throw new MicropubProtocolException('invalid_request', 'Only h-entry objects are supported.');
        }
        $commands = $input;
        foreach ($input['properties'] as $name => $values) {
            if (!is_array($values)) {
                throw new MicropubProtocolException('invalid_request', 'JSON property values must be arrays.');
            }
            if (is_string($name) && str_starts_with($name, 'mp-')) {
                $commands[$name] = $values[0] ?? '';
            }
        }
        return [micropub_normalize_properties($input['properties']), $commands];
    }
    $type = trim((string) ($input['h'] ?? 'entry'));
    if ($type !== 'entry') {
        throw new MicropubProtocolException('invalid_request', 'Only h-entry objects are supported.');
    }
    $properties = $input;
    foreach (['h', 'access_token', 'action', 'url'] as $reserved) {
        unset($properties[$reserved]);
    }
    foreach (array_keys($properties) as $name) {
        if (str_starts_with((string) $name, 'mp-')) {
            unset($properties[$name]);
        }
    }
    return [micropub_normalize_properties($properties), $input];
}

function micropub_first_string(array $properties, string $name): string
{
    foreach ((array) ($properties[$name] ?? []) as $value) {
        if (is_string($value)) {
            return trim($value);
        }
    }
    return '';
}

function micropub_string_values(array $properties, string $name): array
{
    return array_values(array_filter((array) ($properties[$name] ?? []), 'is_string'));
}

function micropub_content_value(array $properties): array
{
    $value = $properties['content'][0] ?? '';
    if (is_string($value)) {
        return [$value, 'markdown'];
    }
    if (is_array($value) && isset($value['html']) && is_string($value['html'])) {
        return [$value['html'], 'html'];
    }
    return ['', 'markdown'];
}

function micropub_note_title(string $content, string $published): string
{
    $plain = trim(preg_replace('/\s+/', ' ', strip_tags($content)) ?? '');
    if ($plain !== '') {
        return mb_substr($plain, 0, 80);
    }
    return 'Note ' . format_datetime_for_display($published, load_config(), 'Y-m-d H:i');
}

function micropub_supported_property_names(): array
{
    return [
        'name', 'summary', 'content', 'published', 'category', 'photo', 'video', 'audio',
        'in-reply-to', 'like-of', 'repost-of', 'bookmark-of', 'rsvp', 'syndication', 'post-status',
    ];
}

function micropub_supported_post_types(): array
{
    return [
        ['type' => 'article', 'name' => 'Article'],
        ['type' => 'note', 'name' => 'Note'],
        ['type' => 'photo', 'name' => 'Photo'],
        ['type' => 'video', 'name' => 'Video'],
        ['type' => 'reply', 'name' => 'Reply'],
        ['type' => 'like', 'name' => 'Like'],
        ['type' => 'repost', 'name' => 'Repost'],
        ['type' => 'bookmark', 'name' => 'Bookmark'],
        ['type' => 'rsvp', 'name' => 'RSVP'],
    ];
}

function micropub_config(): array
{
    return [
        'media-endpoint' => micropub_media_endpoint_url(),
        'syndicate-to' => social_syndication_targets(),
        'post-types' => micropub_supported_post_types(),
    ];
}

function micropub_query_window(mixed $limit, mixed $offset, int $defaultLimit = 30, int $maximumLimit = 100): array
{
    $parse = static function (mixed $value, string $name, int $default): int {
        if ($value === null || $value === '') return $default;
        if (is_array($value) || filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 0) {
            throw new MicropubProtocolException('invalid_request', 'The ' . $name . ' value must be a non-negative integer.');
        }
        return (int) $value;
    };
    return [min($parse($limit, 'limit', $defaultLimit), $maximumLimit), $parse($offset, 'offset', 0)];
}

function micropub_categories(string $filter = '', int $limit = 100, int $offset = 0, ?array $posts = null): array
{
    $categories = [];
    $posts ??= get_all_posts_meta(true);
    foreach ($posts as $post) {
        foreach ((array) ($post['tags'] ?? []) as $tag) {
            $tag = trim((string) $tag);
            if ($tag === '' || ($filter !== '' && mb_stripos($tag, $filter) === false)) continue;
            $categories[mb_strtolower($tag)] ??= $tag;
        }
    }
    $categories = array_values($categories);
    natcasesort($categories);
    return array_values(array_slice($categories, $offset, $limit));
}

function micropub_properties_to_post(array $properties, array $commands = [], ?array $existing = null): array
{
    [$content, $contentFormat] = micropub_content_value($properties);
    $altContext = trim(micropub_first_string($properties, 'name') . "\n" . strip_tags($content));
    if ($contentFormat === 'html') {
        $content = plus_apply_media_alts_to_html($content, $altContext);
    } elseif ($contentFormat === 'markdown') {
        $content = plus_apply_media_alts_to_markdown($content, $altContext);
    }
    if (isset($properties['photo']) && is_array($properties['photo'])) {
        $properties['photo'] = plus_apply_media_alts_to_photos($properties['photo'], $altContext);
    }
    if (isset($properties['video']) && is_array($properties['video'])) {
        $properties['video'] = plus_apply_media_descriptions_to_videos($properties['video']);
    }
    $published = micropub_first_string($properties, 'published');
    if ($published === '') {
        $published = current_site_datetime_for_storage(load_config());
    } elseif (parse_post_datetime_with_timezone($published, load_config()) === null) {
        throw new MicropubProtocolException('invalid_request', 'The published value is not a valid date.');
    }
    $name = micropub_first_string($properties, 'name');
    $kind = $name === '' ? 'note' : 'article';
    $statusValue = micropub_first_string($properties, 'post-status');
    $statusValue = $statusValue !== '' ? $statusValue : trim((string) ($commands['mp-post-status'] ?? ''));
    if ($statusValue === '' && $existing !== null) {
        $status = (string) ($existing['status'] ?? 'draft');
    } elseif ($statusValue === '') {
        $status = 'published';
    } elseif (in_array($statusValue, ['draft', 'published'], true)) {
        $status = $statusValue;
    } else {
        throw new MicropubProtocolException('invalid_request', 'The post-status value is not supported.');
    }
    $post = [
        'title' => $name !== '' ? $name : micropub_note_title($content, $published),
        'slug' => trim((string) ($commands['mp-slug'] ?? ($existing['slug'] ?? ''))),
        'date' => $published,
        'status' => $status,
        'tags' => array_values(array_unique(micropub_string_values($properties, 'category'))),
        'description' => micropub_first_string($properties, 'summary'),
        'content' => $content,
        'micropub_kind' => $kind,
        'micropub_content_format' => $contentFormat,
    ];
    if ($existing !== null) {
        $post['layout'] = (string) ($existing['layout'] ?? '');
        $post['feature_image'] = (string) ($existing['feature_image'] ?? '');
        $post['layout_fields'] = [];
        $reserved = array_fill_keys([
            'title', 'slug', 'date', 'timestamp', 'status', 'tags', 'description', 'content', 'path',
            'layout', 'feature_image', 'categories', 'micropub_kind', 'micropub_content_format',
            'social_announce',
            'micropub_photo', 'micropub_video', 'micropub_audio', 'micropub_in_reply_to',
            'micropub_like_of', 'micropub_repost_of', 'micropub_bookmark_of', 'micropub_rsvp',
            'micropub_syndication',
        ], true);
        foreach ($existing as $fieldName => $fieldValue) {
            if (is_string($fieldName) && !isset($reserved[$fieldName]) && is_scalar($fieldValue)) {
                $post['layout_fields'][$fieldName] = (string) $fieldValue;
            }
        }
        $post['social_announce'] = !empty($existing['social_announce']);
    }
    $requestedSyndication = $commands['mp-syndicate-to'] ?? [];
    $requestedSyndication = is_array($requestedSyndication) ? $requestedSyndication : [$requestedSyndication];
    foreach (social_syndication_targets() as $target) {
        if (in_array((string) $target['uid'], $requestedSyndication, true)) {
            $post['social_announce'] = true;
        }
    }
    if ($post['slug'] === '') {
        $post['slug'] = $kind === 'note'
            ? 'note-' . format_datetime_for_display($published, load_config(), 'Ymd-His')
            : $post['title'];
    }
    foreach (['photo', 'video', 'audio', 'in-reply-to', 'like-of', 'repost-of', 'bookmark-of', 'rsvp', 'syndication'] as $name) {
        $values = (array) ($properties[$name] ?? []);
        if ($values !== []) {
            $post['micropub_' . str_replace('-', '_', $name)] = json_encode($values, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
    }
    return $post;
}

function micropub_post_url(array $post): string
{
    return indieauth_base_url() . '/' . rawurlencode((string) $post['slug']);
}

function micropub_create(array $properties, array $commands = []): array
{
    $post = micropub_properties_to_post($properties, $commands);
    $error = null;
    if (!save_post($post, null, null, null, $error)) {
        throw new MicropubProtocolException('invalid_request', $error ?: 'The post could not be saved.');
    }
    return $post;
}

function micropub_post_from_url(string $url): array
{
    $parts = parse_url($url);
    $base = parse_url(indieauth_base_url());
    if (!is_array($parts) || !is_array($base)
        || indieauth_url_origin($url) !== indieauth_url_origin(indieauth_base_url())
        || isset($parts['query']) || isset($parts['fragment'])) {
        throw new MicropubProtocolException('invalid_request', 'The post URL does not belong to this site.');
    }
    $basePath = rtrim((string) ($base['path'] ?? ''), '/');
    $path = rawurldecode((string) ($parts['path'] ?? ''));
    if ($basePath !== '' && str_starts_with($path, $basePath . '/')) {
        $path = substr($path, strlen($basePath));
    }
    $slug = trim($path, '/');
    if ($slug === '' || str_contains($slug, '/')) {
        throw new MicropubProtocolException('invalid_request', 'The post URL is invalid.');
    }
    $post = get_post_by_slug($slug, true);
    if ($post === null) {
        throw new MicropubProtocolException('invalid_request', 'The requested post was not found.');
    }
    return $post;
}

function micropub_post_to_properties(array $post): array
{
    $content = (string) ($post['content'] ?? '');
    if (str_ends_with($content, "\n")) {
        $content = substr($content, 0, -1);
    }
    $properties = [
        'url' => [micropub_post_url($post)],
        'content' => ($post['micropub_content_format'] ?? '') === 'html'
            ? [['html' => $content]]
            : [$content],
        'published' => [(string) ($post['date'] ?? '')],
        'category' => array_values((array) ($post['tags'] ?? [])),
        'post-status' => [($post['status'] ?? 'draft') === 'published' ? 'published' : 'draft'],
    ];
    if (($post['micropub_kind'] ?? 'article') !== 'note') {
        $properties['name'] = [(string) ($post['title'] ?? '')];
    }
    if (trim((string) ($post['description'] ?? '')) !== '') {
        $properties['summary'] = [(string) $post['description']];
    }
    foreach (['photo', 'video', 'audio', 'in-reply-to', 'like-of', 'repost-of', 'bookmark-of', 'rsvp', 'syndication'] as $name) {
        $key = 'micropub_' . str_replace('-', '_', $name);
        if (!empty($post[$key])) {
            $decoded = json_decode((string) $post[$key], true);
            if (is_array($decoded)) {
                $properties[$name] = array_values($decoded);
            }
        }
    }
    return $properties;
}

function micropub_source_list(int $limit = 30, int $offset = 0, ?array $posts = null): array
{
    $items = [];
    $posts ??= get_all_posts(true);
    foreach (array_slice($posts, $offset, $limit) as $post) {
        $items[] = ['type' => ['h-entry'], 'properties' => micropub_post_to_properties($post)];
    }
    return ['items' => $items];
}

function micropub_source(array $post, array $requested = []): array
{
    $properties = micropub_post_to_properties($post);
    if ($requested !== []) {
        $properties = array_intersect_key($properties, array_fill_keys($requested, true));
        return ['properties' => $properties];
    }
    return ['type' => ['h-entry'], 'properties' => $properties];
}

function micropub_apply_update(array $post, array $request): array
{
    if (!array_key_exists('replace', $request)
        && !array_key_exists('add', $request)
        && !array_key_exists('delete', $request)) {
        throw new MicropubProtocolException('invalid_request', 'An update operation is required.');
    }
    if (array_key_exists('delete', $request) && !is_array($request['delete'])) {
        throw new MicropubProtocolException('invalid_request', 'Delete values must be an array or object.');
    }
    $properties = micropub_post_to_properties($post);
    $statusTouched = array_key_exists('post-status', (array) ($request['replace'] ?? []))
        || array_key_exists('post-status', (array) ($request['add'] ?? []));
    foreach ((array) ($request['replace'] ?? []) as $name => $values) {
        if (!is_array($values)) {
            throw new MicropubProtocolException('invalid_request', 'Replace values must be arrays.');
        }
        if (in_array($name, micropub_supported_property_names(), true)) {
            $properties[$name] = array_values($values);
        }
    }
    foreach ((array) ($request['add'] ?? []) as $name => $values) {
        if (!is_array($values)) {
            throw new MicropubProtocolException('invalid_request', 'Add values must be arrays.');
        }
        if (in_array($name, micropub_supported_property_names(), true)) {
            $properties[$name] = array_values(array_merge((array) ($properties[$name] ?? []), $values));
        }
    }
    $delete = $request['delete'] ?? [];
    if (is_array($delete)) {
        foreach ($delete as $name => $values) {
            if (is_int($name) && is_string($values)) {
                $statusTouched = $statusTouched || $values === 'post-status';
                unset($properties[$values]);
            } elseif (is_string($name) && is_array($values) && isset($properties[$name])) {
                $statusTouched = $statusTouched || $name === 'post-status';
                $properties[$name] = array_values(array_filter(
                    (array) $properties[$name],
                    static fn(mixed $value): bool => !in_array($value, $values, true)
                ));
                if ($properties[$name] === []) {
                    unset($properties[$name]);
                }
            }
        }
    }
    $updated = micropub_properties_to_post($properties, ['mp-slug' => (string) $post['slug']], $post);
    if (($post['status'] ?? '') === 'scheduled' && !$statusTouched) {
        $updated['status'] = 'scheduled';
    }
    foreach ($post as $key => $value) {
        if (str_starts_with((string) $key, 'micropub_') && !array_key_exists($key, $updated)) {
            $updated[$key] = '';
        }
    }
    $error = null;
    if (!save_post($updated, (string) $post['slug'], (string) $post['date'], (string) $post['status'], $error)) {
        throw new MicropubProtocolException('invalid_request', $error ?: 'The post could not be updated.');
    }
    return $updated;
}

plus_register_capability('micropub.server', 1, 0);

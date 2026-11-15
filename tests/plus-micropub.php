<?php

declare(strict_types=1);

function micropub_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function micropub_test_throws(callable $operation, string $errorName): void
{
    try {
        $operation();
    } catch (MicropubProtocolException $error) {
        micropub_test_assert($error->micropubError === $errorName, 'Unexpected Micropub error.');
        return;
    }
    throw new RuntimeException('Expected Micropub error: ' . $errorName);
}

$root = sys_get_temp_dir() . '/pureblog-plus-micropub-' . bin2hex(random_bytes(8));
define('PUREBLOG_PLUS_DATA_PATH', $root . '/plus');

try {
    require dirname(__DIR__) . '/functions.php';

    micropub_test_assert(plus_has_capability('micropub.server', 1, 0), 'Micropub capability missing.');
    micropub_test_assert(!is_dir(PUREBLOG_PLUS_DATA_PATH), 'Micropub initialized storage eagerly.');

    [$formProperties, $formCommands] = micropub_parse_create([
        'h' => 'entry',
        'content' => 'Hello world',
        'category' => ['one', 'two'],
        'mp-slug' => 'hello-world',
        'access_token' => 'secret',
    ], false);
    micropub_test_assert($formProperties['content'] === ['Hello world'], 'Form content was not normalized.');
    micropub_test_assert($formProperties['category'] === ['one', 'two'], 'Repeated form values were lost.');
    micropub_test_assert(!isset($formProperties['access_token'], $formProperties['mp-slug']), 'Reserved form values became post properties.');
    micropub_test_assert($formCommands['mp-slug'] === 'hello-world', 'Form command was lost.');

    [$jsonProperties, $jsonCommands] = micropub_parse_create([
        'type' => ['h-entry'],
        'properties' => [
            'name' => ['An article'],
            'content' => [['html' => '<p>Body</p>']],
            'category' => ['alpha'],
            'mp-slug' => ['custom-slug'],
        ],
    ], true);
    micropub_test_assert($jsonCommands['mp-slug'] === 'custom-slug', 'JSON command was lost.');
    $article = micropub_properties_to_post($jsonProperties, $jsonCommands);
    micropub_test_assert($article['title'] === 'An article' && $article['slug'] === 'custom-slug', 'Article mapping failed.');
    micropub_test_assert($article['micropub_content_format'] === 'html', 'HTML content format was lost.');

    $note = micropub_properties_to_post([
        'content' => ['A short untitled note'],
        'category' => ['notes'],
        'photo' => [['value' => 'https://example.test/photo.jpg', 'alt' => 'Example']],
    ]);
    micropub_test_assert($note['micropub_kind'] === 'note', 'Untitled entry was not mapped as a note.');
    micropub_test_assert(str_starts_with($note['slug'], 'note-'), 'Note did not receive a stable generated slug.');
    $source = micropub_source($note);
    micropub_test_assert(!isset($source['properties']['name']), 'Generated note title leaked into source properties.');
    micropub_test_assert($source['properties']['content'] === ['A short untitled note'], 'Note content changed in source query.');
    micropub_test_assert($source['properties']['photo'][0]['alt'] === 'Example', 'Nested photo metadata was lost.');
    $mediaHtml = render_post_media($note);
    micropub_test_assert(str_contains($mediaHtml, 'alt="Example"'), 'Photo accessibility text was not rendered.');

    $nativePost = [
        'title' => 'Native Markdown', 'slug' => 'native-markdown', 'date' => '2026-11-15 10:00',
        'status' => 'published', 'tags' => [], 'description' => '', 'content' => '**Markdown**',
        'layout' => 'essay', 'feature_image' => '/content/images/native/cover.jpg', 'custom_byline' => 'Rene',
    ];
    $nativeMapped = micropub_properties_to_post(micropub_post_to_properties($nativePost), [], $nativePost);
    micropub_test_assert($nativeMapped['micropub_content_format'] === 'markdown', 'Native Markdown format was not preserved.');
    micropub_test_assert($nativeMapped['layout'] === 'essay' && $nativeMapped['feature_image'] !== '', 'Native presentation fields were not preserved.');
    micropub_test_assert($nativeMapped['layout_fields']['custom_byline'] === 'Rene', 'Custom front matter was not preserved.');

    $draftPost = array_replace($nativePost, ['status' => 'draft']);
    $draftProperties = micropub_post_to_properties($draftPost);
    unset($draftProperties['post-status']);
    $draftMapped = micropub_properties_to_post($draftProperties, [], $draftPost);
    micropub_test_assert($draftMapped['status'] === 'draft', 'Removing post-status unexpectedly published a draft.');
    micropub_test_throws(static function (): void {
        micropub_properties_to_post(['content' => ['Do not publish'], 'post-status' => ['unexpected']]);
    }, 'invalid_request');

    micropub_test_throws(static function (): void {
        micropub_parse_create(['type' => ['h-entry'], 'properties' => ['content' => 'not-an-array']], true);
    }, 'invalid_request');
    micropub_test_throws(static function (): void {
        micropub_parse_create(['h' => 'event', 'content' => 'No'], false);
    }, 'invalid_request');
    micropub_test_throws(static function () use ($nativePost): void {
        micropub_apply_update($nativePost, ['action' => 'update']);
    }, 'invalid_request');

    $verifier = str_repeat('A', 43);
    $challenge = indieauth_base64url(hash('sha256', $verifier, true));
    $code = indieauth_issue_authorization_code(
        'https://client.example/app',
        'https://client.example/callback',
        indieauth_canonical_me(),
        ['create'],
        $challenge
    );
    $tokenResponse = indieauth_exchange_authorization_code(
        $code,
        'https://client.example/app',
        'https://client.example/callback',
        $verifier,
        true
    );
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $tokenResponse['access_token'];
    micropub_test_assert(micropub_require_token('create')['client_id'] === 'https://client.example/app', 'Create scope was rejected.');
    micropub_test_throws(static fn(): array => micropub_require_token('update'), 'insufficient_scope');
    unset($_SERVER['HTTP_AUTHORIZATION']);

    echo "PASS: Micropub parsing, content mapping, source properties, commands, tokens, and scope boundaries\n";
} finally {
    if (is_dir($root)) {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($root);
    }
}

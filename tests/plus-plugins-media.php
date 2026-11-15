<?php

declare(strict_types=1);

function plugin_media_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function plugin_media_remove_tree(string $path): void
{
    if (!is_dir($path)) return;
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    rmdir($path);
}

function on_prepare_media_upload(array $media): array
{
    if (($media['name'] ?? '') !== 'prepared.heic') return $media;
    $prepared = dirname((string) $media['path']) . '/prepared.png';
    $image = imagecreatetruecolor(64, 48);
    imagepng($image, $prepared);
    unset($image);
    return ['path' => $prepared, 'temporary' => true];
}

$root = sys_get_temp_dir() . '/pureblog-plus-media-' . bin2hex(random_bytes(8));
define('PUREBLOG_PLUS_DATA_PATH', $root . '/data');
define('PUREBLOG_MEDIA_PATH', $root . '/public-media');

try {
    require dirname(__DIR__) . '/functions.php';

    plugin_media_assert(plus_has_capability('plugins.bundled', 1, 0), 'Plugin capability missing.');
    plugin_media_assert(plus_has_capability('media.pipeline', 1, 0), 'Media capability missing.');
    plugin_media_assert(!is_dir(PUREBLOG_PLUS_DATA_PATH), 'Plugin discovery initialized storage eagerly.');
    $plugins = plus_plugins();
    plugin_media_assert(array_keys($plugins) === ['local-media', 's3-media', 'openai-alt-text'], 'Unexpected bundled plugin set.');
    plugin_media_assert(plus_plugin_provider('media.storage')['id'] === 'local-media', 'Local media is not the default.');
    plugin_media_assert(plus_enabled_plugin_ids(['plugins' => ['enabled' => []]]) === ['local-media'], 'Local fallback can be disabled.');
    $prepared = plus_prepare_plugin_configuration(default_config(), [
        'enabled' => ['s3-media'],
        'provider' => ['media.storage' => 's3-media', 'media.alt_suggestion' => ''],
        'settings' => ['s3-media' => [
            'endpoint' => 'https://s3.example.test', 'region' => 'test', 'bucket' => 'media',
            'access_key' => 'key', 'secret_key' => 'secret', 'public_base_url' => 'https://media.example.test',
        ]],
    ]);
    plugin_media_assert($prepared['errors'] === [], 'Valid plugin settings were rejected.');
    plugin_media_assert($prepared['plugins']['enabled'] === ['s3-media', 'local-media'], 'Selected provider was not enabled with fallback.');
    $configured = default_config();
    $configured['plugins'] = $prepared['plugins'];
    plugin_media_assert(plus_plugin_provider('media.storage', null, $configured)['id'] === 's3-media', 'Configured storage provider was ignored.');
    $signed = plus_s3_signature_headers(
        'PUT', '/bucket/media/test.txt', 'abc', 'text/plain', 'example.com:8446',
        'test', 'access', 'secret', '20261115T120000Z'
    );
    plugin_media_assert(
        str_ends_with($signed['authorization'], 'Signature=870056166f61a9146a2c923f83330499bbb0ce947c809a7300cac266fa3735e5'),
        'S3 Signature Version 4 contract changed.'
    );
    $invalidPrepared = plus_prepare_plugin_configuration(default_config(), [
        'provider' => ['media.storage' => 's3-media', 'media.alt_suggestion' => ''],
        'settings' => ['s3-media' => ['endpoint' => 'http://private.test']],
    ]);
    plugin_media_assert(count($invalidPrepared['errors']) > 0, 'Incomplete or insecure provider settings were accepted.');
    try {
        plus_register_plugin(['id' => '../remote', 'name' => 'Remote', 'version' => '1.0.0', 'api_version' => 1]);
        throw new RuntimeException('Unsafe plugin identifier was accepted.');
    } catch (InvalidArgumentException) {
    }

    if (!extension_loaded('gd')) throw new RuntimeException('GD is required for this media contract test.');
    $upload = $root . '/upload.png';
    mkdir($root, 0700, true);
    $image = imagecreatetruecolor(1600, 900);
    $background = imagecolorallocate($image, 36, 90, 140);
    imagefill($image, 0, 0, $background);
    imagepng($image, $upload);
    unset($image);

    $media = plus_store_uploaded_media([
        'name' => 'Example image.png', 'tmp_name' => $upload, 'error' => UPLOAD_ERR_OK, 'size' => filesize($upload),
    ], false);
    plugin_media_assert($media['provider'] === 'local-media', 'Upload bypassed configured provider.');
    plugin_media_assert($media['width'] === 1600 && $media['height'] === 900, 'Image dimensions were lost.');
    plugin_media_assert(array_keys($media['variants']) === [360, 720, 1440], 'Responsive variants are incomplete.');
    plugin_media_assert(is_file((string) plus_media_by_url($media['url'])['local_source']), 'Private retained source is missing.');
    plus_set_media_default_alt($media['id'], "  A reusable blue image\n description.  ");
    $storedMedia = plus_media_by_url($media['url']);
    plugin_media_assert($storedMedia['default_alt'] === 'A reusable blue image description.', 'Default media description was not normalized and stored.');
    $missingAlt = plus_missing_media_alt_suggestions('Before ![](' . $media['url'] . ') after');
    plugin_media_assert(count($missingAlt) === 1 && $missingAlt[0]['alt'] === $storedMedia['default_alt'], 'Missing contextual alt text did not expose the stored suggestion.');
    plugin_media_assert(plus_missing_media_alt_suggestions('![Own description](' . $media['url'] . ')') === [], 'Authored contextual alt text was offered for replacement.');
    $filledMarkdown = plus_apply_media_alts_to_markdown('Before ![](' . $media['url'] . ') after');
    plugin_media_assert(str_contains($filledMarkdown, '![A reusable blue image description.]'), 'Empty Markdown alt text was not filled.');
    $authoredMarkdown = '![Author choice](' . $media['url'] . ')';
    plugin_media_assert(plus_apply_media_alts_to_markdown($authoredMarkdown) === $authoredMarkdown, 'Authored Markdown alt text was replaced.');
    $filledHtml = plus_apply_media_alts_to_html('<p><img src="' . $media['url'] . '" alt=""></p>');
    plugin_media_assert(str_contains($filledHtml, 'alt="A reusable blue image description."'), 'Empty HTML alt text was not filled.');
    $authoredHtml = '<img src="' . $media['url'] . '" alt="Author choice">';
    plugin_media_assert(plus_apply_media_alts_to_html($authoredHtml) === $authoredHtml, 'Authored HTML alt text was replaced.');
    $filledPhotos = plus_apply_media_alts_to_photos([['value' => $media['url'], 'alt' => ''], $media['url']]);
    plugin_media_assert($filledPhotos[0]['alt'] === $storedMedia['default_alt'] && $filledPhotos[1]['alt'] === $storedMedia['default_alt'], 'Structured Micropub photos did not receive default alt text.');
    $authoredPhotos = plus_apply_media_alts_to_photos([['value' => $media['url'], 'alt' => 'Author choice']]);
    plugin_media_assert($authoredPhotos[0]['alt'] === 'Author choice', 'Authored structured photo alt text was replaced.');
    $publicVariantPaths = [];
    foreach ($media['variants'] as $variantUrl) {
        $path = parse_url($variantUrl, PHP_URL_PATH);
        $publicVariantPaths[] = PUREBLOG_MEDIA_PATH . substr($path, strlen('/content/media'));
        plugin_media_assert(is_file($publicVariantPaths[array_key_last($publicVariantPaths)]), 'Public variant is missing.');
    }
    $html = render_markdown('![Blue test image](' . $media['url'] . ')');
    plugin_media_assert(str_contains($html, 'alt="Blue test image"'), 'Authored alt text changed.');
    plugin_media_assert(str_contains($html, 'srcset='), 'Responsive sources were not rendered.');
    plugin_media_assert(str_contains($html, 'width="1600" height="900"'), 'Intrinsic dimensions were not rendered.');
    $references = $root . '/references';
    mkdir($references);
    file_put_contents($references . '/post.md', "---\ntitle: Test\n---\n\n![Alt](" . $media['url'] . ")\n");
    plugin_media_assert(plus_media_is_referenced($media['url'], [$references]), 'Media reference was not detected.');

    $preparedInput = $root . '/prepared.heic';
    file_put_contents($preparedInput, 'synthetic unsupported input');
    $preparedMedia = plus_store_uploaded_media([
        'name' => 'prepared.heic', 'tmp_name' => $preparedInput, 'error' => UPLOAD_ERR_OK,
    ], false);
    plugin_media_assert($preparedMedia['mime_type'] === 'image/png', 'Prepared upload did not re-enter MIME validation.');
    plugin_media_assert(is_file($preparedInput), 'Preparing an upload unexpectedly removed the source.');
    plugin_media_assert(plus_delete_media($preparedMedia['id']), 'Prepared media could not be deleted.');
    $editorScript = file_get_contents(dirname(__DIR__) . '/admin/js/editor.js');
    plugin_media_assert(is_string($editorScript) && str_contains($editorScript, 'heic|heif'), 'Prepared image extensions are not accepted by editor drop handling.');

    $unsupported = $root . '/upload.txt';
    file_put_contents($unsupported, 'not media');
    try {
        plus_store_uploaded_media(['name' => 'bad.txt', 'tmp_name' => $unsupported, 'error' => UPLOAD_ERR_OK], false);
        throw new RuntimeException('Unsupported media was accepted.');
    } catch (InvalidArgumentException) {
    }
    plugin_media_assert(is_file($unsupported), 'Rejected upload was moved into media storage.');

    plugin_media_assert(plus_delete_media($media['id']), 'Managed media could not be deleted.');
    plugin_media_assert(plus_media_by_url($media['url']) === null, 'Deleted media record remains.');
    foreach ($publicVariantPaths as $path) plugin_media_assert(!is_file($path), 'Deleted media variant remains.');

    echo "PASS: bundled plugin trust, local provider, shared media storage, automatic missing alt text, prepared formats, responsive variants, authored alt text and deletion\n";
} finally {
    plugin_media_remove_tree($root);
}

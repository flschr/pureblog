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

function on_media_updated(string $id): void
{
    $GLOBALS['_pureblog_test_updated_media'][] = $id;
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
    plugin_media_assert(array_keys($plugins) === ['local-media', 's3-media', 'openai-alt-text', 'personal-photo-gallery'], 'Unexpected bundled plugin set.');
    plugin_media_assert(isset($plugins['openai-alt-text']['capabilities']['media.photo_classification']), 'Bundled photo classifier is missing.');
    plugin_media_assert(isset($plugins['personal-photo-gallery']['capabilities']['site.route']), 'Gallery route capability is missing.');
    plugin_media_assert(count($plugins['personal-photo-gallery']['admin_pages'] ?? []) === 1, 'Gallery admin page contract is missing.');
    plugin_media_assert(!plus_plugin_is_enabled('personal-photo-gallery'), 'Personal gallery must be opt-in.');
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
    $s3PluginSource = file_get_contents(dirname(__DIR__) . '/includes/plugins/s3-media.php');
    plugin_media_assert(is_string($s3PluginSource) && !str_contains($s3PluginSource, 'file_get_contents((string) $file[\'path\'])'), 'S3 uploads still buffer complete media files.');
    $altPluginSource = file_get_contents(dirname(__DIR__) . '/includes/plugins/openai-alt-text.php');
    plugin_media_assert(is_string($altPluginSource) && !str_contains($altPluginSource, 'base64_encode'), 'Alt-text requests still duplicate complete images in memory.');
    plugin_media_assert(str_contains($altPluginSource, "'store' => false"), 'OpenAI media requests can retain gallery inputs.');
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
    plugin_media_assert((string) $storedMedia['source_fingerprint'] === '', 'The upload path eagerly hashed media for an optional gallery projection.');
    plugin_media_assert(in_array((int) $storedMedia['capture_metadata'], [0, 1], true) && in_array((int) $storedMedia['capture_time'], [0, 1], true), 'Privacy-minimised capture evidence is invalid.');
    plugin_media_assert($storedMedia['default_alt'] === 'A reusable blue image description.', 'Default media description was not normalized and stored.');
    $missingAlt = plus_missing_media_alt_suggestions('Before ![](' . $media['url'] . ') after');
    plugin_media_assert(count($missingAlt) === 1 && $missingAlt[0]['alt'] === $storedMedia['default_alt'], 'Missing contextual alt text did not expose the stored suggestion.');
    plugin_media_assert(plus_missing_media_alt_suggestions('![Own description](' . $media['url'] . ')') === [], 'Authored contextual alt text was offered for replacement.');
    $filledMarkdown = plus_apply_media_alts_to_markdown('Before ![](' . $media['url'] . ') after');
    plugin_media_assert(str_contains($filledMarkdown, '![A reusable blue image description.]'), 'Empty Markdown alt text was not filled.');
    $authoredMarkdown = '![Author choice](' . $media['url'] . ')';
    plugin_media_assert(plus_apply_media_alts_to_markdown($authoredMarkdown) === $authoredMarkdown, 'Authored Markdown alt text was replaced.');
    $micropubPost = micropub_properties_to_post(['content' => ['Before ![](' . $media['url'] . ') and ![Author choice](' . $media['url'] . ')']]);
    plugin_media_assert(str_contains($micropubPost['content'], '![A reusable blue image description.]'), 'New Micropub Markdown did not receive the default alt text.');
    plugin_media_assert(str_contains($micropubPost['content'], $authoredMarkdown), 'New Micropub Markdown replaced authored alt text.');
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
    plugin_media_assert(is_string($editorScript) && str_contains($editorScript, 'uploadAccepts(file)'), 'Editor drop handling does not reuse the picker media policy.');
    $editorTemplate = file_get_contents(dirname(__DIR__) . '/admin/partials/editor-media.php');
    plugin_media_assert(is_string($editorTemplate) && str_contains($editorTemplate, 'plus_editor_visual_media_accept()'), 'Editor media picker does not use the shared media policy.');
    $editorAccept = plus_editor_visual_media_accept();
    plugin_media_assert(str_contains($editorAccept, 'video/quicktime') && str_contains($editorAccept, '.mov') && str_contains($editorAccept, '.heic'), 'Shared editor media policy omits prepared images or QuickTime video.');
    foreach (['edit-post.php', 'edit-page.php'] as $editorFile) {
        $editorSource = file_get_contents(dirname(__DIR__) . '/admin/' . $editorFile);
        plugin_media_assert(is_string($editorSource) && str_contains($editorSource, "partials/editor-media.php"), $editorFile . ' does not use the shared media owner.');
    }
    plugin_media_assert(str_contains($editorScript, 'li[data-media-kind="image"]'), 'Video entries can leak into the image gallery picker.');
    $uploadSource = file_get_contents(dirname(__DIR__) . '/admin/upload-image.php');
    plugin_media_assert(is_string($uploadSource) && str_contains($uploadSource, "null, 'visual'"), 'Admin upload remains restricted to images.');

    $videoUpload = $root . '/feeding.mov';
    file_put_contents($videoUpload, pack('N', 20) . 'ftypqt  ' . str_repeat("\0", 8));
    $video = plus_store_uploaded_media([
        'name' => 'feeding.mov', 'tmp_name' => $videoUpload, 'error' => UPLOAD_ERR_OK,
    ], false, null, 'visual');
    plugin_media_assert($video['mime_type'] === 'video/quicktime', 'QuickTime upload did not retain its detected media type.');
    plugin_media_assert(str_contains(plus_media_editor_markup(plus_media_by_id($video['id'])), '<video controls playsinline preload="metadata"'), 'Video upload did not produce editor video markup.');
    $GLOBALS['_pureblog_test_updated_media'] = [];
    plus_update_media_default_alt($video['id'], 'Fish feeding between aquarium rocks.');
    plugin_media_assert($GLOBALS['_pureblog_test_updated_media'] === [$video['id']], 'Editable media description did not publish its invalidation hook.');
    $outpostPost = micropub_properties_to_post([
        'content' => ['Dinner'], 'video' => [$video['url']],
    ]);
    $outpostVideo = json_decode((string) $outpostPost['micropub_video'], true);
    plugin_media_assert(($outpostVideo[0]['alt'] ?? '') === 'Fish feeding between aquarium rocks.', 'Micropub video did not inherit its managed media description.');
    plugin_media_assert(str_contains(render_post_media($outpostPost), 'aria-label="Fish feeding between aquarium rocks."'), 'Micropub video description was not rendered accessibly.');
    $referencedVideo = plus_managed_media_editor_items($outpostPost);
    plugin_media_assert(count($referencedVideo) === 1 && $referencedVideo[0]['media_id'] === $video['id'], 'Micropub video was not discoverable in the post editor.');
    $inlineVideo = plus_enhance_media_html('<video controls src="' . $video['url'] . '"></video>');
    plugin_media_assert(str_contains($inlineVideo, 'aria-label="Fish feeding between aquarium rocks."'), 'Inline managed video did not receive its stored description.');

    $unsupported = $root . '/upload.txt';
    file_put_contents($unsupported, 'not media');
    try {
        plus_store_uploaded_media(['name' => 'bad.txt', 'tmp_name' => $unsupported, 'error' => UPLOAD_ERR_OK], false);
        throw new RuntimeException('Unsupported media was accepted.');
    } catch (InvalidArgumentException) {
    }
    plugin_media_assert(is_file($unsupported), 'Rejected upload was moved into media storage.');

    $deleteAttempts = 0;
    $GLOBALS['_pureblog_test_delete_handler'] = static function () use (&$deleteAttempts): void {
        $deleteAttempts++;
        if ($deleteAttempts === 1) throw new RuntimeException('Synthetic provider delete failure.');
    };
    plus_register_plugin([
        'id' => 'test-delete', 'name' => 'Test delete', 'version' => '1.0.0',
        'api_version' => PUREBLOG_PLUGIN_API_VERSION,
        'capabilities' => ['media.delete' => $GLOBALS['_pureblog_test_delete_handler']], 'settings' => [],
    ]);
    $readOnlyId = bin2hex(random_bytes(16));
    plus_database()->prepare(
        'INSERT INTO plus_media (id,provider_id,url,mime_type,original_name,variants_json,provider_data_json,local_source,created_at) '
        . 'VALUES (?,?,?,?,?,?,?,?,?)'
    )->execute([$readOnlyId, 'test-delete', 'https://media.example.test/archive.jpg', 'image/jpeg', 'archive.jpg', '{}', '{"read_only":true}', '', time()]);
    $readOnlyMedia = plus_media_by_id($readOnlyId);
    plugin_media_assert(is_array($readOnlyMedia) && !plus_media_can_delete($readOnlyMedia), 'Read-only media was exposed as deletable.');
    try {
        plus_delete_media($readOnlyId);
        throw new RuntimeException('Read-only media deletion was accepted.');
    } catch (RuntimeException $error) {
        plugin_media_assert(str_contains($error->getMessage(), 'Read-only'), 'Read-only media returned the wrong deletion error.');
    }
    plugin_media_assert(plus_media_by_id($readOnlyId)['delete_state'] === 'active' && $deleteAttempts === 0,
        'A read-only deletion attempt mutated media state or reached its provider.');
    plus_database()->prepare('DELETE FROM plus_media WHERE id=?')->execute([$readOnlyId]);
    $retrySource = $root . '/retry-source.jpg';
    file_put_contents($retrySource, 'retry');
    $retryId = bin2hex(random_bytes(16));
    plus_database()->prepare(
        'INSERT INTO plus_media (id,provider_id,url,mime_type,original_name,variants_json,provider_data_json,local_source,created_at) '
        . 'VALUES (?,?,?,?,?,?,?,?,?)'
    )->execute([$retryId, 'test-delete', 'https://media.example.test/retry.jpg', 'image/jpeg', 'retry.jpg', '{}', '{}', $retrySource, time()]);
    try {
        plus_delete_media($retryId);
        throw new RuntimeException('Synthetic provider deletion did not fail.');
    } catch (RuntimeException $error) {
        plugin_media_assert(str_contains($error->getMessage(), 'Synthetic'), 'Unexpected provider deletion error.');
    }
    plugin_media_assert(plus_media_by_id($retryId)['delete_state'] === 'deleting', 'Failed deletion was not retained as retryable state.');
    plugin_media_assert(plus_delete_media($retryId), 'Retryable media deletion did not complete.');
    unset($GLOBALS['_pureblog_test_delete_handler']);

    plugin_media_assert(plus_delete_media($media['id']), 'Managed media could not be deleted.');
    plugin_media_assert(plus_media_by_url($media['url']) === null, 'Deleted media record remains.');
    foreach ($publicVariantPaths as $path) plugin_media_assert(!is_file($path), 'Deleted media variant remains.');
    plugin_media_assert(plus_delete_media($video['id']), 'Managed video could not be deleted.');

    echo "PASS: bundled plugin trust, local provider, shared media storage, automatic missing alt text, prepared formats, responsive variants, authored alt text and deletion\n";
} finally {
    plugin_media_remove_tree($root);
}

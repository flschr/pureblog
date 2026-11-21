<?php

declare(strict_types=1);

function photo_gallery_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function photo_gallery_remove_tree(string $path): void
{
    if (!is_dir($path)) return;
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    rmdir($path);
}

$root = sys_get_temp_dir() . '/pureblog-photo-gallery-' . bin2hex(random_bytes(8));
define('PUREBLOG_PLUS_DATA_PATH', $root . '/data');
define('PUREBLOG_MEDIA_PATH', $root . '/public-media');

try {
    require dirname(__DIR__) . '/functions.php';

    photo_gallery_assert(plus_has_capability('photo.gallery', 1, 0), 'Gallery capability is missing.');
    $defaultConfig = default_config();
    photo_gallery_assert(!personal_photo_gallery_enabled($defaultConfig), 'Gallery must be disabled by default.');
    $_GET['partial'] = '1';
    photo_gallery_assert(cache_should_bypass(['cache' => ['enabled' => true]]), 'JSON pagination can be corrupted by the HTML page cache.');
    unset($_GET['partial']);

    $candidates = personal_photo_gallery_post_candidates([
        'title' => 'Review',
        'feature_image' => 'https://media.example.test/feature.jpg',
        'micropub_photo' => json_encode([
            ['value' => 'https://media.example.test/micropub.jpg', 'alt' => 'Micropub photo'],
        ], JSON_THROW_ON_ERROR),
        'content' => "![Cover](https://media.example.test/cover.jpg \"Book cover\")\n"
            . '<p><img src="https://media.example.test/inline.jpg" alt="Inline photo"></p>'
            . '<iframe src="https://video.example.test/embed"><img src="invalid"></iframe>',
    ]);
    photo_gallery_assert(array_column($candidates, 'url') === [
        'https://media.example.test/feature.jpg',
        'https://media.example.test/micropub.jpg',
        'https://media.example.test/cover.jpg',
        'https://media.example.test/inline.jpg',
    ], 'Canonical post image discovery changed.');

    $classes = [];
    plus_register_plugin([
        'id' => 'test-photo-classifier',
        'name' => 'Test photo classifier',
        'version' => '1.0.0',
        'api_version' => PUREBLOG_PLUGIN_API_VERSION,
        'capabilities' => [
            'media.photo_classification' => static function (array $media) use (&$classes): array {
                $id = (string) $media['id'];
                $result = array_key_exists($id, $classes)
                    ? $classes[$id]
                    : ['class' => 'unknown', 'confidence' => 0.4, 'reason' => 'uncertain'];
                if ($result === null) throw new RuntimeException('Synthetic provider outage.');
                return $result;
            },
        ],
        'settings' => [],
    ]);

    $config = default_config();
    $config['plugins']['enabled'] = ['local-media', 'personal-photo-gallery', 'test-photo-classifier'];
    $config['plugins']['providers']['media.photo_classification'] = 'test-photo-classifier';
    $GLOBALS['_pureblog_photo_gallery_config'] = $config;
    photo_gallery_assert(personal_photo_gallery_enabled($config), 'Enabled gallery was not recognized.');

    $database = plus_database();
    personal_photo_gallery_event_configuration_saved($config, $defaultConfig);
    photo_gallery_assert((int) $database->query("SELECT COUNT(*) FROM plus_jobs WHERE type='photo-gallery.rebuild'")->fetchColumn() === 1, 'Activation did not queue the initial gallery rebuild.');
    personal_photo_gallery_event_configuration_saved($config, $config);
    photo_gallery_assert((int) $database->query("SELECT COUNT(*) FROM plus_jobs WHERE type='photo-gallery.rebuild'")->fetchColumn() === 1, 'Unchanged settings queued an unnecessary rebuild.');
    $changedProviderConfig = $config;
    $changedProviderConfig['plugins']['settings']['test-photo-classifier']['revision'] = '2';
    personal_photo_gallery_event_configuration_saved($changedProviderConfig, $config);
    photo_gallery_assert((int) $database->query("SELECT COUNT(*) FROM plus_jobs WHERE type='photo-gallery.rebuild'")->fetchColumn() === 2, 'Classifier changes did not queue reclassification.');
    $mediaInsert = $database->prepare(
        'INSERT INTO plus_media (id,provider_id,url,mime_type,original_name,width,height,variants_json,provider_data_json,local_source,created_at,capture_metadata,capture_time,source_fingerprint) '
        . 'VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    $assetInsert = $database->prepare(
        'INSERT INTO plus_photo_gallery_assets (asset_key,media_id,url,preview_url,variants_json,mime_type,width,height,capture_metadata,capture_time,source_fingerprint,updated_at) '
        . 'VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    $occurrenceInsert = $database->prepare(
        'INSERT INTO plus_photo_gallery_occurrences (post_slug,asset_key,position,alt_text,caption,post_title,published_at,context_text) '
        . 'VALUES (?,?,?,?,?,?,?,?)'
    );

    $fixtures = [
        ['camera', 'camera_photo', 0.98, 'A walk by the lake', 'Lake walk', '2026-11-20 10:00:00'],
        ['cover', 'flat_artwork', 0.99, 'Book cover', 'A book review', '2026-11-19 10:00:00'],
        ['unknown', 'unknown', 0.55, 'Image', 'Notes', '2026-11-18 10:00:00'],
        ['physical', 'photo_of_physical_artwork', 0.96, 'A book on my table', 'Reading weekend', '2026-11-17 10:00:00'],
        ['outage', 'unknown', 0.0, 'Unavailable image', 'Outage', '2026-11-16 10:00:00'],
    ];
    $keys = [];
    $ids = [];
    foreach ($fixtures as $index => [$name, $class, $confidence, $alt, $postTitle, $publishedAt]) {
        $id = hash('md5', 'media-' . $name);
        $assetKey = hash('sha256', 'asset-' . $name);
        $url = 'https://media.example.test/' . $name . '.jpg';
        $preview = 'https://media.example.test/' . $name . '-360.webp';
        $variants = json_encode(['360' => $preview, '720' => 'https://media.example.test/' . $name . '-720.webp'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $mediaInsert->execute([$id, 'local-media', $url, 'image/jpeg', $name . '.jpg', 1200, 800, $variants, '{}', '/private/' . $name . '.jpg', time(), 0, 0, $assetKey]);
        $assetInsert->execute([$assetKey, $id, $url, $preview, $variants, 'image/jpeg', 1200, 800, 0, 0, $assetKey, time()]);
        $occurrenceInsert->execute([$name . '-post', $assetKey, $index, $alt, '', $postTitle, $publishedAt, $postTitle . "\n" . $alt]);
        $keys[$name] = $assetKey;
        $ids[$name] = $id;
        $classes[$id] = $name === 'outage' ? null : ['class' => $class, 'confidence' => $confidence, 'reason' => 'fixture:' . $class];
    }
    $occurrenceInsert->execute(['older-camera-post', $keys['camera'], 0, 'Older occurrence', '', 'Older lake post', '2026-10-01 10:00:00', 'Older lake post']);

    foreach (['camera', 'cover', 'unknown', 'physical'] as $name) personal_photo_gallery_classify($keys[$name]);
    try {
        personal_photo_gallery_classify($keys['outage']);
        throw new RuntimeException('Provider failure did not propagate for retry.');
    } catch (RuntimeException $error) {
        photo_gallery_assert($error->getMessage() === 'Synthetic provider outage.', 'Unexpected provider error.');
    }
    $failedClassification = personal_photo_gallery_classification($keys['outage']);
    photo_gallery_assert(($failedClassification['automatic_state'] ?? '') === 'unknown'
        && str_starts_with((string) ($failedClassification['reason'] ?? ''), 'provider_error:'), 'Provider failure was not stored conservatively.');

    $page = personal_photo_gallery_page_items(null, 24);
    photo_gallery_assert(array_column($page['items'], 'asset_key') === [$keys['camera'], $keys['physical']], 'Automatic gallery selection included a cover, unknown image, or duplicate.');
    photo_gallery_assert($page['next_cursor'] === null, 'Unexpected gallery cursor.');

    $first = personal_photo_gallery_page_items(null, 1);
    photo_gallery_assert(count($first['items']) === 1 && is_string($first['next_cursor']), 'First cursor page is invalid.');
    $second = personal_photo_gallery_page_items($first['next_cursor'], 1);
    photo_gallery_assert(count($second['items']) === 1 && $second['items'][0]['asset_key'] === $keys['physical'], 'Cursor pagination skipped or repeated a photograph.');
    try {
        personal_photo_gallery_page_items('not-a-valid-cursor', 1);
        throw new RuntimeException('Invalid cursor was accepted.');
    } catch (InvalidArgumentException) {
    }

    personal_photo_gallery_set_visibility($keys['cover'], 'visible');
    photo_gallery_assert(in_array($keys['cover'], array_column(personal_photo_gallery_page_items(null, 24)['items'], 'asset_key'), true), 'Manual show did not override cover exclusion.');
    personal_photo_gallery_set_visibility($keys['camera'], 'hidden');
    photo_gallery_assert(!in_array($keys['camera'], array_column(personal_photo_gallery_page_items(null, 24)['items'], 'asset_key'), true), 'Manual hide did not remove the photograph.');

    $classes[$ids['camera']] = ['class' => 'camera_photo', 'confidence' => 0.99, 'reason' => 'reclassified'];
    $database->prepare('UPDATE plus_photo_gallery_occurrences SET context_text=? WHERE asset_key=?')->execute(['Changed context', $keys['camera']]);
    personal_photo_gallery_classify($keys['camera']);
    photo_gallery_assert(count(personal_photo_gallery_admin_items('hidden')) === 1, 'Manual hide did not survive reclassification.');
    photo_gallery_assert(count(personal_photo_gallery_admin_items('excluded')) === 0, 'Manually shown cover remained in the excluded filter.');
    photo_gallery_assert(count(personal_photo_gallery_admin_items('unknown')) === 2, 'Unknown filter does not include conservative or failed classifications.');

    personal_photo_gallery_set_visibility($keys['camera'], null);
    personal_photo_gallery_set_visibility($keys['cover'], null);
    photo_gallery_assert(!in_array($keys['cover'], array_column(personal_photo_gallery_page_items(null, 24)['items'], 'asset_key'), true), 'Automatic cover exclusion was not restored.');
    $stats = personal_photo_gallery_stats();
    photo_gallery_assert($stats === ['visible' => 2, 'hidden' => 0, 'excluded' => 1, 'unknown' => 2], 'Gallery statistics are inconsistent.');

    $rendered = personal_photo_gallery_render_items(personal_photo_gallery_page_items(null, 24)['items'], true);
    photo_gallery_assert(str_contains($rendered, 'srcset=') && str_contains($rendered, 'sizes=')
        && str_contains($rendered, 'width="1200" height="800"') && str_contains($rendered, 'fetchpriority="high"')
        && str_contains($rendered, 'data-source-url='), 'Fast responsive gallery markup is incomplete.');

    $shapeItems = [];
    for ($i = 0; $i < 11; $i++) $shapeItems[] = ['width' => 800 + $i * 10, 'height' => 600];
    $rows = personal_photo_gallery_rows($shapeItems);
    photo_gallery_assert(array_sum(array_map('count', $rows)) === 11 && max(array_map('count', $rows)) <= 3, 'Proportional rows lost or overfilled images.');

    $localConfig = $config;
    $localConfig['plugins']['providers']['media.photo_classification'] = '';
    $GLOBALS['_pureblog_photo_gallery_config'] = $localConfig;
    foreach ([
        ['metadata-camera', 'Mountain landscape', 'visible'],
        ['metadata-cover', 'Book cover', 'excluded'],
    ] as $index => [$name, $alt, $expectedState]) {
        $id = hash('md5', 'media-' . $name);
        $key = hash('sha256', 'asset-' . $name);
        $url = 'https://media.example.test/' . $name . '.jpg';
        $variants = json_encode(['360' => 'https://media.example.test/' . $name . '-360.webp'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $mediaInsert->execute([$id, 'local-media', $url, 'image/jpeg', $name . '.jpg', 1200, 800, $variants, '{}', '/private/' . $name . '.jpg', time(), 1, 1, $key]);
        $assetInsert->execute([$key, $id, $url, $url, $variants, 'image/jpeg', 1200, 800, 1, 1, $key, time()]);
        $occurrenceInsert->execute([$name . '-post', $key, $index, $alt, '', $alt, '2026-10-0' . ($index + 1) . ' 10:00:00', $alt]);
        $classification = personal_photo_gallery_classify($key);
        photo_gallery_assert(($classification['automatic_state'] ?? '') === $expectedState, 'Local capture evidence did not fail closed around cover risk.');
    }

    $schema = $database->query('PRAGMA table_info(plus_media)')->fetchAll();
    $columns = array_column($schema, 'name');
    photo_gallery_assert(in_array('capture_metadata', $columns, true) && in_array('capture_time', $columns, true)
        && !in_array('gps', $columns, true) && !in_array('exif', $columns, true), 'Gallery evidence stores more metadata than intended.');

    $adminSource = file_get_contents(dirname(__DIR__) . '/admin/gallery.php');
    photo_gallery_assert(is_string($adminSource) && str_contains($adminSource, 'verify_csrf()')
        && !str_contains($adminSource, 'plus_delete_media'), 'Gallery moderation is not isolated from media deletion.');
    $script = file_get_contents(dirname(__DIR__) . '/assets/js/photo-gallery.js');
    photo_gallery_assert(is_string($script) && strlen($script) < 3000 && !str_contains($script, 'Mehr laden'), 'Public enhancement is too large or not localized.');

    echo "Personal photo gallery contract passed.\n";
} finally {
    unset($GLOBALS['_pureblog_photo_gallery_config']);
    photo_gallery_remove_tree($root);
}

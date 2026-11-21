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
            . "```html\n<img src=\"https://media.example.test/code.jpg\">\n```\n"
            . '`![Code](https://media.example.test/inline-code.jpg)`' . "\n"
            . '<!-- <img src="https://media.example.test/comment.jpg"> -->'
            . '<p><img src="https://media.example.test/inline.jpg" alt="Inline photo"></p>'
            . '<iframe src="https://video.example.test/embed"><img src="invalid"></iframe>',
    ]);
    photo_gallery_assert(array_column($candidates, 'url') === [
        'https://media.example.test/feature.jpg',
        'https://media.example.test/micropub.jpg',
        'https://media.example.test/cover.jpg',
        'https://media.example.test/inline.jpg',
    ], 'Canonical post image discovery changed.');
    $mergedFeature = personal_photo_gallery_post_candidates([
        'feature_image' => 'https://media.example.test/feature.jpg',
        'content' => '![Detailed authored description](https://media.example.test/feature.jpg)',
    ]);
    photo_gallery_assert(count($mergedFeature) === 1 && $mergedFeature[0]['alt'] === 'Detailed authored description', 'A feature-image occurrence discarded its authored contextual alt text.');
    photo_gallery_assert(personal_photo_gallery_post_candidates([
        'micropub_content_format' => 'text',
        'content' => '![Not markup](https://media.example.test/plain-text.jpg)',
    ]) === [], 'Plain-text Micropub content was parsed as authored image markup.');

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
        'settings' => [
            'revision' => ['type' => 'text', 'label' => 'Revision', 'default' => '1'],
        ],
    ]);

    $config = default_config();
    $config['plugins']['enabled'] = ['local-media', 'personal-photo-gallery', 'test-photo-classifier'];
    $config['plugins']['providers']['media.photo_classification'] = 'test-photo-classifier';
    $GLOBALS['_pureblog_photo_gallery_config'] = $config;
    photo_gallery_assert(personal_photo_gallery_enabled($config), 'Enabled gallery was not recognized.');
    photo_gallery_assert(plus_public_route_matches('/gallery', $config)
        && !plus_public_route_matches('/photos', $config), 'The fast gallery route matcher does not follow the configured route.');

    $legacy = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $legacy->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $legacy->exec('PRAGMA foreign_keys=ON');
    $legacy->exec("CREATE TABLE plus_media (id TEXT PRIMARY KEY,delete_state TEXT NOT NULL DEFAULT 'active',capture_metadata INTEGER NOT NULL DEFAULT 0,capture_time INTEGER NOT NULL DEFAULT 0,source_fingerprint TEXT NOT NULL DEFAULT '')");
    $legacy->exec("INSERT INTO plus_media (id) VALUES ('legacy-media')");
    $legacy->exec("CREATE TABLE plus_photo_gallery_assets (asset_key TEXT PRIMARY KEY,media_id TEXT NOT NULL)");
    $legacy->exec("INSERT INTO plus_photo_gallery_assets VALUES ('legacy-asset','legacy-media')");
    $legacy->exec("CREATE TABLE plus_photo_gallery_occurrences (post_slug TEXT NOT NULL,asset_key TEXT NOT NULL REFERENCES plus_photo_gallery_assets(asset_key) ON DELETE CASCADE,position INTEGER NOT NULL,alt_text TEXT NOT NULL DEFAULT '',caption TEXT NOT NULL DEFAULT '',post_title TEXT NOT NULL,published_at TEXT NOT NULL,context_text TEXT NOT NULL DEFAULT '',PRIMARY KEY (post_slug,asset_key))");
    $legacy->exec("INSERT INTO plus_photo_gallery_occurrences VALUES ('legacy-post','legacy-asset',0,'Legacy','','Legacy','2026-01-01','Legacy')");
    $legacy->exec("CREATE TABLE plus_photo_gallery_classifications (asset_key TEXT PRIMARY KEY REFERENCES plus_photo_gallery_assets(asset_key) ON DELETE CASCADE,visual_class TEXT NOT NULL,confidence REAL NOT NULL,automatic_state TEXT NOT NULL,reason TEXT NOT NULL,classifier_id TEXT NOT NULL,classifier_version TEXT NOT NULL,source_fingerprint TEXT NOT NULL,updated_at INTEGER NOT NULL)");
    $legacy->exec("INSERT INTO plus_photo_gallery_classifications VALUES ('legacy-asset','camera_photo',0.99,'visible','legacy','local','1','legacy-signature',1)");
    $legacy->exec("CREATE TABLE plus_photo_gallery_overrides (asset_key TEXT PRIMARY KEY REFERENCES plus_photo_gallery_assets(asset_key) ON DELETE CASCADE,visibility TEXT NOT NULL,updated_at INTEGER NOT NULL)");
    ($GLOBALS['_pureblog_plus_migrations']['0012_media_capture_evidence'])($legacy);
    ($GLOBALS['_pureblog_plus_migrations']['0013_personal_photo_gallery'])($legacy);
    $legacyOccurrence = $legacy->query('SELECT media_id FROM plus_photo_gallery_latest_occurrences')->fetchColumn();
    $legacySignature = $legacy->query('SELECT input_signature FROM plus_photo_gallery_classifications')->fetchColumn();
    $legacyAssetColumns = array_column($legacy->query('PRAGMA table_info(plus_photo_gallery_assets)')->fetchAll(), 'name');
    $legacy->exec("INSERT INTO plus_photo_gallery_assets (asset_key) VALUES ('new-asset')");
    photo_gallery_assert($legacyOccurrence === 'legacy-media' && $legacySignature === 'legacy-signature'
        && $legacyAssetColumns === ['asset_key'], 'The first gallery schema cannot be upgraded without losing projection data or accepting new assets.');

    $database = plus_database();
    photo_gallery_assert((int) $database->query("SELECT COUNT(*) FROM sqlite_master WHERE type='index' AND name='plus_photo_gallery_occurrence_asset_idx'")->fetchColumn() === 1, 'Latest-occurrence queries have no asset-first index.');
    photo_gallery_assert((int) $database->query("SELECT COUNT(*) FROM sqlite_master WHERE type='view' AND name='plus_photo_gallery_latest_occurrences'")->fetchColumn() === 1, 'Latest-occurrence ownership is duplicated instead of defined once.');
    personal_photo_gallery_event_configuration_saved($config, $defaultConfig);
    photo_gallery_assert((int) $database->query("SELECT COUNT(*) FROM plus_jobs WHERE type='photo-gallery.rebuild'")->fetchColumn() === 1, 'Activation did not queue the initial gallery rebuild.');
    personal_photo_gallery_event_configuration_saved($config, $config);
    photo_gallery_assert((int) $database->query("SELECT COUNT(*) FROM plus_jobs WHERE type='photo-gallery.rebuild'")->fetchColumn() === 1, 'Unchanged settings queued an unnecessary rebuild.');
    $changedProviderConfig = $config;
    $changedProviderConfig['plugins']['settings']['test-photo-classifier']['revision'] = '2';
    personal_photo_gallery_event_configuration_saved($changedProviderConfig, $config);
    photo_gallery_assert((int) $database->query("SELECT COUNT(*) FROM plus_jobs WHERE type='photo-gallery.rebuild'")->fetchColumn() === 1, 'Queued full rebuilds were duplicated instead of coalesced.');
    personal_photo_gallery_event_post_saved(['slug' => 'reverted-post', 'status' => 'published', 'content' => 'first']);
    personal_photo_gallery_event_post_saved(['slug' => 'reverted-post', 'status' => 'published', 'content' => 'second']);
    personal_photo_gallery_event_post_saved(['slug' => 'reverted-post', 'status' => 'published', 'content' => 'first']);
    photo_gallery_assert((int) $database->query("SELECT COUNT(*) FROM plus_jobs WHERE type='photo-gallery.sync'")->fetchColumn() === 3, 'A reverted post state reused a terminal sync job instead of scheduling fresh work.');
    $mediaInsert = $database->prepare(
        'INSERT INTO plus_media (id,provider_id,url,mime_type,original_name,width,height,variants_json,provider_data_json,local_source,created_at,capture_metadata,capture_time,source_fingerprint) '
        . 'VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    $assetInsert = $database->prepare(
        'INSERT INTO plus_photo_gallery_assets (asset_key) VALUES (?)'
    );
    $occurrenceInsert = $database->prepare(
        'INSERT INTO plus_photo_gallery_occurrences (post_slug,asset_key,media_id,position,alt_text,caption,post_title,published_at,context_text) '
        . 'VALUES (?,?,?,?,?,?,?,?,?)'
    );

    $fixtures = [
        ['camera', 'camera_photo', 0.98, 'A walk by the lake', 'Lake walk', '2026-11-20 10:00:00'],
        ['cover', 'flat_artwork', 0.99, 'Book cover', 'A book review', '2026-11-19 10:00:00'],
        ['unknown', 'unknown', 0.55, 'Image', 'Notes', '2026-11-18 10:00:00'],
        ['physical', 'photo_of_physical_artwork', 0.96, 'A book on my table', 'Reading weekend', '2026-11-17 10:00:00'],
        ['outage', 'unknown', 0.0, 'Unavailable image', 'Outage', '2026-11-16 10:00:00'],
        ['risky-camera', 'camera_photo', 0.99, 'Book cover', 'A book review', '2026-11-15 10:00:00'],
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
        $assetInsert->execute([$assetKey]);
        $occurrenceInsert->execute([$name . '-post', $assetKey, $id, $index, $alt, '', $postTitle, $publishedAt, $postTitle . "\n" . $alt]);
        $keys[$name] = $assetKey;
        $ids[$name] = $id;
        $classes[$id] = $name === 'outage' ? null : ['class' => $class, 'confidence' => $confidence, 'reason' => 'fixture:' . $class];
    }
    $occurrenceInsert->execute(['older-camera-post', $keys['camera'], $ids['camera'], 0, 'Older occurrence', '', 'Older lake post', '2026-10-01 10:00:00', 'Older lake post']);

    foreach (['camera', 'cover', 'unknown', 'physical', 'risky-camera'] as $name) personal_photo_gallery_classify($keys[$name]);
    try {
        personal_photo_gallery_classify($keys['outage']);
        throw new RuntimeException('Provider failure did not propagate for retry.');
    } catch (RuntimeException $error) {
        photo_gallery_assert($error->getMessage() === 'Synthetic provider outage.', 'Unexpected provider error.');
    }
    $failedClassification = personal_photo_gallery_classification($keys['outage']);
    photo_gallery_assert(($failedClassification['automatic_state'] ?? '') === 'unknown'
        && str_starts_with((string) ($failedClassification['reason'] ?? ''), 'provider_error:'), 'Provider failure was not stored conservatively.');
    $riskyClassification = personal_photo_gallery_classification($keys['risky-camera']);
    photo_gallery_assert(($riskyClassification['automatic_state'] ?? '') === 'unknown'
        && str_starts_with((string) ($riskyClassification['reason'] ?? ''), 'conflicting_artwork_context:'), 'Conflicting cover context did not fail closed.');

    $reconfigured = $config;
    $reconfigured['plugins']['settings']['test-photo-classifier']['revision'] = '2';
    $GLOBALS['_pureblog_photo_gallery_config'] = $reconfigured;
    $classes[$ids['camera']] = ['class' => 'flat_artwork', 'confidence' => 0.99, 'reason' => 'settings changed'];
    personal_photo_gallery_classify($keys['camera']);
    photo_gallery_assert((personal_photo_gallery_classification($keys['camera'])['automatic_state'] ?? '') === 'excluded', 'Changed provider settings reused a stale classification during rebuild.');
    $GLOBALS['_pureblog_photo_gallery_config'] = $config;
    $classes[$ids['camera']] = ['class' => 'camera_photo', 'confidence' => 0.98, 'reason' => 'fixture:camera_photo'];
    personal_photo_gallery_classify($keys['camera']);

    $missingProviderConfig = $config;
    $missingProviderConfig['plugins']['providers']['media.photo_classification'] = 'missing-provider';
    $GLOBALS['_pureblog_photo_gallery_config'] = $missingProviderConfig;
    try {
        personal_photo_gallery_classify($keys['camera']);
        throw new RuntimeException('Missing configured provider silently fell back to local inclusion.');
    } catch (RuntimeException $error) {
        photo_gallery_assert(str_contains($error->getMessage(), 'not enabled'), 'Unexpected missing-provider error.');
    }
    photo_gallery_assert(str_starts_with((string) (personal_photo_gallery_classification($keys['camera'])['reason'] ?? ''), 'provider_error:'), 'Missing configured provider did not fail closed.');
    $GLOBALS['_pureblog_photo_gallery_config'] = $config;
    personal_photo_gallery_classify($keys['camera']);
    $occurrenceInsert->execute(['stale-camera-post', $keys['camera'], $ids['camera'], 0, 'Book cover', '', 'Book cover', '2026-12-01 10:00:00', 'Book cover']);
    personal_photo_gallery_classify($keys['camera']);
    photo_gallery_assert((personal_photo_gallery_classification($keys['camera'])['automatic_state'] ?? '') === 'unknown', 'Latest duplicate context did not control classification.');
    $database->prepare("DELETE FROM plus_photo_gallery_occurrences WHERE post_slug='stale-camera-post'")->execute();
    personal_photo_gallery_classify_assets([$keys['camera']]);
    photo_gallery_assert((personal_photo_gallery_classification($keys['camera'])['automatic_state'] ?? '') === 'visible', 'Removing the latest occurrence left a stale classification from its context.');

    $duplicateId = hash('md5', 'media-camera-duplicate');
    $duplicateUrl = 'https://media.example.test/camera-duplicate.jpg';
    $duplicateVariants = json_encode(['360' => 'https://media.example.test/camera-duplicate-360.webp'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $mediaInsert->execute([$duplicateId, 'local-media', $duplicateUrl, 'image/jpeg', 'camera-duplicate.jpg', 1200, 800, $duplicateVariants, '{}', '/private/camera-duplicate.jpg', time(), 0, 0, $keys['camera']]);
    $occurrenceInsert->execute(['newer-camera-post', $keys['camera'], $duplicateId, 0, 'Newer duplicate', '', 'Newer lake post', '2026-11-21 10:00:00', 'Newer lake post']);
    $preparedDuplicates = personal_photo_gallery_prepare_candidates([
        'title' => 'Duplicate use',
        'content' => '![First](https://media.example.test/camera.jpg) ![Second](https://media.example.test/camera-duplicate.jpg)',
    ]);
    photo_gallery_assert(count($preparedDuplicates) === 1
        && reset($preparedDuplicates)['media_id'] === $ids['camera'], 'Content-identical uploads in one post were not deduplicated before projection.');

    $page = personal_photo_gallery_page_items(null, 24);
    photo_gallery_assert(array_column($page['items'], 'asset_key') === [$keys['camera'], $keys['physical']], 'Automatic gallery selection included a cover, unknown image, or duplicate.');
    photo_gallery_assert($page['items'][0]['url'] === $duplicateUrl, 'The newest occurrence did not own the deduplicated asset presentation.');
    $database->prepare("UPDATE plus_media SET delete_state='deleting' WHERE id=?")->execute([$duplicateId]);
    $page = personal_photo_gallery_page_items(null, 24);
    photo_gallery_assert($page['items'][0]['url'] === 'https://media.example.test/camera.jpg', 'A deleting duplicate media record hid or broke an active occurrence.');
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
    photo_gallery_assert(count(personal_photo_gallery_admin_items('unknown')) === 3, 'Unknown filter does not include conservative, conflicting, or failed classifications.');

    personal_photo_gallery_set_visibility($keys['camera'], null);
    personal_photo_gallery_set_visibility($keys['cover'], null);
    photo_gallery_assert(!in_array($keys['cover'], array_column(personal_photo_gallery_page_items(null, 24)['items'], 'asset_key'), true), 'Automatic cover exclusion was not restored.');
    $stats = personal_photo_gallery_stats();
    photo_gallery_assert($stats === ['visible' => 2, 'hidden' => 0, 'excluded' => 1, 'unknown' => 3], 'Gallery statistics are inconsistent.');

    $orphanKey = hash('sha256', 'temporarily-orphaned-asset');
    $assetInsert->execute([$orphanKey]);
    personal_photo_gallery_set_visibility($orphanKey, 'hidden');
    personal_photo_gallery_prune_orphan_assets($database);
    photo_gallery_assert((int) $database->query("SELECT COUNT(*) FROM plus_photo_gallery_assets WHERE asset_key='{$orphanKey}'")->fetchColumn() === 1, 'A temporary projection gap discarded its manual visibility override.');
    personal_photo_gallery_set_visibility($orphanKey, null);
    personal_photo_gallery_prune_orphan_assets($database);
    photo_gallery_assert((int) $database->query("SELECT COUNT(*) FROM plus_photo_gallery_assets WHERE asset_key='{$orphanKey}'")->fetchColumn() === 0, 'An unreferenced asset without an override was not pruned.');

    $rendered = personal_photo_gallery_render_items(personal_photo_gallery_page_items(null, 24)['items'], true);
    photo_gallery_assert(str_contains($rendered, 'srcset=') && str_contains($rendered, 'sizes=')
        && str_contains($rendered, 'width="1200" height="800"') && str_contains($rendered, 'fetchpriority="high"')
        && str_contains($rendered, 'data-source-url='), 'Fast responsive gallery markup is incomplete.');
    $fallbackAltItem = personal_photo_gallery_page_items(null, 1)['items'][0];
    $fallbackAltItem['alt_text'] = '';
    $fallbackAltItem['caption'] = '';
    $fallbackAltItem['post_title'] = 'Accessible source title';
    photo_gallery_assert(str_contains(personal_photo_gallery_render_items([$fallbackAltItem]), 'alt="Accessible source title"'), 'Gallery images without authored alt text have no accessible fallback.');

    $shapeItems = [];
    for ($i = 0; $i < 11; $i++) $shapeItems[] = ['width' => 800 + $i * 10, 'height' => 600];
    $rows = personal_photo_gallery_rows($shapeItems);
    photo_gallery_assert(array_sum(array_map('count', $rows)) === 11 && max(array_map('count', $rows)) <= 3, 'Proportional rows lost or overfilled images.');

    $localConfig = $config;
    $localConfig['plugins']['providers']['media.photo_classification'] = '';
    $GLOBALS['_pureblog_photo_gallery_config'] = $localConfig;
    foreach ([
        ['metadata-camera', 'Mountain landscape', 1, 'visible'],
        ['metadata-cover', 'Book cover', 1, 'excluded'],
        ['metadata-partial', 'Mountain landscape', 0, 'unknown'],
    ] as $index => [$name, $alt, $captureTime, $expectedState]) {
        $id = hash('md5', 'media-' . $name);
        $key = hash('sha256', 'asset-' . $name);
        $url = 'https://media.example.test/' . $name . '.jpg';
        $variants = json_encode(['360' => 'https://media.example.test/' . $name . '-360.webp'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $mediaInsert->execute([$id, 'local-media', $url, 'image/jpeg', $name . '.jpg', 1200, 800, $variants, '{}', '/private/' . $name . '.jpg', time(), 1, $captureTime, $key]);
        $assetInsert->execute([$key]);
        $occurrenceInsert->execute([$name . '-post', $key, $id, $index, $alt, '', $alt, '2026-10-0' . ($index + 1) . ' 10:00:00', $alt]);
        $classification = personal_photo_gallery_classify($key);
        photo_gallery_assert(($classification['automatic_state'] ?? '') === $expectedState, 'Local capture evidence did not fail closed around cover risk.');
    }

    $disabledConfig = $localConfig;
    $disabledConfig['plugins']['enabled'] = ['local-media'];
    $GLOBALS['_pureblog_photo_gallery_config'] = $disabledConfig;
    personal_photo_gallery_sync_job(['slug' => 'camera-post']);
    photo_gallery_assert((int) $database->query("SELECT COUNT(*) FROM plus_photo_gallery_occurrences WHERE post_slug='camera-post'")->fetchColumn() === 1, 'A queued sync job mutated the projection after the gallery was disabled.');

    $schema = $database->query('PRAGMA table_info(plus_media)')->fetchAll();
    $columns = array_column($schema, 'name');
    photo_gallery_assert(in_array('capture_metadata', $columns, true) && in_array('capture_time', $columns, true)
        && !in_array('gps', $columns, true) && !in_array('exif', $columns, true), 'Gallery evidence stores more metadata than intended.');

    $adminSource = file_get_contents(dirname(__DIR__) . '/admin/gallery.php');
    photo_gallery_assert(is_string($adminSource) && str_contains($adminSource, 'verify_csrf()')
        && !str_contains($adminSource, 'plus_delete_media'), 'Gallery moderation is not isolated from media deletion.');
    $frontController = file_get_contents(dirname(__DIR__) . '/index.php');
    photo_gallery_assert(is_string($frontController)
        && str_contains($frontController, '!published_content_slug_exists($requestPath)')
        && strrpos($frontController, 'plus_dispatch_public_route') > strpos($frontController, 'get_post_by_slug'), 'A plugin route can shadow an existing post or page.');
    $script = file_get_contents(dirname(__DIR__) . '/assets/js/photo-gallery.js');
    photo_gallery_assert(is_string($script) && strlen($script) < 3000 && !str_contains($script, 'Mehr laden'), 'Public enhancement is too large or not localized.');

    echo "Personal photo gallery contract passed.\n";
} finally {
    unset($GLOBALS['_pureblog_photo_gallery_config']);
    photo_gallery_remove_tree($root);
}

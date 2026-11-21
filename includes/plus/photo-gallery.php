<?php

declare(strict_types=1);

const PERSONAL_PHOTO_GALLERY_RULE_VERSION = '1';

plus_register_migration('0012_personal_photo_gallery', static function (PDO $database): void {
    $database->exec("ALTER TABLE plus_media ADD COLUMN capture_metadata INTEGER NOT NULL DEFAULT 0 CHECK (capture_metadata IN (0,1))");
    $database->exec("ALTER TABLE plus_media ADD COLUMN capture_time INTEGER NOT NULL DEFAULT 0 CHECK (capture_time IN (0,1))");
    $database->exec("ALTER TABLE plus_media ADD COLUMN source_fingerprint TEXT NOT NULL DEFAULT ''");
    $database->exec(
        'CREATE TABLE plus_photo_gallery_assets ('
        . 'asset_key TEXT PRIMARY KEY,'
        . 'media_id TEXT NOT NULL,'
        . 'url TEXT NOT NULL,'
        . 'preview_url TEXT NOT NULL,'
        . 'variants_json TEXT NOT NULL DEFAULT \'{}\','
        . 'mime_type TEXT NOT NULL,'
        . 'width INTEGER NOT NULL,'
        . 'height INTEGER NOT NULL,'
        . 'capture_metadata INTEGER NOT NULL DEFAULT 0 CHECK (capture_metadata IN (0,1)),'
        . 'capture_time INTEGER NOT NULL DEFAULT 0 CHECK (capture_time IN (0,1)),'
        . 'source_fingerprint TEXT NOT NULL,'
        . 'updated_at INTEGER NOT NULL)'
    );
    $database->exec(
        'CREATE TABLE plus_photo_gallery_occurrences ('
        . 'post_slug TEXT NOT NULL,'
        . 'asset_key TEXT NOT NULL REFERENCES plus_photo_gallery_assets(asset_key) ON DELETE CASCADE,'
        . 'position INTEGER NOT NULL,'
        . 'alt_text TEXT NOT NULL DEFAULT \'\','
        . 'caption TEXT NOT NULL DEFAULT \'\','
        . 'post_title TEXT NOT NULL,'
        . 'published_at TEXT NOT NULL,'
        . 'context_text TEXT NOT NULL DEFAULT \'\','
        . 'PRIMARY KEY (post_slug,asset_key))'
    );
    $database->exec('CREATE INDEX plus_photo_gallery_occurrence_date_idx ON plus_photo_gallery_occurrences (published_at DESC,asset_key DESC)');
    $database->exec(
        "CREATE TABLE plus_photo_gallery_classifications ("
        . "asset_key TEXT PRIMARY KEY REFERENCES plus_photo_gallery_assets(asset_key) ON DELETE CASCADE,"
        . "visual_class TEXT NOT NULL CHECK (visual_class IN ('camera_photo','photo_of_physical_artwork','flat_artwork','screenshot','illustration','document','unknown')),"
        . "confidence REAL NOT NULL DEFAULT 0 CHECK (confidence >= 0 AND confidence <= 1),"
        . "automatic_state TEXT NOT NULL CHECK (automatic_state IN ('visible','excluded','unknown')),"
        . "reason TEXT NOT NULL DEFAULT '',"
        . "classifier_id TEXT NOT NULL DEFAULT '',"
        . "classifier_version TEXT NOT NULL DEFAULT '',"
        . "source_fingerprint TEXT NOT NULL,"
        . "updated_at INTEGER NOT NULL)"
    );
    $database->exec(
        "CREATE TABLE plus_photo_gallery_overrides ("
        . "asset_key TEXT PRIMARY KEY REFERENCES plus_photo_gallery_assets(asset_key) ON DELETE CASCADE,"
        . "visibility TEXT NOT NULL CHECK (visibility IN ('visible','hidden')),"
        . "updated_at INTEGER NOT NULL)"
    );
});

/** @return array{capture_metadata:bool,capture_time:bool} */
function personal_photo_gallery_capture_evidence(string $path, string $mime): array
{
    $result = ['capture_metadata' => false, 'capture_time' => false];
    if (!in_array($mime, ['image/jpeg', 'image/tiff'], true) || !function_exists('exif_read_data')) {
        return $result;
    }
    try {
        $exif = @exif_read_data($path, null, true, false);
    } catch (Throwable) {
        return $result;
    }
    if (!is_array($exif)) return $result;
    $flat = [];
    foreach ($exif as $section) {
        if (!is_array($section)) continue;
        foreach ($section as $key => $value) $flat[(string) $key] = $value;
    }
    foreach (['Make', 'Model', 'ExposureTime', 'FNumber', 'ISOSpeedRatings', 'FocalLength'] as $key) {
        if (isset($flat[$key]) && is_scalar($flat[$key]) && trim((string) $flat[$key]) !== '') {
            $result['capture_metadata'] = true;
            break;
        }
    }
    foreach (['DateTimeOriginal', 'DateTimeDigitized'] as $key) {
        if (isset($flat[$key]) && is_scalar($flat[$key]) && trim((string) $flat[$key]) !== '') {
            $result['capture_time'] = true;
            break;
        }
    }
    return $result;
}

function personal_photo_gallery_source_fingerprint(string $path): string
{
    $hash = is_file($path) ? hash_file('sha256', $path) : false;
    return is_string($hash) ? $hash : '';
}

/** @return array{route:string,title:string,page_size:int} */
function personal_photo_gallery_settings(?array $config = null): array
{
    $config ??= load_config();
    $saved = plus_plugin_settings('personal-photo-gallery', $config);
    $route = strtolower(trim((string) ($saved['route'] ?? 'gallery'), "/ \t\n\r\0\x0B"));
    if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $route) !== 1) $route = 'gallery';
    $title = trim((string) ($saved['title'] ?? ''));
    if ($title === '') $title = t('frontend.photo_gallery_title');
    $pageSize = (int) ($saved['page_size'] ?? 24);
    if (!in_array($pageSize, [24, 36], true)) $pageSize = 24;
    return ['route' => $route, 'title' => mb_substr($title, 0, 120), 'page_size' => $pageSize];
}

function personal_photo_gallery_enabled(?array $config = null): bool
{
    return plus_plugin_is_enabled('personal-photo-gallery', $config);
}

/** @return list<array{url:string,alt:string,caption:string,position:int}> */
function personal_photo_gallery_post_candidates(array $post): array
{
    $items = [];
    $seen = [];
    $append = static function (string $url, string $alt = '', string $caption = '') use (&$items, &$seen): void {
        $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($url === '' || isset($seen[$url])) return;
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) return;
        $seen[$url] = true;
        $items[] = [
            'url' => $url,
            'alt' => mb_substr(trim(preg_replace('/\s+/u', ' ', $alt) ?? ''), 0, 1500),
            'caption' => mb_substr(trim(preg_replace('/\s+/u', ' ', $caption) ?? ''), 0, 1500),
            'position' => count($items),
        ];
    };

    $feature = trim((string) ($post['feature_image'] ?? ''));
    if ($feature !== '') $append($feature, (string) ($post['title'] ?? ''));

    $photoJson = trim((string) ($post['micropub_photo'] ?? ''));
    $photos = $photoJson !== '' ? json_decode($photoJson, true) : [];
    if (is_array($photos)) foreach ($photos as $photo) {
        $append(
            is_string($photo) ? $photo : (is_array($photo) ? (string) ($photo['value'] ?? '') : ''),
            is_array($photo) ? (string) ($photo['alt'] ?? '') : ''
        );
    }

    $content = (string) ($post['content'] ?? '');
    if (preg_match_all('/!\[(?<alt>(?:\\.|[^\]])*)\]\((?<url>https?:\/\/[^\s)]+)(?:\s+["\'](?<title>[^"\']*)["\'])?\)/u', $content, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) $append((string) $match['url'], (string) $match['alt'], (string) ($match['title'] ?? ''));
    }
    if (preg_match_all('/<img\b[^>]*>/iu', $content, $tags)) foreach ($tags[0] as $tag) {
        if (!preg_match('/\ssrc\s*=\s*(["\'])(.*?)\1/isu', $tag, $source)) continue;
        preg_match('/\salt\s*=\s*(["\'])(.*?)\1/isu', $tag, $alt);
        preg_match('/\stitle\s*=\s*(["\'])(.*?)\1/isu', $tag, $title);
        $append((string) $source[2], (string) ($alt[2] ?? ''), (string) ($title[2] ?? ''));
    }
    return $items;
}

function personal_photo_gallery_media_fingerprint(array $media): string
{
    $fingerprint = trim((string) ($media['source_fingerprint'] ?? ''));
    if (preg_match('/^[a-f0-9]{64}$/', $fingerprint) === 1) return $fingerprint;
    $fingerprint = personal_photo_gallery_source_fingerprint((string) ($media['local_source'] ?? ''));
    if ($fingerprint !== '') {
        plus_database()->prepare('UPDATE plus_media SET source_fingerprint=? WHERE id=?')->execute([$fingerprint, $media['id']]);
    }
    return $fingerprint;
}

function personal_photo_gallery_sync_post(string $slug, ?string $oldSlug = null): int
{
    $database = plus_database();
    $post = get_post_by_slug($slug, true);
    plus_begin_immediate($database);
    $assetKeys = [];
    try {
        if ($oldSlug !== null && $oldSlug !== '' && $oldSlug !== $slug) {
            $database->prepare('DELETE FROM plus_photo_gallery_occurrences WHERE post_slug=?')->execute([$oldSlug]);
        }
        $database->prepare('DELETE FROM plus_photo_gallery_occurrences WHERE post_slug=?')->execute([$slug]);
        if (is_array($post) && ($post['status'] ?? '') === 'published') {
            $insertAsset = $database->prepare(
                'INSERT INTO plus_photo_gallery_assets (asset_key,media_id,url,preview_url,variants_json,mime_type,width,height,capture_metadata,capture_time,source_fingerprint,updated_at) '
                . 'VALUES (?,?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT(asset_key) DO UPDATE SET '
                . 'media_id=excluded.media_id,url=excluded.url,preview_url=excluded.preview_url,variants_json=excluded.variants_json,mime_type=excluded.mime_type,'
                . 'width=excluded.width,height=excluded.height,capture_metadata=excluded.capture_metadata,capture_time=excluded.capture_time,'
                . 'source_fingerprint=excluded.source_fingerprint,updated_at=excluded.updated_at'
            );
            $insertOccurrence = $database->prepare(
                'INSERT INTO plus_photo_gallery_occurrences (post_slug,asset_key,position,alt_text,caption,post_title,published_at,context_text) '
                . 'VALUES (?,?,?,?,?,?,?,?)'
            );
            foreach (personal_photo_gallery_post_candidates($post) as $candidate) {
                $media = plus_media_by_url($candidate['url']);
                if ($media === null || !str_starts_with((string) ($media['mime_type'] ?? ''), 'image/')) continue;
                $fingerprint = personal_photo_gallery_media_fingerprint($media);
                $assetKey = $fingerprint !== '' ? $fingerprint : (string) $media['id'];
                $variants = (array) ($media['variants'] ?? json_decode((string) ($media['variants_json'] ?? '{}'), true));
                ksort($variants, SORT_NUMERIC);
                $preview = $variants !== [] ? (string) reset($variants) : (string) $media['url'];
                $now = time();
                $insertAsset->execute([
                    $assetKey, (string) $media['id'], (string) $media['url'], $preview,
                    json_encode($variants, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), (string) $media['mime_type'],
                    max(0, (int) ($media['width'] ?? 0)), max(0, (int) ($media['height'] ?? 0)),
                    !empty($media['capture_metadata']) ? 1 : 0, !empty($media['capture_time']) ? 1 : 0,
                    $fingerprint, $now,
                ]);
                $context = implode("\n", array_filter([
                    (string) ($post['title'] ?? ''), (string) ($post['description'] ?? ''),
                    implode(', ', array_map('strval', (array) ($post['tags'] ?? []))),
                    $candidate['alt'], $candidate['caption'],
                ]));
                $insertOccurrence->execute([
                    $slug, $assetKey, $candidate['position'], $candidate['alt'], $candidate['caption'],
                    (string) ($post['title'] ?? ''), (string) ($post['date'] ?? ''), mb_substr($context, 0, 2500),
                ]);
                $assetKeys[$assetKey] = true;
            }
        }
        $database->exec('COMMIT');
    } catch (Throwable $error) {
        if ($database->inTransaction()) $database->exec('ROLLBACK');
        throw $error;
    }

    $classificationError = null;
    foreach (array_keys($assetKeys) as $assetKey) {
        plus_job_heartbeat();
        try {
            personal_photo_gallery_classify($assetKey);
        } catch (Throwable $error) {
            $classificationError ??= $error;
        }
        plus_job_heartbeat();
    }
    cache_clear();
    if ($classificationError !== null) throw $classificationError;
    return count($assetKeys);
}

/** @return array{id:string,version:string,handler:callable,settings:array<string,mixed>}|null */
function personal_photo_gallery_classifier(): ?array
{
    $config = $GLOBALS['_pureblog_photo_gallery_config'] ?? load_config();
    $id = trim((string) ($config['plugins']['providers']['media.photo_classification'] ?? ''));
    if ($id === '') return null;
    try {
        $provider = plus_plugin_provider('media.photo_classification', $id, $config);
    } catch (Throwable) {
        return null;
    }
    $model = trim((string) ($provider['settings']['model'] ?? ''));
    return [
        'id' => $id,
        'version' => (string) ($provider['plugin']['version'] ?? '') . ($model !== '' ? ':' . $model : ''),
        'handler' => $provider['handler'],
        'settings' => $provider['settings'],
    ];
}

function personal_photo_gallery_classify(string $assetKey): array
{
    $query = plus_database()->prepare(
        'SELECT a.*,o.context_text,o.alt_text,o.caption,o.post_title FROM plus_photo_gallery_assets a '
        . 'JOIN plus_photo_gallery_occurrences o ON o.asset_key=a.asset_key '
        . 'WHERE a.asset_key=? ORDER BY o.published_at DESC,o.post_slug DESC LIMIT 1'
    );
    $query->execute([$assetKey]);
    $asset = $query->fetch();
    if (!is_array($asset)) throw new InvalidArgumentException('Unknown gallery asset.');

    $classifier = personal_photo_gallery_classifier();
    $classifierId = $classifier['id'] ?? 'local';
    $classifierVersion = $classifier['version'] ?? PERSONAL_PHOTO_GALLERY_RULE_VERSION;
    $sourceFingerprint = hash('sha256', implode("\n", [
        (string) ($asset['source_fingerprint'] ?: $asset['asset_key']),
        (string) $asset['context_text'], PERSONAL_PHOTO_GALLERY_RULE_VERSION, $classifierId, $classifierVersion,
    ]));
    $existing = plus_database()->prepare('SELECT * FROM plus_photo_gallery_classifications WHERE asset_key=? AND source_fingerprint=?');
    $existing->execute([$assetKey, $sourceFingerprint]);
    $classification = $existing->fetch();
    if (is_array($classification) && !str_starts_with((string) $classification['reason'], 'provider_error:')) return $classification;

    $visualClass = 'unknown';
    $confidence = 0.0;
    $state = 'unknown';
    $reason = 'insufficient_evidence';
    $riskText = mb_strtolower(implode(' ', [(string) $asset['alt_text'], (string) $asset['caption'], (string) $asset['post_title']]));
    $artworkRisk = preg_match('/\b(?:filmplakat|movie poster|poster|buchcover|book cover|albumcover|album art|screenshot|bildschirmfoto|logo|illustration|eintrittskarte|ticket)\b/u', $riskText) === 1;

    if ($classifier === null) {
        if ($artworkRisk) {
            $visualClass = 'flat_artwork'; $confidence = 0.8; $state = 'excluded'; $reason = 'local_artwork_signal';
        } elseif (!empty($asset['capture_metadata'])) {
            $visualClass = 'camera_photo'; $confidence = !empty($asset['capture_time']) ? 0.99 : 0.96;
            $state = 'visible'; $reason = 'camera_metadata';
        }
    } else {
        try {
            $media = plus_media_by_id((string) $asset['media_id']);
            if ($media === null) throw new RuntimeException('Managed gallery media is unavailable.');
            $result = ($classifier['handler'])($media, (string) $asset['context_text'], $classifier['settings']);
            if (!is_array($result)) throw new RuntimeException('Photo classifier returned an invalid result.');
            $allowed = ['camera_photo','photo_of_physical_artwork','flat_artwork','screenshot','illustration','document','unknown'];
            $visualClass = (string) ($result['class'] ?? 'unknown');
            $confidence = (float) ($result['confidence'] ?? 0);
            if (!in_array($visualClass, $allowed, true) || $confidence < 0 || $confidence > 1) {
                throw new RuntimeException('Photo classifier returned an invalid result.');
            }
            if (in_array($visualClass, ['camera_photo', 'photo_of_physical_artwork'], true)
                && $confidence >= (!empty($asset['capture_metadata']) ? 0.85 : 0.92)) {
                $state = 'visible';
            } elseif (in_array($visualClass, ['flat_artwork','screenshot','illustration','document'], true) && $confidence >= 0.8) {
                $state = 'excluded';
            }
            $reason = mb_substr(trim((string) ($result['reason'] ?? $visualClass)), 0, 500);
        } catch (Throwable $error) {
            $reason = 'provider_error:' . mb_substr(trim($error->getMessage()), 0, 400);
            personal_photo_gallery_store_classification($assetKey, 'unknown', 0, 'unknown', $reason, $classifierId, $classifierVersion, $sourceFingerprint);
            throw $error;
        }
    }
    personal_photo_gallery_store_classification($assetKey, $visualClass, $confidence, $state, $reason, $classifierId, $classifierVersion, $sourceFingerprint);
    return personal_photo_gallery_classification($assetKey) ?? [];
}

function personal_photo_gallery_store_classification(
    string $assetKey,
    string $visualClass,
    float $confidence,
    string $state,
    string $reason,
    string $classifierId,
    string $classifierVersion,
    string $sourceFingerprint
): void {
    plus_database()->prepare(
        'INSERT INTO plus_photo_gallery_classifications (asset_key,visual_class,confidence,automatic_state,reason,classifier_id,classifier_version,source_fingerprint,updated_at) '
        . 'VALUES (?,?,?,?,?,?,?,?,?) ON CONFLICT(asset_key) DO UPDATE SET visual_class=excluded.visual_class,confidence=excluded.confidence,'
        . 'automatic_state=excluded.automatic_state,reason=excluded.reason,classifier_id=excluded.classifier_id,'
        . 'classifier_version=excluded.classifier_version,source_fingerprint=excluded.source_fingerprint,updated_at=excluded.updated_at'
    )->execute([$assetKey, $visualClass, $confidence, $state, $reason, $classifierId, $classifierVersion, $sourceFingerprint, time()]);
}

function personal_photo_gallery_classification(string $assetKey): ?array
{
    $query = plus_database()->prepare('SELECT * FROM plus_photo_gallery_classifications WHERE asset_key=?');
    $query->execute([$assetKey]);
    $row = $query->fetch();
    return is_array($row) ? $row : null;
}

function personal_photo_gallery_rebuild(): int
{
    $published = [];
    $count = 0;
    foreach (get_all_posts(true, true) as $post) {
        if (($post['status'] ?? '') !== 'published') continue;
        $slug = (string) ($post['slug'] ?? '');
        if ($slug === '') continue;
        $published[] = $slug;
        $count += personal_photo_gallery_sync_post($slug);
        plus_job_heartbeat();
    }
    if ($published === []) {
        plus_database()->exec('DELETE FROM plus_photo_gallery_occurrences');
    } else {
        $placeholders = implode(',', array_fill(0, count($published), '?'));
        plus_database()->prepare('DELETE FROM plus_photo_gallery_occurrences WHERE post_slug NOT IN (' . $placeholders . ')')->execute($published);
    }
    cache_clear();
    return $count;
}

function personal_photo_gallery_schedule_rebuild(): array
{
    return plus_enqueue_job('photo-gallery.rebuild', [], time(), 5, 'photo-gallery-rebuild-' . time() . '-' . bin2hex(random_bytes(4)));
}

function personal_photo_gallery_event_post_saved(array $post, ?string $oldSlug = null, ?string $oldStatus = null): void
{
    $slug = (string) ($post['slug'] ?? '');
    if ($slug === '') return;
    $signature = hash('sha256', json_encode([
        $slug, $oldSlug, $oldStatus, $post['status'] ?? '', $post['date'] ?? '', $post['content'] ?? '',
        $post['title'] ?? '', $post['description'] ?? '', $post['tags'] ?? [],
        $post['feature_image'] ?? '', $post['micropub_photo'] ?? '',
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    plus_enqueue_job('photo-gallery.sync', ['slug' => $slug, 'old_slug' => $oldSlug], time(), 5, 'photo-gallery-sync-' . $signature);
}

function personal_photo_gallery_event_post_deleted(string $slug): void
{
    if ($slug === '') return;
    plus_enqueue_job('photo-gallery.sync', ['slug' => $slug], time(), 5, 'photo-gallery-delete-' . hash('sha256', $slug . ':' . microtime(true)));
}

function personal_photo_gallery_event_configuration_saved(array $config, array $previous): void
{
    if (!personal_photo_gallery_enabled($config)) return;
    if (!personal_photo_gallery_enabled($previous)) {
        personal_photo_gallery_schedule_rebuild();
        return;
    }
    $provider = (string) ($config['plugins']['providers']['media.photo_classification'] ?? '');
    $previousProvider = (string) ($previous['plugins']['providers']['media.photo_classification'] ?? '');
    $settings = (array) ($config['plugins']['settings'][$provider] ?? []);
    $previousSettings = (array) ($previous['plugins']['settings'][$previousProvider] ?? []);
    if ($provider !== $previousProvider || $settings !== $previousSettings) {
        personal_photo_gallery_schedule_rebuild();
    }
}

/** @param array<string,mixed> $payload */
function personal_photo_gallery_sync_job(array $payload): void
{
    personal_photo_gallery_sync_post((string) ($payload['slug'] ?? ''), isset($payload['old_slug']) ? (string) $payload['old_slug'] : null);
}

/** @param array<string,mixed> $payload */
function personal_photo_gallery_rebuild_job(array $payload): void
{
    personal_photo_gallery_rebuild();
}

function personal_photo_gallery_cursor_encode(array $item): string
{
    $json = json_encode([(string) $item['published_at'], (string) $item['asset_key']], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
}

/** @return array{0:string,1:string}|null */
function personal_photo_gallery_cursor_decode(string $cursor): ?array
{
    if ($cursor === '' || strlen($cursor) > 300 || preg_match('/^[A-Za-z0-9_-]+$/', $cursor) !== 1) return null;
    $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
    $value = is_string($decoded) ? json_decode($decoded, true) : null;
    if (!is_array($value) || count($value) !== 2 || !is_string($value[0]) || !is_string($value[1])
        || preg_match('/^[a-f0-9]{32,64}$/', $value[1]) !== 1) return null;
    return [$value[0], $value[1]];
}

/** @return array{items:list<array<string,mixed>>,next_cursor:?string} */
function personal_photo_gallery_page_items(?string $cursor, int $limit): array
{
    $params = [];
    $cursorSql = '';
    if ($cursor !== null && $cursor !== '') {
        $decoded = personal_photo_gallery_cursor_decode($cursor);
        if ($decoded === null) throw new InvalidArgumentException('Invalid gallery cursor.');
        $cursorSql = ' AND (o.published_at < ? OR (o.published_at = ? AND a.asset_key < ?))';
        $params = [$decoded[0], $decoded[0], $decoded[1]];
    }
    $sql = "SELECT a.*,o.post_slug,o.post_title,o.published_at,o.alt_text,o.caption,c.visual_class,c.confidence,c.automatic_state,v.visibility AS override_visibility "
        . "FROM plus_photo_gallery_assets a JOIN plus_photo_gallery_occurrences o ON o.asset_key=a.asset_key "
        . "LEFT JOIN plus_photo_gallery_classifications c ON c.asset_key=a.asset_key "
        . "LEFT JOIN plus_photo_gallery_overrides v ON v.asset_key=a.asset_key "
        . "WHERE COALESCE(v.visibility,c.automatic_state,'unknown')='visible' "
        . "AND NOT EXISTS (SELECT 1 FROM plus_photo_gallery_occurrences newer WHERE newer.asset_key=o.asset_key "
        . "AND (newer.published_at > o.published_at OR (newer.published_at=o.published_at AND newer.post_slug > o.post_slug)))"
        . $cursorSql . ' ORDER BY o.published_at DESC,a.asset_key DESC LIMIT ?';
    $statement = plus_database()->prepare($sql);
    foreach ($params as $index => $value) $statement->bindValue($index + 1, $value, PDO::PARAM_STR);
    $statement->bindValue(count($params) + 1, $limit + 1, PDO::PARAM_INT);
    $statement->execute();
    $items = $statement->fetchAll() ?: [];
    $hasNext = count($items) > $limit;
    if ($hasNext) array_pop($items);
    foreach ($items as &$item) {
        $item['variants'] = json_decode((string) $item['variants_json'], true) ?: [];
    }
    unset($item);
    return ['items' => $items, 'next_cursor' => $hasNext && $items !== [] ? personal_photo_gallery_cursor_encode($items[array_key_last($items)]) : null];
}

/** @param list<array<string,mixed>> $items @return list<list<array<string,mixed>>> */
function personal_photo_gallery_rows(array $items): array
{
    $best = [count($items) => ['cost' => 0.0, 'rows' => []]];
    for ($start = count($items) - 1; $start >= 0; $start--) {
        $ratio = 0.0;
        for ($count = 1; $count <= 3 && $start + $count <= count($items); $count++) {
            $item = $items[$start + $count - 1];
            $ratio += (int) $item['height'] > 0 ? (int) $item['width'] / (int) $item['height'] : 1.0;
            $next = $best[$start + $count];
            $cost = ($ratio - 2.4) ** 2 + ($count === 1 ? 1.5 : 0) + $next['cost'];
            if (!isset($best[$start]) || $cost < $best[$start]['cost']) {
                $best[$start] = ['cost' => $cost, 'rows' => [array_slice($items, $start, $count), ...$next['rows']]];
            }
        }
    }
    return $best[0]['rows'] ?? [];
}

/** @param list<array<string,mixed>> $items */
function personal_photo_gallery_render_items(array $items, bool $firstPage = false): string
{
    $html = '';
    $index = 0;
    foreach (personal_photo_gallery_rows($items) as $rowIndex => $row) {
        $columns = [];
        $ratios = [];
        foreach ($row as $item) {
            $ratio = (int) $item['height'] > 0 ? (int) $item['width'] / (int) $item['height'] : 1.0;
            $ratios[] = $ratio;
            $columns[] = number_format($ratio, 6, '.', '') . 'fr';
        }
        $ratioTotal = max(0.1, array_sum($ratios));
        $html .= '<div class="personal-photo-gallery-row" style="--photo-columns:' . implode(' ', $columns) . '">';
        foreach ($row as $itemIndex => $item) {
            $caption = trim((string) ($item['caption'] ?: $item['alt_text']));
            $alt = trim((string) $item['alt_text']);
            $sourceUrl = base_path() . '/' . rawurlencode((string) $item['post_slug']);
            $variants = (array) $item['variants'];
            ksort($variants, SORT_NUMERIC);
            $srcset = [];
            foreach ($variants as $width => $url) $srcset[] = e((string) $url) . ' ' . (int) $width . 'w';
            $share = $ratios[$itemIndex] / $ratioTotal;
            $viewportWidth = number_format($share * 100, 2, '.', '');
            $maximumWidth = number_format($share * 96, 2, '.', '');
            $sizes = '(max-width: 720px) calc(' . $viewportWidth . 'vw - 0.5rem), min(' . $maximumWidth . 'rem, calc(' . $viewportWidth . 'vw - 1rem))';
            $eager = $firstPage && $rowIndex === 0;
            $priority = $eager ? ' fetchpriority="high"' : '';
            $html .= '<figure class="personal-photo-gallery-item"><a class="personal-photo-gallery-link" href="' . e((string) $item['url']) . '" data-pb-lightbox data-alt="' . e($alt) . '" data-caption="' . e($caption) . '" data-source-url="' . e($sourceUrl) . '" data-source-title="' . e((string) $item['post_title']) . '">';
            $html .= '<img src="' . e((string) $item['preview_url']) . '" alt="' . e($alt) . '" width="' . max(1, (int) $item['width']) . '" height="' . max(1, (int) $item['height']) . '" loading="' . ($eager ? 'eager' : 'lazy') . '" decoding="async"' . $priority;
            if ($srcset !== []) $html .= ' srcset="' . implode(', ', $srcset) . '" sizes="' . e($sizes) . '"';
            $html .= '></a></figure>';
            $index++;
        }
        $html .= '</div>';
    }
    return $html;
}

function personal_photo_gallery_route_handler(string $requestPath): bool
{
    $settings = personal_photo_gallery_settings();
    if ($requestPath !== '/' . $settings['route']) return false;
    try {
        $galleryPage = personal_photo_gallery_page_items(isset($_GET['cursor']) ? (string) $_GET['cursor'] : null, $settings['page_size']);
    } catch (InvalidArgumentException) {
        http_response_code(400);
        $galleryPage = ['items' => [], 'next_cursor' => null];
    }
    $html = personal_photo_gallery_render_items($galleryPage['items'], empty($_GET['cursor']));
    $nextUrl = $galleryPage['next_cursor'] === null ? null : base_path() . '/' . $settings['route'] . '?cursor=' . rawurlencode($galleryPage['next_cursor']);
    if (($_GET['partial'] ?? '') === '1') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['html' => $html, 'next_url' => $nextUrl], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return true;
    }
    $config = load_config();
    $fontStack = font_stack_css($config['theme']['font_stack'] ?? 'sans');
    $pageTitle = $settings['title'];
    $metaDescription = t('frontend.photo_gallery_description');
    $post = null;
    $page = null;
    if ($__p = find_include('header')) require $__p;
    render_masthead_layout($config, []);
    has_gallery_rendered(true);
    echo '<main class="personal-photo-gallery" data-photo-gallery data-loaded-label="' . e(t('frontend.photo_gallery_loaded')) . '"><header class="personal-photo-gallery-heading"><h1>' . e($settings['title']) . '</h1><p>' . e(t('frontend.photo_gallery_intro')) . '</p></header>';
    echo '<div class="personal-photo-gallery-items" data-photo-gallery-items>' . $html . '</div>';
    if ($galleryPage['items'] === []) echo '<p class="personal-photo-gallery-empty">' . e(t('frontend.photo_gallery_empty')) . '</p>';
    echo '<nav class="personal-photo-gallery-pagination" aria-label="' . e(t('frontend.photo_gallery_pagination')) . '">';
    if ($nextUrl !== null) echo '<a class="button" href="' . e($nextUrl) . '" data-photo-gallery-more data-label="' . e(t('frontend.photo_gallery_more')) . '">' . e(t('frontend.photo_gallery_more')) . '</a>';
    echo '<span class="personal-photo-gallery-status" data-photo-gallery-status aria-live="polite"></span></nav></main>';
    render_footer_layout($config, []);
    echo '<script type="module" src="' . e(base_path()) . '/assets/js/photo-gallery.js"></script></body></html>';
    return true;
}

function personal_photo_gallery_set_visibility(string $assetKey, ?string $visibility): void
{
    if (preg_match('/^[a-f0-9]{32,64}$/', $assetKey) !== 1 || !in_array($visibility, [null, 'visible', 'hidden'], true)) {
        throw new InvalidArgumentException('Invalid gallery visibility.');
    }
    $exists = plus_database()->prepare('SELECT 1 FROM plus_photo_gallery_assets WHERE asset_key=?');
    $exists->execute([$assetKey]);
    if ($exists->fetchColumn() === false) throw new InvalidArgumentException('Unknown gallery image.');
    if ($visibility === null) {
        plus_database()->prepare('DELETE FROM plus_photo_gallery_overrides WHERE asset_key=?')->execute([$assetKey]);
    } else {
        plus_database()->prepare(
            'INSERT INTO plus_photo_gallery_overrides (asset_key,visibility,updated_at) VALUES (?,?,?) '
            . 'ON CONFLICT(asset_key) DO UPDATE SET visibility=excluded.visibility,updated_at=excluded.updated_at'
        )->execute([$assetKey, $visibility, time()]);
    }
    cache_clear();
}

/** @return list<array<string,mixed>> */
function personal_photo_gallery_admin_items(string $filter = 'all', string $search = '', int $limit = 30, int $offset = 0): array
{
    $state = "COALESCE(v.visibility,c.automatic_state,'unknown')";
    $where = ["NOT EXISTS (SELECT 1 FROM plus_photo_gallery_occurrences newer WHERE newer.asset_key=o.asset_key AND (newer.published_at > o.published_at OR (newer.published_at=o.published_at AND newer.post_slug > o.post_slug)))"];
    $params = [];
    if ($filter === 'visible') $where[] = $state . "='visible'";
    elseif ($filter === 'hidden') $where[] = "v.visibility='hidden'";
    elseif ($filter === 'excluded') $where[] = "v.visibility IS NULL AND c.automatic_state='excluded'";
    elseif ($filter === 'unknown') $where[] = "v.visibility IS NULL AND COALESCE(c.automatic_state,'unknown')='unknown'";
    if ($search !== '') {
        $where[] = '(o.post_title LIKE ? ESCAPE \'\\\' OR o.alt_text LIKE ? ESCAPE \'\\\' OR o.caption LIKE ? ESCAPE \'\\\')';
        $needle = '%' . str_replace(['\\','%','_'], ['\\\\','\\%','\\_'], $search) . '%';
        $params = [$needle, $needle, $needle];
    }
    $sql = 'SELECT a.*,o.post_slug,o.post_title,o.published_at,o.alt_text,o.caption,c.visual_class,c.confidence,c.automatic_state,c.reason,'
        . 'v.visibility AS override_visibility,' . $state . ' AS effective_state FROM plus_photo_gallery_assets a '
        . 'JOIN plus_photo_gallery_occurrences o ON o.asset_key=a.asset_key '
        . 'LEFT JOIN plus_photo_gallery_classifications c ON c.asset_key=a.asset_key '
        . 'LEFT JOIN plus_photo_gallery_overrides v ON v.asset_key=a.asset_key WHERE ' . implode(' AND ', $where)
        . ' ORDER BY o.published_at DESC,a.asset_key DESC LIMIT ? OFFSET ?';
    $query = plus_database()->prepare($sql);
    foreach ($params as $index => $value) $query->bindValue($index + 1, $value, PDO::PARAM_STR);
    $query->bindValue(count($params) + 1, $limit, PDO::PARAM_INT);
    $query->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
    $query->execute();
    return $query->fetchAll() ?: [];
}

/** @return array<string,int> */
function personal_photo_gallery_stats(): array
{
    $rows = plus_database()->query(
        "SELECT CASE WHEN v.visibility='hidden' THEN 'hidden' WHEN v.visibility='visible' THEN 'visible' "
        . "ELSE COALESCE(c.automatic_state,'unknown') END AS state,COUNT(*) AS amount "
        . "FROM plus_photo_gallery_assets a LEFT JOIN plus_photo_gallery_classifications c ON c.asset_key=a.asset_key "
        . "LEFT JOIN plus_photo_gallery_overrides v ON v.asset_key=a.asset_key "
        . "WHERE EXISTS (SELECT 1 FROM plus_photo_gallery_occurrences o WHERE o.asset_key=a.asset_key) GROUP BY state"
    )->fetchAll() ?: [];
    $stats = ['visible' => 0, 'hidden' => 0, 'excluded' => 0, 'unknown' => 0];
    foreach ($rows as $row) $stats[(string) $row['state']] = (int) $row['amount'];
    return $stats;
}

plus_register_job_handler('photo-gallery.sync', 'personal_photo_gallery_sync_job');
plus_register_job_handler('photo-gallery.rebuild', 'personal_photo_gallery_rebuild_job');
plus_register_capability('photo.gallery', 1, 0);

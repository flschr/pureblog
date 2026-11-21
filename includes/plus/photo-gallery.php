<?php

declare(strict_types=1);

const PERSONAL_PHOTO_GALLERY_RULE_VERSION = '2';

function personal_photo_gallery_config(): array
{
    return $GLOBALS['_pureblog_photo_gallery_config'] ?? load_config();
}

plus_register_migration('0013_personal_photo_gallery', static function (PDO $database): void {
    $createTables = static function (PDO $connection, string $suffix = ''): void {
        $assets = 'plus_photo_gallery_assets' . $suffix;
        $occurrences = 'plus_photo_gallery_occurrences' . $suffix;
        $classifications = 'plus_photo_gallery_classifications' . $suffix;
        $overrides = 'plus_photo_gallery_overrides' . $suffix;
        $connection->exec("CREATE TABLE {$assets} (asset_key TEXT PRIMARY KEY)");
        $connection->exec(
            "CREATE TABLE {$occurrences} ("
            . 'post_slug TEXT NOT NULL,'
            . "asset_key TEXT NOT NULL REFERENCES {$assets}(asset_key) ON DELETE CASCADE,"
            . 'media_id TEXT NOT NULL,'
            . 'position INTEGER NOT NULL,'
            . "alt_text TEXT NOT NULL DEFAULT '',"
            . "caption TEXT NOT NULL DEFAULT '',"
            . 'post_title TEXT NOT NULL,'
            . 'published_at TEXT NOT NULL,'
            . "context_text TEXT NOT NULL DEFAULT '',"
            . 'PRIMARY KEY (post_slug,asset_key))'
        );
        $connection->exec(
            "CREATE TABLE {$classifications} ("
            . "asset_key TEXT PRIMARY KEY REFERENCES {$assets}(asset_key) ON DELETE CASCADE,"
            . "visual_class TEXT NOT NULL CHECK (visual_class IN ('camera_photo','photo_of_physical_artwork','flat_artwork','screenshot','illustration','document','unknown')),"
            . 'confidence REAL NOT NULL DEFAULT 0 CHECK (confidence >= 0 AND confidence <= 1),'
            . "automatic_state TEXT NOT NULL CHECK (automatic_state IN ('visible','excluded','unknown')),"
            . "reason TEXT NOT NULL DEFAULT '',"
            . "classifier_id TEXT NOT NULL DEFAULT '',"
            . "classifier_version TEXT NOT NULL DEFAULT '',"
            . 'input_signature TEXT NOT NULL,'
            . 'updated_at INTEGER NOT NULL)'
        );
        $connection->exec(
            "CREATE TABLE {$overrides} ("
            . "asset_key TEXT PRIMARY KEY REFERENCES {$assets}(asset_key) ON DELETE CASCADE,"
            . "visibility TEXT NOT NULL CHECK (visibility IN ('visible','hidden')),"
            . 'updated_at INTEGER NOT NULL)'
        );
    };
    $galleryExists = (int) $database->query(
        "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='plus_photo_gallery_assets'"
    )->fetchColumn() === 1;
    if (!$galleryExists) {
        $createTables($database);
    } else {
        $assetColumns = array_column($database->query('PRAGMA table_info(plus_photo_gallery_assets)')->fetchAll(), 'name');
        if (in_array('media_id', $assetColumns, true)) {
            $createTables($database, '_v2');
            $database->exec('INSERT INTO plus_photo_gallery_assets_v2 (asset_key) SELECT asset_key FROM plus_photo_gallery_assets');
            $database->exec(
                'INSERT INTO plus_photo_gallery_occurrences_v2 '
                . '(post_slug,asset_key,media_id,position,alt_text,caption,post_title,published_at,context_text) '
                . 'SELECT o.post_slug,o.asset_key,a.media_id,o.position,o.alt_text,o.caption,o.post_title,o.published_at,o.context_text '
                . 'FROM plus_photo_gallery_occurrences o JOIN plus_photo_gallery_assets a ON a.asset_key=o.asset_key'
            );
            $database->exec(
                'INSERT INTO plus_photo_gallery_classifications_v2 '
                . '(asset_key,visual_class,confidence,automatic_state,reason,classifier_id,classifier_version,input_signature,updated_at) '
                . 'SELECT asset_key,visual_class,confidence,automatic_state,reason,classifier_id,classifier_version,source_fingerprint,updated_at '
                . 'FROM plus_photo_gallery_classifications'
            );
            $database->exec(
                'INSERT INTO plus_photo_gallery_overrides_v2 (asset_key,visibility,updated_at) '
                . 'SELECT asset_key,visibility,updated_at FROM plus_photo_gallery_overrides'
            );
            $database->exec('DROP TABLE plus_photo_gallery_occurrences');
            $database->exec('DROP TABLE plus_photo_gallery_classifications');
            $database->exec('DROP TABLE plus_photo_gallery_overrides');
            $database->exec('DROP TABLE plus_photo_gallery_assets');
            $database->exec('ALTER TABLE plus_photo_gallery_assets_v2 RENAME TO plus_photo_gallery_assets');
            $database->exec('ALTER TABLE plus_photo_gallery_occurrences_v2 RENAME TO plus_photo_gallery_occurrences');
            $database->exec('ALTER TABLE plus_photo_gallery_classifications_v2 RENAME TO plus_photo_gallery_classifications');
            $database->exec('ALTER TABLE plus_photo_gallery_overrides_v2 RENAME TO plus_photo_gallery_overrides');
        }
    }
    $database->exec('CREATE INDEX IF NOT EXISTS plus_photo_gallery_occurrence_date_idx ON plus_photo_gallery_occurrences (published_at DESC,asset_key DESC)');
    $database->exec('CREATE INDEX IF NOT EXISTS plus_photo_gallery_occurrence_asset_idx ON plus_photo_gallery_occurrences (asset_key,published_at DESC,post_slug DESC)');
    $database->exec('DROP VIEW IF EXISTS plus_photo_gallery_latest_occurrences');
    $database->exec(
        "CREATE VIEW plus_photo_gallery_latest_occurrences AS SELECT o.* FROM plus_photo_gallery_occurrences o "
        . "JOIN plus_media current_media ON current_media.id=o.media_id AND current_media.delete_state='active' "
        . "WHERE NOT EXISTS (SELECT 1 FROM plus_photo_gallery_occurrences newer JOIN plus_media newer_media "
        . "ON newer_media.id=newer.media_id AND newer_media.delete_state='active' WHERE newer.asset_key=o.asset_key "
        . "AND (newer.published_at>o.published_at OR (newer.published_at=o.published_at AND newer.post_slug>o.post_slug)))"
    );
});

plus_register_migration('0015_personal_photo_gallery_sources', static function (PDO $database): void {
    $columns = array_column($database->query('PRAGMA table_info(plus_photo_gallery_occurrences)')->fetchAll(), 'name');
    if (in_array('source_type', $columns, true)) return;
    $database->exec('DROP VIEW IF EXISTS plus_photo_gallery_latest_occurrences');
    $database->exec(
        "CREATE TABLE plus_photo_gallery_occurrences_v3 ("
        . 'source_type TEXT NOT NULL CHECK (source_type IN (\'post\',\'social\')),'
        . 'source_id TEXT NOT NULL,source_url TEXT NOT NULL DEFAULT \'\','
        . 'asset_key TEXT NOT NULL REFERENCES plus_photo_gallery_assets(asset_key) ON DELETE CASCADE,'
        . 'media_id TEXT NOT NULL,position INTEGER NOT NULL,'
        . "alt_text TEXT NOT NULL DEFAULT '',caption TEXT NOT NULL DEFAULT '',"
        . "source_title TEXT NOT NULL DEFAULT '',published_at TEXT NOT NULL,context_text TEXT NOT NULL DEFAULT '',"
        . 'PRIMARY KEY (source_type,source_id,asset_key))'
    );
    $database->exec(
        'INSERT INTO plus_photo_gallery_occurrences_v3 '
        . '(source_type,source_id,source_url,asset_key,media_id,position,alt_text,caption,source_title,published_at,context_text) '
        . "SELECT 'post',post_slug,'',asset_key,media_id,position,alt_text,caption,post_title,published_at,context_text "
        . 'FROM plus_photo_gallery_occurrences'
    );
    $database->exec('DROP TABLE plus_photo_gallery_occurrences');
    $database->exec('ALTER TABLE plus_photo_gallery_occurrences_v3 RENAME TO plus_photo_gallery_occurrences');
    $database->exec('CREATE INDEX plus_photo_gallery_occurrence_date_idx ON plus_photo_gallery_occurrences (published_at DESC,asset_key DESC)');
    $database->exec('CREATE INDEX plus_photo_gallery_occurrence_asset_idx ON plus_photo_gallery_occurrences (asset_key,published_at DESC,source_type DESC,source_id DESC)');
    $database->exec(
        "CREATE VIEW plus_photo_gallery_latest_occurrences AS SELECT o.* FROM plus_photo_gallery_occurrences o "
        . "JOIN plus_media current_media ON current_media.id=o.media_id AND current_media.delete_state='active' "
        . "WHERE NOT EXISTS (SELECT 1 FROM plus_photo_gallery_occurrences newer JOIN plus_media newer_media "
        . "ON newer_media.id=newer.media_id AND newer_media.delete_state='active' WHERE newer.asset_key=o.asset_key "
        . "AND (newer.published_at>o.published_at OR (newer.published_at=o.published_at AND (newer.source_type>o.source_type "
        . "OR (newer.source_type=o.source_type AND newer.source_id>o.source_id)))))"
    );
});

/** @return array{route:string,title:string,page_size:int} */
function personal_photo_gallery_settings(?array $config = null): array
{
    $config ??= personal_photo_gallery_config();
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
    return plus_plugin_is_enabled('personal-photo-gallery', $config ?? personal_photo_gallery_config());
}

/** @return list<string> */
function personal_photo_gallery_positive_tags(?array $config = null): array
{
    $saved = plus_plugin_settings('personal-photo-gallery', $config ?? personal_photo_gallery_config());
    $raw = (string) ($saved['positive_tags'] ?? '');
    $tags = [];
    foreach (preg_split('/[,;\n]+/u', $raw) ?: [] as $tag) {
        $tag = mb_strtolower(trim($tag));
        if ($tag === '' || mb_strlen($tag) > 80) continue;
        $tags[$tag] = true;
        if (count($tags) >= 50) break;
    }
    return array_keys($tags);
}

function personal_photo_gallery_job_enabled(): bool
{
    if (isset($GLOBALS['_pureblog_photo_gallery_config'])) {
        return personal_photo_gallery_enabled($GLOBALS['_pureblog_photo_gallery_config']);
    }
    return personal_photo_gallery_enabled(load_config(true));
}

/** @return list<array{url:string,alt:string,caption:string}> */
function personal_photo_gallery_html_images(string $html): array
{
    if ($html === '' || !class_exists(DOMDocument::class)) return [];
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    try {
        $loaded = $document->loadHTML(
            '<!doctype html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        if (!$loaded) return [];
        $xpath = new DOMXPath($document);
        $nodes = $xpath->query('//body//img[not(ancestor::template)]');
        if ($nodes === false) return [];
        $images = [];
        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) continue;
            $images[] = [
                'url' => $node->getAttribute('src'),
                'alt' => $node->getAttribute('alt'),
                'caption' => $node->getAttribute('title'),
            ];
        }
        return $images;
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
}

/** @return list<array{url:string,alt:string,caption:string,position:int}> */
function personal_photo_gallery_post_candidates(array $post): array
{
    $items = [];
    $seen = [];
    $append = static function (string $url, string $alt = '', string $caption = '') use (&$items, &$seen): void {
        $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($url === '') return;
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) return;
        $alt = mb_substr(trim(preg_replace('/\s+/u', ' ', $alt) ?? ''), 0, 1500);
        $caption = mb_substr(trim(preg_replace('/\s+/u', ' ', $caption) ?? ''), 0, 1500);
        if (isset($seen[$url])) {
            $index = $seen[$url];
            if ($items[$index]['alt'] === '' && $alt !== '') $items[$index]['alt'] = $alt;
            if ($items[$index]['caption'] === '' && $caption !== '') $items[$index]['caption'] = $caption;
            return;
        }
        $seen[$url] = count($items);
        $items[] = [
            'url' => $url,
            'alt' => $alt,
            'caption' => $caption,
            'position' => count($items),
        ];
    };

    $feature = trim((string) ($post['feature_image'] ?? ''));
    if ($feature !== '') $append($feature);

    $photoJson = trim((string) ($post['micropub_photo'] ?? ''));
    $photos = $photoJson !== '' ? json_decode($photoJson, true) : [];
    if (is_array($photos)) foreach ($photos as $photo) {
        $append(
            is_string($photo) ? $photo : (is_array($photo) ? (string) ($photo['value'] ?? '') : ''),
            is_array($photo) ? (string) ($photo['alt'] ?? '') : ''
        );
    }

    $content = ($post['micropub_content_format'] ?? 'markdown') === 'text'
        ? ''
        : render_markdown_fragment((string) ($post['content'] ?? ''));
    foreach (personal_photo_gallery_html_images($content) as $image) {
        $append($image['url'], $image['alt'], $image['caption']);
    }
    return $items;
}

function personal_photo_gallery_media_fingerprint(array $media): string
{
    $fingerprint = trim((string) ($media['source_fingerprint'] ?? ''));
    if (preg_match('/^[a-f0-9]{64}$/', $fingerprint) === 1) return $fingerprint;
    $fingerprint = plus_media_source_fingerprint((string) ($media['local_source'] ?? ''));
    if ($fingerprint !== '') {
        plus_database()->prepare('UPDATE plus_media SET source_fingerprint=? WHERE id=?')->execute([$fingerprint, $media['id']]);
    }
    return $fingerprint;
}

function personal_photo_gallery_media_visual_fingerprint(array $media): string
{
    $fingerprint = trim((string) ($media['visual_fingerprint'] ?? ''));
    if (preg_match('/^[a-f0-9]{128}$/', $fingerprint) === 1) return $fingerprint;
    $fingerprint = plus_media_visual_fingerprint(
        (string) ($media['local_source'] ?? ''),
        (string) ($media['mime_type'] ?? '')
    );
    if ($fingerprint !== '') {
        plus_database()->prepare('UPDATE plus_media SET visual_fingerprint=? WHERE id=?')->execute([$fingerprint, $media['id']]);
    }
    return $fingerprint;
}

function personal_photo_gallery_visual_distance(string $left, string $right): int
{
    if (strlen($left) !== 128 || strlen($right) !== 128) return PHP_INT_MAX;
    static $bits = [0, 1, 1, 2, 1, 2, 2, 3, 1, 2, 2, 3, 2, 3, 3, 4];
    $distance = 0;
    for ($index = 0; $index < 128; $index++) {
        $distance += $bits[hexdec($left[$index]) ^ hexdec($right[$index])];
        if ($distance > 12) return $distance;
    }
    return $distance;
}

function personal_photo_gallery_same_shape(array $left, array $right): bool
{
    $leftWidth = (int) ($left['width'] ?? 0);
    $leftHeight = (int) ($left['height'] ?? 0);
    $rightWidth = (int) ($right['width'] ?? 0);
    $rightHeight = (int) ($right['height'] ?? 0);
    if ($leftWidth < 1 || $leftHeight < 1 || $rightWidth < 1 || $rightHeight < 1) return false;
    $cross = abs($leftWidth * $rightHeight - $rightWidth * $leftHeight);
    return $cross / max(1, $leftWidth * $rightHeight) <= 0.003;
}

/** @return array{asset_key:string,merge_asset_keys:list<string>} */
function personal_photo_gallery_resolve_asset(array $media, string $exactFingerprint): array
{
    $fallback = $exactFingerprint !== '' ? $exactFingerprint : (string) $media['id'];
    $visual = personal_photo_gallery_media_visual_fingerprint($media);
    if ($visual === '') return ['asset_key' => $fallback, 'merge_asset_keys' => []];

    $rows = plus_database()->query(
        "SELECT DISTINCT o.asset_key,o.source_type,o.published_at,m.width,m.height,m.visual_fingerprint "
        . "FROM plus_photo_gallery_occurrences o JOIN plus_media m ON m.id=o.media_id "
        . "WHERE m.delete_state='active' AND m.visual_fingerprint<>'' "
        . "ORDER BY CASE WHEN o.source_type='post' THEN 0 ELSE 1 END,o.published_at,o.asset_key"
    )->fetchAll();
    $matches = [];
    foreach ($rows as $row) {
        if (!is_array($row) || !personal_photo_gallery_same_shape($media, $row)) continue;
        if (personal_photo_gallery_visual_distance($visual, (string) $row['visual_fingerprint']) > 12) continue;
        $matches[] = (string) $row['asset_key'];
    }
    if ($matches === []) return ['asset_key' => $fallback, 'merge_asset_keys' => []];
    $canonical = $matches[0];
    return [
        'asset_key' => $canonical,
        'merge_asset_keys' => array_values(array_unique(array_filter(
            array_merge($matches, [$fallback]),
            static fn(string $key): bool => $key !== $canonical
        ))),
    ];
}

/** @param list<string> $aliases */
function personal_photo_gallery_merge_assets(PDO $database, string $canonical, array $aliases): void
{
    $aliases = array_values(array_unique(array_filter($aliases, static fn(string $key): bool => $key !== $canonical)));
    if ($aliases === []) return;
    $database->prepare('INSERT OR IGNORE INTO plus_photo_gallery_assets (asset_key) VALUES (?)')->execute([$canonical]);
    $selectVisibility = $database->prepare(
        'SELECT v.visibility,v.updated_at,c.automatic_state FROM plus_photo_gallery_assets a '
        . 'LEFT JOIN plus_photo_gallery_overrides v ON v.asset_key=a.asset_key '
        . 'LEFT JOIN plus_photo_gallery_classifications c ON c.asset_key=a.asset_key WHERE a.asset_key=?'
    );
    $anyVisible = false;
    $anyOverride = false;
    $canonicalAutomatic = '';
    $latestOverride = 0;
    foreach (array_merge([$canonical], $aliases) as $assetKey) {
        $selectVisibility->execute([$assetKey]);
        $state = $selectVisibility->fetch();
        if (!is_array($state)) continue;
        $override = (string) ($state['visibility'] ?? '');
        $automatic = (string) ($state['automatic_state'] ?? '');
        if ($override !== '') $anyOverride = true;
        if ($assetKey === $canonical) $canonicalAutomatic = $automatic;
        if ($override === 'visible' || ($override === '' && $automatic === 'visible')) $anyVisible = true;
        $latestOverride = max($latestOverride, (int) ($state['updated_at'] ?? 0));
    }
    $desiredOverride = !$anyOverride
        ? null
        : ($anyVisible ? ($canonicalAutomatic === 'visible' ? null : 'visible') : 'hidden');
    if ($desiredOverride === null) {
        $database->prepare('DELETE FROM plus_photo_gallery_overrides WHERE asset_key=?')->execute([$canonical]);
    } else {
        $database->prepare(
            'INSERT INTO plus_photo_gallery_overrides (asset_key,visibility,updated_at) VALUES (?,?,?) '
            . 'ON CONFLICT(asset_key) DO UPDATE SET visibility=excluded.visibility,updated_at=excluded.updated_at'
        )->execute([$canonical, $desiredOverride, max($latestOverride, time())]);
    }
    $move = $database->prepare(
        'INSERT OR IGNORE INTO plus_photo_gallery_occurrences '
        . '(source_type,source_id,source_url,asset_key,media_id,position,alt_text,caption,source_title,published_at,context_text) '
        . 'SELECT source_type,source_id,source_url,?,media_id,position,alt_text,caption,source_title,published_at,context_text '
        . 'FROM plus_photo_gallery_occurrences WHERE asset_key=?'
    );
    $deleteOccurrences = $database->prepare('DELETE FROM plus_photo_gallery_occurrences WHERE asset_key=?');
    $deleteAsset = $database->prepare('DELETE FROM plus_photo_gallery_assets WHERE asset_key=?');
    foreach ($aliases as $alias) {
        $move->execute([$canonical, $alias]);
        $deleteOccurrences->execute([$alias]);
        $deleteAsset->execute([$alias]);
    }
}

/** @return array<string,array<string,mixed>> */
function personal_photo_gallery_prepare_candidates(array $post): array
{
    $prepared = [];
    foreach (personal_photo_gallery_post_candidates($post) as $candidate) {
        $media = plus_media_by_url($candidate['url']);
        if ($media === null || !str_starts_with((string) ($media['mime_type'] ?? ''), 'image/')) continue;
        $fingerprint = personal_photo_gallery_media_fingerprint($media);
        $identity = personal_photo_gallery_resolve_asset($media, $fingerprint);
        $assetKey = $identity['asset_key'];
        if (isset($prepared[$assetKey])) continue;
        $context = implode("\n", [
            'Title: ' . (string) ($post['title'] ?? ''),
            'Description: ' . (string) ($post['description'] ?? ''),
            'Tags: ' . implode(', ', array_map('strval', (array) ($post['tags'] ?? []))),
            'Image alt: ' . $candidate['alt'],
            'Image caption: ' . $candidate['caption'],
        ]);
        $prepared[$assetKey] = [
            'candidate' => $candidate, 'media_id' => (string) $media['id'],
            'asset_key' => $assetKey, 'merge_asset_keys' => $identity['merge_asset_keys'],
            'context' => mb_substr($context, 0, 2500),
        ];
    }
    return $prepared;
}

function personal_photo_gallery_prune_orphan_assets(PDO $database): void
{
    $database->exec(
        'DELETE FROM plus_photo_gallery_assets WHERE NOT EXISTS ('
        . 'SELECT 1 FROM plus_photo_gallery_occurrences o WHERE o.asset_key=plus_photo_gallery_assets.asset_key) '
        . 'AND NOT EXISTS (SELECT 1 FROM plus_photo_gallery_overrides v WHERE v.asset_key=plus_photo_gallery_assets.asset_key)'
    );
}

/** @param list<string> $assetKeys */
function personal_photo_gallery_classify_assets(array $assetKeys): void
{
    $database = plus_database();
    $classificationError = null;
    $hasOccurrence = $database->prepare(
        "SELECT 1 FROM plus_photo_gallery_occurrences o JOIN plus_media m ON m.id=o.media_id "
        . "WHERE o.asset_key=? AND m.delete_state='active' LIMIT 1"
    );
    foreach (array_values(array_unique($assetKeys)) as $assetKey) {
        if (!personal_photo_gallery_job_enabled()) break;
        $hasOccurrence->execute([$assetKey]);
        if ($hasOccurrence->fetchColumn() === false) continue;
        plus_job_heartbeat();
        try {
            personal_photo_gallery_classify($assetKey);
        } catch (Throwable $error) {
            $classificationError ??= $error;
        }
        plus_job_heartbeat();
    }
    if ($classificationError !== null) throw $classificationError;
}

function personal_photo_gallery_sync_post(
    string $slug,
    ?string $oldSlug = null,
    bool $invalidateCache = true,
    ?array $post = null,
    bool $classify = true
): int
{
    $database = plus_database();
    $post ??= get_post_by_slug($slug, true);
    $preparedCandidates = [];
    if (is_array($post) && ($post['status'] ?? '') === 'published') {
        $preparedCandidates = personal_photo_gallery_prepare_candidates($post);
    }
    plus_begin_immediate($database);
    $assetKeys = [];
    try {
        $existingAssets = $database->prepare("SELECT asset_key FROM plus_photo_gallery_occurrences WHERE source_type='post' AND source_id=?");
        foreach (array_unique(array_filter([$slug, $oldSlug], static fn(mixed $value): bool => is_string($value) && $value !== '')) as $affectedSlug) {
            $existingAssets->execute([$affectedSlug]);
            foreach ($existingAssets->fetchAll(PDO::FETCH_COLUMN) as $assetKey) $assetKeys[(string) $assetKey] = true;
        }
        if ($oldSlug !== null && $oldSlug !== '' && $oldSlug !== $slug) {
            $database->prepare("DELETE FROM plus_photo_gallery_occurrences WHERE source_type='post' AND source_id=?")->execute([$oldSlug]);
        }
        $database->prepare("DELETE FROM plus_photo_gallery_occurrences WHERE source_type='post' AND source_id=?")->execute([$slug]);
        if ($preparedCandidates !== []) {
            $insertAsset = $database->prepare(
                'INSERT OR IGNORE INTO plus_photo_gallery_assets (asset_key) VALUES (?)'
            );
            $insertOccurrence = $database->prepare(
                'INSERT INTO plus_photo_gallery_occurrences '
                . '(source_type,source_id,source_url,asset_key,media_id,position,alt_text,caption,source_title,published_at,context_text) '
                . 'VALUES (?,?,?,?,?,?,?,?,?,?,?)'
            );
            foreach ($preparedCandidates as $prepared) {
                $candidate = $prepared['candidate'];
                $assetKey = (string) $prepared['asset_key'];
                personal_photo_gallery_merge_assets($database, $assetKey, $prepared['merge_asset_keys']);
                $insertAsset->execute([$assetKey]);
                $insertOccurrence->execute([
                    'post', $slug, '', $assetKey, $prepared['media_id'], $candidate['position'], $candidate['alt'], $candidate['caption'],
                    (string) ($post['title'] ?? ''), (string) ($post['date'] ?? ''), $prepared['context'],
                ]);
                $assetKeys[$assetKey] = true;
            }
        }
        personal_photo_gallery_prune_orphan_assets($database);
        $database->exec('COMMIT');
    } catch (Throwable $error) {
        if ($database->inTransaction()) $database->exec('ROLLBACK');
        throw $error;
    }

    try {
        if ($classify) personal_photo_gallery_classify_assets(array_keys($assetKeys));
    } finally {
        if ($invalidateCache) cache_clear();
    }
    return count($assetKeys);
}

/** @return array<string,array<string,mixed>> */
function personal_photo_gallery_prepare_social_candidates(array $status, array $archive): array
{
    $accountId = trim((string) ($archive['account_id'] ?? ''));
    if ($accountId === '' || !hash_equals($accountId, trim((string) ($status['author_id'] ?? '')))
        || !in_array((string) ($status['visibility'] ?? ''), ['public', 'unlisted'], true)) return [];
    $remoteId = trim((string) ($status['remote_id'] ?? ''));
    $sourceUrl = trim((string) ($status['url'] ?? ''));
    $sourceParts = parse_url($sourceUrl);
    if ($remoteId === '' || !is_array($sourceParts)
        || !in_array(strtolower((string) ($sourceParts['scheme'] ?? '')), ['http', 'https'], true)) return [];
    $mediaItems = json_decode((string) ($status['media_json'] ?? '[]'), true);
    if (!is_array($mediaItems)) return [];
    $content = trim(preg_replace('/\s+/u', ' ', implode(' ', [
        (string) ($status['spoiler_text'] ?? ''), (string) ($status['content_text'] ?? ''),
    ])) ?? '');
    $accountName = trim((string) ($archive['account_name'] ?? ''));
    $sourceTitle = $content !== '' ? mb_substr($content, 0, 200) : ($accountName !== '' ? '@' . $accountName : 'Social post');
    $prepared = [];
    foreach ($mediaItems as $position => $item) {
        if (!is_array($item) || (string) ($item['type'] ?? '') !== 'image') continue;
        $localUrl = trim((string) ($item['url'] ?? ''));
        $media = $localUrl !== '' ? plus_media_by_url($localUrl) : null;
        if ($media === null || !str_starts_with((string) ($media['mime_type'] ?? ''), 'image/')) continue;
        $fingerprint = personal_photo_gallery_media_fingerprint($media);
        $identity = personal_photo_gallery_resolve_asset($media, $fingerprint);
        $assetKey = $identity['asset_key'];
        if (isset($prepared[$assetKey])) continue;
        $alt = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($item['alt'] ?? '')) ?? ''), 0, 1500);
        $context = implode("\n", [
            'Title: ' . ($accountName !== '' ? 'Social post by @' . $accountName : 'Social post'),
            'Description: ' . $content,
            'Tags: ',
            'Image alt: ' . $alt,
            'Image caption: ',
        ]);
        $prepared[$assetKey] = [
            'asset_key' => $assetKey,
            'merge_asset_keys' => $identity['merge_asset_keys'],
            'media_id' => (string) $media['id'],
            'position' => (int) $position,
            'alt' => $alt,
            'source_id' => (int) $archive['id'] . ':' . $remoteId,
            'source_url' => $sourceUrl,
            'source_title' => $sourceTitle,
            'published_at' => (string) ($status['published_at'] ?? ''),
            'context' => mb_substr($context, 0, 2500),
        ];
    }
    return $prepared;
}

function personal_photo_gallery_sync_social_archive(
    int $archiveId,
    bool $scheduleClassifications = true,
    bool $invalidateCache = true
): int {
    if ($archiveId <= 0 || !personal_photo_gallery_job_enabled()) return 0;
    $database = plus_database();
    $archiveQuery = $database->prepare('SELECT * FROM plus_social_archives WHERE id=?');
    $archiveQuery->execute([$archiveId]);
    $archive = $archiveQuery->fetch();
    if (!is_array($archive) || trim((string) ($archive['account_id'] ?? '')) === '') return 0;
    $statuses = $database->prepare(
        "SELECT * FROM plus_social_statuses WHERE archive_id=? AND author_id=? AND visibility IN ('public','unlisted') "
        . 'ORDER BY published_at,remote_id'
    );
    $statuses->execute([$archiveId, (string) $archive['account_id']]);
    $prepared = [];
    foreach ($statuses->fetchAll() ?: [] as $status) {
        foreach (personal_photo_gallery_prepare_social_candidates($status, $archive) as $assetKey => $candidate) {
            $prepared[(string) $candidate['source_id'] . ':' . $assetKey] = $candidate;
        }
    }
    $prefix = $archiveId . ':';
    $existing = $database->prepare(
        "SELECT asset_key FROM plus_photo_gallery_occurrences WHERE source_type='social' AND source_id LIKE ?"
    );
    $existing->execute([$prefix . '%']);
    $affected = array_fill_keys(array_map('strval', $existing->fetchAll(PDO::FETCH_COLUMN)), true);
    plus_begin_immediate($database);
    try {
        $database->prepare(
            "DELETE FROM plus_photo_gallery_occurrences WHERE source_type='social' AND source_id LIKE ?"
        )->execute([$prefix . '%']);
        $insertAsset = $database->prepare('INSERT OR IGNORE INTO plus_photo_gallery_assets (asset_key) VALUES (?)');
        $insertOccurrence = $database->prepare(
            'INSERT INTO plus_photo_gallery_occurrences '
            . '(source_type,source_id,source_url,asset_key,media_id,position,alt_text,caption,source_title,published_at,context_text) '
            . 'VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        );
        foreach ($prepared as $candidate) {
            $assetKey = (string) $candidate['asset_key'];
            personal_photo_gallery_merge_assets($database, $assetKey, $candidate['merge_asset_keys']);
            $insertAsset->execute([$assetKey]);
            $insertOccurrence->execute([
                'social', $candidate['source_id'], $candidate['source_url'], $assetKey, $candidate['media_id'],
                $candidate['position'], $candidate['alt'], '', $candidate['source_title'], $candidate['published_at'], $candidate['context'],
            ]);
            $affected[$assetKey] = true;
        }
        personal_photo_gallery_prune_orphan_assets($database);
        $database->exec('COMMIT');
    } catch (Throwable $error) {
        if ($database->inTransaction()) $database->exec('ROLLBACK');
        throw $error;
    }
    try {
        if ($scheduleClassifications) personal_photo_gallery_schedule_classifications(array_keys($affected));
    } finally {
        if ($invalidateCache) cache_clear();
    }
    return count($prepared);
}

/**
 * Queue one bounded classification job per projected asset.
 *
 * @param list<string> $assetKeys
 */
function personal_photo_gallery_schedule_classifications(array $assetKeys): int
{
    $pending = [];
    $query = plus_database()->query(
        "SELECT payload FROM plus_jobs WHERE type='photo-gallery.classify' AND state IN ('queued','running')"
    );
    foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $payload) {
        try {
            $decoded = json_decode((string) $payload, true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            continue;
        }
        $assetKey = is_array($decoded) ? (string) ($decoded['asset_key'] ?? '') : '';
        if ($assetKey !== '') $pending[$assetKey] = true;
    }

    $scheduled = 0;
    foreach (array_values(array_unique($assetKeys)) as $assetKey) {
        if (preg_match('/^[a-f0-9]{32,64}$/', $assetKey) !== 1 || isset($pending[$assetKey])) continue;
        plus_enqueue_job('photo-gallery.classify', ['asset_key' => $assetKey], time(), 5);
        $pending[$assetKey] = true;
        $scheduled++;
    }
    return $scheduled;
}

/** @return array{id:string,version:string,handler:callable,settings:array<string,mixed>}|null */
function personal_photo_gallery_classifier(): ?array
{
    $config = personal_photo_gallery_config();
    $id = trim((string) ($config['plugins']['providers']['media.photo_classification'] ?? ''));
    if ($id === '') return null;
    $provider = plus_plugin_provider('media.photo_classification', $id, $config);
    $model = trim((string) ($provider['settings']['model'] ?? ''));
    return [
        'id' => $id,
        'version' => (string) ($provider['plugin']['version'] ?? '') . ($model !== '' ? ':' . $model : ''),
        'handler' => $provider['handler'],
        'settings' => $provider['settings'],
    ];
}

/** @return array{visual_class:string,confidence:float,state:string,reason:string}|null */
function personal_photo_gallery_local_decision(array $asset, ?array $config = null): ?array
{
    $imageText = mb_strtolower(implode(' ', [(string) $asset['alt_text'], (string) $asset['caption']]));
    $explicitPatterns = [
        'screenshot' => '/\b(?:screenshot|bildschirmfoto|bildschirmaufnahme)\b/u',
        'illustration' => '/\b(?:illustration|infografik|zeichnung|comic|logo)\b/u',
        'document' => '/\b(?:dokument|document|scan|eintrittskarte|fahrkarte|ticket)\b/u',
        'flat_artwork' => '/\b(?:filmplakat|movie poster|poster|plakat|buchcover|book cover|buchumschlag|albumcover|album art|covermotiv)\b/u',
    ];
    foreach ($explicitPatterns as $visualClass => $pattern) {
        if (preg_match($pattern, $imageText) === 1) {
            return [
                'visual_class' => $visualClass, 'confidence' => 0.99, 'state' => 'excluded',
                'reason' => 'local_explicit_' . $visualClass . '_signal',
            ];
        }
    }

    if (!empty($asset['capture_metadata']) && !empty($asset['capture_time'])) {
        return [
            'visual_class' => 'camera_photo', 'confidence' => 0.99, 'state' => 'visible',
            'reason' => 'camera_metadata_and_time',
        ];
    }

    $context = mb_strtolower((string) $asset['context_text']);
    $contextTags = [];
    if (preg_match('/^tags:\s*(.*)$/miu', $context, $match) === 1) {
        foreach (explode(',', $match[1]) as $tag) $contextTags[trim($tag)] = true;
    }
    if (array_intersect(personal_photo_gallery_positive_tags($config), array_keys($contextTags)) !== []) {
        return [
            'visual_class' => 'camera_photo', 'confidence' => 0.94, 'state' => 'visible',
            'reason' => 'personal_photo_context',
        ];
    }
    return null;
}

function personal_photo_gallery_classify(string $assetKey): array
{
    $query = plus_database()->prepare(
        'SELECT a.*,m.id AS media_id,m.capture_metadata,m.capture_time,o.context_text,o.alt_text,o.caption,o.source_title '
        . 'FROM plus_photo_gallery_assets a '
        . 'JOIN plus_photo_gallery_latest_occurrences o ON o.asset_key=a.asset_key '
        . "JOIN plus_media m ON m.id=o.media_id AND m.delete_state='active' "
        . 'WHERE a.asset_key=? LIMIT 1'
    );
    $query->execute([$assetKey]);
    $asset = $query->fetch();
    $query->closeCursor();
    if (!is_array($asset)) throw new InvalidArgumentException('Unknown gallery asset.');

    $config = personal_photo_gallery_config();
    $localDecision = personal_photo_gallery_local_decision($asset, $config);
    if ($localDecision !== null) {
        $inputSignature = hash('sha256', implode("\n", [
            (string) $asset['asset_key'], (string) $asset['context_text'],
            (string) $asset['capture_metadata'], (string) $asset['capture_time'],
            PERSONAL_PHOTO_GALLERY_RULE_VERSION, 'local', implode(',', personal_photo_gallery_positive_tags($config)),
        ]));
        $existing = plus_database()->prepare('SELECT * FROM plus_photo_gallery_classifications WHERE asset_key=? AND input_signature=?');
        $existing->execute([$assetKey, $inputSignature]);
        $classification = $existing->fetch();
        $existing->closeCursor();
        if (is_array($classification)) return $classification;
        personal_photo_gallery_store_classification(
            $assetKey, $localDecision['visual_class'], $localDecision['confidence'], $localDecision['state'],
            $localDecision['reason'], 'local', PERSONAL_PHOTO_GALLERY_RULE_VERSION, $inputSignature
        );
        return personal_photo_gallery_classification($assetKey) ?? [];
    }

    try {
        $classifier = personal_photo_gallery_classifier();
    } catch (Throwable $error) {
        $configuredId = trim((string) (personal_photo_gallery_config()['plugins']['providers']['media.photo_classification'] ?? ''));
        $inputSignature = hash('sha256', implode("\n", [
            (string) $asset['asset_key'],
            (string) $asset['context_text'], (string) $asset['capture_metadata'], (string) $asset['capture_time'],
            PERSONAL_PHOTO_GALLERY_RULE_VERSION, $configuredId, 'unavailable',
        ]));
        $reason = 'provider_error:' . mb_substr(trim($error->getMessage()), 0, 400);
        personal_photo_gallery_store_classification($assetKey, 'unknown', 0, 'unknown', $reason, $configuredId, 'unavailable', $inputSignature);
        throw $error;
    }
    $classifierId = $classifier['id'] ?? 'local';
    $classifierVersion = $classifier['version'] ?? PERSONAL_PHOTO_GALLERY_RULE_VERSION;
    $settingsSignature = $classifier === null ? '' : hash('sha256', serialize($classifier['settings']));
    $inputSignature = hash('sha256', implode("\n", [
        (string) $asset['asset_key'],
        (string) $asset['context_text'], (string) $asset['capture_metadata'], (string) $asset['capture_time'],
        PERSONAL_PHOTO_GALLERY_RULE_VERSION, $classifierId, $classifierVersion, $settingsSignature,
    ]));
    $existing = plus_database()->prepare('SELECT * FROM plus_photo_gallery_classifications WHERE asset_key=? AND input_signature=?');
    $existing->execute([$assetKey, $inputSignature]);
    $classification = $existing->fetch();
    $existing->closeCursor();
    if (is_array($classification) && !str_starts_with((string) $classification['reason'], 'provider_error:')) return $classification;

    $visualClass = 'unknown';
    $confidence = 0.0;
    $state = 'unknown';
    $reason = 'insufficient_evidence';
    $riskText = mb_strtolower(implode(' ', [(string) $asset['alt_text'], (string) $asset['caption'], (string) $asset['source_title']]));
    $artworkRisk = preg_match('/\b(?:filmplakat|movie poster|poster|plakat|buchcover|book cover|buchumschlag|albumcover|album art|covermotiv|screenshot|bildschirmfoto|bildschirmaufnahme|logo|illustration|infografik|zeichnung|dokument|document|scan|eintrittskarte|fahrkarte|ticket)\b/u', $riskText) === 1;

    if ($classifier === null) {
        if ($artworkRisk) {
            $visualClass = 'flat_artwork'; $confidence = 0.8; $state = 'excluded'; $reason = 'local_artwork_signal';
        } elseif (!empty($asset['capture_metadata']) && !empty($asset['capture_time'])) {
            $visualClass = 'camera_photo'; $confidence = 0.99;
            $state = 'visible'; $reason = 'camera_metadata_and_time';
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
            $providerReason = mb_substr(trim((string) ($result['reason'] ?? $visualClass)), 0, 500);
            if ($visualClass === 'camera_photo' && $artworkRisk) {
                $state = 'unknown';
                $reason = mb_substr('conflicting_artwork_context:' . $providerReason, 0, 500);
            } elseif (in_array($visualClass, ['camera_photo', 'photo_of_physical_artwork'], true)
                && $confidence >= (!empty($asset['capture_metadata']) ? 0.85 : 0.92)) {
                $state = 'visible';
            } elseif (in_array($visualClass, ['flat_artwork','screenshot','illustration','document'], true) && $confidence >= 0.8) {
                $state = 'excluded';
            }
            if (!str_starts_with($reason, 'conflicting_artwork_context:')) $reason = $providerReason;
        } catch (Throwable $error) {
            $reason = 'provider_error:' . mb_substr(trim($error->getMessage()), 0, 400);
            personal_photo_gallery_store_classification($assetKey, 'unknown', 0, 'unknown', $reason, $classifierId, $classifierVersion, $inputSignature);
            throw $error;
        }
    }
    personal_photo_gallery_store_classification($assetKey, $visualClass, $confidence, $state, $reason, $classifierId, $classifierVersion, $inputSignature);
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
    string $inputSignature
): void {
    plus_database()->prepare(
        'INSERT INTO plus_photo_gallery_classifications (asset_key,visual_class,confidence,automatic_state,reason,classifier_id,classifier_version,input_signature,updated_at) '
        . 'VALUES (?,?,?,?,?,?,?,?,?) ON CONFLICT(asset_key) DO UPDATE SET visual_class=excluded.visual_class,confidence=excluded.confidence,'
        . 'automatic_state=excluded.automatic_state,reason=excluded.reason,classifier_id=excluded.classifier_id,'
        . 'classifier_version=excluded.classifier_version,input_signature=excluded.input_signature,updated_at=excluded.updated_at'
    )->execute([$assetKey, $visualClass, $confidence, $state, $reason, $classifierId, $classifierVersion, $inputSignature, time()]);
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
    $count = 0;
    try {
        foreach (get_all_posts(true, true) as $post) {
            if (!personal_photo_gallery_job_enabled()) return $count;
            if (($post['status'] ?? '') !== 'published') continue;
            $slug = (string) ($post['slug'] ?? '');
            if ($slug === '') continue;
            $count += personal_photo_gallery_sync_post($slug, null, false, $post, false);
            plus_job_heartbeat();
        }
        if (!personal_photo_gallery_job_enabled()) return $count;
        $database = plus_database();
        $publishedLookup = [];
        foreach (get_all_posts_meta(true, true) as $currentPost) {
            if (($currentPost['status'] ?? '') === 'published' && (string) ($currentPost['slug'] ?? '') !== '') {
                $publishedLookup[(string) $currentPost['slug']] = true;
            }
        }
        $staleSlugs = [];
        foreach ($database->query("SELECT DISTINCT source_id FROM plus_photo_gallery_occurrences WHERE source_type='post'")->fetchAll(PDO::FETCH_COLUMN) as $projectedSlug) {
            if (!isset($publishedLookup[(string) $projectedSlug])) $staleSlugs[] = (string) $projectedSlug;
        }
        plus_begin_immediate($database);
        try {
            $delete = $database->prepare("DELETE FROM plus_photo_gallery_occurrences WHERE source_type='post' AND source_id=?");
            foreach ($staleSlugs as $staleSlug) {
                $delete->execute([$staleSlug]);
            }
            personal_photo_gallery_prune_orphan_assets($database);
            $database->exec('COMMIT');
        } catch (Throwable $error) {
            if ($database->inTransaction()) $database->exec('ROLLBACK');
            throw $error;
        }
        foreach ($database->query('SELECT id FROM plus_social_archives ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) as $archiveId) {
            $count += personal_photo_gallery_sync_social_archive((int) $archiveId, false, false);
            plus_job_heartbeat();
        }
        $assetKeys = array_map(
            'strval',
            $database->query(
                "SELECT DISTINCT o.asset_key FROM plus_photo_gallery_occurrences o "
                . "JOIN plus_media m ON m.id=o.media_id AND m.delete_state='active' ORDER BY o.asset_key"
            )->fetchAll(PDO::FETCH_COLUMN)
        );
        personal_photo_gallery_schedule_classifications($assetKeys);
        return $count;
    } finally {
        cache_clear();
    }
}

function personal_photo_gallery_schedule_rebuild(): array
{
    $query = plus_database()->query(
        "SELECT id FROM plus_jobs WHERE type='photo-gallery.rebuild' AND state='queued' ORDER BY id DESC LIMIT 1"
    );
    $queuedId = $query->fetchColumn();
    if ($queuedId !== false) return plus_get_job((int) $queuedId);
    return plus_enqueue_job('photo-gallery.rebuild', [], time(), 5);
}

function personal_photo_gallery_event_post_saved(array $post, ?string $oldSlug = null, ?string $oldStatus = null): void
{
    $slug = (string) ($post['slug'] ?? '');
    if ($slug === '') return;
    plus_enqueue_job('photo-gallery.sync', ['slug' => $slug, 'old_slug' => $oldSlug], time(), 5);
}

function personal_photo_gallery_event_post_deleted(string $slug): void
{
    if ($slug === '') return;
    plus_enqueue_job('photo-gallery.sync', ['slug' => $slug], time(), 5);
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
    if ($provider !== $previousProvider || $settings !== $previousSettings
        || personal_photo_gallery_positive_tags($config) !== personal_photo_gallery_positive_tags($previous)) {
        personal_photo_gallery_schedule_rebuild();
    }
}

/** @param array<string,mixed> $payload */
function personal_photo_gallery_sync_job(array $payload): void
{
    if (!personal_photo_gallery_job_enabled()) return;
    personal_photo_gallery_sync_post((string) ($payload['slug'] ?? ''), isset($payload['old_slug']) ? (string) $payload['old_slug'] : null);
}

/** @param array<string,mixed> $payload */
function personal_photo_gallery_rebuild_job(array $payload): void
{
    if (!personal_photo_gallery_job_enabled()) return;
    personal_photo_gallery_rebuild();
}

/** @param array<string,mixed> $payload */
function personal_photo_gallery_classify_job(array $payload): void
{
    if (!personal_photo_gallery_job_enabled()) return;
    $assetKey = (string) ($payload['asset_key'] ?? '');
    if (preg_match('/^[a-f0-9]{32,64}$/', $assetKey) !== 1) {
        throw new InvalidArgumentException('Invalid gallery asset.');
    }
    try {
        personal_photo_gallery_classify_assets([$assetKey]);
    } finally {
        cache_clear();
    }
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
        $cursorSql = ' AND (o.published_at < ? OR (o.published_at = ? AND o.asset_key < ?))';
        $params = [$decoded[0], $decoded[0], $decoded[1]];
    }
    $sql = "SELECT a.asset_key,m.id AS media_id,m.url,m.variants_json,m.mime_type,m.width,m.height,"
        . "m.capture_metadata,m.capture_time,o.source_type,o.source_id,o.source_url,o.source_title,o.published_at,o.alt_text,o.caption,"
        . "c.visual_class,c.confidence,c.automatic_state,v.visibility AS override_visibility "
        . "FROM plus_photo_gallery_assets a JOIN plus_photo_gallery_latest_occurrences o ON o.asset_key=a.asset_key "
        . "JOIN plus_media m ON m.id=o.media_id AND m.delete_state='active' "
        . "LEFT JOIN plus_photo_gallery_classifications c ON c.asset_key=a.asset_key "
        . "LEFT JOIN plus_photo_gallery_overrides v ON v.asset_key=a.asset_key "
        . "WHERE COALESCE(v.visibility,c.automatic_state,'unknown')='visible' "
        . $cursorSql . ' ORDER BY o.published_at DESC,o.asset_key DESC LIMIT ?';
    $statement = plus_database()->prepare($sql);
    foreach ($params as $index => $value) $statement->bindValue($index + 1, $value, PDO::PARAM_STR);
    $statement->bindValue(count($params) + 1, $limit + 1, PDO::PARAM_INT);
    $statement->execute();
    $items = $statement->fetchAll() ?: [];
    $hasNext = count($items) > $limit;
    if ($hasNext) array_pop($items);
    foreach ($items as &$item) $item = personal_photo_gallery_hydrate_item($item);
    unset($item);
    return ['items' => $items, 'next_cursor' => $hasNext && $items !== [] ? personal_photo_gallery_cursor_encode($items[array_key_last($items)]) : null];
}

/** @param array<string,mixed> $item @return array<string,mixed> */
function personal_photo_gallery_hydrate_item(array $item): array
{
    $variants = json_decode((string) ($item['variants_json'] ?? '{}'), true);
    $item['variants'] = is_array($variants) ? $variants : [];
    ksort($item['variants'], SORT_NUMERIC);
    $item['preview_url'] = $item['variants'] !== [] ? (string) reset($item['variants']) : (string) ($item['url'] ?? '');
    return $item;
}

function personal_photo_gallery_source_url(array $item): string
{
    if ((string) ($item['source_type'] ?? 'post') === 'social') {
        $url = trim((string) ($item['source_url'] ?? ''));
        $parts = parse_url($url);
        if (is_array($parts) && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) return $url;
        return base_path() . '/social.php';
    }
    return base_path() . '/' . rawurlencode((string) ($item['source_id'] ?? ''));
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
            $alt = trim((string) ($item['alt_text'] ?: $item['caption'] ?: $item['source_title']));
            $sourceUrl = personal_photo_gallery_source_url($item);
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
            $html .= '<figure class="personal-photo-gallery-item"><a class="personal-photo-gallery-link" href="' . e((string) $item['url']) . '" data-pb-lightbox data-alt="' . e($alt) . '" data-caption="' . e($caption) . '" data-source-url="' . e($sourceUrl) . '" data-source-title="' . e((string) $item['source_title']) . '">';
            $html .= '<img src="' . e((string) $item['preview_url']) . '" alt="' . e($alt) . '" width="' . max(1, (int) $item['width']) . '" height="' . max(1, (int) $item['height']) . '" loading="' . ($eager ? 'eager' : 'lazy') . '" decoding="async"' . $priority;
            if ($srcset !== []) $html .= ' srcset="' . implode(', ', $srcset) . '" sizes="' . e($sizes) . '"';
            $html .= '></a></figure>';
            $index++;
        }
        $html .= '</div>';
    }
    return $html;
}

function personal_photo_gallery_route_matches(string $requestPath): bool
{
    return $requestPath === '/' . personal_photo_gallery_settings()['route'];
}

function personal_photo_gallery_route_handler(string $requestPath): bool
{
    if (!personal_photo_gallery_route_matches($requestPath)) return false;
    $settings = personal_photo_gallery_settings();
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
    $where = ['1=1'];
    $params = [];
    if ($filter === 'visible') $where[] = $state . "='visible'";
    elseif ($filter === 'hidden') $where[] = "v.visibility='hidden'";
    elseif ($filter === 'excluded') $where[] = "v.visibility IS NULL AND c.automatic_state='excluded'";
    elseif ($filter === 'unknown') $where[] = "v.visibility IS NULL AND COALESCE(c.automatic_state,'unknown')='unknown'";
    if ($search !== '') {
        $where[] = '(o.source_title LIKE ? ESCAPE \'\\\' OR o.alt_text LIKE ? ESCAPE \'\\\' OR o.caption LIKE ? ESCAPE \'\\\')';
        $needle = '%' . str_replace(['\\','%','_'], ['\\\\','\\%','\\_'], $search) . '%';
        $params = [$needle, $needle, $needle];
    }
    $sql = 'SELECT a.asset_key,m.id AS media_id,m.url,m.variants_json,m.mime_type,m.width,m.height,'
        . 'm.capture_metadata,m.capture_time,o.source_type,o.source_id,o.source_url,o.source_title,o.published_at,o.alt_text,o.caption,'
        . 'c.visual_class,c.confidence,c.automatic_state,c.reason,v.visibility AS override_visibility,'
        . $state . ' AS effective_state FROM plus_photo_gallery_assets a '
        . 'JOIN plus_photo_gallery_latest_occurrences o ON o.asset_key=a.asset_key '
        . "JOIN plus_media m ON m.id=o.media_id AND m.delete_state='active' "
        . 'LEFT JOIN plus_photo_gallery_classifications c ON c.asset_key=a.asset_key '
        . 'LEFT JOIN plus_photo_gallery_overrides v ON v.asset_key=a.asset_key WHERE ' . implode(' AND ', $where)
        . ' ORDER BY o.published_at DESC,o.asset_key DESC LIMIT ? OFFSET ?';
    $query = plus_database()->prepare($sql);
    foreach ($params as $index => $value) $query->bindValue($index + 1, $value, PDO::PARAM_STR);
    $query->bindValue(count($params) + 1, $limit, PDO::PARAM_INT);
    $query->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
    $query->execute();
    $items = $query->fetchAll() ?: [];
    foreach ($items as &$item) $item = personal_photo_gallery_hydrate_item($item);
    unset($item);
    return $items;
}

/** @return array<string,int> */
function personal_photo_gallery_stats(): array
{
    $rows = plus_database()->query(
        "SELECT CASE WHEN v.visibility='hidden' THEN 'hidden' WHEN v.visibility='visible' THEN 'visible' "
        . "ELSE COALESCE(c.automatic_state,'unknown') END AS state,COUNT(*) AS amount "
        . "FROM plus_photo_gallery_assets a LEFT JOIN plus_photo_gallery_classifications c ON c.asset_key=a.asset_key "
        . "LEFT JOIN plus_photo_gallery_overrides v ON v.asset_key=a.asset_key "
        . "WHERE EXISTS (SELECT 1 FROM plus_photo_gallery_occurrences o JOIN plus_media m ON m.id=o.media_id "
        . "WHERE o.asset_key=a.asset_key AND m.delete_state='active') GROUP BY state"
    )->fetchAll() ?: [];
    $stats = ['visible' => 0, 'hidden' => 0, 'excluded' => 0, 'unknown' => 0];
    foreach ($rows as $row) $stats[(string) $row['state']] = (int) $row['amount'];
    return $stats;
}

plus_register_job_handler('photo-gallery.sync', 'personal_photo_gallery_sync_job');
plus_register_job_handler('photo-gallery.rebuild', 'personal_photo_gallery_rebuild_job');
plus_register_job_handler('photo-gallery.classify', 'personal_photo_gallery_classify_job');
plus_register_capability('photo.gallery', 1, 0);

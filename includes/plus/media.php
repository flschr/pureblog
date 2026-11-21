<?php

declare(strict_types=1);

plus_register_migration('0005_media', static function (PDO $database): void {
    $database->exec(
        'CREATE TABLE plus_media ('
        . 'id TEXT PRIMARY KEY,'
        . 'provider_id TEXT NOT NULL,'
        . 'url TEXT NOT NULL UNIQUE,'
        . 'mime_type TEXT NOT NULL,'
        . 'original_name TEXT NOT NULL,'
        . 'width INTEGER,'
        . 'height INTEGER,'
        . 'variants_json TEXT NOT NULL DEFAULT \'{}\','
        . 'provider_data_json TEXT NOT NULL DEFAULT \'{}\','
        . 'local_source TEXT NOT NULL,'
        . 'created_at INTEGER NOT NULL)'
    );
});

plus_register_migration('0006_media_default_alt', static function (PDO $database): void {
    $database->exec("ALTER TABLE plus_media ADD COLUMN default_alt TEXT NOT NULL DEFAULT ''");
});

plus_register_migration('0009_media_delete_state', static function (PDO $database): void {
    $database->exec("ALTER TABLE plus_media ADD COLUMN delete_state TEXT NOT NULL DEFAULT 'active' CHECK (delete_state IN ('active','deleting'))");
});

plus_register_migration('0012_media_capture_evidence', static function (PDO $database): void {
    $columns = array_column($database->query('PRAGMA table_info(plus_media)')->fetchAll(), 'name');
    if (!in_array('capture_metadata', $columns, true)) {
        $database->exec("ALTER TABLE plus_media ADD COLUMN capture_metadata INTEGER NOT NULL DEFAULT 0 CHECK (capture_metadata IN (0,1))");
    }
    if (!in_array('capture_time', $columns, true)) {
        $database->exec("ALTER TABLE plus_media ADD COLUMN capture_time INTEGER NOT NULL DEFAULT 0 CHECK (capture_time IN (0,1))");
    }
    if (!in_array('source_fingerprint', $columns, true)) {
        $database->exec("ALTER TABLE plus_media ADD COLUMN source_fingerprint TEXT NOT NULL DEFAULT ''");
    }
});

/** @return array{capture_metadata:bool,capture_time:bool} */
function plus_media_capture_evidence(string $path, string $mime): array
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

function plus_media_source_fingerprint(string $path): string
{
    $hash = is_file($path) ? hash_file('sha256', $path) : false;
    return is_string($hash) ? $hash : '';
}

/** @return array<string,string> */
function plus_media_types(): array
{
    return [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif',
        'image/webp' => 'webp', 'image/avif' => 'avif',
        'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'audio/ogg' => 'ogg',
        'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/flac' => 'flac',
        'video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov',
    ];
}

function plus_editor_visual_media_accept(): string
{
    $accepted = ['.heic', '.heif'];
    foreach (plus_media_types() as $mime => $extension) {
        if (!str_starts_with($mime, 'image/') && !str_starts_with($mime, 'video/')) continue;
        $accepted[] = $mime;
        $accepted[] = '.' . $extension;
    }
    return implode(',', array_values(array_unique($accepted)));
}

/**
 * Store one upload through the configured media provider.
 *
 * @param array<string,mixed> $file
 * @return array<string,mixed>
 */
function plus_store_uploaded_media(array $file, bool $requireUploadedFile = true, ?string $providerId = null, string $kind = 'any'): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('A complete file upload is required.');
    }
    $temporary = (string) ($file['tmp_name'] ?? '');
    $uploadTemporary = $temporary;
    $size = is_file($temporary) ? filesize($temporary) : false;
    if ($temporary === '' || $size === false || $size <= 0 || $size > 104857600) {
        throw new InvalidArgumentException('The file is empty or exceeds 100 MB.');
    }
    if ($requireUploadedFile && !is_uploaded_file($temporary)) {
        throw new InvalidArgumentException('The upload source is not valid.');
    }
    $mimeDetector = new finfo(FILEINFO_MIME_TYPE);
    $mime = $mimeDetector->file($temporary) ?: '';
    $types = plus_media_types();
    if (!isset($types[$mime])) {
        $prepared = apply_filter('on_prepare_media_upload', [
            'path' => $temporary,
            'name' => (string) ($file['name'] ?? ''),
            'mime_type' => $mime,
            'size' => (int) $size,
            'temporary' => false,
        ]);
        if (!is_array($prepared) || !is_string($prepared['path'] ?? null) || !is_file($prepared['path'])) {
            throw new InvalidArgumentException('The file type is not supported.');
        }
        $temporary = $prepared['path'];
        $size = filesize($temporary);
        $mime = $mimeDetector->file($temporary) ?: '';
        if ($size === false || $size <= 0 || $size > 104857600 || !isset($types[$mime])) {
            if ($temporary !== $uploadTemporary && !empty($prepared['temporary'])) @unlink($temporary);
            throw new InvalidArgumentException('The prepared media file is invalid or unsupported.');
        }
    }
    $kindMatches = $kind === 'any'
        || ($kind === 'visual' && (str_starts_with($mime, 'image/') || str_starts_with($mime, 'video/')))
        || str_starts_with($mime, $kind . '/');
    if (!$kindMatches) {
        throw new InvalidArgumentException('The file type is not supported here.');
    }

    $database = plus_database();
    $id = bin2hex(random_bytes(16));
    $createdAt = time();
    $privateDirectory = PUREBLOG_PLUS_DATA_PATH . '/media-originals';
    if (!is_dir($privateDirectory) && !mkdir($privateDirectory, 0700, true) && !is_dir($privateDirectory)) {
        throw new RuntimeException('Unable to create the private media directory.');
    }
    @chmod($privateDirectory, 0700);
    $source = $privateDirectory . '/' . $id . '.' . $types[$mime];
    if ($temporary === $uploadTemporary) {
        $moved = $requireUploadedFile ? move_uploaded_file($temporary, $source) : rename($temporary, $source);
    } elseif (!empty($prepared['temporary'])) {
        $moved = rename($temporary, $source);
    } else {
        $moved = copy($temporary, $source);
    }
    if (!$moved && $temporary !== $uploadTemporary && copy($temporary, $source)) {
        $moved = true;
        if (!empty($prepared['temporary'])) @unlink($temporary);
    }
    if (!$moved || !is_file($source)) {
        throw new RuntimeException('Unable to retain the media source.');
    }
    @chmod($source, 0600);

    $generated = [];
    $stored = null;
    try {
        [$width, $height] = plus_media_dimensions($source, $mime);
        $captureEvidence = ['capture_metadata' => false, 'capture_time' => false];
        if (str_starts_with($mime, 'image/')) {
            $maximumPixels = class_exists('Imagick') ? 60000000 : 30000000;
            if ($width < 1 || $height < 1 || $width > 20000 || $height > 20000 || $width * $height > $maximumPixels) {
                throw new InvalidArgumentException('The image dimensions are invalid or too large for this server.');
            }
            $captureEvidence = plus_media_capture_evidence($source, $mime);
            plus_normalize_image($source, $mime);
            call_hook('on_image_uploaded', [$source]);
            [$width, $height] = plus_media_dimensions($source, $mime);
        }
        $originalName = $id . '.' . $types[$mime];
        $files = [[
            'role' => 'original', 'path' => $source, 'name' => $originalName,
            'mime_type' => $mime, 'width' => $width, 'height' => $height,
        ]];
        if (str_starts_with($mime, 'image/') && $mime !== 'image/gif') {
            foreach (plus_generate_image_variants($source, $id, $width, $height) as $variant) {
                $files[] = $variant;
                $generated[] = $variant['path'];
            }
        }
        $provider = plus_plugin_provider('media.storage', $providerId);
        $asset = [
            'id' => $id, 'mime_type' => $mime, 'width' => $width, 'height' => $height,
            'created_at' => $createdAt, 'original_name' => (string) ($file['name'] ?? $originalName),
        ];
        $stored = ($provider['handler'])($asset, $files, $provider['settings']);
        $url = trim((string) ($stored['url'] ?? ''));
        $variants = (array) ($stored['variants'] ?? []);
        if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new RuntimeException('Media provider returned an invalid URL.');
        }
        foreach ($variants as $variantWidth => $variantUrl) {
            if ((int) $variantWidth < 1 || !is_string($variantUrl) || !filter_var($variantUrl, FILTER_VALIDATE_URL)) {
                throw new RuntimeException('Media provider returned invalid variants.');
            }
        }
        $statement = $database->prepare(
            'INSERT INTO plus_media (id,provider_id,url,mime_type,original_name,width,height,variants_json,provider_data_json,local_source,created_at,capture_metadata,capture_time,source_fingerprint) '
            . 'VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $statement->execute([
            $id, $provider['id'], $url, $mime, basename((string) ($file['name'] ?? $originalName)), $width ?: null, $height ?: null,
            json_encode($variants, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            json_encode((array) ($stored['provider_data'] ?? []), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $source, $createdAt, $captureEvidence['capture_metadata'] ? 1 : 0, $captureEvidence['capture_time'] ? 1 : 0, '',
        ]);
        return [
            'id' => $id, 'provider' => $provider['id'], 'url' => $url, 'mime_type' => $mime,
            'width' => $width, 'height' => $height, 'variants' => $variants,
        ];
    } catch (Throwable $error) {
        if (is_array($stored) && is_callable($stored['rollback'] ?? null)) {
            try {
                ($stored['rollback'])();
            } catch (Throwable) {
            }
        }
        @unlink($source);
        throw $error;
    } finally {
        foreach ($generated as $path) {
            @unlink($path);
        }
    }
}

function plus_normalize_image(string $path, string $mime): void
{
    if (class_exists('Imagick')) {
        try {
            $image = new Imagick($path);
            if ($image->getNumberImages() === 1) {
                $image->autoOrient();
                $image->stripImage();
                $image->writeImage($path . '.normalized');
                if (is_file($path . '.normalized') && filesize($path . '.normalized') > 0) {
                    rename($path . '.normalized', $path);
                }
            }
            $image->destroy();
            return;
        } catch (Throwable) {
            @unlink($path . '.normalized');
        }
    }
    strip_image_metadata($path, $mime);
}

/** @return array{0:int,1:int} */
function plus_media_dimensions(string $path, string $mime): array
{
    if (!str_starts_with($mime, 'image/')) {
        return [0, 0];
    }
    $dimensions = @getimagesize($path);
    return is_array($dimensions) ? [(int) $dimensions[0], (int) $dimensions[1]] : [0, 0];
}

/** @return list<array<string,mixed>> */
function plus_generate_image_variants(string $source, string $id, int $width, int $height): array
{
    if ($width < 1 || $height < 1 || (!class_exists('Imagick') && !extension_loaded('gd'))) {
        return [];
    }
    $variants = [];
    foreach ([360, 720, 1440] as $targetWidth) {
        if ($targetWidth > $width && $variants !== []) {
            continue;
        }
        $variantWidth = min($targetWidth, $width);
        if (isset($variants[$variantWidth])) {
            continue;
        }
        $destination = PUREBLOG_PLUS_DATA_PATH . '/' . $id . '-' . $variantWidth . '.webp';
        if (!plus_write_webp_variant($source, $destination, $variantWidth)) {
            continue;
        }
        $variants[$variantWidth] = [
            'role' => 'variant', 'path' => $destination, 'name' => $id . '-' . $variantWidth . '.webp',
            'mime_type' => 'image/webp', 'width' => $variantWidth,
            'height' => (int) round($height * ($variantWidth / $width)),
        ];
    }
    ksort($variants, SORT_NUMERIC);
    return array_values($variants);
}

function plus_write_webp_variant(string $source, string $destination, int $targetWidth): bool
{
    if (class_exists('Imagick')) {
        try {
            $image = new Imagick($source);
            $image->setIteratorIndex(0);
            $image->autoOrient();
            $sourceWidth = $image->getImageWidth();
            $sourceHeight = $image->getImageHeight();
            if ($sourceWidth < 1 || $sourceHeight < 1) { $image->clear(); return false; }
            $targetHeight = max(1, (int) round($sourceHeight * (min($targetWidth, $sourceWidth) / $sourceWidth)));
            $image->thumbnailImage(min($targetWidth, $sourceWidth), $targetHeight, true);
            $image->setImageFormat('webp');
            $image->setImageCompressionQuality(82);
            $image->stripImage();
            $ok = $image->writeImage($destination);
            $image->destroy();
            return $ok && is_file($destination) && filesize($destination) > 0;
        } catch (Throwable) {
            @unlink($destination);
            return false;
        }
    }
    $info = @getimagesize($source);
    $mime = (string) ($info['mime'] ?? '');
    $loader = match ($mime) {
        'image/jpeg' => 'imagecreatefromjpeg', 'image/png' => 'imagecreatefrompng',
        'image/webp' => 'imagecreatefromwebp', 'image/avif' => 'imagecreatefromavif',
        default => '',
    };
    if ($loader === '' || !function_exists($loader) || !function_exists('imagewebp')) {
        return false;
    }
    $input = @$loader($source);
    if ($input === false) {
        return false;
    }
    $sourceWidth = imagesx($input);
    $sourceHeight = imagesy($input);
    $targetWidth = min($targetWidth, $sourceWidth);
    $targetHeight = max(1, (int) round($sourceHeight * ($targetWidth / $sourceWidth)));
    $output = imagecreatetruecolor($targetWidth, $targetHeight);
    imagealphablending($output, false);
    imagesavealpha($output, true);
    $ok = imagecopyresampled($output, $input, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight)
        && imagewebp($output, $destination, 82);
    unset($input, $output);
    return $ok && is_file($destination) && filesize($destination) > 0;
}

function plus_media_by_url(string $url): ?array
{
    if (!is_file(plus_database_path())) {
        return null;
    }
    $statement = plus_database()->prepare('SELECT * FROM plus_media WHERE url=?');
    $statement->execute([$url]);
    $media = $statement->fetch();
    if (!is_array($media)) {
        return null;
    }
    $media['variants'] = json_decode((string) $media['variants_json'], true) ?: [];
    $media['provider_data'] = json_decode((string) $media['provider_data_json'], true) ?: [];
    return $media;
}

function plus_media_by_id(string $id): ?array
{
    if (preg_match('/^[a-f0-9]{32}$/', $id) !== 1 || !is_file(plus_database_path())) return null;
    $statement = plus_database()->prepare('SELECT * FROM plus_media WHERE id=?');
    $statement->execute([$id]);
    $media = $statement->fetch();
    if (!is_array($media)) return null;
    $media['variants'] = json_decode((string) $media['variants_json'], true) ?: [];
    $media['provider_data'] = json_decode((string) $media['provider_data_json'], true) ?: [];
    return $media;
}

function plus_media_is_referenced(string $url, ?array $directories = null): bool
{
    $directories ??= [PUREBLOG_POSTS_PATH, PUREBLOG_PAGES_PATH];
    foreach ($directories as $directory) {
        if (!is_dir($directory)) continue;
        foreach (glob($directory . '/*.md') ?: [] as $file) {
            $raw = file_get_contents($file);
            if (is_string($raw) && str_contains($raw, $url)) return true;
        }
    }
    return false;
}

function plus_media_is_read_only(array $media): bool
{
    $providerData = $media['provider_data'] ?? null;
    if (!is_array($providerData)) {
        $decoded = json_decode((string) ($media['provider_data_json'] ?? ''), true);
        $providerData = is_array($decoded) ? $decoded : [];
    }
    return !empty($providerData['read_only']);
}

function plus_media_can_delete(array $media): bool
{
    if (plus_media_is_read_only($media)) return false;
    $plugin = plus_plugins()[(string) ($media['provider_id'] ?? '')] ?? null;
    return is_callable($plugin['capabilities']['media.delete'] ?? null);
}

function plus_delete_media(string $id): bool
{
    $media = plus_media_by_id($id);
    if ($media === null) return false;
    if (plus_media_is_read_only($media)) {
        throw new RuntimeException('Read-only media cannot be deleted.');
    }
    if (plus_media_is_referenced((string) $media['url'])
        || (function_exists('social_media_is_referenced') && social_media_is_referenced((string) $media['url']))) {
        throw new RuntimeException('Referenced media cannot be deleted.');
    }
    $plugin = plus_plugins()[(string) $media['provider_id']] ?? null;
    $handler = $plugin['capabilities']['media.delete'] ?? null;
    if (!is_callable($handler)) throw new RuntimeException('Media provider cannot delete this asset.');
    plus_database()->prepare("UPDATE plus_media SET delete_state='deleting' WHERE id=?")->execute([$id]);
    $handler($media, plus_plugin_settings((string) $media['provider_id']));
    $source = (string) $media['local_source'];
    if (is_file($source) && !unlink($source)) throw new RuntimeException('Private media source could not be deleted.');
    plus_database()->prepare('DELETE FROM plus_media WHERE id=?')->execute([$id]);
    return true;
}

function plus_alt_suggestions_enabled(?array $config = null): bool
{
    $config ??= load_config();
    $id = trim((string) ($config['plugins']['providers']['media.alt_suggestion'] ?? ''));
    if ($id === '') return false;
    try {
        plus_plugin_provider('media.alt_suggestion', $id, $config);
        return true;
    } catch (Throwable) {
        return false;
    }
}

function plus_suggest_media_alt(string $url, string $context): string
{
    $media = plus_media_by_url($url);
    if ($media === null || !str_starts_with((string) $media['mime_type'], 'image/')) {
        throw new InvalidArgumentException('Unknown image.');
    }
    $provider = plus_plugin_provider('media.alt_suggestion');
    $suggestion = ($provider['handler'])($media, mb_substr($context, 0, 2500), $provider['settings']);
    $suggestion = trim(preg_replace('/\s+/u', ' ', (string) $suggestion) ?? '');
    if ($suggestion === '') throw new RuntimeException('Alt-text suggestion was empty.');
    $suggestion = mb_substr($suggestion, 0, 1500);
    plus_set_media_default_alt((string) $media['id'], $suggestion);
    return $suggestion;
}

function plus_set_media_default_alt(string $id, string $alt): void
{
    if (preg_match('/^[a-f0-9]{32}$/', $id) !== 1) throw new InvalidArgumentException('Unknown media.');
    $alt = mb_substr(trim(preg_replace('/\s+/u', ' ', $alt) ?? ''), 0, 1500);
    $statement = plus_database()->prepare('UPDATE plus_media SET default_alt=? WHERE id=?');
    $statement->execute([$alt, $id]);
    if ($statement->rowCount() !== 1 && plus_media_by_id($id) === null) throw new InvalidArgumentException('Unknown media.');
}

function plus_update_media_default_alt(string $id, string $alt): void
{
    plus_set_media_default_alt($id, $alt);
    call_hook('on_media_updated', [$id]);
}

function plus_media_default_alt(string $url, string $context = ''): string
{
    $media = plus_media_by_url($url);
    if ($media === null || !str_starts_with((string) $media['mime_type'], 'image/')) return '';
    $alt = trim((string) ($media['default_alt'] ?? ''));
    if ($alt !== '') return $alt;
    if (!plus_alt_suggestions_enabled()) return '';
    try {
        return plus_suggest_media_alt($url, $context);
    } catch (Throwable) {
        return '';
    }
}

function plus_media_description(string $url): string
{
    $media = plus_media_by_url($url);
    return $media === null ? '' : trim((string) ($media['default_alt'] ?? ''));
}

function plus_markdown_alt(string $alt): string
{
    return str_replace(['\\', '[', ']'], ['\\\\', '\\[', '\\]'], trim(preg_replace('/\s+/u', ' ', $alt) ?? ''));
}

function plus_media_editor_markup(array $media): string
{
    $url = (string) ($media['url'] ?? '');
    $mime = (string) ($media['mime_type'] ?? '');
    if (str_starts_with($mime, 'video/')) {
        return '<video controls playsinline preload="metadata" src="'
            . htmlspecialchars($url, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"></video>';
    }
    $filename = basename((string) (($media['original_name'] ?? '') ?: (parse_url($url, PHP_URL_PATH) ?? ($media['id'] ?? 'image'))));
    $alt = trim((string) ($media['default_alt'] ?? ''));
    if ($alt === '') $alt = pathinfo($filename, PATHINFO_FILENAME) ?: 'image';
    return '![' . plus_markdown_alt($alt) . '](' . $url . ')';
}

function plus_apply_media_alts_to_markdown(string $content, string $context = ''): string
{
    if (!str_contains($content, '![') || !is_file(plus_database_path())) return $content;
    return preg_replace_callback(
        '/!\[\s*\]\((https?:\/\/[^\s)]+)((?:\s+["\'][^"\']*["\'])?)\)/u',
        static function (array $match) use ($context): string {
            $alt = plus_media_default_alt($match[1], $context);
            return $alt === '' ? $match[0] : '![' . plus_markdown_alt($alt) . '](' . $match[1] . $match[2] . ')';
        },
        $content
    ) ?? $content;
}

function plus_apply_media_alts_to_html(string $html, string $context = ''): string
{
    if (!str_contains(strtolower($html), '<img') || !is_file(plus_database_path())) return $html;
    return preg_replace_callback('/<img\b[^>]*>/iu', static function (array $match) use ($context): string {
        $tag = $match[0];
        $hasAlt = preg_match('/\salt\s*=\s*(["\'])(.*?)\1/isu', $tag, $existing) === 1;
        if ($hasAlt && trim(html_entity_decode($existing[2], ENT_QUOTES | ENT_HTML5)) !== '') return $tag;
        if (!preg_match('/\ssrc\s*=\s*(["\'])(.*?)\1/isu', $tag, $source)) return $tag;
        $url = html_entity_decode($source[2], ENT_QUOTES | ENT_HTML5);
        $alt = plus_media_default_alt($url, $context);
        if ($alt === '') return $tag;
        $attribute = 'alt="' . htmlspecialchars($alt, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"';
        if ($hasAlt) {
            return preg_replace_callback(
                '/\salt\s*=\s*(["\']).*?\1/isu',
                static fn(): string => ' ' . $attribute,
                $tag,
                1
            ) ?? $tag;
        }
        $closing = str_ends_with(rtrim($tag), '/>') ? ' />' : '>';
        return preg_replace_callback('/\s*\/?>$/u', static fn(): string => ' ' . $attribute . $closing, $tag, 1) ?? $tag;
    }, $html) ?? $html;
}

/** @param list<mixed> $values @return list<mixed> */
function plus_apply_media_alts_to_photos(array $values, string $context = ''): array
{
    foreach ($values as $index => $value) {
        $url = is_string($value) ? $value : (is_array($value) ? (string) ($value['value'] ?? '') : '');
        $authoredAlt = is_array($value) ? trim((string) ($value['alt'] ?? '')) : '';
        if ($url === '' || $authoredAlt !== '') continue;
        $alt = plus_media_default_alt($url, $context);
        if ($alt === '') continue;
        $values[$index] = is_array($value) ? array_replace($value, ['alt' => $alt]) : ['value' => $url, 'alt' => $alt];
    }
    return array_values($values);
}

/** @param list<mixed> $values @return list<mixed> */
function plus_apply_media_descriptions_to_videos(array $values): array
{
    foreach ($values as $index => $value) {
        $url = is_string($value) ? $value : (is_array($value) ? (string) ($value['value'] ?? '') : '');
        $authoredAlt = is_array($value) ? trim((string) ($value['alt'] ?? '')) : '';
        if ($url === '' || $authoredAlt !== '') continue;
        $description = plus_media_description($url);
        if ($description === '') continue;
        $values[$index] = is_array($value)
            ? array_replace($value, ['alt' => $description])
            : ['value' => $url, 'alt' => $description];
    }
    return array_values($values);
}

/** @return list<array{id:string,url:string,alt:string}> */
function plus_missing_media_alt_suggestions(string $content): array
{
    if (!str_contains($content, '![') || !is_file(plus_database_path())) return [];
    $suggestions = [];
    if (!preg_match_all('/!\[\s*\]\((https?:\/\/[^\s)]+)(?:\s+["\'][^"\']*["\'])?\)/u', $content, $matches)) return [];
    foreach (array_unique($matches[1]) as $url) {
        $media = plus_media_by_url($url);
        if ($media === null) continue;
        $alt = trim((string) ($media['default_alt'] ?? ''));
        if ($alt !== '') $suggestions[] = ['id' => (string) $media['id'], 'url' => $url, 'alt' => $alt];
    }
    return $suggestions;
}

/** @return list<array<string,mixed>> */
function plus_media_list(int $limit = 5000, int $offset = 0): array
{
    if ($limit < 1 || $limit > 100000 || $offset < 0 || !is_file(plus_database_path())) {
        return [];
    }
    $statement = plus_database()->prepare('SELECT * FROM plus_media ORDER BY created_at DESC,id DESC LIMIT ? OFFSET ?');
    $statement->bindValue(1, $limit, PDO::PARAM_INT);
    $statement->bindValue(2, $offset, PDO::PARAM_INT);
    $statement->execute();
    return $statement->fetchAll() ?: [];
}

/** @return list<array<string,mixed>> */
function plus_media_referenced_by(string $content, string $featureImage = '', array $additionalUrls = []): array
{
    $result = [];
    $additional = array_fill_keys(array_filter($additionalUrls, 'is_string'), true);
    foreach (plus_media_list() as $media) {
        $url = (string) $media['url'];
        $mime = (string) $media['mime_type'];
        $isImage = str_starts_with($mime, 'image/');
        $isVideo = str_starts_with($mime, 'video/');
        if (($isImage || $isVideo)
            && (($isImage && $featureImage === $url) || str_contains($content, $url) || isset($additional[$url]))) {
            $result[] = $media;
        }
    }
    return $result;
}

/** @return list<string> */
function plus_entry_visual_media_urls(array $entry): array
{
    $urls = [];
    foreach (['photo', 'video'] as $type) {
        $raw = (string) ($entry['micropub_' . $type] ?? '');
        $values = $raw !== '' ? json_decode($raw, true) : [];
        if (!is_array($values)) continue;
        foreach ($values as $value) {
            $url = is_string($value) ? $value : (is_array($value) ? (string) ($value['value'] ?? '') : '');
            if ($url !== '') $urls[] = $url;
        }
    }
    return array_values(array_unique($urls));
}

/** @return list<array<string,mixed>> */
function plus_managed_media_editor_items(array $entry): array
{
    $items = [];
    foreach (plus_media_referenced_by(
        (string) ($entry['content'] ?? ''),
        (string) ($entry['feature_image'] ?? ''),
        plus_entry_visual_media_urls($entry)
    ) as $media) {
        $url = (string) $media['url'];
        $filename = basename((string) ($media['original_name'] ?: (parse_url($url, PHP_URL_PATH) ?? $media['id'])));
        $items[] = [
            'filename' => $filename, 'markdown' => plus_media_editor_markup($media),
            'url' => $url, 'thumb_url' => $url, 'managed_media' => true,
            'media_id' => (string) $media['id'],
            'media_kind' => str_starts_with((string) $media['mime_type'], 'video/') ? 'video' : 'image',
            'default_alt' => trim((string) ($media['default_alt'] ?? '')),
        ];
    }
    return $items;
}

function plus_enhance_media_html(string $html): string
{
    $lower = strtolower($html);
    if ((!str_contains($lower, '<img') && !str_contains($lower, '<video')) || !is_file(plus_database_path())) {
        return $html;
    }
    $html = preg_replace_callback('/<img\b[^>]*>/i', static function (array $match): string {
        $tag = $match[0];
        if (!preg_match('/\ssrc=["\']([^"\']+)["\']/i', $tag, $source)) {
            return $tag;
        }
        $media = plus_media_by_url(html_entity_decode($source[1], ENT_QUOTES));
        if ($media === null) {
            return $tag;
        }
        $variants = (array) $media['variants'];
        $tag = preg_replace('/\s(?:srcset|sizes|width|height)=["\'][^"\']*["\']/i', '', $tag) ?? $tag;
        $attributes = '';
        if ((int) $media['width'] > 0 && (int) $media['height'] > 0) {
            $attributes .= ' width="' . (int) $media['width'] . '" height="' . (int) $media['height'] . '"';
        }
        if ($variants !== []) {
            ksort($variants, SORT_NUMERIC);
            $sources = [];
            foreach ($variants as $width => $url) {
                $sources[] = e((string) $url) . ' ' . (int) $width . 'w';
            }
            $attributes .= ' srcset="' . implode(', ', $sources) . '" sizes="(max-width: 720px) calc(100vw - 32px), 680px"';
        }
        return preg_replace('/^<img\b/i', '<img' . $attributes, $tag) ?? $tag;
    }, $html) ?? $html;
    return preg_replace_callback('/<video\b[^>]*>/i', static function (array $match): string {
        $tag = $match[0];
        if (preg_match('/\saria-label=["\'][^"\']*["\']/i', $tag)) return $tag;
        if (!preg_match('/\ssrc=["\']([^"\']+)["\']/i', $tag, $source)) return $tag;
        $description = plus_media_description(html_entity_decode($source[1], ENT_QUOTES | ENT_HTML5));
        if ($description === '') return $tag;
        return preg_replace('/>$/', ' aria-label="' . e($description) . '">', $tag, 1) ?? $tag;
    }, $html) ?? $html;
}

plus_register_capability('media.pipeline', 1, 0);

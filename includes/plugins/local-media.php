<?php

declare(strict_types=1);

plus_register_plugin([
    'id' => 'local-media',
    'name' => 'Local media',
    'name_key' => 'admin.settings.plugins.bundled.local_media.name',
    'description' => 'Stores originals and responsive variants on this server.',
    'description_key' => 'admin.settings.plugins.bundled.local_media.description',
    'version' => '1.0.0',
    'api_version' => PUREBLOG_PLUGIN_API_VERSION,
    'capabilities' => [
        'media.storage' => 'plus_local_media_store',
        'media.delete' => 'plus_local_media_delete',
    ],
    'settings' => [],
]);

/**
 * @param array<string,mixed> $asset
 * @param list<array<string,mixed>> $files
 * @return array{url:string,variants:array<string,string>}
 */
function plus_local_media_store(array $asset, array $files, array $settings): array
{
    $relativeDirectory = gmdate('Y/m', (int) ($asset['created_at'] ?? time()));
    $directory = rtrim(PUREBLOG_MEDIA_PATH, '/') . '/' . $relativeDirectory;
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create the local media directory.');
    }
    $root = realpath(PUREBLOG_MEDIA_PATH);
    $realDirectory = realpath($directory);
    if ($root === false || $realDirectory === false || !str_starts_with($realDirectory . '/', $root . '/')) {
        throw new RuntimeException('Local media directory is outside the media root.');
    }
    $stored = [];
    try {
        foreach ($files as $file) {
            $name = basename((string) $file['name']);
            $destination = $directory . '/' . $name;
            if (!copy((string) $file['path'], $destination)) {
                throw new RuntimeException('Unable to store local media.');
            }
            @chmod($destination, 0644);
            $stored[$file['role'] . ':' . ($file['width'] ?? '')] = $destination;
        }
    } catch (Throwable $error) {
        foreach ($stored as $path) {
            @unlink($path);
        }
        throw $error;
    }
    $base = rtrim(indieauth_base_url(), '/') . '/content/media/' . $relativeDirectory . '/';
    $url = '';
    $variants = [];
    foreach ($files as $file) {
        $fileUrl = $base . rawurlencode(basename((string) $file['name']));
        if ($file['role'] === 'original') {
            $url = $fileUrl;
        } elseif ($file['role'] === 'variant') {
            $variants[(string) (int) $file['width']] = $fileUrl;
        }
    }
    if ($url === '') {
        throw new RuntimeException('Local media result has no original URL.');
    }
    return [
        'url' => $url,
        'variants' => $variants,
        'provider_data' => ['files' => array_values(array_map(
            static fn(string $path): string => substr($path, strlen(rtrim(PUREBLOG_MEDIA_PATH, '/')) + 1),
            $stored
        ))],
        'rollback' => static function () use ($stored): void {
            foreach ($stored as $path) @unlink($path);
        },
    ];
}

function plus_local_media_delete(array $media, array $settings): void
{
    $root = realpath(PUREBLOG_MEDIA_PATH);
    if ($root === false) return;
    foreach ((array) ($media['provider_data']['files'] ?? []) as $relative) {
        if (!is_string($relative) || $relative === '' || str_contains($relative, '..')) {
            throw new RuntimeException('Stored local media path is invalid.');
        }
        $path = $root . '/' . ltrim($relative, '/');
        $parent = realpath(dirname($path));
        if ($parent === false && !is_file($path)) continue;
        if ($parent === false || !str_starts_with($parent . '/', $root . '/')) {
            throw new RuntimeException('Stored local media path is outside the media root.');
        }
        if (is_file($path) && !unlink($path)) throw new RuntimeException('Local media could not be deleted.');
    }
}

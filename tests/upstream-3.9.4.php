<?php

declare(strict_types=1);

require dirname(__DIR__) . '/functions.php';

function upstream_394_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$excerpt = get_excerpt('Spacing&nbsp;stays &mdash; readable.');
upstream_394_assert(!str_contains($excerpt, '&nbsp;'), 'The non-breaking-space entity remained visible in the excerpt.');
upstream_394_assert(str_contains($excerpt, '—'), 'The em-dash entity remained visible in the excerpt.');

$thumbnailFolder = PUREBLOG_CONTENT_IMAGES_PATH . '/upstream-394-test';
$thumbnailSource = $thumbnailFolder . '/sample.png';
if (class_exists('Imagick') || extension_loaded('gd')) {
    if (!is_dir($thumbnailFolder) && !mkdir($thumbnailFolder, 0755, true)) {
        throw new RuntimeException('Thumbnail fixture directory could not be created.');
    }
    file_put_contents(
        $thumbnailSource,
        base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true)
    );
    try {
        $thumbnailUrl = get_image_thumb_url('upstream-394-test', 'sample.png');
        upstream_394_assert(
            str_contains($thumbnailUrl, '/admin/thumb.php?slug=upstream-394-test&file=sample.png'),
            'Missing thumbnails are not deferred to the authenticated thumbnail endpoint.'
        );
        upstream_394_assert(!is_file($thumbnailFolder . '/thumbs/sample.png'), 'Listing an image generated its thumbnail synchronously.');
    } finally {
        @unlink($thumbnailSource);
        @rmdir($thumbnailFolder . '/thumbs');
        @rmdir($thumbnailFolder);
    }
}

upstream_394_assert(!is_file(dirname(__DIR__) . '/api.php'), 'The unreviewed generic REST API was included.');
upstream_394_assert(!function_exists('check_and_run_auto_update'), 'Automatic upstream updates were included.');

echo "upstream 3.9.4 integration tests: PASS\n";

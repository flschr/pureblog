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
$thumbnailSource = $thumbnailFolder . '/sample.gif';
if (class_exists('Imagick') || extension_loaded('gd')) {
    if (!is_dir($thumbnailFolder) && !mkdir($thumbnailFolder, 0755, true)) {
        throw new RuntimeException('Thumbnail fixture directory could not be created.');
    }
    file_put_contents(
        $thumbnailSource,
        base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==', true) . str_repeat('padding', 2048)
    );
    try {
        $thumbnailUrl = get_image_thumb_url('upstream-394-test', 'sample.gif');
        upstream_394_assert(
            str_contains($thumbnailUrl, '/admin/thumb.php?slug=upstream-394-test&file=sample.gif'),
            'Missing thumbnails are not deferred to the authenticated thumbnail endpoint.'
        );
        upstream_394_assert(!is_file($thumbnailFolder . '/thumbs/sample.gif'), 'Listing an image generated its thumbnail synchronously.');
        upstream_394_assert(
            generate_image_thumbnail($thumbnailSource, $thumbnailFolder . '/thumbs/sample.gif'),
            'The deferred thumbnail request could not create a thumbnail.'
        );
        upstream_394_assert(is_file($thumbnailFolder . '/thumbs/sample.gif'), 'The generated thumbnail was not atomically published.');
        upstream_394_assert(
            filesize($thumbnailFolder . '/thumbs/sample.gif') < filesize($thumbnailSource),
            'A small GIF was copied wholesale instead of being reduced to a single preview frame.'
        );
        upstream_394_assert(
            glob($thumbnailFolder . '/thumbs/sample.gif.*.tmp.*') === [],
            'Thumbnail generation left a temporary artifact behind.'
        );
    } finally {
        @unlink($thumbnailSource);
        @unlink($thumbnailFolder . '/thumbs/sample.gif');
        @rmdir($thumbnailFolder . '/thumbs');
        @rmdir($thumbnailFolder);
    }
}

upstream_394_assert(!is_file(dirname(__DIR__) . '/api.php'), 'The unreviewed generic REST API was included.');
upstream_394_assert(!function_exists('check_and_run_auto_update'), 'Automatic upstream updates were included.');
upstream_394_assert(!is_file(dirname(__DIR__) . '/setup.php'), 'The retired public setup wizard was included.');
upstream_394_assert(!is_file(dirname(__DIR__) . '/bin/setup-token.php'), 'The retired web-setup token command was included.');
upstream_394_assert(!is_file(dirname(__DIR__) . '/upgrade-notice.json'), 'Retired updater metadata was included.');
upstream_394_assert(!function_exists('create_site_backup_zip'), 'The browser backup archive path was included.');
upstream_394_assert(!array_key_exists('community', default_config()), 'The retired PureComments configuration was included.');
$postMeta = file_get_contents(dirname(__DIR__) . '/includes/post-meta.php');
upstream_394_assert(is_string($postMeta) && !str_contains($postMeta, 'PureComments') && !str_contains($postMeta, 'purecomments'), 'The PureComments embed was included.');

echo "upstream 3.9.4 integration tests: PASS\n";

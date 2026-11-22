<?php

declare(strict_types=1);

function test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function test_remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
}

$root = sys_get_temp_dir() . '/pureblog-content-' . bin2hex(random_bytes(8));
define('PUREBLOG_DATA_PATH', $root . '/data');
define('PUREBLOG_CONFIG_PATH', $root . '/config/config.php');
define('PUREBLOG_POSTS_PATH', $root . '/posts');
define('PUREBLOG_PAGES_PATH', $root . '/pages');
$GLOBALS['_pureblog_test_cache_clears'] = 0;
$GLOBALS['_pureblog_test_search_builds'] = 0;
$GLOBALS['_pureblog_test_tag_builds'] = 0;
$GLOBALS['_pureblog_test_hooks'] = [];
$GLOBALS['_pureblog_test_side_effects'] = [];

function cache_clear(): void
{
    $GLOBALS['_pureblog_test_cache_clears']++;
}

function load_config(bool $reload = false): array
{
    return ['timezone' => 'Europe/Berlin', 'date_format' => 'F j, Y'];
}

function _lang_translate_date(string $value): string
{
    return $value;
}

function build_search_index(): bool
{
    $GLOBALS['_pureblog_test_search_builds']++;
    return true;
}

function build_tag_index(): bool
{
    $GLOBALS['_pureblog_test_tag_builds']++;
    return true;
}

function plus_apply_media_alts_to_markdown(string $markdown, string $context = ''): string
{
    return $markdown;
}

function call_hook(string $name, array $args = []): void
{
    $GLOBALS['_pureblog_test_hooks'][] = $name;
}

function webmention_sender_content_saved(string $type, array $content, ?string $originalSlug, ?string $originalStatus): void
{
    $GLOBALS['_pureblog_test_side_effects'][] = 'webmention:' . $type;
}

function social_content_saved(array $post, ?string $originalSlug, ?string $originalStatus): void
{
    $GLOBALS['_pureblog_test_side_effects'][] = 'social:post';
}

function plus_emit_plugin_event(string $event, array $args = []): void
{
    $GLOBALS['_pureblog_test_side_effects'][] = 'plugin:' . $event;
}

try {
    require dirname(__DIR__) . '/includes/lib/content.php';

    test_assert(pureblog_valid_base_url('https://example.com/blog'), 'Valid HTTPS site URL was rejected.');
    test_assert(pureblog_valid_base_url('http://localhost:8000'), 'Local development URL was rejected.');
    test_assert(!pureblog_valid_base_url('http://example.com'), 'Insecure public site URL was accepted.');

    $configurationClosed = false;
    try {
        require_configured_site();
    } catch (RuntimeException $error) {
        $configurationClosed = $error->getMessage() === 'Site configuration is unavailable.';
    }
    test_assert($configurationClosed, 'Missing configuration did not fail closed.');

    $target = $root . '/content/post.md';
    test_assert(pureblog_atomic_write_file($target, 'first'), 'Initial atomic write failed.');
    test_assert(file_get_contents($target) === 'first', 'Initial atomic write was incomplete.');
    test_assert(pureblog_atomic_write_file($target, str_repeat('second', 1000)), 'Replacement write failed.');
    test_assert(file_get_contents($target) === str_repeat('second', 1000), 'Replacement write was torn.');
    test_assert(glob(dirname($target) . '/.*.tmp') === [], 'Atomic write left a temporary file.');

    $order = [];
    pureblog_with_content_mutation_lock(static function () use (&$order): void {
        $order[] = 'outer';
        pureblog_with_content_mutation_lock(static function () use (&$order): void {
            $order[] = 'inner';
        });
    });
    test_assert($order === ['outer', 'inner'], 'Content mutation lock is not reentrant.');
    test_assert((fileperms(PUREBLOG_DATA_PATH . '/content-mutations.lock') & 0777) === 0600, 'Mutation lock is not private.');

    test_assert(save_config(['site_title' => 'Cache contract']), 'Configuration could not be saved atomically.');
    test_assert($GLOBALS['_pureblog_test_cache_clears'] === 1, 'A successful configuration change left public page-cache entries active.');

    mkdir(PUREBLOG_POSTS_PATH, 0755, true);
    mkdir(PUREBLOG_PAGES_PATH, 0755, true);
    file_put_contents(PUREBLOG_POSTS_PATH . '/2026-11-21-gallery.md', "---\nslug: gallery\nstatus: draft\n---\n\nDraft\n");
    file_put_contents(PUREBLOG_PAGES_PATH . '/photos.md', "---\nslug: photos\nstatus: published\n---\n\nA deliberately large body is not needed for route conflict detection.\n");
    test_assert(!published_content_slug_exists('gallery') && published_content_slug_exists('photos'), 'Fast public-route conflict detection ignored publication state or page metadata.');

    $postPath = PUREBLOG_POSTS_PATH . '/2026-11-22-writer.md';
    file_put_contents($postPath, "---\ntitle: Writer\nslug: writer\ndate: 2026-11-22 12:00\nstatus: published\ntags: [one, two]\ndescription: Preserved\nlayout: essay\nfeature_image: /old.jpg\nsocial_announce: true\nmicropub_kind: article\ncustom_byline: Rene\n---\n\nBody stays intact.\n");
    get_all_posts(true, true);
    $cacheBefore = $GLOBALS['_pureblog_test_cache_clears'];
    $searchBefore = $GLOBALS['_pureblog_test_search_builds'];
    $tagBefore = $GLOBALS['_pureblog_test_tag_builds'];
    $saveError = '';
    test_assert(save_content_feature_image('post', 'writer', 'https://media.example.test/new.jpg', $saveError), 'Post feature image did not use the content writer: ' . $saveError);
    $savedPost = parse_post_file($postPath);
    $savedPostFront = $savedPost['front_matter'];
    test_assert(($savedPostFront['feature_image'] ?? '') === 'https://media.example.test/new.jpg', 'Post feature image was not saved.');
    test_assert(($savedPostFront['layout'] ?? '') === 'essay' && ($savedPostFront['custom_byline'] ?? '') === 'Rene', 'Post layout metadata was not preserved.');
    test_assert(($savedPostFront['micropub_kind'] ?? '') === 'article' && ($savedPostFront['social_announce'] ?? '') === 'true', 'Post protocol or social metadata was not preserved.');
    test_assert(trim($savedPost['content']) === 'Body stays intact.', 'Post body changed during feature-image save.');
    test_assert($GLOBALS['_pureblog_test_cache_clears'] === $cacheBefore + 1, 'Post feature-image save did not clear the cache exactly once.');
    test_assert($GLOBALS['_pureblog_test_search_builds'] === $searchBefore + 1 && $GLOBALS['_pureblog_test_tag_builds'] === $tagBefore + 1, 'Post feature-image save did not rebuild derived indexes exactly once.');
    test_assert(array_count_values($GLOBALS['_pureblog_test_hooks'])['on_post_updated'] === 1, 'Post update hook did not run exactly once.');
    test_assert($GLOBALS['_pureblog_test_side_effects'] === ['webmention:post', 'social:post', 'plugin:post.saved'], 'Post side effects did not use the canonical save lifecycle exactly once.');

    $pagePath = PUREBLOG_PAGES_PATH . '/writer-page.md';
    file_put_contents($pagePath, "---\ntitle: Writer page\nslug: writer-page\nstatus: published\ndescription: Preserved page\ninclude_in_nav: false\nfeature_image: /old-page.jpg\ncustom_banner: Keep me\n---\n\nPage body stays intact.\n");
    get_all_pages(true, true);
    $cacheBefore = $GLOBALS['_pureblog_test_cache_clears'];
    $searchBefore = $GLOBALS['_pureblog_test_search_builds'];
    $hooksBefore = count($GLOBALS['_pureblog_test_hooks']);
    $sideEffectsBefore = count($GLOBALS['_pureblog_test_side_effects']);
    test_assert(save_content_feature_image('page', 'writer-page', '', $saveError), 'Page feature image did not use the content writer: ' . $saveError);
    $savedPage = parse_post_file($pagePath);
    test_assert(!array_key_exists('feature_image', $savedPage['front_matter']), 'Removing the page feature image left stale front matter.');
    test_assert(($savedPage['front_matter']['include_in_nav'] ?? true) === false, 'Page navigation metadata was not preserved.');
    test_assert(($savedPage['front_matter']['custom_banner'] ?? '') === 'Keep me', 'Custom page metadata was not preserved.');
    test_assert(trim($savedPage['content']) === 'Page body stays intact.', 'Page body changed during feature-image save.');
    test_assert($GLOBALS['_pureblog_test_cache_clears'] === $cacheBefore + 1 && $GLOBALS['_pureblog_test_search_builds'] === $searchBefore + 1, 'Page feature-image save did not refresh derived state exactly once.');
    test_assert(array_slice($GLOBALS['_pureblog_test_hooks'], $hooksBefore) === ['on_page_updated'], 'Page update hook did not run exactly once.');
    test_assert(array_slice($GLOBALS['_pureblog_test_side_effects'], $sideEffectsBefore) === ['webmention:page'], 'Page side effects did not use the canonical save lifecycle exactly once.');
    test_assert(!save_content_feature_image('post', 'missing', '/missing.jpg', $saveError) && $saveError === 'Post not found.', 'Missing content was not rejected by the feature-image writer.');
    test_assert(!function_exists('update_front_matter_field'), 'The superseded direct front-matter writer is still available.');
    test_assert(!function_exists('maybe_publish_scheduled_posts'), 'The request-driven scheduler is still available.');

    $duePostPath = PUREBLOG_POSTS_PATH . '/2000-01-01-due.md';
    $futurePostPath = PUREBLOG_POSTS_PATH . '/2099-01-01-future.md';
    file_put_contents($duePostPath, "---\ntitle: Due\nslug: due\ndate: 2000-01-01 12:00\nstatus: scheduled\ntags: []\n---\n\nDue body.\n");
    file_put_contents($futurePostPath, "---\ntitle: Future\nslug: future\ndate: 2099-01-01 12:00\nstatus: scheduled\ntags: []\n---\n\nFuture body.\n");
    test_assert(publish_scheduled_posts() === 1, 'The explicit scheduler did not publish exactly one due post.');
    test_assert((parse_post_file($duePostPath)['front_matter']['status'] ?? '') === 'published', 'The due post remained scheduled.');
    test_assert((parse_post_file($futurePostPath)['front_matter']['status'] ?? '') === 'scheduled', 'The future post was published early.');
    test_assert(publish_scheduled_posts() === 0, 'The explicit scheduler published the same post twice.');

    $deletedContent = $root . '/content/delete.md';
    $deletedImages = $root . '/images/delete';
    mkdir($deletedImages, 0755, true);
    file_put_contents($deletedContent, 'delete me');
    file_put_contents($deletedImages . '/image.jpg', 'image');
    test_assert(pureblog_stage_content_delete($deletedContent, $deletedImages), 'Content deletion could not be staged.');
    test_assert(!file_exists($deletedContent) && !file_exists($deletedImages), 'Staged content deletion remained visible.');

    echo "content storage tests: PASS\n";
} finally {
    test_remove_tree($root);
}

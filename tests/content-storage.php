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

try {
    require dirname(__DIR__) . '/includes/lib/content.php';

    test_assert(pureblog_valid_base_url('https://example.com/blog'), 'Valid HTTPS site URL was rejected.');
    test_assert(pureblog_valid_base_url('http://localhost:8000'), 'Local development URL was rejected.');
    test_assert(!pureblog_valid_base_url('http://example.com'), 'Insecure public site URL was accepted.');

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

<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

verify_csrf();

$slug     = trim($_POST['slug'] ?? '');
$filename = trim($_POST['filename'] ?? '');
$redirect = base_path() . '/admin/images.php';

if ($slug === '' || $filename === '') {
    header('Location: ' . $redirect . '?delete_error=' . urlencode(t('admin.editor.error_delete_missing_data')));
    exit;
}

$baseDir = realpath(__DIR__ . '/../content/images');
if ($baseDir === false) {
    header('Location: ' . $redirect . '?delete_error=' . urlencode(t('admin.editor.error_image_folder_missing')));
    exit;
}

if (!is_safe_image_slug($slug)) {
    header('Location: ' . $redirect . '?delete_error=' . urlencode(t('admin.editor.error_image_invalid_path')));
    exit;
}

$targetDir  = $baseDir . '/' . $slug;
$targetFile = $targetDir . '/' . basename($filename);

if (!validate_image_path($baseDir, $targetDir) || !validate_image_path($baseDir, $targetFile)) {
    header('Location: ' . $redirect . '?delete_error=' . urlencode(t('admin.editor.error_image_invalid_path')));
    exit;
}

$deleteResult = pureblog_with_content_mutation_lock(static function () use ($targetFile, $targetDir, $filename): string {
    if (!is_file($targetFile)) return 'not_found';
    if (!unlink($targetFile)) return 'failed';
    $thumbFile = $targetDir . '/thumbs/' . basename($filename);
    if (is_file($thumbFile)) @unlink($thumbFile);
    $thumbDir = $targetDir . '/thumbs';
    if (is_dir($thumbDir) && !(glob($thumbDir . '/*') ?: [])) @rmdir($thumbDir);
    if (!(glob($targetDir . '/*') ?: [])) @rmdir($targetDir);
    return 'deleted';
});

if ($deleteResult === 'not_found') {
    header('Location: ' . $redirect . '?delete_error=' . urlencode(t('admin.editor.error_image_not_found')));
    exit;
}

if ($deleteResult !== 'deleted') {
    header('Location: ' . $redirect . '?delete_error=' . urlencode(t('admin.images.error_delete')));
    exit;
}

header('Location: ' . $redirect . '?deleted=1');
exit;

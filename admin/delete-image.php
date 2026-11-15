<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

verify_csrf();

$slug = trim($_POST['slug'] ?? '');
$date = trim($_POST['date'] ?? '');
$filename = trim($_POST['filename'] ?? '');
$editorType = trim($_POST['editor_type'] ?? 'post');

$redirect = $editorType === 'page'
    ? base_path() . '/admin/edit-page.php?slug=' . urlencode($slug)
    : base_path() . '/admin/edit-post.php?slug=' . urlencode($slug);

if ($slug === '' || ($editorType !== 'page' && $date === '') || $filename === '') {
    header('Location: ' . $redirect . '&upload_error=' . urlencode(t('admin.editor.error_delete_missing_data')));
    exit;
}

$folderName = $slug;
$baseDir = realpath(__DIR__ . '/../content/images');
if ($baseDir === false) {
    header('Location: ' . $redirect . '&upload_error=' . urlencode(t('admin.editor.error_image_folder_missing')));
    exit;
}

if (!is_safe_image_slug($folderName)) {
    header('Location: ' . $redirect . '&upload_error=' . urlencode(t('admin.editor.error_image_invalid_path')));
    exit;
}

$targetDir = $baseDir . '/' . $folderName;
$targetFile = $targetDir . '/' . basename($filename);

if (!validate_image_path($baseDir, $targetDir)) {
    header('Location: ' . $redirect . '&upload_error=' . urlencode(t('admin.editor.error_image_invalid_path')));
    exit;
}

if (!validate_image_path($baseDir, $targetFile)) {
    header('Location: ' . $redirect . '&upload_error=' . urlencode(t('admin.editor.error_image_invalid_path')));
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
    header('Location: ' . $redirect . '&upload_error=' . urlencode(t('admin.editor.error_image_not_found')));
    exit;
}

if ($deleteResult !== 'deleted') {
    header('Location: ' . $redirect . '&upload_error=' . urlencode(t('admin.editor.error_delete_failed')));
    exit;
}

header('Location: ' . $redirect);
exit;

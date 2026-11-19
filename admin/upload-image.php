<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

// Detect when post_max_size is exceeded (PHP empties $_POST and $_FILES on POST requests)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $postMaxSize = ini_get('post_max_size') ?: 'unknown';
    $error = t('admin.editor.error_upload_post_size', ['limit' => $postMaxSize]);
    $redirect = base_path() . '/admin/content.php?upload_error=' . urlencode($error);
    header('Location: ' . $redirect);
    exit;
}

verify_csrf();

$slug = trim($_POST['slug'] ?? '');
$date = trim($_POST['date'] ?? '');
$editorType = trim($_POST['editor_type'] ?? 'post');
$messages = [];
$errors = [];

$uploadField = isset($_FILES['media']) ? 'media' : 'image';
if ($slug === '') {
    $errors[] = t('admin.editor.error_upload_no_slug');
} elseif (!isset($_FILES[$uploadField])) {
    $errors[] = t('admin.editor.error_upload_no_file');
}

$fileList = [];
if (empty($errors) && isset($_FILES[$uploadField])) {
    if (is_array($_FILES[$uploadField]['name'])) {
        $count = count($_FILES[$uploadField]['name']);
        for ($i = 0; $i < $count; $i++) {
            if ($_FILES[$uploadField]['error'][$i] === UPLOAD_ERR_NO_FILE && $count > 1) {
                continue;
            }
            $fileList[] = [
                'name'     => (string) $_FILES[$uploadField]['name'][$i],
                'type'     => (string) ($_FILES[$uploadField]['type'][$i] ?? ''),
                'tmp_name' => (string) ($_FILES[$uploadField]['tmp_name'][$i] ?? ''),
                'error'    => (int) $_FILES[$uploadField]['error'][$i],
                'size'     => (int) $_FILES[$uploadField]['size'][$i],
            ];
        }
    } else {
        $fileList[] = [
            'name'     => (string) $_FILES[$uploadField]['name'],
            'type'     => (string) ($_FILES[$uploadField]['type'] ?? ''),
            'tmp_name' => (string) ($_FILES[$uploadField]['tmp_name'] ?? ''),
            'error'    => (int) $_FILES[$uploadField]['error'],
            'size'     => (int) $_FILES[$uploadField]['size'],
        ];
    }

    if (empty($fileList)) {
        $errors[] = t('admin.editor.error_upload_no_file');
    }
}

if (empty($errors)) {
    foreach ($fileList as $file) {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $uploadErr = $file['error'];
            $maxFileSize = ini_get('upload_max_filesize') ?: 'unknown';
            $errors[] = match ($uploadErr) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => t('admin.editor.error_upload_size', ['limit' => $maxFileSize]),
                UPLOAD_ERR_PARTIAL    => t('admin.editor.error_upload_partial'),
                UPLOAD_ERR_NO_FILE    => t('admin.editor.error_upload_no_file'),
                UPLOAD_ERR_NO_TMP_DIR => t('admin.editor.error_upload_no_tmp_dir'),
                UPLOAD_ERR_CANT_WRITE => t('admin.editor.error_upload_cant_write'),
                UPLOAD_ERR_EXTENSION  => t('admin.editor.error_upload_extension'),
                default               => t('admin.editor.error_upload_failed'),
            };
            continue;
        }

        try {
            $media = plus_store_uploaded_media($file, true, null, 'visual');
            if (str_starts_with((string) $media['mime_type'], 'video/')) {
                $messages[] = '<video controls playsinline preload="metadata" src="'
                    . htmlspecialchars((string) $media['url'], ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"></video>';
            } else {
                $altText = pathinfo(basename((string) $file['name']), PATHINFO_FILENAME) ?: 'image';
                $messages[] = '![' . str_replace([']', "\r", "\n"], ['\\]', ' ', ' '], $altText) . '](' . $media['url'] . ')';
            }
        } catch (Throwable $error) {
            $errors[] = $error instanceof InvalidArgumentException
                ? $error->getMessage()
                : t('admin.editor.error_upload_save') . ' (' . e((string) $file['name']) . ')';
        }
    }
}

$redirect = $editorType === 'page'
    ? base_path() . '/admin/edit-page.php?slug=' . urlencode($slug)
    : base_path() . '/admin/edit-post.php?slug=' . urlencode($slug);
if (!empty($messages)) {
    $redirect .= '&uploaded=' . urlencode(implode("\n\n", $messages));
}
if (!empty($errors)) {
    $redirect .= '&upload_error=' . urlencode(implode(' ', $errors));
}

header('Location: ' . $redirect);
exit;

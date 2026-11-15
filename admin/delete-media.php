<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
verify_csrf();
$id = trim((string) ($_POST['id'] ?? ''));
$redirect = base_path() . '/admin/images.php';
try {
    if (!plus_delete_media($id)) throw new RuntimeException(t('admin.editor.error_image_not_found'));
    header('Location: ' . $redirect . '?deleted=1');
} catch (Throwable $error) {
    header('Location: ' . $redirect . '?delete_error=' . urlencode($error->getMessage()));
}
exit;

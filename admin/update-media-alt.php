<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
verify_csrf();
$id = trim((string) ($_POST['id'] ?? ''));
$alt = (string) ($_POST['default_alt'] ?? '');
$redirect = base_path() . '/admin/images.php';
try {
    plus_set_media_default_alt($id, $alt);
    header('Location: ' . $redirect . '?alt_saved=1');
} catch (Throwable $error) {
    header('Location: ' . $redirect . '?alt_error=' . urlencode($error->getMessage()));
}
exit;

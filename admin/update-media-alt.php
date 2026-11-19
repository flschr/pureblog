<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
verify_csrf();
$id = trim((string) ($_POST['id'] ?? ''));
$alt = (string) ($_POST['default_alt'] ?? '');
$returnTo = (string) ($_POST['return_to'] ?? '');
$slug = trim((string) ($_POST['slug'] ?? ''));
$redirect = in_array($returnTo, ['post', 'page'], true) && $slug !== ''
    ? base_path() . '/admin/edit-' . $returnTo . '.php?slug=' . urlencode($slug)
    : base_path() . '/admin/images.php';
$separator = str_contains($redirect, '?') ? '&' : '?';
try {
    plus_update_media_default_alt($id, $alt);
    header('Location: ' . $redirect . $separator . 'alt_saved=1');
} catch (Throwable $error) {
    header('Location: ' . $redirect . $separator . 'alt_error=' . urlencode($error->getMessage()));
}
exit;

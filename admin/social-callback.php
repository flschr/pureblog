<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

try {
    if (isset($_GET['error'])) throw new RuntimeException('Authorization was declined.');
    social_complete_connection(trim((string) ($_GET['code'] ?? '')), trim((string) ($_GET['state'] ?? '')));
    header('Location: ' . base_path() . '/admin/settings-social.php?connected=1');
} catch (Throwable $error) {
    header('Location: ' . base_path() . '/admin/settings-social.php?error=' . rawurlencode($error->getMessage()));
}
exit;

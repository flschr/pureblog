<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
ob_start();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    verify_csrf();
    header('Allow: GET');
    http_response_code(405);
}

$adminTitle = t('admin.settings.updates.page_title');
require __DIR__ . '/../includes/admin-head.php';
?>
    <main class="mid">
        <h1><?= e(t('admin.settings.updates.heading')) ?></h1>
        <?php require __DIR__ . '/../includes/admin-settings-nav.php'; ?>
        <?php require __DIR__ . '/../includes/admin-notices.php'; ?>

        <section class="section-divider">
            <span class="title"><?= e(t('admin.settings.updates.section_version')) ?></span>
            <p><strong><?= e(t('admin.settings.updates.current_version')) ?></strong> <?= e(detect_current_pureblog_version()) ?></p>
            <p><?= e(t('admin.settings.updates.deployment_managed')) ?></p>
        </section>
    </main>
<?php require __DIR__ . '/../includes/admin-footer.php'; ?>

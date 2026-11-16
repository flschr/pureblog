<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$config = load_config();
$fontStack = font_stack_css($config['theme']['admin_font_stack'] ?? 'sans');
$error = trim((string) ($_GET['error'] ?? ''));
$notice = isset($_GET['connected']) ? t('admin.settings.social.connected') : '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !isset($_POST['admin_action_id'])) {
    verify_csrf();
    $action = (string) ($_POST['social_action'] ?? 'save');
    try {
        if ($action === 'connect') {
            header('Location: ' . social_begin_connection((string) ($_POST['instance_url'] ?? '')));
            exit;
        }
        if ($action === 'disconnect') {
            social_disconnect();
            $notice = t('admin.settings.social.disconnected');
        } elseif ($action === 'sync') {
            social_schedule_sync(time(), true);
            $notice = t('admin.settings.social.sync_queued');
        } else {
            social_set_settings(
                isset($_POST['public_enabled']),
                (string) ($_POST['announcement_template'] ?? social_announcement_template())
            );
            $notice = t('admin.settings.social.saved');
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$connection = social_connection();
$connected = social_connected($connection);
$adminTitle = t('admin.settings.social.page_title');
require __DIR__ . '/../includes/admin-head.php';
?>
<div class="admin-content">
    <header class="admin-content-header">
        <h1><?= e(t('admin.settings.social.heading')) ?></h1>
        <nav class="admin-actions"><?php require __DIR__ . '/../includes/admin-settings-nav.php'; ?></nav>
    </header>
    <?php if ($notice !== ''): ?><p class="notice"><?= e($notice) ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="notice delete"><?= e($error) ?></p><?php endif; ?>
    <p><?= e(t('admin.settings.social.intro')) ?></p>

    <?php if (!$connected): ?>
        <?php if (!empty($connection['account_name'])): ?>
            <p><?= e(t('admin.settings.social.frozen', ['account' => (string) $connection['account_name']])) ?></p>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="social_action" value="save">
                <label><input type="checkbox" name="public_enabled" value="1"<?= !empty($connection['public_enabled']) ? ' checked' : '' ?>> <?= e(t('admin.settings.social.public_enabled')) ?></label>
                <label for="announcement-template"><b><?= e(t('admin.settings.social.announcement_template')) ?></b></label>
                <textarea id="announcement-template" name="announcement_template" rows="5" maxlength="2000"><?= e(social_announcement_template($connection)) ?></textarea>
                <p class="tip"><?= e(t('admin.settings.social.announcement_template_tip')) ?></p>
                <button type="submit"><?= e(t('admin.settings.nav.save')) ?></button>
            </form>
        <?php endif; ?>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="social_action" value="connect">
            <label for="instance-url"><b><?= e(t('admin.settings.social.instance')) ?></b></label>
            <input id="instance-url" type="url" name="instance_url" value="<?= e((string) ($connection['instance_url'] ?? '')) ?>" placeholder="https://social.example" required>
            <button type="submit" class="save"><?= e(t('admin.settings.social.connect')) ?></button>
        </form>
    <?php else: ?>
        <section class="settings-section">
            <span class="title"><?= e(t('admin.settings.social.account')) ?></span>
            <p><a href="<?= e((string) $connection['account_url']) ?>" rel="me noopener noreferrer" target="_blank">@<?= e((string) $connection['account_name']) ?></a> · <?= e((string) $connection['instance_url']) ?></p>
            <?php if (!empty($connection['last_sync_at'])): ?><p><?= e(t('admin.settings.social.last_sync', ['date' => format_datetime_for_display(date('c', (int) $connection['last_sync_at']), $config)])) ?></p><?php endif; ?>
            <?php if (!empty($connection['last_error'])): ?><p class="notice delete"><?= e((string) $connection['last_error']) ?></p><?php endif; ?>
        </section>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="social_action" value="save">
            <label><input type="checkbox" name="public_enabled" value="1"<?= !empty($connection['public_enabled']) ? ' checked' : '' ?>> <?= e(t('admin.settings.social.public_enabled')) ?></label>
            <p class="tip"><?= e(t('admin.settings.social.public_tip')) ?></p>
            <label for="announcement-template"><b><?= e(t('admin.settings.social.announcement_template')) ?></b></label>
            <textarea id="announcement-template" name="announcement_template" rows="5" maxlength="2000"><?= e(social_announcement_template($connection)) ?></textarea>
            <p class="tip"><?= e(t('admin.settings.social.announcement_template_tip')) ?></p>
            <button type="submit" class="save"><?= e(t('admin.settings.nav.save')) ?></button>
        </form>
        <form method="post" class="inline-form">
            <?= csrf_field() ?><input type="hidden" name="social_action" value="sync">
            <button type="submit"><?= e(t('admin.settings.social.sync')) ?></button>
        </form>
        <form method="post" class="inline-form" onsubmit="return confirm('<?= e(t('admin.settings.social.disconnect_confirm')) ?>')">
            <?= csrf_field() ?><input type="hidden" name="social_action" value="disconnect">
            <button type="submit" class="delete"><?= e(t('admin.settings.social.disconnect')) ?></button>
        </form>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/admin-footer.php'; ?>

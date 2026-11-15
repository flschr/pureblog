<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$config = load_config();
$fontStack = font_stack_css($config['theme']['admin_font_stack'] ?? 'sans');
$notice = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf();
    $id = (int) ($_POST['webmention_id'] ?? 0);
    $action = (string) ($_POST['webmention_action'] ?? '');
    if ($id < 1) {
        $error = t('admin.webmentions.invalid');
    } elseif (in_array($action, ['verified', 'rejected'], true)) {
        if (webmention_review($id, $action)) {
            $notice = $action === 'verified' ? t('admin.webmentions.restored') : t('admin.webmentions.rejected');
        } else {
            $error = t('admin.webmentions.invalid');
        }
    } elseif ($action === 'verify' && webmention_get($id) !== null) {
        webmention_enqueue_verification($id, time());
        $notice = t('admin.webmentions.queued');
    } else {
        $error = t('admin.webmentions.invalid');
    }
}

$filter = (string) ($_GET['status'] ?? '');
$allowedFilters = ['pending', 'verified', 'rejected', 'invalid', 'deleted'];
$mentions = webmention_list(in_array($filter, $allowedFilters, true) ? $filter : null);
$adminTitle = t('admin.webmentions.page_title');
require __DIR__ . '/../includes/admin-head.php';
?>
    <h1><?= e(t('admin.webmentions.heading')) ?></h1>
    <p><?= e(t('admin.webmentions.intro')) ?></p>
    <?php if ($notice !== ''): ?><p class="notice"><?= e($notice) ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="notice delete"><?= e($error) ?></p><?php endif; ?>
    <p>
        <a href="?status="><?= e(t('admin.webmentions.all')) ?></a>
        <?php foreach ($allowedFilters as $status): ?> · <a href="?status=<?= e($status) ?>"><?= e(t('admin.webmentions.status_' . $status)) ?></a><?php endforeach; ?>
    </p>
    <?php if ($mentions === []): ?>
        <p><?= e(t('admin.webmentions.empty')) ?></p>
    <?php else: ?>
        <?php foreach ($mentions as $mention): ?>
            <article class="notice">
                <p><strong><?= e((string) ($mention['author_name'] ?: parse_url((string) $mention['source_url'], PHP_URL_HOST))) ?></strong> · <?= e(t('admin.webmentions.status_' . $mention['status'])) ?></p>
                <?php if ((string) $mention['excerpt'] !== ''): ?><blockquote><?= e((string) $mention['excerpt']) ?></blockquote><?php endif; ?>
                <p><a href="<?= e((string) $mention['source_url']) ?>" target="_blank" rel="noopener noreferrer"><?= e((string) $mention['source_url']) ?></a></p>
                <p><?= e(t('admin.webmentions.target')) ?>: <a href="<?= e((string) $mention['target_url']) ?>" target="_blank" rel="noopener noreferrer"><?= e((string) $mention['target_slug']) ?></a></p>
                <form method="post" class="inline-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="webmention_id" value="<?= (int) $mention['id'] ?>">
                    <?php if ((string) $mention['status'] === 'verified'): ?>
                        <button class="delete" type="submit" name="webmention_action" value="rejected"><?= e(t('admin.webmentions.reject')) ?></button>
                    <?php elseif ((string) $mention['status'] === 'rejected'): ?>
                        <button type="submit" name="webmention_action" value="verified"><?= e(t('admin.webmentions.restore')) ?></button>
                    <?php endif; ?>
                    <button type="submit" name="webmention_action" value="verify"><?= e(t('admin.webmentions.verify')) ?></button>
                </form>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>
<?php require __DIR__ . '/../includes/admin-footer.php'; ?>

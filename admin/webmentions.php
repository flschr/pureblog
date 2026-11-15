<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$config = load_config();
$fontStack = font_stack_css($config['theme']['admin_font_stack'] ?? 'sans');
$notice = '';
$error = '';
$direction = (string) ($_GET['direction'] ?? $_POST['direction'] ?? 'incoming');
$direction = $direction === 'outgoing' ? 'outgoing' : 'incoming';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['webmention_action'] ?? '');
    if ($direction === 'outgoing' && $action === 'retry') {
        if (webmention_sender_retry((int) ($_POST['delivery_id'] ?? 0))) {
            $notice = t('admin.webmentions.delivery_queued');
        } else {
            $error = t('admin.webmentions.invalid');
        }
    } else {
        $id = (int) ($_POST['webmention_id'] ?? 0);
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
}

$filter = (string) ($_GET['status'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
if ($direction === 'outgoing') {
    $allowedFilters = ['queued', 'sent', 'unsupported', 'failed'];
    $deliveries = webmention_sender_list(in_array($filter, $allowedFilters, true) ? $filter : null, $perPage + 1, ($page - 1) * $perPage);
    $mentions = [];
} else {
    $allowedFilters = ['pending', 'verified', 'rejected', 'invalid', 'deleted'];
    $mentions = webmention_list(in_array($filter, $allowedFilters, true) ? $filter : null, $perPage + 1, ($page - 1) * $perPage);
    $deliveries = [];
}
$items = $direction === 'outgoing' ? $deliveries : $mentions;
$hasNextPage = count($items) > $perPage;
if ($hasNextPage) {
    array_pop($items);
}
if ($direction === 'outgoing') {
    $deliveries = $items;
} else {
    $mentions = $items;
}
$adminTitle = t('admin.webmentions.page_title');
require __DIR__ . '/../includes/admin-head.php';
?>
    <h1><?= e(t('admin.webmentions.heading')) ?></h1>
    <p><?= e(t('admin.webmentions.intro')) ?></p>
    <p>
        <a href="?direction=incoming"><?= e(t('admin.webmentions.incoming')) ?></a> ·
        <a href="?direction=outgoing"><?= e(t('admin.webmentions.outgoing')) ?></a>
    </p>
    <?php if ($notice !== ''): ?><p class="notice"><?= e($notice) ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="notice delete"><?= e($error) ?></p><?php endif; ?>
    <p>
        <a href="?direction=<?= e($direction) ?>&amp;status="><?= e(t('admin.webmentions.all')) ?></a>
        <?php foreach ($allowedFilters as $status): ?> · <a href="?direction=<?= e($direction) ?>&amp;status=<?= e($status) ?>"><?= e(t('admin.webmentions.status_' . $status)) ?></a><?php endforeach; ?>
    </p>
    <?php if ($direction === 'outgoing'): ?>
        <?php if ($deliveries === []): ?>
            <p><?= e(t('admin.webmentions.no_deliveries')) ?></p>
        <?php else: ?>
            <?php foreach ($deliveries as $delivery): ?>
                <article class="notice">
                    <p><strong><?= e(t('admin.webmentions.status_' . $delivery['status'])) ?></strong></p>
                    <p><?= e(t('admin.webmentions.source')) ?>: <a href="<?= e((string) $delivery['source_url']) ?>" target="_blank" rel="noopener noreferrer"><?= e((string) $delivery['source_url']) ?></a></p>
                    <p><?= e(t('admin.webmentions.target')) ?>: <a href="<?= e((string) $delivery['target_url']) ?>" target="_blank" rel="noopener noreferrer"><?= e((string) $delivery['target_url']) ?></a></p>
                    <?php if ((string) $delivery['endpoint_url'] !== ''): ?><p><?= e(t('admin.webmentions.endpoint')) ?>: <?= e((string) $delivery['endpoint_url']) ?></p><?php endif; ?>
                    <?php if ((string) $delivery['last_error'] !== ''): ?><p><?= e((string) $delivery['last_error']) ?></p><?php endif; ?>
                    <form method="post" class="inline-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="direction" value="outgoing">
                        <input type="hidden" name="delivery_id" value="<?= (int) $delivery['id'] ?>">
                        <button type="submit" name="webmention_action" value="retry"><?= e(t('admin.webmentions.retry')) ?></button>
                    </form>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php elseif ($mentions === []): ?>
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
                    <input type="hidden" name="direction" value="incoming">
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
    <?php if ($page > 1 || $hasNextPage): ?>
        <p>
            <?php if ($page > 1): ?><a href="?direction=<?= e($direction) ?>&amp;status=<?= e($filter) ?>&amp;page=<?= $page - 1 ?>"><?= e(t('admin.webmentions.previous')) ?></a><?php endif; ?>
            <?php if ($page > 1 && $hasNextPage): ?> · <?php endif; ?>
            <?php if ($hasNextPage): ?><a href="?direction=<?= e($direction) ?>&amp;status=<?= e($filter) ?>&amp;page=<?= $page + 1 ?>"><?= e(t('admin.webmentions.next')) ?></a><?php endif; ?>
        </p>
    <?php endif; ?>
<?php require __DIR__ . '/../includes/admin-footer.php'; ?>

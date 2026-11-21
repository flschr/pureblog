<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$config = load_config();
if (!personal_photo_gallery_enabled($config)) {
    header('Location: ' . base_path() . '/admin/settings-plugins.php');
    exit;
}

$notice = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['gallery_action'] ?? '');
    try {
        if ($action === 'rebuild') {
            personal_photo_gallery_schedule_rebuild();
            $notice = t('admin.photo_gallery.rebuild_queued');
        } elseif (in_array($action, ['hide', 'show', 'auto'], true)) {
            $assetKey = (string) ($_POST['asset_key'] ?? '');
            personal_photo_gallery_set_visibility($assetKey, $action === 'auto' ? null : ($action === 'hide' ? 'hidden' : 'visible'));
            $notice = $action === 'hide' ? t('admin.photo_gallery.hidden') : ($action === 'show' ? t('admin.photo_gallery.shown') : t('admin.photo_gallery.automatic'));
        } else {
            throw new InvalidArgumentException(t('admin.photo_gallery.invalid_action'));
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$filter = (string) ($_GET['view'] ?? 'all');
if (!in_array($filter, ['all','visible','hidden','excluded','unknown'], true)) $filter = 'all';
$search = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 200);
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 30;
$items = personal_photo_gallery_admin_items($filter, $search, $perPage + 1, ($page - 1) * $perPage);
$hasNext = count($items) > $perPage;
if ($hasNext) array_pop($items);
$stats = personal_photo_gallery_stats();
$gallerySettings = personal_photo_gallery_settings($config);
$fontStack = font_stack_css($config['theme']['admin_font_stack'] ?? 'sans');
$adminTitle = t('admin.photo_gallery.page_title');
require __DIR__ . '/../includes/admin-head.php';
?>
<main class="mid photo-gallery-admin">
    <header class="admin-content-header">
        <div>
            <h1><?= e(t('admin.photo_gallery.heading')) ?></h1>
            <p><?= e(t('admin.photo_gallery.intro')) ?></p>
        </div>
        <div class="admin-actions">
            <a class="button" href="<?= e(base_path() . '/' . $gallerySettings['route']) ?>" target="_blank" rel="noopener noreferrer"><?= e(t('admin.photo_gallery.view_public')) ?></a>
            <form method="post">
                <?= csrf_field() ?>
                <button class="save" name="gallery_action" value="rebuild"><?= e(t('admin.photo_gallery.rebuild')) ?></button>
            </form>
        </div>
    </header>
    <?php if ($notice !== ''): ?><p class="notice" role="status"><?= e($notice) ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="notice delete" role="alert"><?= e($error) ?></p><?php endif; ?>
    <p class="photo-gallery-admin-stats">
        <?= e(t('admin.photo_gallery.stats', $stats)) ?>
    </p>
    <details class="content-filter"<?= $filter !== 'all' || $search !== '' ? ' open' : '' ?>>
        <summary class="content-filter-summary"><svg class="icon" aria-hidden="true"><use href="#icon-search"></use></svg><?= e(t('admin.photo_gallery.search_filter')) ?></summary>
        <form method="get" class="content-filter-form">
            <div class="content-filter-row">
                <div class="content-filter-search">
                    <label for="gallery-search"><?= e(t('admin.photo_gallery.search')) ?></label>
                    <input id="gallery-search" type="search" name="q" value="<?= e($search) ?>">
                </div>
                <div class="content-filter-selects">
                    <div class="content-filter-field">
                        <label for="gallery-view"><?= e(t('admin.photo_gallery.visibility')) ?></label>
                        <select id="gallery-view" name="view">
                            <?php foreach (['all','visible','hidden','excluded','unknown'] as $value): ?>
                                <option value="<?= e($value) ?>"<?= $filter === $value ? ' selected' : '' ?>><?= e(t('admin.photo_gallery.filter_' . $value)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="content-filter-actions">
                        <button><?= e(t('admin.photo_gallery.apply')) ?></button>
                        <?php if ($filter !== 'all' || $search !== ''): ?><a class="delete" href="<?= e(base_path()) ?>/admin/gallery.php"><?= e(t('admin.photo_gallery.reset')) ?></a><?php endif; ?>
                    </div>
                </div>
            </div>
        </form>
    </details>
    <?php if ($items === []): ?>
        <p><?= e(t('admin.photo_gallery.empty')) ?></p>
    <?php else: ?>
        <ul class="photo-gallery-admin-list">
            <?php foreach ($items as $item): ?>
                <?php
                $effective = (string) ($item['effective_state'] ?? 'unknown');
                $override = (string) ($item['override_visibility'] ?? '');
                $statusKey = $override === 'hidden' ? 'hidden' : ($override === 'visible' ? 'visible_manual' : $effective);
                $thumbnailAlt = (string) ($item['alt_text'] ?: $item['caption'] ?: $item['post_title']);
                ?>
                <li class="admin-list-item photo-gallery-admin-item">
                    <a class="photo-gallery-admin-thumb" href="<?= e((string) $item['url']) ?>" target="_blank" rel="noopener noreferrer">
                        <img src="<?= e((string) $item['preview_url']) ?>" alt="<?= e($thumbnailAlt) ?>" loading="lazy" width="<?= max(1, (int) $item['width']) ?>" height="<?= max(1, (int) $item['height']) ?>">
                    </a>
                    <div class="photo-gallery-admin-copy">
                        <strong class="admin-list-title"><?= e((string) ($item['caption'] ?: $item['alt_text'] ?: $item['post_title'])) ?></strong>
                        <div class="admin-list-meta">
                            <span><svg class="icon" aria-hidden="true"><use href="#icon-calendar"></use></svg><?= e(format_post_date_for_display((string) $item['published_at'], $config)) ?></span>
                            <span class="status <?= $effective === 'visible' ? 'published' : 'draft' ?>"><svg class="icon" aria-hidden="true"><use href="#icon-toggle-right"></use></svg><?= e(t('admin.photo_gallery.status_' . $statusKey)) ?></span>
                            <span title="<?= e((string) ($item['reason'] ?? '')) ?>"><?= e(t('admin.photo_gallery.class_' . ((string) ($item['visual_class'] ?? 'unknown')))) ?> · <?= e((string) round((float) ($item['confidence'] ?? 0) * 100)) ?>%</span>
                        </div>
                        <div class="admin-list-meta photo-gallery-admin-actions">
                            <a class="admin-list-view" href="<?= e(base_path() . '/' . rawurlencode((string) $item['post_slug'])) ?>" target="_blank" rel="noopener noreferrer"><svg class="icon" aria-hidden="true"><use href="#icon-eye"></use></svg><?= e(t('admin.photo_gallery.view_post')) ?></a>
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="asset_key" value="<?= e((string) $item['asset_key']) ?>">
                                <button name="gallery_action" value="<?= $effective === 'visible' ? 'hide' : 'show' ?>"><?= e($effective === 'visible' ? t('admin.photo_gallery.hide') : t('admin.photo_gallery.show')) ?></button>
                                <?php if ($override !== ''): ?><button class="delete" name="gallery_action" value="auto"><?= e(t('admin.photo_gallery.use_automatic')) ?></button><?php endif; ?>
                            </form>
                        </div>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php if ($page > 1 || $hasNext): ?>
        <nav class="pagination" aria-label="<?= e(t('admin.photo_gallery.pagination')) ?>">
            <?php $query = ['view' => $filter, 'q' => $search]; ?>
            <?= $page > 1 ? '<a href="?' . e(http_build_query($query + ['page' => $page - 1])) . '">' . e(t('admin.photo_gallery.previous')) . '</a>' : '<span></span>' ?>
            <?php if ($hasNext): ?><a href="?<?= e(http_build_query($query + ['page' => $page + 1])) ?>"><?= e(t('admin.photo_gallery.next')) ?></a><?php endif; ?>
        </nav>
    <?php endif; ?>
</main>
<?php require __DIR__ . '/../includes/admin-footer.php'; ?>

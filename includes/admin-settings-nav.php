<?php
$settingsUriPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
$bp = base_path();
if ($bp !== '' && str_starts_with($settingsUriPath, $bp)) {
    $settingsUriPath = substr($settingsUriPath, strlen($bp));
}
$settingsPath = trim($settingsUriPath, '/');
$settingsItems = [
    '/admin/settings-site.php'    => ['label' => t('admin.settings.nav.site'),    'icon' => 'globe'],
    '/admin/settings-theme.php'   => ['label' => t('admin.settings.nav.theme'),   'icon' => 'paintbrush'],
    '/admin/settings-css.php'     => ['label' => t('admin.settings.nav.css'),     'icon' => 'braces'],
    '/admin/settings-plugins.php' => ['label' => t('admin.settings.nav.plugins'), 'icon' => 'toggle-right'],
    '/admin/settings-social.php'  => ['label' => t('admin.settings.nav.social'),  'icon' => 'globe'],
    '/admin/settings-user.php'    => ['label' => t('admin.settings.nav.user'),    'icon' => 'user'],
    '/admin/settings-updates.php' => ['label' => t('admin.settings.nav.updates'), 'icon' => 'upgrade'],
];
$settingsSaveFormId = $settingsSaveFormId ?? '';
?>
<nav class="settings-tabs-nav" aria-label="<?= e(t('admin.nav.settings')) ?>">
    <ul class="settings-tabs-list">
        <?php foreach ($settingsItems as $href => $item): ?>
            <?php $isCurrent = $settingsPath === ltrim($href, '/'); ?>
            <li class="settings-tab-item">
                <a href="<?= e($bp . $href) ?>" class="settings-tab-link<?= $isCurrent ? ' active' : '' ?>">
                    <svg class="icon" aria-hidden="true"><use href="#icon-<?= e($item['icon']) ?>"></use></svg>
                    <span><?= e($item['label']) ?></span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</nav>
<?php if ($settingsSaveFormId !== ''): ?>
    <button class="save" type="submit" form="<?= e($settingsSaveFormId) ?>" aria-label="<?= e(t('admin.settings.nav.save')) ?>">
        <svg class="icon" aria-hidden="true"><use href="#icon-save"></use></svg>
        <?= e(t('admin.settings.nav.save')) ?>
    </button>
<?php endif; ?>

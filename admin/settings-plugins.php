<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$config = load_config();
$fontStack = font_stack_css($config['theme']['admin_font_stack'] ?? 'sans');
$plugins = plus_plugins();
$errors = [];
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $previousConfig = $config;
    verify_csrf();
    $prepared = plus_prepare_plugin_configuration($config, $_POST);
    foreach ($prepared['errors'] as $error) {
        $errors[] = match ($error['code']) {
            'required' => t('admin.settings.plugins.error_required', ['field' => $error['field'], 'plugin' => $error['plugin']]),
            'url' => t('admin.settings.plugins.error_url', ['field' => $error['field']]),
            default => t('admin.settings.plugins.error_provider'),
        };
    }

    if ($errors === []) {
        $config['plugins'] = $prepared['plugins'];
        if (save_config($config)) {
            $notice = t('admin.settings.plugins.notice_saved');
            $config = load_config(true);
            plus_emit_plugin_event('configuration.saved', [$config, $previousConfig], $config);
        } else {
            $errors[] = t('admin.settings.plugins.error_save');
        }
    }
}

$enabled = plus_enabled_plugin_ids($config);
$providers = (array) ($config['plugins']['providers'] ?? []);
$adminTitle = t('admin.settings.plugins.page_title');
$settingsSaveFormId = 'settings-form';
require __DIR__ . '/../includes/admin-head.php';
?>
<div class="admin-content">
    <header class="admin-content-header">
        <h1><?= e(t('admin.settings.plugins.heading')) ?></h1>
        <nav class="admin-actions"><?php require __DIR__ . '/../includes/admin-settings-nav.php'; ?></nav>
    </header>
    <?php if ($notice !== ''): ?><p class="notice"><?= e($notice) ?></p><?php endif; ?>
    <?php foreach ($errors as $error): ?><p class="notice delete"><?= e($error) ?></p><?php endforeach; ?>
    <p><?= e(t('admin.settings.plugins.intro')) ?></p>
    <form method="post" id="settings-form">
        <?= csrf_field() ?>
        <section class="settings-section">
            <span class="title"><?= e(t('admin.settings.plugins.section_providers')) ?></span>
            <label for="storage-provider"><b><?= e(t('admin.settings.plugins.storage_provider')) ?></b></label>
            <select id="storage-provider" name="provider[media.storage]">
                <?php foreach ($plugins as $id => $plugin): if (!isset($plugin['capabilities']['media.storage'])) continue; ?>
                    <option value="<?= e($id) ?>"<?= ($providers['media.storage'] ?? 'local-media') === $id ? ' selected' : '' ?>><?= e(plus_plugin_label($plugin, 'name')) ?></option>
                <?php endforeach; ?>
            </select>
            <label for="alt-provider"><b><?= e(t('admin.settings.plugins.alt_provider')) ?></b></label>
            <select id="alt-provider" name="provider[media.alt_suggestion]">
                <option value=""><?= e(t('admin.settings.plugins.none')) ?></option>
                <?php foreach ($plugins as $id => $plugin): if (!isset($plugin['capabilities']['media.alt_suggestion'])) continue; ?>
                    <option value="<?= e($id) ?>"<?= ($providers['media.alt_suggestion'] ?? '') === $id ? ' selected' : '' ?>><?= e(plus_plugin_label($plugin, 'name')) ?></option>
                <?php endforeach; ?>
            </select>
            <label for="photo-provider"><b><?= e(t('admin.settings.plugins.photo_provider')) ?></b></label>
            <select id="photo-provider" name="provider[media.photo_classification]">
                <option value=""><?= e(t('admin.settings.plugins.none')) ?></option>
                <?php foreach ($plugins as $id => $plugin): if (!isset($plugin['capabilities']['media.photo_classification'])) continue; ?>
                    <option value="<?= e($id) ?>"<?= ($providers['media.photo_classification'] ?? '') === $id ? ' selected' : '' ?>><?= e(plus_plugin_label($plugin, 'name')) ?></option>
                <?php endforeach; ?>
            </select>
        </section>
        <?php foreach ($plugins as $id => $plugin): ?>
            <section class="settings-section">
                <span class="title"><?= e(plus_plugin_label($plugin, 'name')) ?></span>
                <p><?= e(plus_plugin_label($plugin, 'description')) ?></p>
                <?php if ($id === 'local-media'): ?>
                    <p><b><?= e(t('admin.settings.plugins.required')) ?></b></p>
                    <input type="hidden" name="enabled[]" value="local-media">
                <?php else: ?>
                    <label><input type="checkbox" name="enabled[]" value="<?= e($id) ?>"<?= in_array($id, $enabled, true) ? ' checked' : '' ?>> <?= e(t('admin.settings.plugins.enabled')) ?></label>
                <?php endif; ?>
                <?php $values = plus_plugin_settings($id, $config); ?>
                <?php foreach ($plugin['settings'] as $key => $definition): ?>
                    <label for="plugin-<?= e($id . '-' . $key) ?>"><b><?= e(plus_plugin_setting_label($definition, $key)) ?></b></label>
                    <?php if (($definition['type'] ?? '') === 'checkbox'): ?>
                        <input id="plugin-<?= e($id . '-' . $key) ?>" type="checkbox" name="settings[<?= e($id) ?>][<?= e($key) ?>]" value="1"<?= !empty($values[$key]) ? ' checked' : '' ?>>
                    <?php elseif (($definition['type'] ?? '') === 'select'): ?>
                        <select id="plugin-<?= e($id . '-' . $key) ?>" name="settings[<?= e($id) ?>][<?= e($key) ?>]">
                            <?php foreach ((array) ($definition['options'] ?? []) as $optionValue => $optionLabel): ?>
                                <option value="<?= e((string) $optionValue) ?>"<?= (string) ($values[$key] ?? '') === (string) $optionValue ? ' selected' : '' ?>><?= e((string) $optionLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input id="plugin-<?= e($id . '-' . $key) ?>" type="<?= e($definition['type'] === 'password' ? 'password' : ($definition['type'] === 'url' ? 'url' : 'text')) ?>" name="settings[<?= e($id) ?>][<?= e($key) ?>]" value="<?= $definition['type'] === 'password' ? '' : e((string) ($values[$key] ?? '')) ?>">
                        <?php if ($definition['type'] === 'password' && ($values[$key] ?? '') !== ''): ?><p class="tip"><?= e(t('admin.settings.plugins.secret_preserved')) ?></p><?php endif; ?>
                    <?php endif; ?>
                <?php endforeach; ?>
            </section>
        <?php endforeach; ?>
    </form>
</div>
<?php require __DIR__ . '/../includes/admin-footer.php'; ?>

<?php

declare(strict_types=1);

const PUREBLOG_PLUGIN_API_VERSION = 1;

/** @var array<string,array<string,mixed>> */
$GLOBALS['_pureblog_plus_plugins'] ??= [];
$GLOBALS['_pureblog_plus_plugins_loaded'] ??= false;

/**
 * Register a plugin that is already trusted and bundled with this release.
 *
 * @param array<string,mixed> $plugin
 */
function plus_register_plugin(array $plugin): void
{
    $id = (string) ($plugin['id'] ?? '');
    $name = trim((string) ($plugin['name'] ?? ''));
    $version = (string) ($plugin['version'] ?? '');
    $apiVersion = (int) ($plugin['api_version'] ?? 0);
    $capabilities = $plugin['capabilities'] ?? [];
    $settings = $plugin['settings'] ?? [];

    if (preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/', $id) !== 1) {
        throw new InvalidArgumentException('Invalid plugin identifier.');
    }
    if ($name === '' || preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version) !== 1) {
        throw new InvalidArgumentException('Invalid plugin metadata: ' . $id);
    }
    if ($apiVersion !== PUREBLOG_PLUGIN_API_VERSION) {
        throw new RuntimeException('Plugin API is incompatible: ' . $id);
    }
    if (!is_array($capabilities) || !is_array($settings)) {
        throw new InvalidArgumentException('Invalid plugin contract: ' . $id);
    }
    foreach ($capabilities as $capability => $handler) {
        if (!is_string($capability)
            || preg_match('/^[a-z][a-z0-9]*(?:\.[a-z0-9_]+)+$/', $capability) !== 1
            || !is_callable($handler)) {
            throw new InvalidArgumentException('Invalid plugin capability: ' . $id);
        }
    }
    foreach ($settings as $key => $definition) {
        if (!is_string($key)
            || preg_match('/^[a-z][a-z0-9_]*$/', $key) !== 1
            || !is_array($definition)
            || !in_array($definition['type'] ?? '', ['text', 'password', 'url', 'select', 'checkbox'], true)) {
            throw new InvalidArgumentException('Invalid plugin setting: ' . $id);
        }
    }
    if (isset($GLOBALS['_pureblog_plus_plugins'][$id])) {
        throw new LogicException('Plugin already registered: ' . $id);
    }
    $plugin['description'] = trim((string) ($plugin['description'] ?? ''));
    $plugin['capabilities'] = $capabilities;
    $plugin['settings'] = $settings;
    $GLOBALS['_pureblog_plus_plugins'][$id] = $plugin;
}

function plus_load_bundled_plugins(): void
{
    if ($GLOBALS['_pureblog_plus_plugins_loaded']) {
        return;
    }
    $GLOBALS['_pureblog_plus_plugins_loaded'] = true;
    foreach (['local-media.php', 's3-media.php', 'openai-alt-text.php'] as $filename) {
        $path = PUREBLOG_BUNDLED_PLUGINS_PATH . '/' . $filename;
        if (!is_file($path)) {
            throw new RuntimeException('Bundled plugin is missing: ' . $filename);
        }
        require $path;
    }
}

/** @return array<string,array<string,mixed>> */
function plus_plugins(): array
{
    plus_load_bundled_plugins();
    return $GLOBALS['_pureblog_plus_plugins'];
}

function plus_plugin_label(array $plugin, string $field): string
{
    $key = trim((string) ($plugin[$field . '_key'] ?? ''));
    return $key !== '' ? t($key) : (string) ($plugin[$field] ?? '');
}

function plus_plugin_setting_label(array $definition, string $fallback): string
{
    $key = trim((string) ($definition['label_key'] ?? ''));
    return $key !== '' ? t($key) : (string) ($definition['label'] ?? $fallback);
}

/** @return list<string> */
function plus_enabled_plugin_ids(?array $config = null): array
{
    $config ??= load_config();
    $enabled = array_values(array_unique(array_filter(
        (array) ($config['plugins']['enabled'] ?? ['local-media']),
        static fn(mixed $id): bool => is_string($id)
    )));
    if (!in_array('local-media', $enabled, true)) {
        $enabled[] = 'local-media';
    }
    return array_values(array_intersect($enabled, array_keys(plus_plugins())));
}

function plus_plugin_is_enabled(string $id, ?array $config = null): bool
{
    return in_array($id, plus_enabled_plugin_ids($config), true);
}

/** @return array<string,mixed> */
function plus_plugin_settings(string $id, ?array $config = null): array
{
    $config ??= load_config();
    $plugin = plus_plugins()[$id] ?? null;
    if ($plugin === null) {
        return [];
    }
    $saved = (array) ($config['plugins']['settings'][$id] ?? []);
    $result = [];
    foreach ($plugin['settings'] as $key => $definition) {
        $result[$key] = array_key_exists($key, $saved) ? $saved[$key] : ($definition['default'] ?? '');
    }
    return $result;
}

/** @return array{id:string,plugin:array<string,mixed>,handler:callable,settings:array<string,mixed>} */
function plus_plugin_provider(string $capability, ?string $providerId = null, ?array $config = null): array
{
    $config ??= load_config();
    $providerId ??= (string) ($config['plugins']['providers'][$capability] ?? '');
    if ($providerId === '' && $capability === 'media.storage') {
        $providerId = 'local-media';
    }
    $plugin = plus_plugins()[$providerId] ?? null;
    if ($plugin === null || !plus_plugin_is_enabled($providerId, $config)) {
        throw new RuntimeException('Configured plugin provider is not enabled: ' . $capability);
    }
    $handler = $plugin['capabilities'][$capability] ?? null;
    if (!is_callable($handler)) {
        throw new RuntimeException('Configured plugin does not provide: ' . $capability);
    }
    return [
        'id' => $providerId,
        'plugin' => $plugin,
        'handler' => $handler,
        'settings' => plus_plugin_settings($providerId, $config),
    ];
}

/**
 * @param array<string,mixed> $currentConfig
 * @param array<string,mixed> $input
 * @return array{plugins:array<string,mixed>,errors:list<array<string,string>>}
 */
function plus_prepare_plugin_configuration(array $currentConfig, array $input): array
{
    $plugins = plus_plugins();
    $requestedEnabled = array_values(array_filter((array) ($input['enabled'] ?? []), 'is_string'));
    $storageProvider = trim((string) ($input['provider']['media.storage'] ?? 'local-media'));
    $altProvider = trim((string) ($input['provider']['media.alt_suggestion'] ?? ''));
    $requestedEnabled[] = 'local-media';
    if ($storageProvider !== '') $requestedEnabled[] = $storageProvider;
    if ($altProvider !== '') $requestedEnabled[] = $altProvider;
    $enabled = array_values(array_unique(array_intersect($requestedEnabled, array_keys($plugins))));
    $errors = [];
    foreach (['media.storage' => $storageProvider, 'media.alt_suggestion' => $altProvider] as $capability => $providerId) {
        if ($providerId === '' && $capability === 'media.alt_suggestion') continue;
        if (!isset($plugins[$providerId]['capabilities'][$capability]) || !in_array($providerId, $enabled, true)) {
            $errors[] = ['code' => 'provider', 'field' => $capability, 'plugin' => $providerId];
        }
    }

    $settings = (array) ($currentConfig['plugins']['settings'] ?? []);
    foreach ($plugins as $id => $plugin) {
        $submitted = (array) ($input['settings'][$id] ?? []);
        $current = (array) ($settings[$id] ?? []);
        foreach ($plugin['settings'] as $key => $definition) {
            $type = (string) $definition['type'];
            $value = $type === 'checkbox' ? !empty($submitted[$key]) : trim((string) ($submitted[$key] ?? ''));
            if ($type === 'password' && $value === '' && isset($current[$key])) $value = $current[$key];
            if ($value === '' && array_key_exists('default', $definition) && $type !== 'password') {
                $value = $definition['default'];
            }
            $isSelected = $id === $storageProvider || $id === $altProvider;
            if ($isSelected && $value === '') {
                $errors[] = ['code' => 'required', 'field' => plus_plugin_setting_label($definition, $key), 'plugin' => plus_plugin_label($plugin, 'name')];
            }
            if ($value !== '' && $type === 'url'
                && (!filter_var($value, FILTER_VALIDATE_URL) || parse_url($value, PHP_URL_SCHEME) !== 'https')) {
                $errors[] = ['code' => 'url', 'field' => plus_plugin_setting_label($definition, $key), 'plugin' => plus_plugin_label($plugin, 'name')];
            }
            $settings[$id][$key] = $value;
        }
    }
    return [
        'plugins' => [
            'enabled' => $enabled,
            'providers' => ['media.storage' => $storageProvider, 'media.alt_suggestion' => $altProvider],
            'settings' => $settings,
        ],
        'errors' => $errors,
    ];
}

plus_register_capability('plugins.bundled', 1, 0);

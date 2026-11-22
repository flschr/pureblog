<?php

declare(strict_types=1);

$root = sys_get_temp_dir() . '/pureblog-runtime-boundary-' . bin2hex(random_bytes(8));
mkdir($root . '/user', 0700, true);
mkdir($root . '/core', 0700, true);
$runtimeHooks = $root . '/runtime-hooks.php';
file_put_contents($runtimeHooks, "<?php \$GLOBALS['_pureblog_runtime_hooks_loaded'] = true;\n");
file_put_contents($root . '/user/header.php', "<?php \$GLOBALS['_pureblog_user_template_loaded'] = true;\n");
file_put_contents($root . '/core/header.php', "<?php\n");

putenv('PUREBLOG_DISABLE_LOCAL_PHP=1');
putenv('PUREBLOG_RUNTIME_HOOKS_PATH=' . $runtimeHooks);
require dirname(__DIR__) . '/functions.php';

try {
    if (empty($GLOBALS['_pureblog_runtime_hooks_loaded'])) {
        throw new RuntimeException('The explicit runtime hooks file was not loaded.');
    }
    if (resolve_template_file('header', $root . '/user', $root . '/core') !== $root . '/core/header.php') {
        throw new RuntimeException('A writable user template remained executable.');
    }
    if (resolve_custom_route_template('route.php') !== null || get_layouts() !== []) {
        throw new RuntimeException('Local PHP routes or layouts remained enabled.');
    }
    try {
        render_layout_file($root . '/user/header.php', [], [], []);
        throw new RuntimeException('A local PHP layout was executed.');
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'Local PHP layouts are disabled.') {
            throw $error;
        }
    }
    if (!empty($GLOBALS['_pureblog_user_template_loaded'])) {
        throw new RuntimeException('A writable user template was included.');
    }
    echo "PASS runtime PHP boundary\n";
} finally {
    @unlink($root . '/user/header.php');
    @unlink($root . '/core/header.php');
    @unlink($runtimeHooks);
    @rmdir($root . '/user');
    @rmdir($root . '/core');
    @rmdir($root);
}

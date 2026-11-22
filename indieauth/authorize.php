<?php

declare(strict_types=1);

require dirname(__DIR__) . '/functions.php';
require_configured_site();
if (!indieauth_server_available()) {
    indieauth_send_json(['error' => 'temporarily_unavailable'], 503);
}
start_admin_session();
maybe_restore_admin_from_cookie();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['grant_type'])) {
    try {
        if ((string) $_POST['grant_type'] !== 'authorization_code') {
            throw new IndieAuthProtocolException('unsupported_grant_type', 'Only authorization_code is supported.');
        }
        $response = indieauth_exchange_authorization_code(
            (string) ($_POST['code'] ?? ''),
            indieauth_validate_client_id((string) ($_POST['client_id'] ?? '')),
            indieauth_validate_redirect_uri((string) ($_POST['redirect_uri'] ?? '')),
            (string) ($_POST['code_verifier'] ?? ''),
            false
        );
        unset($response['scope']);
        indieauth_send_json($response);
    } catch (IndieAuthProtocolException $error) {
        indieauth_send_error($error);
    } catch (Throwable) {
        indieauth_send_json(['error' => 'server_error', 'error_description' => 'The authorization request could not be completed.'], 500);
    }
}

$pending = $_SESSION['indieauth_pending_request'] ?? null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && isset($_GET['client_id'])) {
    try {
        $pending = indieauth_validate_authorization_request($_GET);
        $_SESSION['indieauth_pending_request'] = $pending;
    } catch (IndieAuthProtocolException $error) {
        indieauth_send_error($error);
    }
}

if (!is_array($pending) || time() - (int) ($pending['created_at'] ?? 0) > 600) {
    unset($_SESSION['indieauth_pending_request']);
    indieauth_send_json(['error' => 'invalid_request', 'error_description' => 'Authorization request is missing or expired.'], 400);
}

indieauth_send_interaction_headers((string) $pending['redirect_uri']);

if (!is_admin_logged_in()) {
    set_admin_login_return_path(base_path() . '/indieauth/authorize.php');
    header('Location: ' . base_path() . '/admin/index.php', true, 303);
    exit;
}

$errorMessage = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf();
    $decision = (string) ($_POST['decision'] ?? '');
    if ($decision === 'deny') {
        unset($_SESSION['indieauth_pending_request']);
        $location = indieauth_append_query((string) $pending['redirect_uri'], [
            'error' => 'access_denied',
            'state' => (string) $pending['state'],
            'iss' => indieauth_issuer(),
        ]);
        header('Location: ' . $location, true, 303);
        exit;
    }
    if ($decision === 'approve') {
        $requested = array_values(array_filter((array) ($pending['scope'] ?? []), 'is_string'));
        $approvedInput = isset($_POST['scope']) && is_array($_POST['scope']) ? $_POST['scope'] : [];
        $approved = [];
        foreach ($approvedInput as $scope) {
            if (is_string($scope)
                && in_array($scope, $requested, true)
                && in_array($scope, indieauth_supported_scopes(), true)) {
                $approved[$scope] = true;
            }
        }
        if ($requested !== [] && $approved === []) {
            $errorMessage = t('admin.indieauth.error_scope_required');
        } else {
            $code = indieauth_issue_authorization_code(
                (string) $pending['client_id'],
                (string) $pending['redirect_uri'],
                (string) $pending['me'],
                array_keys($approved),
                (string) $pending['code_challenge']
            );
            unset($_SESSION['indieauth_pending_request']);
            $location = indieauth_append_query((string) $pending['redirect_uri'], [
                'code' => $code,
                'state' => (string) $pending['state'],
                'iss' => indieauth_issuer(),
            ]);
            header('Location: ' . $location, true, 303);
            exit;
        }
    }
}

$config = load_config();
$fontStack = font_stack_css($config['theme']['admin_font_stack'] ?? 'sans');
$adminTitle = t('admin.indieauth.page_title');
$hideAdminNav = true;
$allowExternalAdminAssets = false;
$supported = indieauth_supported_scopes();
require dirname(__DIR__) . '/includes/admin-head.php';
?>
    <main class="narrow">
        <br>
        <h1><?= e(t('admin.indieauth.heading')) ?></h1>
        <p><?= e(t('admin.indieauth.intro', ['client' => (string) $pending['client_name']])) ?></p>
        <?php if ($errorMessage !== ''): ?>
            <p class="notice delete"><?= e($errorMessage) ?></p>
        <?php endif; ?>
        <dl>
            <dt><strong><?= e(t('admin.indieauth.client')) ?></strong></dt>
            <dd><a href="<?= e((string) $pending['client_uri']) ?>" rel="noopener noreferrer" target="_blank"><?= e((string) $pending['client_id']) ?></a></dd>
            <dt><strong><?= e(t('admin.indieauth.redirect')) ?></strong></dt>
            <dd><code><?= e((string) $pending['redirect_uri']) ?></code></dd>
        </dl>
        <form method="post">
            <?= csrf_field() ?>
            <?php if (!empty($pending['scope'])): ?>
                <fieldset>
                    <legend><?= e(t('admin.indieauth.permissions')) ?></legend>
                    <?php foreach ((array) $pending['scope'] as $scope): ?>
                        <?php $isSupported = is_string($scope) && in_array($scope, $supported, true); ?>
                        <label class="checkbox-label">
                            <input type="checkbox" name="scope[]" value="<?= e((string) $scope) ?>"<?= $isSupported ? ' checked' : ' disabled' ?>>
                            <code><?= e((string) $scope) ?></code>
                            <?php if (!$isSupported): ?> — <?= e(t('admin.indieauth.unsupported_scope')) ?><?php endif; ?>
                        </label>
                    <?php endforeach; ?>
                </fieldset>
            <?php else: ?>
                <p><?= e(t('admin.indieauth.sign_in_only')) ?></p>
            <?php endif; ?>
            <div style="display:flex; gap:0.5rem; margin-top:1.25rem;">
                <button type="submit" name="decision" value="approve"><?= e(t('admin.indieauth.approve')) ?></button>
                <button class="delete" type="submit" name="decision" value="deny"><?= e(t('admin.indieauth.deny')) ?></button>
            </div>
        </form>
    </main>
<?php require dirname(__DIR__) . '/includes/admin-footer.php'; ?>

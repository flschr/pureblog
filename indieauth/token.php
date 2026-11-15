<?php

declare(strict_types=1);

require dirname(__DIR__) . '/functions.php';

if (!is_installed() || !indieauth_server_available()) {
    indieauth_send_json(['error' => 'temporarily_unavailable'], 503);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    indieauth_send_json(['error' => 'invalid_request', 'error_description' => 'The token endpoint requires POST.'], 405);
}

try {
    if ((string) ($_POST['grant_type'] ?? '') !== 'authorization_code') {
        throw new IndieAuthProtocolException('unsupported_grant_type', 'Only authorization_code is supported.');
    }
    $clientId = indieauth_validate_client_id((string) ($_POST['client_id'] ?? ''));
    $redirectUri = indieauth_validate_redirect_uri((string) ($_POST['redirect_uri'] ?? ''));
    $response = indieauth_exchange_authorization_code(
        (string) ($_POST['code'] ?? ''),
        $clientId,
        $redirectUri,
        (string) ($_POST['code_verifier'] ?? ''),
        true
    );
    indieauth_send_json($response);
} catch (IndieAuthProtocolException $error) {
    indieauth_send_error($error);
} catch (Throwable) {
    indieauth_send_json(['error' => 'server_error', 'error_description' => 'The token request could not be completed.'], 500);
}

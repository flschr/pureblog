<?php

declare(strict_types=1);

require dirname(__DIR__) . '/functions.php';

if (!is_installed() || !indieauth_server_available()) {
    indieauth_send_json(['error' => 'temporarily_unavailable'], 503);
}

indieauth_send_json([
    'issuer' => indieauth_issuer(),
    'authorization_endpoint' => indieauth_endpoint_url('indieauth/authorize.php'),
    'token_endpoint' => indieauth_endpoint_url('indieauth/token.php'),
    'code_challenge_methods_supported' => ['S256'],
    'grant_types_supported' => ['authorization_code'],
    'response_types_supported' => ['code'],
    'scopes_supported' => indieauth_supported_scopes(),
    'authorization_response_iss_parameter_supported' => true,
]);

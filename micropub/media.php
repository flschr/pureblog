<?php

declare(strict_types=1);

require dirname(__DIR__) . '/functions.php';

if (!is_installed() || !indieauth_server_available()) {
    micropub_send_json(['error' => 'temporarily_unavailable'], 503);
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        header('Allow: POST');
        throw new MicropubProtocolException('invalid_request', 'The media endpoint requires POST.', 405);
    }
    micropub_require_token('media');
    $file = $_FILES['file'] ?? null;
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new MicropubProtocolException('invalid_request', 'A complete file upload is required.');
    }
    $media = plus_store_uploaded_media($file);
    http_response_code(201);
    header('Location: ' . $media['url']);
    exit;
} catch (InvalidArgumentException $error) {
    micropub_send_json(['error' => 'invalid_request', 'error_description' => $error->getMessage()], 400);
} catch (MicropubProtocolException $error) {
    micropub_send_error($error);
} catch (Throwable) {
    micropub_send_json(['error' => 'server_error', 'error_description' => 'The media upload could not be completed.'], 500);
}

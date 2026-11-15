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
    $temporary = (string) ($file['tmp_name'] ?? '');
    $actualSize = is_uploaded_file($temporary) ? filesize($temporary) : false;
    if ($actualSize === false || $actualSize <= 0 || $actualSize > 104857600) {
        throw new MicropubProtocolException('invalid_request', 'The file is empty or exceeds 100 MB.');
    }
    $types = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif',
        'image/webp' => 'webp', 'image/avif' => 'avif',
        'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'audio/ogg' => 'ogg',
        'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/flac' => 'flac',
        'video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov',
    ];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temporary) ?: '';
    if (!isset($types[$mime])) {
        throw new MicropubProtocolException('invalid_request', 'The file type is not supported.');
    }
    $relativeDirectory = 'content/media/' . gmdate('Y/m');
    $mediaRoot = PUREBLOG_BASE_PATH . '/content/media';
    $directory = PUREBLOG_BASE_PATH . '/' . $relativeDirectory;
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create the media directory.');
    }
    $realRoot = realpath($mediaRoot);
    $realDirectory = realpath($directory);
    if ($realRoot === false || $realDirectory === false || !str_starts_with($realDirectory . '/', $realRoot . '/')) {
        throw new RuntimeException('The media directory is outside the local media root.');
    }
    $filename = bin2hex(random_bytes(16)) . '.' . $types[$mime];
    $destination = $directory . '/' . $filename;
    if (!move_uploaded_file($temporary, $destination)) {
        throw new RuntimeException('Unable to store the uploaded file.');
    }
    if (str_starts_with($mime, 'image/')) {
        strip_image_metadata($destination, $mime);
        call_hook('on_image_uploaded', [$destination]);
    }
    @chmod($destination, 0644);
    http_response_code(201);
    header('Location: ' . indieauth_base_url() . '/' . $relativeDirectory . '/' . $filename);
    exit;
} catch (MicropubProtocolException $error) {
    micropub_send_error($error);
} catch (Throwable) {
    micropub_send_json(['error' => 'server_error', 'error_description' => 'The media upload could not be completed.'], 500);
}

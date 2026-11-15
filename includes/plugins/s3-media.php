<?php

declare(strict_types=1);

plus_register_plugin([
    'id' => 's3-media',
    'name' => 'S3-compatible media',
    'name_key' => 'admin.settings.plugins.bundled.s3_media.name',
    'description' => 'Stores media in an S3-compatible bucket and serves it from a configured public URL.',
    'description_key' => 'admin.settings.plugins.bundled.s3_media.description',
    'version' => '1.0.0',
    'api_version' => PUREBLOG_PLUGIN_API_VERSION,
    'capabilities' => ['media.storage' => 'plus_s3_media_store', 'media.delete' => 'plus_s3_media_delete'],
    'settings' => [
        'endpoint' => ['type' => 'url', 'label' => 'S3 endpoint', 'label_key' => 'admin.settings.plugins.bundled.s3_media.endpoint', 'default' => ''],
        'region' => ['type' => 'text', 'label' => 'Region', 'label_key' => 'admin.settings.plugins.bundled.s3_media.region', 'default' => 'auto'],
        'bucket' => ['type' => 'text', 'label' => 'Bucket', 'label_key' => 'admin.settings.plugins.bundled.s3_media.bucket', 'default' => ''],
        'access_key' => ['type' => 'text', 'label' => 'Access key', 'label_key' => 'admin.settings.plugins.bundled.s3_media.access_key', 'default' => ''],
        'secret_key' => ['type' => 'password', 'label' => 'Secret key', 'label_key' => 'admin.settings.plugins.bundled.s3_media.secret_key', 'default' => ''],
        'public_base_url' => ['type' => 'url', 'label' => 'Public media URL', 'label_key' => 'admin.settings.plugins.bundled.s3_media.public_base_url', 'default' => ''],
    ],
]);

/** @param array<string,mixed> $asset @param list<array<string,mixed>> $files */
function plus_s3_media_store(array $asset, array $files, array $settings): array
{
    foreach (['endpoint', 'region', 'bucket', 'access_key', 'secret_key', 'public_base_url'] as $required) {
        if (trim((string) ($settings[$required] ?? '')) === '') {
            throw new RuntimeException('S3 media setting is missing: ' . $required);
        }
    }
    $endpoint = rtrim((string) $settings['endpoint'], '/');
    $publicBase = rtrim((string) $settings['public_base_url'], '/');
    if (parse_url($endpoint, PHP_URL_SCHEME) !== 'https' || parse_url($publicBase, PHP_URL_SCHEME) !== 'https') {
        throw new RuntimeException('S3 endpoint and public URL must use HTTPS.');
    }
    $prefix = 'media/' . gmdate('Y/m', (int) ($asset['created_at'] ?? time())) . '/';
    $uploaded = [];
    try {
        foreach ($files as $file) {
            $key = $prefix . basename((string) $file['name']);
            plus_s3_upload_file($key, (string) $file['path'], (string) $file['mime_type'], $settings);
            $uploaded[] = $key;
        }
    } catch (Throwable $error) {
        foreach ($uploaded as $key) {
            try {
                plus_s3_request('DELETE', $key, '', 'application/octet-stream', $settings);
            } catch (Throwable) {
            }
        }
        throw $error;
    }
    $url = '';
    $variants = [];
    foreach ($files as $file) {
        $fileUrl = $publicBase . '/' . $prefix . rawurlencode(basename((string) $file['name']));
        if ($file['role'] === 'original') {
            $url = $fileUrl;
        } else {
            $variants[(string) (int) $file['width']] = $fileUrl;
        }
    }
    return [
        'url' => $url,
        'variants' => $variants,
        'provider_data' => ['keys' => $uploaded],
        'rollback' => static function () use ($uploaded, $settings): void {
            foreach ($uploaded as $key) {
                try { plus_s3_request('DELETE', $key, '', 'application/octet-stream', $settings); } catch (Throwable) {}
            }
        },
    ];
}

function plus_s3_upload_file(string $key, string $path, string $contentType, array $settings): void
{
    $size = is_file($path) ? filesize($path) : false;
    $payloadHash = is_file($path) ? hash_file('sha256', $path) : false;
    if ($size === false || $payloadHash === false) throw new RuntimeException('S3 upload source is unavailable.');
    plus_s3_request('PUT', $key, '', $contentType, $settings, $path, $payloadHash, (int) $size);
}

function plus_s3_media_delete(array $media, array $settings): void
{
    foreach ((array) ($media['provider_data']['keys'] ?? []) as $key) {
        if (!is_string($key) || $key === '') throw new RuntimeException('Stored S3 media key is invalid.');
        plus_s3_request('DELETE', $key, '', 'application/octet-stream', $settings);
    }
}

function plus_s3_request(
    string $method,
    string $key,
    string $body,
    string $contentType,
    array $settings,
    ?string $uploadPath = null,
    ?string $payloadHash = null,
    ?int $uploadSize = null
): void
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('S3 media requires the curl extension.');
    }
    $endpoint = rtrim((string) $settings['endpoint'], '/');
    $parts = parse_url($endpoint);
    if (!is_array($parts) || isset($parts['query'], $parts['fragment'], $parts['user'], $parts['pass'])) {
        throw new RuntimeException('S3 endpoint is invalid.');
    }
    $host = strtolower((string) ($parts['host'] ?? ''));
    $ips = indieauth_resolve_public_ips($host);
    if ($host === '' || $ips === []) {
        throw new RuntimeException('S3 endpoint does not resolve to a public host.');
    }
    $bucket = trim((string) $settings['bucket'], '/');
    $encodedKey = implode('/', array_map('rawurlencode', explode('/', $key)));
    $basePath = rtrim((string) ($parts['path'] ?? ''), '/');
    $canonicalUri = ($basePath === '' ? '' : $basePath) . '/' . rawurlencode($bucket) . '/' . $encodedKey;
    $hostForUrl = str_contains($host, ':') ? '[' . $host . ']' : $host;
    $hostHeader = $hostForUrl . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
    $url = 'https://' . $hostHeader . $canonicalUri;
    $signed = plus_s3_signature_headers(
        $method, $canonicalUri, $body, $contentType, $hostHeader,
        (string) $settings['region'], (string) $settings['access_key'], (string) $settings['secret_key'], null, $payloadHash
    );
    $port = (int) ($parts['port'] ?? 443);
    $resolvedIp = str_contains($ips[0], ':') ? '[' . $ips[0] . ']' : $ips[0];
    $handle = curl_init($url);
    if ($handle === false) throw new RuntimeException('Unable to initialize S3 media request.');
    $uploadStream = null;
    if ($uploadPath !== null) {
        $uploadStream = fopen($uploadPath, 'rb');
        if ($uploadStream === false) {
            curl_close($handle);
            throw new RuntimeException('Unable to open the S3 upload source.');
        }
    }
    $responseBody = '';
    $tooLarge = false;
    $curlOptions = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 60,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_PROXY => '',
        CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $resolvedIp],
        CURLOPT_HTTPHEADER => [
            'Authorization: ' . $signed['authorization'], 'Content-Type: ' . $contentType,
            'X-Amz-Content-Sha256: ' . $signed['payload_hash'], 'X-Amz-Date: ' . $signed['amz_date'],
        ],
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$responseBody, &$tooLarge): int {
            if (strlen($responseBody) + strlen($chunk) > 65536) { $tooLarge = true; return 0; }
            $responseBody .= $chunk;
            return strlen($chunk);
        },
    ];
    if (is_resource($uploadStream)) {
        $curlOptions[CURLOPT_UPLOAD] = true;
        $curlOptions[CURLOPT_INFILE] = $uploadStream;
        $curlOptions[CURLOPT_INFILESIZE] = $uploadSize ?? 0;
    } else {
        $curlOptions[CURLOPT_POSTFIELDS] = $body;
    }
    curl_setopt_array($handle, $curlOptions);
    $response = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_error($handle);
    curl_close($handle);
    if (is_resource($uploadStream)) fclose($uploadStream);
    if ($tooLarge || $response === false || $status < 200 || $status >= 300) {
        throw new RuntimeException($error !== '' ? $error : 'S3 media request returned HTTP ' . $status . '.');
    }
}

/** @return array{authorization:string,payload_hash:string,amz_date:string} */
function plus_s3_signature_headers(
    string $method,
    string $canonicalUri,
    string $body,
    string $contentType,
    string $host,
    string $region,
    string $accessKey,
    string $secretKey,
    ?string $amzDate = null,
    ?string $payloadHash = null
): array {
    $amzDate ??= gmdate('Ymd\THis\Z');
    if (preg_match('/^\d{8}T\d{6}Z$/', $amzDate) !== 1) throw new InvalidArgumentException('Invalid S3 signing date.');
    $date = substr($amzDate, 0, 8);
    $payloadHash ??= hash('sha256', $body);
    if (preg_match('/^[a-f0-9]{64}$/', $payloadHash) !== 1) throw new InvalidArgumentException('Invalid S3 payload hash.');
    $canonicalHeaders = 'content-type:' . trim($contentType) . "\n" . 'host:' . strtolower(trim($host)) . "\n"
        . 'x-amz-content-sha256:' . $payloadHash . "\n" . 'x-amz-date:' . $amzDate . "\n";
    $signedHeaders = 'content-type;host;x-amz-content-sha256;x-amz-date';
    $canonicalRequest = strtoupper($method) . "\n" . $canonicalUri . "\n\n" . $canonicalHeaders . "\n" . $signedHeaders . "\n" . $payloadHash;
    $scope = $date . '/' . $region . '/s3/aws4_request';
    $stringToSign = "AWS4-HMAC-SHA256\n" . $amzDate . "\n" . $scope . "\n" . hash('sha256', $canonicalRequest);
    $dateKey = hash_hmac('sha256', $date, 'AWS4' . $secretKey, true);
    $regionKey = hash_hmac('sha256', $region, $dateKey, true);
    $serviceKey = hash_hmac('sha256', 's3', $regionKey, true);
    $signingKey = hash_hmac('sha256', 'aws4_request', $serviceKey, true);
    $signature = hash_hmac('sha256', $stringToSign, $signingKey);
    return [
        'authorization' => 'AWS4-HMAC-SHA256 Credential=' . $accessKey . '/' . $scope
            . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature,
        'payload_hash' => $payloadHash,
        'amz_date' => $amzDate,
    ];
}

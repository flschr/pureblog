<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}
verify_csrf();
$url = trim((string) ($_POST['url'] ?? ''));
$context = (string) ($_POST['context'] ?? '');
$now = time();
$recent = array_values(array_filter(
    (array) ($_SESSION['plus_alt_requests'] ?? []),
    static fn(mixed $time): bool => is_int($time) && $time > $now - 60
));
if (count($recent) >= 20) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many alt-text requests.']);
    exit;
}
$recent[] = $now;
$_SESSION['plus_alt_requests'] = $recent;
session_write_close();
try {
    echo json_encode(['alt' => plus_suggest_media_alt($url, $context)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException $error) {
    http_response_code(400);
    echo json_encode(['error' => $error->getMessage()]);
} catch (Throwable) {
    http_response_code(502);
    echo json_encode(['error' => 'Alt-text suggestion is unavailable.']);
}

<?php

declare(strict_types=1);

function social_test_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function social_test_remove_tree(string $path): void
{
    if (!is_dir($path)) return;
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    rmdir($path);
}

$root = sys_get_temp_dir() . '/pureblog-social-' . bin2hex(random_bytes(8));
define('PUREBLOG_PLUS_DATA_PATH', $root . '/plus');
$requests = [];
$statusGeneration = 1;
$revokeFails = false;

try {
    require dirname(__DIR__) . '/functions.php';
    $legacyDatabase = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $legacyDatabase->exec('CREATE TABLE plus_social_connection (id INTEGER PRIMARY KEY,public_enabled INTEGER NOT NULL DEFAULT 0,updated_at INTEGER NOT NULL)');
    $legacyDatabase->exec('INSERT INTO plus_social_connection (id,public_enabled,updated_at) VALUES (1,1,1000)');
    ($GLOBALS['_pureblog_plus_migrations']['0011_social_announcement_template'])($legacyDatabase);
    social_test_assert(
        $legacyDatabase->query('SELECT announcement_template FROM plus_social_connection WHERE id=1')->fetchColumn() === '',
        'Existing social settings did not inherit the default announcement template during migration.'
    );
    $authorshipDatabase = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $authorshipDatabase->exec("CREATE TABLE plus_social_archives (id INTEGER PRIMARY KEY,account_id TEXT NOT NULL);INSERT INTO plus_social_archives VALUES (7,'account-7')");
    $authorshipDatabase->exec("CREATE TABLE plus_social_statuses (archive_id INTEGER NOT NULL,remote_id TEXT NOT NULL,PRIMARY KEY(archive_id,remote_id));INSERT INTO plus_social_statuses VALUES (7,'status-7')");
    ($GLOBALS['_pureblog_plus_migrations']['0014_social_status_authorship'])($authorshipDatabase);
    social_test_assert(
        $authorshipDatabase->query("SELECT author_id FROM plus_social_statuses WHERE remote_id='status-7'")->fetchColumn() === 'account-7',
        'Existing account-scoped statuses did not inherit their canonical archive author.'
    );
    $GLOBALS['_pureblog_social_public_ips'] = static fn(string $host): array => ['203.0.113.10'];
    $GLOBALS['_pureblog_social_media_archiver'] = static function (string $url): string {
        if (str_contains($url, 'broken')) throw new RuntimeException('Synthetic archive failure.');
        return 'https://blog.example/content/media/social/' . hash('sha256', $url) . '.jpg';
    };
    $GLOBALS['_pureblog_social_requester'] = static function (string $method, string $url, array $options) use (&$requests, &$statusGeneration, &$revokeFails): array {
        $requests[] = [$method, $url, $options];
        $payload = [];
        if (str_ends_with($url, '/api/v1/apps')) {
            $payload = ['client_id' => 'client-id', 'client_secret' => 'client-secret'];
        } elseif (str_ends_with($url, '/oauth/token')) {
            $payload = ['access_token' => 'access-token'];
        } elseif (str_ends_with($url, '/oauth/revoke')) {
            if ($revokeFails) return ['ok' => false, 'status' => 503, 'body' => '{}', 'headers' => [], 'error' => 'Synthetic revoke failure'];
            $payload = [];
        } elseif (str_ends_with($url, '/api/v1/accounts/verify_credentials')) {
            $payload = ['id' => 'account-1', 'acct' => 'rene@example.social', 'url' => 'https://example.social/@rene'];
        } elseif (str_contains($url, '/api/v1/accounts/account-1/statuses')) {
            $payload = $statusGeneration === 1 ? [[
                'id' => '102', 'uri' => 'tag:example,102', 'url' => 'https://example.social/@rene/102',
                'account' => ['id' => 'account-1'],
                'content' => '<p>First<br>status</p>', 'spoiler_text' => '', 'visibility' => 'public',
                'created_at' => '2026-11-15T10:00:00Z', 'edited_at' => null,
                'media_attachments' => [['type' => 'image', 'url' => 'https://media.example/one.jpg', 'description' => 'One']],
            ], [
                'id' => '101', 'uri' => 'tag:example,101', 'url' => 'https://example.social/@rene/101',
                'account' => ['id' => 'account-1'],
                'content' => '<p>Broken image survives</p>', 'spoiler_text' => '', 'visibility' => 'unlisted',
                'created_at' => '2026-11-15T09:00:00Z', 'edited_at' => null,
                'media_attachments' => [['type' => 'image', 'url' => 'https://media.example/broken.jpg', 'description' => 'Broken']],
            ], [
                'id' => '100', 'url' => 'https://example.social/@rene/100', 'content' => 'Private',
                'account' => ['id' => 'account-1'],
                'visibility' => 'private', 'created_at' => '2026-11-15T08:00:00Z', 'media_attachments' => [],
            ], [
                'id' => '099', 'url' => 'https://example.social/@other/099', 'content' => 'Foreign',
                'account' => ['id' => 'account-2'], 'visibility' => 'public', 'created_at' => '2026-11-15T07:00:00Z',
                'media_attachments' => [['type' => 'image', 'url' => 'https://media.example/foreign.jpg', 'description' => 'Foreign']],
            ], [
                'id' => '098', 'url' => 'https://example.social/@rene/098', 'content' => 'Boost',
                'account' => ['id' => 'account-1'], 'reblog' => ['id' => 'foreign-original'], 'visibility' => 'public',
                'created_at' => '2026-11-15T06:00:00Z',
                'media_attachments' => [['type' => 'image', 'url' => 'https://media.example/boost.jpg', 'description' => 'Boost']],
            ]] : [[
                'id' => '102', 'uri' => 'tag:example,102', 'url' => 'https://example.social/@rene/102',
                'account' => ['id' => 'account-1'],
                'content' => '<p>Edited status</p>', 'spoiler_text' => '', 'visibility' => 'public',
                'created_at' => '2026-11-15T10:00:00Z', 'edited_at' => '2026-11-15T11:00:00Z', 'media_attachments' => [],
            ]];
        } elseif (str_contains($url, '/api/v1/statuses/announcement-1') && $method === 'DELETE') {
            $payload = ['id' => 'announcement-1'];
        } elseif (str_ends_with($url, '/api/v1/statuses') && $method === 'POST') {
            $payload = ['id' => 'announcement-new', 'url' => 'https://example.social/@rene/announcement-new'];
        } elseif (str_contains($url, '/api/v1/statuses/announcement-new') && $method === 'PUT') {
            $payload = ['id' => 'announcement-new', 'url' => 'https://example.social/@rene/announcement-new'];
        } else {
            return ['ok' => false, 'status' => 404, 'body' => '{}', 'headers' => [], 'error' => 'Unexpected request'];
        }
        return ['ok' => true, 'status' => 200, 'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'headers' => [], 'error' => ''];
    };

    social_test_assert(plus_has_capability('social.connector', 1, 0), 'Social capability missing.');
    $authorization = social_begin_connection('https://example.social');
    parse_str((string) parse_url($authorization, PHP_URL_QUERY), $authorizationQuery);
    social_test_assert(($authorizationQuery['state'] ?? '') !== '', 'OAuth state missing.');
    try {
        social_complete_connection('code', 'wrong-state');
        throw new RuntimeException('Invalid OAuth state was accepted.');
    } catch (RuntimeException $error) {
        social_test_assert(str_contains($error->getMessage(), 'missing or expired'), 'Unexpected OAuth state error.');
    }
    $connection = social_complete_connection('code', (string) $authorizationQuery['state']);
    social_test_assert(social_connected($connection), 'OAuth connection was not persisted.');
    social_test_assert(count(social_syndication_targets()) === 1, 'Connected account was not advertised as a syndication target.');
    $replacementAuthorization = social_begin_connection('https://example.social', 900);
    parse_str((string) parse_url($replacementAuthorization, PHP_URL_QUERY), $replacementQuery);
    social_test_assert(social_connected(), 'Starting a replacement discarded the active connection.');
    try {
        social_complete_connection('replacement-code', 'wrong-state', 901);
        throw new RuntimeException('Invalid replacement OAuth state was accepted.');
    } catch (RuntimeException $error) {
        social_test_assert(str_contains($error->getMessage(), 'missing or expired'), 'Unexpected replacement OAuth state error.');
    }
    social_test_assert(social_connected(), 'Failed replacement discarded the active connection.');
    $connection = social_complete_connection('replacement-code', (string) ($replacementQuery['state'] ?? ''), 902);
    social_test_assert(social_connected($connection), 'Validated replacement connection was not activated.');
    $staleRequestCount = count($requests);
    ($GLOBALS['_pureblog_plus_job_handlers']['social.announce'])([
        'slug' => 'stale-post', 'action' => 'publish', 'archive_id' => social_active_archive_id() + 1,
    ]);
    social_test_assert(count($requests) === $staleRequestCount, 'A stale account-bound announcement job reached the API.');

    social_test_assert(social_sync(1000) === 2, 'Initial status synchronization count is wrong.');
    $statuses = social_statuses();
    social_test_assert(count($statuses) === 2, 'Public projection contains the wrong statuses.');
    social_test_assert(array_unique(array_column($statuses, 'author_id')) === ['account-1'], 'A foreign or unattributed status entered the personal archive.');
    social_test_assert($statuses[0]['content_text'] === "First\nstatus", 'Remote HTML was not reduced to safe text.');
    social_test_assert(str_contains($statuses[0]['media_json'], 'blog.example/content/media/social'), 'Archived media URL was not retained.');
    $archivedMedia = json_decode((string) $statuses[0]['media_json'], true);
    social_test_assert(social_media_is_referenced((string) $archivedMedia[0]['url']), 'Social projection did not protect archived media from deletion.');
    social_test_assert($statuses[1]['media_json'] === '[]', 'A media failure removed its status or retained the remote URL.');
    plus_database()->prepare('INSERT INTO plus_social_media (archive_id,remote_url,local_url,created_at) VALUES (?,?,?,?)')
        ->execute([social_active_archive_id(), 'https://media.example/stale.jpg', 'https://blog.example/content/media/social/stale.jpg', 1000]);

    $statusGeneration = 2;
    social_test_assert(social_sync(1300) === 1, 'Updated status synchronization count is wrong.');
    $statuses = social_statuses();
    social_test_assert(count($statuses) === 1 && $statuses[0]['content_text'] === 'Edited status', 'Remote updates and deletions were not reconciled.');
    social_test_assert((int) plus_database()->query('SELECT COUNT(*) FROM plus_social_media')->fetchColumn() === 0, 'Unreferenced social media mapping was not evicted.');

    $announcementRequestCount = count($requests);
    social_publish_announcement('new-post', 'New post', null, null, 1300);
    $announcement = social_announcement_record('new-post');
    social_test_assert($announcement !== null && $announcement['remote_id'] === 'announcement-new', 'Blog announcement was not mapped.');
    social_test_assert(
        ($requests[array_key_last($requests)][2]['form']['status'] ?? '') === "New post\n\nhttp://localhost/new-post",
        'Default blog announcement template changed.'
    );
    social_test_assert(count($requests) === $announcementRequestCount + 1 && ($requests[array_key_last($requests)][2]['idempotency_key'] ?? '') !== '', 'Announcement creation was not idempotent at the API boundary.');
    social_publish_announcement('new-post', 'New post', $announcement, null, 1301);
    social_test_assert(count($requests) === $announcementRequestCount + 1, 'Unchanged announcement was sent twice.');
    social_set_settings(true, "New on the blog: {title}\n{url}\nComments welcome.", 1302);
    social_test_assert(social_announcement_template() === "New on the blog: {title}\n{url}\nComments welcome.", 'Announcement template setting was not persisted.');
    social_publish_announcement('new-post', 'Renamed post', $announcement, null, 1303);
    social_test_assert(
        count($requests) === $announcementRequestCount + 2
        && $requests[array_key_last($requests)][0] === 'PUT'
        && ($requests[array_key_last($requests)][2]['form']['status'] ?? '') === "New on the blog: Renamed post\nhttp://localhost/new-post\nComments welcome.",
        'Changed announcement template was not rendered and updated in place.'
    );
    social_set_settings(true, '', 1304);
    social_test_assert(social_configured_announcement_template() === '', 'Empty announcement template was duplicated into persisted settings.');
    social_test_assert(social_announcement_template() === social_default_announcement_template(), 'Empty announcement template did not inherit the default.');
    foreach ([['Only {title}', 'must contain'], ['{title} {unknown} {url}', 'unknown placeholder']] as [$invalidTemplate, $expectedError]) {
        try {
            social_validate_announcement_template($invalidTemplate);
            throw new RuntimeException('Invalid announcement template was accepted.');
        } catch (InvalidArgumentException $error) {
            social_test_assert(str_contains($error->getMessage(), $expectedError), 'Unexpected announcement template validation error.');
        }
    }

    plus_database()->prepare('INSERT INTO plus_social_announcements (archive_id,post_slug,remote_id,remote_url,content_hash,updated_at) VALUES (?,?,?,?,?,?)')
        ->execute([social_active_archive_id(), 'post', 'announcement-1', 'https://example.social/@rene/announcement-1', 'hash', 1300]);
    social_run_announcement('post', 'delete', 1301, social_active_archive_id());
    social_test_assert(social_announcement_record('post') === null, 'Deleted announcement mapping remains.');

    $revokeFails = true;
    social_disconnect(1400);
    $requestCount = count($requests);
    social_test_assert(!social_connected(), 'Disconnect retained active credentials.');
    social_test_assert(count(social_statuses()) === 1, 'Disconnect removed the frozen projection.');
    social_test_assert(social_sync(1500) === 0 && count($requests) === $requestCount, 'Disconnected archive continued polling.');
    social_test_assert((int) plus_database()->query('SELECT COUNT(*) FROM plus_social_revocations')->fetchColumn() === 1, 'Failed token revocation was discarded.');
    $revokeFails = false;
    $revocationId = (int) plus_database()->query('SELECT id FROM plus_social_revocations')->fetchColumn();
    social_retry_revocation($revocationId);
    social_test_assert((int) plus_database()->query('SELECT COUNT(*) FROM plus_social_revocations')->fetchColumn() === 0, 'Deferred token revocation did not complete.');

    $reconnectAuthorization = social_begin_connection('https://example.social', 1600);
    parse_str((string) parse_url($reconnectAuthorization, PHP_URL_QUERY), $reconnectQuery);
    $reconnected = social_complete_connection('reconnect-code', (string) ($reconnectQuery['state'] ?? ''), 1601);
    social_test_assert(social_connected($reconnected), 'Reconnect did not restore an active account.');
    social_test_assert(count(social_statuses()) === 1, 'Reconnect removed the frozen projection.');
    social_test_assert(social_sync(1602) === 1, 'Reconnected account did not resume polling.');

    echo "PASS: social OAuth state, one-account connection, polling, media isolation, projection updates, announcement deletion, frozen disconnect and reconnect\n";
} finally {
    unset($GLOBALS['_pureblog_social_public_ips'], $GLOBALS['_pureblog_social_media_archiver'], $GLOBALS['_pureblog_social_requester']);
    social_test_remove_tree($root);
}

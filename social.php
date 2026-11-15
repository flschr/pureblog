<?php

declare(strict_types=1);

if (!function_exists('social_statuses')) {
    header('Location: /');
    exit;
}

$connection = social_connection();
if (empty($connection['public_enabled'])) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    return;
}
$config = $config ?? load_config();
$fontStack = $fontStack ?? font_stack_css($config['theme']['font_stack'] ?? 'sans');
$pageTitle = t('frontend.social_title');
$metaDescription = t('frontend.social_description');
$statuses = social_statuses(100);
?>
<?php if ($__p = find_include('header')) require $__p; ?>
<?php render_masthead_layout($config, []); ?>
<main>
    <h1><?= e(t('frontend.social_title')) ?></h1>
    <?php if ($statuses === []): ?>
        <p><?= e(t('frontend.social_empty')) ?></p>
    <?php else: ?>
        <div class="social-status-list">
        <?php foreach ($statuses as $status): ?>
            <article class="social-status">
                <?php if (trim((string) $status['spoiler_text']) !== ''): ?><p><strong><?= e((string) $status['spoiler_text']) ?></strong></p><?php endif; ?>
                <p><?= nl2br(e((string) $status['content_text']), false) ?></p>
                <?php $media = json_decode((string) $status['media_json'], true); ?>
                <?php if (is_array($media)): foreach ($media as $item): if (!is_array($item)) continue; ?>
                    <?php if (($item['type'] ?? 'image') === 'audio'): ?>
                        <audio controls preload="metadata" src="<?= e((string) ($item['url'] ?? '')) ?>"></audio>
                    <?php elseif (in_array(($item['type'] ?? ''), ['video', 'gifv'], true)): ?>
                        <video controls preload="metadata" src="<?= e((string) ($item['url'] ?? '')) ?>"></video>
                    <?php else: ?>
                        <figure><img loading="lazy" src="<?= e((string) ($item['url'] ?? '')) ?>" alt="<?= e((string) ($item['alt'] ?? '')) ?>"></figure>
                    <?php endif; ?>
                <?php endforeach; endif; ?>
                <p class="post-date"><a href="<?= e((string) $status['url']) ?>" rel="noopener noreferrer"><?= e(format_datetime_for_display((string) $status['published_at'], $config)) ?></a></p>
            </article>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>
<?php render_footer_layout($config, []); ?>
</body>
</html>

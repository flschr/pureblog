<?php

declare(strict_types=1);

/** @var list<array<string,mixed>> $images */
/** @var array{type:string,slug:string,date?:string,feature_image?:string,delete_confirm_key:string} $editorMediaOwner */
$ownerType = $editorMediaOwner['type'];
$ownerSlug = $editorMediaOwner['slug'];
$featureImage = (string) ($editorMediaOwner['feature_image'] ?? '');
$hasImages = array_filter($images, static fn(array $item): bool => ($item['media_kind'] ?? 'image') === 'image') !== [];
?>
<section class="sidebar-section">
    <div class="section-divider">
        <span class="images-title"><?= e(t('admin.editor.media_title')) ?></span>
        <form method="post" action="<?= base_path() ?>/admin/upload-image.php" enctype="multipart/form-data" class="upload-form">
            <input type="hidden" name="slug" value="<?= e($ownerSlug) ?>">
            <?php if ($ownerType === 'post'): ?>
                <input type="hidden" name="date" value="<?= e((string) ($editorMediaOwner['date'] ?? '')) ?>">
            <?php else: ?>
                <input type="hidden" name="editor_type" value="page">
            <?php endif; ?>
            <?= csrf_field() ?>
            <label class="hidden" for="image"><?= e(t('admin.editor.upload_media_label')) ?></label>
            <input type="file" id="image" name="image[]" accept="<?= e(plus_editor_visual_media_accept()) ?>" multiple>
            <button type="submit" disabled>
                <svg class="icon" aria-hidden="true"><use href="#icon-upload"></use></svg>
                <?= e(t('admin.editor.upload')) ?>
            </button>
        </form>
        <?php if (!$images): ?>
            <p><?= e(t('admin.editor.no_media')) ?></p>
        <?php else: ?>
            <div class="attached-images-header">
                <p><?= e(t('admin.editor.attached_media')) ?></p>
                <?php if ($hasImages): ?>
                    <button type="button" class="link-button insert-gallery-btn" title="<?= e(t('admin.editor.insert_gallery_title')) ?>">
                        <svg class="icon" aria-hidden="true"><use href="#icon-image"></use></svg>
                        <?= e(t('admin.editor.insert_gallery')) ?>
                    </button>
                <?php endif; ?>
            </div>
            <ul class="image-list">
                <?php foreach ($images as $image):
                    $imageRawPath = (string) $image['url'];
                    $isFeature = $featureImage === $imageRawPath;
                    $mediaKind = $image['media_kind'] ?? 'image';
                ?>
                    <li<?= $isFeature ? ' class="is-feature"' : '' ?> data-media-kind="<?= e($mediaKind) ?>">
                        <div class="image-list-top">
                            <?php if ($mediaKind === 'video'): ?>
                                <video src="<?= e($image['thumb_url']) ?>" width="40" height="30" class="image-list-preview" preload="metadata" muted aria-label="<?= e($image['filename']) ?>"></video>
                            <?php else: ?>
                                <img src="<?= e($image['thumb_url']) ?>" width="30" height="30" class="image-list-preview" alt="<?= e($image['filename']) ?>">
                            <?php endif; ?>
                            <code><?= e($image['filename']) ?></code>
                        </div>
                        <div class="image-list-actions">
                            <?php if ($mediaKind === 'image'): ?>
                                <label class="feature-image-label">
                                    <input type="checkbox" class="feature-image-check" data-url="<?= e($imageRawPath) ?>" data-filename="<?= e($image['filename']) ?>"<?= $isFeature ? ' checked' : '' ?>>
                                    <?= e(t('admin.editor.feature_image')) ?>
                                </label>
                            <?php endif; ?>
                            <button type="button" class="link-button copy-markdown" data-markdown="<?= e($image['markdown']) ?>"><svg class="icon" aria-hidden="true"><use href="#icon-copy"></use></svg> <?= e(t('admin.editor.copy')) ?></button>
                            <?php if ($mediaKind === 'video' && !empty($image['managed_media'])): ?>
                                <form method="post" action="<?= base_path() ?>/admin/update-media-alt.php" class="video-description-form">
                                    <input type="hidden" name="id" value="<?= e($image['media_id']) ?>">
                                    <input type="hidden" name="return_to" value="<?= e($ownerType) ?>">
                                    <input type="hidden" name="slug" value="<?= e($ownerSlug) ?>">
                                    <?= csrf_field() ?>
                                    <label><?= e(t('admin.editor.video_description')) ?>
                                        <input type="text" name="default_alt" maxlength="1500" value="<?= e($image['default_alt']) ?>" placeholder="<?= e(t('admin.editor.video_description_placeholder')) ?>">
                                    </label>
                                    <button type="submit" class="link-button"><?= e(t('admin.editor.save')) ?></button>
                                </form>
                            <?php endif; ?>
                            <?php if (empty($image['managed_media'])): ?>
                                <form method="post" action="<?= base_path() ?>/admin/delete-image.php" class="inline-form" onsubmit="return confirm('<?= e(t($editorMediaOwner['delete_confirm_key'])) ?>');">
                                    <input type="hidden" name="slug" value="<?= e($ownerSlug) ?>">
                                    <?php if ($ownerType === 'post'): ?>
                                        <input type="hidden" name="date" value="<?= e((string) ($editorMediaOwner['date'] ?? '')) ?>">
                                    <?php else: ?>
                                        <input type="hidden" name="editor_type" value="page">
                                    <?php endif; ?>
                                    <input type="hidden" name="filename" value="<?= e($image['filename']) ?>">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="link-button delete"><svg class="icon" aria-hidden="true"><use href="#icon-circle-x"></use></svg> <?= e(t('admin.editor.delete')) ?></button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</section>

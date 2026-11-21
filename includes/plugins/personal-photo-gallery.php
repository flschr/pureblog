<?php

declare(strict_types=1);

plus_register_plugin([
    'id' => 'personal-photo-gallery',
    'name' => 'Personal photo gallery',
    'name_key' => 'admin.settings.plugins.bundled.photo_gallery.name',
    'description' => 'Builds a fast public gallery from likely author-captured photos in published posts.',
    'description_key' => 'admin.settings.plugins.bundled.photo_gallery.description',
    'version' => '1.0.1',
    'api_version' => PUREBLOG_PLUGIN_API_VERSION,
    'capabilities' => [
        'site.route' => 'personal_photo_gallery_route_handler',
        'site.route_match' => 'personal_photo_gallery_route_matches',
    ],
    'events' => [
        'post.saved' => 'personal_photo_gallery_event_post_saved',
        'post.deleted' => 'personal_photo_gallery_event_post_deleted',
        'configuration.saved' => 'personal_photo_gallery_event_configuration_saved',
    ],
    'admin_pages' => [[
        'id' => 'photo-gallery',
        'path' => 'admin/gallery.php',
        'label' => 'Photo gallery',
        'label_key' => 'admin.photo_gallery.nav',
        'icon' => 'image',
    ]],
    'settings' => [
        'route' => [
            'type' => 'text', 'label' => 'Public path',
            'label_key' => 'admin.settings.plugins.bundled.photo_gallery.route', 'default' => 'gallery',
        ],
        'title' => [
            'type' => 'text', 'label' => 'Gallery title',
            'label_key' => 'admin.settings.plugins.bundled.photo_gallery.title', 'default' => '',
        ],
        'page_size' => [
            'type' => 'select', 'label' => 'Photos per page',
            'label_key' => 'admin.settings.plugins.bundled.photo_gallery.page_size', 'default' => '24',
            'options' => ['24' => '24', '36' => '36'],
        ],
        'positive_tags' => [
            'type' => 'text', 'label' => 'Personal-photo tags',
            'label_key' => 'admin.settings.plugins.bundled.photo_gallery.positive_tags', 'default' => '',
        ],
    ],
]);

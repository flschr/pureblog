<?php

declare(strict_types=1);

require_once __DIR__ . '/capabilities.php';
require_once __DIR__ . '/migrations.php';
require_once __DIR__ . '/jobs.php';
require_once __DIR__ . '/job-runner.php';
require_once __DIR__ . '/plugins.php';
require_once __DIR__ . '/media.php';
require_once __DIR__ . '/photo-gallery.php';
require_once __DIR__ . '/indieauth.php';
require_once __DIR__ . '/micropub.php';
require_once __DIR__ . '/webmentions.php';
require_once __DIR__ . '/webmention-sender.php';
require_once __DIR__ . '/social-connector.php';

// Trusted bundled manifests register their event, route, provider, and admin contracts.
plus_load_bundled_plugins();

plus_register_capability('plus.runtime', 1, 0);

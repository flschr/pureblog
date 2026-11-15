<?php

declare(strict_types=1);

require_once __DIR__ . '/capabilities.php';
require_once __DIR__ . '/migrations.php';
require_once __DIR__ . '/jobs.php';
require_once __DIR__ . '/job-runner.php';
require_once __DIR__ . '/indieauth.php';
require_once __DIR__ . '/micropub.php';

plus_register_capability('plus.runtime', 1, 0);

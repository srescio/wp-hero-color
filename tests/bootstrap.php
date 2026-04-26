<?php

declare(strict_types=1);

$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

require_once __DIR__ . '/../includes/class-wp-hero-color-service.php';
require_once __DIR__ . '/../includes/class-wp-hero-color-plugin.php';

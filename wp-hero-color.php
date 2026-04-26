<?php
/**
 * Plugin Name: WP Hero Color
 * Description: Deterministic hero background colors and gradients from featured images.
 * Version: 0.1.3
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * Author: Simone Rescio
 * License: GPL-2.0-or-later
 * Text Domain: wp-hero-color
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('WP_HERO_COLOR_VERSION', '0.1.3');
define('WP_HERO_COLOR_FILE', __FILE__);
define('WP_HERO_COLOR_DIR', plugin_dir_path(__FILE__));
define('WP_HERO_COLOR_URL', plugin_dir_url(__FILE__));

require_once WP_HERO_COLOR_DIR . 'includes/class-wp-hero-color-plugin.php';
require_once WP_HERO_COLOR_DIR . 'includes/functions-public-api.php';

\WPHeroColor\Plugin::bootstrap();

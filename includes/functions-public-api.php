<?php

declare(strict_types=1);

use WPHeroColor\Plugin;

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('wp_hero_color_get_attributes')) {
    /**
     * @return array<string,string>
     */
    function wp_hero_color_get_attributes(int $post_id): array
    {
        return Plugin::get_attributes_for_post($post_id);
    }
}

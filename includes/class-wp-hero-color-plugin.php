<?php

declare(strict_types=1);

namespace WPHeroColor;

final class Plugin
{
    public static function bootstrap(): void
    {
        add_action('plugins_loaded', [self::class, 'load_textdomain']);
    }

    public static function load_textdomain(): void
    {
        load_plugin_textdomain(
            'wp-hero-color',
            false,
            dirname(plugin_basename(WP_HERO_COLOR_FILE)) . '/languages'
        );
    }
}

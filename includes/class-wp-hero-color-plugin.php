<?php

declare(strict_types=1);

namespace WPHeroColor;

final class Plugin
{
    private static ?Service $service = null;

    public static function bootstrap(): void
    {
        require_once WP_HERO_COLOR_DIR . 'includes/class-wp-hero-color-service.php';
        require_once WP_HERO_COLOR_DIR . 'includes/class-wp-hero-color-rest-controller.php';

        add_action('plugins_loaded', [self::class, 'load_textdomain']);
        add_action('init', [self::class, 'register_meta']);
        add_action('rest_api_init', [self::class, 'register_rest_routes']);
        add_action('set_post_thumbnail', [self::class, 'on_set_post_thumbnail'], 10, 3);
        add_filter('pll_copy_post_metas', [self::class, 'register_polylang_meta_copy'], 10, 5);
    }

    public static function service(): Service
    {
        if (self::$service === null) {
            self::$service = new Service();
        }

        return self::$service;
    }

    public static function load_textdomain(): void
    {
        load_plugin_textdomain(
            'wp-hero-color',
            false,
            dirname(plugin_basename(WP_HERO_COLOR_FILE)) . '/languages'
        );
    }

    public static function register_meta(): void
    {
        self::service()->register_meta();
    }

    public static function register_rest_routes(): void
    {
        $controller = new RestController(self::service());
        $controller->register_routes();
    }

    /**
     * @return array<string,string>
     */
    public static function get_attributes_for_post(int $post_id): array
    {
        $payload = self::service()->get_payload($post_id);
        if ($payload === null) {
            return [];
        }

        return self::service()->build_attributes($payload);
    }

    public static function on_set_post_thumbnail(int $meta_id, int $post_id, int $thumbnail_id): void
    {
        if ($post_id < 1 || $thumbnail_id < 1) {
            return;
        }

        self::service()->recompute_for_post($post_id, $thumbnail_id, null, null);
    }

    /**
     * @param array<int,string> $metas
     * @return array<int,string>
     */
    public static function register_polylang_meta_copy(
        array $metas,
        bool $sync,
        int $from,
        int $to,
        string $lang
    ): array {
        if (!in_array(Service::META_KEY, $metas, true)) {
            $metas[] = Service::META_KEY;
        }

        return $metas;
    }
}

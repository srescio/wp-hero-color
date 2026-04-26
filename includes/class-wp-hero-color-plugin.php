<?php

declare(strict_types=1);

namespace WPHeroColor;

final class Plugin
{
    private static ?Service $service = null;

    public static function bootstrap(): void
    {
        require_once WP_HERO_COLOR_DIR . 'includes/class-wp-hero-color-requirements.php';
        require_once WP_HERO_COLOR_DIR . 'includes/class-wp-hero-color-service.php';
        require_once WP_HERO_COLOR_DIR . 'includes/class-wp-hero-color-bulk-runner.php';
        require_once WP_HERO_COLOR_DIR . 'includes/class-wp-hero-color-rest-controller.php';
        require_once WP_HERO_COLOR_DIR . 'includes/class-wp-hero-color-cli-command.php';
        require_once WP_HERO_COLOR_DIR . 'includes/class-wp-hero-color-admin-settings.php';

        add_action('plugins_loaded', [self::class, 'load_textdomain']);
        add_action('init', [self::class, 'register_meta']);
        add_action('rest_api_init', [self::class, 'register_rest_routes']);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_frontend_assets']);
        add_action('wp_head', [self::class, 'render_inline_post_styles'], 99);
        add_action('add_meta_boxes', [self::class, 'register_classic_meta_box']);
        add_action('save_post', [self::class, 'save_classic_meta_box'], 10, 2);
        add_action('enqueue_block_editor_assets', [self::class, 'enqueue_editor_assets']);
        add_action('set_post_thumbnail', [self::class, 'on_set_post_thumbnail'], 10, 3);
        add_filter('pll_copy_post_metas', [self::class, 'register_polylang_meta_copy'], 10, 5);
        add_action('init', [self::class, 'register_cli']);
        AdminSettings::init();
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

    public static function register_classic_meta_box(): void
    {
        foreach (['post', 'page'] as $postType) {
            if (function_exists('use_block_editor_for_post_type') && use_block_editor_for_post_type($postType)) {
                continue;
            }
            if (!post_type_supports($postType, 'thumbnail')) {
                continue;
            }
            add_meta_box(
                'wp-hero-color-classic-preview',
                __('Hero Color', 'wp-hero-color'),
                [self::class, 'render_classic_meta_box'],
                $postType,
                'side',
                'default'
            );
        }
    }

    /**
     * @param \WP_Post $post
     */
    public static function render_classic_meta_box($post): void
    {
        if (!is_object($post) || !isset($post->ID)) {
            echo '<p>' . esc_html__('No post context available.', 'wp-hero-color') . '</p>';
            return;
        }

        if (!Requirements::is_ready()) {
            echo '<div class="notice notice-error inline"><p>';
            echo esc_html(Requirements::blocking_message_block());
            echo '</p><p class="description">';
            echo esc_html__(
                'Fix the server requirements listed on Settings → Hero Color, then use Recompute again.',
                'wp-hero-color'
            );
            echo '</p></div>';
        }

        wp_nonce_field('wp_hero_color_classic_box', 'wp_hero_color_classic_box_nonce');

        $payload = self::service()->get_payload((int) $post->ID);
        if (!is_array($payload)) {
            $payload = self::service()->sanitize_payload([]);
            echo '<p>' . esc_html__('No computed hero data yet. Set a featured image and run recompute.', 'wp-hero-color') . '</p>';
        }

        $payload = self::service()->sanitize_payload($payload);
        $previewBg = self::service()->build_background_css($payload);
        $main = (string) $payload['main'];
        $mode = (string) $payload['mode'];
        $dir = (string) $payload['linear_dir'];

        echo '<div style="display:flex;flex-direction:column;gap:8px;">';
        echo '<label for="wp-hero-color-mode"><strong>' . esc_html__('Mode', 'wp-hero-color') . '</strong></label>';
        echo '<select id="wp-hero-color-mode" name="wp_hero_color_mode" style="width:100%;">';
        foreach (Service::MODES as $modeOpt) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($modeOpt),
                selected($mode, $modeOpt, false),
                esc_html($modeOpt)
            );
        }
        echo '</select>';
        echo '<label for="wp-hero-color-linear-dir"><strong>' . esc_html__('Direction', 'wp-hero-color') . '</strong></label>';
        echo '<select id="wp-hero-color-linear-dir" name="wp_hero_color_linear_dir" style="width:100%;">';
        foreach (Service::LINEAR_DIRECTIONS as $dirOpt) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($dirOpt),
                selected($dir, $dirOpt, false),
                esc_html($dirOpt)
            );
        }
        echo '</select>';
        $recomputeAttrs = Requirements::is_ready() ? '' : ' disabled="disabled" aria-disabled="true"';
        echo '<button type="submit" class="button button-secondary" name="wp_hero_color_recompute" value="1"' . $recomputeAttrs . '>'
            . esc_html__('Recompute', 'wp-hero-color') . '</button>';
        echo '<div style="width:100%;aspect-ratio:16/10;border:1px solid #dcdcde;border-radius:4px;background:' . esc_attr($previewBg) . ';"></div>';
        echo '<div style="display:flex;align-items:center;gap:8px;">';
        echo '<span style="display:inline-block;width:16px;height:16px;border:1px solid #c3c4c7;border-radius:3px;background:' . esc_attr($main) . ';"></span>';
        echo '<code style="font-size:12px;">' . esc_html($main) . '</code>';
        echo '</div>';
        if (isset($payload['edges']) && is_array($payload['edges'])) {
            echo '<div style="display:grid;grid-template-columns:repeat(8,1fr);gap:4px;">';
            foreach ($payload['edges'] as $edge) {
                $color = is_string($edge) ? $edge : $main;
                echo '<span title="' . esc_attr($color) . '" style="display:inline-block;width:100%;height:14px;border:1px solid #c3c4c7;border-radius:2px;background:' . esc_attr($color) . ';"></span>';
            }
            echo '</div>';
        }
        echo '</div>';
    }

    /**
     * @param \WP_Post $post
     */
    public static function save_classic_meta_box(int $post_id, $post): void
    {
        if (!is_object($post) || !isset($post->post_type)) {
            return;
        }
        if (!in_array((string) $post->post_type, ['post', 'page'], true)) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!isset($_POST['wp_hero_color_classic_box_nonce']) || !wp_verify_nonce((string) $_POST['wp_hero_color_classic_box_nonce'], 'wp_hero_color_classic_box')) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $mode = isset($_POST['wp_hero_color_mode']) ? sanitize_text_field((string) $_POST['wp_hero_color_mode']) : null;
        $dir = isset($_POST['wp_hero_color_linear_dir']) ? sanitize_text_field((string) $_POST['wp_hero_color_linear_dir']) : null;
        $recompute = isset($_POST['wp_hero_color_recompute']) && '1' === (string) $_POST['wp_hero_color_recompute'];

        if ($recompute) {
            if (!Requirements::is_ready()) {
                return;
            }
            try {
                self::service()->recompute_for_post($post_id, null, $mode, $dir);
            } catch (\RuntimeException $e) {
                return;
            }

            return;
        }

        $payload = self::service()->get_payload($post_id);
        if (!is_array($payload)) {
            $payload = self::service()->sanitize_payload([]);
        }
        if (is_string($mode) && in_array($mode, Service::MODES, true)) {
            $payload['mode'] = $mode;
        }
        if (is_string($dir) && in_array($dir, Service::LINEAR_DIRECTIONS, true)) {
            $payload['linear_dir'] = $dir;
        }

        self::service()->save_payload($post_id, $payload);
    }

    public static function enqueue_frontend_assets(): void
    {
        wp_enqueue_style(
            'wp-hero-color',
            WP_HERO_COLOR_URL . 'assets/css/sr-hero-color.css',
            [],
            WP_HERO_COLOR_VERSION
        );
    }

    public static function enqueue_editor_assets(): void
    {
        wp_enqueue_script(
            'wp-hero-color-editor',
            WP_HERO_COLOR_URL . 'assets/js/editor.js',
            ['wp-components', 'wp-data', 'wp-edit-post', 'wp-element', 'wp-plugins'],
            WP_HERO_COLOR_VERSION,
            true
        );

        wp_localize_script(
            'wp-hero-color-editor',
            'wpHeroColorConfig',
            [
                'restComputeUrl' => rest_url('sr-hero-color/v1/compute'),
                'nonce' => wp_create_nonce('wp_rest'),
            ]
        );
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

        if (!Requirements::is_ready()) {
            return;
        }

        try {
            self::service()->recompute_for_post($post_id, $thumbnail_id, null, null);
        } catch (\RuntimeException $e) {
            return;
        }
    }

    public static function register_cli(): void
    {
        if (!defined('WP_CLI') || !constant('WP_CLI') || !class_exists('\\WP_CLI')) {
            return;
        }

        $command = new CliCommand(self::service());
        \WP_CLI::add_command('hero-color', $command);
    }

    public static function render_inline_post_styles(): void
    {
        $postIds = self::resolve_query_post_ids();
        if ($postIds === []) {
            return;
        }

        $css = '';
        foreach ($postIds as $postId) {
            $payload = self::service()->get_payload($postId);
            if (!is_array($payload)) {
                continue;
            }

            $payload = self::service()->sanitize_payload($payload);
            $main = (string) $payload['main'];
            $mode = (string) $payload['mode'];
            $selector = '#post-' . (int) $postId . ' .post-thumbnail';
            $declarations = '--sr-hero-main:' . $main . ';--sr-hero-bg:' . $main . ';background-color:' . $main . ';background-attachment:scroll !important;position:relative;';

            if ($mode !== 'solid') {
                $bg = self::service()->build_background_css($payload);
                $declarations .= '--sr-hero-bg-image:' . $bg . ';';
            }

            $css .= $selector . '{' . $declarations . '}';
            $css .= $selector . ' figure{background-color:' . $main . ';position:relative;z-index:1;}';
            if ($mode !== 'solid') {
                $beforeBlend = ('conic' === $mode) ? 'normal' : 'soft-light';
                $beforeOpacity = ('conic' === $mode) ? '0.92' : '1';
                $css .= $selector . '::before{content:"";position:absolute;inset:0;pointer-events:none;z-index:0;opacity:1;'
                    . 'background-image:var(--sr-hero-bg-image);background-repeat:no-repeat;background-size:cover;'
                    . 'background-position:center center;mix-blend-mode:' . $beforeBlend . ';opacity:' . $beforeOpacity . ';}';
            } else {
                $css .= $selector . '::before{content:none;}';
            }
        }

        if ($css === '') {
            return;
        }

        echo '<style id="wp-hero-color-inline">' . $css . '</style>';
    }

    /**
     * @return array<int,int>
     */
    private static function resolve_query_post_ids(): array
    {
        $postIds = [];
        if (is_singular()) {
            $postIds[] = (int) get_the_ID();
        }
        if (isset($GLOBALS['wp_query']) && isset($GLOBALS['wp_query']->posts) && is_array($GLOBALS['wp_query']->posts)) {
            foreach ($GLOBALS['wp_query']->posts as $post) {
                if (is_object($post) && isset($post->ID)) {
                    $postIds[] = (int) $post->ID;
                }
            }
        }

        return array_values(array_unique(array_filter($postIds, static fn ($id): bool => $id > 0)));
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

<?php

declare(strict_types=1);

namespace WPHeroColor;

final class AdminSettings
{
    private const OPTION_KEY = 'wp_hero_color_admin_prefs';

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_menu']);
        add_action('admin_post_wp_hero_color_bulk', [self::class, 'handle_bulk']);
        add_filter('plugin_action_links_' . plugin_basename(WP_HERO_COLOR_FILE), [self::class, 'plugin_action_links']);
    }

    /**
     * @param array<string,string> $links
     * @return array<string,string>
     */
    public static function plugin_action_links(array $links): array
    {
        $url = admin_url('options-general.php?page=wp-hero-color');
        $links['wp_hero_color_settings'] = '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'wp-hero-color') . '</a>';

        return $links;
    }

    public static function register_menu(): void
    {
        add_options_page(
            __('WP Hero Color', 'wp-hero-color'),
            __('Hero Color', 'wp-hero-color'),
            'manage_options',
            'wp-hero-color',
            [self::class, 'render_page']
        );
    }

    public static function handle_bulk(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to run bulk actions.', 'wp-hero-color'));
        }

        check_admin_referer('wp_hero_color_bulk');

        $mode = isset($_POST['mode']) ? sanitize_text_field((string) $_POST['mode']) : null;
        if (!is_string($mode) || !in_array($mode, Service::MODES, true)) {
            $mode = null;
        }

        $linearDir = isset($_POST['linear_dir']) ? sanitize_text_field((string) $_POST['linear_dir']) : null;
        if (!is_string($linearDir) || !in_array($linearDir, Service::LINEAR_DIRECTIONS, true)) {
            $linearDir = null;
        }

        $scope = isset($_POST['scope']) ? sanitize_text_field((string) $_POST['scope']) : 'selected';
        if ('all_public' === $scope) {
            $postTypes = BulkRunner::publicPostTypeNames();
        } else {
            $raw = isset($_POST['post_types']) && is_array($_POST['post_types']) ? $_POST['post_types'] : [];
            $postTypes = array_values(array_filter(array_map('sanitize_key', $raw)));
        }

        if ('all_public' !== $scope && $postTypes === []) {
            wp_safe_redirect(
                add_query_arg(
                    [
                        'page' => 'wp-hero-color',
                        'bulk_error' => 'notypes',
                    ],
                    admin_url('options-general.php')
                )
            );
            exit;
        }

        update_option(
            self::OPTION_KEY,
            [
                'scope' => $scope,
                'post_types' => $postTypes,
                'mode' => $mode,
                'linear_dir' => $linearDir,
            ],
            false
        );

        @set_time_limit(0);

        $runner = new BulkRunner(Plugin::service());
        $results = $runner->run($postTypes, $mode, $linearDir);

        $counts = ['processed' => 0, 'skipped' => 0, 'failed' => 0];
        foreach ($results as $row) {
            $st = isset($row['status']) ? (string) $row['status'] : '';
            if ('processed' === $st) {
                ++$counts['processed'];
            } elseif ('skipped' === $st) {
                ++$counts['skipped'];
            } elseif ('failed' === $st) {
                ++$counts['failed'];
            }
        }

        set_transient(
            'wp_hero_color_bulk_summary_' . get_current_user_id(),
            $counts,
            120
        );

        wp_safe_redirect(
            add_query_arg(
                [
                    'page' => 'wp-hero-color',
                    'bulk_done' => '1',
                ],
                admin_url('options-general.php')
            )
        );
        exit;
    }

    public static function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'wp-hero-color'));
        }

        $summary = get_transient('wp_hero_color_bulk_summary_' . get_current_user_id());
        if (is_array($summary)) {
            delete_transient('wp_hero_color_bulk_summary_' . get_current_user_id());
        }

        $prefs = get_option(self::OPTION_KEY, []);
        if (!is_array($prefs)) {
            $prefs = [];
        }

        $restCompute = rest_url('sr-hero-color/v1/compute');
        $restReadTpl = rest_url('sr-hero-color/v1/post/POST_ID');

        $thumbTypes = BulkRunner::thumbnailPostTypeNames();
        $savedTypes = isset($prefs['post_types']) && is_array($prefs['post_types']) ? $prefs['post_types'] : ['post', 'page'];
        $savedScope = isset($prefs['scope']) ? (string) $prefs['scope'] : 'selected';
        $savedMode = isset($prefs['mode']) && is_string($prefs['mode']) ? $prefs['mode'] : '';
        $savedDir = isset($prefs['linear_dir']) && is_string($prefs['linear_dir']) ? $prefs['linear_dir'] : '';

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('WP Hero Color', 'wp-hero-color') . '</h1>';

        if (is_array($summary) && isset($_GET['bulk_done'])) {
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html(
                    sprintf(
                        /* translators: 1: processed count, 2: skipped, 3: failed */
                        __('Bulk run finished. Processed: %1$d, skipped (no image): %2$d, failed: %3$d.', 'wp-hero-color'),
                        (int) ($summary['processed'] ?? 0),
                        (int) ($summary['skipped'] ?? 0),
                        (int) ($summary['failed'] ?? 0)
                    )
                )
            );
        }

        if (isset($_GET['bulk_error']) && 'notypes' === (string) $_GET['bulk_error']) {
            printf(
                '<div class="notice notice-error is-dismissible"><p>%s</p></div>',
                esc_html__('Select at least one post type, or choose “All public post types”.', 'wp-hero-color')
            );
        }

        echo '<p>' . esc_html__('Run the same bulk logic as WP-CLI on the server, or copy REST / SSH commands for automation.', 'wp-hero-color') . '</p>';

        echo '<h2>' . esc_html__('Bulk recompute (server-side)', 'wp-hero-color') . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="wp_hero_color_bulk" />';
        wp_nonce_field('wp_hero_color_bulk');

        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr><th scope="row">' . esc_html__('Scope', 'wp-hero-color') . '</th><td>';
        echo '<fieldset>';
        echo '<label><input type="radio" name="scope" value="selected" ' . checked($savedScope, 'selected', false) . ' /> ';
        echo esc_html__('Selected post types (below)', 'wp-hero-color') . '</label><br />';
        echo '<label><input type="radio" name="scope" value="all_public" ' . checked($savedScope, 'all_public', false) . ' /> ';
        echo esc_html__('All public post types (same as WP-CLI --all-supported)', 'wp-hero-color') . '</label>';
        echo '</fieldset></td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Post types', 'wp-hero-color') . '</th><td>';
        echo '<p class="description">' . esc_html__('Only types that support featured images are listed. Skips posts without a featured image.', 'wp-hero-color') . '</p>';
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:6px;max-width:640px;">';
        foreach ($thumbTypes as $type) {
            $checked = in_array($type, $savedTypes, true) ? ' checked' : '';
            echo '<label><input type="checkbox" name="post_types[]" value="' . esc_attr($type) . '"' . $checked . ' /> ' . esc_html($type) . '</label>';
        }
        echo '</div></td></tr>';

        echo '<tr><th scope="row"><label for="wp-hero-color-mode">' . esc_html__('Mode override', 'wp-hero-color') . '</label></th><td>';
        echo '<select name="mode" id="wp-hero-color-mode">';
        echo '<option value="">' . esc_html__('(keep each post as saved)', 'wp-hero-color') . '</option>';
        foreach (Service::MODES as $m) {
            echo '<option value="' . esc_attr($m) . '"' . selected($savedMode, $m, false) . '>' . esc_html($m) . '</option>';
        }
        echo '</select></td></tr>';

        echo '<tr><th scope="row"><label for="wp-hero-color-dir">' . esc_html__('Linear direction override', 'wp-hero-color') . '</label></th><td>';
        echo '<select name="linear_dir" id="wp-hero-color-dir">';
        echo '<option value="">' . esc_html__('(keep each post as saved)', 'wp-hero-color') . '</option>';
        foreach (Service::LINEAR_DIRECTIONS as $d) {
            echo '<option value="' . esc_attr($d) . '"' . selected($savedDir, $d, false) . '>' . esc_html($d) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__('Used when mode is linear or when forcing linear_dir with other modes.', 'wp-hero-color') . '</p>';
        echo '</td></tr>';

        echo '</tbody></table>';

        submit_button(__('Run bulk recompute now', 'wp-hero-color'), 'primary', 'submit', false);
        echo '</form>';

        echo '<h2>' . esc_html__('REST API (MCP-friendly)', 'wp-hero-color') . '</h2>';
        echo '<p>' . esc_html__('Use Application Passwords or cookie auth in tools that call WordPress REST. Meta key for stored payload:', 'wp-hero-color') . ' <code>' . esc_html(Service::META_KEY) . '</code></p>';
        echo '<p><strong>' . esc_html__('Compute (POST)', 'wp-hero-color') . '</strong><br /><code style="user-select:all;">' . esc_html($restCompute) . '</code></p>';
        echo '<p><strong>' . esc_html__('Read payload (GET)', 'wp-hero-color') . '</strong><br /><code style="user-select:all;">' . esc_html($restReadTpl) . '</code></p>';
        echo '<pre style="overflow:auto;background:#f6f7f7;padding:12px;">curl -X POST ' . esc_html($restCompute) . " \\\n  -H 'Content-Type: application/json' \\\n  -H 'X-WP-Nonce: YOUR_NONCE' \\\n  --data '{\"post_id\":123,\"attachment_id\":456,\"mode\":\"conic\",\"linear_dir\":\"vertical\"}'</pre>";

        echo '<h2>' . esc_html__('WP-CLI over SSH', 'wp-hero-color') . '</h2>';
        echo '<p>' . esc_html__('Browsers cannot open SSH sessions. Copy these to run on the server (same as this plugin’s bulk form).', 'wp-hero-color') . '</p>';
        $site = preg_replace('#^https?://#', '', home_url());
        echo '<pre style="overflow:auto;background:#f6f7f7;padding:12px;">ssh user@host \'cd /path/to/wordpress && wp hero-color recompute_all --post_type=post,page --mode=conic --linear_dir=vertical\'</pre>';
        echo '<pre style="overflow:auto;background:#f6f7f7;padding:12px;">wp hero-color recompute_all --all-supported --mode=solid</pre>';
        echo '<p class="description">' . esc_html__('Replace user@host and /path/to/wordpress. Site:', 'wp-hero-color') . ' <code>' . esc_html($site) . '</code></p>';

        echo '</div>';
    }
}

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

        if (!in_array($mode, ['linear', 'conic'], true)) {
            $linearDir = null;
        }

        $prefsBefore = get_option(self::OPTION_KEY, []);
        if (!is_array($prefsBefore)) {
            $prefsBefore = [];
        }
        $prevTypes = isset($prefsBefore['post_types']) && is_array($prefsBefore['post_types']) && $prefsBefore['post_types'] !== []
            ? $prefsBefore['post_types']
            : ['post', 'page'];

        $scope = isset($_POST['scope']) ? sanitize_text_field((string) $_POST['scope']) : 'selected';
        if ('all_public' === $scope) {
            $postTypesRun = BulkRunner::publicPostTypeNames();
            $postTypesPrefs = $prevTypes;
        } else {
            $raw = isset($_POST['post_types']) && is_array($_POST['post_types']) ? $_POST['post_types'] : [];
            $postTypesRun = array_values(array_filter(array_map('sanitize_key', $raw)));
            $postTypesPrefs = $postTypesRun;
        }

        if ('all_public' !== $scope && $postTypesRun === []) {
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

        $catRaw = isset($_POST['categories']) && is_array($_POST['categories']) ? $_POST['categories'] : [];
        $tagRaw = isset($_POST['tags']) && is_array($_POST['tags']) ? $_POST['tags'] : [];
        $categoryIn = self::sanitize_term_ids($catRaw, 'category');
        $tagIn = self::sanitize_term_ids($tagRaw, 'post_tag');
        $taxFilters = [];
        if ('all_public' !== $scope) {
            if ($categoryIn !== []) {
                $taxFilters['category_in'] = $categoryIn;
            }
            if ($tagIn !== []) {
                $taxFilters['tag_in'] = $tagIn;
            }
        }

        update_option(
            self::OPTION_KEY,
            [
                'scope' => $scope,
                'post_types' => $postTypesPrefs,
                'mode' => $mode,
                'linear_dir' => $linearDir,
                'categories' => $categoryIn,
                'tags' => $tagIn,
            ],
            false
        );

        @set_time_limit(0);

        $runner = new BulkRunner(Plugin::service());
        $results = $runner->run($postTypesRun, $mode, $linearDir, $taxFilters);

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
        $savedCats = isset($prefs['categories']) && is_array($prefs['categories']) ? array_map('intval', $prefs['categories']) : [];
        $savedTags = isset($prefs['tags']) && is_array($prefs['tags']) ? array_map('intval', $prefs['tags']) : [];
        $taxRowsHidden = 'all_public' === $savedScope ? ' style="display:none;"' : '';
        $linearDirRowHidden = in_array($savedMode, ['linear', 'conic'], true) ? '' : ' style="display:none;"';

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

        $postTypesRowStyle = 'all_public' === $savedScope ? ' style="display:none;"' : '';
        echo '<tr id="wp-hero-color-post-types-row"' . $postTypesRowStyle . '><th scope="row">' . esc_html__('Post types', 'wp-hero-color') . '</th><td>';
        echo '<p class="description">' . esc_html__('Only used when scope is "Selected post types (below)". Listed types support featured images. Skips posts without a featured image.', 'wp-hero-color') . '</p>';
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:6px;max-width:640px;">';
        foreach ($thumbTypes as $type) {
            $checked = in_array($type, $savedTypes, true) ? ' checked' : '';
            echo '<label><input type="checkbox" name="post_types[]" value="' . esc_attr($type) . '"' . $checked . ' /> ' . esc_html($type) . '</label>';
        }
        echo '</div></td></tr>';

        echo '<tr id="wp-hero-color-categories-row"' . $taxRowsHidden . '><th scope="row">' . esc_html__('Categories', 'wp-hero-color') . '</th><td>';
        echo '<p class="description">' . esc_html__('Optional when scope is "Selected post types". Posts must be in at least one selected category. Combined with tags using AND. Applies via tax_query (mainly affects the built-in "post" type).', 'wp-hero-color') . '</p>';
        echo '<div style="max-height:220px;overflow:auto;border:1px solid #c3c4c7;padding:8px;border-radius:4px;max-width:640px;">';
        $cats = get_terms([
            'taxonomy' => 'category',
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
        ]);
        if (is_wp_error($cats) || !is_array($cats)) {
            echo '<p>' . esc_html__('No categories found.', 'wp-hero-color') . '</p>';
        } else {
            foreach ($cats as $term) {
                if (!isset($term->term_id)) {
                    continue;
                }
                $tid = (int) $term->term_id;
                $checked = in_array($tid, $savedCats, true) ? ' checked' : '';
                echo '<label style="display:block;margin:2px 0;"><input type="checkbox" name="categories[]" value="' . esc_attr((string) $tid) . '"' . $checked . ' /> ';
                echo esc_html((string) $term->name) . ' <span class="description">(' . esc_html((string) $tid) . ')</span></label>';
            }
        }
        echo '</div></td></tr>';

        echo '<tr id="wp-hero-color-tags-row"' . $taxRowsHidden . '><th scope="row">' . esc_html__('Tags', 'wp-hero-color') . '</th><td>';
        echo '<p class="description">' . esc_html__('Optional when scope is "Selected post types". Posts must have at least one selected tag. When both categories and tags are set, posts must match both.', 'wp-hero-color') . '</p>';
        echo '<div style="max-height:220px;overflow:auto;border:1px solid #c3c4c7;padding:8px;border-radius:4px;max-width:640px;">';
        $tags = get_terms([
            'taxonomy' => 'post_tag',
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
            'number' => 500,
        ]);
        if (is_wp_error($tags) || !is_array($tags)) {
            echo '<p>' . esc_html__('No tags found.', 'wp-hero-color') . '</p>';
        } else {
            foreach ($tags as $term) {
                if (!isset($term->term_id)) {
                    continue;
                }
                $tid = (int) $term->term_id;
                $checked = in_array($tid, $savedTags, true) ? ' checked' : '';
                echo '<label style="display:block;margin:2px 0;"><input type="checkbox" name="tags[]" value="' . esc_attr((string) $tid) . '"' . $checked . ' /> ';
                echo esc_html((string) $term->name) . ' <span class="description">(' . esc_html((string) $tid) . ')</span></label>';
            }
        }
        echo '</div></td></tr>';

        echo '<tr><th scope="row"><label for="wp-hero-color-mode">' . esc_html__('Mode override', 'wp-hero-color') . '</label></th><td>';
        echo '<select name="mode" id="wp-hero-color-mode">';
        echo '<option value="">' . esc_html__('(keep each post as saved)', 'wp-hero-color') . '</option>';
        foreach (Service::MODES as $m) {
            echo '<option value="' . esc_attr($m) . '"' . selected($savedMode, $m, false) . '>' . esc_html($m) . '</option>';
        }
        echo '</select></td></tr>';

        echo '<tr id="wp-hero-color-linear-dir-row"' . $linearDirRowHidden . '><th scope="row"><label for="wp-hero-color-dir">' . esc_html__('Linear direction override', 'wp-hero-color') . '</label></th><td>';
        echo '<select name="linear_dir" id="wp-hero-color-dir">';
        echo '<option value="">' . esc_html__('(keep each post as saved)', 'wp-hero-color') . '</option>';
        foreach (Service::LINEAR_DIRECTIONS as $d) {
            echo '<option value="' . esc_attr($d) . '"' . selected($savedDir, $d, false) . '>' . esc_html($d) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__('Shown only when mode override is linear (gradient) or conic (ambilight). Ignored for solid.', 'wp-hero-color') . '</p>';
        echo '</td></tr>';

        echo '</tbody></table>';

        submit_button(__('Run bulk recompute now', 'wp-hero-color'), 'primary', 'submit', false);
        echo '</form>';

        echo '<script>';
        echo '(function(){';
        echo 'var pt=document.getElementById("wp-hero-color-post-types-row");';
        echo 'var cr=document.getElementById("wp-hero-color-categories-row");';
        echo 'var tr=document.getElementById("wp-hero-color-tags-row");';
        echo 'var dr=document.getElementById("wp-hero-color-linear-dir-row");';
        echo 'var mode=document.getElementById("wp-hero-color-mode");';
        echo 'function scope(){var e=document.querySelector(\'input[name="scope"]:checked\');var hide=e&&e.value==="all_public";';
        echo 'var d=hide?"none":"";if(pt)pt.style.display=d;if(cr)cr.style.display=d;if(tr)tr.style.display=d;}';
        echo 'function modeRow(){if(!dr||!mode)return;var v=mode.value;var show=v==="linear"||v==="conic";dr.style.display=show?"":"none";}';
        echo 'document.querySelectorAll(\'input[name="scope"]\').forEach(function(n){n.addEventListener("change",scope);});';
        echo 'if(mode)mode.addEventListener("change",modeRow);scope();modeRow();})();';
        echo '</script>';

        echo '<h2>' . esc_html__('REST API (MCP-friendly)', 'wp-hero-color') . '</h2>';
        echo '<p>' . esc_html__('Use Application Passwords or cookie auth in tools that call WordPress REST. Meta key for stored payload:', 'wp-hero-color') . ' <code>' . esc_html(Service::META_KEY) . '</code></p>';
        echo '<p><strong>' . esc_html__('Compute (POST)', 'wp-hero-color') . '</strong><br /><code style="user-select:all;">' . esc_html($restCompute) . '</code></p>';
        echo '<p><strong>' . esc_html__('Read payload (GET)', 'wp-hero-color') . '</strong><br /><code style="user-select:all;">' . esc_html($restReadTpl) . '</code></p>';
        echo '<pre style="overflow:auto;background:#f6f7f7;padding:12px;">curl -X POST ' . esc_html($restCompute) . " \\\n  -H 'Content-Type: application/json' \\\n  -H 'X-WP-Nonce: YOUR_NONCE' \\\n  --data '{\"post_id\":123,\"attachment_id\":456,\"mode\":\"conic\",\"linear_dir\":\"vertical\"}'</pre>";

        echo '<h2>' . esc_html__('WP-CLI over SSH', 'wp-hero-color') . '</h2>';
        echo '<p>' . esc_html__('Browsers cannot open SSH sessions. Copy these to run on the server (same as this plugin’s bulk form).', 'wp-hero-color') . '</p>';
        $site = preg_replace('#^https?://#', '', home_url());
        echo '<pre style="overflow:auto;background:#f6f7f7;padding:12px;">ssh user@host \'cd /path/to/wordpress && wp hero-color recompute_all --post_type=post --mode=conic --linear_dir=vertical --category_in=3,12 --tag_in=40\'</pre>';
        echo '<pre style="overflow:auto;background:#f6f7f7;padding:12px;">wp hero-color recompute_all --all-supported --mode=solid</pre>';
        echo '<p class="description">' . esc_html__('Replace user@host and /path/to/wordpress. Site:', 'wp-hero-color') . ' <code>' . esc_html($site) . '</code></p>';

        echo '</div>';
    }

    /**
     * @param array<int,mixed> $raw
     * @return array<int,int>
     */
    private static function sanitize_term_ids(array $raw, string $taxonomy): array
    {
        $out = [];
        foreach ($raw as $item) {
            $id = (int) $item;
            if ($id < 1) {
                continue;
            }
            $term = get_term($id, $taxonomy);
            if ($term instanceof \WP_Term) {
                $out[] = $id;
            }
        }

        return array_values(array_unique($out));
    }
}

<?php

declare(strict_types=1);

namespace WPHeroColor;

use WP_CLI;
use WP_CLI\Utils;

final class CliCommand
{
    private Service $service;

    private BulkRunner $bulk;

    public function __construct(Service $service)
    {
        $this->service = $service;
        $this->bulk = new BulkRunner($service);
    }

    /**
     * Recompute hero colors for a single post.
     *
     * ## OPTIONS
     *
     * --post_id=<id>
     * : Post ID.
     *
     * [--mode=<mode>]
     * : solid|linear|conic
     *
     * [--linear_dir=<dir>]
     * : vertical|horizontal|diag_tl_br|diag_tr_bl
     *
     * [--format=<format>]
     * : table|json
     * ---
     * default: table
     * ---
     *
     * @when after_wp_load
     */
    public function recompute(array $args, array $assoc_args): void
    {
        $this->assert_requirements();

        $postId = isset($assoc_args['post_id']) ? (int) $assoc_args['post_id'] : 0;
        if ($postId < 1) {
            WP_CLI::error('Missing or invalid --post_id.');
            return;
        }

        $mode = isset($assoc_args['mode']) ? (string) $assoc_args['mode'] : null;
        $dir = isset($assoc_args['linear_dir']) ? (string) $assoc_args['linear_dir'] : null;

        $result = $this->bulk->run([$postId], $mode, $dir)[0] ?? [
            'post_id' => $postId,
            'thumbnail_id' => 0,
            'status' => 'failed',
            'message' => 'No result',
            'mode' => '',
            'linear_dir' => '',
        ];
        $this->render_results([$result], (string) ($assoc_args['format'] ?? 'table'));

        if ($result['status'] === 'failed') {
            WP_CLI::halt(1);
        }
    }

    /**
     * Recompute hero colors in bulk.
     *
     * ## OPTIONS
     *
     * [--post_type=<types>]
     * : Comma-separated post types.
     *
     * [--all-supported]
     * : Include all post types supporting thumbnails.
     *
     * [--mode=<mode>]
     * : solid|linear|conic
     *
     * [--linear_dir=<dir>]
     * : vertical|horizontal|diag_tl_br|diag_tr_bl
     *
     * [--category_in=<ids>]
     * : Comma-separated category term IDs (match any selected category; AND with tag_in when both set).
     *
     * [--tag_in=<ids>]
     * : Comma-separated post_tag term IDs (match any selected tag).
     *
     * [--format=<format>]
     * : table|json
     * ---
     * default: table
     * ---
     *
     * @when after_wp_load
     */
    public function recompute_all(array $args, array $assoc_args): void
    {
        $this->assert_requirements();

        $mode = isset($assoc_args['mode']) ? (string) $assoc_args['mode'] : null;
        $dir = isset($assoc_args['linear_dir']) ? (string) $assoc_args['linear_dir'] : null;
        $postTypes = BulkRunner::resolvePostTypesFromCliArgs($assoc_args);
        if ($postTypes === []) {
            WP_CLI::error('No post types resolved. Use --post_type or --all-supported.');
            return;
        }

        $taxFilters = BulkRunner::resolveTaxFiltersFromCliArgs($assoc_args);
        $results = $this->bulk->run($postTypes, $mode, $dir, $taxFilters);

        $this->render_results($results, (string) ($assoc_args['format'] ?? 'table'));

        $failed = array_filter($results, static fn (array $row): bool => $row['status'] === 'failed');
        if ($failed !== []) {
            WP_CLI::halt(1);
        }
    }

    private function assert_requirements(): void
    {
        if (Requirements::is_ready()) {
            return;
        }

        WP_CLI::error(wp_strip_all_tags(Requirements::blocking_message_block()));
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    private function render_results(array $rows, string $format): void
    {
        if ($format === 'json') {
            WP_CLI::line((string) wp_json_encode($rows));
            return;
        }

        Utils\format_items(
            'table',
            $rows,
            ['post_id', 'thumbnail_id', 'status', 'mode', 'linear_dir', 'message']
        );
    }
}

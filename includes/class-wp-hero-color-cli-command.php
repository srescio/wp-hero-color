<?php

declare(strict_types=1);

namespace WPHeroColor;

use WP_CLI;
use WP_CLI\Utils;

final class CliCommand
{
    private Service $service;

    public function __construct(Service $service)
    {
        $this->service = $service;
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
        $postId = isset($assoc_args['post_id']) ? (int) $assoc_args['post_id'] : 0;
        if ($postId < 1) {
            WP_CLI::error('Missing or invalid --post_id.');
            return;
        }

        $mode = isset($assoc_args['mode']) ? (string) $assoc_args['mode'] : null;
        $dir = isset($assoc_args['linear_dir']) ? (string) $assoc_args['linear_dir'] : null;

        $result = $this->recompute_post($postId, $mode, $dir);
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
        $mode = isset($assoc_args['mode']) ? (string) $assoc_args['mode'] : null;
        $dir = isset($assoc_args['linear_dir']) ? (string) $assoc_args['linear_dir'] : null;
        $postTypes = $this->resolve_post_types($assoc_args);
        if ($postTypes === []) {
            WP_CLI::error('No post types resolved. Use --post_type or --all-supported.');
            return;
        }

        $ids = get_posts([
            'post_type' => $postTypes,
            'post_status' => ['publish', 'draft', 'pending', 'future', 'private'],
            'posts_per_page' => -1,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);

        $results = [];
        foreach ($ids as $postId) {
            $results[] = $this->recompute_post((int) $postId, $mode, $dir);
        }

        $this->render_results($results, (string) ($assoc_args['format'] ?? 'table'));

        $failed = array_filter($results, static fn (array $row): bool => $row['status'] === 'failed');
        if ($failed !== []) {
            WP_CLI::halt(1);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function recompute_post(int $postId, ?string $mode, ?string $dir): array
    {
        $thumbnail = (int) get_post_thumbnail_id($postId);
        if ($thumbnail < 1) {
            return [
                'post_id' => $postId,
                'thumbnail_id' => 0,
                'status' => 'skipped',
                'message' => 'No featured image',
                'mode' => '',
                'linear_dir' => '',
            ];
        }

        try {
            $payload = $this->service->recompute_for_post($postId, $thumbnail, $mode, $dir);
        } catch (\Throwable $e) {
            return [
                'post_id' => $postId,
                'thumbnail_id' => $thumbnail,
                'status' => 'failed',
                'message' => $e->getMessage(),
                'mode' => '',
                'linear_dir' => '',
            ];
        }

        if (!is_array($payload)) {
            return [
                'post_id' => $postId,
                'thumbnail_id' => $thumbnail,
                'status' => 'failed',
                'message' => 'No payload generated',
                'mode' => '',
                'linear_dir' => '',
            ];
        }

        return [
            'post_id' => $postId,
            'thumbnail_id' => $thumbnail,
            'status' => 'processed',
            'message' => 'OK',
            'mode' => (string) $payload['mode'],
            'linear_dir' => (string) $payload['linear_dir'],
        ];
    }

    /**
     * @param array<string,mixed> $assoc_args
     * @return array<int,string>
     */
    private function resolve_post_types(array $assoc_args): array
    {
        if (!empty($assoc_args['post_type'])) {
            return array_values(array_filter(array_map('trim', explode(',', (string) $assoc_args['post_type']))));
        }

        if (!empty($assoc_args['all-supported'])) {
            return get_post_types(['public' => true], 'names');
        }

        return [];
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

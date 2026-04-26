<?php

declare(strict_types=1);

namespace WPHeroColor;

final class BulkRunner
{
    public function __construct(private Service $service)
    {
    }

    /**
     * @param array<int,string> $postTypes
     * @param array{category_in?: array<int>, tag_in?: array<int>} $taxFilters Term IDs; category and tag groups are AND-combined. Within each group, terms use IN (match any).
     * @return array<int,array<string,mixed>>
     */
    public function run(array $postTypes, ?string $mode, ?string $linearDir, array $taxFilters = []): array
    {
        $postTypes = array_values(array_unique(array_filter($postTypes, static fn (string $t): bool => $t !== '')));
        if ($postTypes === []) {
            return [];
        }

        $args = [
            'post_type' => $postTypes,
            'post_status' => ['publish', 'draft', 'pending', 'future', 'private'],
            'posts_per_page' => -1,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
            'suppress_filters' => true,
        ];

        $taxQuery = self::buildTaxQuery($taxFilters);
        if ($taxQuery !== null) {
            $args['tax_query'] = $taxQuery;
        }

        $ids = get_posts($args);

        $results = [];
        foreach ($ids as $postId) {
            $results[] = $this->recomputeOne((int) $postId, $mode, $linearDir);
        }

        return $results;
    }

    /**
     * @param array<string,mixed> $assocArgs
     * @return array<int,string>
     */
    public static function resolvePostTypesFromCliArgs(array $assocArgs): array
    {
        if (!empty($assocArgs['post_type'])) {
            return array_values(array_filter(array_map('trim', explode(',', (string) $assocArgs['post_type']))));
        }

        if (!empty($assocArgs['all-supported'])) {
            return self::publicPostTypeNames();
        }

        return [];
    }

    /**
     * @return array<int,string>
     */
    public static function publicPostTypeNames(): array
    {
        return array_values(get_post_types(['public' => true], 'names'));
    }

    /**
     * Post types that support a featured image (for admin UI defaults).
     *
     * @return array<int,string>
     */
    public static function thumbnailPostTypeNames(): array
    {
        $out = [];
        foreach (get_post_types([], 'names') as $name) {
            if (post_type_supports((string) $name, 'thumbnail')) {
                $out[] = (string) $name;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param array<string,mixed> $assocArgs
     * @return array{category_in?: array<int>, tag_in?: array<int>}
     */
    public static function resolveTaxFiltersFromCliArgs(array $assocArgs): array
    {
        $out = [];
        if (!empty($assocArgs['category_in'])) {
            $ids = array_values(array_filter(array_map('intval', explode(',', (string) $assocArgs['category_in']))));
            if ($ids !== []) {
                $out['category_in'] = $ids;
            }
        }
        if (!empty($assocArgs['tag_in'])) {
            $ids = array_values(array_filter(array_map('intval', explode(',', (string) $assocArgs['tag_in']))));
            if ($ids !== []) {
                $out['tag_in'] = $ids;
            }
        }

        return $out;
    }

    /**
     * @param array{category_in?: array<int>, tag_in?: array<int>} $taxFilters
     * @return array<string,mixed>|null
     */
    public static function buildTaxQuery(array $taxFilters): ?array
    {
        $catIn = isset($taxFilters['category_in']) && is_array($taxFilters['category_in'])
            ? array_values(array_unique(array_filter(array_map('intval', $taxFilters['category_in']), static fn (int $id): bool => $id > 0)))
            : [];
        $tagIn = isset($taxFilters['tag_in']) && is_array($taxFilters['tag_in'])
            ? array_values(array_unique(array_filter(array_map('intval', $taxFilters['tag_in']), static fn (int $id): bool => $id > 0)))
            : [];

        if ($catIn === [] && $tagIn === []) {
            return null;
        }

        $clauses = [];
        if ($catIn !== []) {
            $clauses[] = [
                'taxonomy' => 'category',
                'field' => 'term_id',
                'terms' => $catIn,
                'operator' => 'IN',
                'include_children' => true,
            ];
        }
        if ($tagIn !== []) {
            $clauses[] = [
                'taxonomy' => 'post_tag',
                'field' => 'term_id',
                'terms' => $tagIn,
                'operator' => 'IN',
            ];
        }

        if ($clauses === []) {
            return null;
        }

        $clauses['relation'] = 'AND';

        return $clauses;
    }

    /**
     * @return array<string,mixed>
     */
    private function recomputeOne(int $postId, ?string $mode, ?string $dir): array
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
}

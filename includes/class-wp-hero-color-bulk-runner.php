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
     * @return array<int,array<string,mixed>>
     */
    public function run(array $postTypes, ?string $mode, ?string $linearDir): array
    {
        $postTypes = array_values(array_unique(array_filter($postTypes, static fn (string $t): bool => $t !== '')));
        if ($postTypes === []) {
            return [];
        }

        $ids = get_posts([
            'post_type' => $postTypes,
            'post_status' => ['publish', 'draft', 'pending', 'future', 'private'],
            'posts_per_page' => -1,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
            'suppress_filters' => true,
        ]);

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

<?php

declare(strict_types=1);

namespace WPHeroColor;

use RuntimeException;

final class Service
{
    public const META_KEY = '_sr_hero_bg';
    public const EDGE_KEYS = ['tl', 't', 'tr', 'r', 'br', 'b', 'bl', 'l'];
    public const MODES = ['solid', 'linear', 'conic'];
    public const LINEAR_DIRECTIONS = ['vertical', 'horizontal', 'diag_tl_br', 'diag_tr_bl'];

    /**
     * @return array<string,mixed>|null
     */
    public function get_payload(int $post_id): ?array
    {
        $raw = get_post_meta($post_id, self::META_KEY, true);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        return $this->sanitize_payload($decoded);
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function save_payload(int $post_id, array $payload): void
    {
        $sanitized = $this->sanitize_payload($payload);
        update_post_meta($post_id, self::META_KEY, wp_json_encode($sanitized));
    }

    public function register_meta(): void
    {
        register_post_meta(
            '',
            self::META_KEY,
            [
                'type' => 'string',
                'single' => true,
                'show_in_rest' => true,
                'sanitize_callback' => static function ($value): string {
                    if (!is_string($value)) {
                        return '';
                    }

                    $decoded = json_decode($value, true);
                    if (!is_array($decoded)) {
                        return '';
                    }

                    $service = new self();

                    return (string) wp_json_encode($service->sanitize_payload($decoded));
                },
                'auth_callback' => static function (): bool {
                    return current_user_can('edit_posts');
                },
            ]
        );
    }

    public function recompute_for_post(
        int $post_id,
        ?int $attachment_id = null,
        ?string $mode = null,
        ?string $linear_dir = null
    ): ?array {
        if ($attachment_id === null || $attachment_id < 1) {
            $attachment_id = (int) get_post_thumbnail_id($post_id);
        }

        if ($attachment_id < 1 || !wp_attachment_is_image($attachment_id)) {
            return null;
        }

        $existing = $this->get_payload($post_id);
        $payload = $this->compute_payload_from_attachment($attachment_id, $existing, $mode, $linear_dir);
        $this->save_payload($post_id, $payload);

        return $payload;
    }

    /**
     * @param array<string,mixed>|null $existing
     * @return array<string,mixed>
     */
    public function compute_payload_from_attachment(
        int $attachment_id,
        ?array $existing = null,
        ?string $mode = null,
        ?string $linear_dir = null
    ): array {
        $path = get_attached_file($attachment_id);
        if (!is_string($path) || $path === '' || !file_exists($path)) {
            throw new RuntimeException('Attachment file not found.');
        }

        $binary = file_get_contents($path);
        if ($binary === false) {
            throw new RuntimeException('Unable to read image bytes.');
        }

        $source = @imagecreatefromstring($binary);
        if (!$source) {
            throw new RuntimeException('Unable to decode image.');
        }

        $width = (int) imagesx($source);
        $height = (int) imagesy($source);
        if ($width < 2 || $height < 2) {
            imagedestroy($source);
            throw new RuntimeException('Invalid image dimensions.');
        }

        $maxWidth = 320;
        if ($width > $maxWidth) {
            $targetWidth = $maxWidth;
            $targetHeight = max(2, (int) round(($height / $width) * $targetWidth));
        } else {
            $targetWidth = $width;
            $targetHeight = $height;
        }

        $img = imagecreatetruecolor($targetWidth, $targetHeight);
        imagecopyresampled($img, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
        imagedestroy($source);

        $band = max(8, (int) round(min($targetWidth, $targetHeight) * 0.12));
        $main = $this->sample_region(
            $img,
            $band,
            max($band + 1, $targetWidth - $band),
            $band,
            max($band + 1, $targetHeight - $band)
        );

        $regions = [
            'tl' => [0, $band, 0, $band],
            't' => [$band, max($band + 1, $targetWidth - $band), 0, $band],
            'tr' => [max(0, $targetWidth - $band), $targetWidth, 0, $band],
            'r' => [max(0, $targetWidth - $band), $targetWidth, $band, max($band + 1, $targetHeight - $band)],
            'br' => [max(0, $targetWidth - $band), $targetWidth, max(0, $targetHeight - $band), $targetHeight],
            'b' => [$band, max($band + 1, $targetWidth - $band), max(0, $targetHeight - $band), $targetHeight],
            'bl' => [0, $band, max(0, $targetHeight - $band), $targetHeight],
            'l' => [0, $band, $band, max($band + 1, $targetHeight - $band)],
        ];

        $edges = [];
        foreach (self::EDGE_KEYS as $key) {
            [$x1, $x2, $y1, $y2] = $regions[$key];
            $edges[] = $this->rgb_to_css($this->sample_region($img, $x1, $x2, $y1, $y2));
        }

        imagedestroy($img);

        $existingMode = is_array($existing) && isset($existing['mode']) ? (string) $existing['mode'] : 'solid';
        $existingDir = is_array($existing) && isset($existing['linear_dir']) ? (string) $existing['linear_dir'] : 'vertical';

        $payload = [
            'v' => 1,
            'main' => $this->rgb_to_css($main),
            'edges' => $edges,
            'mode' => $mode ?? $existingMode,
            'linear_dir' => $linear_dir ?? $existingDir,
            'attachment_id' => $attachment_id,
            'updated_at' => gmdate(DATE_ATOM),
        ];

        return $this->sanitize_payload($payload);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function sanitize_payload(array $payload): array
    {
        $mode = isset($payload['mode']) ? (string) $payload['mode'] : 'solid';
        if (!in_array($mode, self::MODES, true)) {
            $mode = 'solid';
        }

        $linearDir = isset($payload['linear_dir']) ? (string) $payload['linear_dir'] : 'vertical';
        if (!in_array($linearDir, self::LINEAR_DIRECTIONS, true)) {
            $linearDir = 'vertical';
        }

        $main = isset($payload['main']) ? (string) $payload['main'] : 'rgb(34,34,34)';
        if (!$this->is_valid_rgb_css($main)) {
            $main = 'rgb(34,34,34)';
        }

        $edges = [];
        $inputEdges = isset($payload['edges']) && is_array($payload['edges']) ? $payload['edges'] : [];
        foreach (range(0, 7) as $idx) {
            $value = isset($inputEdges[$idx]) ? (string) $inputEdges[$idx] : $main;
            $edges[] = $this->is_valid_rgb_css($value) ? $value : $main;
        }

        return [
            'v' => 1,
            'main' => $main,
            'edges' => $edges,
            'mode' => $mode,
            'linear_dir' => $linearDir,
            'attachment_id' => isset($payload['attachment_id']) ? (int) $payload['attachment_id'] : 0,
            'updated_at' => isset($payload['updated_at']) ? (string) $payload['updated_at'] : gmdate(DATE_ATOM),
        ];
    }

    public function is_valid_rgb_css(string $value): bool
    {
        return (bool) preg_match('/^rgb\\((\\s*\\d{1,3}\\s*,){2}\\s*\\d{1,3}\\s*\\)$/', trim($value));
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function build_background_css(array $payload): string
    {
        $payload = $this->sanitize_payload($payload);
        $mode = (string) $payload['mode'];

        if ($mode === 'conic') {
            /** @var array<int,string> $edges */
            $edges = $payload['edges'];
            $stops = [];
            foreach ($edges as $index => $edge) {
                $stops[] = sprintf('%s %ddeg', $edge, $index * 45);
            }
            $stops[] = sprintf('%s 360deg', $edges[0]);

            return sprintf('conic-gradient(from 0deg at 50%% 50%%, %s)', implode(', ', $stops));
        }

        if ($mode === 'linear') {
            /** @var array<int,string> $edges */
            $edges = $payload['edges'];
            $direction = (string) $payload['linear_dir'];

            [$start, $end, $cssDirection] = $this->linear_gradient_stops_from_edges($edges, $direction);

            return sprintf('linear-gradient(%s, %s, %s)', $cssDirection, $start, $end);
        }

        return (string) $payload['main'];
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function build_inline_style(array $payload): string
    {
        $payload = $this->sanitize_payload($payload);
        $background = $this->build_background_css($payload);

        $vars = [
            '--sr-hero-main:' . $payload['main'],
            '--sr-hero-bg:' . $payload['main'],
            '--sr-hero-bg-image:' . $background,
        ];

        if ((string) $payload['mode'] === 'solid') {
            $vars[] = 'background-color:' . $payload['main'];
        } else {
            $vars[] = 'background-image:' . $background;
            $vars[] = 'background-color:' . $payload['main'];
        }

        return implode(';', $vars) . ';';
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,string>
     */
    public function build_attributes(array $payload): array
    {
        $payload = $this->sanitize_payload($payload);

        return [
            'data-sr-hero-computed' => '1',
            'data-sr-hero-mode' => (string) $payload['mode'],
            'data-sr-hero-dir' => (string) $payload['linear_dir'],
            'style' => $this->build_inline_style($payload),
        ];
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    private function sample_region($img, int $x1, int $x2, int $y1, int $y2): array
    {
        $w = (int) imagesx($img);
        $h = (int) imagesy($img);

        $x1 = max(0, min($w - 1, $x1));
        $x2 = max($x1 + 1, min($w, $x2));
        $y1 = max(0, min($h - 1, $y1));
        $y2 = max($y1 + 1, min($h, $y2));

        $step = max(1, (int) floor(min($w, $h) / 80));
        $bucketSize = 16;
        $palette = [];

        for ($x = $x1; $x < $x2; $x += $step) {
            for ($y = $y1; $y < $y2; $y += $step) {
                $index = imagecolorat($img, $x, $y);
                $r = ($index >> 16) & 0xFF;
                $g = ($index >> 8) & 0xFF;
                $b = $index & 0xFF;

                if (($r < 6 && $g < 6 && $b < 6) || ($r > 249 && $g > 249 && $b > 249)) {
                    continue;
                }

                $rb = (int) min(255, round($r / $bucketSize) * $bucketSize);
                $gb = (int) min(255, round($g / $bucketSize) * $bucketSize);
                $bb = (int) min(255, round($b / $bucketSize) * $bucketSize);
                $key = $rb . ',' . $gb . ',' . $bb;

                if (!isset($palette[$key])) {
                    $palette[$key] = 0;
                }
                $palette[$key]++;
            }
        }

        if ($palette === []) {
            return [34, 34, 34];
        }

        arsort($palette);
        $first = (string) array_key_first($palette);
        [$r, $g, $b] = array_map('intval', explode(',', $first));

        return [$r, $g, $b];
    }

    /**
     * @param array{0:int,1:int,2:int} $rgb
     */
    private function rgb_to_css(array $rgb): string
    {
        return sprintf(
            'rgb(%d,%d,%d)',
            max(0, min(255, $rgb[0])),
            max(0, min(255, $rgb[1])),
            max(0, min(255, $rgb[2]))
        );
    }

    /**
     * @param array<int,string> $edges
     * @return array{0:string,1:string,2:string}
     */
    private function linear_gradient_stops_from_edges(array $edges, string $direction): array
    {
        $cssDirection = 'to bottom';
        $startIndices = [0, 1, 2];
        $endIndices = [6, 5, 4];

        if ($direction === 'horizontal') {
            $cssDirection = 'to right';
            $startIndices = [7, 0, 6];
            $endIndices = [3, 2, 4];
        } elseif ($direction === 'diag_tl_br') {
            $cssDirection = 'to bottom right';
            $startIndices = [0, 1, 7];
            $endIndices = [4, 3, 5];
        } elseif ($direction === 'diag_tr_bl') {
            $cssDirection = 'to bottom left';
            $startIndices = [2, 1, 3];
            $endIndices = [6, 5, 7];
        }

        $start = $this->average_colors([$edges[$startIndices[0]], $edges[$startIndices[1]], $edges[$startIndices[2]]]);
        $end = $this->average_colors([$edges[$endIndices[0]], $edges[$endIndices[1]], $edges[$endIndices[2]]]);

        return [$start, $end, $cssDirection];
    }

    /**
     * @param array<int,string> $colors
     */
    private function average_colors(array $colors): string
    {
        $sumR = 0;
        $sumG = 0;
        $sumB = 0;
        $count = 0;

        foreach ($colors as $color) {
            if (!$this->is_valid_rgb_css($color)) {
                continue;
            }
            preg_match_all('/\\d+/', $color, $matches);
            if (!isset($matches[0]) || count($matches[0]) < 3) {
                continue;
            }
            $sumR += (int) $matches[0][0];
            $sumG += (int) $matches[0][1];
            $sumB += (int) $matches[0][2];
            $count++;
        }

        if ($count < 1) {
            return 'rgb(34,34,34)';
        }

        return sprintf('rgb(%d,%d,%d)', (int) round($sumR / $count), (int) round($sumG / $count), (int) round($sumB / $count));
    }
}

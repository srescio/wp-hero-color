<?php

declare(strict_types=1);

namespace WPHeroColor;

/**
 * Runtime checks for server capabilities used to decode images and sample colors.
 *
 * Color extraction uses PHP GD only (no ImageMagick, no external tools).
 */
final class Requirements
{
    /**
     * Human-readable facts for the settings “Environment” table.
     *
     * @return array<string,string>
     */
    public static function environment_facts(): array
    {
        $facts = [
            __('PHP version', 'wp-hero-color') => PHP_VERSION,
            __('JSON encode/decode', 'wp-hero-color') => (function_exists('json_encode') && function_exists('json_decode'))
                ? __('Available', 'wp-hero-color')
                : __('Missing (required)', 'wp-hero-color'),
            __('Read files (file_get_contents)', 'wp-hero-color') => self::file_get_contents_usable()
                ? __('Available', 'wp-hero-color')
                : __('Disabled in PHP configuration', 'wp-hero-color'),
            __('GD extension', 'wp-hero-color') => extension_loaded('gd')
                ? __('Loaded', 'wp-hero-color')
                : __('Not loaded', 'wp-hero-color'),
        ];

        if (extension_loaded('gd') && function_exists('gd_info')) {
            /** @var array<string,mixed> $gd */
            $gd = gd_info();
            $facts[__('GD version', 'wp-hero-color')] = isset($gd['GD Version']) ? (string) $gd['GD Version'] : '—';
            $facts[__('GD: JPEG', 'wp-hero-color')] = !empty($gd['JPEG Support'])
                ? __('Yes', 'wp-hero-color')
                : __('No', 'wp-hero-color');
            $facts[__('GD: PNG', 'wp-hero-color')] = !empty($gd['PNG Support'])
                ? __('Yes', 'wp-hero-color')
                : __('No', 'wp-hero-color');
            $facts[__('GD: WebP', 'wp-hero-color')] = !empty($gd['WebP Support'])
                ? __('Yes', 'wp-hero-color')
                : __('No', 'wp-hero-color');
        }

        return $facts;
    }

    /**
     * @return list<string>
     */
    public static function blocking_messages(): array
    {
        $messages = [];

        if (version_compare(PHP_VERSION, '8.0', '<')) {
            $messages[] = sprintf(
                /* translators: %s: current PHP version */
                __('WP Hero Color requires PHP 8.0 or newer. This server is running PHP %s.', 'wp-hero-color'),
                PHP_VERSION
            );
        }

        if (!function_exists('json_encode') || !function_exists('json_decode')) {
            $messages[] = __('The json extension is required to store hero color data.', 'wp-hero-color');
        }

        if (!self::file_get_contents_usable()) {
            $messages[] = __(
                'file_get_contents() is disabled in php.ini (disable_functions). The plugin cannot read image files from disk.',
                'wp-hero-color'
            );
        }

        if (!extension_loaded('gd')) {
            $messages[] = __(
                'The PHP GD extension is not loaded. It is required to decode featured images and sample colors.',
                'wp-hero-color'
            );

            return $messages;
        }

        $requiredGd = [
            'imagecreatefromstring' => __('imagecreatefromstring()', 'wp-hero-color'),
            'imagecreatetruecolor' => __('imagecreatetruecolor()', 'wp-hero-color'),
            'imagecopyresampled' => __('imagecopyresampled()', 'wp-hero-color'),
            'imagecolorat' => __('imagecolorat()', 'wp-hero-color'),
            'imagesx' => __('imagesx() / imagesy()', 'wp-hero-color'),
        ];

        foreach ($requiredGd as $fn => $label) {
            if ($fn === 'imagesx') {
                if (!function_exists('imagesx') || !function_exists('imagesy')) {
                    $messages[] = sprintf(
                        /* translators: %s: PHP function name */
                        __('GD is missing a required function: %s.', 'wp-hero-color'),
                        $label
                    );
                }
            } elseif (!function_exists($fn)) {
                $messages[] = sprintf(
                    /* translators: %s: PHP function name */
                    __('GD is missing a required function: %s.', 'wp-hero-color'),
                    $label
                );
            }
        }

        if (function_exists('gd_info')) {
            /** @var array<string,mixed> $gd */
            $gd = gd_info();
            $jpeg = !empty($gd['JPEG Support']);
            $png = !empty($gd['PNG Support']);
            $webp = !empty($gd['WebP Support']);
            if (!$jpeg && !$png && !$webp) {
                $messages[] = __(
                    'GD is loaded but JPEG, PNG, and WebP support are all unavailable. At least one common image format must be enabled in GD to decode uploads.',
                    'wp-hero-color'
                );
            }
        }

        return $messages;
    }

    /**
     * Non-fatal issues (recompute may still work for some formats).
     *
     * @return list<string>
     */
    public static function warning_messages(): array
    {
        $warnings = [];

        if (!extension_loaded('gd') || !function_exists('gd_info')) {
            return $warnings;
        }

        /** @var array<string,mixed> $gd */
        $gd = gd_info();
        $jpeg = !empty($gd['JPEG Support']);
        $png = !empty($gd['PNG Support']);
        $webp = !empty($gd['WebP Support']);

        if ($jpeg && !$webp) {
            $warnings[] = __(
                'GD has JPEG support but not WebP. Featured images uploaded as WebP may fail to decode until WebP support is enabled in PHP GD.',
                'wp-hero-color'
            );
        }

        if (!$jpeg && $png) {
            $warnings[] = __(
                'GD has PNG support but not JPEG. JPEG featured images may fail to decode until JPEG support is enabled in PHP GD.',
                'wp-hero-color'
            );
        }

        return $warnings;
    }

    public static function is_ready(): bool
    {
        return self::blocking_messages() === [];
    }

    public static function blocking_message_block(): string
    {
        $items = self::blocking_messages();

        return $items === [] ? '' : implode("\n", $items);
    }

    private static function file_get_contents_usable(): bool
    {
        if (!function_exists('file_get_contents')) {
            return false;
        }

        $disabled = ini_get('disable_functions');
        if (!is_string($disabled) || $disabled === '') {
            return true;
        }

        foreach (array_map('trim', explode(',', $disabled)) as $name) {
            if ($name !== '' && strcasecmp($name, 'file_get_contents') === 0) {
                return false;
            }
        }

        return true;
    }
}

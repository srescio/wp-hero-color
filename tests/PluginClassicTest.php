<?php

declare(strict_types=1);

namespace WPHeroColor\Tests;

use PHPUnit\Framework\TestCase;
use WPHeroColor\Plugin;

final class PluginClassicTest extends TestCase
{
    public function test_classic_meta_box_methods_exist(): void
    {
        self::assertTrue(method_exists(Plugin::class, 'register_classic_meta_box'));
        self::assertTrue(method_exists(Plugin::class, 'render_classic_meta_box'));
        self::assertTrue(method_exists(Plugin::class, 'save_classic_meta_box'));
    }

    public function test_existing_core_entrypoints_still_exist(): void
    {
        self::assertTrue(method_exists(Plugin::class, 'bootstrap'));
        self::assertTrue(method_exists(Plugin::class, 'register_rest_routes'));
        self::assertTrue(method_exists(Plugin::class, 'enqueue_editor_assets'));
    }
}


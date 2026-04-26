<?php

declare(strict_types=1);

namespace WPHeroColor\Tests;

use PHPUnit\Framework\TestCase;
use WPHeroColor\Service;

final class ServiceTest extends TestCase
{
    public function test_sanitize_payload_normalizes_invalid_values(): void
    {
        $service = new Service();

        $payload = $service->sanitize_payload([
            'main' => 'invalid-color',
            'edges' => ['rgb(1,2,3)'],
            'mode' => 'bad-mode',
            'linear_dir' => 'bad-dir',
            'attachment_id' => '12',
        ]);

        self::assertSame('solid', $payload['mode']);
        self::assertSame('vertical', $payload['linear_dir']);
        self::assertSame('rgb(34,34,34)', $payload['main']);
        self::assertCount(8, $payload['edges']);
        self::assertSame(12, $payload['attachment_id']);
    }

    public function test_linear_css_is_generated_with_expected_direction(): void
    {
        $service = new Service();

        $payload = [
            'main' => 'rgb(100,100,100)',
            'edges' => [
                'rgb(10,10,10)',
                'rgb(20,20,20)',
                'rgb(30,30,30)',
                'rgb(40,40,40)',
                'rgb(200,200,200)',
                'rgb(210,210,210)',
                'rgb(220,220,220)',
                'rgb(230,230,230)',
            ],
            'mode' => 'linear',
            'linear_dir' => 'horizontal',
            'attachment_id' => 99,
        ];

        $css = $service->build_background_css($payload);

        self::assertStringStartsWith('linear-gradient(to right', $css);
    }

    public function test_conic_css_includes_full_rotation(): void
    {
        $service = new Service();
        $payload = [
            'main' => 'rgb(100,100,100)',
            'edges' => [
                'rgb(10,10,10)',
                'rgb(20,20,20)',
                'rgb(30,30,30)',
                'rgb(40,40,40)',
                'rgb(50,50,50)',
                'rgb(60,60,60)',
                'rgb(70,70,70)',
                'rgb(80,80,80)',
            ],
            'mode' => 'conic',
            'linear_dir' => 'vertical',
            'attachment_id' => 12,
        ];

        $css = $service->build_background_css($payload);

        self::assertStringContainsString('conic-gradient(', $css);
        self::assertStringContainsString('360deg', $css);
    }
}

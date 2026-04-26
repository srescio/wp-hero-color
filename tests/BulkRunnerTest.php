<?php

declare(strict_types=1);

namespace WPHeroColor\Tests;

use PHPUnit\Framework\TestCase;
use WPHeroColor\BulkRunner;

final class BulkRunnerTest extends TestCase
{
    public function test_build_tax_query_returns_null_when_empty(): void
    {
        self::assertNull(BulkRunner::buildTaxQuery([]));
        self::assertNull(BulkRunner::buildTaxQuery(['category_in' => [], 'tag_in' => []]));
    }

    public function test_build_tax_query_category_clause(): void
    {
        $q = BulkRunner::buildTaxQuery(['category_in' => [2, 5]]);
        self::assertIsArray($q);
        self::assertSame('AND', $q['relation']);
        self::assertSame('category', $q[0]['taxonomy']);
        self::assertSame([2, 5], $q[0]['terms']);
        self::assertSame('IN', $q[0]['operator']);
    }

    public function test_build_tax_query_category_and_tag(): void
    {
        $q = BulkRunner::buildTaxQuery(['category_in' => [1], 'tag_in' => [9, 10]]);
        self::assertIsArray($q);
        self::assertSame('AND', $q['relation']);
        self::assertSame('post_tag', $q[1]['taxonomy']);
        self::assertSame([9, 10], $q[1]['terms']);
    }

    public function test_resolve_tax_filters_from_cli_args(): void
    {
        $f = BulkRunner::resolveTaxFiltersFromCliArgs([
            'category_in' => '1, 2',
            'tag_in' => '99',
        ]);
        self::assertSame([1, 2], $f['category_in']);
        self::assertSame([99], $f['tag_in']);
    }
}

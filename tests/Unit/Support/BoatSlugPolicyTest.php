<?php

declare(strict_types=1);

namespace Maradigma\Tests\Unit\Support;

use Maradigma\Support\BoatSlugPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BoatSlugPolicyTest extends TestCase
{
    #[DataProvider('slugProvider')]
    public function testItRecognizesNumberedVariantsOfTheBaseSlug(string $slug, string $base, bool $expected): void
    {
        self::assertSame($expected, BoatSlugPolicy::isVariantOf($slug, $base));
    }

    /**
     * @return iterable<string,array{0:string,1:string,2:bool}>
     */
    public static function slugProvider(): iterable
    {
        yield 'same slug' => ['bertram-131', 'bertram-131', true];
        yield 'second copy' => ['bertram-131-2', 'bertram-131', true];
        yield 'ninth copy' => ['bertram-131-9', 'bertram-131', true];
        yield 'tenth copy' => ['bertram-131-10', 'bertram-131', true];
        yield 'twenty-first copy' => ['bertram-131-21', 'bertram-131', true];
        yield 'base ending in a number' => ['lomac-790-2', 'lomac-790-2', true];
        yield 'variant of a base ending in a number' => ['lomac-790-2-2', 'lomac-790-2', true];
        yield 'wordpress never adds -1' => ['bertram-131-1', 'bertram-131', false];
        yield 'leading zero is not a wordpress suffix' => ['bertram-131-02', 'bertram-131', false];
        yield 'renamed boat' => ['bertram-131', 'bertram-132', false];
        yield 'longer name sharing a prefix' => ['bertram-131-flybridge', 'bertram-131', false];
        yield 'regex characters in base' => ['a-b-2', 'a.b', false];
        yield 'empty slug' => ['', 'bertram-131', false];
        yield 'empty base' => ['bertram-131', '', false];
    }
}

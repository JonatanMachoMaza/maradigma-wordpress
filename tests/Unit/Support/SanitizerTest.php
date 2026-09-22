<?php

declare(strict_types=1);

namespace Maradigma\Tests\Unit\Support;

use Maradigma\Support\Sanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SanitizerTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, int|null}>
     */
    public static function idProvider(): iterable
    {
        yield 'integer' => [42, 42];
        yield 'numeric string' => ['7', 7];
        yield 'zero' => [0, null];
        yield 'negative' => [-3, null];
        yield 'empty' => ['', null];
        yield 'null' => [null, null];
    }

    #[DataProvider('idProvider')]
    public function testIdReturnsOnlyPositiveIdentifiers(mixed $input, ?int $expected): void
    {
        self::assertSame($expected, Sanitizer::id($input));
    }

    public function testSlugUsesFrameworkIndependentFallbackOutsideWordPress(): void
    {
        self::assertSame('pardo-yachts-38-ibiza', Sanitizer::slug(' Pardo Yachts 38 Ibiza '));
        self::assertNull(Sanitizer::slug('***'));
    }

    public function testBooleanValuesAreNormalizedStrictly(): void
    {
        self::assertTrue(Sanitizer::bool('YES'));
        self::assertTrue(Sanitizer::bool(1));
        self::assertFalse(Sanitizer::bool('false'));
        self::assertFalse(Sanitizer::bool(2));
    }

    public function testCssClassRemovesUnsafeCharactersAndNormalizesWhitespace(): void
    {
        self::assertSame('boat-card alertscript', Sanitizer::cssClass(" boat-card   alert<script> "));
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function locationTokenProvider(): iterable
    {
        yield 'bare destination id' => ['1704', 'destination:1704'];
        yield 'destination token' => ['destination:1704', 'destination:1704'];
        yield 'port token with spaces and case' => [' Port : 12 ', 'port:12'];
        yield 'zero id' => ['destination:0', null];
        yield 'leading zero' => ['0704', null];
        yield 'unknown kind' => ['city:5', null];
        yield 'text' => ['ibiza', null];
        yield 'empty' => ['', null];
    }

    #[DataProvider('locationTokenProvider')]
    public function testLocationTokensAreNormalized(string $input, ?string $expected): void
    {
        self::assertSame($expected, Sanitizer::normalizeLocationToken($input));
    }

    public function testDestinationAliasBecomesDepartureLocationAndCombinesWithBoatType(): void
    {
        $documentation = [
            ['attr' => 'id_group', 'type' => 'string', 'default' => 'boats', 'description' => 'Group'],
            ['attr' => 'id_group_content_type', 'type' => 'int', 'default' => '', 'description' => 'Type'],
            ['attr' => 'departure_location', 'type' => 'location', 'default' => '', 'description' => 'Location'],
        ];

        self::assertSame(
            ['departure_location' => 'destination:1704', 'id_group' => 'boats', 'id_group_content_type' => 3],
            Sanitizer::normalizeBoatsSearchAtts(['destination' => '1704', 'boat_type_id' => '3'], $documentation)
        );

        // An explicit departure_location wins over the alias; an invalid token is dropped.
        self::assertSame(
            ['departure_location' => 'port:2', 'id_group' => 'boats'],
            Sanitizer::normalizeBoatsSearchAtts(['destination' => '1704', 'departure_location' => 'port:2'], $documentation)
        );
        self::assertSame(
            ['id_group' => 'boats'],
            Sanitizer::normalizeBoatsSearchAtts(['departure_location' => 'ibiza'], $documentation)
        );
    }

    public function testSearchAttributesApplyAliasesTypesDefaultsAndStableOrdering(): void
    {
        $documentation = [
            ['attr' => 'id_group', 'type' => 'string', 'default' => 'boats', 'description' => 'Group'],
            ['attr' => 'min_price', 'type' => 'float', 'default' => '', 'description' => 'Minimum price'],
            ['attr' => 'boat_id_builder', 'type' => 'int|int[]', 'default' => '', 'description' => 'Builders'],
            ['attr' => 'ins_book', 'type' => 'bool', 'default' => '', 'description' => 'Instant booking'],
        ];

        $actual = Sanitizer::normalizeBoatsSearchAtts(
            [
                'price-min' => '125.50',
                'builders' => '302, 113, 302',
                'ins_book' => 'yes',
                'not_allowed' => 'discard me',
            ],
            $documentation
        );

        self::assertSame(
            [
                'boat_id_builder' => [302, 113],
                'id_group' => 'boats',
                'ins_book' => true,
                'min_price' => 125.5,
            ],
            $actual
        );
    }
}

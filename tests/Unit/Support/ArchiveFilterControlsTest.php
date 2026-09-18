<?php

declare(strict_types=1);

namespace Maradigma\Tests\Unit\Support;

use Maradigma\Support\ArchiveFilterControls;
use PHPUnit\Framework\TestCase;

final class ArchiveFilterControlsTest extends TestCase
{
    public function testStepperMaxIsHigherForCapacity(): void
    {
        self::assertSame(100, ArchiveFilterControls::stepperMax('boat_capacity'));
        self::assertSame(10, ArchiveFilterControls::stepperMax('boat_cabins'));
        self::assertSame(10, ArchiveFilterControls::stepperMax('boat_bathrooms'));
    }

    /**
     * @dataProvider countProvider
     */
    public function testParseCountTreatsAnythingButAPositiveIntegerAsNoFilter(string $raw, int $max, int $expected): void
    {
        self::assertSame($expected, ArchiveFilterControls::parseCount($raw, $max));
    }

    /**
     * @return array<string,array{string,int,int}>
     */
    public static function countProvider(): array
    {
        return [
            'empty'            => ['', 10, 0],
            'zero'             => ['0', 10, 0],
            'valid'            => ['3', 10, 3],
            'padded'           => [' 4 ', 10, 4],
            'above the limit'  => ['25', 10, 10],
            'negative'         => ['-2', 10, 0],
            'decimal'          => ['2.5', 10, 0],
            'text'             => ['abc', 10, 0],
        ];
    }

    public function testQueryParamsForField(): void
    {
        self::assertSame(['md_min_price', 'md_max_price'], ArchiveFilterControls::queryParamsForField('price_range'));
        self::assertSame(['md_min_boat_length', 'md_max_boat_length'], ArchiveFilterControls::queryParamsForField('boat_length'));
        self::assertSame(['md_boat_cabins'], ArchiveFilterControls::queryParamsForField('boat_cabins'));
        self::assertSame(['md_boat_skipper_option'], ArchiveFilterControls::queryParamsForField('boat_skipper_option'));
    }

    public function testLengthBoundsAreRoundedOutwardsToWholeMeters(): void
    {
        self::assertSame(['min' => 8, 'max' => 18], ArchiveFilterControls::normalizeLengthBounds(8.4, 17.9));
        self::assertSame(['min' => 8, 'max' => 18], ArchiveFilterControls::normalizeLengthBounds('8.00', '17.90'));
    }

    public function testLengthBoundsKeepAPositiveSpan(): void
    {
        self::assertSame(['min' => 12, 'max' => 13], ArchiveFilterControls::normalizeLengthBounds(12.0, 12.0));
    }

    public function testLengthBoundsFallBackWhenTheCatalogRangeIsUnknown(): void
    {
        self::assertSame(['min' => 0, 'max' => 50], ArchiveFilterControls::normalizeLengthBounds(null, null));
    }

    public function testRangeValuesDefaultToTheBounds(): void
    {
        self::assertSame(['min' => 8, 'max' => 18], ArchiveFilterControls::resolveRangeValues(8, 18, '', ''));
    }

    public function testRangeValuesAreClampedInsideTheBounds(): void
    {
        self::assertSame(['min' => 8, 'max' => 18], ArchiveFilterControls::resolveRangeValues(8, 18, '2', '99'));
        self::assertSame(['min' => 10, 'max' => 14], ArchiveFilterControls::resolveRangeValues(8, 18, '10', '14'));
    }

    public function testRangeValuesNeverInvertTheSelection(): void
    {
        self::assertSame(['min' => 15, 'max' => 15], ArchiveFilterControls::resolveRangeValues(8, 18, '15', '10'));
    }

    /**
     * @dataProvider skipperProvider
     * @param array{with:bool,without:bool} $expected
     */
    public function testDecodeSkipperSelection(string $csv, array $expected): void
    {
        self::assertSame($expected, ArchiveFilterControls::decodeSkipperSelection($csv));
    }

    /**
     * @return array<string,array{string,array{with:bool,without:bool}}>
     */
    public static function skipperProvider(): array
    {
        return [
            'no filter'                 => ['', ['with' => false, 'without' => false]],
            'with skipper codes'        => [ArchiveFilterControls::SKIPPER_CODES_WITH, ['with' => true, 'without' => false]],
            'without skipper codes'     => [ArchiveFilterControls::SKIPPER_CODES_WITHOUT, ['with' => false, 'without' => true]],
            'every code'                => ['0,1,2', ['with' => true, 'without' => true]],
            'only optional skipper'     => ['2', ['with' => true, 'without' => true]],
            'single required skipper'   => ['0', ['with' => true, 'without' => false]],
            'single no skipper'         => ['1', ['with' => false, 'without' => true]],
            'garbage'                   => ['x, ,-1', ['with' => false, 'without' => false]],
        ];
    }

    public function testSkipperCodesRoundTripThroughTheDecoder(): void
    {
        self::assertSame(
            ['with' => true, 'without' => false],
            ArchiveFilterControls::decodeSkipperSelection(ArchiveFilterControls::SKIPPER_CODES_WITH)
        );
        self::assertSame(
            ['with' => false, 'without' => true],
            ArchiveFilterControls::decodeSkipperSelection(ArchiveFilterControls::SKIPPER_CODES_WITHOUT)
        );
    }
}

<?php

declare(strict_types=1);

namespace Maradigma\Tests\Unit\Support;

use Maradigma\Support\Utils;
use PHPUnit\Framework\TestCase;

final class UtilsTest extends TestCase
{
    public function testStableQuerySortsKeysAndDropsEmptyValues(): void
    {
        self::assertSame(
            'a=first&space=hello%20world&z=last',
            Utils::buildStableQuery([
                'z' => 'last',
                'empty' => '',
                'space' => 'hello world',
                'null' => null,
                'a' => 'first',
            ])
        );
    }

    public function testJsonDecodeReturnsOnlyAssociativeArrays(): void
    {
        self::assertSame(['boat' => 304], Utils::jsonDecodeAssoc('{"boat":304}'));
        self::assertSame([], Utils::jsonDecodeAssoc('invalid json'));
        self::assertSame([], Utils::jsonDecodeAssoc('null'));
    }

    public function testMoneyFormattingIsDeterministic(): void
    {
        self::assertSame('1.235€', Utils::formatMoney(1234.56));
        self::assertSame('1234.50 USD', Utils::formatMoney(1234.5, 'usd'));
        self::assertSame('1.234,50 €', Utils::formatMoneyAdvanced(1234.5, 'EUR', ['decimals_mode' => '2']));
    }

    public function testLocalizedNumbersCanBeConvertedToFloat(): void
    {
        self::assertSame(1234.5, Utils::toFloatOrNull('1.234,50 €'));
        self::assertSame(12.75, Utils::toFloatOrNull('12,75'));
        self::assertNull(Utils::toFloatOrNull('not a number'));
    }

    public function testPluginVersionReadsTheBootstrapConstant(): void
    {
        self::assertSame('test', Utils::pluginVersion());
    }
}

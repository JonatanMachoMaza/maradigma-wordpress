<?php

declare(strict_types=1);

namespace Maradigma\Tests\Unit\Support;

use Maradigma\Support\BoatPageLanguages;
use PHPUnit\Framework\TestCase;

final class BoatPageLanguagesTest extends TestCase
{
    public function testEmptyChoiceSynchronizesEveryActiveLanguage(): void
    {
        self::assertSame(
            ['es', 'de', 'en', 'fr', 'it'],
            BoatPageLanguages::select(['de', 'en', 'es', 'fr', 'it'], 'es', [])
        );
    }

    public function testChoiceLimitsLanguagesAndKeepsTheSource(): void
    {
        self::assertSame(['es'], BoatPageLanguages::select(['de', 'en', 'es', 'fr', 'it'], 'es', ['es']));
        self::assertSame(['es', 'en'], BoatPageLanguages::select(['de', 'en', 'es'], 'es', ['en']));
    }

    public function testChoiceIgnoresLanguagesTheSiteNoLongerHas(): void
    {
        self::assertSame(['es', 'en'], BoatPageLanguages::select(['en', 'es'], 'es', ['EN', 'pt', '<b>']));
    }

    public function testStoresAnEmptyListWhenEveryLanguageIsChosen(): void
    {
        self::assertSame([], BoatPageLanguages::forStorage(['en', 'es'], 'es', ['en', 'es']));
        self::assertSame([], BoatPageLanguages::forStorage(['es'], 'es', []));
        self::assertSame(['es'], BoatPageLanguages::forStorage(['en', 'es'], 'es', ['es']));
    }

    public function testNormalizeDropsInvalidValues(): void
    {
        self::assertSame(['es', 'pt-br'], BoatPageLanguages::normalize([' ES ', 'pt-br', '', ['x'], 'es', 'not a code']));
        self::assertSame([], BoatPageLanguages::normalize('es'));
    }
}

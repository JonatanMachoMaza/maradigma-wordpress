<?php

declare(strict_types=1);

namespace Maradigma\Tests\Unit;

use Maradigma\SettingsPage;
use PHPUnit\Framework\TestCase;

final class SettingsLocalizedValueTest extends TestCase
{
    public function testPlainValueIsUsedForEveryLanguage(): void
    {
        $value = '{{service_name}} en alquiler, con reserva flexible. Horario: 9:00';

        self::assertSame($value, SettingsPage::pickLocalizedValue($value, 'fr', 'es'));
    }

    public function testValuesMayContainCommas(): void
    {
        $raw = 'es:{{service_name}} en alquiler, con atención personalizada y reserva flexible.,'
            . 'en:Rent the {{service_name}}, with personal service and flexible booking.';

        self::assertSame(
            '{{service_name}} en alquiler, con atención personalizada y reserva flexible.',
            SettingsPage::pickLocalizedValue($raw, 'es', 'en')
        );
        self::assertSame(
            'Rent the {{service_name}}, with personal service and flexible booking.',
            SettingsPage::pickLocalizedValue($raw, 'en', 'es')
        );
    }

    public function testMissingLanguageFallsBack(): void
    {
        $raw = 'es:alquiler-barcos,en:boat-rental';

        self::assertSame('boat-rental', SettingsPage::pickLocalizedValue($raw, 'nl', 'en'));
        self::assertSame('alquiler-barcos', SettingsPage::pickLocalizedValue($raw, 'nl', 'xx'));
        self::assertSame('alquiler-barcos', SettingsPage::pickLocalizedValue('ES: alquiler-barcos , EN: boat-rental', 'es'));
    }
}

<?php

declare(strict_types=1);

namespace Maradigma\Tests\Unit;

use Maradigma\ExternalApiClient;
use PHPUnit\Framework\TestCase;

final class ExternalApiClientTest extends TestCase
{
    public function testAcceptsPluralDestinationsExpansion(): void
    {
        self::assertSame(
            ['service_destination', 'service_destinations'],
            ExternalApiClient::normalizeBoatDetailsExpandOptions([
                'service_destination',
                'service_destinations',
            ])
        );
    }

    public function testBoatDetailsLanguagesBecomeRealLocales(): void
    {
        self::assertSame('en_GB', ExternalApiClient::localeForDetailsLanguage('EN'));
        self::assertSame('es_ES', ExternalApiClient::localeForDetailsLanguage('es'));
        self::assertSame('ca_ES', ExternalApiClient::localeForDetailsLanguage('CA'));
        self::assertSame('es_ES', ExternalApiClient::localeForDetailsLanguage('es_ES'));
        self::assertSame('en-GB', ExternalApiClient::localeForDetailsLanguage('en-GB'));
        self::assertSame('pt', ExternalApiClient::localeForDetailsLanguage('PT'));
    }
}

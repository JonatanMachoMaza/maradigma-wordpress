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
}

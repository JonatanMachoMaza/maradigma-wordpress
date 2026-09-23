<?php

declare(strict_types=1);

namespace Maradigma\Tests\Unit\Support;

use Maradigma\Support\PublicBoatPayload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PublicBoatPayloadTest extends TestCase
{
    public function testItDropsInternalExpandOptions(): void
    {
        self::assertSame(
            ['service_additional_services', 'service_prices', 'service_unavailability_dates'],
            PublicBoatPayload::expandOptions(
                'service_unavailability_dates,service_owner,service_prices,service_accounting,'
                . 'service_admin_tools,service_payment_methods,service_ical,garbage,service_additional_services'
            )
        );
    }

    public function testItSortsAndDeduplicatesExpandOptions(): void
    {
        self::assertSame(
            PublicBoatPayload::expandOptions(['service_prices', 'service_images']),
            PublicBoatPayload::expandOptions(['service_images', 'service_prices', 'SERVICE_PRICES'])
        );
        self::assertSame([], PublicBoatPayload::expandOptions(null));
    }

    public function testItStripsInternalFields(): void
    {
        $projected = PublicBoatPayload::project([
            'id' => 2410,
            'service_name' => 'Pardo Yachts 38',
            'boat_capacity' => 12,
            'images' => [['url' => 'https://example.test/a.jpg']],
            'additional_services' => [],
            'unavailability_dates_and_timeslots' => ['dates' => []],
            'url_ical' => 'https://app.example.test/public.ics',
            'owner' => ['email' => 'owner@example.test', 'tax_id' => 'X'],
            'accounting' => ['commission_collaborator' => 20],
            'admin_tools' => ['edit_url' => 'https://app.example.test/edit'],
            'id_owner' => 7,
            'collab_commission' => 20,
            'ical_url' => 'https://calendar.example.test/private-token/basic.ics',
            'id_default_skipper' => 390,
            'boat_plate_number' => '6ª CO-6-9-26',
            'boat_license' => '562456425',
            'payment_methods' => 'redsys',
        ]);

        self::assertSame(
            ['id', 'service_name', 'boat_capacity', 'images', 'additional_services', 'unavailability_dates_and_timeslots', 'url_ical'],
            array_keys($projected)
        );
    }

    #[DataProvider('languageProvider')]
    public function testItNormalizesTheLanguage(mixed $raw, string $expected): void
    {
        self::assertSame($expected, PublicBoatPayload::language($raw));
    }

    /**
     * @return iterable<string,array{0:mixed,1:string}>
     */
    public static function languageProvider(): iterable
    {
        yield 'slug' => ['ca', 'CA'];
        yield 'upper' => ['ES', 'ES'];
        yield 'locale' => ['es_ES', 'ES'];
        yield 'region tag' => ['pt-br', 'PT'];
        yield 'empty' => ['', 'EN'];
        yield 'one letter' => ['e', 'EN'];
        yield 'symbols only' => ['../', 'EN'];
        yield 'array' => [['es'], 'EN'];
    }
}

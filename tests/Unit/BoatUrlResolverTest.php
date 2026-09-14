<?php

declare(strict_types=1);

namespace Maradigma\Tests\Unit;

use Maradigma\BoatUrlResolver;
use PHPUnit\Framework\TestCase;

final class BoatUrlResolverTest extends TestCase
{
    public function testResolvesLocalizedDestinationInBasePath(): void
    {
        $boat = [
            'destination' => [
                'text' => 'Mallorca',
                'id' => 42,
                'place_type' => 'island',
            ],
        ];

        self::assertSame(
            'alquiler-barcos-mallorca',
            BoatUrlResolver::resolveBasePath('alquiler-barcos-{{destination}}', $boat)
        );
    }

    public function testDestinationAliasAndNestedPathsAreSupported(): void
    {
        $boat = ['destination' => ['text' => 'Ibiza']];

        self::assertSame(
            'charter/ibiza',
            BoatUrlResolver::resolveBasePath('charter/{{destination_slug}}', $boat)
        );
    }

    public function testMissingDestinationFallsBackToStaticBase(): void
    {
        self::assertSame(
            'alquiler-barcos',
            BoatUrlResolver::resolveBasePath('alquiler-barcos-{{destination}}')
        );
    }

    public function testBuildsRewritePatternWithBoatCaptureAfterDestination(): void
    {
        $pattern = BoatUrlResolver::buildRewritePattern('alquiler-barcos-{{destination}}');

        self::assertSame('alquiler\-barcos\-([^/]+)', $pattern['regex']);
        self::assertSame(2, $pattern['boat_match_index']);
    }

    public function testResolvesDestinationAndBoatTypeInNestedBasePath(): void
    {
        $boat = [
            'destination' => ['text' => 'Mallorca'],
            'boat_type' => ['name' => 'Yates', 'slug' => 'yate'],
        ];

        self::assertSame(
            'mallorca/alquiler-yate',
            BoatUrlResolver::resolveBasePath('{{destination}}/alquiler-{{boat_type}}', $boat, 'boats', 'es')
        );
    }

    public function testBuildsRewritePatternWithBoatCaptureAfterBothDynamicSegments(): void
    {
        $pattern = BoatUrlResolver::buildRewritePattern('{{destination}}/alquiler-{{boat_type}}');

        self::assertSame('([^/]+)/alquiler\-([^/]+)', $pattern['regex']);
        self::assertSame(3, $pattern['boat_match_index']);
    }

    public function testBuildsRewriteVariantsWhenDynamicValuesAreMissing(): void
    {
        self::assertSame(
            [
                ['regex' => '([^/]+)/alquiler\-([^/]+)', 'boat_match_index' => 3],
                ['regex' => 'alquiler\-([^/]+)', 'boat_match_index' => 2],
                ['regex' => '([^/]+)/alquiler', 'boat_match_index' => 2],
                ['regex' => 'alquiler', 'boat_match_index' => 1],
            ],
            BoatUrlResolver::buildRewritePatterns('{{destination}}/alquiler-{{boat_type}}')
        );
    }

    public function testBoatTypeAliasIsSupported(): void
    {
        $boat = ['boat_type_slug' => 'lancha'];

        self::assertSame(
            'ibiza/alquiler-lancha',
            BoatUrlResolver::resolveBasePath('ibiza/alquiler-{{boat_type_slug}}', $boat, 'boats', 'es')
        );
    }

    public function testCatalogEnrichesStandardSpanishBoatTypeWithSingularRouteSlug(): void
    {
        BoatUrlResolver::storeBoatTypeCatalog('es', [
            ['id' => 2, 'name' => 'Yates', 'slug' => 'yachts'],
            ['id' => 3, 'name' => 'Lanchas', 'slug' => 'motorboats'],
        ]);

        $yacht = BoatUrlResolver::enrichBoatType(['id_group_content_type' => 2], 'es');
        $motorboat = BoatUrlResolver::enrichBoatType(['id_group_content_type' => 3], 'es');

        self::assertSame('yate', $yacht['boat_type_slug']);
        self::assertSame('lancha', $motorboat['boat_type_slug']);
    }

    public function testCatalogEnrichmentUsesListPayloadWhenDetailOmitsBoatType(): void
    {
        BoatUrlResolver::storeBoatTypeCatalog('es', [
            ['id' => 2, 'name' => 'Yates', 'slug' => 'yachts'],
        ]);

        $boat = BoatUrlResolver::enrichBoatType(
            ['id' => 2410, 'service_name' => 'Pardo Yachts 38 TEST'],
            'es',
            ['id' => 2410, 'id_group_content_type' => 2]
        );

        self::assertSame(2, $boat['id_group_content_type']);
        self::assertSame('Yates', $boat['boat_type_name']);
        self::assertSame('yate', $boat['boat_type_slug']);
    }

    public function testReadsDestinationFallbackFields(): void
    {
        $boat = [
            'destination_name' => 'Formentera',
            'destination_slug' => 'formentera-island',
        ];

        self::assertSame('Formentera', BoatUrlResolver::getDestinationName($boat));
        self::assertSame('formentera-island', BoatUrlResolver::getDestinationSlug($boat));
    }

    public function testPrefersIslandFromDestinationCatalogForSeoRoutes(): void
    {
        $boat = [
            'destination' => [
                'text' => 'Palma',
                'place_type' => 'locality',
            ],
            'destinations' => [
                [
                    'text' => 'Palma',
                    'place_type' => 'locality',
                    'priority' => 10,
                ],
                [
                    'text' => 'Mallorca',
                    'place_type' => 'island',
                    'priority' => 20,
                ],
                [
                    'text' => 'Balearic Islands',
                    'place_type' => 'archipelago',
                    'priority' => 30,
                ],
            ],
        ];

        self::assertSame('Mallorca', BoatUrlResolver::getDestinationName($boat));
        self::assertSame('mallorca', BoatUrlResolver::getDestinationSlug($boat));
        self::assertSame(
            'mallorca/alquiler-yate',
            BoatUrlResolver::resolveBasePath(
                '{{destination}}/alquiler-{{boat_type}}',
                $boat + ['boat_type_slug' => 'yate'],
                'boats',
                'es'
            )
        );
    }

    public function testUsesLocalityWhenDestinationCatalogHasNoIsland(): void
    {
        $boat = [
            'destination' => ['text' => 'Port Olimpic'],
            'destinations' => [
                ['text' => 'Catalonia', 'place_type' => 'administrative_area'],
                ['text' => 'Barcelona', 'place_type' => 'locality'],
                ['text' => 'Port Olimpic', 'place_type' => 'port'],
            ],
        ];

        self::assertSame('Barcelona', BoatUrlResolver::getDestinationName($boat));
        self::assertSame('barcelona', BoatUrlResolver::getDestinationSlug($boat));
    }

    public function testDetectsLegacyFlattenedMultilingualProductSlug(): void
    {
        self::assertTrue(
            BoatUrlResolver::isMalformedLegacyBaseSlug('esproductoenproductdeproduktcaproductee')
        );
        self::assertTrue(
            BoatUrlResolver::isMalformedLegacyBaseSlug('esproductoenproductdeproduktcaproducte')
        );
    }

    public function testDoesNotRejectValidBoatBaseConfigurations(): void
    {
        self::assertFalse(BoatUrlResolver::isMalformedLegacyBaseSlug('boats'));
        self::assertFalse(BoatUrlResolver::isMalformedLegacyBaseSlug('alquiler-barcos-mallorca'));
        self::assertFalse(
            BoatUrlResolver::isMalformedLegacyBaseSlug(
                'es:alquiler-barcos-{{destination}},en:boat-rental-{{destination}}'
            )
        );
    }

    public function testMalformedLegacyBaseFallsBackDuringNormalization(): void
    {
        self::assertSame(
            'boats',
            BoatUrlResolver::normalizeBaseTemplate('esproductoenproductdeproduktcaproductee')
        );
    }
}

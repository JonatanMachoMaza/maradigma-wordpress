<?php

declare(strict_types=1);

namespace Maradigma\Tests\Unit;

use Maradigma\BoatUrlResolver;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testBuildsStrictDynamicAndSafeFallbackRewritePatterns(): void
    {
        self::assertSame(
            [
                ['regex' => '([^/]+)/alquiler\-([^/]+)', 'boat_match_index' => 3],
                ['regex' => 'boats', 'boat_match_index' => 1],
            ],
            BoatUrlResolver::buildRewritePatterns('{{destination}}/alquiler-{{boat_type}}')
        );
    }

    public function testBoatWithMissingDynamicValueUsesSafeFallbackBase(): void
    {
        self::assertSame(
            'boats',
            BoatUrlResolver::resolveBasePath(
                'alquiler-de-barcos/{{destination}}/{{boat_type}}',
                ['destination' => ['text' => 'Ibiza']],
                'boats',
                'es',
                true
            )
        );
    }

    public function testDynamicRewritePatternsDoNotInterceptStaticAncestorPages(): void
    {
        $patterns = BoatUrlResolver::buildRewritePatterns(
            'alquiler-de-barcos/{{destination}}/{{boat_type}}'
        );

        self::assertFalse(self::matchesRewritePatterns(
            $patterns,
            'alquiler-de-barcos/ibiza'
        ));
        self::assertFalse(self::matchesRewritePatterns(
            $patterns,
            'alquiler-de-barcos/ibiza/lancha'
        ));
        self::assertTrue(self::matchesRewritePatterns(
            $patterns,
            'alquiler-de-barcos/ibiza/lancha/marlin-rib-38'
        ));
        self::assertTrue(self::matchesRewritePatterns(
            $patterns,
            'alquiler-de-barcos/ibiza/lancha/marlin-rib-38/'
        ));
        self::assertFalse(self::matchesRewritePatterns(
            $patterns,
            'alquiler-de-barcos/ibiza/lancha/marlin-rib-38/extra'
        ));
        self::assertTrue(self::matchesRewritePatterns($patterns, 'boats/marlin-rib-38'));
    }

    /**
     * @param list<string> $staticPaths
     * @param list<string> $boatPaths
     * @param list<string> $invalidPaths
     */
    #[DataProvider('strictRewriteRouteProvider')]
    public function testRewriteMatrixOnlyMatchesCompleteBoatRoutes(
        string $template,
        string $fallback,
        array $staticPaths,
        array $boatPaths,
        array $invalidPaths
    ): void {
        $patterns = BoatUrlResolver::buildRewritePatterns($template, $fallback);

        foreach ($staticPaths as $path) {
            self::assertFalse(
                self::matchesRewritePatterns($patterns, $path),
                sprintf('Static path must not be intercepted: %s', $path)
            );
        }

        foreach ($boatPaths as $path) {
            self::assertTrue(
                self::matchesRewritePatterns($patterns, $path),
                sprintf('Complete boat path must resolve: %s', $path)
            );
        }

        foreach ($invalidPaths as $path) {
            self::assertFalse(
                self::matchesRewritePatterns($patterns, $path),
                sprintf('Invalid path must not resolve: %s', $path)
            );
        }
    }

    /**
     * @return iterable<string,array{string,string,list<string>,list<string>,list<string>}>
     */
    public static function strictRewriteRouteProvider(): iterable
    {
        yield 'client destination and type hierarchy' => [
            'alquiler-de-barcos/{{destination}}/{{boat_type}}',
            'boats',
            ['alquiler-de-barcos', 'alquiler-de-barcos/ibiza', 'alquiler-de-barcos/ibiza/lancha'],
            [
                'alquiler-de-barcos/ibiza/lancha/marlin-rib-38',
                'alquiler-de-barcos/ibiza/lancha/marlin-rib-38/',
                'boats/marlin-rib-38',
            ],
            [
                'alquiler-de-barcos//lancha/marlin-rib-38',
                'alquiler-de-barcos/ibiza//marlin-rib-38',
                'alquiler-de-barcos/ibiza/lancha/marlin-rib-38/extra',
            ],
        ];

        yield 'existing inline rental type hierarchy' => [
            '{{destination}}/alquiler-{{boat_type}}',
            'boats',
            ['ibiza', 'ibiza/alquiler-lancha'],
            ['ibiza/alquiler-lancha/marlin-rib-38', 'boats/marlin-rib-38/'],
            ['ibiza/alquiler-/marlin-rib-38', 'ibiza/alquiler-lancha/marlin-rib-38/extra'],
        ];

        yield 'destination alias hierarchy' => [
            'charter/{{destination_slug}}',
            'fleet',
            ['charter', 'charter/ibiza'],
            ['charter/ibiza/boat-one', 'fleet/boat-one'],
            ['charter//boat-one', 'fleet/boat-one/extra'],
        ];

        yield 'boat type alias hierarchy' => [
            'rental/{{boat_type_slug}}',
            'fleet',
            ['rental', 'rental/yacht'],
            ['rental/yacht/boat-one', 'fleet/boat-one/'],
            ['rental//boat-one', 'rental/yacht/boat-one/extra'],
        ];

        yield 'two placeholders in one segment' => [
            'alquiler-{{boat_type}}-en-{{destination}}',
            'boats',
            ['alquiler-lancha-en-ibiza'],
            ['alquiler-lancha-en-ibiza/boat-one', 'boats/boat-one'],
            ['alquiler--en-ibiza/boat-one', 'alquiler-lancha-en-/boat-one'],
        ];

        yield 'static route without placeholders' => [
            'boats',
            'boats',
            ['boats'],
            ['boats/boat-one', 'boats/boat-one/'],
            ['boats/boat-one/extra', 'boats//'],
        ];
    }

    /**
     * @param array<string,mixed> $boat
     */
    #[DataProvider('requiredDynamicValuesProvider')]
    public function testRequiredDynamicValuesUseAnUnambiguousFallback(
        string $template,
        array $boat,
        string $language,
        string $fallback,
        string $expected
    ): void {
        self::assertSame(
            $expected,
            BoatUrlResolver::resolveBasePath($template, $boat, $fallback, $language, true)
        );
    }

    /**
     * @return iterable<string,array{string,array<string,mixed>,string,string,string}>
     */
    public static function requiredDynamicValuesProvider(): iterable
    {
        yield 'complete Spanish hierarchy' => [
            'alquiler-de-barcos/{{destination}}/{{boat_type}}',
            ['destination_slug' => 'ibiza', 'boat_type_slug' => 'lancha'],
            'es',
            'boats',
            'alquiler-de-barcos/ibiza/lancha',
        ];

        yield 'missing destination' => [
            'alquiler-de-barcos/{{destination}}/{{boat_type}}',
            ['boat_type_slug' => 'lancha'],
            'es',
            'boats',
            'boats',
        ];

        yield 'missing boat type' => [
            'alquiler-de-barcos/{{destination}}/{{boat_type}}',
            ['destination_slug' => 'ibiza'],
            'es',
            'boats',
            'boats',
        ];

        yield 'both values missing with custom fallback' => [
            '{{destination}}/charter/{{boat_type}}',
            [],
            'en',
            'fleet',
            'fleet',
        ];

        yield 'static template needs no payload' => [
            'boat-rental',
            [],
            'en',
            'boats',
            'boat-rental',
        ];

        yield 'aliases resolve normally' => [
            '{{destination_slug}}/rent-{{boat_type_slug}}',
            ['destination_name' => 'Palma', 'boat_type_slug' => 'yacht'],
            'en',
            'boats',
            'palma/rent-yacht',
        ];
    }

    public function testSinglePlaceholderRewriteDoesNotInterceptItsStaticParent(): void
    {
        $patterns = BoatUrlResolver::buildRewritePatterns(
            'alquiler-de-barcos/{{destination}}'
        );

        self::assertSame(
            [
                ['regex' => 'alquiler\-de\-barcos/([^/]+)', 'boat_match_index' => 2],
                ['regex' => 'boats', 'boat_match_index' => 1],
            ],
            $patterns
        );
        self::assertSame(0, preg_match(
            '#^' . $patterns[0]['regex'] . '/([^/]+)/?$#',
            'alquiler-de-barcos/ibiza'
        ));
        self::assertSame(1, preg_match(
            '#^' . $patterns[0]['regex'] . '/([^/]+)/?$#',
            'alquiler-de-barcos/ibiza/marlin-rib-38'
        ));
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

    /**
     * @param array<int,array{regex:string,boat_match_index:int}> $patterns
     */
    private static function matchesRewritePatterns(array $patterns, string $path): bool
    {
        foreach ($patterns as $pattern) {
            $rule = '#^' . $pattern['regex'] . '/([^/]+)/?$#';
            if (preg_match($rule, $path) === 1) {
                return true;
            }
        }

        return false;
    }
}

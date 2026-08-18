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

    public function testReadsDestinationFallbackFields(): void
    {
        $boat = [
            'destination_name' => 'Formentera',
            'destination_slug' => 'formentera-island',
        ];

        self::assertSame('Formentera', BoatUrlResolver::getDestinationName($boat));
        self::assertSame('formentera-island', BoatUrlResolver::getDestinationSlug($boat));
    }
}

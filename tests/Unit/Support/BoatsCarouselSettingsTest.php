<?php

declare(strict_types=1);

namespace Maradigma\Tests\Unit\Support;

use Maradigma\Support\BoatsCarouselSettings;
use PHPUnit\Framework\TestCase;

final class BoatsCarouselSettingsTest extends TestCase
{
    public function testDefaultsLoadTwoDesktopViewsPerRequest(): void
    {
        $settings = BoatsCarouselSettings::fromAtts([]);

        self::assertSame(['mobile' => 1.2, 'tablet' => 2.5, 'desktop' => 4.0], $settings['per_view']);
        self::assertSame(16, $settings['gap']);
        self::assertSame(8, $settings['batch']);
        self::assertSame(0, $settings['max_boats']);
        self::assertTrue($settings['navigation']);
        self::assertFalse($settings['bleed']);
        self::assertSame('', $settings['label']);
        self::assertSame('', $settings['class']);
    }

    public function testBleedIsOptIn(): void
    {
        foreach (['1', 'true', 'yes', 'ON'] as $on) {
            self::assertTrue(BoatsCarouselSettings::fromAtts(['bleed' => $on])['bleed']);
        }

        foreach (['0', '', 'full', 'no'] as $off) {
            self::assertFalse(BoatsCarouselSettings::fromAtts(['bleed' => $off])['bleed']);
        }

        self::assertArrayNotHasKey('bleed', BoatsCarouselSettings::searchAtts(['bleed' => '1'], 8));
    }

    public function testCardsPerViewAcceptDecimalsAndStayInRange(): void
    {
        $settings = BoatsCarouselSettings::fromAtts([
            'slides_mobile'  => '1,25',
            'slides_tablet'  => '0.5',
            'slides_desktop' => '12',
        ]);

        self::assertSame(['mobile' => 1.25, 'tablet' => 1.0, 'desktop' => 8.0], $settings['per_view']);
        self::assertSame(16, $settings['batch']);

        $invalid = BoatsCarouselSettings::fromAtts(['slides_desktop' => 'four', 'slides_mobile' => '']);
        self::assertSame(4.0, $invalid['per_view']['desktop']);
        self::assertSame(1.2, $invalid['per_view']['mobile']);
    }

    public function testTheBatchFollowsTheDesktopLayoutAndNeverExceedsTheMaximum(): void
    {
        self::assertSame(4, BoatsCarouselSettings::fromAtts(['slides_desktop' => '1'])['batch']);
        self::assertSame(6, BoatsCarouselSettings::fromAtts(['slides_desktop' => '2.5'])['batch']);
        self::assertSame(5, BoatsCarouselSettings::fromAtts(['batch' => '5'])['batch']);
        self::assertSame(24, BoatsCarouselSettings::fromAtts(['batch' => '500'])['batch']);
        self::assertSame(8, BoatsCarouselSettings::fromAtts(['batch' => '-3'])['batch']);

        $capped = BoatsCarouselSettings::fromAtts(['max_boats' => '6']);
        self::assertSame(6, $capped['max_boats']);
        self::assertSame(6, $capped['batch']);
        self::assertSame(200, BoatsCarouselSettings::fromAtts(['max_boats' => '999'])['max_boats']);
    }

    public function testNavigationLabelAndClassesAreNormalized(): void
    {
        foreach (['0', 'false', 'no', 'OFF'] as $off) {
            self::assertFalse(BoatsCarouselSettings::fromAtts(['navigation' => $off])['navigation']);
        }

        $settings = BoatsCarouselSettings::fromAtts([
            'label'         => ' <b>Featured</b> boats ',
            'class'         => 'home-boats  home-boats "><script> is-dark',
            'empty_message' => 'No boats yet',
            'gap'           => '100',
        ]);

        self::assertSame('Featured boats', $settings['label']);
        self::assertSame('home-boats script is-dark', $settings['class']);
        self::assertSame('No boats yet', $settings['empty_message']);
        self::assertSame(64, $settings['gap']);
    }

    public function testTheSearchKeepsTheSelectionButDropsTheCarouselSettings(): void
    {
        $search = BoatsCarouselSettings::searchAtts([
            'portfolio'      => '12345678-1234-4321-8123-000000000012',
            'featured'       => '1',
            'card'           => 'external',
            'slides_desktop' => '4',
            'batch'          => '8',
            'class'          => 'home-boats',
            'show_filters'   => '1',
            'offset_services' => '20',
        ], 8);

        self::assertSame('12345678-1234-4321-8123-000000000012', $search['portfolio']);
        self::assertSame('1', $search['featured']);
        self::assertSame('external', $search['card']);
        self::assertArrayNotHasKey('slides_desktop', $search);
        self::assertArrayNotHasKey('batch', $search);
        self::assertArrayNotHasKey('class', $search);
        self::assertSame('0', $search['show_filters']);
        self::assertSame('0', $search['allow_url_filters']);
        self::assertSame('8', $search['limit_services']);
        self::assertSame('0', $search['offset_services']);
        self::assertSame('0', $search['order_by']);

        self::assertSame('2', BoatsCarouselSettings::searchAtts(['order_by' => '2'], 8)['order_by']);
    }

    public function testTheLayoutBecomesCssCustomProperties(): void
    {
        $settings = BoatsCarouselSettings::fromAtts(['slides_mobile' => '1.25', 'slides_tablet' => '3', 'gap' => '8']);

        self::assertSame(
            '--md-carousel-per-view-mobile:1.25;--md-carousel-per-view-tablet:3;--md-carousel-per-view-desktop:4;--md-carousel-gap:8px',
            BoatsCarouselSettings::styleVariables($settings)
        );
    }
}

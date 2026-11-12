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
        self::assertNull($settings['gap_mobile']);
        self::assertNull($settings['gap_tablet']);
        self::assertSame(8, $settings['batch']);
        self::assertSame(0, $settings['max_boats']);
        self::assertTrue($settings['navigation']);
        self::assertSame('circle', $settings['arrows']);
        self::assertSame('', $settings['bleed']);
        self::assertSame(0, $settings['max_width']);
        self::assertSame(40, $settings['shadow_space']);
        self::assertSame('', $settings['label']);
        self::assertSame('', $settings['class']);
        self::assertSame('', $settings['track_class']);
        self::assertSame('', $settings['slide_class']);
    }

    public function testMaxWidthRoomForShadowsAndListClassesAreNormalized(): void
    {
        $settings = BoatsCarouselSettings::fromAtts([
            'max_width'    => '1776',
            'shadow_space' => '84',
            'track_class'  => 'product-cards-list "><script>',
            'slide_class'  => ' maradigma-boats  maradigma-boats ',
        ]);

        self::assertSame(1776, $settings['max_width']);
        self::assertSame(84, $settings['shadow_space']);
        self::assertSame('product-cards-list script', $settings['track_class']);
        self::assertSame('maradigma-boats', $settings['slide_class']);

        self::assertSame(4000, BoatsCarouselSettings::fromAtts(['max_width' => '99999'])['max_width']);
        self::assertSame(0, BoatsCarouselSettings::fromAtts(['max_width' => 'wide'])['max_width']);
        self::assertSame(200, BoatsCarouselSettings::fromAtts(['shadow_space' => '500'])['shadow_space']);
        self::assertSame(0, BoatsCarouselSettings::fromAtts(['shadow_space' => '0'])['shadow_space']);
        self::assertSame(40, BoatsCarouselSettings::fromAtts(['shadow_space' => '-5'])['shadow_space']);

        $search = BoatsCarouselSettings::searchAtts([
            'max_width'    => '1776',
            'shadow_space' => '84',
            'track_class'  => 'product-cards-list',
            'slide_class'  => 'maradigma-boats',
        ], 8);
        foreach (['max_width', 'shadow_space', 'track_class', 'slide_class'] as $attribute) {
            self::assertArrayNotHasKey($attribute, $search);
        }
    }

    public function testPhonesAndTabletsCanHaveTheirOwnGapAndTheArrowsCanBeChevrons(): void
    {
        $settings = BoatsCarouselSettings::fromAtts(['gap_mobile' => '8', 'gap_tablet' => ' 0 ', 'arrows' => ' Chevron ']);

        self::assertSame(8, $settings['gap_mobile']);
        self::assertSame(0, $settings['gap_tablet']);
        self::assertSame(16, $settings['gap']);
        self::assertSame('chevron', $settings['arrows']);

        self::assertSame(64, BoatsCarouselSettings::fromAtts(['gap_mobile' => '90'])['gap_mobile']);
        foreach (['', 'wide', '-4', '1.5'] as $invalid) {
            self::assertNull(BoatsCarouselSettings::fromAtts(['gap_mobile' => $invalid])['gap_mobile']);
        }
        self::assertNull(BoatsCarouselSettings::fromAtts(['gap_tablet' => ['8']])['gap_tablet']);

        foreach (['', 'circle', 'arrows', '1'] as $circle) {
            self::assertSame('circle', BoatsCarouselSettings::fromAtts(['arrows' => $circle])['arrows']);
        }

        $search = BoatsCarouselSettings::searchAtts(['gap_mobile' => '8', 'gap_tablet' => '8', 'arrows' => 'chevron'], 8);
        foreach (['gap_mobile', 'gap_tablet', 'arrows'] as $attribute) {
            self::assertArrayNotHasKey($attribute, $search);
        }
    }

    public function testBleedIsOptInAndCanTakeTheWholeScreen(): void
    {
        foreach (['1', 'true', 'yes', 'ON'] as $edges) {
            self::assertSame('edges', BoatsCarouselSettings::fromAtts(['bleed' => $edges])['bleed']);
        }

        foreach (['full', ' FULL '] as $full) {
            self::assertSame('full', BoatsCarouselSettings::fromAtts(['bleed' => $full])['bleed']);
        }

        foreach (['0', '', 'no', 'wide'] as $off) {
            self::assertSame('', BoatsCarouselSettings::fromAtts(['bleed' => $off])['bleed']);
        }

        self::assertSame('', BoatsCarouselSettings::fromAtts(['bleed' => ['full']])['bleed']);
        self::assertArrayNotHasKey('bleed', BoatsCarouselSettings::searchAtts(['bleed' => 'full'], 8));
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

        // The room for shadows and the maximum width are only written when set.
        $full = BoatsCarouselSettings::fromAtts(['bleed' => 'full', 'max_width' => '1776', 'shadow_space' => '84']);
        self::assertSame(
            '--md-carousel-per-view-mobile:1.2;--md-carousel-per-view-tablet:2.5;--md-carousel-per-view-desktop:4;--md-carousel-gap:16px'
            . ';--md-carousel-shadow-space:84px;--md-carousel-max-width:1776px',
            BoatsCarouselSettings::styleVariables($full)
        );

        // Phones and tablets only get a gap of their own when it is set.
        $gaps = BoatsCarouselSettings::fromAtts(['gap_mobile' => '8', 'gap_tablet' => '0']);
        self::assertSame(
            '--md-carousel-per-view-mobile:1.2;--md-carousel-per-view-tablet:2.5;--md-carousel-per-view-desktop:4;--md-carousel-gap:16px'
            . ';--md-carousel-gap-mobile:8px;--md-carousel-gap-tablet:0px',
            BoatsCarouselSettings::styleVariables($gaps)
        );
    }
}

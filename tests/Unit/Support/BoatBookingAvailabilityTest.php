<?php

declare(strict_types=1);

namespace Maradigma\Tests\Unit\Support;

use Maradigma\Support\BoatBookingAvailability;
use PHPUnit\Framework\TestCase;

final class BoatBookingAvailabilityTest extends TestCase
{
    public function testTheApiAnswerOfTheBoatDetailWins(): void
    {
        self::assertTrue(BoatBookingAvailability::hasRentOnline(['booking' => ['has_rent_online' => true], 'is_owner' => 0]));
        self::assertFalse(BoatBookingAvailability::hasRentOnline(['booking' => ['has_rent_online' => false], 'is_owner' => 1]));
    }

    public function testAnOwnBoatAcceptsOnlineBookingWithoutInstantBookingFlag(): void
    {
        self::assertTrue(BoatBookingAvailability::hasRentOnline(['is_owner' => 1, 'ins_book' => 0]));
    }

    public function testASharedBoatOfAnotherMemberNeedsInstantBooking(): void
    {
        self::assertTrue(BoatBookingAvailability::hasRentOnline(['is_owner' => 0, 'is_tenant_member' => true, 'ins_book' => 1]));
        self::assertFalse(BoatBookingAvailability::hasRentOnline(['is_owner' => 0, 'is_tenant_member' => true, 'ins_book' => 0]));
    }

    public function testABoatOfAnExternalOwnerNeedsAnIcalCalendar(): void
    {
        self::assertFalse(BoatBookingAvailability::hasRentOnline(['is_owner' => 0, 'id_owner' => 586, 'ins_book' => 1]));
        self::assertTrue(BoatBookingAvailability::hasRentOnline([
            'is_owner' => 0,
            'id_owner' => 586,
            'url_ical' => 'https://app.maradigma.com/api/public/ical/2/1/calendar.ics',
        ]));
    }

    public function testAnUnknownBoatDoesNotAcceptOnlineBooking(): void
    {
        self::assertFalse(BoatBookingAvailability::hasRentOnline([]));
    }

    public function testABoatOfAnExternalOwnerMarkedOnlineWithoutIcalIsNotBookable(): void
    {
        $boat = ['is_owner' => 0, 'id_owner' => 586, 'booking' => ['has_rent_online' => true]];

        self::assertFalse(BoatBookingAvailability::hasRentOnline($boat));
        self::assertFalse(BoatBookingAvailability::canRenderBooking($boat));
    }

    public function testABoatOfAnExternalOwnerWithIcalIsBookableWhenMarkedOnline(): void
    {
        $boat = [
            'is_owner' => 0,
            'id_owner' => 586,
            'url_ical' => 'https://app.maradigma.com/api/public/ical/2/1/calendar.ics',
            'booking'  => ['has_rent_online' => true],
        ];

        self::assertTrue(BoatBookingAvailability::canRenderBooking($boat));

        $boat['booking']['has_rent_online'] = false;
        self::assertFalse(BoatBookingAvailability::canRenderBooking($boat));
    }

    public function testABoatOfAnExternalOwnerOnlyShowsItsCalendarWithIcal(): void
    {
        $boat = ['is_owner' => 0, 'id_owner' => 586, 'booking' => ['has_rent_online' => false]];
        self::assertFalse(BoatBookingAvailability::canRenderCalendar($boat));

        // Showing availability does not depend on whether the boat can be booked.
        $boat['url_ical'] = 'https://app.maradigma.com/api/public/ical/2/1/calendar.ics';
        self::assertTrue(BoatBookingAvailability::canRenderCalendar($boat));
    }

    public function testOwnAndSharedBoatsAlwaysShowTheirCalendar(): void
    {
        self::assertTrue(BoatBookingAvailability::canRenderCalendar(['is_owner' => 1]));
        self::assertTrue(BoatBookingAvailability::canRenderCalendar(['is_owner' => 0, 'is_tenant_member' => true]));
        self::assertFalse(BoatBookingAvailability::canRenderCalendar([]));
    }
}

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
}

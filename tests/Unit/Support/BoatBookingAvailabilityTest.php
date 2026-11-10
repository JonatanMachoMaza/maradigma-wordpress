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

    public function testABoatOfAnExternalOwnerWithIcalIsBookableWithInstantBookingWhenMarkedOnline(): void
    {
        $boat = [
            'is_owner' => 0,
            'id_owner' => 586,
            'ins_book' => 1,
            'url_ical' => 'https://app.maradigma.com/api/public/ical/2/1/calendar.ics',
            'booking'  => ['has_rent_online' => true],
        ];

        self::assertTrue(BoatBookingAvailability::canRenderBooking($boat));

        $boat['booking']['has_rent_online'] = false;
        self::assertFalse(BoatBookingAvailability::canRenderBooking($boat));
    }

    public function testABoatOfAnExternalOwnerWithoutInstantBookingIsOnlyRequestedEvenWithIcal(): void
    {
        // The API marks it as rentable online because it has an iCal feed.
        $boat = [
            'is_owner' => 0,
            'id_owner' => 586,
            'ins_book' => 0,
            'ical_url' => 'https://calendar.example.com/boat.ics',
            'booking'  => ['has_rent_online' => true],
        ];

        self::assertFalse(BoatBookingAvailability::canRenderBooking($boat));
        self::assertTrue(BoatBookingAvailability::canRenderCalendar($boat));

        unset($boat['ins_book']);
        self::assertFalse(BoatBookingAvailability::canRenderBooking($boat));
    }

    public function testASearchRowOfAnExternalOwnerFollowsItsInstantBookingSetting(): void
    {
        // Search rows carry no booking node.
        $row = [
            'is_owner' => 0,
            'id_owner' => 586,
            'ins_book' => 1,
            'ical_url' => 'https://calendar.example.com/boat.ics',
        ];

        self::assertTrue(BoatBookingAvailability::canRenderBooking($row));

        $row['ins_book'] = 0;
        self::assertFalse(BoatBookingAvailability::canRenderBooking($row));

        $row['ins_book'] = 1;
        unset($row['ical_url']);
        self::assertFalse(BoatBookingAvailability::canRenderBooking($row));
    }

    public function testABoatOfAnExternalOwnerOnlyShowsItsCalendarWithIcal(): void
    {
        $boat = ['is_owner' => 0, 'id_owner' => 586, 'booking' => ['has_rent_online' => false]];
        self::assertFalse(BoatBookingAvailability::canRenderCalendar($boat));

        // Showing availability does not depend on whether the boat can be booked.
        $boat['url_ical'] = 'https://app.maradigma.com/api/public/ical/2/1/calendar.ics';
        self::assertTrue(BoatBookingAvailability::canRenderCalendar($boat));
    }

    public function testABookingRequestIsAcceptedOnlyForABookableBoat(): void
    {
        $boats = [
            '214' => ['is_owner' => 0, 'is_tenant_member' => true, 'ins_book' => 1],
            '2162' => [
                'is_owner' => 0,
                'id_owner' => 586,
                'ins_book' => 0,
                'ical_url' => 'https://calendar.example.com/boat.ics',
                'booking'  => ['has_rent_online' => true],
            ],
        ];
        $requested = [];
        $loadBoat = static function (string $boatId) use ($boats, &$requested): ?array {
            $requested[] = $boatId;
            return $boats[$boatId] ?? null;
        };

        self::assertTrue(BoatBookingAvailability::acceptsBookingRequest(' 214 ', $loadBoat));
        self::assertFalse(BoatBookingAvailability::acceptsBookingRequest('2162', $loadBoat));
        self::assertFalse(BoatBookingAvailability::acceptsBookingRequest('999', $loadBoat));
        self::assertSame(['214', '2162', '999'], $requested);
    }

    public function testABookingRequestWithoutAValidBoatIdNeverLoadsABoat(): void
    {
        $loadBoat = static function (string $boatId): ?array {
            throw new \LogicException('The boat must not be loaded for ' . $boatId);
        };

        foreach (['', '0', '-3', 'abc', '12abc', '1.5'] as $boatId) {
            self::assertFalse(BoatBookingAvailability::acceptsBookingRequest($boatId, $loadBoat));
        }
    }

    public function testOwnAndSharedBoatsAlwaysShowTheirCalendar(): void
    {
        self::assertTrue(BoatBookingAvailability::canRenderCalendar(['is_owner' => 1]));
        self::assertTrue(BoatBookingAvailability::canRenderCalendar(['is_owner' => 0, 'is_tenant_member' => true]));
        self::assertFalse(BoatBookingAvailability::canRenderCalendar([]));
    }
}

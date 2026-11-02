<?php

declare(strict_types=1);

namespace Maradigma\Support;

/**
 * Evaluates whether booking and calendar interfaces can be shown for a boat.
 *
 * Booking and showing availability are separate decisions: a boat of an external
 * owner only shows its calendar when it has an iCal feed, and is only bookable when
 * it is also marked as rentable online.
 */
final class BoatBookingAvailability
{
    /** @param array<string,mixed> $boat */
    public static function canRenderBooking(array $boat): bool
    {
        if (self::isTenantMember($boat)) {
            return self::isInstantBookingEnabled($boat);
        }

        if (self::hasExternalOwnerId($boat)) {
            return self::hasRentOnline($boat);
        }

        return self::isOwnBoat($boat) && self::isInstantBookingEnabled($boat);
    }

    /**
     * Whether the Maradigma API accepts online bookings for the boat.
     *
     * The boat detail carries the API's own answer in booking.has_rent_online. Search
     * rows (the ones boat cards are built from) do not, so the same rule is applied to
     * the fields they do carry: an own boat always accepts, a boat of an external owner
     * needs an iCal calendar, and a shared boat of another member needs instant booking.
     *
     * A boat of an external owner without an iCal feed never accepts online bookings,
     * even when the API marks it as rentable online: its availability cannot be known.
     *
     * @param array<string,mixed> $boat
     */
    public static function hasRentOnline(array $boat): bool
    {
        if (self::hasExternalOwnerId($boat) && !self::hasValidIcalUrl($boat)) {
            return false;
        }

        $booking = $boat['booking'] ?? null;

        if (is_array($booking) && array_key_exists('has_rent_online', $booking)) {
            return self::toBool($booking['has_rent_online']);
        }

        if (self::hasExternalOwnerId($boat)) {
            return true;
        }

        if (self::isOwnBoat($boat)) {
            return true;
        }

        return self::isTenantMember($boat) && self::isInstantBookingEnabled($boat);
    }

    /** @param array<string,mixed> $boat */
    public static function canRenderCalendar(array $boat): bool
    {
        if (self::isTenantMember($boat)) {
            return true;
        }

        if (self::isOwnBoat($boat)) {
            return true;
        }

        return self::hasExternalOwnerId($boat) && self::hasValidIcalUrl($boat);
    }

    /** @param array<string,mixed> $boat */
    private static function isOwnBoat(array $boat): bool
    {
        return self::toBool($boat['is_owner'] ?? null);
    }

    /** @param array<string,mixed> $boat */
    private static function isInstantBookingEnabled(array $boat): bool
    {
        return self::toBool($boat['ins_book'] ?? null);
    }

    /** @param array<string,mixed> $boat */
    private static function isTenantMember(array $boat): bool
    {
        if (array_key_exists('is_tenant_member', $boat)) {
            return self::toBool($boat['is_tenant_member']);
        }

        $ownership = $boat['ownership'] ?? null;

        if (!is_array($ownership)) {
            return false;
        }

        return self::toBool($ownership['is_tenant_member'] ?? null);
    }

    /** @param array<string,mixed> $boat */
    private static function hasExternalOwnerId(array $boat): bool
    {
        if (!array_key_exists('id_owner', $boat)) {
            return false;
        }

        $ownerId = $boat['id_owner'];

        if (is_int($ownerId)) {
            return $ownerId > 0;
        }

        if (is_string($ownerId)) {
            $ownerId = trim($ownerId);
            return $ownerId !== '' && ctype_digit($ownerId) && (int) $ownerId > 0;
        }

        return false;
    }

    /** @param array<string,mixed> $boat */
    private static function hasValidIcalUrl(array $boat): bool
    {
        $url = trim((string) ($boat['ical_url'] ?? ''));
        if ($url === '') {
            $url = trim((string) ($boat['url_ical'] ?? ''));
        }

        return $url !== '' && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /** @param mixed $value */
    private static function toBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            $value = strtolower(trim($value));
            return in_array($value, ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }
}

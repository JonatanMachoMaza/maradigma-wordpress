<?php

declare(strict_types=1);

namespace Maradigma\Support;

/**
 * Evaluates whether booking and calendar interfaces can be shown for a boat.
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
            return false;
        }

        return self::isOwnBoat($boat) && self::isInstantBookingEnabled($boat);
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

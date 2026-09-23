<?php

declare(strict_types=1);

namespace Maradigma\Support;

/**
 * Decides when the obsolete-page cleanup may retire a synced boat page.
 */
final class BoatCleanupPolicy
{
    public const REASON_NOT_FOUND = 'not_found';
    public const REASON_DELETED = 'deleted';
    public const REASON_NOT_PUBLISHED = 'not_published';

    /**
     * Below this many missing boats a site can always clean up.
     */
    public const MASS_REMOVAL_MIN_BOATS = 3;

    /**
     * Maps the API answer for GET /services/boats/{id} to the reason the boat
     * is gone, or '' when the answer does not confirm it.
     *
     * @param array<string,mixed> $response
     */
    public static function goneReason(int $httpStatus, array $response): string
    {
        if ($httpStatus !== 404 || ($response['status'] ?? '') !== 'error') {
            return '';
        }

        return match ((string) ($response['message'] ?? '')) {
            'Service not found' => self::REASON_NOT_FOUND,
            'Service deleted' => self::REASON_DELETED,
            'Service is not published' => self::REASON_NOT_PUBLISHED,
            default => '',
        };
    }

    /**
     * Lowers trash/delete to draft for a boat that is only unpublished in
     * Maradigma, so its page returns when the boat is published again.
     */
    public static function effectiveAction(string $action, string $reason): string
    {
        if ($reason === self::REASON_NOT_PUBLISHED && in_array($action, ['trash', 'delete'], true)) {
            return 'draft';
        }

        return $action;
    }

    /**
     * True when most of the site's synced boats are missing from the API list,
     * which usually means the API key now points at another catalogue.
     */
    public static function isMassRemoval(int $localBoats, int $missingBoats): bool
    {
        return $missingBoats >= self::MASS_REMOVAL_MIN_BOATS && $missingBoats * 2 > $localBoats;
    }

    /**
     * Only numeric ids are checked: the API resolves anything else as a slug.
     */
    public static function isNumericBoatId(string $boatId): bool
    {
        return preg_match('/^[1-9][0-9]*$/', $boatId) === 1;
    }
}

<?php

declare(strict_types=1);

namespace Maradigma\Support;

/**
 * Pure slug rules for synchronized boat posts.
 */
final class BoatSlugPolicy
{
    /**
     * Determines whether a slug is the base slug or a WordPress numbered variant of it.
     *
     * WordPress makes slugs unique by appending "-2", "-3"... so "foo-3" is still
     * the slug of a boat whose base slug is "foo".
     */
    public static function isVariantOf(string $slug, string $baseSlug): bool
    {
        $slug = \trim($slug);
        $baseSlug = \trim($baseSlug);

        if ($slug === '' || $baseSlug === '') {
            return false;
        }

        if ($slug === $baseSlug) {
            return true;
        }

        return \preg_match('/^' . \preg_quote($baseSlug, '/') . '-(?:[2-9]|[1-9]\d+)$/', $slug) === 1;
    }
}

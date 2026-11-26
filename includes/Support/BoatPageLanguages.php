<?php

declare(strict_types=1);

namespace Maradigma\Support;

/**
 * Decides which site languages get a synchronized boat page.
 *
 * An empty choice means every active language, so a language added to the
 * site later is synchronized too. The source language is always kept because
 * the translations are linked to its page.
 */
final class BoatPageLanguages
{
    /**
     * Normalizes a stored or submitted list of language codes.
     *
     * @param mixed $value
     * @return list<string>
     */
    public static function normalize($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $languages = [];
        foreach ($value as $language) {
            if (!is_scalar($language)) {
                continue;
            }

            $language = strtolower(trim((string) $language));
            if (preg_match('/^[a-z]{2,3}(?:[-_][a-z0-9]{2,8})?$/', $language) === 1) {
                $languages[] = $language;
            }
        }

        return array_values(array_unique($languages));
    }

    /**
     * Returns the languages to synchronize, in the site's order, source first.
     *
     * @param list<string> $active Active site languages.
     * @param list<string> $chosen Languages chosen in the settings.
     * @return list<string>
     */
    public static function select(array $active, string $source, array $chosen): array
    {
        $source = strtolower(trim($source));
        $active = self::normalize($active);
        $chosen = self::normalize($chosen);

        $languages = $chosen === []
            ? $active
            : array_values(array_filter($active, static fn(string $language): bool => in_array($language, $chosen, true)));

        if ($source === '') {
            return $languages;
        }

        return array_values(array_unique(array_merge([$source], $languages)));
    }

    /**
     * Returns what to store: an empty list when every active language is chosen.
     *
     * @param list<string> $active Active site languages.
     * @param list<string> $chosen Languages chosen in the form.
     * @return list<string>
     */
    public static function forStorage(array $active, string $source, array $chosen): array
    {
        $active = self::normalize($active);
        $chosen = self::select($active, $source, $chosen);

        if ($active === [] || array_diff($active, $chosen) === []) {
            return [];
        }

        return $chosen;
    }
}

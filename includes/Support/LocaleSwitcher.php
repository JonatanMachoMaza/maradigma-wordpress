<?php

declare(strict_types=1);

namespace Maradigma\Support;

/**
 * Renders output in the language of the page a request comes from.
 *
 * REST and AJAX requests are answered in the site locale, not in the language of the page
 * that triggered them, so server-rendered texts must be produced under that page's locale.
 *
 * WordPress only switches to locales whose language pack is installed, so when it cannot
 * switch, the plugin's own bundled translations are applied to its text domain instead.
 */
final class LocaleSwitcher
{
    private const TEXT_DOMAIN = 'maradigma';

    /**
     * WordPress locale used for each language slug. Each one has a bundled translation file.
     *
     * @var array<string,string>
     */
    private const LOCALES = [
        'en' => 'en_GB',
        'es' => 'es_ES',
        'ca' => 'ca',
        'de' => 'de_DE',
        'fr' => 'fr_FR',
        'it' => 'it_IT',
        'nl' => 'nl_NL',
    ];

    /** @var array<string,\MO|null> */
    private static array $catalogs = [];

    /**
     * Resolves the WordPress locale of a language code such as "en", "en-GB" or "es_ES".
     * Unknown or empty languages resolve to $fallbackLocale.
     */
    public static function localeForLanguage(string $language, string $fallbackLocale = ''): string
    {
        $language = \strtolower(\trim($language));
        $parts    = \preg_split('/[_-]/', $language);
        $base     = \preg_replace('/[^a-z0-9]/', '', (string) ($parts[0] ?? '')) ?? '';

        return self::LOCALES[$base] ?? $fallbackLocale;
    }

    /**
     * Runs the callback under the locale of the language and undoes the change afterwards.
     * Runs it as is when the language is unknown or already active.
     *
     * @template T
     *
     * @param callable():T $callback
     *
     * @return T
     */
    public static function run(string $language, callable $callback)
    {
        $currentLocale = \function_exists('determine_locale')
            ? (string) \determine_locale()
            : (\function_exists('get_locale') ? (string) \get_locale() : '');

        $locale = self::localeForLanguage($language, $currentLocale);

        if ($locale === '' || $locale === $currentLocale) {
            return $callback();
        }

        $switched = \function_exists('switch_to_locale')
            && \function_exists('restore_previous_locale')
            && \switch_to_locale($locale);

        $undoBundled = $switched ? null : self::applyBundledTranslations($locale);

        try {
            return $callback();
        } finally {
            if ($switched) {
                \restore_previous_locale();
            } elseif ($undoBundled !== null) {
                $undoBundled();
            }
        }
    }

    /**
     * Makes the plugin's text domain use its bundled translation file for the locale.
     *
     * @return (callable():void)|null Restores the previous behaviour, or null when nothing was applied.
     */
    private static function applyBundledTranslations(string $locale): ?callable
    {
        if (!\defined('MARADIGMA_PLUGIN_DIR') || !\function_exists('add_filter') || !\function_exists('remove_filter')) {
            return null;
        }

        $catalog = self::loadCatalog(\MARADIGMA_PLUGIN_DIR . 'languages/' . self::TEXT_DOMAIN . '-' . $locale . '.mo');

        if ($catalog === null) {
            return null;
        }

        $gettext = static fn ($translation, $text, $domain) => $domain === self::TEXT_DOMAIN
            ? $catalog->translate((string) $text)
            : $translation;

        $gettextWithContext = static fn ($translation, $text, $context, $domain) => $domain === self::TEXT_DOMAIN
            ? $catalog->translate((string) $text, (string) $context)
            : $translation;

        $ngettext = static fn ($translation, $single, $plural, $number, $domain) => $domain === self::TEXT_DOMAIN
            ? $catalog->translate_plural((string) $single, (string) $plural, (int) $number)
            : $translation;

        \add_filter('gettext', $gettext, 10, 3);
        \add_filter('gettext_with_context', $gettextWithContext, 10, 4);
        \add_filter('ngettext', $ngettext, 10, 5);

        return static function () use ($gettext, $gettextWithContext, $ngettext): void {
            \remove_filter('gettext', $gettext, 10);
            \remove_filter('gettext_with_context', $gettextWithContext, 10);
            \remove_filter('ngettext', $ngettext, 10);
        };
    }

    private static function loadCatalog(string $file): ?\MO
    {
        if (\array_key_exists($file, self::$catalogs)) {
            return self::$catalogs[$file];
        }

        $catalog = null;

        if (\class_exists(\MO::class, false) && \is_readable($file)) {
            $mo = new \MO();

            if ($mo->import_from_file($file)) {
                $catalog = $mo;
            }
        }

        self::$catalogs[$file] = $catalog;

        return $catalog;
    }
}

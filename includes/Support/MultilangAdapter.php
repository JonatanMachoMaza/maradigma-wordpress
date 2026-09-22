<?php

declare(strict_types=1);

namespace Maradigma\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Multilang adapter for Polylang / WPML.
 *
 * Responsibilities:
 * - Detect provider
 * - List active languages (only those enabled by the site)
 * - Get/set post language
 * - Create and link translations
 *
 * IMPORTANT:
 * - No hard dependencies: everything guarded with function_exists / has_filter / class_exists.
 */
final class MultilangAdapter
{
    /**
     * @return 'polylang'|'wpml'|''
     */
    public static function detectProvider(): string
    {
        // Polylang
        if (\function_exists('pll_current_language') || \function_exists('pll_languages_list')) {
            return 'polylang';
        }

        // WPML: usually present as filters/actions
        if (\has_filter('wpml_current_language') || \has_filter('wpml_object_id')) {
            return 'wpml';
        }

        return '';
    }

    /**
     * Determines whether a supported multilingual plugin is active.
     */
    public static function isActive(): bool
    {
        return self::detectProvider() !== '';
    }

    /**
     * Get active languages configured in the multilingual plugin.
     *
     * @return list<string> e.g. ['en','es','fr']
     */
    public static function getActiveLanguages(): array
    {
        $provider = self::detectProvider();

        if ($provider === 'polylang' && \function_exists('pll_languages_list')) {
            $langs = \pll_languages_list(['fields' => 'slug']); // list<string>
            $out = [];

            if (\is_array($langs)) {
                foreach ($langs as $l) {
                    $l = \strtolower(\trim((string) $l));
                    if ($l !== '') {
                        $out[] = $l;
                    }
                }
            }

            return \array_values(\array_unique($out));
        }

        if ($provider === 'wpml') {
            /**
             * WPML provides languages via filters. Common approach:
             * apply_filters('wpml_active_languages', null, ['skip_missing' => 0, 'orderby' => 'code']);
             */
            $maybe = \apply_filters('wpml_active_languages', null, ['skip_missing' => 0, 'orderby' => 'code']);
            $out = self::normalizeWpmlLanguages($maybe);

            // wpml_active_languages requires the front-end `wp` action. Cron and
            // admin-AJAX workers therefore need WPML's service as a fallback.
            if (empty($out)) {
                $sitepress = $GLOBALS['sitepress'] ?? null;
                if (\is_object($sitepress) && \is_callable([$sitepress, 'get_active_languages'])) {
                    try {
                        $out = self::normalizeWpmlLanguages(
                            \call_user_func([$sitepress, 'get_active_languages'])
                        );
                    } catch (\Throwable) {
                        $out = [];
                    }
                }
            }

            // Fallback: if filter returns nothing, at least keep current language
            if (empty($out)) {
                $cur = self::getCurrentLanguage();
                if ($cur !== '') {
                    $out[] = $cur;
                }
            }

            return \array_values(\array_unique($out));
        }

        return [];
    }

    /**
     * Normalize the different language row shapes returned by WPML versions.
     *
     * @param mixed $languages
     * @return list<string>
     */
    private static function normalizeWpmlLanguages($languages): array
    {
        if (!\is_array($languages)) {
            return [];
        }

        $out = [];

        foreach ($languages as $key => $row) {
            $code = '';

            if (\is_array($row)) {
                $code = (string) ($row['language_code'] ?? $row['code'] ?? '');
            } elseif (\is_object($row)) {
                $rowData = (array) $row;
                $code = (string) ($rowData['language_code'] ?? $rowData['code'] ?? '');
            } elseif (\is_string($row)) {
                $code = $row;
            }

            if ($code === '' && \is_string($key)) {
                $code = $key;
            }

            $code = \strtolower(\trim($code));
            $code = (string) \preg_replace('/[^a-z0-9_-]/', '', $code);

            if ($code !== '') {
                $out[] = $code;
            }
        }

        return \array_values(\array_unique($out));
    }

    /**
     * Current language from provider, else empty.
     */
    public static function getCurrentLanguage(): string
    {
        $provider = self::detectProvider();

        if ($provider === 'polylang' && \function_exists('pll_current_language')) {
            $l = (string) \pll_current_language('slug');
            $l = \strtolower(\trim($l));
            return $l;
        }

        if ($provider === 'wpml') {
            $maybe = \apply_filters('wpml_current_language', null);
            $l = \is_string($maybe) ? \strtolower(\trim($maybe)) : '';
            return $l;
        }

        return '';
    }

    /**
     * Default language configured in multilang plugin (NOT your plugin default).
     */
    public static function getDefaultLanguage(): string
    {
        $provider = self::detectProvider();

        if ($provider === 'polylang' && \function_exists('pll_default_language')) {
            $l = (string) \pll_default_language('slug');
            $l = \strtolower(\trim($l));
            return $l;
        }

        if ($provider === 'wpml') {
            $maybe = \apply_filters('wpml_default_language', null);
            $l = \is_string($maybe) ? \strtolower(\trim($maybe)) : '';
            return $l;
        }

        return '';
    }

    /**
     * Get the language assigned to a post by the active multilingual provider.
     */
    public static function getPostLanguage(int $postId): string
    {
        if ($postId <= 0) {
            return '';
        }

        $provider = self::detectProvider();

        if ($provider === 'polylang' && \function_exists('pll_get_post_language')) {
            return \strtolower(\trim((string) \pll_get_post_language($postId, 'slug')));
        }

        if ($provider === 'wpml') {
            $postType = (string) \get_post_type($postId);
            if ($postType === '') {
                return '';
            }

            $code = \apply_filters('wpml_element_language_code', null, [
                'element_id'   => $postId,
                'element_type' => 'post_' . $postType,
            ]);

            return \is_string($code) ? \strtolower(\trim($code)) : '';
        }

        return '';
    }

    /**
     * Set post language.
     */
    public static function setPostLanguage(int $postId, string $lang): void
    {
        $lang = \strtolower(\trim($lang));
        if ($postId <= 0 || $lang === '') {
            return;
        }

        $provider = self::detectProvider();

        if ($provider === 'polylang' && \function_exists('pll_set_post_language')) {
            \pll_set_post_language($postId, $lang);
            return;
        }

        if ($provider === 'wpml') {
            // WPML requires setting language details via action
            // We need element type + trid. We'll do best effort.
            $postType = (string) \get_post_type($postId);
            if ($postType === '') {
                return;
            }

            $elementType = 'post_' . $postType;

            $trid = \apply_filters('wpml_element_trid', null, $postId, $elementType);
            $trid = \is_numeric($trid) ? (int) $trid : 0;

            // If trid is missing, WPML will create it when we set details.
            \do_action('wpml_set_element_language_details', [
                'element_id'           => $postId,
                'element_type'         => $elementType,
                'trid'                 => ($trid > 0) ? $trid : null,
                'language_code'        => $lang,
                'source_language_code' => null,
            ]);
        }
    }

    /**
     * For a given "source" post (any language), find translation in $lang.
     */
    public static function getTranslationPostId(int $postId, string $lang): int
    {
        $lang = \strtolower(\trim($lang));
        if ($postId <= 0 || $lang === '') {
            return 0;
        }

        $provider = self::detectProvider();

        if ($provider === 'polylang' && \function_exists('pll_get_post')) {
            $t = (int) \pll_get_post($postId, $lang);
            return $t > 0 ? $t : 0;
        }

        if ($provider === 'wpml') {
            $postType = (string) \get_post_type($postId);
            if ($postType === '') {
                return 0;
            }
            $t = \apply_filters('wpml_object_id', $postId, $postType, false, $lang);
            return \is_numeric($t) ? (int) $t : 0;
        }

        return 0;
    }

    /**
     * Returns every post in the translation group of a post, keyed by language.
     *
     * @return array<string,int> lang => postId (includes the post itself when it has a language)
     */
    public static function getPostTranslations(int $postId): array
    {
        if ($postId <= 0) {
            return [];
        }

        $provider = self::detectProvider();
        $out = [];

        if ($provider === 'polylang' && \function_exists('pll_get_post_translations')) {
            $translations = \pll_get_post_translations($postId);
            if (\is_array($translations)) {
                foreach ($translations as $lang => $translatedId) {
                    $lang = \strtolower(\trim((string) $lang));
                    $translatedId = (int) $translatedId;
                    if ($lang !== '' && $translatedId > 0) {
                        $out[$lang] = $translatedId;
                    }
                }
            }

            return $out;
        }

        if ($provider === 'wpml') {
            $postType = (string) \get_post_type($postId);
            if ($postType === '') {
                return [];
            }

            $elementType = 'post_' . $postType;
            $trid = \apply_filters('wpml_element_trid', null, $postId, $elementType);
            if (!\is_numeric($trid) || (int) $trid <= 0) {
                return [];
            }

            $translations = \apply_filters('wpml_get_element_translations', null, (int) $trid, $elementType);
            if (\is_array($translations)) {
                foreach ($translations as $lang => $row) {
                    $translatedId = \is_object($row) ? (int) ($row->element_id ?? 0) : (\is_array($row) ? (int) ($row['element_id'] ?? 0) : 0);
                    $lang = \strtolower(\trim((string) $lang));
                    if ($lang !== '' && $translatedId > 0) {
                        $out[$lang] = $translatedId;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * Link translations given a map lang => postId.
     *
     * @param array<string,int> $langToPostId
     */
    public static function linkTranslations(array $langToPostId): void
    {
        $provider = self::detectProvider();

        // Normalize
        $clean = [];
        foreach ($langToPostId as $lang => $pid) {
            $lang = \strtolower(\trim((string) $lang));
            $pid  = (int) $pid;
            if ($lang !== '' && $pid > 0) {
                $clean[$lang] = $pid;
            }
        }

        if (\count($clean) < 2) {
            return;
        }

        if ($provider === 'polylang' && \function_exists('pll_save_post_translations')) {
            \pll_save_post_translations($clean);
            return;
        }

        if ($provider === 'wpml') {
            // WPML: attach all posts to same trid, each with its language + source language.
            // We'll pick one as "source" (default language if present, else first).
            $default = self::getDefaultLanguage();
            $sourceLang = ($default !== '' && isset($clean[$default])) ? $default : (string) \array_key_first($clean);

            $sourcePostId = (int) ($clean[$sourceLang] ?? 0);
            if ($sourcePostId <= 0) {
                return;
            }

            $postType = (string) \get_post_type($sourcePostId);
            if ($postType === '') {
                return;
            }

            $elementType = 'post_' . $postType;

            $trid = \apply_filters('wpml_element_trid', null, $sourcePostId, $elementType);
            $trid = \is_numeric($trid) ? (int) $trid : 0;

            foreach ($clean as $lang => $pid) {
                $pid = (int) $pid;
                if ($pid <= 0) {
                    continue;
                }

                \do_action('wpml_set_element_language_details', [
                    'element_id'           => $pid,
                    'element_type'         => $elementType,
                    'trid'                 => ($trid > 0) ? $trid : null,
                    'language_code'        => $lang,
                    'source_language_code' => ($lang === $sourceLang) ? null : $sourceLang,
                ]);
            }
        }
    }

    /**
     * Determines whether post type translatable.
     */
    public static function isPostTypeTranslatable(string $postType): bool
    {
        $postType = strtolower(trim($postType));
        if ($postType === '' || !post_type_exists($postType)) {
            return false;
        }

        $provider = self::detectProvider();

        // ✅ Polylang (100% fiable)
        if ($provider === 'polylang') {
            if (function_exists('pll_is_translated_post_type')) {
                return (bool) pll_is_translated_post_type($postType);
            }

            // Fallback best-effort (versiones antiguas / edge)
            if (function_exists('pll_get_post_types')) {
                $types = pll_get_post_types([], true); // ojo: algunas versiones usan 2º param como "is_settings"
                return is_array($types) && isset($types[$postType]);
            }

            return false;
        }

        // ✅ WPML (best-effort)
        if ($provider === 'wpml') {
            if (has_filter('wpml_is_translated_post_type')) {
                // Official WPML hook; its third-party-owned name must remain unchanged.
                return (bool) apply_filters('wpml_is_translated_post_type', false, $postType);
            }
            return true; // si WPML activo, evitamos falso negativo
        }

        return false;
    }

    /**
     * Best-effort: ensure the post type is included as "translatable" in Polylang.
     * (WPML still requires enabling it in WPML Settings UI.)
     */
    public static function ensurePostTypeTranslatable(string $postType): void
    {
        $postType = strtolower(trim($postType));
        if ($postType === '') {
            return;
        }

        $provider = self::detectProvider();

        // ✅ Polylang: forzar el CPT como traducible (SIN tocar la pantalla de settings)
        if ($provider === 'polylang') {
            \add_filter('pll_get_post_types', static function ($types, bool $isSettings) use ($postType) {
                // En la UI de Polylang (settings) NO forzamos nada
                if ($isSettings) {
                    return $types;
                }

                if (!\is_array($types)) {
                    $types = [];
                }

                // Polylang espera array<string,bool>
                $types[$postType] = true;
                return $types;
            }, 10, 2);

            return;
        }

        // ✅ WPML: "best effort" por filtro, pero LO IDEAL sigue siendo wpml-config.xml
        if ($provider === 'wpml') {
            \add_filter('wpml_is_translated_post_type', static function ($isTranslated, string $pt) use ($postType) {
                if ($pt === $postType) {
                    return true;
                }
                return $isTranslated;
            }, 10, 2);

            return;
        }
    }

    /**
     * Returns normalized language/locale context for the current request.
     *
     * Output example:
     * [
     *   'provider' => 'polylang',
     *   'lang'     => 'es',
     *   'locale'   => 'es-ES',
     * ]
     *
     * Rules:
     * - Language is resolved from the active multilingual provider when possible.
     * - Locale prefers provider-specific locale (e.g. Polylang locale) and falls back
     *   to WordPress determine_locale()/get_locale().
     * - Output is normalized for frontend usage:
     *   - lang   => lowercase slug (es, en, fr)
     *   - locale => BCP 47 style (es-ES, en-GB) using "-" instead of "_"
     *
     * @param string $fallbackLang
     * @param string $fallbackLocale
     *
     * @return array{provider:'polylang'|'wpml'|'',lang:string,locale:string}
     */
    public static function getCurrentContext(string $fallbackLang = 'en', string $fallbackLocale = 'en-GB'): array
    {
        $provider = self::detectProvider();
        $langSlug = self::getCurrentLanguage();

        $locale = \function_exists('determine_locale')
            ? (string) \determine_locale()
            : (\function_exists('get_locale') ? (string) \get_locale() : '');

        if ($provider === 'polylang' && \function_exists('pll_current_language')) {
            $pllLocale = (string) \pll_current_language('locale');
            if (\trim($pllLocale) !== '') {
                $locale = $pllLocale;
            }

            if ($langSlug === '') {
                $slug = (string) \pll_current_language('slug');
                $langSlug = \strtolower(\trim($slug));
            }
        }

        if ($provider === 'wpml' && $langSlug === '') {
            $langSlug = self::getDefaultLanguage();
        }

        $langSlug = \strtolower(\trim((string) $langSlug));
        $locale   = \trim(\str_replace('_', '-', (string) $locale));

        if ($langSlug === '') {
            $langSlug = \strtolower(\trim($fallbackLang));
        }

        if ($locale === '') {
            $locale = \trim($fallbackLocale);
        }

        return [
            'provider' => $provider,
            'lang'     => $langSlug,
            'locale'   => $locale,
        ];
    }

    /**
     * Translates an editable string using multilingual string tables.
     *
     * Use this for widget titles or editable admin strings that may also
     * be registered by WPML or Polylang.
     *
     * @param string $value
     * @param string $context
     * @param string $name
     * @param string $textDomain Deprecated compatibility argument. Dynamic
     *                           strings are never passed to gettext APIs.
     *
     * @return string
     */
    public static function translateEditableString(
        string $value,
        string $context = 'Maradigma',
        string $name = '',
        string $textDomain = 'maradigma'
    ): string {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        unset($textDomain);

        $translated = self::translateAdminString($value, $context, $name);
        $translated = trim($translated);

        if ($translated !== '' && $translated !== $value) {
            return $translated;
        }

        return $value;
    }

    /**
     * Translates an editable value while preserving gettext for its built-in default.
     *
     * Page builders persist editable defaults as literal source strings. When the
     * stored value still matches that canonical default, use the already translated
     * gettext value supplied by the caller. Custom values continue through the
     * multilingual string tables used by WPML and Polylang.
     *
     * @param string $value             Stored editable value.
     * @param string $defaultValue      Canonical source-language default.
     * @param string $translatedDefault Gettext translation of the default.
     * @param string $context           Multilingual string-table context.
     * @param string $name              Stable multilingual string identifier.
     *
     * @return string
     */
    public static function translateEditableDefault(
        string $value,
        string $defaultValue,
        string $translatedDefault,
        string $context = 'Maradigma',
        string $name = ''
    ): string {
        $value = trim($value);
        $defaultValue = trim($defaultValue);
        $translatedDefault = trim($translatedDefault);

        if ($value === '') {
            return '';
        }

        if ($defaultValue !== '' && $value === $defaultValue) {
            return $translatedDefault !== '' ? $translatedDefault : $defaultValue;
        }

        return self::translateEditableString($value, $context, $name);
    }
    
    /**
     * Registers and translates a dynamic admin/editor string through Polylang or WPML.
     *
     * This is intended for strings stored in database/options/Elementor settings,
     * not for static code strings that should use __().
     *
     * Examples:
     * - Elementor widget titles
     * - Editable button labels
     * - Editable section headings
     *
     * @param string $value   Original string stored in DB.
     * @param string $context Translation group/context.
     * @param string $name    Unique translation key.
     *
     * @return string Translated string if available, otherwise original value.
     */
    public static function translateAdminString(string $value, string $context, string $name): string
    {
        $value   = trim($value);
        $context = trim($context);
        $name    = trim($name);

        if ($value === '') {
            return '';
        }

        if ($context === '') {
            $context = 'Maradigma';
        }

        if ($name === '') {
            $name = md5($context . '|' . $value);
        }

        $provider = self::detectProvider();

        if ($provider === 'polylang') {
            if (\function_exists('pll_register_string')) {
                \pll_register_string($name, $value, $context);
            }

            if (\function_exists('pll__')) {
                $translated = (string) \pll__($value);
                if (\trim($translated) !== '') {
                    return $translated;
                }
            }

            return $value;
        }

        if ($provider === 'wpml') {
            if (\function_exists('do_action')) {
                \do_action('wpml_register_single_string', $context, $name, $value);
            }

            if (\has_filter('wpml_translate_single_string')) {
                $translated = \apply_filters('wpml_translate_single_string', $value, $context, $name);
                if (\is_string($translated) && \trim($translated) !== '') {
                    return $translated;
                }
            }

            return $value;
        }

        return $value;
    }

    /**
     * Translates a previously registered dynamic string without forcing registration.
     *
     * Useful when you know the string has already been registered elsewhere.
     *
     * @param string $value   Original string.
     * @param string $context Translation group/context.
     * @param string $name    Unique translation key.
     *
     * @return string
     */
    public static function translateRegisteredString(string $value, string $context, string $name): string
    {
        $value   = trim($value);
        $context = trim($context);
        $name    = trim($name);

        if ($value === '') {
            return '';
        }

        $provider = self::detectProvider();

        if ($provider === 'polylang' && \function_exists('pll__')) {
            $translated = (string) \pll__($value);
            return \trim($translated) !== '' ? $translated : $value;
        }

        if ($provider === 'wpml' && \has_filter('wpml_translate_single_string')) {
            $translated = \apply_filters('wpml_translate_single_string', $value, $context, $name);
            if (\is_string($translated) && \trim($translated) !== '') {
                return $translated;
            }
        }

        return $value;
    }

}

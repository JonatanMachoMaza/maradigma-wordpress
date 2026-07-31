<?php

declare(strict_types=1);

namespace Maradigma\Support;

/**
 * Provides runtime context utilities for WordPress execution environments.
 *
 * Used by Sanitizer, Renderer, REST, etc.
 *
 * NOTE:
 * - Multilingual detection and operations are delegated to MultilangAdapter.
 */
final class RuntimeContext
{
    /**
     * Returns locale.
     */
    public static function getLocale(): string
    {
        if (\function_exists('determine_locale')) {
            $locale = (string) \determine_locale();
            return $locale !== '' ? $locale : 'en_US';
        }

        if (\function_exists('get_locale')) {
            $locale = (string) \get_locale();
            return $locale !== '' ? $locale : 'en_US';
        }

        return 'en_US';
    }

    /**
     * Returns language.
     */
    public static function getLanguage(): string
    {
        $locale = self::getLocale();
        $lang = \strtolower(\substr($locale, 0, 2));
        return $lang !== '' ? $lang : 'en';
    }

    /**
     * Determines whether admin.
     */
    public static function isAdmin(): bool
    {
        return \function_exists('is_admin') ? (bool) \is_admin() : false;
    }

    /**
     * Determines whether AJAX.
     */
    public static function isAjax(): bool
    {
        return \defined('DOING_AJAX') && DOING_AJAX === true;
    }

    /**
     * Determines whether REST.
     */
    public static function isRest(): bool
    {
        if (\defined('REST_REQUEST') && REST_REQUEST === true) {
            return true;
        }

        if (isset($_SERVER['REQUEST_URI'])) {
            $requestUri = \sanitize_url((string) \wp_unslash($_SERVER['REQUEST_URI']));
            return \str_contains($requestUri, '/wp-json/');
        }

        return false;
    }

    /**
     * Determines whether Elementor preview.
     */
    public static function isElementorPreview(): bool
    {
        // Builder query parameters only identify a read-only editor/preview context.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['elementor-preview']) || isset($_GET['elementor_library'])) {
            return true;
        }

        if (
            isset($_GET['action'])
            && \sanitize_key((string) \wp_unslash($_GET['action'])) === 'elementor'
        ) {
            return true;
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        return false;
    }

    /**
     * Determines whether Elementor editor context.
     */
    public static function isElementorEditorContext(): bool
    {
        if (!\did_action('elementor/loaded')) {
            return false;
        }

        if (!\class_exists('\Elementor\Plugin')) {
            return self::isElementorPreview();
        }

        $plugin = \Elementor\Plugin::$instance ?? null;
        if (!$plugin) {
            return self::isElementorPreview();
        }

        if (
            isset($plugin->editor)
            && \method_exists($plugin->editor, 'is_edit_mode')
            && $plugin->editor->is_edit_mode()
        ) {
            return true;
        }

        if (
            isset($plugin->preview)
            && \method_exists($plugin->preview, 'is_preview_mode')
            && $plugin->preview->is_preview_mode()
        ) {
            return true;
        }

        return self::isElementorPreview();
    }

    /**
     * Determines whether WPBakery editor context.
     */
    public static function isWPBakeryEditorContext(): bool
    {
        // WPBakery uses public query flags to identify its read-only editor context.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['vc_editable']) || isset($_GET['vc_action']) || isset($_GET['vc_post_id'])) {
            // phpcs:enable WordPress.Security.NonceVerification.Recommended
            return true;
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        if (function_exists('vc_is_inline') && vc_is_inline()) {
            return true;
        }

        if (function_exists('vc_is_frontend_editor') && vc_is_frontend_editor()) {
            return true;
        }

        return false;
    }

    /**
     * Determines whether builder preview.
     */
    public static function isBuilderPreview(): bool
    {
        return self::isElementorPreview() || self::isWPBakeryEditorContext();
    }

    /**
     * Determines whether bypass cache.
     */
    public static function shouldBypassCache(): bool
    {
        return self::isBuilderPreview() || self::isAdmin() || self::isAjax() || self::isRest();
    }

    /**
     * Returns plugin default language.
     */
    public static function getPluginDefaultLanguage(): string
    {
        $opt = \get_option('maradigma_settings', []);
        $lang = '';

        if (\is_array($opt)) {
            $lang = (string) ($opt['default_language'] ?? '');
        }

        $lang = \strtolower(\trim($lang));
        return $lang !== '' ? $lang : 'en';
    }

    /**
     * Whether public boat pages sync is enabled.
     */
    public static function isBoatPagesSyncEnabled(): bool
    {
        $opt = \get_option('maradigma_settings', []);
        return \is_array($opt) && !empty($opt['enable_boat_pages_sync']);
    }

    /**
     * Detects current language.
     */
    public static function detectCurrentLanguage(): string
    {
        if (\class_exists(\Maradigma\Support\MultilangAdapter::class)) {
            $cur = \Maradigma\Support\MultilangAdapter::getCurrentLanguage();
            $cur = \strtolower(\trim((string) $cur));

            if ($cur === '') {
                $def = \Maradigma\Support\MultilangAdapter::getDefaultLanguage();
                $def = \strtolower(\trim((string) $def));
                if ($def !== '') {
                    return $def;
                }
            } else {
                return $cur;
            }
        }

        $loc = (string) \get_locale();
        $loc = \strtolower(\trim($loc));
        if ($loc !== '') {
            $parts = \explode('_', $loc);
            $lang = \strtolower(\trim((string) ($parts[0] ?? $loc)));
            if ($lang !== '') {
                return $lang;
            }
        }

        return self::getPluginDefaultLanguage();
    }

    /**
     * Build boats base URL (language-aware).
     *
     * IMPORTANT:
     * - Returns an empty string when public boat pages sync is disabled.
     * - This ensures the plugin does not emit /boats/... public URLs when the
     *   feature is disabled.
     */
    public static function getBoatsBaseUrl(?string $lang = null): string
    {
        if (!self::isBoatPagesSyncEnabled()) {
            return '';
        }

        $lang = $lang !== null ? \strtolower(\trim((string) $lang)) : '';
        if ($lang === '') {
            $lang = self::detectCurrentLanguage();
        }

        $home = rtrim(self::getHomeUrlForLanguage($lang), '/') . '/';

        $slug = self::getBoatsBaseSlugForLang($lang);
        $slug = \trim((string) $slug, "/ \t\n\r\0\x0B");

        if ($slug === '') {
            $slug = 'boats';
        }

        return $home . \trim($slug, '/') . '/';
    }

    /**
     * Resolve a setting value that can be:
     * - "boats"
     * - "es:barcos,en:boats,fr:bateaux"
     */
    public static function resolveLangMappedValue(string $raw, string $lang, string $fallbackLang): string
    {
        $raw = \trim($raw);
        if ($raw === '') {
            return '';
        }

        if (\strpos($raw, ':') === false) {
            return $raw;
        }

        $map = [];
        $pairs = \array_filter(\array_map('trim', \explode(',', $raw)));

        foreach ($pairs as $pair) {
            $pos = \strpos($pair, ':');
            if ($pos === false) {
                continue;
            }

            $k = \strtolower(\trim(\substr($pair, 0, $pos)));
            $v = \trim(\substr($pair, $pos + 1));

            $k = (string) (\preg_split('/[_-]/', $k)[0] ?? $k);

            if ($k !== '' && $v !== '') {
                $map[$k] = $v;
            }
        }

        $lang = \strtolower(\trim($lang));
        $lang = (string) (\preg_split('/[_-]/', $lang)[0] ?? $lang);

        $fallbackLang = \strtolower(\trim($fallbackLang));
        $fallbackLang = (string) (\preg_split('/[_-]/', $fallbackLang)[0] ?? $fallbackLang);

        if ($lang !== '' && isset($map[$lang]) && $map[$lang] !== '') {
            return $map[$lang];
        }

        if ($fallbackLang !== '' && isset($map[$fallbackLang]) && $map[$fallbackLang] !== '') {
            return $map[$fallbackLang];
        }

        foreach ($map as $v) {
            if (\is_string($v) && $v !== '') {
                return $v;
            }
        }

        return '';
    }

    /**
     * Get boats base slug resolved for the current language.
     *
     * IMPORTANT:
     * - Returns empty string when public boat pages sync is disabled.
     * - This keeps the slug inert outside the dedicated feature.
     */
    public static function getBoatsBaseSlug(): string
    {
        if (!self::isBoatPagesSyncEnabled()) {
            return '';
        }

        $lang = self::detectCurrentLanguage();
        return self::getBoatsBaseSlugForLang($lang);
    }

    /**
     * Get boats base slug resolved for a specific language (NOT current request).
     *
     * IMPORTANT:
     * - Returns empty string when public boat pages sync is disabled.
     */
    public static function getBoatsBaseSlugForLang(string $lang): string
    {
        if (!self::isBoatPagesSyncEnabled()) {
            return '';
        }

        $lang = \strtolower(\trim($lang));
        $lang = (string) (\preg_split('/[_-]/', $lang)[0] ?? $lang);

        if ($lang === '') {
            $lang = self::detectCurrentLanguage();
        }

        $opt = \get_option('maradigma_settings', []);
        $raw = 'boats';

        if (\is_array($opt)) {
            $raw = (string) ($opt['boats_base_slug'] ?? 'boats');
        }

        $fallback = self::getPluginDefaultLanguage();
        if ($fallback === '') {
            $fallback = 'en';
        }

        $resolved = self::resolveLangMappedValue($raw, $lang, $fallback);
        $resolved = \trim($resolved, "/ \t\n\r\0\x0B");

        if (self::looksLikeFlattenedLanguageMappedSlug($resolved)) {
            $resolved = 'boats';
        }

        if (\function_exists('apply_filters')) {
            $resolved = (string) \apply_filters('maradigma_boats_base_slug_for_lang', $resolved, $lang, $raw);
            $resolved = \trim($resolved, "/ \t\n\r\0\x0B");
        }

        if (self::looksLikeFlattenedLanguageMappedSlug($resolved)) {
            $resolved = 'boats';
        }

        return $resolved !== '' ? $resolved : 'boats';
    }

    /**
     * Detects language maps accidentally flattened by slug sanitization.
     */
    private static function looksLikeFlattenedLanguageMappedSlug(string $slug): bool
    {
        $slug = \function_exists('sanitize_title')
            ? \sanitize_title($slug)
            : \strtolower(\preg_replace('/[^a-z0-9]+/i', '', $slug) ?: '');

        if ($slug === '') {
            return false;
        }

        return (bool) \preg_match(
            '/^(?:[a-z]{2,3}(?:product[a-z]*|produkt[a-z]*|producto[s]?|produit[s]?|prodotto|prodotti|produto[s]?)){2,}$/',
            $slug
        );
    }
    /**
     * Returns home URL for language.
     */
    public static function getHomeUrlForLanguage(?string $lang = null): string
    {
        $lang = $lang !== null ? \strtolower(\trim($lang)) : self::detectCurrentLanguage();
        $lang = (string) (\preg_split('/[_-]/', $lang)[0] ?? $lang);

        if (\class_exists(\Maradigma\Support\MultilangAdapter::class) && \Maradigma\Support\MultilangAdapter::isActive()) {
            // Adapter may already normalize language-aware home URLs.
        }

        $provider = \class_exists(\Maradigma\Support\MultilangAdapter::class)
            ? \Maradigma\Support\MultilangAdapter::detectProvider()
            : '';

        if ($provider === 'polylang' && \function_exists('pll_home_url')) {
            $u = (string) \pll_home_url($lang);
            if ($u !== '') {
                return $u;
            }
        }

        if ($provider === 'wpml' && \has_filter('wpml_home_url')) {
            $u = \apply_filters('wpml_home_url', \home_url('/'), $lang);
            if (\is_string($u) && $u !== '') {
                return $u;
            }
        }

        return \home_url('/');
    }
}

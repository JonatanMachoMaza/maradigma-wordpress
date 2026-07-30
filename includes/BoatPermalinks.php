<?php

declare(strict_types=1);

namespace Maradigma;

if (!defined('ABSPATH')) {
    exit;
}

use Maradigma\Support\MultilangAdapter;
use Maradigma\Support\RuntimeContext;

/**
 * BoatPermalinks
 *
 * Fixes the "home-de" issue:
 * - NEVER uses pll_home_url() (or any "home page" URL) as base for CPT permalinks.
 * - Uses the LANGUAGE ROOT (e.g. /de/) even if the translated home is /de/home-de/.
 * - Builds: {langRoot}/{boatsBaseSlug}/{post_name}/
 *
 * Also adds rewrite rules matching the same structure.
 *
 * IMPORTANT:
 * - Public boat permalinks and rewrites are only enabled when
 *   "enable_boat_pages_sync" is enabled in plugin settings.
 * - When disabled, the CPT may still exist for admin/internal usage,
 *   but no public front-end routes must be captured.
 */
final class BoatPermalinks
{
    public static function init(): void
    {
        /**
         * Register hooks always.
         * Each callback checks the latest saved setting at runtime.
         */
        add_filter('post_type_link', [__CLASS__, 'filterBoatPermalink'], 20, 2);
        add_action('init', [__CLASS__, 'addRewriteRules'], 20);
    }

    public static function filterBoatPermalink(string $permalink, \WP_Post $post): string
    {
        if ($post->post_type !== BoatPostType::POST_TYPE) {
            return $permalink;
        }

        if (!self::isBoatPagesSyncEnabled()) {
            return $permalink;
        }

        $lang = self::getPostLang((int) $post->ID);

        $langRootUrl = self::getLanguageRootUrl($lang);

        $baseSlug = self::getBoatsBaseSlugForLang($lang);
        $baseSlug = self::normalizeBaseSlug($baseSlug, 'boats');

        $postSlug = trim((string) $post->post_name);

        if ($postSlug === '') {
            $postSlug = sanitize_title((string) $post->post_title);
        }

        if ($postSlug === '') {
            return $permalink;
        }

        return trailingslashit(trailingslashit($langRootUrl) . $baseSlug . '/' . $postSlug);
    }

    public static function addRewriteRules(): void
    {
        if (!self::isBoatPagesSyncEnabled()) {
            return;
        }

        if (!post_type_exists(BoatPostType::POST_TYPE)) {
            return;
        }

        $langs = MultilangAdapter::getActiveLanguages();
        if (empty($langs)) {
            $langs = [self::getPluginDefaultLang()];
        }

        $langs = array_values(array_unique(array_filter(array_map(
            static function ($l): string {
                $l = strtolower(trim((string) $l));
                $l = (string) (preg_split('/[_-]/', $l)[0] ?? $l);
                return $l;
            },
            $langs
        ))));

        if (empty($langs)) {
            $langs = ['en'];
        }

        foreach ($langs as $lang) {
            if ($lang === '') {
                continue;
            }

            $baseSlug = self::normalizeBaseSlug(self::getBoatsBaseSlugForLang($lang), 'boats');

            $rootPath = self::getLanguageRootPath($lang);
            $prefix   = trim($rootPath, '/');

            $rule = '^'
                . ($prefix !== '' ? preg_quote($prefix, '#') . '/' : '')
                . preg_quote($baseSlug, '#')
                . '/([^/]+)/?$';

            add_rewrite_rule(
                $rule,
                'index.php?post_type=' . BoatPostType::POST_TYPE . '&name=$matches[1]',
                'top'
            );
        }
    }

    private static function isBoatPagesSyncEnabled(): bool
    {
        return RuntimeContext::isBoatPagesSyncEnabled();
    }

    private static function getPostLang(int $postId): string
    {
        $lang = '';

        if (function_exists('pll_get_post_language')) {
            $lang = (string) pll_get_post_language($postId, 'slug');
        }

        $lang = strtolower(trim($lang));
        $lang = (string) (preg_split('/[_-]/', $lang)[0] ?? $lang);

        if ($lang === '') {
            $lang = strtolower(trim((string) MultilangAdapter::getDefaultLanguage()));
            $lang = (string) (preg_split('/[_-]/', $lang)[0] ?? $lang);
        }

        if ($lang === '') {
            $lang = self::getPluginDefaultLang();
        }

        if ($lang === '') {
            $lang = 'en';
        }

        return $lang;
    }

    private static function getPluginDefaultLang(): string
    {
        $lang = '';

        if (class_exists(RuntimeContext::class) && method_exists(RuntimeContext::class, 'getPluginDefaultLanguage')) {
            $lang = (string) RuntimeContext::getPluginDefaultLanguage();
        } else {
            $settings = SettingsPage::getSettings();
            $lang = (string) ($settings['default_language'] ?? 'en');
        }

        $lang = strtolower(trim($lang));
        $lang = (string) (preg_split('/[_-]/', $lang)[0] ?? $lang);

        return $lang !== '' ? $lang : 'en';
    }

    /**
     * Returns the LANGUAGE ROOT URL (e.g. https://site.com/de/)
     * even if the translated home is /de/home-de/.
     */
    private static function getLanguageRootUrl(string $lang): string
    {
        $path = self::getLanguageRootPath($lang);
        return home_url($path);
    }

    /**
     * Returns the LANGUAGE ROOT PATH:
     * - "/de/" when language is prefixed
     * - "/" when default language is not prefixed
     *
     * Never returns "/de/home-de/".
     */
    private static function getLanguageRootPath(string $lang): string
    {
        $lang = strtolower(trim($lang));
        $lang = (string) (preg_split('/[_-]/', $lang)[0] ?? $lang);

        if ($lang === '') {
            return '/';
        }

        $fallback = '/' . trim($lang, '/') . '/';

        if (function_exists('pll_home_url') && function_exists('pll_default_language')) {
            $pllHome = (string) pll_home_url($lang);
            $pllPath = (string) (wp_parse_url($pllHome, PHP_URL_PATH) ?: '');
            $pllPath = '/' . trim($pllPath, '/') . '/';

            $default = (string) pll_default_language('slug');
            $default = strtolower(trim($default));
            $default = (string) (preg_split('/[_-]/', $default)[0] ?? $default);

            $expectedPrefixed = '/' . trim($lang, '/') . '/';

            if (str_starts_with($pllPath, $expectedPrefixed)) {
                return $expectedPrefixed;
            }

            if ($default !== '' && $lang === $default) {
                return '/';
            }

            return $fallback;
        }

        return $fallback;
    }

    private static function getBoatsBaseSlugForLang(string $lang): string
    {
        return RuntimeContext::getBoatsBaseSlugForLang($lang);
    }

    private static function normalizeBaseSlug(string $baseSlug, string $fallback = 'boats'): string
    {
        $baseSlug = trim($baseSlug);

        $baseSlug = preg_replace('~https?://[^/]+~i', '', $baseSlug) ?: $baseSlug;
        $baseSlug = trim($baseSlug, '/');

        if ($baseSlug === '') {
            return $fallback;
        }

        if (strpos($baseSlug, '/') !== false) {
            $parts = array_values(array_filter(array_map('trim', explode('/', $baseSlug))));
            $baseSlug = (string) end($parts);
        }

        $baseSlug = sanitize_title($baseSlug);

        return $baseSlug !== '' ? $baseSlug : $fallback;
    }
}

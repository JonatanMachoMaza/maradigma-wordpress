<?php

declare(strict_types=1);

namespace Maradigma;

if (!defined('ABSPATH')) {
    exit;
}

use Maradigma\Support\BoatPostSelectionPolicy;
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
    /** Whether the current request invalidated the registered rewrite rules. */
    private static bool $rewriteRulesFlushScheduled = false;

    /** Option used to track the installed boat rewrite-rule schema. */
    private const REWRITE_SCHEMA_OPTION = 'maradigma_boat_rewrite_schema';

    /**
     * Rewrite-rule schema version.
     *
     * Increment this value whenever an update changes the generated regexes.
     */
    private const REWRITE_SCHEMA_VERSION = '2';

    /**
     * Registers the component's WordPress hooks.
     */
    public static function init(): void
    {
        /**
         * Register hooks always.
         * Each callback checks the latest saved setting at runtime.
         */
        add_filter('post_type_link', [__CLASS__, 'filterBoatPermalink'], 20, 2);
        add_action('init', [__CLASS__, 'addRewriteRules'], 20);
        add_action('init', [__CLASS__, 'maybeFlushRewriteRules'], 99);
        add_action('template_redirect', [__CLASS__, 'redirectNonCanonicalBoatRequest'], 1);
        add_filter('old_slug_redirect_post_id', [__CLASS__, 'filterOldSlugRedirectPostId']);
    }

    /**
     * Keeps old-slug redirects of boat URLs in the requested language.
     *
     * Boat posts of different languages can share an old slug (earlier syncs
     * renamed slugs, and retired duplicates hand theirs to the kept post). Core
     * redirects to the first post found, whatever its status or language; this
     * prefers the published boat post in the language of the requested URL and
     * never redirects to an unpublished boat post.
     *
     * @param mixed $postId Post ID chosen by WordPress core.
     * @return mixed
     */
    public static function filterOldSlugRedirectPostId($postId)
    {
        $name = (string) get_query_var('name');
        $queriedType = get_query_var('post_type');
        $queriedType = is_array($queriedType) ? (string) reset($queriedType) : (string) $queriedType;

        if ($name === '' || $queriedType !== BoatPostType::POST_TYPE) {
            return $postId;
        }

        $current = is_numeric($postId) ? (int) $postId : 0;
        $currentIsPublic = $current > 0 && get_post_status($current) === 'publish';
        $language = self::detectPathLanguage(self::getRequestedPath());

        if ($currentIsPublic && ($language === '' || BoatPostSelectionPolicy::normalizeLanguage(MultilangAdapter::getPostLanguage($current)) === $language)) {
            return $postId;
        }

        if ($language === '') {
            return $currentIsPublic ? $postId : 0;
        }

        $sameLanguageId = self::findPublishedBoatByOldSlug($name, $language);
        if ($sameLanguageId > 0) {
            return $sameLanguageId;
        }

        // No published post in the requested language: keep a published core choice, never an unpublished one.
        return $currentIsPublic ? $postId : 0;
    }

    /**
     * Flushes persisted rewrite rules once after their schema changes.
     *
     * WordPress stores rewrite rules in the database, so changing the regex
     * generator alone would leave previously generated rules active until an
     * administrator manually saved the permalink settings.
     */
    public static function maybeFlushRewriteRules(): void
    {
        if (self::$rewriteRulesFlushScheduled) {
            return;
        }

        if ((string) get_option(self::REWRITE_SCHEMA_OPTION, '') === self::REWRITE_SCHEMA_VERSION) {
            return;
        }

        flush_rewrite_rules(false);
        update_option(self::REWRITE_SCHEMA_OPTION, self::REWRITE_SCHEMA_VERSION, false);
    }

    /**
     * Schedules a rewrite flush for the next WordPress initialization.
     *
     * Settings are saved after the current request's `init` action. Deferring
     * the flush ensures the next request registers routes from the new values
     * before WordPress persists them.
     */
    public static function scheduleRewriteRulesFlush(): void
    {
        self::$rewriteRulesFlushScheduled = true;
        update_option(self::REWRITE_SCHEMA_OPTION, '', false);
    }

    /**
     * Filters boat permalink.
     */
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

        $baseSlug = BoatUrlResolver::resolveBasePathForPost((int) $post->ID, $lang);

        $postSlug = trim((string) $post->post_name);

        if ($postSlug === '') {
            $postSlug = sanitize_title((string) $post->post_title);
        }

        if ($postSlug === '') {
            return $permalink;
        }

        return trailingslashit(trailingslashit($langRootUrl) . $baseSlug . '/' . $postSlug);
    }

    /**
     * Adds rewrite rules.
     */
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

            $baseTemplate = self::getBoatsBaseSlugForLang($lang);
            $rootPath = self::getLanguageRootPath($lang);
            $prefix   = trim($rootPath, '/');

            foreach (BoatUrlResolver::buildRewritePatterns($baseTemplate) as $pattern) {
                $rule = '^'
                    . ($prefix !== '' ? preg_quote($prefix, '#') . '/' : '')
                    . $pattern['regex']
                    . '/([^/]+)/?$';

                add_rewrite_rule(
                    $rule,
                    'index.php?post_type=' . BoatPostType::POST_TYPE . '&name=$matches[' . $pattern['boat_match_index'] . ']',
                    'top'
                );
            }
        }
    }

    /**
     * Redirects a resolved boat request to the current localized permalink.
     *
     * Dynamic rewrite rules intentionally accept any destination and boat-type
     * segment so WordPress can resolve the post by its final slug. Without this
     * redirect, stale routes remain indexable after an API destination or type
     * changes. Running before multilingual redirectors also prevents them from
     * interpreting an outdated route as a request for the wrong language.
     */
    public static function redirectNonCanonicalBoatRequest(): void
    {
        if (
            !self::isBoatPagesSyncEnabled()
            || is_admin()
            || wp_doing_ajax()
            || !is_singular(BoatPostType::POST_TYPE)
        ) {
            return;
        }

        $post = get_queried_object();
        if (!$post instanceof \WP_Post || $post->post_type !== BoatPostType::POST_TYPE) {
            return;
        }

        $canonicalUrl = get_permalink($post);
        if (!is_string($canonicalUrl) || $canonicalUrl === '') {
            return;
        }

        $requestedPath = self::getRequestedPath();
        $canonicalPath = self::normalizeComparablePath((string) wp_parse_url($canonicalUrl, PHP_URL_PATH));

        // A post's own canonical URL never redirects.
        if ($requestedPath === '' || $requestedPath === $canonicalPath) {
            return;
        }

        $target = self::resolveRequestLanguageBoatPost($post, (string) get_query_var('name'), $requestedPath);
        if ($target->ID !== $post->ID) {
            $targetUrl = get_permalink($target);
            if (is_string($targetUrl) && $targetUrl !== '') {
                $canonicalUrl = $targetUrl;
                $canonicalPath = self::normalizeComparablePath((string) wp_parse_url($targetUrl, PHP_URL_PATH));
            }
        }

        if ($requestedPath === $canonicalPath) {
            return;
        }

        wp_safe_redirect($canonicalUrl, 301, 'Maradigma');
        exit;
    }

    /**
     * Returns the boat post a request should land on in the language of its URL.
     *
     * WordPress resolves a singular boat URL by slug alone, whatever its language
     * prefix. Earlier plugin versions renamed slugs on every sync, so an old slug
     * of one language is often the live slug of another language's translation:
     * /de/.../boat-3/ then resolves the French post that now owns "boat-3". Prefer
     * the published post of the URL language that used that slug before, then the
     * URL-language translation of the resolved boat; otherwise keep the post.
     */
    private static function resolveRequestLanguageBoatPost(\WP_Post $post, string $requestedSlug, string $requestedPath): \WP_Post
    {
        $requestLanguage = self::detectPathLanguage($requestedPath);
        $postLanguage = BoatPostSelectionPolicy::normalizeLanguage(MultilangAdapter::getPostLanguage($post->ID));

        if ($requestLanguage === '' || $postLanguage === '' || $requestLanguage === $postLanguage) {
            return $post;
        }

        $previousOwnerId = $requestedSlug !== '' ? self::findPublishedBoatByOldSlug($requestedSlug, $requestLanguage) : 0;
        if ($previousOwnerId > 0) {
            $previousOwner = get_post($previousOwnerId);
            if ($previousOwner instanceof \WP_Post) {
                return $previousOwner;
            }
        }

        $translationId = MultilangAdapter::getTranslationPostId($post->ID, $requestLanguage);
        if ($translationId > 0 && $translationId !== $post->ID && get_post_status($translationId) === 'publish') {
            $translation = get_post($translationId);
            if ($translation instanceof \WP_Post && $translation->post_type === BoatPostType::POST_TYPE) {
                return $translation;
            }
        }

        return $post;
    }

    /**
     * Returns the normalized path of the current request, or ''.
     */
    private static function getRequestedPath(): string
    {
        $requestUri = isset($_SERVER['REQUEST_URI'])
            ? (string) wp_unslash($_SERVER['REQUEST_URI'])
            : '';

        return self::normalizeComparablePath((string) wp_parse_url($requestUri, PHP_URL_PATH));
    }

    /**
     * Returns the language whose boat URL root the path starts with, or ''.
     *
     * Uses the same language roots that build boat permalinks (/de/ or /), not the
     * multilingual plugin's current language, which can come from a cookie or the
     * browser when the URL has no language prefix. A path without a language
     * prefix only maps to the language served from the site root (hidden default
     * language); otherwise it has no language.
     */
    private static function detectPathLanguage(string $path): string
    {
        if ($path === '') {
            return '';
        }

        $homePath = self::normalizeComparablePath((string) wp_parse_url(home_url('/'), PHP_URL_PATH));
        $prefixed = [];
        $rootLanguage = '';

        foreach (MultilangAdapter::getActiveLanguages() as $language) {
            $root = self::normalizeComparablePath((string) wp_parse_url(home_url(self::getLanguageRootPath($language)), PHP_URL_PATH));

            if ($root === $homePath) {
                if ($rootLanguage === '') {
                    $rootLanguage = $language;
                }
                continue;
            }

            if (!isset($prefixed[$root])) {
                $prefixed[$root] = $language;
            }
        }

        \uksort($prefixed, static fn(string $a, string $b): int => \strlen($b) <=> \strlen($a));

        foreach ($prefixed as $root => $language) {
            if ($path === $root || \str_starts_with($path, $root . '/')) {
                return BoatPostSelectionPolicy::normalizeLanguage($language);
            }
        }

        return BoatPostSelectionPolicy::normalizeLanguage($rootLanguage);
    }

    /**
     * Returns the newest published boat post of a language that used a slug before, or 0.
     */
    private static function findPublishedBoatByOldSlug(string $slug, string $language): int
    {
        $query = new \WP_Query([
            'post_type'        => BoatPostType::POST_TYPE,
            'post_status'      => 'publish',
            'fields'           => 'ids',
            'posts_per_page'   => -1,
            'no_found_rows'    => true,
            'orderby'          => 'ID',
            'order'            => 'DESC',
            'lang'             => '',
            // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters
            'suppress_filters' => true,
            'meta_query'       => [
                [
                    'key'   => '_wp_old_slug',
                    'value' => $slug,
                ],
            ],
        ]);

        foreach ((array) $query->posts as $candidateId) {
            $candidateId = is_numeric($candidateId) ? (int) $candidateId : 0;
            if ($candidateId > 0 && BoatPostSelectionPolicy::normalizeLanguage(MultilangAdapter::getPostLanguage($candidateId)) === $language) {
                return $candidateId;
            }
        }

        return 0;
    }

    /**
     * Normalizes URL paths before canonical route comparison.
     */
    private static function normalizeComparablePath(string $path): string
    {
        $path = rawurldecode($path);
        $path = '/' . trim($path, '/');

        return $path === '/' ? '/' : untrailingslashit($path);
    }

    /**
     * Determines whether boat pages sync enabled.
     */
    private static function isBoatPagesSyncEnabled(): bool
    {
        return RuntimeContext::isBoatPagesSyncEnabled();
    }

    /**
     * Returns the language assigned to a post.
     */
    private static function getPostLang(int $postId): string
    {
        $lang = (string) MultilangAdapter::getPostLanguage($postId);

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

    /**
     * Returns the plugin default language.
     */
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

    /**
     * Returns the localized boat archive base slug for a language.
     */
    private static function getBoatsBaseSlugForLang(string $lang): string
    {
        return RuntimeContext::getBoatsBaseSlugForLang($lang);
    }

}

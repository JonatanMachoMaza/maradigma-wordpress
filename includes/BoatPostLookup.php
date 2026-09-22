<?php

declare(strict_types=1);

namespace Maradigma;

if (!defined('ABSPATH')) {
    exit;
}

use Maradigma\Support\BoatPostSelectionPolicy;
use Maradigma\Support\MultilangAdapter;

/**
 * Finds the WordPress posts bound to Maradigma boats, across every language.
 *
 * Every query sets 'lang' => '' on purpose. Polylang adds its current-language
 * filter in the parse_query action, and 'suppress_filters' does not disable
 * actions. Admin AJAX requests sent without Polylang's pll_ajax_backend flag
 * (such as the sync pumps) run as front-end requests with a current language,
 * so a lookup without 'lang' => '' only sees the posts in that language.
 *
 * @phpstan-import-type BoatPostCandidate from BoatPostSelectionPolicy
 */
final class BoatPostLookup
{
    public const META_BOAT_ID      = '_maradigma_boat_id';
    public const META_PAGE_BOAT_ID = '_maradigma_page_boat_id';
    public const META_MANAGED      = '_maradigma_managed';
    /** Set on a duplicate a cleanup retired; the value is the kept post ID. */
    public const META_DUPLICATE_OF = '_maradigma_duplicate_of';

    /** Flags an editor sets on a boat post to keep it as customized. */
    private const CURATED_META_KEYS = [
        '_maradigma_disable_sync',
        '_maradigma_elementor_custom_layout',
        '_maradigma_wpbakery_custom_layout',
        '_maradigma_gutenberg_custom_layout',
    ];

    /**
     * Returns every non-trashed boat post the sync may own for a Maradigma boat.
     *
     * @return list<BoatPostCandidate>
     */
    public static function getSyncCandidates(string $boatId): array
    {
        $boatId = \trim($boatId);
        if ($boatId === '') {
            return [];
        }

        $query = new \WP_Query([
            'post_type'        => BoatPostType::POST_TYPE,
            'post_status'      => 'any',
            'fields'           => 'ids',
            'posts_per_page'   => -1,
            'no_found_rows'    => true,
            'orderby'          => 'ID',
            'order'            => 'ASC',
            'lang'             => '',
            // WPML filters by language in posts_join/posts_where, which suppress_filters disables.
            // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters
            'suppress_filters' => true,
            'meta_query'       => [
                [
                    'key'   => self::META_BOAT_ID,
                    'value' => $boatId,
                ],
            ],
        ]);

        $candidates = self::describePosts(self::queryIds($query), false);

        return $candidates[$boatId] ?? [];
    }

    /**
     * Returns the post the sync must update for a boat in a language, or 0.
     *
     * The selection policy picks among every post in exactly that language,
     * including orphans that fell out of the translation group: a post an
     * editor customized first, then the translation linked to the boat's
     * source-language post ($anchorPostId), then the other rules.
     *
     * @param list<BoatPostCandidate>|null $candidates Pre-fetched candidates for the boat.
     */
    public static function findSyncPostId(string $boatId, string $language, int $anchorPostId = 0, ?array $candidates = null): int
    {
        $candidates ??= self::getSyncCandidates($boatId);
        if ($candidates === []) {
            return 0;
        }

        if (MultilangAdapter::detectProvider() === '') {
            return BoatPostSelectionPolicy::pick(BoatPostSelectionPolicy::filterLanguage($candidates, ''));
        }

        $inLanguage = BoatPostSelectionPolicy::filterLanguage($candidates, $language);
        if ($inLanguage === []) {
            return 0;
        }

        $preferredId = 0;
        if ($anchorPostId > 0) {
            // The provider's own language code (e.g. 'pt-br'), not a normalized form.
            $preferredId = MultilangAdapter::getTranslationPostId($anchorPostId, \strtolower(\trim($language)));
        }

        return BoatPostSelectionPolicy::pick($inLanguage, $preferredId);
    }

    /**
     * Resolves the published post that represents each boat in a language.
     *
     * Order: a post in the requested language (a page an editor bound to the
     * boat first, then a translation-group member, then the newest with layout
     * content), then the requested-language translation of the best post, then
     * a post without language, then the best post in any language.
     *
     * @param list<string> $boatIds
     * @param bool         $includePageBindings Also consider pages bound with the page boat ID meta.
     * @return array<string,int> boatId => postId
     */
    public static function resolvePublicPostIds(array $boatIds, string $language, bool $includePageBindings = true): array
    {
        $boatIds = \array_values(\array_unique(\array_filter(\array_map(
            static fn($value): string => \trim((string) $value),
            $boatIds
        ), static fn(string $value): bool => $value !== '')));

        if ($boatIds === []) {
            return [];
        }

        $metaQuery = [
            [
                'key'     => self::META_BOAT_ID,
                'value'   => $boatIds,
                'compare' => 'IN',
            ],
        ];

        if ($includePageBindings) {
            $metaQuery = [
                'relation' => 'OR',
                [
                    'key'     => self::META_PAGE_BOAT_ID,
                    'value'   => $boatIds,
                    'compare' => 'IN',
                ],
                $metaQuery[0],
            ];
        }

        $query = new \WP_Query([
            'post_type'              => $includePageBindings ? 'any' : BoatPostType::POST_TYPE,
            'post_status'            => 'publish',
            'fields'                 => 'ids',
            'posts_per_page'         => -1,
            'no_found_rows'          => true,
            'update_post_term_cache' => false,
            'lang'                   => '',
            'meta_query'             => $metaQuery,
        ]);

        $language = BoatPostSelectionPolicy::normalizeLanguage($language);
        $wanted = \array_fill_keys($boatIds, true);
        $out = [];

        foreach (self::describePosts(self::queryIds($query), $includePageBindings) as $boatId => $candidates) {
            $boatId = (string) $boatId;
            if (!isset($wanted[$boatId])) {
                continue;
            }

            $postId = self::pickPublicPostId(BoatPostSelectionPolicy::filterPublished($candidates), $language);
            if ($postId > 0) {
                $out[$boatId] = $postId;
            }
        }

        return $out;
    }

    /**
     * Returns the Maradigma boat ID a post is bound to, or ''.
     *
     * @param bool $includePageBindings Read the page boat ID meta first, like the public listings do.
     */
    public static function getBoundBoatId(int $postId, bool $includePageBindings = true): string
    {
        if ($postId <= 0) {
            return '';
        }

        $boatId = $includePageBindings
            ? \trim((string) \get_post_meta($postId, self::META_PAGE_BOAT_ID, true))
            : '';

        if ($boatId === '') {
            $boatId = \trim((string) \get_post_meta($postId, self::META_BOAT_ID, true));
        }

        return $boatId;
    }

    /**
     * Determines whether an editor marked a boat post as customized: one of the
     * "custom layout" flags, or the no-sync flag.
     */
    public static function isCurated(int $postId): bool
    {
        foreach (self::CURATED_META_KEYS as $metaKey) {
            if ((bool) \get_post_meta($postId, $metaKey, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determines whether a boat post's layout was changed after the sync wrote it.
     *
     * The sync stores a hash of the layout it seeds (Gutenberg and WPBakery
     * post_content, Elementor data). Layout that matches none of those hashes
     * was written or edited by someone else. Unknown provenance counts as edited,
     * so a doubtful post is protected rather than retired.
     */
    public static function hasHandEditedLayout(int $postId): bool
    {
        $post = \get_post($postId);
        if (!$post instanceof \WP_Post) {
            return false;
        }

        $content = \str_replace(["\r\n", "\r"], "\n", (string) $post->post_content);
        if (\trim($content) !== '') {
            // Same normalizations as GutenbergIntegration::hashGutenbergTemplateContent()
            // and WPBakeryIntegration::hashWPBakeryTemplateContent().
            $gutenbergHash = (string) \get_post_meta($postId, '_maradigma_gutenberg_content_hash', true);
            $wpbakeryHash = (string) \get_post_meta($postId, '_maradigma_wpbakery_content_hash', true);
            $matchesSeed = ($gutenbergHash !== '' && \hash_equals($gutenbergHash, \md5(\rtrim($content))))
                || ($wpbakeryHash !== '' && \hash_equals($wpbakeryHash, \md5(\trim($content))));

            if (!$matchesSeed) {
                return true;
            }
        }

        $elementorData = (string) \get_post_meta($postId, '_elementor_data', true);
        if (!\in_array(\trim($elementorData), ['', '[]'], true)) {
            $elementorHash = (string) \get_post_meta($postId, '_maradigma_elementor_data_hash', true);

            return $elementorHash === '' || !\hash_equals($elementorHash, \md5($elementorData));
        }

        return false;
    }

    /**
     * Determines whether a post has layout content from any supported builder.
     */
    public static function hasLayoutContent(\WP_Post $post): bool
    {
        if (\trim((string) $post->post_content) !== '') {
            return true;
        }

        $elementorData = \get_post_meta($post->ID, '_elementor_data', true);

        return \is_string($elementorData) && !\in_array(\trim($elementorData), ['', '[]'], true);
    }

    /**
     * Picks the public post for one boat among its published candidates (same order
     * as resolvePublicPostIds()); the result may be a translation outside the list.
     *
     * @param list<BoatPostCandidate> $published
     */
    public static function pickPublicPostId(array $published, string $language): int
    {
        if ($published === []) {
            return 0;
        }

        $language = self::refineRequestLanguage($language);

        $inLanguage = BoatPostSelectionPolicy::filterLanguage($published, $language);
        if ($inLanguage !== []) {
            return BoatPostSelectionPolicy::pick($inLanguage);
        }

        $ranked = BoatPostSelectionPolicy::rank($published);

        if ($language !== '') {
            foreach ($ranked as $candidate) {
                $translatedId = MultilangAdapter::getTranslationPostId($candidate['id'], $language);
                if ($translatedId > 0 && \get_post_status($translatedId) === 'publish') {
                    return $translatedId;
                }
            }

            // Last language resort: a regional sibling ('en' page, 'en-gb' post).
            $sameFamily = BoatPostSelectionPolicy::filterPrimaryLanguage($published, $language);
            if ($sameFamily !== []) {
                return BoatPostSelectionPolicy::pick($sameFamily);
            }
        }

        $withoutLanguage = BoatPostSelectionPolicy::filterLanguage($published, '');

        return $withoutLanguage !== []
            ? BoatPostSelectionPolicy::pick($withoutLanguage)
            : $ranked[0]['id'];
    }

    /**
     * Restores the region of a shortened request language from the current
     * multilingual language ('pt' on a 'pt-br' page becomes 'pt-br').
     *
     * The listings shorten the page language to its primary subtag; matching
     * boat posts needs the provider's full code to keep regional variants apart.
     */
    private static function refineRequestLanguage(string $language): string
    {
        $language = BoatPostSelectionPolicy::normalizeLanguage($language);
        $current = BoatPostSelectionPolicy::normalizeLanguage(MultilangAdapter::getCurrentLanguage());

        if ($language !== '' && $current !== '' && $current !== $language
            && \strpos($language, '-') === false
            && BoatPostSelectionPolicy::primaryLanguage($current) === $language
        ) {
            return $current;
        }

        return $language;
    }

    /**
     * Describes boat-bound posts as selection candidates, grouped by boat ID.
     *
     * @param list<int> $postIds
     * @return array<string,list<BoatPostCandidate>> boatId => candidates
     */
    public static function describePosts(array $postIds, bool $includePageBindings): array
    {
        if ($postIds === []) {
            return [];
        }

        \_prime_post_caches($postIds, false, true);

        $out = [];

        foreach ($postIds as $postId) {
            $post = \get_post($postId);
            if (!$post instanceof \WP_Post) {
                continue;
            }

            $boatId = self::getBoundBoatId($postId, $includePageBindings);
            if ($boatId === '') {
                continue;
            }

            $out[$boatId][] = [
                'id'          => $postId,
                'lang'        => BoatPostSelectionPolicy::normalizeLanguage(MultilangAdapter::getPostLanguage($postId)),
                'status'      => (string) $post->post_status,
                'explicit'    => $includePageBindings
                    && \trim((string) \get_post_meta($postId, self::META_PAGE_BOAT_ID, true)) === $boatId,
                // Made by hand (not by the sync) or customized by an editor.
                'curated'     => self::isCurated($postId) || !(bool) \get_post_meta($postId, self::META_MANAGED, true),
                'retired'     => (int) \get_post_meta($postId, self::META_DUPLICATE_OF, true) > 0,
                'linked'      => self::isLinkedToSameBoat($postId, $boatId, $includePageBindings),
                'has_content' => self::hasLayoutContent($post),
                'date'        => self::postDate($post),
            ];
        }

        return $out;
    }

    /**
     * Determines whether a post shares a translation group with another post of the same boat.
     *
     * A post dropped from its group can still point at the group's term, which
     * then lists another post for its language; such a post is not linked.
     */
    private static function isLinkedToSameBoat(int $postId, string $boatId, bool $includePageBindings): bool
    {
        $translations = MultilangAdapter::getPostTranslations($postId);
        if (!\in_array($postId, $translations, true)) {
            return false;
        }

        foreach ($translations as $translatedId) {
            if ($translatedId !== $postId && self::getBoundBoatId($translatedId, $includePageBindings) === $boatId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the post date used to order candidates (GMT when available).
     */
    private static function postDate(\WP_Post $post): string
    {
        $date = (string) $post->post_date_gmt;

        return ($date === '' || $date === '0000-00-00 00:00:00') ? (string) $post->post_date : $date;
    }

    /**
     * @return list<int>
     */
    private static function queryIds(\WP_Query $query): array
    {
        $ids = [];

        foreach ((array) $query->posts as $postId) {
            $postId = \is_numeric($postId) ? (int) $postId : 0;
            if ($postId > 0) {
                $ids[] = $postId;
            }
        }

        return \array_values(\array_unique($ids));
    }
}

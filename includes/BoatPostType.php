<?php

declare(strict_types=1);

namespace Maradigma;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers the "maradigma_boat" custom post type.
 *
 * Goal:
 * - Keep the CPT manageable from admin even when public sync is disabled.
 * - Avoid claiming any public slug namespace when "enable_boat_pages_sync" is disabled.
 *
 * IMPORTANT:
 * - We keep the CPT stable and public enough for WordPress/editor integrations.
 * - The public slug capture is controlled by rewrite/permalink logic, not by destroying
 *   the CPT visibility model.
 */
final class BoatPostType
{
    public const POST_TYPE = 'maradigma_boat';

    private const META_BOAT_ID = '_maradigma_boat_id';
    private const META_ATTACHMENT_BOAT_ID = '_maradigma_boat_id';

    /**
     * Registers the component's WordPress hooks.
     */
    public static function init(): void
    {
        \add_action('init', [__CLASS__, 'register'], 1);

        if (\is_admin()) {
            \add_filter('manage_edit-' . self::POST_TYPE . '_columns', [__CLASS__, 'addAdminColumns']);
            \add_action('manage_' . self::POST_TYPE . '_posts_custom_column', [__CLASS__, 'renderAdminColumns'], 10, 2);
            \add_action('admin_enqueue_scripts', [__CLASS__, 'enqueueAdminColumnsCss']);
        }
    }

    /**
     * Registers the component with WordPress.
     */
    public static function register(): void
    {
        if (\post_type_exists(self::POST_TYPE)) {
            return;
        }

        $settings = \Maradigma\SettingsPage::getSettings();
        $boatPagesSyncEnabled = !empty($settings['enable_boat_pages_sync']);

        $labels = [
            'name'               => \__('Boats', 'maradigma'),
            'singular_name'      => \__('Boat', 'maradigma'),
            'add_new'            => \__('Add new', 'maradigma'),
            'add_new_item'       => \__('Add new boat', 'maradigma'),
            'edit_item'          => \__('Edit boat', 'maradigma'),
            'new_item'           => \__('New boat', 'maradigma'),
            'view_item'          => \__('View boat', 'maradigma'),
            'search_items'       => \__('Search boats', 'maradigma'),
            'not_found'          => \__('No boats found', 'maradigma'),
            'not_found_in_trash' => \__('No boats found in trash', 'maradigma'),
            'all_items'          => \__('Boat pages', 'maradigma'),
            'menu_name'          => \__('Boats', 'maradigma'),
        ];

        \register_post_type(self::POST_TYPE, [
            'labels' => $labels,

            // Admin / internal management
            'show_ui' => true,
            'show_in_menu' => 'maradigma-settings',
            'menu_icon' => 'dashicons-admin-site-alt3',
            'supports' => ['title', 'editor', 'excerpt', 'thumbnail', 'custom-fields'],

            /**
             * Keep the CPT stable for WordPress/editor integrations.
             * The real public namespace capture problem is the rewrite slug,
             * so when sync is OFF we disable only rewrite-based routing.
             */
            'public' => true,
            'publicly_queryable' => true,
            'show_in_rest' => true,
            'has_archive' => true,
            'query_var' => true,

            /**
             * CRITICAL:
             * - When sync is ON, allow the plugin rewrite base.
             * - When sync is OFF, disable CPT rewrites completely so the plugin
             *   does not claim /boats/... or any other configured slug.
             */
            'rewrite' => $boatPagesSyncEnabled
                ? [
                    'slug' => 'boats',
                    'with_front' => false,
                ]
                : false,

            /**
             * Search/nav menu visibility can still reflect whether public boat pages
             * are meant to be exposed.
             */
            'exclude_from_search' => !$boatPagesSyncEnabled,
            'show_in_nav_menus' => $boatPagesSyncEnabled,
        ]);
    }

    /**
     * Adds admin columns.
     */
    public static function addAdminColumns(array $columns): array
    {
        $out = [];

        foreach ($columns as $key => $label) {
            $out[$key] = $label;

            if ($key === 'title') {
                $out['maradigma_lang'] = \__('Lang', 'maradigma');
                $out['maradigma_images'] = \__('Images', 'maradigma');
            }
        }

        if (!isset($out['maradigma_lang'])) {
            $out['maradigma_lang'] = \__('Lang', 'maradigma');
        }

        if (!isset($out['maradigma_images'])) {
            $out['maradigma_images'] = \__('Images', 'maradigma');
        }

        return $out;
    }

    /**
     * Renders admin columns.
     */
    public static function renderAdminColumns(string $column, int $postId): void
    {
        if ($postId <= 0 || \get_post_type($postId) !== self::POST_TYPE) {
            if ($column === 'maradigma_lang') {
                echo '<span class="maradigma-lang-pill maradigma-lang-pill--empty">—</span>';
            } elseif ($column === 'maradigma_images') {
                echo '<span class="maradigma-images-pill maradigma-images-pill--empty">—</span>';
            }
            return;
        }

        if ($column === 'maradigma_lang') {
            $lang = self::getPostLanguageSlug($postId);
            $code = self::langSlugToCode($lang);

            if ($code === '') {
                echo '<span class="maradigma-lang-pill maradigma-lang-pill--empty">—</span>';
                return;
            }

            echo '<span class="maradigma-lang-pill" title="' . \esc_attr($code) . '">' . \esc_html($code) . '</span>';
            return;
        }

        if ($column === 'maradigma_images') {
            $boatId = self::getExternalBoatIdFromPost($postId);

            if ($boatId === '') {
                echo '<span class="maradigma-images-pill maradigma-images-pill--empty">—</span>';
                return;
            }

            $count = self::countCachedImagesByBoatId($boatId);

            echo '<span class="maradigma-images-pill" title="' . \esc_attr__('Cached images in Media Library', 'maradigma') . '">'
                . \esc_html((string) $count)
                . '</span>';

            return;
        }
    }

    /**
     * Enqueues styles for the custom boat administration columns.
     */
    public static function enqueueAdminColumnsCss(string $hookSuffix): void
    {
        if ($hookSuffix !== 'edit.php' || !\function_exists('get_current_screen')) {
            return;
        }

        $screen = \get_current_screen();
        if (!$screen || ($screen->post_type ?? '') !== self::POST_TYPE || ($screen->base ?? '') !== 'edit') {
            return;
        }

        \wp_enqueue_style(
            'maradigma-boat-post-type-columns',
            MARADIGMA_PLUGIN_URL . 'assets/css/admin/boat-post-type-columns.css',
            [],
            MARADIGMA_PLUGIN_VERSION
        );
    }

    /**
     * Returns external boat ID from post.
     */
    private static function getExternalBoatIdFromPost(int $postId): string
    {
        if ($postId <= 0) {
            return '';
        }

        return \trim((string) \get_post_meta($postId, self::META_BOAT_ID, true));
    }

    /**
     * Counts cached images by boat ID.
     */
    private static function countCachedImagesByBoatId(string $boatId): int
    {
        $boatId = \trim($boatId);
        if ($boatId === '') {
            return 0;
        }

        $q = new \WP_Query([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'fields' => 'ids',
            'posts_per_page' => 1,
            'no_found_rows' => false,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            // Cached images belong to the boat, not to a language.
            'lang' => '',
            'meta_query' => [
                [
                    'key' => self::META_ATTACHMENT_BOAT_ID,
                    'value' => $boatId,
                ],
            ],
        ]);

        return isset($q->found_posts) ? (int) $q->found_posts : 0;
    }

    /**
     * Returns post language slug.
     */
    private static function getPostLanguageSlug(int $postId): string
    {
        if (\function_exists('pll_get_post_language')) {
            $pl = (string) \pll_get_post_language($postId, 'slug');
            $pl = \strtolower(\trim($pl));
            if ($pl !== '') {
                return $pl;
            }
        }

        if (\has_filter('wpml_element_language_code')) {
            $code = \apply_filters('wpml_element_language_code', null, [
                'element_id' => $postId,
                'element_type' => 'post_' . (string) \get_post_type($postId),
            ]);

            if (\is_string($code)) {
                $code = \strtolower(\trim($code));
                if ($code !== '') {
                    return $code;
                }
            }
        }

        $code = \Maradigma\Support\MultilangAdapter::getPostLanguage($postId);
        $code = \strtolower(\trim($code));
        if ($code !== '') {
            return $code;
        }

        return '';
    }

    /**
     * Converts a language slug into its normalized language code.
     */
    private static function langSlugToCode(string $lang): string
    {
        $lang = \strtolower(\trim($lang));
        if ($lang === '') {
            return '';
        }

        $lang = (string) (\preg_split('/[_-]/', $lang)[0] ?? $lang);
        $lang = \strtolower(\trim($lang));

        return $lang !== '' ? \strtoupper($lang) : '';
    }
}

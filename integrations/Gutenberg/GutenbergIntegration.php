<?php

declare(strict_types=1);

namespace Maradigma\Integrations\Gutenberg;

if (!defined('ABSPATH')) {
    exit;
}

use Maradigma\BoatCardEngine;
use Maradigma\BoatPostType;
use Maradigma\Cache;
use Maradigma\MetaManager;
use Maradigma\SettingsPage;
use Maradigma\Support\BoatAdditionalServicesRenderer;
use Maradigma\Support\BoatPricesRenderer;
use Maradigma\Support\Logger;
use Maradigma\Support\MultilangAdapter;

/**
 * Gutenberg integration for Maradigma.
 *
 * The integration registers dynamic server-rendered blocks that reuse the existing
 * Maradigma shortcodes and renderers. This keeps Gutenberg aligned with the same
 * source of truth used by Elementor, shortcodes, templates, API cache, languages
 * and frontend assets.
 *
 * It also provides an editable Gutenberg master template for boat detail pages.
 * The master template is stored as a private Maradigma CPT and can be edited from
 * WordPress with the native block editor.
 */
final class GutenbergIntegration
{
    public const TEMPLATE_POST_TYPE = 'maradigma_gb_tpl';

    private const CATEGORY_SLUG = 'maradigma';
    private const OPTION_MASTER_TEMPLATE_ID = 'maradigma_gutenberg_master_template_id';

    /**
     * Preview source used only while editing the Gutenberg master template.
     *
     * This stores a WordPress post ID, never a remote Maradigma boat ID. The
     * actual remote boat identifier remains centralized in MetaManager.
     */
    private const META_TEMPLATE_PREVIEW_BOAT_POST_ID = '_maradigma_preview_boat_post_id';

    /**
     * Global fallback preview post ID for the Gutenberg master template editor.
     *
     * This stores a WordPress post ID, never a remote Maradigma boat ID.
     */
    private const OPTION_PREVIEW_BOAT_POST_ID = 'maradigma_gutenberg_preview_boat_post_id';

    private const META_LAYOUT_BUILDER = '_maradigma_layout_builder';
    private const META_GUTENBERG_SEEDED = '_maradigma_gutenberg_seeded';
    private const META_GUTENBERG_CONTENT_HASH = '_maradigma_gutenberg_content_hash';
    private const META_GUTENBERG_TEMPLATE_HASH = '_maradigma_gutenberg_template_hash';
    private const META_GUTENBERG_CUSTOM_LAYOUT = '_maradigma_gutenberg_custom_layout';

    /**
     * @var bool Prevents duplicate registration when init/register are both called.
     */
    private static bool $registered = false;

    /**
     * @var bool Prevents recursive post updates while seeding the default Gutenberg template.
     */
    private static bool $seedingDefaultBoatContent = false;

    /**
     * @var bool Prevents duplicate asset registration/inline config in the same request.
     */
    private static bool $editorAssetsRegistered = false;

    /**
     * Bootstrap entry point used by the main Plugin loader.
     */
    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        self::$registered = true;

        if (!\function_exists('register_block_type')) {
            return;
        }

        \add_filter('block_categories_all', [__CLASS__, 'registerBlockCategory'], 10, 2);

        \add_action('init', [__CLASS__, 'registerTemplatePostType'], 9);
        \add_action('init', [__CLASS__, 'registerEditorAssets'], 10);
        \add_action('init', [__CLASS__, 'registerBlocks'], 20);
        \add_action('init', [__CLASS__, 'registerBlockPatterns'], 30);

        /**
         * Gutenberg boat layout automation.
         *
         * These callbacks are guarded by the selected builder setting, so they only
         * run when Boat page builder = Gutenberg.
         */
        \add_filter('default_content', [__CLASS__, 'filterDefaultBoatContent'], 10, 2);
        \add_action('wp_after_insert_post', [__CLASS__, 'maybeSeedDefaultBoatPostContent'], 20, 4);
    }

    /**
     * Backward-compatible alias if the plugin loader calls init().
     */
    public static function init(): void
    {
        self::register();
    }

    /**
     * Registers the internal CPT used to store editable Gutenberg master templates.
     */
    public static function registerTemplatePostType(): void
    {
        if (\post_type_exists(self::TEMPLATE_POST_TYPE)) {
            return;
        }

        \register_post_type(self::TEMPLATE_POST_TYPE, [
            'labels' => [
                'name'               => \__('Gutenberg template', 'maradigma'),
                'singular_name'      => \__('Gutenberg template', 'maradigma'),
                'add_new'            => \__('Add new', 'maradigma'),
                'add_new_item'       => \__('Add new Gutenberg template', 'maradigma'),
                'edit_item'          => \__('Edit Gutenberg template', 'maradigma'),
                'new_item'           => \__('New Gutenberg template', 'maradigma'),
                'view_item'          => \__('View Gutenberg template', 'maradigma'),
                'search_items'       => \__('Search Gutenberg templates', 'maradigma'),
                'not_found'          => \__('No Gutenberg templates found', 'maradigma'),
                'not_found_in_trash' => \__('No Gutenberg templates found in trash', 'maradigma'),
                'all_items'          => \__('Gutenberg template', 'maradigma'),
                'menu_name'          => \__('Gutenberg template', 'maradigma'),
            ],
            'public'              => false,
            'publicly_queryable'  => false,
            'exclude_from_search' => true,
            'show_ui'             => true,
            'show_in_menu'        => self::shouldShowTemplateMenu() ? 'maradigma-settings' : false,
            'show_in_rest'        => true,
            'query_var'           => false,
            'rewrite'             => false,
            'capability_type'     => 'post',
            'capabilities'        => [
                'create_posts' => 'do_not_allow',
            ],
            'map_meta_cap'        => true,
            'supports'            => ['title', 'editor', 'revisions'],
            'menu_icon'           => 'dashicons-layout',
        ]);
    }

    /**
     * Adds the Maradigma category to the block inserter.
     *
     * @param array<int,array<string,mixed>> $categories
     * @param mixed                         $post
     *
     * @return array<int,array<string,mixed>>
     */
    public static function registerBlockCategory(array $categories, $post = null): array
    {
        foreach ($categories as $category) {
            if (($category['slug'] ?? '') === self::CATEGORY_SLUG) {
                return $categories;
            }
        }

        $categories[] = [
            'slug'  => self::CATEGORY_SLUG,
            'title' => \__('Maradigma', 'maradigma'),
            'icon'  => null,
        ];

        return $categories;
    }

    /**
     * Registers the default Maradigma Gutenberg patterns.
     *
     * This allows users to manually insert the default boat template again if they
     * delete it or want to use it on a normal page.
     */
    public static function registerBlockPatterns(): void
    {
        if (!\function_exists('register_block_pattern')) {
            return;
        }

        if (\function_exists('register_block_pattern_category')) {
            \register_block_pattern_category(
                self::CATEGORY_SLUG,
                [
                    'label' => \__('Maradigma', 'maradigma'),
                ]
            );
        }

        \register_block_pattern(
            'maradigma/boat-detail-template',
            [
                'title'       => \__('Maradigma Boat Detail Template', 'maradigma'),
                'description' => \__('Default editable Gutenberg template for Maradigma boat detail pages.', 'maradigma'),
                'categories'  => [self::CATEGORY_SLUG],
                'content'     => self::getDefaultBoatTemplateContent(),
            ]
        );
    }

    /**
     * Creates/returns the editable Gutenberg master template post.
     */
    public static function ensureGutenbergMasterTemplate(): int
    {
        if (!\post_type_exists(self::TEMPLATE_POST_TYPE)) {
            self::registerTemplatePostType();
        }

        $templateId = (int) \get_option(self::OPTION_MASTER_TEMPLATE_ID, 0);

        if ($templateId > 0) {
            $post = \get_post($templateId);
            if ($post instanceof \WP_Post && $post->post_type === self::TEMPLATE_POST_TYPE) {
                if (\trim((string) $post->post_content) === '') {
                    \wp_update_post([
                        'ID'           => $templateId,
                        'post_content' => self::getDefaultBoatTemplateContent(),
                    ]);
                }

                return $templateId;
            }

            \delete_option(self::OPTION_MASTER_TEMPLATE_ID);
            $templateId = 0;
        }

        $inserted = \wp_insert_post([
            'post_title'   => \__('Maradigma - Boat Template (Gutenberg)', 'maradigma'),
            'post_type'    => self::TEMPLATE_POST_TYPE,
            'post_status'  => 'publish',
            'post_content' => self::getDefaultBoatTemplateContent(),
        ], true);

        if (\is_wp_error($inserted) || (int) $inserted <= 0) {
            return 0;
        }

        $templateId = (int) $inserted;
        \update_option(self::OPTION_MASTER_TEMPLATE_ID, $templateId, false);

        return $templateId;
    }

    /**
     * Returns the editable Gutenberg master template ID, if it exists.
     */
    public static function getGutenbergMasterTemplateId(): int
    {
        $templateId = (int) \get_option(self::OPTION_MASTER_TEMPLATE_ID, 0);
        if ($templateId <= 0) {
            return 0;
        }

        $post = \get_post($templateId);
        if (!$post instanceof \WP_Post || $post->post_type !== self::TEMPLATE_POST_TYPE) {
            return 0;
        }

        return $templateId;
    }

    /**
     * Returns the editable Gutenberg master template content, with plugin fallback.
     */
    public static function getGutenbergMasterTemplateContent(): string
    {
        $templateId = self::ensureGutenbergMasterTemplate();
        if ($templateId > 0) {
            $post = \get_post($templateId);
            if ($post instanceof \WP_Post) {
                $content = \trim((string) $post->post_content);
                if ($content !== '') {
                    return (string) $post->post_content;
                }
            }
        }

        return self::getDefaultBoatTemplateContent();
    }

    /**
     * Returns the edit URL for the Gutenberg master template.
     */
    public static function getGutenbergMasterTemplateEditUrl(): string
    {
        $templateId = self::ensureGutenbergMasterTemplate();
        if ($templateId <= 0) {
            return '';
        }

        return \admin_url('post.php?post=' . $templateId . '&action=edit');
    }

    /**
     * Replaces the editable Gutenberg master template with the current plugin default.
     */
    public static function resetGutenbergMasterTemplateToDefault(): int
    {
        $templateId = self::ensureGutenbergMasterTemplate();

        if ($templateId <= 0) {
            return 0;
        }

        $updated = \wp_update_post([
            'ID'           => $templateId,
            'post_content' => self::getDefaultBoatTemplateContent(),
        ], true);

        if (\is_wp_error($updated) || (int) $updated <= 0) {
            return 0;
        }

        return (int) $updated;
    }

    /**
     * Applies the current Gutenberg master template to a boat post.
     *
     * Modes:
     * - seed_missing: only if post_content is empty.
     * - overwrite: overwrite only if the previous content is still managed/unchanged.
     * - force: overwrite always unless the post is invalid.
     */
    public static function applyGutenbergTemplateToBoatPost(int $postId, string $mode = 'seed_missing'): bool
    {
        if ($postId <= 0 || !self::isBoatPostType((string) \get_post_type($postId))) {
            return false;
        }

        $mode = \sanitize_key($mode);
        if (!\in_array($mode, ['seed_missing', 'overwrite', 'force'], true)) {
            $mode = 'seed_missing';
        }

        $post = \get_post($postId);
        if (!$post instanceof \WP_Post) {
            return false;
        }

        $currentContent = (string) $post->post_content;
        $templateContent = self::getGutenbergMasterTemplateContent();

        if (\trim($templateContent) === '') {
            return false;
        }

        if ($mode === 'seed_missing' && \trim($currentContent) !== '') {
            return false;
        }

        if ($mode === 'overwrite' && !self::shouldOverwriteGutenbergContent($postId, $currentContent)) {
            return false;
        }

        self::$seedingDefaultBoatContent = true;

        try {
            global $wpdb;

            $updated = $wpdb->update(
                $wpdb->posts,
                [
                    'post_content'          => $templateContent,
                    'post_modified'         => \current_time('mysql'),
                    'post_modified_gmt'     => \current_time('mysql', true),
                    'post_content_filtered' => '',
                ],
                ['ID' => $postId],
                ['%s', '%s', '%s', '%s'],
                ['%d']
            );

            if ($updated === false) {
                return false;
            }

            $contentHash = self::hashGutenbergTemplateContent($templateContent);
            \clean_post_cache($postId);
            $storedPost = \get_post($postId);
            $storedContent = $storedPost instanceof \WP_Post ? (string) $storedPost->post_content : '';

            if (!\hash_equals($contentHash, self::hashGutenbergTemplateContent($storedContent))) {
                return false;
            }

            \update_post_meta($postId, self::META_LAYOUT_BUILDER, 'gutenberg');
            \update_post_meta($postId, self::META_GUTENBERG_SEEDED, '1');
            \update_post_meta($postId, self::META_GUTENBERG_CONTENT_HASH, $contentHash);
            \update_post_meta($postId, self::META_GUTENBERG_TEMPLATE_HASH, $contentHash);

            /**
             * Avoid Elementor/Gutenberg collisions: keep _elementor_data as backup,
             * but disable Elementor rendering for this boat.
             */
            \delete_post_meta($postId, '_elementor_edit_mode');

            \do_action('maradigma_gutenberg_template_applied_to_boat', $postId, self::ensureGutenbergMasterTemplate(), $mode);

            return true;
        } catch (\Throwable $e) {
            self::logException($e, 'GutenbergIntegration::applyGutenbergTemplateToBoatPost');
            return false;
        } finally {
            self::$seedingDefaultBoatContent = false;
        }
    }

    /**
     * Returns Gutenberg template apply skip reason.
     */
    public static function getGutenbergTemplateApplySkipReason(int $postId, string $mode = 'seed_missing'): string
    {
        if ($postId <= 0 || !self::isBoatPostType((string) \get_post_type($postId))) {
            return 'invalid_boat_post';
        }

        $mode = \sanitize_key($mode);
        if (!\in_array($mode, ['seed_missing', 'overwrite', 'force'], true)) {
            $mode = 'seed_missing';
        }

        $post = \get_post($postId);
        if (!$post instanceof \WP_Post) {
            return 'post_not_found';
        }

        $currentContent = (string) $post->post_content;
        $templateContent = self::getGutenbergMasterTemplateContent();

        if (\trim($templateContent) === '') {
            return 'empty_gutenberg_master_template';
        }

        if ($mode === 'seed_missing' && \trim($currentContent) !== '') {
            return 'seed_missing_has_content';
        }

        if ($mode === 'overwrite') {
            return self::getGutenbergOverwriteBlockReason($postId, $currentContent);
        }

        return '';
    }

    /**
     * Determines whether the post content matches the current Gutenberg master template.
     */
    public static function postMatchesGutenbergMasterTemplate(int $postId): bool
    {
        if ($postId <= 0 || !self::isBoatPostType((string) \get_post_type($postId))) {
            return false;
        }

        $templateContent = self::getGutenbergMasterTemplateContent();
        if (\trim($templateContent) === '') {
            return false;
        }

        \clean_post_cache($postId);
        $storedPost = \get_post($postId);
        $storedContent = $storedPost instanceof \WP_Post ? (string) $storedPost->post_content : '';

        return \hash_equals(
            self::hashGutenbergTemplateContent($templateContent),
            self::hashGutenbergTemplateContent($storedContent)
        );
    }

    /**
     * @return array<string,mixed>
     */
    public static function getGutenbergTemplatePersistenceDebug(int $postId): array
    {
        $templateContent = self::getGutenbergMasterTemplateContent();
        \clean_post_cache($postId);
        $storedPost = $postId > 0 ? \get_post($postId) : null;
        $storedContent = $storedPost instanceof \WP_Post ? (string) $storedPost->post_content : '';

        return [
            'template_length' => \strlen($templateContent),
            'stored_length'   => \strlen($storedContent),
            'template_hash'   => self::hashGutenbergTemplateContent($templateContent),
            'stored_hash'     => self::hashGutenbergTemplateContent($storedContent),
            'template_raw_hash' => \md5($templateContent),
            'stored_raw_hash'   => \md5($storedContent),
        ];
    }

    /**
     * Determines whether a non-forced overwrite is safe.
     */
    private static function shouldOverwriteGutenbergContent(int $postId, string $currentContent): bool
    {
        return self::getGutenbergOverwriteBlockReason($postId, $currentContent) === '';
    }

    /**
     * Returns Gutenberg overwrite block reason.
     */
    private static function getGutenbergOverwriteBlockReason(int $postId, string $currentContent): string
    {
        if ((bool) \get_post_meta($postId, self::META_GUTENBERG_CUSTOM_LAYOUT, true)) {
            return 'custom_gutenberg_layout';
        }

        if (\trim($currentContent) === '') {
            return '';
        }

        $layoutBuilder = (string) \get_post_meta($postId, self::META_LAYOUT_BUILDER, true);

        if ($layoutBuilder !== 'gutenberg') {
            return (bool) \get_post_meta($postId, '_maradigma_elementor_custom_layout', true)
                ? 'custom_elementor_layout'
                : '';
        }

        return '';
    }

    /**
     * Calculates the hash for Gutenberg template content.
     */
    public static function hashGutenbergTemplateContent(string $content): string
    {
        $content = \str_replace(["\r\n", "\r"], "\n", $content);
        $content = \rtrim($content);

        return \md5($content);
    }

    /**
     * Provides default Gutenberg content when creating a new Maradigma boat post manually.
     *
     * @param string   $content
     * @param \WP_Post $post
     *
     * @return string
     */
    public static function filterDefaultBoatContent(string $content, \WP_Post $post): string
    {
        if (self::getConfiguredLayoutBuilder() !== 'gutenberg') {
            return $content;
        }

        if (!self::isBoatPostType((string) $post->post_type)) {
            return $content;
        }

        if (\trim($content) !== '') {
            return $content;
        }

        return self::getGutenbergMasterTemplateContent();
    }

    /**
     * Seeds the Gutenberg master template into synced boat posts when they are empty.
     *
     * @param int           $postId
     * @param \WP_Post      $post
     * @param bool          $update
     * @param \WP_Post|null $postBefore
     */
    public static function maybeSeedDefaultBoatPostContent(int $postId, \WP_Post $post, bool $update, ?\WP_Post $postBefore = null): void
    {
        if (self::$seedingDefaultBoatContent) {
            return;
        }

        if (self::getConfiguredLayoutBuilder() !== 'gutenberg') {
            return;
        }

        if ($postId <= 0 || !self::isBoatPostType((string) $post->post_type)) {
            return;
        }

        if ($post->post_status === 'auto-draft') {
            return;
        }

        if (\defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (\wp_is_post_revision($postId) || \wp_is_post_autosave($postId)) {
            return;
        }

        if (\trim((string) $post->post_content) !== '') {
            return;
        }

        self::applyGutenbergTemplateToBoatPost($postId, 'seed_missing');
    }

    /**
     * Returns the plugin fallback editable Gutenberg template for boat detail pages.
     */
    public static function getDefaultBoatTemplateContent(): string
    {
        return implode("\n", [
    '<!-- wp:group {"className":"maradigma-boat-template","layout":{"type":"constrained"}} -->',
    '<div class="wp-block-group maradigma-boat-template">',
    '',
    '    <!-- wp:maradigma/boat-title {"tag":"h1"} /-->',
    '',
    '    <!-- wp:maradigma/boat-gallery {"layout":"grid","columns":"3","enable_lightbox":"1","max_images":"12","gap":"10","radius":"10"} /-->',
    '',
    '    <!-- wp:maradigma/boat-description {"type":"auto","allow_html":"1","max_words":"0"} /-->',
    '',
    '    <!-- wp:columns {"className":"maradigma-boat-template__summary"} -->',
    '    <div class="wp-block-columns maradigma-boat-template__summary">',
    '',
    '        <!-- wp:column {"width":"70%","className":"maradigma-boat-template__specs"} -->',
    '        <div class="wp-block-column maradigma-boat-template__specs" style="flex-basis:70%">',
    '',
    '            <!-- wp:maradigma/boat-specs {"layout":"two_cols","show_labels":"1","show_icons":"1","fields":"boat_capacity,boat_length,boat_beam,boat_builder,boat_base_port_name"} /-->',
    '',
    '        </div>',
    '        <!-- /wp:column -->',
    '',
    '        <!-- wp:column {"width":"30%","className":"maradigma-boat-template__actions"} -->',
    '        <div class="wp-block-column maradigma-boat-template__actions" style="flex-basis:30%">',
    '',
    '            <!-- wp:maradigma/boat-pdf-download {"button_text":"Download PDF","open_in_new_tab":"1","force_download":"0"} /-->',
    '',
    '            <!-- wp:maradigma/boat-booking {"button_text":"Book now","calendar_display":"inline","calendar_months":"1","calendar_selection_mode":"range","show_schedule_text":"1","show_promo_code":"1","show_children_included":"1","free_additional_label":"free"} /-->',
    '',
    '        </div>',
    '        <!-- /wp:column -->',
    '',
    '    </div>',
    '    <!-- /wp:columns -->',
    '',
    '    <!-- wp:maradigma/boat-price {"title":"Prices","show_title":true,"layout":"cards","range_mode":"dates_short","order_by":"date_from_asc"} /-->',
    '',
    '    <!-- wp:maradigma/boat-equipments {"title":"Equipments","show_title":"1","show_icon":"0","sort":"1"} /-->',
    '',
    '    <!-- wp:maradigma/boat-included {"title":"Included","show_title":"1","show_tick_icon":"1","sort":"1"} /-->',
    '',
    '    <!-- wp:maradigma/boat-not-included {"title":"Not included","show_title":"1","show_cross_icon":"1","sort":"1"} /-->',
    '',
    '    <!-- wp:maradigma/boat-additional-services {"title":"Additional services","show_title":true} /-->',
    '',
    '    <!-- wp:maradigma/boat-calendar {"months":"12","start_month":"current","show_legend":"1","option_statuses":"4","include_booking_status":"1"} /-->',
    '',
    '</div>',
    '<!-- /wp:group -->',
]);
    }

    /**
     * Registers the shared editor assets used by Maradigma Gutenberg blocks.
     *
     * WordPress attaches these handles through register_block_type(), so the
     * block editor follows the native block metadata asset flow instead of a
     * global admin enqueue.
     */
    public static function registerEditorAssets(): void
    {
        try {
            if (self::$editorAssetsRegistered) {
                return;
            }

            self::$editorAssetsRegistered = true;

            $baseDir = \defined('MARADIGMA_PLUGIN_DIR')
                ? \trailingslashit((string) MARADIGMA_PLUGIN_DIR)
                : \trailingslashit(\plugin_dir_path(MARADIGMA_PLUGIN_FILE));

            $baseUrl = \defined('MARADIGMA_PLUGIN_URL')
                ? \trailingslashit((string) MARADIGMA_PLUGIN_URL)
                : \trailingslashit(\plugin_dir_url(MARADIGMA_PLUGIN_FILE));

            /**
             * Keep these paths explicit.
             *
             * Do not rely on build/dist helpers here because these files are editor-only
             * Gutenberg assets and may not exist in the dist folder yet.
             */
            $jsRelativePath  = 'assets/js/editor/maradigma-gutenberg-blocks.js';
            $cssRelativePath = 'assets/css/admin/maradigma-gutenberg-blocks.css';

            $jsPath  = $baseDir . $jsRelativePath;
            $cssPath = $baseDir . $cssRelativePath;

            if (\is_readable($jsPath)) {
                \wp_register_script(
                    'maradigma-gutenberg-blocks',
                    $baseUrl . $jsRelativePath,
                    [
                        'wp-blocks',
                        'wp-block-editor',
                        'wp-components',
                        'wp-data',
                        'wp-edit-post',
                        'wp-element',
                        'wp-i18n',
                        'wp-plugins',
                        'wp-server-side-render',
                    ],
                    (string) \filemtime($jsPath),
                    true
                );

                if (\is_admin()) {
                    \wp_add_inline_script(
                        'maradigma-gutenberg-blocks',
                        'window.MaradigmaRemoteSelect2 = ' . \wp_json_encode(self::getEditorRemoteSelectConfig()) . ';' . "\n" .
                        'window.MaradigmaGutenbergBlocks = ' . \wp_json_encode([
                            'blockMetadata'   => self::getEditorBlockMetadata(),
                            'boatCardOptions' => self::getEditorBoatCardOptions(),
                            'boatBinding'     => [
                                'bindings' => [
                                    [
                                        'postType'            => BoatPostType::POST_TYPE,
                                        'boatIdMetaKey'       => MetaManager::META_CPT_BOAT_ID,
                                        'customLayoutMetaKey' => MetaManager::META_CPT_ELEMENTOR_CUSTOM_LAYOUT,
                                    ],
                                    [
                                        'postTypes'           => MetaManager::getSupportedBoatBindingPostTypes(),
                                        'isBoatPageMetaKey'   => MetaManager::META_PAGE_IS_BOAT_PAGE,
                                        'boatIdMetaKey'       => MetaManager::META_PAGE_BOAT_ID,
                                    ],
                                ],
                            ],
                        ]) . ';',
                        'before'
                    );
                }

                if (\function_exists('wp_set_script_translations')) {
                    \wp_set_script_translations(
                        'maradigma-gutenberg-blocks',
                        'maradigma',
                        $baseDir . 'languages'
                    );
                }
            } else {
                self::logDebug('Gutenberg editor JS not readable: ' . $jsPath);
            }

            if (\is_readable($cssPath)) {
                \wp_register_style(
                    'maradigma-gutenberg-blocks',
                    $baseUrl . $cssRelativePath,
                    ['wp-edit-blocks'],
                    (string) \filemtime($cssPath)
                );
            } else {
                self::logDebug('Gutenberg editor CSS not readable: ' . $cssPath);
            }
        } catch (\Throwable $e) {
            self::logException($e, 'GutenbergIntegration::registerEditorAssets');
        }
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private static function getEditorBlockMetadata(): array
    {
        $base = \plugin_dir_path(MARADIGMA_PLUGIN_FILE) . 'integrations/Gutenberg/Blocks';
        $metadata = [];

        foreach (self::getGutenbergBlockSlugs() as $block) {
            $json = \trailingslashit($base) . $block . '/block.json';
            if (!\is_readable($json)) {
                continue;
            }

            $decoded = \json_decode((string) \file_get_contents($json), true);
            if (!\is_array($decoded)) {
                continue;
            }

            $name = isset($decoded['name']) ? (string) $decoded['name'] : '';
            if ($name === '') {
                continue;
            }

            $metadata[$name] = [
                'apiVersion'  => $decoded['apiVersion'] ?? 3,
                'title'       => $decoded['title'] ?? '',
                'category'    => $decoded['category'] ?? self::CATEGORY_SLUG,
                'icon'        => $decoded['icon'] ?? 'admin-site-alt3',
                'description' => $decoded['description'] ?? '',
                'attributes'  => $decoded['attributes'] ?? [],
                'supports'    => self::normalizeBlockSupports($decoded['supports'] ?? []),
            ];
        }

        return $metadata;
    }

    /**
     * @param mixed $supports
     * @return array<string,mixed>
     */
    private static function normalizeBlockSupports($supports): array
    {
        $supports = \is_array($supports) ? $supports : [];

        return \array_replace_recursive(
            self::getCommonBlockSupports(),
            $supports
        );
    }

    /**
     * @return array<int,array{label:string,value:string}>
     */
    private static function getEditorBoatCardOptions(): array
    {
        $cardOptions = [
            [
                'label' => \esc_html__('Default (plugin setting)', 'maradigma'),
                'value' => '',
            ],
        ];

        if (!\class_exists(\Maradigma\BoatCardRepository::class) || !\method_exists(\Maradigma\BoatCardRepository::class, 'listCards')) {
            return $cardOptions;
        }

        $cards = \Maradigma\BoatCardRepository::listCards();

        foreach ($cards as $cardId => $card) {
            $cardId = (string) $cardId;
            if ($cardId === '') {
                continue;
            }

            $cardName = \trim((string) ($card['name'] ?? $cardId));
            if ($cardName === '') {
                $cardName = $cardId;
            }

            $cardOptions[] = [
                'label' => \sprintf('%s (%s)', $cardName, $cardId),
                'value' => $cardId,
            ];
        }

        return $cardOptions;
    }

    /**
     * Returns the remote option lookup config consumed by native Gutenberg controls.
     *
     * The block editor script uses WordPress components such as FormTokenField and
     * ComboboxControl, so it only needs AJAX metadata. Loading the shared Select2
     * initializer in Gutenberg can mutate editor DOM on page load and destabilize
     * the block editor on pages that contain Maradigma blocks.
     *
     * @return array<string,mixed>
     */
    private static function getEditorRemoteSelectConfig(): array
    {
        return [
            'ajaxUrl' => \admin_url('admin-ajax.php'),
            'nonce'   => \wp_create_nonce('maradigma_admin'),
            'search'  => [
                'boatTypesAction' => 'maradigma_admin_search_boat_types',
                'tagsAction'      => 'maradigma_admin_search_tags',
                'buildersAction'  => 'maradigma_admin_search_builders',
                'builderByIdAction' => 'maradigma_admin_get_builder_by_id',
                'boatsAction'     => 'maradigma_admin_search_boats',
                'basePortsAction' => 'maradigma_admin_search_base_ports',
                'boatByIdAction'  => 'maradigma_admin_get_boat_by_id',
                'destinationsAction' => 'maradigma_admin_search_destinations',
            ],
            'context' => 'admin',
        ];
    }

    /**
     * Loads every block index.php. Each block file calls self::registerBlock().
     */
    public static function registerBlocks(): void
    {
        $base = \plugin_dir_path(MARADIGMA_PLUGIN_FILE) . 'integrations/Gutenberg/Blocks';

        $blocks = self::getGutenbergBlockSlugs();

        foreach ($blocks as $block) {
            $file = \trailingslashit($base) . $block . '/index.php';

            if (!\is_readable($file)) {
                continue;
            }

            try {
                require_once $file;
            } catch (\Throwable $e) {
                self::logException($e, 'GutenbergIntegration::registerBlocks:' . $block);
            }
        }
    }

    /**
     * @return array<int,string>
     */
    private static function getGutenbergBlockSlugs(): array
    {
        return [
            'boats-archive',
            'search',
            'boat-single',
            'boat-title',
            'boat-field',
            'boat-pax',
            'boat-length',
            'boat-beam',
            'boat-builder',
            'boat-base-port',
            'boat-main-image',
            'boat-gallery',
            'boat-videos',
            'boat-specs',
            'boat-description',
            'boat-price',
            'boat-equipments',
            'boat-included',
            'boat-not-included',
            'boat-additional-services',
            'boat-pdf-download',
            'boat-calendar',
            'boat-booking',
        ];
    }

    /**
     * Registers one block from its block.json metadata directory.
     *
     * @param string   $blockDir
     * @param callable $renderCallback
     */
    public static function registerBlock(string $blockDir, callable $renderCallback): void
    {
        if (!\function_exists('register_block_type')) {
            return;
        }

        $json = \trailingslashit($blockDir) . 'block.json';

        if (!\is_readable($json)) {
            return;
        }

        $slug = \basename($blockDir);

        $decoded = \json_decode((string) \file_get_contents($json), true);
        $supports = \is_array($decoded)
            ? self::normalizeBlockSupports($decoded['supports'] ?? [])
            : self::getCommonBlockSupports();

        $args = [
            'editor_script_handles' => ['maradigma-gutenberg-blocks'],
            'editor_style_handles'  => ['maradigma-gutenberg-blocks'],
            'supports'              => $supports,
            'render_callback'       => static function (array $attributes = [], string $content = '', $block = null) use ($renderCallback, $slug): string {
                if ($slug === 'boats-archive' && GutenbergIntegration::isEditorBlockRenderRequest()) {
                    return GutenbergIntegration::renderBlockWrapper(
                        GutenbergIntegration::renderEditorPlaceholder(
                            \__('Boats archive', 'maradigma'),
                            \__('Editor preview is rendered statically to keep Gutenberg responsive. The real boat listing renders on the frontend.', 'maradigma')
                        ),
                        'maradigma-gutenberg-block maradigma-gutenberg-block--' . \sanitize_html_class($slug)
                    );
                }

                $rendered = \wp_kses(
                    (string) \call_user_func($renderCallback, $attributes, $content, $block),
                    BoatCardEngine::getAllowedHtml()
                );

                return GutenbergIntegration::renderBlockWrapper(
                    $rendered,
                    'maradigma-gutenberg-block maradigma-gutenberg-block--' . \sanitize_html_class($slug)
                );
            },
        ];

        \register_block_type(
            $blockDir,
            $args
        );
    }

    /**
     * Determines whether editor block render request.
     */
    public static function isEditorBlockRenderRequest(): bool
    {
        if (\is_admin()) {
            return true;
        }

        if (!\defined('REST_REQUEST') || !REST_REQUEST) {
            return false;
        }

        $uri = isset($_SERVER['REQUEST_URI'])
            ? \sanitize_url((string) \wp_unslash($_SERVER['REQUEST_URI']))
            : '';

        return \strpos($uri, '/wp/v2/block-renderer/') !== false
            || \strpos($uri, '/wp/v2/block-renderer?') !== false;
    }

    /**
     * Renders editor placeholder.
     */
    public static function renderEditorPlaceholder(string $title, string $message): string
    {
        return '<div class="maradigma-gutenberg-placeholder">'
            . '<strong>' . \esc_html($title) . '</strong>'
            . '<p>' . \esc_html($message) . '</p>'
            . '</div>';
    }

    /**
     * Returns the native Gutenberg style supports shared by Maradigma blocks.
     *
     * @return array<string,mixed>
     */
    private static function getCommonBlockSupports(): array
    {
        return [
            'html'       => false,
            'color'      => [
                'text'                  => true,
                'background'            => true,
                'heading'               => true,
                'enableContrastChecker' => true,
            ],
            'typography' => [
                'fontSize'   => true,
                'lineHeight' => true,
            ],
            'spacing'    => [
                'margin'   => true,
                'padding'  => true,
                'blockGap' => true,
            ],
            'border'     => [
                'color'  => true,
                'radius' => true,
                'style'  => true,
                'width'  => true,
            ],
            'shadow'     => true,
        ];
    }

    /**
     * Renders block wrapper.
     */
    public static function renderBlockWrapper(string $content, string $className = ''): string
    {
        if (\trim($content) === '') {
            return '';
        }

        $extraAttributes = [];
        $className       = \trim($className);

        if ($className !== '') {
            $extraAttributes['class'] = $className;
        }

        if (\function_exists('get_block_wrapper_attributes')) {
            return '<div ' . \get_block_wrapper_attributes($extraAttributes) . '>' . $content . '</div>';
        }

        $classAttribute = $className !== ''
            ? ' class="' . \esc_attr($className) . '"'
            : '';

        return '<div' . $classAttribute . '>' . $content . '</div>';
    }

    /**
     * Returns selected attributes only.
     *
     * @param array<string,mixed> $attributes
     * @param array<int,string>   $allowed
     *
     * @return array<string,mixed>
     */
    public static function pickAttributes(array $attributes, array $allowed): array
    {
        $out = [];

        foreach ($allowed as $key) {
            if (!\array_key_exists($key, $attributes)) {
                continue;
            }

            $value = $attributes[$key];

            if ($value === null) {
                continue;
            }

            if (\is_string($value) && \trim($value) === '') {
                continue;
            }

            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * Resolves id/slug from explicit attributes, the current Gutenberg post context,
     * or a preview boat source when editing the Gutenberg master template.
     *
     * Important:
     * - The Gutenberg master template must never store a fixed boat id/slug.
     * - While editing the master template, a real synced boat post is used only as
     *   preview context.
     * - The real remote boat ID remains centralized in MetaManager::getBoundBoatIdForPost().
     *
     * @param array<string,mixed> $attributes
     * @param mixed               $block
     *
     * @return array<string,mixed>
     */
    public static function withContextIdentifier(array $attributes, $block = null): array
    {
        $slug = \trim((string) ($attributes['slug'] ?? ''));
        $id   = \trim((string) ($attributes['id'] ?? ''));

        if ($slug !== '' || $id !== '') {
            return $attributes;
        }

        $postId = self::resolveCurrentPostIdFromBlock($block);

        if ($postId <= 0 || !\class_exists(MetaManager::class)) {
            return $attributes;
        }

        /**
         * Gutenberg master template preview.
         *
         * The current post is the internal template CPT, not a real boat post. We
         * resolve a preview post first and then delegate the actual remote boat ID
         * lookup to MetaManager, matching the Elementor single-boat widget approach.
         */
        if (self::isGutenbergTemplatePost($postId)) {
            $previewBoatPostId = self::resolvePreviewBoatPostIdForTemplate($postId);

            if ($previewBoatPostId > 0) {
                $previewBoatId = \trim((string) MetaManager::getBoundBoatIdForPost($previewBoatPostId));

                if ($previewBoatId !== '') {
                    $attributes['id'] = $previewBoatId;
                    return $attributes;
                }
            }

            return $attributes;
        }

        /**
         * Normal rendering context.
         *
         * This supports:
         * - generated maradigma_boat CPT posts
         * - regular pages/posts linked through the Maradigma metabox
         * - any supported custom post type configured in SettingsPage/MetaManager
         */
        $boundBoatId = \trim((string) MetaManager::getBoundBoatIdForPost($postId));

        if ($boundBoatId !== '') {
            $attributes['id'] = $boundBoatId;
        }

        return $attributes;
    }

    /**
     * Renders an existing Maradigma shortcode safely.
     *
     * @param string              $tag
     * @param array<string,mixed> $attributes
     * @param string              $content
     *
     * @return string
     */
    public static function renderShortcode(string $tag, array $attributes = [], string $content = ''): string
    {
        $tag = \trim($tag);

        if ($tag === '' || !\function_exists('do_shortcode')) {
            return '';
        }

        $parts = [];

        foreach ($attributes as $key => $value) {
            $key = \sanitize_key((string) $key);

            if ($key === '') {
                continue;
            }

            if (\is_bool($value)) {
                $value = $value ? '1' : '0';
            } elseif (\is_array($value)) {
                $value = \implode(',', \array_filter(\array_map('strval', $value), static fn (string $v): bool => \trim($v) !== ''));
            } elseif (\is_object($value)) {
                continue;
            }

            if ($value === null) {
                continue;
            }

            $value = (string) $value;

            if ($value === '') {
                continue;
            }

            $parts[] = $key . '="' . \esc_attr($value) . '"';
        }

        $open = '[' . $tag . (!empty($parts) ? ' ' . \implode(' ', $parts) : '') . ']';

        if ($content !== '') {
            return (string) \do_shortcode($open . $content . '[/' . $tag . ']');
        }

        return (string) \do_shortcode($open);
    }

    /**
     * Renders the same search form currently available in Elementor, but as a
     * Gutenberg dynamic block.
     *
     * @param array<string,mixed> $attributes
     *
     * @return string
     */
    public static function renderSearchForm(array $attributes): string
    {
        $targetUrl = \trim((string) ($attributes['target_url'] ?? ''));

        $requestUri = isset($_SERVER['REQUEST_URI'])
            ? \sanitize_url((string) \wp_unslash($_SERVER['REQUEST_URI']))
            : '/';
        $action = $targetUrl !== ''
            ? $targetUrl
            : \home_url(\add_query_arg([], $requestUri));

        $showTerm     = !empty($attributes['show_term']);
        $showCapacity = !empty($attributes['show_capacity']);
        $showPrice    = !empty($attributes['show_price']);
        $showDates    = !empty($attributes['show_dates']);

        // Public search filters are read-only and intentionally shareable through the URL.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $term = isset($_GET['term'])
            ? \sanitize_text_field((string) \wp_unslash($_GET['term']))
            : '';
        $capacity = isset($_GET['boat_capacity']) ? \absint(\wp_unslash($_GET['boat_capacity'])) : 0;
        $minPrice = isset($_GET['min_price'])
            ? \sanitize_text_field((string) \wp_unslash($_GET['min_price']))
            : '';
        $maxPrice = isset($_GET['max_price'])
            ? \sanitize_text_field((string) \wp_unslash($_GET['max_price']))
            : '';
        $dateStart = isset($_GET['date_start'])
            ? \sanitize_text_field((string) \wp_unslash($_GET['date_start']))
            : '';
        $dateEnd = isset($_GET['date_end'])
            ? \sanitize_text_field((string) \wp_unslash($_GET['date_end']))
            : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        \ob_start();
        ?>
        <form class="maradigma-search-form" method="get" action="<?php echo \esc_url($action); ?>">
            <?php if ($showTerm) : ?>
                <div class="maradigma-search-field">
                    <label><?php echo \esc_html__('Search', 'maradigma'); ?></label>
                    <input type="text" name="term" value="<?php echo \esc_attr($term); ?>" />
                </div>
            <?php endif; ?>

            <?php if ($showCapacity) : ?>
                <div class="maradigma-search-field">
                    <label><?php echo \esc_html__('Pax', 'maradigma'); ?></label>
                    <input type="number" min="1" name="boat_capacity" value="<?php echo $capacity > 0 ? \esc_attr((string) $capacity) : ''; ?>" />
                </div>
            <?php endif; ?>

            <?php if ($showPrice) : ?>
                <div class="maradigma-search-field">
                    <label><?php echo \esc_html__('Min price', 'maradigma'); ?></label>
                    <input type="number" min="0" step="1" name="min_price" value="<?php echo \esc_attr($minPrice); ?>" />
                </div>

                <div class="maradigma-search-field">
                    <label><?php echo \esc_html__('Max price', 'maradigma'); ?></label>
                    <input type="number" min="0" step="1" name="max_price" value="<?php echo \esc_attr($maxPrice); ?>" />
                </div>
            <?php endif; ?>

            <?php if ($showDates) : ?>
                <div class="maradigma-search-field">
                    <label><?php echo \esc_html__('Start date', 'maradigma'); ?></label>
                    <input type="date" name="date_start" value="<?php echo \esc_attr($dateStart); ?>" />
                </div>

                <div class="maradigma-search-field">
                    <label><?php echo \esc_html__('End date', 'maradigma'); ?></label>
                    <input type="date" name="date_end" value="<?php echo \esc_attr($dateEnd); ?>" />
                </div>
            <?php endif; ?>

            <button type="submit" class="maradigma-search-submit">
                <?php echo \esc_html__('Search', 'maradigma'); ?>
            </button>
        </form>
        <?php

        return (string) \ob_get_clean();
    }

    /**
     * Loads boat details from the Maradigma cache layer.
     *
     * @param array<string,mixed> $attributes
     * @param mixed               $block
     * @param array<string,mixed> $options
     * @param array<int,string>   $requiredDataKeys
     *
     * @return array<string,mixed>|null
     */
    public static function loadBoatData(array $attributes, $block = null, array $options = [], array $requiredDataKeys = []): ?array
    {
        $attributes = self::withContextIdentifier($attributes, $block);

        $identifier = self::resolveIdentifierFromAttributes($attributes);

        if ($identifier === '') {
            return null;
        }

        if (!\class_exists(Cache::class)) {
            return null;
        }

        $cache = new Cache();

        try {
            $method = new \ReflectionMethod($cache, 'getBoatDetails');
            $argc   = $method->getNumberOfParameters();
        } catch (\Throwable $e) {
            $argc = 3;
        }

        $language = self::getCurrentLanguage();

        try {
            if ($argc >= 5) {
                $result = $cache->getBoatDetails($identifier, $language, $options, $requiredDataKeys, false);
            } elseif ($argc >= 4) {
                $result = $cache->getBoatDetails($identifier, $language, $options, $requiredDataKeys);
            } else {
                $result = $cache->getBoatDetails($identifier, $language, $options);
            }
        } catch (\Throwable $e) {
            self::logException($e, 'GutenbergIntegration::loadBoatData');

            return null;
        }

        $data = \is_array($result['data'] ?? null) ? $result['data'] : null;

        return $data ?: null;
    }

    /**
     * @param array<string,mixed> $attributes
     *
     * @return string
     */
    private static function resolveIdentifierFromAttributes(array $attributes): string
    {
        $slug = \trim((string) ($attributes['slug'] ?? ''));

        if ($slug !== '') {
            return $slug;
        }

        return \trim((string) ($attributes['id'] ?? ''));
    }

    /**
     * Returns the current language code expected by the Maradigma API.
     */
    public static function getCurrentLanguage(): string
    {
        if (\class_exists(MultilangAdapter::class)) {
            $current = \strtoupper(\trim((string) MultilangAdapter::getCurrentLanguage()));

            if ($current !== '') {
                return $current;
            }
        }

        if (\class_exists(SettingsPage::class)) {
            $settings = SettingsPage::getSettings();
            $language = \strtoupper(\trim((string) ($settings['default_language'] ?? 'EN')));

            if ($language !== '') {
                $parts    = \preg_split('/[_-]/', $language);
                $language = \strtoupper((string) ($parts[0] ?? 'EN'));

                return $language !== '' ? $language : 'EN';
            }
        }

        $locale   = (string) \get_locale();
        $parts    = \preg_split('/[_-]/', $locale);
        $language = \strtoupper((string) ($parts[0] ?? 'EN'));

        return $language !== '' ? $language : 'EN';
    }

    /**
     * Renders a practical Gutenberg version of the Elementor Boat Prices widget.
     *
     * @param array<string,mixed> $attributes
     * @param mixed               $block
     *
     * @return string
     */
    public static function renderBoatPrice(array $attributes, $block = null): string
    {
        $data = self::loadBoatData(
            $attributes,
            $block,
            ['expand' => ['service_prices']],
            ['prices']
        );

        if (!$data) {
            return self::renderNotice(\__('Select a Maradigma boat in this post first.', 'maradigma'));
        }

        return BoatPricesRenderer::render($data, self::pickAttributes($attributes, [
            'title',
            'show_title',
            'fallback',
            'layout',
            'range_mode',
            'order_by',
            'show_headers',
            'row_gap',
            'vat_mode',
            'vat_position',
            'vat_text_included',
            'vat_text_excluded',
            'currency_display',
            'decimals_mode',
            'thousands_sep',
            'decimal_sep',
        ]));
    }

    /**
     * Renders a simple additional services list using the same API expand used by Elementor.
     *
     * @param array<string,mixed> $attributes
     * @param mixed               $block
     *
     * @return string
     */
    public static function renderBoatAdditionalServices(array $attributes, $block = null): string
    {
        $data = self::loadBoatData(
            $attributes,
            $block,
            ['expand' => ['service_additional_services']],
            ['additionals']
        );

        if (!$data) {
            return self::renderNotice(\__('Select a Maradigma boat in this post first.', 'maradigma'));
        }

        $options = self::pickAttributes($attributes, [
            'title',
            'show_title',
            'fallback',
            'layout',
            'show_headers',
            'group_by_category',
            'show_category_title',
            'show_badges',
            'show_badge_optional_type',
            'show_badge_price_type',
            'show_badge_payment',
            'badge_style',
            'show_description',
            'show_quantity',
            'price_display',
            'vat_mode',
            'vat_position',
            'vat_text_included',
            'vat_text_excluded',
            'currency_display',
            'decimals_mode',
            'thousands_sep',
            'decimal_sep',
        ]);

        $options['language'] = self::getCurrentLanguage();

        return BoatAdditionalServicesRenderer::render($data, $options);
    }

    /**
     * Renders notice.
     */
    public static function renderNotice(string $message): string
    {
        return '<div class="maradigma-gutenberg-notice"><strong>' .
            \esc_html__('Maradigma', 'maradigma') .
            '</strong><span>' .
            \esc_html($message) .
            '</span></div>';
    }

    /** @param mixed $value */
    private static function toFloatOrNull($value): ?float
    {
        if (\is_int($value) || \is_float($value)) {
            return (float) $value;
        }

        if (\is_string($value)) {
            $value = \str_replace(',', '.', \trim($value));

            if (\is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }

    /**
     * @param mixed $pricesPayload
     *
     * @return array<int,array<string,mixed>>
     */
    private static function extractSeasonPrices($pricesPayload, string $currencyFallback): array
    {
        if (!\is_array($pricesPayload)) {
            return [];
        }

        $range = $pricesPayload['range'] ?? null;

        if (!\is_array($range) || $range === []) {
            return [];
        }

        $rows = [];

        foreach ($range as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $price = self::toFloatOrNull($row['price'] ?? null);

            if ($price === null || $price <= 0) {
                continue;
            }

            $dfRaw = \trim((string) ($row['date_start'] ?? ''));
            $dtRaw = \trim((string) ($row['date_end'] ?? ''));

            $currency = \strtoupper(\trim((string) ($row['currency'] ?? $row['currency_code'] ?? $currencyFallback)));

            if ($currency === '') {
                $currency = $currencyFallback;
            }

            $rows[] = [
                'date_from'     => self::parseDateOrNull($dfRaw),
                'date_to'       => self::parseDateOrNull($dtRaw),
                'date_from_raw' => $dfRaw,
                'date_to_raw'   => $dtRaw,
                'price'         => $price,
                'currency'      => $currency,
                'vat_percent'   => self::toFloatOrNull($row['vat_percent'] ?? null),
                'vat_price'     => self::toFloatOrNull($row['vat_price'] ?? null),
                'total_price'   => self::toFloatOrNull($row['total_price'] ?? null),
            ];
        }

        return $rows;
    }

    /** @param array<int,array<string,mixed>> $rows */
    private static function sortPriceRows(array &$rows, string $orderBy): void
    {
        \usort($rows, static function (array $a, array $b) use ($orderBy): int {
            $aTs = ($a['date_from'] ?? null) instanceof \DateTimeImmutable ? $a['date_from']->getTimestamp() : 0;
            $bTs = ($b['date_from'] ?? null) instanceof \DateTimeImmutable ? $b['date_from']->getTimestamp() : 0;

            $aPrice = (float) ($a['price'] ?? 0);
            $bPrice = (float) ($b['price'] ?? 0);

            return match ($orderBy) {
                'date_from_desc' => $bTs <=> $aTs,
                'price_asc'      => $aPrice <=> $bPrice,
                'price_desc'     => $bPrice <=> $aPrice,
                default          => $aTs <=> $bTs,
            };
        });
    }

    /** @param array<string,mixed> $row */
    private static function formatPriceRangeLabel(array $row, string $mode): string
    {
        if (isset($row['label'])) {
            return (string) $row['label'];
        }

        $from = $row['date_from'] ?? null;
        $to   = $row['date_to'] ?? null;

        if (!($from instanceof \DateTimeImmutable) || !($to instanceof \DateTimeImmutable)) {
            $fromRaw = \trim((string) ($row['date_from_raw'] ?? ''));
            $toRaw   = \trim((string) ($row['date_to_raw'] ?? ''));

            if ($fromRaw !== '' && $toRaw !== '') {
                return $fromRaw . ' - ' . $toRaw;
            }

            return $fromRaw !== '' ? $fromRaw : ($toRaw !== '' ? $toRaw : \__('Season', 'maradigma'));
        }

        if ($mode === 'month') {
            $start = self::i18nMonthName($from);
            $end   = self::i18nMonthName($to);

            if ($start !== '' && $end !== '' && $start !== $end) {
                return $start . ' - ' . $end;
            }

            return $start !== '' ? $start : \__('Season', 'maradigma');
        }

        if ($mode === 'dates_long') {
            return self::i18nDate($from, 'd F Y') . ' - ' . self::i18nDate($to, 'd F Y');
        }

        return self::i18nDate($from, 'd M') . ' - ' . self::i18nDate($to, 'd M');
    }

    /** @param array<string,mixed> $row */
    private static function formatPriceAmountHtml(array $row, string $currencyFallback): string
    {
        $price = self::toFloatOrNull($row['price'] ?? null);

        if ($price === null) {
            return '';
        }

        $currency = \strtoupper(\trim((string) ($row['currency'] ?? $currencyFallback)));

        if ($currency === '') {
            $currency = $currencyFallback;
        }

        $html = '<span class="maradigma-boat-prices__amount">' .
            \esc_html(self::formatMoney($price, $currency)) .
            '</span>';

        $vatPercent = self::toFloatOrNull($row['vat_percent'] ?? null);
        $totalPrice = self::toFloatOrNull($row['total_price'] ?? null);

        if ($vatPercent !== null && $totalPrice !== null && $totalPrice > 0) {
            $html .= '<br><small class="maradigma-boat-prices__vat">' .
                \esc_html(\sprintf(
                    /* translators: 1: VAT percentage, 2: formatted total price. */
                    \__('VAT %1$.2f%%. Total: %2$s', 'maradigma'),
                    $vatPercent,
                    self::formatMoney($totalPrice, $currency)
                )) .
                '</small>';
        }

        return $html;
    }

    /**
     * Parses a date value or returns null when it is invalid.
     */
    private static function parseDateOrNull(string $value): ?\DateTimeImmutable
    {
        $value = \trim($value);

        if ($value === '') {
            return null;
        }

        if (\ctype_digit($value)) {
            $timestamp = (int) $value;

            if ($timestamp > 0) {
                return (new \DateTimeImmutable('@' . $timestamp))->setTimezone(\wp_timezone());
            }
        }

        foreach (['Y-m-d', 'Y-m-d H:i:s', 'd/m/Y', 'd-m-Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value, \wp_timezone());

            if ($date instanceof \DateTimeImmutable) {
                return $date;
            }
        }

        try {
            return new \DateTimeImmutable($value, \wp_timezone());
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Returns the localized month name for a date.
     */
    private static function i18nMonthName(\DateTimeImmutable $date): string
    {
        $value = \function_exists('date_i18n')
            ? (string) \date_i18n('F', $date->getTimestamp())
            : $date->format('F');

        $value = \trim($value);

        if ($value === '') {
            return '';
        }

        if (\function_exists('mb_substr') && \function_exists('mb_strtoupper')) {
            return \mb_strtoupper(\mb_substr($value, 0, 1, 'UTF-8'), 'UTF-8') .
                \mb_substr($value, 1, null, 'UTF-8');
        }

        return \ucfirst($value);
    }

    /**
     * Formats a date using WordPress localization.
     */
    private static function i18nDate(\DateTimeImmutable $date, string $format): string
    {
        return \trim(\function_exists('date_i18n') ? (string) \date_i18n($format, $date->getTimestamp()) : $date->format($format));
    }

    /**
     * Formats money.
     */
    private static function formatMoney(float $amount, string $currency): string
    {
        $formatted = \function_exists('number_format_i18n')
            ? \number_format_i18n($amount, 2)
            : \number_format($amount, 2, '.', ',');

        return \trim($formatted . ' ' . $currency);
    }

    /**
     * @param array<string,mixed> $data
     * @param mixed               $pricesPayload
     */
    private static function guessCurrency(array $data, $pricesPayload): string
    {
        foreach (['currency', 'currency_code'] as $key) {
            if (!empty($data[$key]) && \is_string($data[$key])) {
                $currency = \strtoupper(\trim((string) $data[$key]));

                if ($currency !== '') {
                    return $currency;
                }
            }
        }

        if (\is_array($pricesPayload)) {
            $currency = self::findFirstScalarByKeyRecursive($pricesPayload, 'currency');

            if (\is_string($currency) && \trim($currency) !== '') {
                return \strtoupper(\trim($currency));
            }

            $currency = self::findFirstScalarByKeyRecursive($pricesPayload, 'currency_code');

            if (\is_string($currency) && \trim($currency) !== '') {
                return \strtoupper(\trim($currency));
            }
        }

        return 'EUR';
    }

    /**
     * @param mixed $node
     *
     * @return mixed
     */
    private static function findFirstScalarByKeyRecursive($node, string $key)
    {
        if (!\is_array($node)) {
            return null;
        }

        if (\array_key_exists($key, $node) && \is_scalar($node[$key])) {
            return $node[$key];
        }

        foreach ($node as $child) {
            if (\is_array($child)) {
                $found = self::findFirstScalarByKeyRecursive($child, $key);

                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /** @param array<string,mixed> $item */
    private static function pickTranslatedLabel(array $item, string $language): string
    {
        $language = \strtoupper(\trim($language));

        if (isset($item['translations']) && \is_array($item['translations'])) {
            if (
                isset($item['translations'][$language]) &&
                \is_string($item['translations'][$language]) &&
                \trim($item['translations'][$language]) !== ''
            ) {
                return \trim($item['translations'][$language]);
            }

            foreach ($item['translations'] as $translation) {
                if (\is_string($translation) && \trim($translation) !== '') {
                    return \trim($translation);
                }
            }
        }

        foreach (['name', 'title', 'label', 'service_name', 'item_name'] as $key) {
            if (isset($item[$key]) && \is_scalar($item[$key])) {
                $label = \trim((string) $item[$key]);

                if ($label !== '') {
                    return $label;
                }
            }
        }

        return '';
    }

    /**
     * Resolves the current post ID from Gutenberg block context, queried object,
     * global loop or global $post.
     *
     * @param mixed $block
     */
    private static function resolveCurrentPostIdFromBlock($block = null): int
    {
        $postId = 0;

        if (\is_object($block) && isset($block->context) && \is_array($block->context)) {
            $postId = (int) ($block->context['postId'] ?? 0);
        }

        if ($postId <= 0 && \function_exists('get_queried_object_id')) {
            $postId = (int) \get_queried_object_id();
        }

        if ($postId <= 0 && \function_exists('get_the_ID')) {
            $postId = (int) \get_the_ID();
        }

        if ($postId <= 0) {
            global $post;

            if ($post instanceof \WP_Post) {
                $postId = (int) $post->ID;
            }
        }

        return $postId > 0 ? $postId : 0;
    }

    /**
     * Checks whether the given post is the internal Gutenberg template CPT.
     */
    private static function isGutenbergTemplatePost(int $postId): bool
    {
        if ($postId <= 0) {
            return false;
        }

        return (string) \get_post_type($postId) === self::TEMPLATE_POST_TYPE;
    }

    /**
     * Returns a WordPress post ID to use as preview source while editing the
     * Gutenberg master template.
     *
     * Resolution order mirrors the Elementor single-boat widgets approach:
     * 1. Template-level preview post meta.
     * 2. Gutenberg preview option fallback.
     * 3. First available Maradigma Boat CPT post.
     * 4. Any supported binding post type that resolves to a boat.
     */
    private static function resolvePreviewBoatPostIdForTemplate(int $templatePostId): int
    {
        if ($templatePostId <= 0) {
            return 0;
        }

        $metaId = (int) \get_post_meta($templatePostId, self::META_TEMPLATE_PREVIEW_BOAT_POST_ID, true);
        if ($metaId > 0 && self::isValidPreviewBoatBindingPost($metaId)) {
            return $metaId;
        }

        $optionId = (int) \get_option(self::OPTION_PREVIEW_BOAT_POST_ID, 0);
        if ($optionId > 0 && self::isValidPreviewBoatBindingPost($optionId)) {
            \update_post_meta($templatePostId, self::META_TEMPLATE_PREVIEW_BOAT_POST_ID, $optionId);
            return $optionId;
        }

        $boatPostType = self::getBoatPostType();

        foreach (['publish', 'any'] as $postStatus) {
            $query = new \WP_Query([
                'post_type'              => $boatPostType,
                'post_status'            => $postStatus,
                'fields'                 => 'ids',
                'posts_per_page'         => 10,
                'orderby'                => 'date',
                'order'                  => 'DESC',
                'no_found_rows'          => true,
                'update_post_meta_cache' => true,
                'update_post_term_cache' => false,
            ]);

            if (empty($query->posts) || !\is_array($query->posts)) {
                continue;
            }

            foreach ($query->posts as $candidatePostId) {
                $candidatePostId = (int) $candidatePostId;

                if (!self::isValidPreviewBoatBindingPost($candidatePostId)) {
                    continue;
                }

                \update_option(self::OPTION_PREVIEW_BOAT_POST_ID, $candidatePostId, false);
                \update_post_meta($templatePostId, self::META_TEMPLATE_PREVIEW_BOAT_POST_ID, $candidatePostId);

                return $candidatePostId;
            }
        }

        $supportedPostTypes = [];

        if (\class_exists(MetaManager::class) && \method_exists(MetaManager::class, 'getSupportedBoatBindingPostTypes')) {
            $supportedPostTypes = (array) MetaManager::getSupportedBoatBindingPostTypes();
        }

        $supportedPostTypes = \array_values(\array_unique(\array_filter(\array_map(
            static fn ($value): string => \sanitize_key((string) $value),
            $supportedPostTypes
        ), static fn (string $postType): bool => $postType !== '')));

        if ($supportedPostTypes === []) {
            return 0;
        }

        $query = new \WP_Query([
            'post_type'              => $supportedPostTypes,
            'post_status'            => ['publish', 'draft', 'private', 'pending', 'future'],
            'fields'                 => 'ids',
            'posts_per_page'         => 25,
            'orderby'                => 'date',
            'order'                  => 'DESC',
            'no_found_rows'          => true,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => false,
        ]);

        if (!empty($query->posts) && \is_array($query->posts)) {
            foreach ($query->posts as $candidatePostId) {
                $candidatePostId = (int) $candidatePostId;

                if (!self::isValidPreviewBoatBindingPost($candidatePostId)) {
                    continue;
                }

                \update_option(self::OPTION_PREVIEW_BOAT_POST_ID, $candidatePostId, false);
                \update_post_meta($templatePostId, self::META_TEMPLATE_PREVIEW_BOAT_POST_ID, $candidatePostId);

                return $candidatePostId;
            }
        }

        return 0;
    }

    /**
     * Checks whether a post can be used as preview source for Gutenberg boat blocks.
     */
    private static function isValidPreviewBoatBindingPost(int $postId): bool
    {
        if ($postId <= 0 || !\class_exists(MetaManager::class)) {
            return false;
        }

        $postType = (string) \get_post_type($postId);
        if ($postType === '') {
            return false;
        }

        $boatId = \trim((string) MetaManager::getBoundBoatIdForPost($postId));

        return $boatId !== '';
    }

    /**
     * Returns the Maradigma boat post type.
     */
    private static function getBoatPostType(): string
    {
        if (
            \class_exists(BoatPostType::class) &&
            \defined(BoatPostType::class . '::POST_TYPE')
        ) {
            return (string) \constant(BoatPostType::class . '::POST_TYPE');
        }

        return 'maradigma_boat';
    }

    /**
     * Checks whether a post type is the Maradigma Boat CPT.
     */
    private static function isBoatPostType(string $postType): bool
    {
        if (
            \class_exists(BoatPostType::class) &&
            \defined(BoatPostType::class . '::POST_TYPE')
        ) {
            return $postType === \constant(BoatPostType::class . '::POST_TYPE');
        }

        return $postType === 'maradigma_boat';
    }

    /**
     * Returns configured layout builder.
     */
    private static function getConfiguredLayoutBuilder(): string
    {
        $settings = \class_exists(SettingsPage::class) ? SettingsPage::getSettings() : [];
        $builder = \sanitize_key((string) ($settings['boat_layout_builder'] ?? 'elementor'));

        return \in_array($builder, ['elementor', 'gutenberg', 'wpbakery'], true) ? $builder : 'elementor';
    }

    /**
     * Determines whether show template menu.
     */
    private static function shouldShowTemplateMenu(): bool
    {
        $settings = \class_exists(SettingsPage::class) ? SettingsPage::getSettings() : [];

        return !empty($settings['enable_boat_pages_sync'])
            && self::getConfiguredLayoutBuilder() === 'gutenberg';
    }

    /**
     * Writes a Gutenberg integration diagnostic message.
     */
    private static function logDebug(string $message): void
    {
        if (\defined('WP_DEBUG') && WP_DEBUG === true) {
            \maradigma_debug_log('[Maradigma] ' . $message);
        }
    }

    /**
     * Writes a caught Gutenberg integration exception to diagnostics.
     */
    private static function logException(\Throwable $e, string $where): void
    {
        if (\class_exists(Logger::class)) {
            Logger::exception($e, ['where' => $where]);
            return;
        }

        if (\defined('WP_DEBUG') && WP_DEBUG === true) {
            \maradigma_debug_log('[Maradigma] ' . $where . ': ' . $e->getMessage());
        }
    }
}

<?php

declare(strict_types=1);

namespace Maradigma;

if (!defined('ABSPATH')) {
    exit;
}

final class MetaManager
{
    public const META_PAGE_BOATS_MODE = '_maradigma_boats_mode';

    // Generic post binding (SEO URL -> Boat ID)
    public const META_PAGE_IS_BOAT_PAGE = '_maradigma_is_boat_page';
    public const META_PAGE_BOAT_ID      = '_maradigma_page_boat_id';
    private const FIELD_APPLY_TO_TRANSLATIONS = 'maradigma_apply_boat_binding_to_translations';

    // Boat CPT binding (Boat post -> ERP Boat ID)
    public const META_CPT_BOAT_ID = '_maradigma_boat_id';

    // Boat CPT: Elementor layout override toggle (do not overwrite _elementor_data on sync)
    public const META_CPT_ELEMENTOR_CUSTOM_LAYOUT = '_maradigma_elementor_custom_layout';

    // Boat CPT: WPBakery layout override toggle (do not overwrite post_content on sync)
    public const META_CPT_WPBAKERY_CUSTOM_LAYOUT = '_maradigma_wpbakery_custom_layout';

    // Taxonomy term binding (SEO URL -> Boat ID)
    public const META_TERM_IS_BOAT_PAGE = '_maradigma_is_boat_page';
    public const META_TERM_BOAT_ID      = '_maradigma_page_boat_id';

    public static function init(): void
    {
        add_action('init', [__CLASS__, 'registerMeta']);

        // Posts / pages / configurable post types / own CPT
        add_action('add_meta_boxes', [__CLASS__, 'registerBoatBindingMetaBox']);

        // IMPORTANT:
        // Use the global save_post hook so late-registered/custom post types
        // are also covered correctly.
        add_action('save_post', [__CLASS__, 'saveBoatBindingMetaBox']);

        // Taxonomies
        self::registerTaxonomyHooks();
    }

    public static function registerMeta(): void
    {
        // ─────────────────────────────────────────────
        // Meta for configurable post types
        // ─────────────────────────────────────────────
        foreach (self::getSupportedBoatBindingPostTypes() as $postType) {
            register_post_meta(
                $postType,
                self::META_PAGE_BOATS_MODE,
                [
                    'show_in_rest'  => true,
                    'single'        => true,
                    'type'          => 'string',
                    'auth_callback' => [__CLASS__, 'canEditPostMeta'],
                    'default'       => '',
                ]
            );

            register_post_meta(
                $postType,
                self::META_PAGE_IS_BOAT_PAGE,
                [
                    'show_in_rest'  => true,
                    'single'        => true,
                    'type'          => 'boolean',
                    'auth_callback' => [__CLASS__, 'canEditPostMeta'],
                    'default'       => false,
                ]
            );

            register_post_meta(
                $postType,
                self::META_PAGE_BOAT_ID,
                [
                    'show_in_rest'  => true,
                    'single'        => true,
                    'type'          => 'string',
                    'auth_callback' => [__CLASS__, 'canEditPostMeta'],
                    'default'       => '',
                ]
            );
        }

        // ─────────────────────────────────────────────
        // Meta for boats CPT
        // ─────────────────────────────────────────────
        register_post_meta(
            BoatPostType::POST_TYPE,
            self::META_CPT_BOAT_ID,
            [
                'show_in_rest'  => true,
                'single'        => true,
                'type'          => 'string',
                'auth_callback' => [__CLASS__, 'canEditPostMeta'],
                'default'       => '',
            ]
        );

        register_post_meta(
            BoatPostType::POST_TYPE,
            '_maradigma_managed',
            [
                'show_in_rest'  => true,
                'single'        => true,
                'type'          => 'boolean',
                'auth_callback' => [__CLASS__, 'canEditPostMeta'],
                'default'       => false,
            ]
        );

        register_post_meta(
            BoatPostType::POST_TYPE,
            '_maradigma_disable_sync',
            [
                'show_in_rest'  => true,
                'single'        => true,
                'type'          => 'boolean',
                'auth_callback' => [__CLASS__, 'canEditPostMeta'],
                'default'       => false,
            ]
        );

        register_post_meta(
            BoatPostType::POST_TYPE,
            self::META_CPT_ELEMENTOR_CUSTOM_LAYOUT,
            [
                'show_in_rest'  => true,
                'single'        => true,
                'type'          => 'boolean',
                'auth_callback' => [__CLASS__, 'canEditPostMeta'],
                'default'       => false,
            ]
        );
        register_post_meta(
            BoatPostType::POST_TYPE,
            self::META_CPT_WPBAKERY_CUSTOM_LAYOUT,
            [
                'show_in_rest'  => true,
                'single'        => true,
                'type'          => 'boolean',
                'auth_callback' => [__CLASS__, 'canEditPostMeta'],
                'default'       => false,
            ]
        );

        // ─────────────────────────────────────────────
        // Meta for taxonomy terms
        // ─────────────────────────────────────────────
        foreach (self::getSupportedBoatTaxonomies() as $taxonomy) {
            register_term_meta(
                $taxonomy,
                self::META_TERM_IS_BOAT_PAGE,
                [
                    'show_in_rest'  => true,
                    'single'        => true,
                    'type'          => 'boolean',
                    'auth_callback' => static fn(): bool => current_user_can('manage_categories'),
                    'default'       => false,
                ]
            );

            register_term_meta(
                $taxonomy,
                self::META_TERM_BOAT_ID,
                [
                    'show_in_rest'  => true,
                    'single'        => true,
                    'type'          => 'string',
                    'auth_callback' => static fn(): bool => current_user_can('manage_categories'),
                    'default'       => '',
                ]
            );
        }
    }

    /**
     * REST meta auth must be scoped to the concrete post being edited.
     *
     * @param bool|null $allowed
     * @param string    $metaKey
     * @param int       $postId
     */
    public static function canEditPostMeta($allowed = null, string $metaKey = '', int $postId = 0): bool
    {
        if ($postId > 0) {
            return current_user_can('edit_post', $postId);
        }

        return current_user_can('edit_posts');
    }

    public static function registerBoatBindingMetaBox(): void
    {
        foreach (self::getSupportedBoatBindingPostTypes() as $screen) {
            if (self::shouldUseNativeBoatBindingPanel($screen)) {
                continue;
            }

            add_meta_box(
                'maradigma_boat_binding',
                __('Maradigma', 'maradigma'),
                [__CLASS__, 'renderBoatBindingMetaBox'],
                $screen,
                'side',
                'high'
            );
        }

        if (!self::shouldUseNativeBoatCptPanel()) {
            add_meta_box(
                'maradigma_boat_binding',
                __('Maradigma', 'maradigma'),
                [__CLASS__, 'renderBoatBindingMetaBox'],
                BoatPostType::POST_TYPE,
                'side',
                'high'
            );
        }
    }

    public static function renderBoatBindingMetaBox(\WP_Post $post): void
    {
        wp_nonce_field('maradigma_boat_binding', 'maradigma_boat_binding_nonce');

        if (self::isPostProtectedFromBoatBinding((int) $post->ID, $post)) {
            echo '<p>' . esc_html__('Boat binding is disabled for this page by an integration.', 'maradigma') . '</p>';
            return;
        }

        if ($post->post_type === BoatPostType::POST_TYPE) {
            self::renderCptBoatBindingUI($post);
            return;
        }

        if (self::isSupportedBoatBindingPostType($post->post_type)) {
            self::renderPageBoatBindingUI($post);
            return;
        }

        echo esc_html__('Unsupported post type.', 'maradigma');
    }

    private static function renderPageBoatBindingUI(\WP_Post $post): void
    {
        $isBoatPage = (bool) get_post_meta($post->ID, self::META_PAGE_IS_BOAT_PAGE, true);
        $boatId     = trim((string) get_post_meta($post->ID, self::META_PAGE_BOAT_ID, true));
        $translationCount = self::countTranslationSiblings($post->ID);

        ?>
        <div class="maradigma-boat-page-metabox">
            <p style="margin:0 0 8px;">
                <label style="display:flex;gap:8px;align-items:center;">
                    <input
                        type="checkbox"
                        id="maradigma_is_boat_page"
                        name="maradigma_is_boat_page"
                        value="1"
                        <?php checked($isBoatPage); ?>
                    />
                    <span><?php echo esc_html__('This page is a boat detail page', 'maradigma'); ?></span>
                </label>
            </p>

            <div class="maradigma-boat-page-binding" style="<?php echo $isBoatPage ? '' : 'display:none;'; ?>">
                <p style="margin:0 0 6px;">
                    <label for="maradigma_page_boat_id" style="display:block;font-weight:600;">
                        <?php echo esc_html__('Select boat', 'maradigma'); ?>
                    </label>
                </p>

                <select
                    id="maradigma_page_boat_id"
                    name="maradigma_page_boat_id"
                    class="widefat maradigma-remote-select"
                    data-maradigma-source="boats"
                    data-multiple="0"
                >
                    <?php if ($boatId !== ''): ?>
                        <option value="<?php echo esc_attr($boatId); ?>" selected>
                            <?php echo esc_html('Boat #' . $boatId); ?>
                        </option>
                    <?php endif; ?>
                </select>

                <p style="margin:8px 0 0;color:#666;font-size:12px;line-height:1.3;">
                    <?php echo esc_html__('Keeps the current URL for SEO and renders the selected boat on this page.', 'maradigma'); ?>
                </p>

                <?php if ($translationCount > 0) : ?>
                    <p style="margin:10px 0 0;">
                        <label style="display:flex;gap:8px;align-items:flex-start;">
                            <input
                                type="checkbox"
                                id="<?php echo esc_attr(self::FIELD_APPLY_TO_TRANSLATIONS); ?>"
                                name="<?php echo esc_attr(self::FIELD_APPLY_TO_TRANSLATIONS); ?>"
                                value="1"
                            />
                            <span>
                                <strong><?php echo esc_html__('Apply this boat link to the translated versions of this page', 'maradigma'); ?></strong><br>
                                <span style="display:block;margin-top:4px;color:#666;font-size:12px;line-height:1.3;">
                                    <?php
                                    echo esc_html__(
                                        'Useful with Polylang/WPML when you want the same selected boat to be linked in the other language versions too.',
                                        'maradigma'
                                    );
                                    ?>
                                </span>
                            </span>
                        </label>
                    </p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private static function renderCptBoatBindingUI(\WP_Post $post): void
    {
        $boatId                  = trim((string) get_post_meta($post->ID, self::META_CPT_BOAT_ID, true));
        $isCustomLayout          = (bool) get_post_meta($post->ID, self::META_CPT_ELEMENTOR_CUSTOM_LAYOUT, true);
        $isWPBakeryCustomLayout  = (bool) get_post_meta($post->ID, self::META_CPT_WPBAKERY_CUSTOM_LAYOUT, true);

        ?>
        <div class="maradigma-boat-cpt-metabox">
            <p style="margin:0 0 6px;">
                <label for="maradigma_cpt_boat_id" style="display:block;font-weight:600;">
                    <?php echo esc_html__('Maradigma boat (searchable)', 'maradigma'); ?>
                </label>
            </p>

            <select
                id="maradigma_cpt_boat_id"
                name="maradigma_cpt_boat_id"
                class="widefat maradigma-remote-select"
                data-maradigma-source="boats"
                data-multiple="0"
            >
                <?php if ($boatId !== ''): ?>
                    <option value="<?php echo esc_attr($boatId); ?>" selected>
                        <?php echo esc_html('Boat #' . $boatId); ?>
                    </option>
                <?php endif; ?>
            </select>

            <p style="margin:8px 0 0;color:#666;font-size:12px;line-height:1.3;">
                <?php
                echo esc_html__(
                    'Select one of your active/public boats from Maradigma. Start typing to search.',
                    'maradigma'
                );
                ?>
            </p>

            <hr style="margin:10px 0;" />

            <p style="margin:0;">
                <label style="display:flex;gap:8px;align-items:flex-start;">
                    <input
                        type="checkbox"
                        id="maradigma_elementor_custom_layout"
                        name="maradigma_elementor_custom_layout"
                        value="1"
                        <?php checked($isCustomLayout); ?>
                    />
                    <span>
                        <strong><?php echo esc_html__('Use custom Elementor layout', 'maradigma'); ?></strong><br/>
                        <span style="display:block;margin-top:4px;color:#666;font-size:12px;line-height:1.3;">
                            <?php
                            echo esc_html__(
                                'When enabled, sync will never overwrite this boat’s Elementor layout. Boat data, images and SEO will still be synced.',
                                'maradigma'
                            );
                            ?>
                        </span>
                    </span>
                </label>
            </p>
            <p style="margin:10px 0 0;">
                <label style="display:flex;gap:8px;align-items:flex-start;">
                    <input
                        type="checkbox"
                        id="maradigma_wpbakery_custom_layout"
                        name="maradigma_wpbakery_custom_layout"
                        value="1"
                        <?php checked($isWPBakeryCustomLayout); ?>
                    />
                    <span>
                        <strong><?php echo esc_html__('Use custom WPBakery layout', 'maradigma'); ?></strong><br/>
                        <span style="display:block;margin-top:4px;color:#666;font-size:12px;line-height:1.3;">
                            <?php
                            echo esc_html__(
                                'When enabled, sync will never overwrite this boat\'s WPBakery content. Boat data, images and SEO will still be synced.',
                                'maradigma'
                            );
                            ?>
                        </span>
                    </span>
                </label>
            </p>
        </div>
        <?php
    }

    private static function registerTaxonomyHooks(): void
    {
        foreach (self::getSupportedBoatTaxonomies() as $taxonomy) {
            add_action($taxonomy . '_add_form_fields', [__CLASS__, 'renderTermBoatBindingAddFields']);
            add_action($taxonomy . '_edit_form_fields', [__CLASS__, 'renderTermBoatBindingEditFields'], 10, 2);
            add_action('created_' . $taxonomy, [__CLASS__, 'saveTermBoatBindingFields'], 10, 2);
            add_action('edited_' . $taxonomy, [__CLASS__, 'saveTermBoatBindingFields'], 10, 2);
        }
    }

    public static function renderTermBoatBindingAddFields(string $taxonomy): void
    {
        unset($taxonomy);

        wp_nonce_field('maradigma_term_boat_binding', 'maradigma_term_boat_binding_nonce');
        ?>
        <div class="form-field term-maradigma-wrap">
            <label for="maradigma_term_is_boat_page"><?php echo esc_html__('Maradigma', 'maradigma'); ?></label>

            <label style="display:flex;gap:8px;align-items:center;">
                <input
                    type="checkbox"
                    id="maradigma_term_is_boat_page"
                    name="maradigma_term_is_boat_page"
                    value="1"
                />
                <span><?php echo esc_html__('This taxonomy term is a boat detail page', 'maradigma'); ?></span>
            </label>

            <div class="maradigma-term-boat-binding" style="display:none;margin-top:10px;">
                <p style="margin:0 0 6px;">
                    <label for="maradigma_term_boat_id" style="display:block;font-weight:600;">
                        <?php echo esc_html__('Select boat', 'maradigma'); ?>
                    </label>
                </p>

                <select
                    id="maradigma_term_boat_id"
                    name="maradigma_term_boat_id"
                    class="widefat maradigma-remote-select"
                    data-maradigma-source="boats"
                    data-multiple="0"
                ></select>

                <p class="description">
                    <?php echo esc_html__('Keeps the current taxonomy URL for SEO and renders the selected boat on this term archive.', 'maradigma'); ?>
                </p>
            </div>
        </div>
        <?php
    }

    public static function renderTermBoatBindingEditFields(\WP_Term $term, string $taxonomy): void
    {
        unset($taxonomy);

        wp_nonce_field('maradigma_term_boat_binding', 'maradigma_term_boat_binding_nonce');

        $isBoatPage = (bool) get_term_meta($term->term_id, self::META_TERM_IS_BOAT_PAGE, true);
        $boatId     = trim((string) get_term_meta($term->term_id, self::META_TERM_BOAT_ID, true));

        ?>
        <tr class="form-field term-maradigma-wrap">
            <th scope="row">
                <label for="maradigma_term_is_boat_page"><?php echo esc_html__('Maradigma', 'maradigma'); ?></label>
            </th>
            <td>
                <p style="margin:0 0 8px;">
                    <label style="display:flex;gap:8px;align-items:center;">
                        <input
                            type="checkbox"
                            id="maradigma_term_is_boat_page"
                            name="maradigma_term_is_boat_page"
                            value="1"
                            <?php checked($isBoatPage); ?>
                        />
                        <span><?php echo esc_html__('This taxonomy term is a boat detail page', 'maradigma'); ?></span>
                    </label>
                </p>

                <div class="maradigma-term-boat-binding" style="<?php echo $isBoatPage ? '' : 'display:none;'; ?>">
                    <p style="margin:0 0 6px;">
                        <label for="maradigma_term_boat_id" style="display:block;font-weight:600;">
                            <?php echo esc_html__('Select boat', 'maradigma'); ?>
                        </label>
                    </p>

                    <select
                        id="maradigma_term_boat_id"
                        name="maradigma_term_boat_id"
                        class="widefat maradigma-remote-select"
                        data-maradigma-source="boats"
                        data-multiple="0"
                    >
                        <?php if ($boatId !== ''): ?>
                            <option value="<?php echo esc_attr($boatId); ?>" selected>
                                <?php echo esc_html('Boat #' . $boatId); ?>
                            </option>
                        <?php endif; ?>
                    </select>

                    <p class="description">
                        <?php echo esc_html__('Keeps the current taxonomy URL for SEO and renders the selected boat on this term archive.', 'maradigma'); ?>
                    </p>
                </div>
            </td>
        </tr>
        <?php
    }

    public static function saveBoatBindingMetaBox(int $postId, \WP_Post $post = null): void
    {
        $nonce = isset($_POST['maradigma_boat_binding_nonce'])
            ? sanitize_text_field((string) wp_unslash($_POST['maradigma_boat_binding_nonce']))
            : '';
        if (!wp_verify_nonce($nonce, 'maradigma_boat_binding')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (wp_is_post_revision($postId)) {
            return;
        }

        if (!$post instanceof \WP_Post) {
            $post = get_post($postId);
        }

        if (!$post instanceof \WP_Post) {
            return;
        }

        if (self::isPostProtectedFromBoatBinding($postId, $post)) {
            return;
        }

        // ─────────────────────────────────────────────
        // Save for configurable post types
        // ─────────────────────────────────────────────
        if (self::isSupportedBoatBindingPostType($post->post_type)) {
            if (!current_user_can('edit_post', $postId)) {
                return;
            }

            $isBoatPage = isset($_POST['maradigma_is_boat_page'])
                && sanitize_key((string) wp_unslash($_POST['maradigma_is_boat_page'])) === '1';
            update_post_meta($postId, self::META_PAGE_IS_BOAT_PAGE, $isBoatPage ? '1' : '0');

            $applyToTranslations = isset($_POST[self::FIELD_APPLY_TO_TRANSLATIONS])
                && sanitize_key((string) wp_unslash($_POST[self::FIELD_APPLY_TO_TRANSLATIONS])) === '1';

            if (!$isBoatPage) {
                delete_post_meta($postId, self::META_PAGE_BOAT_ID);

                if ($applyToTranslations) {
                    self::syncPageBoatBindingToTranslations($postId, false, '');
                }

                return;
            }

            $boatId = isset($_POST['maradigma_page_boat_id'])
                ? trim(sanitize_text_field((string) wp_unslash($_POST['maradigma_page_boat_id'])))
                : '';
            if ($boatId !== '' && preg_match('/^\d+$/', $boatId)) {
                update_post_meta($postId, self::META_PAGE_BOAT_ID, $boatId);
            } else {
                delete_post_meta($postId, self::META_PAGE_BOAT_ID);
                $boatId = '';
            }

            if ($applyToTranslations) {
                self::syncPageBoatBindingToTranslations($postId, true, $boatId);
            }

            return;
        }

        // ─────────────────────────────────────────────
        // Save for BOAT CPT
        // ─────────────────────────────────────────────
        if ($post->post_type === BoatPostType::POST_TYPE) {
            if (!current_user_can('edit_post', $postId)) {
                return;
            }

            $boatId = isset($_POST['maradigma_cpt_boat_id'])
                ? trim(sanitize_text_field((string) wp_unslash($_POST['maradigma_cpt_boat_id'])))
                : '';
            if ($boatId !== '' && preg_match('/^\d+$/', $boatId)) {
                update_post_meta($postId, self::META_CPT_BOAT_ID, $boatId);
            } else {
                delete_post_meta($postId, self::META_CPT_BOAT_ID);
            }

            $isCustomLayout = isset($_POST['maradigma_elementor_custom_layout'])
                && sanitize_key((string) wp_unslash($_POST['maradigma_elementor_custom_layout'])) === '1';
            $isWPBakeryCustomLayout = isset($_POST['maradigma_wpbakery_custom_layout'])
                && sanitize_key((string) wp_unslash($_POST['maradigma_wpbakery_custom_layout'])) === '1';

            update_post_meta($postId, self::META_CPT_ELEMENTOR_CUSTOM_LAYOUT, $isCustomLayout ? '1' : '0');
            update_post_meta($postId, self::META_CPT_WPBAKERY_CUSTOM_LAYOUT, $isWPBakeryCustomLayout ? '1' : '0');
        }
    }

    public static function saveTermBoatBindingFields(int $termId, int $ttId = 0): void
    {
        unset($ttId);

        $nonce = isset($_POST['maradigma_term_boat_binding_nonce'])
            ? sanitize_text_field((string) wp_unslash($_POST['maradigma_term_boat_binding_nonce']))
            : '';
        if (!wp_verify_nonce($nonce, 'maradigma_term_boat_binding')) {
            return;
        }

        $taxonomy = isset($_POST['taxonomy'])
            ? sanitize_key((string) wp_unslash($_POST['taxonomy']))
            : '';
        if ($taxonomy === '' || !in_array($taxonomy, self::getSupportedBoatTaxonomies(), true)) {
            return;
        }

        $taxonomyObject = get_taxonomy($taxonomy);
        if (!$taxonomyObject instanceof \WP_Taxonomy) {
            return;
        }

        $capability = $taxonomyObject->cap->edit_terms ?? 'manage_categories';
        if (!current_user_can($capability)) {
            return;
        }

        $isBoatPage = isset($_POST['maradigma_term_is_boat_page'])
            && sanitize_key((string) wp_unslash($_POST['maradigma_term_is_boat_page'])) === '1';
        update_term_meta($termId, self::META_TERM_IS_BOAT_PAGE, $isBoatPage ? '1' : '0');

        if (!$isBoatPage) {
            delete_term_meta($termId, self::META_TERM_BOAT_ID);
            return;
        }

        $boatId = isset($_POST['maradigma_term_boat_id'])
            ? trim(sanitize_text_field((string) wp_unslash($_POST['maradigma_term_boat_id'])))
            : '';
        if ($boatId !== '' && preg_match('/^\d+$/', $boatId)) {
            update_term_meta($termId, self::META_TERM_BOAT_ID, $boatId);
        } else {
            delete_term_meta($termId, self::META_TERM_BOAT_ID);
        }
    }

    public static function getSupportedBoatBindingPostTypes(): array
    {
        return \Maradigma\SettingsPage::getEnabledBoatBindingPostTypes();
    }

    public static function isSupportedBoatBindingPostType(string $postType): bool
    {
        return in_array($postType, self::getSupportedBoatBindingPostTypes(), true);
    }

    public static function getSupportedBoatTaxonomies(): array
    {
        $taxonomies = apply_filters(
            'maradigma_boat_binding_taxonomies',
            ['boats']
        );

        if (!is_array($taxonomies)) {
            return [];
        }

        $taxonomies = array_map(
            static fn($taxonomy): string => sanitize_key((string) $taxonomy),
            $taxonomies
        );

        $taxonomies = array_filter(
            $taxonomies,
            static fn(string $taxonomy): bool => $taxonomy !== '' && taxonomy_exists($taxonomy)
        );

        return array_values(array_unique($taxonomies));
    }

    private static function countTranslationSiblings(int $postId): int
    {
        return count(self::getTranslationSiblingPostIds($postId));
    }

    /** @return list<int> */
    private static function getTranslationSiblingPostIds(int $postId): array
    {
        $postId = (int) $postId;
        if ($postId <= 0) {
            return [];
        }

        $provider = \Maradigma\Support\MultilangAdapter::detectProvider();
        if ($provider === '') {
            return [];
        }

        $postType = (string) get_post_type($postId);
        if ($postType === '' || !\Maradigma\Support\MultilangAdapter::isPostTypeTranslatable($postType)) {
            return [];
        }

        $siblings = [];
        foreach (\Maradigma\Support\MultilangAdapter::getActiveLanguages() as $lang) {
            $lang = strtolower(trim((string) $lang));
            if ($lang === '') {
                continue;
            }

            $translatedId = \Maradigma\Support\MultilangAdapter::getTranslationPostId($postId, $lang);
            if ($translatedId > 0 && $translatedId !== $postId) {
                $siblings[] = $translatedId;
            }
        }

        $siblings = array_values(array_unique(array_map('intval', $siblings)));

        return array_filter($siblings, static fn(int $id): bool => $id > 0);
    }

    private static function syncPageBoatBindingToTranslations(int $postId, bool $isBoatPage, string $boatId): void
    {
        foreach (self::getTranslationSiblingPostIds($postId) as $translatedPostId) {
            update_post_meta($translatedPostId, self::META_PAGE_IS_BOAT_PAGE, $isBoatPage ? '1' : '0');

            if ($isBoatPage && $boatId !== '') {
                update_post_meta($translatedPostId, self::META_PAGE_BOAT_ID, $boatId);
                continue;
            }

            delete_post_meta($translatedPostId, self::META_PAGE_BOAT_ID);
        }
    }

    private static function shouldUseNativeBoatCptPanel(): bool
    {
        return self::shouldUseNativeBoatBindingPanel(BoatPostType::POST_TYPE);
    }

    private static function shouldUseNativeBoatBindingPanel(string $postType): bool
    {
        return function_exists('use_block_editor_for_post_type')
            && use_block_editor_for_post_type($postType);
    }

    public static function isSupportedBoatTaxonomy(string $taxonomy): bool
    {
        return in_array($taxonomy, self::getSupportedBoatTaxonomies(), true);
    }

    public static function isPostProtectedFromBoatBinding(int $postId, ?\WP_Post $post = null): bool
    {
        if ($postId <= 0) {
            return false;
        }

        if (!$post instanceof \WP_Post) {
            $post = get_post($postId);
        }

        if (!$post instanceof \WP_Post) {
            return false;
        }

        return (bool) apply_filters('maradigma_is_post_protected_from_boat_binding', false, $postId, $post);
    }

    public static function getBoundBoatIdForPost(int $postId): string
    {
        $post = get_post($postId);
        if (!$post instanceof \WP_Post) {
            return '';
        }

        if (self::isPostProtectedFromBoatBinding($postId, $post)) {
            return '';
        }

        if (self::isSupportedBoatBindingPostType($post->post_type)) {
            $isBoatPage = (bool) get_post_meta($postId, self::META_PAGE_IS_BOAT_PAGE, true);
            if (!$isBoatPage) {
                return '';
            }

            return trim((string) get_post_meta($postId, self::META_PAGE_BOAT_ID, true));
        }

        if ($post->post_type === BoatPostType::POST_TYPE) {
            return trim((string) get_post_meta($postId, self::META_CPT_BOAT_ID, true));
        }

        return '';
    }

    public static function getBoundBoatIdForTerm(int $termId): string
    {
        $isBoatPage = (bool) get_term_meta($termId, self::META_TERM_IS_BOAT_PAGE, true);
        if (!$isBoatPage) {
            return '';
        }

        return trim((string) get_term_meta($termId, self::META_TERM_BOAT_ID, true));
    }
}

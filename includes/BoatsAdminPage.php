<?php

declare(strict_types=1);

namespace Maradigma;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Configuración por página para listados de barcos.
 *
 * - Añade una metabox en las páginas (post_type=page).
 * - Guarda la configuración en el meta _maradigma_boats_page.
 * - Inyecta el listado en el contenido usando los shortcodes.
 */
final class BoatsAdminPage
{
    private const META_KEY = '_maradigma_boats_page';

    /**
     * Registers the component's WordPress hooks.
     */
    public static function init(): void
    {

        // IMPORTANTE: indicamos que el hook recibe 2 argumentos.
        add_action('add_meta_boxes', [__CLASS__, 'registerMetaBox'], 10, 2);

        // Guardar al salvar una página.
        add_action('save_post', [__CLASS__, 'saveMeta'], 10, 2);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueueMetaBoxAssets']);

        // Inyectar el listado en el contenido del front.
        add_filter('the_content', [__CLASS__, 'maybeInjectBoatsListing']);
    }

    /**
     * Registra la metabox sólo para post_type=page.
     *
     * @param string   $postType
     * @param \WP_Post $post
     */
    /**
     * Enqueue meta-box assets only on page editing screens.
     */
    public static function enqueueMetaBoxAssets(string $hookSuffix): void
    {
        if (!in_array($hookSuffix, ['post.php', 'post-new.php'], true)) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->post_type !== 'page') {
            return;
        }

        wp_enqueue_style(
            'maradigma-boats-meta-box',
            MARADIGMA_PLUGIN_URL . 'assets/css/admin/boats-meta-box.css',
            [],
            MARADIGMA_PLUGIN_VERSION
        );
        wp_enqueue_script(
            'maradigma-boats-meta-box',
            MARADIGMA_PLUGIN_URL . 'assets/js/admin/boats-meta-box.js',
            [],
            MARADIGMA_PLUGIN_VERSION,
            true
        );
    }

    /**
     * Registers meta box.
     */
    public static function registerMetaBox(string $postType, \WP_Post $post): void
    {
        // DEBUG
        maradigma_debug_log(sprintf('[Maradigma] registerMetaBox() llamado. postType=%s, ID=%d', $postType, $post->ID));

        if ($postType !== 'page') {
            return;
        }

        add_meta_box(
            'maradigma_boats_page',
            __('Configuración Maradigma - Página de barcos', 'maradigma'),
            [__CLASS__, 'renderMetaBox'],
            'page',
            'normal',
            'default'
        );

        // DEBUG
        maradigma_debug_log(sprintf('[Maradigma] Metabox maradigma_boats_page registrado para la página ID=%d', $post->ID));
    }

    /**
     * Render the meta box UI in the editor.
     *
     * @param \WP_Post $post
     */
    public static function renderMetaBox(\WP_Post $post): void
    {
        // Security nonce.
        wp_nonce_field('maradigma_boats_page_meta', 'maradigma_boats_page_nonce');

        // Meta "antiguo" (array) donde guardas todo.
        $meta = get_post_meta($post->ID, self::META_KEY, true);
        if (!is_array($meta)) {
            $meta = [];
        }

        // Modo desde el meta usado por el editor de bloques (source of truth).
        $modeFromGutenberg = (string) get_post_meta($post->ID, '_maradigma_boats_mode', true);
        $modeFromArray     = (string) ($meta['mode'] ?? '');

        // Prioridad: Gutenberg → fallback al array viejo.
        $mode = $modeFromGutenberg !== '' ? $modeFromGutenberg : $modeFromArray;

        // by_type
        $byTypeGcType = isset($meta['by_type_gc_type']) ? (int)$meta['by_type_gc_type'] : 0;

        // multiple_types
        $multipleTypes = [];
        if (isset($meta['multiple_types_gc_type']) && is_array($meta['multiple_types_gc_type'])) {
            $multipleTypes = array_values(
                array_filter(
                    array_map('intval', $meta['multiple_types_gc_type']),
                    static fn(int $v): bool => $v > 0
                )
            );
        }

        // custom_listing structure
        $custom = isset($meta['custom_listing']) && is_array($meta['custom_listing'])
            ? $meta['custom_listing']
            : [];

        $customIdGcType = isset($custom['id_gc_type']) && is_array($custom['id_gc_type'])
            ? array_values(array_filter(array_map('intval', $custom['id_gc_type']), static fn(int $v): bool => $v > 0))
            : [];

        $customTags = isset($custom['tags']) && is_array($custom['tags'])
            ? array_values(array_filter(array_map('intval', $custom['tags']), static fn(int $v): bool => $v > 0))
            : [];

        $customBuilders = isset($custom['boat_builders']) && is_array($custom['boat_builders'])
            ? array_values(array_filter(array_map('intval', $custom['boat_builders']), static fn(int $v): bool => $v > 0))
            : [];

        $customBoats = isset($custom['boat_ids']) && is_array($custom['boat_ids'])
            ? array_values(array_filter(array_map('intval', $custom['boat_ids']), static fn(int $v): bool => $v > 0))
            : [];

        $customBareboat    = !empty($custom['bareboat']);
        $customDiscount    = !empty($custom['discount']);
        $customInsBook     = !empty($custom['ins_book']);
        $customFeatured    = !empty($custom['featured']);
        $customLastMinute  = !empty($custom['last_minute']);

        ?>

        <div class="maradigma-meta-wrap">
            <div class="maradigma-meta-header">
                <div>
                    <h3 class="maradigma-meta-title">
                        <span class="maradigma-meta-title-dot"></span>
                        <?php esc_html_e('Maradigma - configuración de listado de barcos', 'maradigma'); ?>
                    </h3>
                    <p class="maradigma-meta-tagline">
                        <?php esc_html_e(
                            'Elige qué tipo de listado de barcos quieres mostrar en esta página.',
                            'maradigma'
                        ); ?>
                    </p>
                </div>
                <span class="maradigma-meta-pill">
                    <?php esc_html_e('Página de listados', 'maradigma'); ?>
                </span>
            </div>

            <div class="maradigma-meta-modes">
                <label>
                    <input type="radio"
                        name="maradigma_boats_page[mode]"
                        value=""
                        <?php checked($mode, ''); ?> />
                    <?php esc_html_e('Sin integración (página normal)', 'maradigma'); ?>
                </label>

                <label>
                    <input type="radio"
                        name="maradigma_boats_page[mode]"
                        value="listing"
                        <?php checked($mode, 'listing'); ?> />
                    <?php esc_html_e('Listing boats (listado general)', 'maradigma'); ?>
                </label>

                <label>
                    <input type="radio"
                        name="maradigma_boats_page[mode]"
                        value="by_type"
                        <?php checked($mode, 'by_type'); ?> />
                    <?php esc_html_e('List of boats by type (un solo tipo)', 'maradigma'); ?>
                </label>

                <label>
                    <input type="radio"
                        name="maradigma_boats_page[mode]"
                        value="multiple_types"
                        <?php checked($mode, 'multiple_types'); ?> />
                    <?php esc_html_e('List of boats by multiple types', 'maradigma'); ?>
                </label>

                <label>
                    <input type="radio"
                        name="maradigma_boats_page[mode]"
                        value="custom_list"
                        <?php checked($mode, 'custom_list'); ?> />
                    <?php esc_html_e('Custom listing (filtros avanzados)', 'maradigma'); ?>
                </label>
            </div>

            <!-- by_type section -->
            <div id="maradigma-mode-by_type"
                class="maradigma-meta-section maradigma-mode-section"
                style="<?php echo $mode === 'by_type' ? '' : 'display:none;'; ?>">
                <h4><?php esc_html_e('List of boats by type (single)', 'maradigma'); ?></h4>
                <p class="description">
                    <?php esc_html_e(
                        'Selecciona un solo tipo de barco. El listado mostrará únicamente ese tipo.',
                        'maradigma'
                    ); ?>
                </p>

                <div class="maradigma-meta-select">
                    <span class="maradigma-meta-small-label">
                        <?php esc_html_e('Boat type', 'maradigma'); ?>
                    </span><br />
                    <select id="maradigma_by_type_gc_type"
                        name="maradigma_boats_page[by_type_gc_type]"
                        class="widefat maradigma-remote-select"
                        data-maradigma-source="boat_types"
                        data-multiple="0">
                        <?php if ($byTypeGcType > 0) : ?>
                            <option value="<?php echo (int)$byTypeGcType; ?>" selected>
                                <?php
                                echo esc_html(
                                    sprintf(
                                        /* translators: %d: boat type ID. */
                                        __('Type #%d', 'maradigma'),
                                        (int)$byTypeGcType
                                    )
                                );
                                ?>
                            </option>
                        <?php endif; ?>
                    </select>
                    <p class="maradigma-meta-hint">
                        <?php esc_html_e(
                            'El desplegable se rellenará desde la API de Maradigma (tipos de barco).',
                            'maradigma'
                        ); ?>
                    </p>
                </div>
            </div>

            <!-- multiple_types section -->
            <div id="maradigma-mode-multiple_types"
                class="maradigma-meta-section maradigma-mode-section"
                style="<?php echo $mode === 'multiple_types' ? '' : 'display:none;'; ?>">
                <h4><?php esc_html_e('List of boats by multiple types', 'maradigma'); ?></h4>
                <p class="description">
                    <?php esc_html_e(
                        'Selecciona varios tipos de barco. El listado mostrará cualquier barco que encaje en esos tipos.',
                        'maradigma'
                    ); ?>
                </p>

                <div class="maradigma-meta-select">
                    <span class="maradigma-meta-small-label">
                        <?php esc_html_e('Boat types (multiple)', 'maradigma'); ?>
                    </span><br />
                    <select id="maradigma_multiple_types_gc_type"
                        name="maradigma_boats_page[multiple_types_gc_type][]"
                        class="widefat maradigma-remote-select"
                        multiple="multiple"
                        data-maradigma-source="boat_types"
                        data-multiple="1">
                        <?php foreach ($multipleTypes as $id) : ?>
                            <option value="<?php echo (int)$id; ?>" selected>
                                <?php
                                echo esc_html(
                                    sprintf(
                                        /* translators: %d: boat type ID. */
                                        __('Type #%d', 'maradigma'),
                                        (int)$id
                                    )
                                );
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="maradigma-meta-hint">
                        <?php esc_html_e(
                            'Puedes seleccionar varios tipos. Se cargan desde la API de Maradigma.',
                            'maradigma'
                        ); ?>
                    </p>
                </div>
            </div>

            <!-- custom_list section -->
            <div id="maradigma-mode-custom_list"
                class="maradigma-meta-section maradigma-mode-section"
                style="<?php echo $mode === 'custom_list' ? '' : 'display:none;'; ?>">
                <h4><?php esc_html_e('Custom listing (advanced filters)', 'maradigma'); ?></h4>
                <p class="description">
                    <?php esc_html_e(
                        'Combina tipos de servicio, tags, builders y barcos concretos, junto con opciones especiales (bareboat, featured, etc.).',
                        'maradigma'
                    ); ?>
                </p>

                <div class="maradigma-meta-grid">
                    <!-- Service types -->
                    <div>
                        <span class="maradigma-meta-small-label">
                            <?php esc_html_e('Service type (id_gc_type)', 'maradigma'); ?>
                        </span><br />
                        <select id="maradigma_custom_gc_type"
                            name="maradigma_boats_page[custom_listing][id_gc_type][]"
                            class="widefat maradigma-remote-select"
                            multiple="multiple"
                            data-maradigma-source="boat_types"
                            data-multiple="1">
                            <?php foreach ($customIdGcType as $id) : ?>
                                <option value="<?php echo (int)$id; ?>" selected>
                                    <?php
                                    echo esc_html(
                                        sprintf(
                                            /* translators: %d: boat type ID. */
                                            __('Type #%d', 'maradigma'),
                                            (int)$id
                                        )
                                    );
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="maradigma-meta-hint">
                            <?php esc_html_e(
                                'Tipos de servicio / barco que quieres incluir en el listing.',
                                'maradigma'
                            ); ?>
                        </p>
                    </div>

                    <!-- Tags -->
                    <div>
                        <span class="maradigma-meta-small-label">
                            <?php esc_html_e('Tags', 'maradigma'); ?>
                        </span><br />
                        <select id="maradigma_custom_tags"
                            name="maradigma_boats_page[custom_listing][tags][]"
                            class="widefat maradigma-remote-select"
                            multiple="multiple"
                            data-maradigma-source="tags"
                            data-multiple="1">
                            <?php foreach ($customTags as $id) : ?>
                                <option value="<?php echo (int)$id; ?>" selected>
                                    <?php
                                    echo esc_html(
                                        sprintf(
                                            /* translators: %d: tag ID. */
                                            __('Tag #%d', 'maradigma'),
                                            (int)$id
                                        )
                                    );
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="maradigma-meta-hint">
                            <?php esc_html_e(
                                'Tags específicos (por ejemplo Modern, Open...).',
                                'maradigma'
                            ); ?>
                        </p>
                    </div>

                    <!-- Builders -->
                    <div>
                        <span class="maradigma-meta-small-label">
                            <?php esc_html_e('Builders', 'maradigma'); ?>
                        </span><br />
                        <select id="maradigma_custom_builders"
                            name="maradigma_boats_page[custom_listing][boat_builders][]"
                            class="widefat maradigma-remote-select"
                            multiple="multiple"
                            data-maradigma-source="builders"
                            data-multiple="1">
                            <?php foreach ($customBuilders as $id) : ?>
                                <option value="<?php echo (int)$id; ?>" selected>
                                    <?php
                                    echo esc_html(
                                        sprintf(
                                            /* translators: %d: builder ID. */
                                            __('Builder #%d', 'maradigma'),
                                            (int)$id
                                        )
                                    );
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="maradigma-meta-hint">
                            <?php esc_html_e(
                                'Constructores concretos (Astondoa, Sunseeker, etc.).',
                                'maradigma'
                            ); ?>
                        </p>
                    </div>

                    <!-- Specific boats -->
                    <div>
                        <span class="maradigma-meta-small-label">
                            <?php esc_html_e('Specific boats (searchable)', 'maradigma'); ?>
                        </span><br />
                        <select id="maradigma_custom_boats"
                            name="maradigma_boats_page[custom_listing][boat_ids][]"
                            class="widefat maradigma-remote-select"
                            multiple="multiple"
                            data-maradigma-source="boats"
                            data-multiple="1">
                            <?php foreach ($customBoats as $id) : ?>
                                <option value="<?php echo (int)$id; ?>" selected>
                                    <?php
                                    echo esc_html(
                                        sprintf(
                                            /* translators: %d: boat ID. */
                                            __('Boat #%d', 'maradigma'),
                                            (int)$id
                                        )
                                    );
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="maradigma-meta-hint">
                            <?php esc_html_e(
                                'Barcos concretos seleccionados por búsqueda (no introducir IDs a mano).',
                                'maradigma'
                            ); ?>
                        </p>
                    </div>
                </div>

                <!-- Options -->
                <div class="maradigma-toggle-group">
                    <span class="maradigma-meta-small-label">
                        <?php esc_html_e('Options', 'maradigma'); ?>
                    </span>
                    <div class="maradigma-toggle-row">
                        <label class="maradigma-toggle-switch">
                            <input type="checkbox"
                                name="maradigma_boats_page[custom_listing][bareboat]"
                                value="1"
                                <?php checked($customBareboat); ?> />
                            <?php esc_html_e('Bareboat', 'maradigma'); ?>
                        </label>

                        <label class="maradigma-toggle-switch">
                            <input type="checkbox"
                                name="maradigma_boats_page[custom_listing][discount]"
                                value="1"
                                <?php checked($customDiscount); ?> />
                            <?php esc_html_e('Discount', 'maradigma'); ?>
                        </label>

                        <label class="maradigma-toggle-switch">
                            <input type="checkbox"
                                name="maradigma_boats_page[custom_listing][ins_book]"
                                value="1"
                                <?php checked($customInsBook); ?> />
                            <?php esc_html_e('Instant booking', 'maradigma'); ?>
                        </label>

                        <label class="maradigma-toggle-switch">
                            <input type="checkbox"
                                name="maradigma_boats_page[custom_listing][featured]"
                                value="1"
                                <?php checked($customFeatured); ?> />
                            <?php esc_html_e('Featured', 'maradigma'); ?>
                        </label>

                        <label class="maradigma-toggle-switch">
                            <input type="checkbox"
                                name="maradigma_boats_page[custom_listing][last_minute]"
                                value="1"
                                <?php checked($customLastMinute); ?> />
                            <?php esc_html_e('Last minute', 'maradigma'); ?>
                        </label>
                    </div>
                </div>
            </div>

        </div>
        <?php
    }

    /**
     * Save page meta when the post is saved.
     *
     * @param int      $postId
     * @param \WP_Post $post
     */
    public static function saveMeta(int $postId, \WP_Post $post): void
    {
        if ($post->post_type !== 'page') {
            return;
        }

        // Autosave / revisions
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Nonce check
        if (
            !isset($_POST['maradigma_boats_page_nonce']) ||
            !wp_verify_nonce(
                sanitize_text_field((string) wp_unslash($_POST['maradigma_boats_page_nonce'])),
                'maradigma_boats_page_meta'
            )
        ) {
            return;
        }

        // Permissions
        if (!current_user_can('edit_page', $postId)) {
            return;
        }

        if (!isset($_POST['maradigma_boats_page']) || !is_array($_POST['maradigma_boats_page'])) {
            // No hay datos = limpiamos ambos metas para dejar la página “normal”
            delete_post_meta($postId, self::META_KEY);
            delete_post_meta($postId, '_maradigma_boats_mode');
            return;
        }

        $raw = map_deep((array) wp_unslash($_POST['maradigma_boats_page']), 'sanitize_text_field');

        // ─────────────────────────────────────────
        // MODO (sincronizado con Gutenberg)
        // ─────────────────────────────────────────
        $mode = isset($raw['mode']) ? sanitize_text_field((string) $raw['mode']) : '';
        $allowedModes = ['', 'listing', 'by_type', 'multiple_types', 'custom_list'];
        if (!in_array($mode, $allowedModes, true)) {
            $mode = '';
        }

        // Guardamos el modo en el meta específico que usa el editor de bloques
        update_post_meta($postId, '_maradigma_boats_mode', $mode);

        // by_type
        $byTypeGcType = 0;
        if (isset($raw['by_type_gc_type'])) {
            $byTypeGcType = (int) $raw['by_type_gc_type'];
            if ($byTypeGcType < 0) {
                $byTypeGcType = 0;
            }
        }

        // multiple_types
        $multipleTypes = [];
        if (isset($raw['multiple_types_gc_type']) && is_array($raw['multiple_types_gc_type'])) {
            $multipleTypes = array_values(
                array_filter(
                    array_map('intval', $raw['multiple_types_gc_type']),
                    static fn(int $v): bool => $v > 0
                )
            );
        }

        // custom_listing
        $customRaw = isset($raw['custom_listing']) && is_array($raw['custom_listing'])
            ? $raw['custom_listing']
            : [];

        $customIdGcType = [];
        if (isset($customRaw['id_gc_type']) && is_array($customRaw['id_gc_type'])) {
            $customIdGcType = array_values(
                array_filter(
                    array_map('intval', $customRaw['id_gc_type']),
                    static fn(int $v): bool => $v > 0
                )
            );
        }

        $customTags = [];
        if (isset($customRaw['tags']) && is_array($customRaw['tags'])) {
            $customTags = array_values(
                array_filter(
                    array_map('intval', $customRaw['tags']),
                    static fn(int $v): bool => $v > 0
                )
            );
        }

        $customBuilders = [];
        if (isset($customRaw['boat_builders']) && is_array($customRaw['boat_builders'])) {
            $customBuilders = array_values(
                array_filter(
                    array_map('intval', $customRaw['boat_builders']),
                    static fn(int $v): bool => $v > 0
                )
            );
        }

        $customBoats = [];
        if (isset($customRaw['boat_ids']) && is_array($customRaw['boat_ids'])) {
            $customBoats = array_values(
                array_filter(
                    array_map('intval', $customRaw['boat_ids']),
                    static fn(int $v): bool => $v > 0
                )
            );
        }

        $customBareboat   = !empty($customRaw['bareboat']) ? 1 : 0;
        $customDiscount   = !empty($customRaw['discount']) ? 1 : 0;
        $customInsBook    = !empty($customRaw['ins_book']) ? 1 : 0;
        $customFeatured   = !empty($customRaw['featured']) ? 1 : 0;
        $customLastMinute = !empty($customRaw['last_minute']) ? 1 : 0;

        $meta = [
            'mode'                   => $mode,
            'by_type_gc_type'        => $byTypeGcType,
            'multiple_types_gc_type' => $multipleTypes,
            'custom_listing'         => [
                'id_gc_type'    => $customIdGcType,
                'tags'          => $customTags,
                'boat_builders' => $customBuilders,
                'boat_ids'      => $customBoats,
                'bareboat'      => $customBareboat,
                'discount'      => $customDiscount,
                'ins_book'      => $customInsBook,
                'featured'      => $customFeatured,
                'last_minute'   => $customLastMinute,
            ],
        ];

        // If everything is "empty", remove meta to avoid noise.
        $isCompletelyEmpty =
            $mode === '' &&
            $byTypeGcType === 0 &&
            $multipleTypes === [] &&
            $customIdGcType === [] &&
            $customTags === [] &&
            $customBuilders === [] &&
            $customBoats === [] &&
            !$customBareboat &&
            !$customDiscount &&
            !$customInsBook &&
            !$customFeatured &&
            !$customLastMinute;

        if ($isCompletelyEmpty) {
            delete_post_meta($postId, self::META_KEY);
            delete_post_meta($postId, '_maradigma_boats_mode');
        } else {
            update_post_meta($postId, self::META_KEY, $meta);
        }
    }

    /**
     * Inject boats listing in the frontend content according to page configuration.
     *
     * @param string $content
     * @return string
     */
    public static function maybeInjectBoatsListing(string $content): string
    {
        if (!is_singular('page') || !in_the_loop() || !is_main_query()) {
            return $content;
        }

        $postId = get_the_ID();
        if (!$postId) {
            return $content;
        }

        $meta = get_post_meta($postId, self::META_KEY, true);
        if (!is_array($meta)) {
            return $content;
        }

        $mode = (string)($meta['mode'] ?? '');
        if ($mode === '') {
            return $content;
        }

        $shortcode = '';
        $attrs     = [];

        switch ($mode) {
            case 'listing':
                // Plain listing with plugin defaults
                $shortcode = '[maradigma_boats]';
                break;

            case 'by_type':
                $byTypeGcType = isset($meta['by_type_gc_type']) ? (int)$meta['by_type_gc_type'] : 0;
                if ($byTypeGcType > 0) {
                    $attrs['gc_type'] = (string)$byTypeGcType;
                }
                break;

            case 'multiple_types':
                $multipleTypes = [];
                if (isset($meta['multiple_types_gc_type']) && is_array($meta['multiple_types_gc_type'])) {
                    $multipleTypes = array_values(
                        array_filter(
                            array_map('intval', $meta['multiple_types_gc_type']),
                            static fn(int $v): bool => $v > 0
                        )
                    );
                }
                if ($multipleTypes !== []) {
                    $attrs['gc_type'] = implode(',', $multipleTypes);
                }
                break;

            case 'custom_list':
                $custom = isset($meta['custom_listing']) && is_array($meta['custom_listing'])
                    ? $meta['custom_listing']
                    : [];

                $idGcType = isset($custom['id_gc_type']) && is_array($custom['id_gc_type'])
                    ? array_values(array_filter(array_map('intval', $custom['id_gc_type']), static fn(int $v): bool => $v > 0))
                    : [];

                $tags = isset($custom['tags']) && is_array($custom['tags'])
                    ? array_values(array_filter(array_map('intval', $custom['tags']), static fn(int $v): bool => $v > 0))
                    : [];

                $builders = isset($custom['boat_builders']) && is_array($custom['boat_builders'])
                    ? array_values(array_filter(array_map('intval', $custom['boat_builders']), static fn(int $v): bool => $v > 0))
                    : [];

                $boats = isset($custom['boat_ids']) && is_array($custom['boat_ids'])
                    ? array_values(array_filter(array_map('intval', $custom['boat_ids']), static fn(int $v): bool => $v > 0))
                    : [];

                if ($idGcType !== []) {
                    $attrs['gc_type'] = implode(',', $idGcType);
                }
                if ($tags !== []) {
                    $attrs['tags'] = implode(',', $tags);
                }
                if ($builders !== []) {
                    $attrs['builders'] = implode(',', $builders);
                }
                if ($boats !== []) {
                    $attrs['boats'] = implode(',', $boats);
                }

                if (!empty($custom['bareboat'])) {
                    $attrs['bareboat'] = '1';
                }
                if (!empty($custom['discount'])) {
                    $attrs['discount'] = '1';
                }
                if (!empty($custom['ins_book'])) {
                    $attrs['ins_book'] = '1';
                }
                if (!empty($custom['featured'])) {
                    $attrs['featured'] = '1';
                }
                if (!empty($custom['last_minute'])) {
                    $attrs['last_minute'] = '1';
                }
                break;
        }

        if ($shortcode === '' && $attrs === []) {
            // Nothing to inject / misconfigured
            return $content;
        }

        if ($shortcode === '') {
            // Build base shortcode with attributes
            $shortcode = '[maradigma_boats';
            foreach ($attrs as $key => $value) {
                // Values are numeric / CSV of numeric IDs → safe
                $shortcode .= ' ' . $key . '="' . $value . '"';
            }
            $shortcode .= ']';
        }

        $listingHtml = do_shortcode($shortcode);

        return $content . "\n\n" .
            '<div class="maradigma-boats-page-listing">' . $listingHtml . '</div>';
    }
}

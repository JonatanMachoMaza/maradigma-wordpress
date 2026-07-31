<?php

declare(strict_types=1);

namespace Maradigma\Integrations\WPBakery;

if (!defined('ABSPATH')) {
    exit;
}

use Maradigma\BoatPostType;
use Maradigma\BoatTemplateSyncAllService;
use Maradigma\SettingsPage;

/**
 * Bridges Maradigma boat pages with WPBakery Page Builder.
 *
 * WPBakery persists its layout as shortcode-based `post_content`, unlike
 * Elementor which stores JSON in post meta. This integration therefore owns
 * three responsibilities:
 *
 * - Register Maradigma shortcodes as native WPBakery elements.
 * - Maintain a private editable master template for synced boat pages.
 * - Propagate saved master-template changes through the shared batched template
 *   sync service, while preserving boats explicitly marked as custom layouts.
 */
final class WPBakeryIntegration
{
    /** Private CPT used only for the editable WPBakery boat master template. */
    public const TEMPLATE_POST_TYPE = 'maradigma_wpb_tpl';

    /** WPBakery element category shown in the builder element picker. */
    private const CATEGORY_NAME = 'Maradigma';

    /** Stores the canonical master-template post ID. */
    private const OPTION_MASTER_TEMPLATE_ID = 'maradigma_wpbakery_master_template_id';

    /** Shared layout marker consumed by sync/debug tooling. */
    private const META_LAYOUT_BUILDER = '_maradigma_layout_builder';

    /** Marks boat content that was originally created by Maradigma WPBakery sync. */
    private const META_WPBAKERY_SEEDED = '_maradigma_wpbakery_seeded';

    /** Hash of the currently stored WPBakery shortcode content on a boat post. */
    private const META_WPBAKERY_CONTENT_HASH = '_maradigma_wpbakery_content_hash';

    /** Hash of the master template used in the last successful boat update. */
    private const META_WPBAKERY_TEMPLATE_HASH = '_maradigma_wpbakery_template_hash';

    /** Per-boat opt-out flag: never overwrite this boat's WPBakery content. */
    private const META_WPBAKERY_CUSTOM_LAYOUT = '_maradigma_wpbakery_custom_layout';

    /** WPBakery flag that enables builder mode for shortcode content. */
    private const META_WPB_JS_STATUS = '_wpb_vc_js_status';

    /** Prevents duplicated hook registration. */
    private static bool $registered = false;

    /** Prevents recursive sync starts while the master template is being marked. */
    private static bool $handlingTemplateSave = false;

    /**
     * Registers WPBakery post type support, elements and template propagation.
     *
     * The integration may be loaded before WPBakery has finished booting, so
     * every WPBakery-specific registration is attached to the earliest safe hook
     * when the corresponding API is not available yet.
     */
    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        self::$registered = true;

        if (\did_action('init')) {
            self::registerTemplatePostType();
        } else {
            \add_action('init', [__CLASS__, 'registerTemplatePostType'], 1);
        }

        if (\did_action('vc_before_init') || \function_exists('vc_map')) {
            self::registerElements();
        } else {
            \add_action('vc_before_init', [__CLASS__, 'registerElements']);
        }

        if (\did_action('vc_after_init')) {
            self::ensureEditorPostTypes();
        } else {
            \add_action('vc_after_init', [__CLASS__, 'ensureEditorPostTypes']);
        }

        \add_filter('vc_check_post_type_validation', [__CLASS__, 'validateEditorPostType'], 10, 2);
        \add_action('save_post_' . self::TEMPLATE_POST_TYPE, [__CLASS__, 'handleMasterTemplateSaved'], 20, 3);
    }

    /** Backwards-compatible boot alias used by the plugin loader. */
    public static function init(): void
    {
        self::register();
    }

    /**
     * Returns whether WPBakery appears to be loaded in the current request.
     */
    public static function isAvailable(): bool
    {
        return \defined('WPB_VC_VERSION') || \class_exists('Vc_Manager') || \function_exists('vc_map');
    }

    /**
     * Registers the private post type that stores the editable master template.
     */
    public static function registerTemplatePostType(): void
    {
        if (\post_type_exists(self::TEMPLATE_POST_TYPE)) {
            return;
        }

        \register_post_type(self::TEMPLATE_POST_TYPE, [
            'labels' => [
                'name'               => \__('WPBakery templates', 'maradigma'),
                'singular_name'      => \__('WPBakery template', 'maradigma'),
                'add_new'            => \__('Add new', 'maradigma'),
                'add_new_item'       => \__('Add new WPBakery template', 'maradigma'),
                'edit_item'          => \__('Edit WPBakery template', 'maradigma'),
                'new_item'           => \__('New WPBakery template', 'maradigma'),
                'view_item'          => \__('View WPBakery template', 'maradigma'),
                'search_items'       => \__('Search WPBakery templates', 'maradigma'),
                'not_found'          => \__('No WPBakery templates found', 'maradigma'),
                'not_found_in_trash' => \__('No WPBakery templates found in trash', 'maradigma'),
                'all_items'          => \__('WPBakery templates', 'maradigma'),
                'menu_name'          => \__('WPBakery template', 'maradigma'),
            ],
            'public'              => false,
            'publicly_queryable'  => false,
            'exclude_from_search' => true,
            'show_ui'             => true,
            'show_in_menu'        => self::shouldShowTemplateMenu() ? 'maradigma-settings' : false,
            'show_in_rest'        => false,
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
     * Allows WPBakery to open both the master template CPT and synced boat CPT.
     *
     * @param bool|null $valid WPBakery's current validation decision.
     * @param string    $type  Post type being checked by WPBakery.
     */
    public static function validateEditorPostType($valid, string $type): ?bool
    {
        if (\in_array($type, [self::TEMPLATE_POST_TYPE, self::getBoatPostType()], true)) {
            return true;
        }

        return $valid;
    }
    /**
     * Adds Maradigma post types to WPBakery's editable post-type allow list.
     */
    public static function ensureEditorPostTypes(): void
    {
        if (!self::isAvailable() || !\function_exists('vc_editor_post_types') || !\function_exists('vc_editor_set_post_types')) {
            return;
        }

        $postTypes = (array) \vc_editor_post_types();
        $postTypes[] = self::getBoatPostType();
        $postTypes[] = self::TEMPLATE_POST_TYPE;

        $postTypes = \array_values(\array_unique(\array_filter(\array_map(
            static fn ($postType): string => \sanitize_key((string) $postType),
            $postTypes
        ))));

        try {
            \vc_editor_set_post_types($postTypes);
        } catch (\Throwable) {
            // WPBakery can throw while its settings are still booting.
        }
    }

    /**
     * Registers all Maradigma shortcode wrappers as WPBakery elements.
     */
    public static function registerElements(): void
    {
        if (!\function_exists('vc_map')) {
            return;
        }

        self::mapElement([
            'name'        => \__('Maradigma - Boats Archive', 'maradigma'),
            'base'        => 'maradigma_boats',
            'icon'        => 'dashicons dashicons-screenoptions',
            'description' => \__('Boat listing with filters and cards.', 'maradigma'),
            'params'      => self::archiveParams(),
        ]);

        self::mapElement([
            'name'        => \__('Maradigma - Boats Search', 'maradigma'),
            'base'        => 'maradigma_search',
            'icon'        => 'dashicons dashicons-search',
            'description' => \__('Search form that submits Maradigma filter parameters.', 'maradigma'),
            'params'      => self::searchParams(),
        ]);

        self::mapElement([
            'name'        => \__('Maradigma - Boat Detail', 'maradigma'),
            'base'        => 'maradigma_boat',
            'icon'        => 'dashicons dashicons-media-document',
            'description' => \__('Full single boat render.', 'maradigma'),
            'params'      => self::boatContextParams(),
        ]);

        foreach (self::singleBoatElements() as $base => $config) {
            self::mapElement(\array_merge([
                'base'   => $base,
                'icon'   => 'dashicons dashicons-palmtree',
                'params' => self::mergeParams(self::boatContextParams(), $config['params'] ?? []),
            ], $config));
        }
    }

    /** @param array<string,mixed> $settings */
    private static function mapElement(array $settings): void
    {
        $settings['category'] = self::CATEGORY_NAME;
        \vc_map($settings);
    }

    /** @return array<int,array<string,mixed>> */
    private static function archiveParams(): array
    {
        return [
            self::textfield('id_group', \__('Group', 'maradigma'), 'boats'),
            self::textfield('card', \__('Boat card template', 'maradigma'), ''),
            self::textfield('image_token', \__('Card image token', 'maradigma'), 'image_main'),
            self::number('limit_services', \__('Items per page', 'maradigma'), '10'),
            self::dropdown('order_by', \__('Default order', 'maradigma'), [
                \__('Relevance', 'maradigma') => '0',
                \__('Price: low to high', 'maradigma') => '1',
                \__('Price: high to low', 'maradigma') => '2',
                \__('Length: low to high', 'maradigma') => '6',
                \__('Length: high to low', 'maradigma') => '5',
                \__('Featured first', 'maradigma') => '3',
                \__('Newest first', 'maradigma') => '4',
            ], '0'),
            self::checkbox('show_filters', \__('Show filters form', 'maradigma')),
            self::checkbox('autosubmit_filters', \__('Autosubmit filters on change', 'maradigma'), '1'),
            self::checkbox('allow_url_filters', \__('Allow URL filters override defaults', 'maradigma'), '1'),
            self::textfield('filters_ui_fields', \__('Visible filter fields', 'maradigma'), 'date_start,boat_capacity,min_price,max_price'),
            self::textfield('filters_ui_fields_offcanvas', \__('Offcanvas filter fields', 'maradigma'), 'boat_type_id,builders,ids_gi'),
            self::dropdown('filters_ui_layout', \__('Filters layout', 'maradigma'), [
                \__('Horizontal', 'maradigma') => 'horizontal',
                \__('Vertical', 'maradigma') => 'vertical',
            ], 'horizontal'),
            self::dropdown('filters_ui_submit_mode', \__('Submit mode', 'maradigma'), [
                \__('Auto', 'maradigma') => 'auto',
                \__('Button', 'maradigma') => 'button',
            ], 'auto'),
            self::checkbox('filters_ui_show_reset', \__('Show reset link', 'maradigma'), '1'),
            self::checkbox('show_more_filters_button', \__('Show more filters button', 'maradigma'), '1'),
            self::textfield('more_filters_button_text', \__('More filters button text', 'maradigma'), 'More filters'),
            self::textfield('more_filters_offcanvas_title', \__('Offcanvas title', 'maradigma'), 'More filters'),
            self::dropdown('date_picker_mode', \__('Date picker mode', 'maradigma'), [
                \__('Range', 'maradigma') => 'range',
                \__('Separate fields', 'maradigma') => 'separate',
            ], 'range'),
            self::textfield('term', \__('Search term', 'maradigma'), ''),
            self::number('boat_capacity', \__('Minimum pax', 'maradigma'), ''),
            self::textfield('boat_type_id', \__('Boat type ID', 'maradigma'), ''),
            self::textfield('builders', \__('Builder IDs', 'maradigma'), ''),
            self::textfield('ids_gi', \__('Specific boat IDs', 'maradigma'), ''),
            self::checkbox('featured', \__('Featured only', 'maradigma')),
            self::checkbox('ins_book', \__('Instant booking only', 'maradigma')),
            self::number('min_price', \__('Min price', 'maradigma'), ''),
            self::number('max_price', \__('Max price', 'maradigma'), ''),
            self::textfield('date_start', \__('Start date', 'maradigma'), ''),
            self::textfield('date_end', \__('End date', 'maradigma'), ''),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private static function searchParams(): array
    {
        return [
            self::textfield('target_url', \__('Target URL', 'maradigma'), ''),
            self::checkbox('show_term', \__('Show search term', 'maradigma'), '1'),
            self::checkbox('show_dates', \__('Show dates', 'maradigma'), '1'),
            self::checkbox('show_passengers', \__('Show passengers', 'maradigma'), '1'),
            self::textfield('button_text', \__('Button text', 'maradigma'), 'Search'),
            self::textfield('placeholder', \__('Search placeholder', 'maradigma'), 'Search boats'),
        ];
    }
    /** @return array<int,array<string,mixed>> */
    private static function boatContextParams(): array
    {
        return [
            self::textfield('id', \__('Boat ID', 'maradigma'), ''),
            self::textfield('slug', \__('Boat slug', 'maradigma'), ''),
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private static function singleBoatElements(): array
    {
        return [
            'maradigma_boat_title' => [
                'name'        => \__('Maradigma - Boat Title', 'maradigma'),
                'description' => \__('Boat title/H1.', 'maradigma'),
                'params'      => self::titleParams(),
            ],
            'maradigma_boat_gallery' => [
                'name'        => \__('Maradigma - Boat Gallery', 'maradigma'),
                'description' => \__('Boat image gallery.', 'maradigma'),
                'params'      => [
                    self::dropdown('layout', \__('Layout', 'maradigma'), [
                        \__('Grid', 'maradigma') => 'grid',
                        \__('Slider', 'maradigma') => 'slider',
                    ], 'grid'),
                    self::number('columns', \__('Grid columns', 'maradigma'), '3'),
                    self::number('start_index', \__('Start image index', 'maradigma'), '1'),
                    self::number('end_index', \__('End image index', 'maradigma'), '0'),
                    self::number('max_images', \__('Max images', 'maradigma'), '12'),
                    self::textfield('prefer_size_key', \__('Preferred thumbnail size key', 'maradigma'), 'large'),
                    self::checkbox('prefer_wp_cache', \__('Prefer WP media cache', 'maradigma'), '1'),
                    self::checkbox('enable_lightbox', \__('Enable lightbox', 'maradigma'), '1'),
                    self::number('gap', \__('Gap', 'maradigma'), '10'),
                    self::number('radius', \__('Border radius', 'maradigma'), '10'),
                    self::checkbox('slider_autoplay', \__('Slider autoplay', 'maradigma'), '0'),
                    self::number('slider_delay', \__('Slider delay', 'maradigma'), '3500'),
                    self::checkbox('slider_loop', \__('Slider loop', 'maradigma'), '1'),
                    self::number('slider_speed', \__('Slider speed', 'maradigma'), '400'),
                    self::number('slider_space_between', \__('Slider space between', 'maradigma'), '10'),
                    self::number('slider_per_view_desktop', \__('Slides per view desktop', 'maradigma'), '1'),
                    self::number('slider_per_view_tablet', \__('Slides per view tablet', 'maradigma'), '1'),
                    self::number('slider_per_view_mobile', \__('Slides per view mobile', 'maradigma'), '1'),
                    self::checkbox('slider_navigation', \__('Slider navigation', 'maradigma'), '1'),
                    self::checkbox('slider_pagination', \__('Slider pagination', 'maradigma'), '1'),
                ],
            ],
            'maradigma_boat_videos' => [
                'name'        => \__('Maradigma - Boat Videos', 'maradigma'),
                'description' => \__('Boat videos.', 'maradigma'),
                'params'      => [
                    self::dropdown('layout', \__('Layout', 'maradigma'), [
                        \__('Grid', 'maradigma') => 'grid',
                        \__('List', 'maradigma') => 'list',
                    ], 'grid'),
                    self::dropdown('mode', \__('Mode', 'maradigma'), [
                        \__('Embed', 'maradigma') => 'embed',
                        \__('Thumbnail link', 'maradigma') => 'thumbnail',
                    ], 'embed'),
                    self::number('columns', \__('Columns', 'maradigma'), '2'),
                    self::number('max_videos', \__('Max videos', 'maradigma'), '12'),
                    self::checkbox('show_title', \__('Show title', 'maradigma'), '0'),
                    self::textfield('class', \__('CSS class', 'maradigma'), ''),
                ],
            ],
            'maradigma_boat_specs' => [
                'name'        => \__('Maradigma - Boat Specs', 'maradigma'),
                'description' => \__('Main boat specifications.', 'maradigma'),
                'params'      => [
                    self::textfield('fields', \__('Fields', 'maradigma'), 'boat_type,boat_builder,boat_model,boat_capacity,boat_cabins,boat_length,boat_beam,boat_consumption,boat_base_port_name'),
                    self::dropdown('layout', \__('Layout', 'maradigma'), [
                        \__('Two columns', 'maradigma') => 'two_cols',
                        \__('List', 'maradigma') => 'list',
                    ], 'two_cols'),
                    self::checkbox('show_labels', \__('Show labels', 'maradigma'), '1'),
                    self::checkbox('show_icons', \__('Show icons', 'maradigma'), '1'),
                    self::textfield('icons', \__('Icons map', 'maradigma'), ''),
                ],
            ],
            'maradigma_boat_description' => [
                'name'        => \__('Maradigma - Boat Description', 'maradigma'),
                'description' => \__('Boat description.', 'maradigma'),
                'params'      => [
                    self::dropdown('type', \__('Description type', 'maradigma'), [
                        \__('Automatic', 'maradigma') => 'auto',
                        \__('Short', 'maradigma') => 'short',
                        \__('Long', 'maradigma') => 'long',
                    ], 'auto'),
                    self::checkbox('allow_html', \__('Allow HTML', 'maradigma'), '1'),
                    self::checkbox('esc', \__('Escape text output', 'maradigma'), '0'),
                    self::number('max_words', \__('Max words', 'maradigma'), '0'),
                    self::textfield('fallback', \__('Fallback', 'maradigma'), ''),
                ],
            ],
            'maradigma_boat_prices' => [
                'name'        => \__('Maradigma - Boat Prices', 'maradigma'),
                'description' => \__('Rates and prices table.', 'maradigma'),
                'params'      => self::priceParams(),
            ],
            'maradigma_boat_price' => [
                'name'        => \__('Maradigma - Boat Price From', 'maradigma'),
                'description' => \__('Price block. This shortcode uses the same renderer as Boat Prices.', 'maradigma'),
                'params'      => self::priceParams(),
            ],
            'maradigma_boat_equipments' => [
                'name'        => \__('Maradigma - Boat Equipments', 'maradigma'),
                'description' => \__('Equipment list.', 'maradigma'),
                'params'      => self::listParams(\__('Equipments', 'maradigma'), 'show_icon', \__('Icon text', 'maradigma'), ''),
            ],
            'maradigma_boat_included' => [
                'name'        => \__('Maradigma - Boat Included', 'maradigma'),
                'description' => \__('Included services.', 'maradigma'),
                'params'      => self::listParams(\__('Included', 'maradigma'), 'show_tick_icon', \__('Tick text', 'maradigma'), ''),
            ],
            'maradigma_boat_not_included' => [
                'name'        => \__('Maradigma - Boat Not Included', 'maradigma'),
                'description' => \__('Not included services.', 'maradigma'),
                'params'      => self::listParams(\__('Not included', 'maradigma'), 'show_cross_icon', \__('Cross text', 'maradigma'), ''),
            ],
            'maradigma_boat_additional_services' => [
                'name'        => \__('Maradigma - Boat Additional Services', 'maradigma'),
                'description' => \__('Additional services.', 'maradigma'),
                'params'      => self::additionalServicesParams(),
            ],
            'maradigma_boat_pdf_download' => [
                'name'        => \__('Maradigma - Boat PDF Download', 'maradigma'),
                'description' => \__('Boat PDF button.', 'maradigma'),
                'params'      => [
                    self::textfield('button_text', \__('Button text', 'maradigma'), 'Download PDF'),
                    self::checkbox('open_in_new_tab', \__('Open in new tab', 'maradigma'), '1'),
                    self::checkbox('force_download', \__('Force download', 'maradigma'), '0'),
                    self::textfield('fallback', \__('Fallback', 'maradigma'), ''),
                ],
            ],
            'maradigma_boat_calendar' => [
                'name'        => \__('Maradigma - Boat Calendar', 'maradigma'),
                'description' => \__('Boat availability calendar.', 'maradigma'),
                'params'      => [
                    self::number('months', \__('Months', 'maradigma'), '12'),
                    self::dropdown('start_month', \__('Start month', 'maradigma'), [
                        \__('Current month', 'maradigma') => 'current',
                        \__('Next month', 'maradigma') => 'next',
                    ], 'current'),
                    self::checkbox('show_legend', \__('Show legend', 'maradigma'), '1'),
                    self::colorpicker('color_available', \__('Available color', 'maradigma'), '#d4edda'),
                    self::colorpicker('color_booked', \__('Booked color', 'maradigma'), '#ffc0bd'),
                    self::colorpicker('color_option', \__('Option color', 'maradigma'), '#ffe8a1'),
                    self::textfield('option_statuses', \__('Option status IDs', 'maradigma'), '4'),
                    self::checkbox('include_booking_status', \__('Include booking status', 'maradigma'), '1'),
                ],
            ],
            'maradigma_boat_booking' => [
                'name'        => \__('Maradigma - Boat Booking', 'maradigma'),
                'description' => \__('Booking/request form.', 'maradigma'),
                'params'      => [
                    self::textfield('button_text', \__('Button text', 'maradigma'), 'Book now'),
                    self::textfield('redirect_url_success', \__('Success redirect URL', 'maradigma'), ''),
                    self::dropdown('calendar_display', \__('Calendar display', 'maradigma'), [
                        \__('Inline', 'maradigma') => 'inline',
                        \__('Popup', 'maradigma') => 'popup',
                    ], 'inline'),
                    self::dropdown('calendar_months', \__('Calendar months', 'maradigma'), [
                        '1' => '1',
                        '2' => '2',
                    ], '1'),
                    self::dropdown('calendar_selection_mode', \__('Calendar selection mode', 'maradigma'), [
                        \__('Range', 'maradigma') => 'range',
                        \__('Single day', 'maradigma') => 'single',
                    ], 'range'),
                    self::checkbox('show_schedule_text', \__('Show schedule text', 'maradigma'), '1'),
                    self::checkbox('show_promo_code', \__('Show promo code', 'maradigma'), '1'),
                    self::checkbox('show_children_included', \__('Show children included', 'maradigma'), '1'),
                    self::dropdown('free_additional_label', \__('Free additional label', 'maradigma'), [
                        \__('Free', 'maradigma') => 'free',
                        \__('Included', 'maradigma') => 'included',
                    ], 'free'),
                ],
            ],
            'maradigma_boat_field' => [
                'name'        => \__('Maradigma - Boat Field', 'maradigma'),
                'description' => \__('Any boat field.', 'maradigma'),
                'params'      => [
                    self::textfield('field', \__('Field key', 'maradigma'), 'service_name'),
                    self::checkbox('esc', \__('Escape output', 'maradigma'), '1'),
                    self::textfield('fallback', \__('Fallback', 'maradigma'), ''),
                    self::dropdown('format', \__('Format', 'maradigma'), [
                        \__('Raw', 'maradigma') => 'raw',
                        \__('Text', 'maradigma') => 'text',
                        \__('Number', 'maradigma') => 'number',
                    ], 'raw'),
                    self::number('decimals', \__('Decimals', 'maradigma'), '0'),
                ],
            ],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private static function titleParams(): array
    {
        return [
            self::textfield('fallback', \__('Fallback', 'maradigma'), ''),
            self::checkbox('show_builder', \__('Show builder', 'maradigma'), '1'),
            self::checkbox('show_model', \__('Show model', 'maradigma'), '1'),
            self::checkbox('show_alias', \__('Show alias', 'maradigma'), '1'),
            self::checkbox('quote_alias', \__('Quote alias', 'maradigma'), '1'),
            self::checkbox('fallback_service_name', \__('Fallback to service name', 'maradigma'), '1'),
            self::textfield('separator', \__('Separator', 'maradigma'), ' '),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private static function priceParams(): array
    {
        return [
            self::textfield('title', \__('Title', 'maradigma'), 'Prices'),
            self::checkbox('show_title', \__('Show title', 'maradigma'), '1'),
            self::textfield('fallback', \__('Fallback', 'maradigma'), 'Prices not available.'),
            self::textfield('empty_text', \__('Empty text', 'maradigma'), ''),
            self::dropdown('layout', \__('Layout', 'maradigma'), [
                \__('Cards', 'maradigma') => 'cards',
                \__('Table', 'maradigma') => 'table',
            ], 'cards'),
            self::dropdown('range_mode', \__('Range mode', 'maradigma'), [
                \__('Short dates', 'maradigma') => 'dates_short',
                \__('Full dates', 'maradigma') => 'dates_full',
                \__('Season name', 'maradigma') => 'season',
            ], 'dates_short'),
            self::dropdown('order_by', \__('Order by', 'maradigma'), [
                \__('Date from ascending', 'maradigma') => 'date_from_asc',
                \__('Date from descending', 'maradigma') => 'date_from_desc',
                \__('Price ascending', 'maradigma') => 'price_asc',
                \__('Price descending', 'maradigma') => 'price_desc',
            ], 'date_from_asc'),
            self::checkbox('show_headers', \__('Show headers', 'maradigma'), '1'),
            self::number('row_gap', \__('Row gap', 'maradigma'), '10'),
            self::dropdown('vat_mode', \__('VAT mode', 'maradigma'), [
                \__('Included', 'maradigma') => 'included',
                \__('Excluded', 'maradigma') => 'excluded',
                \__('Hide VAT text', 'maradigma') => 'hidden',
            ], 'included'),
            self::checkbox('vat_use_backend', \__('Use backend VAT setting', 'maradigma'), '0'),
            self::dropdown('vat_position', \__('VAT text position', 'maradigma'), [
                \__('Below price', 'maradigma') => 'below',
                \__('Inline', 'maradigma') => 'inline',
                \__('Hidden', 'maradigma') => 'hidden',
            ], 'below'),
            self::textfield('vat_text_included', \__('VAT included text', 'maradigma'), 'VAT included'),
            self::textfield('vat_text_excluded', \__('VAT excluded text', 'maradigma'), '+ VAT'),
            self::dropdown('currency_display', \__('Currency display', 'maradigma'), [
                \__('Symbol', 'maradigma') => 'symbol',
                \__('Code', 'maradigma') => 'code',
            ], 'symbol'),
            self::dropdown('decimals_mode', \__('Decimals mode', 'maradigma'), [
                \__('Auto', 'maradigma') => 'auto',
                \__('Always', 'maradigma') => 'always',
                \__('Never', 'maradigma') => 'never',
            ], 'auto'),
            self::textfield('thousands_sep', \__('Thousands separator', 'maradigma'), '.'),
            self::textfield('decimal_sep', \__('Decimal separator', 'maradigma'), ','),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private static function listParams(string $defaultTitle, string $iconFlag, string $iconLabel, string $iconDefault): array
    {
        return [
            self::textfield('title', \__('Title', 'maradigma'), $defaultTitle),
            self::checkbox('show_title', \__('Show title', 'maradigma'), '1'),
            self::checkbox($iconFlag, $iconLabel, $iconFlag === 'show_icon' ? '0' : '1'),
            self::textfield($iconFlag === 'show_icon' ? 'icon_text' : ($iconFlag === 'show_tick_icon' ? 'tick_text' : 'cross_text'), $iconLabel, $iconDefault),
            self::checkbox('sort', \__('Sort alphabetically', 'maradigma'), '1'),
            self::textfield('fallback', \__('Fallback', 'maradigma'), ''),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private static function additionalServicesParams(): array
    {
        return [
            self::textfield('title', \__('Title', 'maradigma'), 'Additional services'),
            self::checkbox('show_title', \__('Show title', 'maradigma'), '1'),
            self::textfield('fallback', \__('Fallback', 'maradigma'), ''),
            self::dropdown('layout', \__('Layout', 'maradigma'), [
                \__('Blocks', 'maradigma') => 'blocks',
                \__('Table', 'maradigma') => 'table',
            ], 'blocks'),
            self::checkbox('show_headers', \__('Show headers', 'maradigma'), '1'),
            self::checkbox('group_by_category', \__('Group by category', 'maradigma'), '1'),
            self::checkbox('show_category_title', \__('Show category title', 'maradigma'), '1'),
            self::checkbox('show_badges', \__('Show badges', 'maradigma'), '1'),
            self::checkbox('show_badge_optional_type', \__('Show optional type badge', 'maradigma'), '1'),
            self::checkbox('show_badge_price_type', \__('Show price type badge', 'maradigma'), '1'),
            self::checkbox('show_badge_payment', \__('Show payment badge', 'maradigma'), '1'),
            self::dropdown('badge_style', \__('Badge style', 'maradigma'), [
                \__('Friendly', 'maradigma') => 'friendly',
                \__('Plain', 'maradigma') => 'plain',
            ], 'friendly'),
            self::checkbox('show_description', \__('Show description', 'maradigma'), '0'),
            self::checkbox('show_quantity', \__('Show quantity', 'maradigma'), '1'),
            self::dropdown('price_display', \__('Price display', 'maradigma'), [
                \__('Total HTML', 'maradigma') => 'total_html',
                \__('Total', 'maradigma') => 'total',
                \__('Base', 'maradigma') => 'base',
            ], 'total_html'),
            self::dropdown('vat_mode', \__('VAT mode', 'maradigma'), [
                \__('Included', 'maradigma') => 'included',
                \__('Excluded', 'maradigma') => 'excluded',
                \__('Hide VAT text', 'maradigma') => 'hidden',
            ], 'included'),
            self::dropdown('vat_position', \__('VAT text position', 'maradigma'), [
                \__('Below price', 'maradigma') => 'below',
                \__('Inline', 'maradigma') => 'inline',
                \__('Hidden', 'maradigma') => 'hidden',
            ], 'below'),
            self::textfield('vat_text_included', \__('VAT included text', 'maradigma'), 'VAT included'),
            self::textfield('vat_text_excluded', \__('VAT excluded text', 'maradigma'), '+ VAT'),
            self::dropdown('currency_display', \__('Currency display', 'maradigma'), [
                \__('Symbol', 'maradigma') => 'symbol',
                \__('Code', 'maradigma') => 'code',
            ], 'symbol'),
            self::dropdown('decimals_mode', \__('Decimals mode', 'maradigma'), [
                \__('Auto', 'maradigma') => 'auto',
                \__('Always', 'maradigma') => 'always',
                \__('Never', 'maradigma') => 'never',
            ], 'auto'),
            self::textfield('thousands_sep', \__('Thousands separator', 'maradigma'), '.'),
            self::textfield('decimal_sep', \__('Decimal separator', 'maradigma'), ','),
        ];
    }

    /** @return array<string,mixed> */
    private static function colorpicker(string $name, string $label, string $value = ''): array
    {
        return ['type' => 'colorpicker', 'heading' => $label, 'param_name' => $name, 'value' => $value];
    }
    /** @param array<int,array<string,mixed>> ...$groups @return array<int,array<string,mixed>> */
    private static function mergeParams(array ...$groups): array
    {
        $params = [];
        foreach ($groups as $group) {
            foreach ($group as $param) {
                $params[] = $param;
            }
        }

        return $params;
    }

    /** @return array<string,mixed> */
    private static function textfield(string $name, string $label, string $value = ''): array
    {
        return ['type' => 'textfield', 'heading' => $label, 'param_name' => $name, 'value' => $value];
    }

    /** @return array<string,mixed> */
    private static function number(string $name, string $label, string $value = ''): array
    {
        return ['type' => 'textfield', 'heading' => $label, 'param_name' => $name, 'value' => $value];
    }

    /** @param array<string,string> $values @return array<string,mixed> */
    private static function dropdown(string $name, string $label, array $values, string $value = ''): array
    {
        return ['type' => 'dropdown', 'heading' => $label, 'param_name' => $name, 'value' => $values, 'std' => $value];
    }

    /** @return array<string,mixed> */
    private static function checkbox(string $name, string $label, string $value = ''): array
    {
        return [
            'type'       => 'checkbox',
            'heading'    => $label,
            'param_name' => $name,
            'value'      => [\__('Yes', 'maradigma') => '1'],
            'std'        => $value,
        ];
    }

    /**
     * Returns the master template ID, creating a default one when needed.
     */
    public static function ensureWPBakeryMasterTemplate(): int
    {
        if (!\post_type_exists(self::TEMPLATE_POST_TYPE)) {
            self::registerTemplatePostType();
        }

        $existingId = (int) \get_option(self::OPTION_MASTER_TEMPLATE_ID, 0);
        if ($existingId > 0) {
            $existing = \get_post($existingId);
            if ($existing && $existing->post_type === self::TEMPLATE_POST_TYPE) {
                return $existingId;
            }
        }

        $query = new \WP_Query([
            'post_type'              => self::TEMPLATE_POST_TYPE,
            'post_status'            => ['publish', 'draft', 'private'],
            'posts_per_page'         => 1,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ]);

        $posts = \is_array($query->posts) ? $query->posts : [];
        if (!empty($posts)) {
            $id = (int) $posts[0];
            \update_option(self::OPTION_MASTER_TEMPLATE_ID, $id, false);
            self::markAsWPBakery($id, self::getPostContent($id));
            return $id;
        }

        $postId = \wp_insert_post([
            'post_type'    => self::TEMPLATE_POST_TYPE,
            'post_status'  => 'publish',
            'post_title'   => 'Maradigma - Boat Template (WPBakery)',
            'post_content' => self::getDefaultBoatTemplateContent(),
        ], true);

        if (\is_wp_error($postId) || (int) $postId <= 0) {
            return 0;
        }

        $postId = (int) $postId;
        \update_option(self::OPTION_MASTER_TEMPLATE_ID, $postId, false);
        self::markAsWPBakery($postId, self::getPostContent($postId));

        return $postId;
    }

    /**
     * Returns the shortcode content currently stored in the WPBakery master template.
     */
    public static function getWPBakeryMasterTemplateContent(): string
    {
        $id = self::ensureWPBakeryMasterTemplate();
        if ($id <= 0) {
            return self::getDefaultBoatTemplateContent();
        }

        $content = self::getPostContent($id);
        return \trim($content) !== '' ? $content : self::getDefaultBoatTemplateContent();
    }

    /** Returns the admin edit URL for the WPBakery master template. */
    public static function getWPBakeryMasterTemplateEditUrl(): string
    {
        $id = self::ensureWPBakeryMasterTemplate();
        return $id > 0 ? \admin_url('post.php?post=' . $id . '&action=edit') : '';
    }

    /**
     * Replaces the master template content with the plugin default layout.
     */
    public static function resetWPBakeryMasterTemplateToDefault(): int
    {
        $id = self::ensureWPBakeryMasterTemplate();
        if ($id <= 0) {
            return 0;
        }

        \wp_update_post([
            'ID'           => $id,
            'post_content' => self::getDefaultBoatTemplateContent(),
        ]);
        self::markAsWPBakery($id, self::getPostContent($id));

        return $id;
    }

    /**
     * Starts batched propagation after the canonical WPBakery master template is saved.
     *
     * This mirrors the Elementor/Gutenberg template workflow: editing the master
     * template updates all managed boat pages, while boats marked with the custom
     * WPBakery layout flag remain untouched unless a manual force sync is used.
     * The first batch is processed immediately so the save action has visible
     * effect without waiting for a separate settings-page AJAX pump.
     *
     * @param int      $postId Saved template post ID.
     * @param \WP_Post $post   Saved template post object.
     * @param bool     $update Whether WordPress considers this an update.
     */
    public static function handleMasterTemplateSaved(int $postId, \WP_Post $post, bool $update): void
    {
        if (self::$handlingTemplateSave) {
            return;
        }

        if ($postId <= 0 || $post->post_type !== self::TEMPLATE_POST_TYPE) {
            return;
        }

        if (\defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (\wp_is_post_revision($postId) || \wp_is_post_autosave($postId)) {
            return;
        }

        if (\in_array((string) $post->post_status, ['auto-draft', 'trash'], true)) {
            return;
        }

        $settings = \class_exists(SettingsPage::class) ? SettingsPage::getSettings() : [];
        if (empty($settings['enable_boat_pages_sync']) || self::getConfiguredLayoutBuilder() !== 'wpbakery') {
            return;
        }

        $masterId = (int) \get_option(self::OPTION_MASTER_TEMPLATE_ID, 0);
        if ($masterId <= 0 || $masterId !== $postId) {
            return;
        }

        $content = self::getPostContent($postId);
        if (\trim($content) === '') {
            return;
        }

        self::$handlingTemplateSave = true;

        try {
            self::markAsWPBakery($postId, $content);

            if (\class_exists(BoatTemplateSyncAllService::class) && \method_exists(BoatTemplateSyncAllService::class, 'start')) {
                BoatTemplateSyncAllService::start(100, 'wpbakery', 'overwrite');

                if (\method_exists(BoatTemplateSyncAllService::class, 'tick')) {
                    BoatTemplateSyncAllService::tick();
                }
            }
        } finally {
            self::$handlingTemplateSave = false;
        }
    }

    /**
     * Applies the master WPBakery shortcode layout to a synced boat post.
     *
     * Modes:
     * - seed_missing: only writes to empty boat content.
     * - overwrite: updates content previously managed by Maradigma WPBakery sync.
     * - force: overwrites regardless of custom-layout markers.
     */
    public static function applyWPBakeryTemplateToBoatPost(int $postId, string $mode = 'seed_missing'): bool
    {
        if ($postId <= 0) {
            return false;
        }

        $post = \get_post($postId);
        if (!$post || $post->post_type !== self::getBoatPostType()) {
            return false;
        }

        $mode = \sanitize_key($mode);
        if (!\in_array($mode, ['seed_missing', 'overwrite', 'force'], true)) {
            $mode = 'seed_missing';
        }

        if ($mode !== 'force' && (bool) \get_post_meta($postId, self::META_WPBAKERY_CUSTOM_LAYOUT, true)) {
            return false;
        }

        $current = self::getPostContent($postId);
        if ($mode === 'seed_missing' && \trim($current) !== '') {
            return false;
        }

        if ($mode === 'overwrite' && !self::isManagedWPBakeryLayout($postId)) {
            return false;
        }

        $templateContent = self::getWPBakeryMasterTemplateContent();
        if (\trim($templateContent) === '') {
            return false;
        }

        $result = \wp_update_post([
            'ID'           => $postId,
            'post_content' => $templateContent,
        ], true);

        if (\is_wp_error($result) || (int) $result <= 0) {
            return false;
        }

        self::markAsWPBakery($postId, self::getPostContent($postId));
        \update_post_meta($postId, self::META_WPBAKERY_TEMPLATE_HASH, self::hashWPBakeryTemplateContent($templateContent));

        return true;
    }

    /**
     * Determines whether the post content matches the current WPBakery master template.
     */
    public static function postMatchesWPBakeryMasterTemplate(int $postId): bool
    {
        if ($postId <= 0) {
            return false;
        }

        $content = self::getPostContent($postId);
        if (\trim($content) === '') {
            return false;
        }

        return \hash_equals(
            self::hashWPBakeryTemplateContent(self::getWPBakeryMasterTemplateContent()),
            self::hashWPBakeryTemplateContent($content)
        );
    }

    /**
     * Returns WPBakery template apply skip reason.
     */
    public static function getWPBakeryTemplateApplySkipReason(int $postId, string $mode = 'seed_missing'): string
    {
        if ($postId <= 0) {
            return 'invalid_post';
        }

        if ((bool) \get_post_meta($postId, self::META_WPBAKERY_CUSTOM_LAYOUT, true) && \sanitize_key($mode) !== 'force') {
            return 'custom_wpbakery_layout';
        }

        $content = self::getPostContent($postId);
        if (\sanitize_key($mode) === 'seed_missing' && \trim($content) !== '') {
            return 'content_not_empty';
        }

        if (\sanitize_key($mode) === 'overwrite' && !self::isManagedWPBakeryLayout($postId)) {
            return 'not_managed_wpbakery_layout';
        }

        return 'not_applied_unknown';
    }

    /** @return array<string,string|int|bool> */
    public static function getWPBakeryTemplatePersistenceDebug(int $postId): array
    {
        $template = self::getWPBakeryMasterTemplateContent();
        $stored = self::getPostContent($postId);

        return [
            'post_id'       => $postId,
            'template_len'  => \strlen($template),
            'stored_len'    => \strlen($stored),
            'template_hash' => self::hashWPBakeryTemplateContent($template),
            'stored_hash'   => self::hashWPBakeryTemplateContent($stored),
            'post_matches'  => self::postMatchesWPBakeryMasterTemplate($postId),
        ];
    }

    /**
     * Normalizes shortcode line endings and returns a stable content hash.
     */
    public static function hashWPBakeryTemplateContent(string $content): string
    {
        return \md5(\trim(\str_replace(["\r\n", "\r"], "\n", $content)));
    }

    /**
     * Default shortcode-based detail layout used when no master template exists.
     */
    public static function getDefaultBoatTemplateContent(): string
    {
        return \implode("\n", [
            '[vc_row][vc_column][maradigma_boat_title show_builder="1" show_model="1" show_alias="1" quote_alias="1"][/vc_column][/vc_row]',
            '[vc_row][vc_column][maradigma_boat_gallery layout="grid" columns="3" max_images="12" enable_lightbox="1" gap="10" radius="10"][/vc_column][/vc_row]',
            '[vc_row][vc_column width="2/3"][maradigma_boat_description type="auto" allow_html="1" max_words="0"][maradigma_boat_specs fields="boat_capacity,boat_cabins,boat_length,boat_base_port_name" layout="two_cols" show_labels="1" show_icons="1"][/vc_column][vc_column width="1/3"][maradigma_boat_pdf_download button_text="Download PDF" open_in_new_tab="1"][maradigma_boat_booking button_text="Book now" calendar_display="inline" calendar_months="1" calendar_selection_mode="range" show_promo_code="1"][/vc_column][/vc_row]',
            '[vc_row][vc_column][maradigma_boat_prices layout="table" show_title="1" show_headers="1" vat_mode="included"][/vc_column][/vc_row]',
            '[vc_row][vc_column width="1/2"][maradigma_boat_equipments show_title="1"][maradigma_boat_included show_title="1"][/vc_column][vc_column width="1/2"][maradigma_boat_not_included show_title="1"][maradigma_boat_additional_services show_title="1" layout="blocks"][/vc_column][/vc_row]',
            '[vc_row][vc_column][maradigma_boat_calendar months="12" start_month="current" show_legend="1" include_booking_status="1"][/vc_column][/vc_row]',
        ]);
    }

    /**
     * Marks as WPBakery.
     */
    private static function markAsWPBakery(int $postId, string $content): void
    {
        \update_post_meta($postId, self::META_LAYOUT_BUILDER, 'wpbakery');
        \update_post_meta($postId, self::META_WPB_JS_STATUS, 'true');
        \update_post_meta($postId, self::META_WPBAKERY_SEEDED, '1');
        \update_post_meta($postId, self::META_WPBAKERY_CONTENT_HASH, self::hashWPBakeryTemplateContent($content));
    }

    /**
     * Determines whether managed WPBakery layout.
     */
    private static function isManagedWPBakeryLayout(int $postId): bool
    {
        return (bool) \get_post_meta($postId, self::META_WPBAKERY_SEEDED, true)
            || \get_post_meta($postId, self::META_LAYOUT_BUILDER, true) === 'wpbakery';
    }

    /**
     * Returns post content.
     */
    private static function getPostContent(int $postId): string
    {
        return (string) \get_post_field('post_content', $postId);
    }

    /**
     * Returns boat post type.
     */
    private static function getBoatPostType(): string
    {
        if (\class_exists(BoatPostType::class) && \defined(BoatPostType::class . '::POST_TYPE')) {
            return (string) \constant(BoatPostType::class . '::POST_TYPE');
        }

        return 'maradigma_boat';
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
            && self::getConfiguredLayoutBuilder() === 'wpbakery';
    }
}

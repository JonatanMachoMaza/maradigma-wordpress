<?php

declare(strict_types=1);

namespace Maradigma;

if (!defined('ABSPATH')) {
    exit;
}

use RuntimeException;
use Maradigma\Admin\AvailableBoatsPage;
use Maradigma\Admin\BoatCardsActions;
use Maradigma\Admin\SettingsHelpTab;
use Maradigma\Admin\SettingsImageSyncActions;
use Maradigma\Admin\SettingsLogActions;
use Maradigma\Admin\SettingsNotices;
use Maradigma\Admin\SettingsSyncActions;
use Maradigma\Admin\SettingsTemplateActions;
use Maradigma\Admin\SettingsTemplateStats;
use Maradigma\Support\MultilangAdapter;

/**
 * Admin settings for Maradigma plugin.
 */
final class SettingsPage
{
    public const OPTION_KEY = 'maradigma_settings';

    private const OPTION_LAST_SYNC_AT    = 'maradigma_boats_last_sync_at';
    private const OPTION_LAST_SYNC_COUNT = 'maradigma_boats_last_sync_count';
    private const OPTION_LAST_SYNC_STATS = 'maradigma_boats_last_sync_stats';

    /**
     * Admin page hook suffix (returned by add_menu_page).
     * Example: 'toplevel_page_maradigma-settings'
     */
    private static ?string $adminPageHook = null;
    private static ?string $adminApiBoatsHook = null;

    /**
     * Registers the component's WordPress hooks.
     */
    public static function init(): void
    {
        add_action('admin_menu', [__CLASS__, 'registerMenu']);
        add_action('admin_init', [__CLASS__, 'registerSettings']);

        add_action('admin_post_maradigma_images_sync_start', [SettingsImageSyncActions::class, 'start']);
        add_action('admin_post_maradigma_images_sync_stop',  [SettingsImageSyncActions::class, 'stop']);
        add_action('admin_post_maradigma_images_sync_reset', [SettingsImageSyncActions::class, 'reset']);

        add_action('admin_post_maradigma_boat_cards', [BoatCardsActions::class, 'handle']);
        add_action('admin_post_maradigma_sync_boats', [SettingsSyncActions::class, 'syncBoats']);
        add_action('admin_post_maradigma_flush_cache', [SettingsSyncActions::class, 'flushCache']);
        add_action('admin_post_maradigma_refresh_api_boats', [AvailableBoatsPage::class, 'refresh']);
        add_action('admin_post_maradigma_delete_all_boats', [SettingsSyncActions::class, 'deleteAllBoats']);

        add_action('admin_post_maradigma_force_template_sync', [SettingsTemplateActions::class, 'startTemplateSync']);
        add_action('admin_post_maradigma_force_template_sync_stop', [SettingsTemplateActions::class, 'stopTemplateSync']);
        add_action('admin_post_maradigma_open_gutenberg_template', [SettingsTemplateActions::class, 'openGutenbergTemplate']);
        add_action('admin_post_maradigma_reset_gutenberg_template', [SettingsTemplateActions::class, 'resetGutenbergTemplate']);
        add_action('admin_post_maradigma_import_boat_elementor_template', [SettingsTemplateActions::class, 'importBoatIntoElementorMasterTemplate']);

        add_action('admin_post_maradigma_download_log', [SettingsLogActions::class, 'download']);
        add_action('admin_post_maradigma_clear_log', [SettingsLogActions::class, 'clear']);
    }

    /**
     * Registers menu.
     */
    public static function registerMenu(): void
    {
        $pageTitle  = __('Maradigma', 'maradigma');
        $menuTitle  = 'Maradigma';
        $capability = 'manage_options';

        // Parent menu slug (must match BoatPostType 'show_in_menu')
        $menuSlug = 'maradigma-settings';

        $callback = [__CLASS__, 'renderSettingsPage'];

        $iconUrl  = MARADIGMA_PLUGIN_URL . 'assets/img/maradigma-icon-20x20.png';
        $position = 58;

        // Main page
        self::$adminPageHook = add_menu_page(
            $pageTitle,
            $menuTitle,
            $capability,
            $menuSlug,
            $callback,
            $iconUrl,
            $position
        );

        // Boats from API (submenu)
        self::$adminApiBoatsHook = add_submenu_page(
            $menuSlug,
            __('Available boats', 'maradigma'),
            __('Available boats', 'maradigma'),
            $capability,
            'maradigma-api-boats',
            [AvailableBoatsPage::class, 'render']
        );

        // Make "Settings" explicit as a submenu item
        add_submenu_page(
            $menuSlug,
            __('Settings', 'maradigma'),
            __('Settings', 'maradigma'),
            $capability,
            $menuSlug,
            $callback
        );

        /**
         * ✅ Hide 3rd-party admin notices ONLY on Maradigma admin pages.
         * This prevents Elementor / other plugins notices from showing inside your plugin UI.
         *
         * We hook into `in_admin_header` because it runs before WordPress prints admin notices.
         */
        add_action('in_admin_header', static function (): void {
            if (!function_exists('get_current_screen')) {
                return;
            }

            $screen = get_current_screen();
            if (!$screen || empty($screen->id)) {
                return;
            }

            // These IDs usually match the hook_suffix returned by add_menu_page / add_submenu_page
            $allowedScreens = array_filter([
                self::$adminPageHook ?? null,
                self::$adminApiBoatsHook ?? null,
            ]);

            if (empty($allowedScreens)) {
                return;
            }

            if (!in_array($screen->id, $allowedScreens, true)) {
                return;
            }

            // Remove ALL notices coming from other plugins/themes on our screens
            remove_all_actions('admin_notices');
            remove_all_actions('all_admin_notices');
            remove_all_actions('network_admin_notices');
            remove_all_actions('user_admin_notices');
        }, 0);

        // ✅ Hide "Boat pages" (CPT list) if sync is OFF
        $settings = self::getSettings();
        $enabled  = !empty($settings['enable_boat_pages_sync']);

        if (!$enabled) {
            remove_submenu_page($menuSlug, 'edit.php?post_type=maradigma_boat');
            // Optional: also hide "Add new boat"
            // remove_submenu_page($menuSlug, 'post-new.php?post_type=maradigma_boat');
        }

        /**
         * ✅ Enqueue admin assets only on Maradigma pages (recommended).
         * If your enqueueAdminAssets already checks $hook, perfecto.
         */
        add_action('admin_enqueue_scripts', static function (string $hook): void {
            $allowedHooks = array_filter([
                self::$adminPageHook ?? null,
                self::$adminApiBoatsHook ?? null,
            ]);

            if (!in_array($hook, $allowedHooks, true)) {
                return;
            }

            call_user_func([__CLASS__, 'enqueueAdminAssets'], $hook);
        });
    }

    /**
     * Renders API boats page.
     */
    public static function renderApiBoatsPage(): void
    {
        AvailableBoatsPage::render();
    }

    /**
     * Handles refresh API boats post.
     */
    public static function handleRefreshApiBoatsPost(): void
    {
        AvailableBoatsPage::refresh();
    }

    /**
     * Enqueue CSS/JS solo para la página del plugin.
     *
     * @param string $hookSuffix
     */
    public static function enqueueAdminAssets(string $hookSuffix): void
    {
        if (
            (self::$adminPageHook === null && self::$adminApiBoatsHook === null)
            || ($hookSuffix !== self::$adminPageHook && $hookSuffix !== self::$adminApiBoatsHook)
        ) {
            return;
        }

        $ver = defined('MARADIGMA_PLUGIN_VERSION') ? (string) MARADIGMA_PLUGIN_VERSION : '1.0.0';

        // CSS admin (settings)
        wp_enqueue_style(
            'maradigma-admin-settings',
            MARADIGMA_PLUGIN_URL . 'assets/css/admin/settings.css',
            [],
            $ver
        );

        // Solo en tab=cards cargamos el editor y el JS de cards
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin tab routing.
        $tab = isset($_GET['tab']) ? sanitize_key((string) wp_unslash($_GET['tab'])) : 'settings';
        if ($tab !== 'cards') {
            return;
        }

        // Code editor settings (CodeMirror). NO inicializar aquí.
        $codeEditorSettings = wp_enqueue_code_editor([
            'type' => 'text/html',
        ]);

        // Asegurar dependencias del code editor cuando existe
        if ($codeEditorSettings !== false) {
            wp_enqueue_script('code-editor');
            wp_enqueue_style('code-editor');
        }

        // JS admin cards
        wp_enqueue_script(
            'maradigma-boats-cards-admin',
            MARADIGMA_PLUGIN_URL . 'assets/js/admin/boats-cards.js',
            array_values(array_filter([
                'jquery',
                ($codeEditorSettings !== false) ? 'code-editor' : null,
            ])),
            $ver,
            true
        );

        $frontendCssUrl  = trailingslashit(MARADIGMA_PLUGIN_URL) . 'assets/css/frontend/boats-cards.css';
        $frontendCssUrls = [$frontendCssUrl];

        // Preview sample (para reemplazar tokens en el preview)
        $previewSample = [
            '{{id}}'               => '123',
            '{{url}}'              => '#',
            '{{name}}'             => 'Sunseeker 52',
            '{{service_name}}'     => 'Sunseeker 52',
            '{{boat_builder}}'     => 'Sunseeker',
            '{{boat_model}}'       => '52',
            '{{port}}'             => 'Ibiza',
            '{{pax}}'              => '10',
            '{{cabins}}'           => '2',
            '{{skipper_label}}'    => 'Optional skipper',
            '{{year}}'             => '2018',
            '{{length}}'           => '16',
            '{{price_from}}'       => '650 EUR',
            '{{price_from_service_with_mandatory_additionals_base}}'  => '700 EUR',
            '{{price_from_service_with_mandatory_additionals_vat}}'   => '147 EUR',
            '{{price_from_service_with_mandatory_additionals_total}}' => '847 EUR',
            '{{badge_featured_html}}' => '',
            '{{badge_instant_booking_html}}' => '',

            // Imagen dummy (svg data-uri)
            '{{image_url_600x400}}' => 'data:image/svg+xml;utf8,' . rawurlencode(
                '<svg xmlns="http://www.w3.org/2000/svg" width="800" height="500">' .
                    '<rect width="100%" height="100%" fill="#f1f1f1"/>' .
                    '<text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" font-family="Arial, sans-serif" font-size="32" fill="#666">Boat image</text>' .
                    '</svg>'
            ),
        ];

        $frontendCssUrl  = trailingslashit(MARADIGMA_PLUGIN_URL) . 'assets/css/frontend/boats-cards.css';
        $frontendCssUrls = [$frontendCssUrl];

        wp_localize_script(
            'maradigma-boats-cards-admin',
            'MaradigmaCardsAdmin',
            [
                'hasCodeEditor'      => ($codeEditorSettings !== false) ? '1' : '0',
                'codeEditorSettings' => ($codeEditorSettings !== false) ? $codeEditorSettings : null,

                'frontendCssUrl'     => $frontendCssUrl,   // legacy (por si acaso)
                'frontendCssUrls'    => $frontendCssUrls,  // preferido

                'previewSample'      => $previewSample,
                'tokens'             => $tokens ?? [], // si ya lo pasas en otro lado, OK
            ]
        );
    }

    /**
     * Registers settings.
     */
    public static function registerSettings(): void
    {
        register_setting(
            'maradigma_settings_group',
            self::OPTION_KEY,
            [
                'type'              => 'array',
                'sanitize_callback' => [__CLASS__, 'sanitizeSettings'],
                'default'           => [],
            ]
        );

        add_settings_section(
            'maradigma_main_section',
            __('Maradigma Settings', 'maradigma'),
            static function (): void {
                echo '<p>' . esc_html__(
                    'Connect this website to your Maradigma account by entering your credentials. The rest of the integration will be handled automatically.',
                    'maradigma'
                ) . '</p>';
            },
            'maradigma-settings'
        );

        add_settings_field(
            'external_api_key',
            __('Public key (X-API-KEY)', 'maradigma'),
            [__CLASS__, 'fieldApiKey'],
            'maradigma-settings',
            'maradigma_main_section'
        );

        add_settings_field(
            'api_secret',
            __('Secret key', 'maradigma'),
            [__CLASS__, 'fieldApiSecret'],
            'maradigma-settings',
            'maradigma_main_section'
        );

        add_settings_field(
            'default_language',
            __('Default language', 'maradigma'),
            [__CLASS__, 'fieldDefaultLanguage'],
            'maradigma-settings',
            'maradigma_main_section'
        );

        add_settings_field(
            'boats_base_slug',
            __('Base slug for boats (SEO)', 'maradigma'),
            [__CLASS__, 'fieldBoatsBaseSlug'],
            'maradigma-settings',
            'maradigma_main_section'
        );
    }

    /**
     * Sanitizes settings.
     */
    public static function sanitizeSettings(array $input): array
    {
        // Start from current saved settings (merge strategy)
        $output = self::getSettings();

        // ─────────────────────────────────────────────
        // Connection
        // ─────────────────────────────────────────────
        if (array_key_exists('external_api_key', $input)) {
            $output['external_api_key'] = sanitize_text_field((string) $input['external_api_key']);
        } else {
            $output['external_api_key'] = (string) ($output['external_api_key'] ?? '');
        }

        if (array_key_exists('api_secret', $input)) {
            $output['api_secret'] = trim((string) wp_unslash($input['api_secret']));
        } else {
            $output['api_secret'] = (string) ($output['api_secret'] ?? '');
        }

        if (array_key_exists('default_language', $input)) {
            $output['default_language'] = sanitize_text_field((string) $input['default_language']);
        } else {
            $output['default_language'] = (string) ($output['default_language'] ?? '');
        }

        if (array_key_exists('booking_payment_intro_texts', $input) && is_array($input['booking_payment_intro_texts'])) {
            $texts = [];

            foreach ($input['booking_payment_intro_texts'] as $language => $text) {
                $language = self::normalizeLanguageCode((string) $language);
                if ($language === '') {
                    continue;
                }

                $texts[$language] = sanitize_textarea_field((string) $text);
            }

            $output['booking_payment_intro_texts'] = $texts;
        } else {
            $output['booking_payment_intro_texts'] = self::normalizeLocalizedTextMap($output['booking_payment_intro_texts'] ?? []);
        }

        // ─────────────────────────────────────────────
        // SEO - base slug
        // ─────────────────────────────────────────────
        if (array_key_exists('boats_base_slug', $input)) {
            $boatsBaseSlug = (string) $input['boats_base_slug'];
        } else {
            $boatsBaseSlug = (string) ($output['boats_base_slug'] ?? 'boats');
        }

        $boatsBaseSlug = trim(sanitize_text_field($boatsBaseSlug));
        if ($boatsBaseSlug === '') {
            $boatsBaseSlug = 'boats';
        }

        $filteredBoatsBaseSlug = apply_filters('maradigma_boats_base_slug_before_save', $boatsBaseSlug, $input, $output);
        $boatsBaseSlug = trim(sanitize_text_field((string) $filteredBoatsBaseSlug));
        if ($boatsBaseSlug === '') {
            $boatsBaseSlug = 'boats';
        }

        $output['boats_base_slug'] = $boatsBaseSlug;

        // ─────────────────────────────────────────────
        // SEO - templates (title / description / H1)
        // IMPORTANT: keep values when field is not present (tabs)
        // ─────────────────────────────────────────────
        if (array_key_exists('seo_title_template', $input)) {
            $seoTitleTpl = (string) $input['seo_title_template'];
            $seoTitleTpl = trim(wp_strip_all_tags(wp_unslash($seoTitleTpl)));
            if ($seoTitleTpl === '') {
                $seoTitleTpl = 'Boat hire {{service_name}}';
            }
            $output['seo_title_template'] = $seoTitleTpl;
        } else {
            $output['seo_title_template'] = (string) ($output['seo_title_template'] ?? 'Boat hire {{service_name}}');
        }

        if (array_key_exists('seo_meta_description_template', $input)) {
            $seoDescTpl = (string) $input['seo_meta_description_template'];
            $seoDescTpl = trim(wp_strip_all_tags(wp_unslash($seoDescTpl)));
            if ($seoDescTpl === '') {
                $seoDescTpl = 'Rent the {{service_name}} in {{port}}. Capacity {{pax}} people, from {{price_from}}.';
            }
            $output['seo_meta_description_template'] = $seoDescTpl;
        } else {
            $output['seo_meta_description_template'] = (string) ($output['seo_meta_description_template'] ?? 'Rent the {{service_name}} in {{port}}. Capacity {{pax}} people, from {{price_from}}.');
        }

        if (array_key_exists('seo_h1_template', $input)) {
            $seoH1Tpl = (string) $input['seo_h1_template'];

            // H1 is optional: allow empty
            $seoH1Tpl = trim(wp_strip_all_tags(wp_unslash($seoH1Tpl)));
            $output['seo_h1_template'] = $seoH1Tpl;
        } else {
            $output['seo_h1_template'] = (string) ($output['seo_h1_template'] ?? '{{service_name}}');
        }

        // ─────────────────────────────────────────────
        // Boat binding post types
        // IMPORTANT: configurable post types where the boat selector metabox appears
        // ─────────────────────────────────────────────
        $boatBindingPostTypesPresent = !empty($input['boat_binding_post_types_present']);

        if ($boatBindingPostTypesPresent) {
            $eligiblePostTypes = array_keys(self::getEligibleBoatBindingPostTypes());

            $rawSelectedPostTypes = [];
            if (array_key_exists('boat_binding_post_types', $input) && is_array($input['boat_binding_post_types'])) {
                $rawSelectedPostTypes = $input['boat_binding_post_types'];
            }

            $selectedPostTypes = array_map(
                static fn($postType): string => sanitize_key((string) $postType),
                $rawSelectedPostTypes
            );

            $selectedPostTypes = array_filter(
                $selectedPostTypes,
                static fn(string $postType): bool => $postType !== ''
            );

            $selectedPostTypes = array_values(array_unique(array_intersect($selectedPostTypes, $eligiblePostTypes)));

            $output['boat_binding_post_types'] = $selectedPostTypes;
        } else {
            $output['boat_binding_post_types'] = isset($output['boat_binding_post_types']) && is_array($output['boat_binding_post_types'])
                ? array_values(array_unique(array_filter(array_map(
                    static fn($postType): string => sanitize_key((string) $postType),
                    $output['boat_binding_post_types']
                ), static fn(string $postType): bool => $postType !== '')))
                : ['page'];
        }

        // ─────────────────────────────────────────────
        // Media cache (store boat images locally)
        // IMPORTANT: do NOT reset when field is not present in current form (tabs)
        // ─────────────────────────────────────────────
        if (array_key_exists('store_boat_images_locally', $input)) {
            $output['store_boat_images_locally'] = !empty($input['store_boat_images_locally']) ? 1 : 0;
        } else {
            $output['store_boat_images_locally'] = isset($output['store_boat_images_locally'])
                ? (int) $output['store_boat_images_locally']
                : 0;
        }

        if (array_key_exists('max_images_per_boat', $input)) {
            $output['max_images_per_boat'] = min(25, absint($input['max_images_per_boat']));
        } else {
            $output['max_images_per_boat'] = isset($output['max_images_per_boat'])
                ? min(25, absint($output['max_images_per_boat']))
                : 0;
        }

        // ─────────────────────────────────────────────
        // Sync flag
        // IMPORTANT: do NOT reset when field is not present in current form (tabs)
        // ─────────────────────────────────────────────
        if (array_key_exists('enable_boat_pages_sync', $input)) {
            $output['enable_boat_pages_sync'] = !empty($input['enable_boat_pages_sync']) ? 1 : 0;
        } else {
            $output['enable_boat_pages_sync'] = isset($output['enable_boat_pages_sync'])
                ? (int) $output['enable_boat_pages_sync']
                : 0;
        }

        // ─────────────────────────────────────────────
        // Boat page builder
        // IMPORTANT: Elementor and Gutenberg must be mutually exclusive.
        // ─────────────────────────────────────────────
        if (array_key_exists('boat_layout_builder', $input)) {
            $boatLayoutBuilder = sanitize_key((string) $input['boat_layout_builder']);
        } else {
            $boatLayoutBuilder = sanitize_key((string) ($output['boat_layout_builder'] ?? 'elementor'));
        }

        if (!in_array($boatLayoutBuilder, ['elementor', 'gutenberg', 'wpbakery'], true)) {
            $boatLayoutBuilder = 'elementor';
        }

        $output['boat_layout_builder'] = $boatLayoutBuilder;

        // ─────────────────────────────────────────────
        // PRO uninstall flags
        // IMPORTANT: do NOT reset when field is not present in current form (tabs)
        // ─────────────────────────────────────────────
        if (array_key_exists('delete_data_on_uninstall', $input)) {
            $output['delete_data_on_uninstall'] = !empty($input['delete_data_on_uninstall']) ? 1 : 0;
        } else {
            $output['delete_data_on_uninstall'] = isset($output['delete_data_on_uninstall'])
                ? (int) $output['delete_data_on_uninstall']
                : 0;
        }

        if (array_key_exists('delete_uploads_on_uninstall', $input)) {
            $output['delete_uploads_on_uninstall'] = !empty($input['delete_uploads_on_uninstall']) ? 1 : 0;
        } else {
            $output['delete_uploads_on_uninstall'] = isset($output['delete_uploads_on_uninstall'])
                ? (int) $output['delete_uploads_on_uninstall']
                : 0;
        }

        // Guard: if user doesn't delete data, uploads flag should not matter
        if ((int) $output['delete_data_on_uninstall'] !== 1) {
            $output['delete_uploads_on_uninstall'] = 0;
        }

        add_settings_error(
            'maradigma_settings_group',
            'maradigma_settings_saved',
            __('Settings saved successfully.', 'maradigma'),
            'success'
        );

        return $output;
    }

    /**
     * Renders the API secret settings field.
     */
    public static function fieldApiSecret(): void
    {
        $settings = self::getSettings();
        $value    = (string) ($settings['api_secret'] ?? '');

        ?>
        <input
            type="password"
            name="<?php echo esc_attr(self::OPTION_KEY); ?>[api_secret]"
            value="<?php echo esc_attr($value); ?>"
            class="regular-text"
            autocomplete="new-password" />
        <p class="description">
            <?php esc_html_e('Secret key the plugin will use to sign requests (X-SIGNATURE). Do not share it with anyone.', 'maradigma'); ?>
        </p>
    <?php
    }

    /**
     * @return array<string,mixed>
     */
    public static function getSettings(): array
    {
        $settings = get_option(self::OPTION_KEY, []);
        if (!is_array($settings)) {
            $settings = [];
        }

        $settings['external_api_key'] = $settings['external_api_key'] ?? '';
        $settings['api_secret'] = $settings['api_secret'] ?? '';
        $settings['default_language'] = $settings['default_language'] ?? '';

        $settings['boat_binding_post_types'] = isset($settings['boat_binding_post_types']) && is_array($settings['boat_binding_post_types'])
            ? array_values(array_unique(array_filter(array_map(
                static fn($postType): string => sanitize_key((string) $postType),
                $settings['boat_binding_post_types']
            ), static fn(string $postType): bool => $postType !== '')))
            : ['page'];

        $settings['store_boat_images_locally'] = isset($settings['store_boat_images_locally'])
            ? (int) $settings['store_boat_images_locally']
            : 0;

        $settings['max_images_per_boat'] = isset($settings['max_images_per_boat'])
            ? min(25, absint($settings['max_images_per_boat']))
            : 0;

        $settings['enable_boat_pages_sync'] = isset($settings['enable_boat_pages_sync'])
            ? (int) $settings['enable_boat_pages_sync']
            : 0;

        $settings['boat_layout_builder'] = sanitize_key((string) ($settings['boat_layout_builder'] ?? 'elementor'));
        if (!in_array($settings['boat_layout_builder'], ['elementor', 'gutenberg', 'wpbakery'], true)) {
            $settings['boat_layout_builder'] = 'elementor';
        }

        $settings['booking_payment_intro_texts'] = self::normalizeLocalizedTextMap($settings['booking_payment_intro_texts'] ?? []);

        // PRO uninstall defaults
        $settings['delete_data_on_uninstall'] = isset($settings['delete_data_on_uninstall'])
            ? (int) $settings['delete_data_on_uninstall']
            : 0;

        $settings['delete_uploads_on_uninstall'] = isset($settings['delete_uploads_on_uninstall'])
            ? (int) $settings['delete_uploads_on_uninstall']
            : 0;

        // Consistency: uploads only if delete data is enabled
        if ((int) $settings['delete_data_on_uninstall'] !== 1) {
            $settings['delete_uploads_on_uninstall'] = 0;
        }

        $settings['boats_base_slug'] = $settings['boats_base_slug'] ?? 'boats';
        $settings['seo_title_template'] = $settings['seo_title_template'] ?? 'Boat hire {{service_name}}';
        $settings['seo_meta_description_template'] = $settings['seo_meta_description_template'] ?? 'Rent the {{service_name}} in {{port}}. Capacity {{pax}} people, from {{price_from}}.';
        $settings['seo_h1_template'] = $settings['seo_h1_template'] ?? '{{service_name}}';

        return $settings;
    }

    /**
     * Store live progress stats from BoatSyncService state.
     *
     * @param array<string,mixed> $state
     * @param array<string,mixed> $extra
     */
    public static function storeBoatSyncProgress(array $state, array $extra = []): void
    {
        $boatsTotal   = (int)($state['boats_total'] ?? 0);
        $boatsDone    = (int)($state['boats_done'] ?? 0);
        $created      = (int)($state['posts_created_total'] ?? 0);
        $updated      = (int)($state['posts_updated_total'] ?? 0);
        $postsTotal   = $created + $updated;
        $perLang      = is_array($state['per_lang'] ?? null) ? (array)$state['per_lang'] : [];
        $cleanup      = is_array($state['cleanup'] ?? null) ? (array)$state['cleanup'] : [];
        $status       = (string)($state['status'] ?? '');
        $message      = (string)($state['message'] ?? '');
        $offset       = (int)($state['offset'] ?? 0);
        $limit        = (int)($state['limit'] ?? 0);
        $startedAt    = (int)($state['started_at'] ?? 0);
        $lastRunAt    = (int)($state['last_run_at'] ?? 0);

        $stats = [
            // Summary
            'mode'               => 'batched',
            'status'             => $status,
            'message'            => $message,

            // Totals
            'boats_total'        => $boatsTotal,
            'boats_done'         => $boatsDone,

            'posts_total'        => $postsTotal,
            'posts_created_total' => $created,
            'posts_updated_total' => $updated,

            'per_lang'           => $perLang,
            'cleanup'            => $cleanup,

            // Progress details (optional)
            'offset'             => $offset,
            'limit'              => $limit,
            'started_at'         => $startedAt,
            'last_run_at'        => $lastRunAt,
        ];

        // Extra fields (options, finished_at, etc.)
        foreach ($extra as $k => $v) {
            $stats[$k] = $v;
        }

        update_option(self::OPTION_LAST_SYNC_STATS, $stats, false);

        // Keep legacy count in sync too
        update_option(self::OPTION_LAST_SYNC_COUNT, $boatsDone, false);
    }

    /**
     * Store final stats when sync is done/error/stopped.
     *
     * @param array<string,mixed> $state
     */
    public static function storeBoatSyncFinished(array $state): void
    {
        self::storeBoatSyncProgress($state, [
            'finished_at' => time(),
        ]);

        // "Last sync" should represent end of execution (not schedule time)
        update_option(self::OPTION_LAST_SYNC_AT, time(), false);
    }

    /**
     * Crea un cliente de API listo para usar en base a la config guardada.
     *
     * @throws RuntimeException si falta algún dato esencial.
     */
    public static function makeExternalApiClient(): ExternalApiClient
    {
        $settings = self::getSettings();

        $baseUrl = \Maradigma\Environment::getApiBaseUrl();
        $publicKey = (string) ($settings['external_api_key'] ?? '');
        $secretKey = (string) ($settings['api_secret'] ?? '');

        $clientDomain = (string) (wp_parse_url(home_url('/'), PHP_URL_HOST) ?? '');

        $language = trim((string) ($settings['default_language'] ?? ''));
        if ($language === '') {
            $language = (string) get_locale();
        }

        if ($baseUrl === '' || $publicKey === '' || $secretKey === '' || $clientDomain === '') {
            throw new RuntimeException('External API client misconfigured.');
        }

        return new ExternalApiClient(
            $baseUrl,
            $publicKey,
            $secretKey,
            $clientDomain,
            $language
        );
    }

    /**
     * Builds admin notice HTML.
     */
    private static function buildAdminNoticeHtml(string $type, string $message): string
    {
        return SettingsNotices::html($type, $message);
    }

    /**
     * Renders settings page.
     */
    public static function renderSettingsPage(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        /**
         * ✅ PRO: En esta pantalla del plugin NO queremos que salgan notices ajenos.
         * OJO: esto también oculta avisos core/otros plugins SOLO aquí.
         */
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && isset($screen->id) && $screen->id === 'toplevel_page_maradigma-settings') {
            remove_all_actions('admin_notices');
            remove_all_actions('all_admin_notices');
            remove_all_actions('network_admin_notices');
            remove_all_actions('user_admin_notices');
        }

        $settings  = self::getSettings();
        $hasApiKey = trim((string) ($settings['external_api_key'] ?? '')) !== '';
        $hasSecret = trim((string) ($settings['api_secret'] ?? '')) !== '';

        $connectionStatus = 'empty';
        $connectionError  = null;

        if ($hasApiKey && $hasSecret) {
            try {
                $client   = self::makeExternalApiClient();
                $authResp = $client->testAuth();

                if (!empty($authResp['status']) && $authResp['status'] === 'success') {
                    $connectionStatus = 'ok';
                } else {
                    $connectionStatus = 'invalid';
                    $connectionError  = $authResp['message'] ?? 'unknown_error';
                }
            } catch (\Throwable $e) {
                $connectionStatus = 'error';
                $connectionError  = $e->getMessage();
            }
        }

        $logoUrl = esc_url(MARADIGMA_PLUGIN_URL . 'assets/img/maradigma-logo.png');

        // These GET values only select a read-only settings tab or notice.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $activeTab = isset($_GET['tab'])
            ? sanitize_key((string) wp_unslash($_GET['tab']))
            : 'settings';
        $validTabs = ['settings', 'seo', 'booking', 'cards', 'advanced', 'about', 'help'];
        if (!in_array($activeTab, $validTabs, true)) {
            $activeTab = 'settings';
        }

        $pageUrl      = menu_page_url('maradigma-settings', false);
        $adminPostUrl = admin_url('admin-post.php');

        $notice = isset($_GET['notice']) ? sanitize_key((string) wp_unslash($_GET['notice'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        // ✅ Settings API errors render manual (porque hemos ocultado admin_notices)
        $settingsErrorsHtml = '';
        if (function_exists('get_settings_errors')) {
            $errs = get_settings_errors('maradigma_settings_group', false);
            if (is_array($errs) && !empty($errs)) {
                foreach ($errs as $err) {
                    $type = isset($err['type']) ? (string) $err['type'] : 'error';
                    $msg  = isset($err['message']) ? (string) $err['message'] : '';

                    $class = 'notice notice-error inline';
                    if ($type === 'updated' || $type === 'success') {
                        $class = 'notice notice-success inline';
                    } elseif ($type === 'warning') {
                        $class = 'notice notice-warning inline';
                    } elseif ($type === 'info') {
                        $class = 'notice notice-info inline';
                    }

                    if ($msg !== '') {
                        $settingsErrorsHtml .= '<div class="' . esc_attr($class) . '"><p><strong>' . esc_html($msg) . '</strong></p></div>';
                    }
                }
            }
        }

        // Core's settings-updated flag only controls a read-only success notice.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ($settingsErrorsHtml === '' && isset($_GET['settings-updated']) && sanitize_text_field(wp_unslash((string) $_GET['settings-updated'])) === 'true') {
            $settingsErrorsHtml = self::buildAdminNoticeHtml(
                'success',
                __('Settings saved successfully.', 'maradigma')
            );
        }

        // Sync notices (kept in settings tab)
        $syncNoticeHtml = '';
        if ($notice === 'sync_ok') {
            $syncNoticeHtml = '<div class="notice notice-info inline"><p><strong>' . esc_html__('Boat sync started. It will continue in batches via WP-Cron while this screen keeps it moving with AJAX.', 'maradigma') . '</strong></p></div>';
        } elseif ($notice === 'sync_error') {
            $extra = get_transient('maradigma_last_sync_error');
            if (is_string($extra) && $extra !== '') {
                delete_transient('maradigma_last_sync_error');
                $syncNoticeHtml = '<div class="notice notice-error inline"><p><strong>' .
                    esc_html__('Sync failed:', 'maradigma') . ' ' . esc_html($extra) .
                    '</strong></p></div>';
            } else {
                $syncNoticeHtml = '<div class="notice notice-error inline"><p><strong>' .
                    esc_html__('Sync failed. Please check your API keys and try again.', 'maradigma') .
                    '</strong></p></div>';
            }
        } elseif ($notice === 'sync_disabled') {
            $syncNoticeHtml = '<div class="notice notice-warning inline"><p><strong>' .
                esc_html__('Sync is disabled. Enable "Boat pages sync (opt-in)" and save changes first.', 'maradigma') .
                '</strong></p></div>';
        } elseif ($notice === 'cache_flushed') {
            $syncNoticeHtml = '<div class="notice notice-success inline"><p><strong>' . esc_html__('Cache cleared successfully.', 'maradigma') . '</strong></p></div>';
        } elseif ($notice === 'cache_flush_error') {
            $syncNoticeHtml = '<div class="notice notice-error inline"><p><strong>' . esc_html__('Could not clear cache. Please try again.', 'maradigma') . '</strong></p></div>';
        } elseif ($notice === 'boats_deleted') {
            $syncNoticeHtml = '<div class="notice notice-success inline"><p><strong>' .
                esc_html__('All boat pages were deleted. You can sync again from scratch.', 'maradigma') .
                '</strong></p></div>';
        } elseif ($notice === 'boats_delete_error') {
            $syncNoticeHtml = '<div class="notice notice-error inline"><p><strong>' .
                esc_html__('Could not delete boat pages. Check logs.', 'maradigma') .
                '</strong></p></div>';
        } elseif ($notice === 'log_not_found') {
            $syncNoticeHtml = '<div class="notice notice-warning inline"><p><strong>' .
                esc_html__('Log file not found.', 'maradigma') .
                '</strong></p></div>';
        } elseif ($notice === 'log_cleared') {
            $syncNoticeHtml = '<div class="notice notice-success inline"><p><strong>' .
                esc_html__('Log cleared successfully.', 'maradigma') .
                '</strong></p></div>';
        } elseif ($notice === 'log_clear_error') {
            $syncNoticeHtml = '<div class="notice notice-error inline"><p><strong>' .
                esc_html__('Could not clear the log file.', 'maradigma') .
                '</strong></p></div>';
        } elseif ($notice === 'images_sync_started') {
            $syncNoticeHtml = '<div class="notice notice-success inline"><p><strong>' .
                esc_html__('Boat images sync started successfully.', 'maradigma') .
                '</strong></p></div>';
        } elseif ($notice === 'images_sync_stopped') {
            $syncNoticeHtml = '<div class="notice notice-warning inline"><p><strong>' .
                esc_html__('Boat images sync stopped.', 'maradigma') .
                '</strong></p></div>';
        } elseif ($notice === 'images_sync_reset') {
            $syncNoticeHtml = '<div class="notice notice-success inline"><p><strong>' .
                esc_html__('Boat images cache reset successfully.', 'maradigma') .
                '</strong></p></div>';
        } elseif ($notice === 'images_sync_reset_started') {
            $syncNoticeHtml = '<div class="notice notice-success inline"><p><strong>' .
                esc_html__('Boat images cache was reset and sync started again.', 'maradigma') .
                '</strong></p></div>';
        } elseif ($notice === 'images_sync_disabled') {
            $syncNoticeHtml = '<div class="notice notice-warning inline"><p><strong>' .
                esc_html__('Boat images sync is disabled. Enable local image storage first.', 'maradigma') .
                '</strong></p></div>';
        } elseif ($notice === 'gutenberg_template_reset') {
            $syncNoticeHtml = '<div class="notice notice-success inline"><p><strong>' .
                esc_html__('Gutenberg master template restored to plugin defaults.', 'maradigma') .
                '</strong></p></div>';
        } elseif ($notice === 'gutenberg_template_reset_error') {
            $syncNoticeHtml = '<div class="notice notice-error inline"><p><strong>' .
                esc_html__('Could not restore the Gutenberg master template.', 'maradigma') .
                '</strong></p></div>';
        } elseif ($notice === 'gutenberg_template_error') {
            $syncNoticeHtml = '<div class="notice notice-error inline"><p><strong>' .
                esc_html__('Could not open the Gutenberg master template.', 'maradigma') .
                '</strong></p></div>';
        } elseif ($notice === 'template_sync_started') {
            $syncNoticeHtml = '<div class="notice notice-success inline"><p><strong>' .
                esc_html__('Template sync started. It will continue in batches via WP-Cron.', 'maradigma') .
                '</strong></p></div>';
        } elseif ($notice === 'template_sync_stopped') {
            $syncNoticeHtml = '<div class="notice notice-warning inline"><p><strong>' .
                esc_html__('Template sync stopped.', 'maradigma') .
                '</strong></p></div>';
        } elseif ($notice === 'elementor_template_imported') {
            $syncNoticeHtml = '<div class="notice notice-success inline"><p><strong>' .
                esc_html__('Elementor master template updated from the selected boat layout.', 'maradigma') .
                '</strong></p></div>';
        } elseif ($notice === 'elementor_template_import_invalid_source') {
            $syncNoticeHtml = '<div class="notice notice-error inline"><p><strong>' .
                esc_html__('Please select a valid boat with Elementor layout.', 'maradigma') .
                '</strong></p></div>';
        } elseif ($notice === 'elementor_template_import_empty_source') {
            $syncNoticeHtml = '<div class="notice notice-error inline"><p><strong>' .
                esc_html__('The selected boat does not contain Elementor data to import.', 'maradigma') .
                '</strong></p></div>';
        } elseif ($notice === 'elementor_template_import_master_error') {
            $syncNoticeHtml = '<div class="notice notice-error inline"><p><strong>' .
                esc_html__('The Elementor master template could not be created or loaded.', 'maradigma') .
                '</strong></p></div>';
        }

        $lastSyncAt    = (int) get_option(self::OPTION_LAST_SYNC_AT, 0);
        $lastSyncHuman = ($lastSyncAt > 0)
            ? wp_date('Y-m-d H:i:s', $lastSyncAt)
            : '-';

        $lastSyncStats = get_option(self::OPTION_LAST_SYNC_STATS, []);
        if (!is_array($lastSyncStats)) {
            $lastSyncStats = [];
        }
        $boatSyncState = \Maradigma\BoatSyncService::getState();
        $boatSyncStatus = (string) ($boatSyncState['status'] ?? '');

        $lastBoatsTotal   = (int) ($lastSyncStats['boats_total'] ?? 0);
        $lastPostsTotal   = (int) ($lastSyncStats['posts_total'] ?? 0);
        $lastPostsCreated = (int) ($lastSyncStats['posts_created_total'] ?? 0);
        $lastPostsUpdated = (int) ($lastSyncStats['posts_updated_total'] ?? 0);
        $lastCleanup      = is_array($lastSyncStats['cleanup'] ?? null) ? (array) $lastSyncStats['cleanup'] : [];

        $perLang = is_array($lastSyncStats['per_lang'] ?? null) ? $lastSyncStats['per_lang'] : [];

        // ─────────────────────────────────────────────
        // SEO settings (tab: seo)
        // ─────────────────────────────────────────────
        $defaultLang = (string) ($settings['default_language'] ?? 'en');
        $defaultLang = strtolower(trim($defaultLang));
        if ($defaultLang === '') {
            $defaultLang = 'en';
        }

        $boatsBaseSlug = (string) ($settings['boats_base_slug'] ?? 'boats');
        $boatsBaseSlug = trim($boatsBaseSlug);
        if ($boatsBaseSlug === '') {
            $boatsBaseSlug = 'boats';
        }

        $seoTitleTpl = (string) ($settings['seo_title_template'] ?? 'Boat hire {{service_name}}');
        $seoDescTpl  = (string) ($settings['seo_meta_description_template'] ?? 'Rent the {{service_name}} in {{port}}. Capacity {{pax}} people, from {{price_from}}.');
        $seoH1Tpl    = (string) ($settings['seo_h1_template'] ?? '{{service_name}}');

        // Placeholders
        $placeholders = \Maradigma\BoatCardEngine::getPlaceholdersLabels();
        foreach (\Maradigma\BoatCardEngine::listTokens() as $token) {
            $key = '{{' . $token . '}}';
            if (!isset($placeholders[$key])) {
                $placeholders[$key] = $token;
            }
        }
        uksort($placeholders, static function (string $a, string $b) use ($placeholders): int {
            $aHuman = $placeholders[$a] !== trim($a, '{}');
            $bHuman = $placeholders[$b] !== trim($b, '{}');

            if ($aHuman !== $bHuman) {
                return $aHuman ? -1 : 1;
            }

            return strcasecmp($a, $b);
        });

        $languageOptions = [
            ['label' => 'EN', 'value' => 'en'],
            ['label' => 'ES', 'value' => 'es'],
            ['label' => 'FR', 'value' => 'fr'],
            ['label' => 'DE', 'value' => 'de'],
            ['label' => 'IT', 'value' => 'it'],
            ['label' => 'NL', 'value' => 'nl'],
        ];

        // Fields: connection (manual render to avoid duplicated SEO fields coming from do_settings_sections())
        $externalApiKey = (string) ($settings['external_api_key'] ?? '');
        $apiSecret      = (string) ($settings['api_secret'] ?? '');

        // ─────────────────────────────────────────────
        // Compatibility detection (shown in Settings → "Resumen rápido")
        // ─────────────────────────────────────────────
        $compat = [
            'elementor' => did_action('elementor/loaded'),
            'yoast'     => defined('WPSEO_VERSION') || class_exists('\WPSEO_Options'),

            // Provider
            'multilang_provider' => MultilangAdapter::detectProvider(),

            // Is our CPT actually translatable?
            'boats_translatable' => MultilangAdapter::isPostTypeTranslatable(\Maradigma\BoatPostType::POST_TYPE),
        ];

        // ─────────────────────────────────────────────
        // Translation diagnostics (tab: advanced)
        // ─────────────────────────────────────────────
        $wpLocale = function_exists('determine_locale')
            ? (string) determine_locale()
            : (string) get_locale();

        $currentLanguage = MultilangAdapter::getCurrentLanguage();
        $defaultLanguage = MultilangAdapter::getDefaultLanguage();
        $provider        = MultilangAdapter::detectProvider();

        $textdomainLoaded = is_textdomain_loaded('maradigma') ? 'YES' : 'NO';

        $probes = [
            'Featured'     => __('Featured', 'maradigma'),
            'from'         => __('from', 'maradigma'),
            'Book now'     => __('Book now', 'maradigma'),
            'Boat details' => __('Boat details', 'maradigma'),
            'cabins'       => __('cabins', 'maradigma'),
            'pers.'        => __('pers.', 'maradigma'),
        ];

        $pluginLangDir    = plugin_dir_path(MARADIGMA_PLUGIN_FILE) . 'languages/';
        $expectedMo       = $pluginLangDir . 'maradigma-' . $wpLocale . '.mo';
        $expectedPo       = $pluginLangDir . 'maradigma-' . $wpLocale . '.po';
        $expectedMoExists = is_readable($expectedMo) ? 'YES' : 'NO';
        $expectedPoExists = is_readable($expectedPo) ? 'YES' : 'NO';

    ?>
        <div class="wrap maradigma-settings-wrap">

            <div class="maradigma-settings-header">
                <div class="maradigma-settings-header-logo">
                    <?php if ($logoUrl) : ?>
                        <img src="<?php echo esc_url($logoUrl); ?>" alt="<?php esc_attr_e('Maradigma logo', 'maradigma'); ?>">
                    <?php endif; ?>
                </div>
                <div>
                    <h1 class="maradigma-settings-header-title">
                        Maradigma - Boats &amp; bookings
                    </h1>
                    <p class="maradigma-settings-header-subtitle">
                        <?php esc_html_e(
                            'Connect your website to Maradigma to showcase your boats and enable online bookings for your customers.',
                            'maradigma'
                        ); ?>
                    </p>
                </div>
                <div class="maradigma-settings-badge">
                    <?php esc_html_e('Settings panel', 'maradigma'); ?>
                </div>
            </div>

            <div class="maradigma-tabs">
                <a href="<?php echo esc_url(add_query_arg('tab', 'settings', $pageUrl)); ?>"
                    class="<?php echo $activeTab === 'settings' ? 'is-active' : ''; ?>">
                    <?php esc_html_e('Configuration', 'maradigma'); ?>
                </a>

                <a href="<?php echo esc_url(add_query_arg('tab', 'seo', $pageUrl)); ?>"
                    class="<?php echo $activeTab === 'seo' ? 'is-active' : ''; ?>">
                    <?php esc_html_e('SEO', 'maradigma'); ?>
                </a>

                <a href="<?php echo esc_url(add_query_arg('tab', 'booking', $pageUrl)); ?>"
                    class="<?php echo $activeTab === 'booking' ? 'is-active' : ''; ?>">
                    <?php esc_html_e('Bookings', 'maradigma'); ?>
                </a>

                <a href="<?php echo esc_url(add_query_arg('tab', 'cards', $pageUrl)); ?>"
                    class="<?php echo $activeTab === 'cards' ? 'is-active' : ''; ?>">
                    <?php esc_html_e('Boat cards', 'maradigma'); ?>
                </a>

                <a href="<?php echo esc_url(add_query_arg('tab', 'advanced', $pageUrl)); ?>"
                    class="<?php echo $activeTab === 'advanced' ? 'is-active' : ''; ?>">
                    <?php esc_html_e('Advanced settings', 'maradigma'); ?>
                </a>

                <a href="<?php echo esc_url(add_query_arg('tab', 'about', $pageUrl)); ?>"
                    class="<?php echo $activeTab === 'about' ? 'is-active' : ''; ?>">
                    <?php esc_html_e('About Maradigma', 'maradigma'); ?>
                </a>

                <a href="<?php echo esc_url(add_query_arg('tab', 'help', $pageUrl)); ?>"
                    class="<?php echo $activeTab === 'help' ? 'is-active' : ''; ?>">
                    <?php esc_html_e('Help &amp; support', 'maradigma'); ?>
                </a>
            </div>

            <?php
            echo $settingsErrorsHtml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo $syncNoticeHtml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            ?>

            <?php if ($activeTab === 'settings') : ?>

                <div class="maradigma-settings-section-map" aria-label="<?php esc_attr_e('Configuration sections', 'maradigma'); ?>">
                    <a class="maradigma-settings-section-link" href="#maradigma-section-connection">
                        <span class="maradigma-settings-section-kicker"><?php esc_html_e('Step 1', 'maradigma'); ?></span>
                        <strong><?php esc_html_e('Connection', 'maradigma'); ?></strong>
                        <span><?php esc_html_e('API credentials, local media and generated boat pages.', 'maradigma'); ?></span>
                    </a>

                    <a class="maradigma-settings-section-link" href="#maradigma-section-sync">
                        <span class="maradigma-settings-section-kicker"><?php esc_html_e('Step 2', 'maradigma'); ?></span>
                        <strong><?php esc_html_e('Boats sync', 'maradigma'); ?></strong>
                        <span><?php esc_html_e('Create/update boat pages, images and cache tools.', 'maradigma'); ?></span>
                    </a>

                    <?php if (!empty($settings['enable_boat_pages_sync'])) : ?>
                        <a class="maradigma-settings-section-link" href="#maradigma-section-templates">
                            <span class="maradigma-settings-section-kicker"><?php esc_html_e('Step 3', 'maradigma'); ?></span>
                            <strong><?php esc_html_e('Templates', 'maradigma'); ?></strong>
                            <span><?php esc_html_e('Manage Elementor or Gutenberg master layouts.', 'maradigma'); ?></span>
                        </a>
                    <?php endif; ?>

                    <a class="maradigma-settings-section-link" href="#maradigma-section-compatibility">
                        <span class="maradigma-settings-section-kicker"><?php esc_html_e('Status', 'maradigma'); ?></span>
                        <strong><?php esc_html_e('Compatibility', 'maradigma'); ?></strong>
                        <span><?php esc_html_e('Detected builders, SEO and multilingual integrations.', 'maradigma'); ?></span>
                    </a>
                </div>

                <div class="maradigma-settings-grid">
                    <div class="maradigma-card">
                        <h2 id="maradigma-section-connection">
                            <span class="maradigma-dot"></span>
                            <?php esc_html_e('Connection with Maradigma', 'maradigma'); ?>
                        </h2>

                        <p class="maradigma-p-compact">
                            <?php esc_html_e(
                                'Enter your Maradigma public key and secret key to connect this website to your account. The plugin will automatically sign requests.',
                                'maradigma'
                            ); ?>
                        </p>

                        <p>
                            <?php if ($connectionStatus === 'ok') : ?>
                                <span class="maradigma-status-pill maradigma-status-pill--ok">
                                    <span class="maradigma-status-dot"></span>
                                    <?php esc_html_e('Ready: valid keys and successful connection', 'maradigma'); ?>
                                </span>
                            <?php elseif ($connectionStatus === 'invalid') : ?>
                                <span class="maradigma-status-pill maradigma-status-pill--warn">
                                    <span class="maradigma-status-dot"></span>
                                    <?php esc_html_e('The Maradigma keys are not valid. Please check the public key and the secret key.', 'maradigma'); ?>
                                </span>
                                <?php if ($connectionError) : ?>
                        <p class="maradigma-conn-error">
                            <?php echo esc_html((string) $connectionError); ?>
                        </p>
                    <?php endif; ?>
                <?php elseif ($connectionStatus === 'error') : ?>
                    <span class="maradigma-status-pill maradigma-status-pill--error">
                        <span class="maradigma-status-dot"></span>
                        <?php esc_html_e("We couldn't connect to Maradigma. Please try again later or contact support.", 'maradigma'); ?>
                    </span>
                    <?php if ($connectionError) : ?>
                        <p class="maradigma-conn-error">
                            <?php echo esc_html((string) $connectionError); ?>
                        </p>
                    <?php endif; ?>
                <?php else : ?>
                    <span class="maradigma-status-pill maradigma-status-pill--warn">
                        <span class="maradigma-status-dot"></span>
                        <?php esc_html_e('Pending: enter your Maradigma keys', 'maradigma'); ?>
                    </span>
                <?php endif; ?>
                </p>

                <!--
                IMPORTANT:
                No llamamos a do_settings_sections('maradigma-settings') aquí,
                porque ahí WordPress te renderiza también default_language y boats_base_slug
                (y los quieres SOLO en la pestaña SEO).
                -->
                <form method="post" action="options.php">
                    <?php settings_fields('maradigma_settings_group'); ?>

                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row">
                                    <label for="maradigma-external-api-key">
                                        <?php esc_html_e('Public key (API Key)', 'maradigma'); ?>
                                    </label>
                                </th>
                                <td>
                                    <input
                                        type="text"
                                        id="maradigma-external-api-key"
                                        name="<?php echo esc_attr(self::OPTION_KEY); ?>[external_api_key]"
                                        value="<?php echo esc_attr($externalApiKey); ?>"
                                        class="regular-text"
                                        style="width:100%;max-width:620px;"
                                        autocomplete="off"
                                        placeholder="bch_..." />
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="maradigma-api-secret">
                                        <?php esc_html_e('Secret key (API Secret)', 'maradigma'); ?>
                                    </label>
                                </th>
                                <td>
                                    <input
                                        type="password"
                                        id="maradigma-api-secret"
                                        name="<?php echo esc_attr(self::OPTION_KEY); ?>[api_secret]"
                                        value="<?php echo esc_attr($apiSecret); ?>"
                                        class="regular-text"
                                        style="width:100%;max-width:620px;"
                                        autocomplete="new-password" />
                                </td>
                            </tr>
                            <?php $storeImages = !empty($settings['store_boat_images_locally']); ?>
                            <tr>
                                <th scope="row"><?php esc_html_e('Store boat images in WordPress', 'maradigma'); ?></th>
                                <td>
                                    <label style="display:flex;gap:10px;align-items:flex-start;">
                                        <input
                                            type="hidden"
                                            name="<?php echo esc_attr(self::OPTION_KEY); ?>[store_boat_images_locally]"
                                            value="0" />
                                        <input
                                            type="checkbox"
                                            name="<?php echo esc_attr(self::OPTION_KEY); ?>[store_boat_images_locally]"
                                            value="1"
                                            <?php checked($storeImages); ?> />
                                        <span>
                                            <strong><?php esc_html_e('Download and serve boat images from this WordPress Media Library', 'maradigma'); ?></strong><br>
                                            <span class="description">
                                                <?php esc_html_e('Recommended ON. This avoids hotlinking Maradigma images and improves privacy/performance. Use the sync tool below to fetch/update images in batches.', 'maradigma'); ?>
                                            </span>
                                        </span>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="maradigma-max-images-per-boat">
                                        <?php esc_html_e('Maximum images per boat', 'maradigma'); ?>
                                    </label>
                                </th>
                                <td>
                                    <input
                                        type="number"
                                        id="maradigma-max-images-per-boat"
                                        name="<?php echo esc_attr(self::OPTION_KEY); ?>[max_images_per_boat]"
                                        value="<?php echo esc_attr((string) ($settings['max_images_per_boat'] ?? 0)); ?>"
                                        min="0"
                                        max="25"
                                        step="1"
                                        class="small-text" />
                                    <p class="description">
                                        <?php esc_html_e('Use 0 to store all available images. Any custom limit must be between 1 and 25 images per boat.', 'maradigma'); ?>
                                    </p>
                                </td>
                            </tr>
                            <?php $enableBoatPagesSync = !empty($settings['enable_boat_pages_sync']); ?>
                            <tr>
                                <th scope="row">
                                    <?php esc_html_e('Boat pages sync (opt-in)', 'maradigma'); ?>
                                </th>
                                <td>
                                    <label style="display:flex;gap:10px;align-items:flex-start;">
                                        <input
                                            type="hidden"
                                            name="<?php echo esc_attr(self::OPTION_KEY); ?>[enable_boat_pages_sync]"
                                            value="0" />
                                        <input
                                            type="checkbox"
                                            name="<?php echo esc_attr(self::OPTION_KEY); ?>[enable_boat_pages_sync]"
                                            value="1"
                                            <?php checked($enableBoatPagesSync); ?> />
                                        <span>
                                            <strong><?php esc_html_e('Allow Maradigma to create/update one public page per boat', 'maradigma'); ?></strong><br>
                                            <span class="description">
                                                <?php esc_html_e('Recommended OFF by default. Enable only if you want the plugin to generate and keep updated a public WordPress page for each boat.', 'maradigma'); ?>
                                            </span>
                                        </span>
                                    </label>
                                </td>
                            </tr>

                            <?php $boatLayoutBuilder = (string) ($settings['boat_layout_builder'] ?? 'elementor'); ?>
                            <?php if ($enableBoatPagesSync) : ?>
                                <tr>
                                    <th scope="row">
                                        <?php esc_html_e('Boat page builder', 'maradigma'); ?>
                                    </th>
                                    <td>
                                        <label style="display:flex;gap:10px;align-items:flex-start;margin-bottom:10px;">
                                            <input
                                                type="radio"
                                                name="<?php echo esc_attr(self::OPTION_KEY); ?>[boat_layout_builder]"
                                                value="elementor"
                                                <?php checked($boatLayoutBuilder, 'elementor'); ?> />
                                            <span>
                                                <strong><?php esc_html_e('Elementor', 'maradigma'); ?></strong><br>
                                                <span class="description">
                                                    <?php esc_html_e('Use the Elementor master template for generated boat pages. Gutenberg template sync will be disabled.', 'maradigma'); ?>
                                                </span>
                                            </span>
                                        </label>

                                        <label style="display:flex;gap:10px;align-items:flex-start;">
                                            <input
                                                type="radio"
                                                name="<?php echo esc_attr(self::OPTION_KEY); ?>[boat_layout_builder]"
                                                value="gutenberg"
                                                <?php checked($boatLayoutBuilder, 'gutenberg'); ?> />
                                            <span>
                                                <strong><?php esc_html_e('Gutenberg', 'maradigma'); ?></strong><br>
                                                <span class="description">
                                                    <?php esc_html_e('Use the editable Gutenberg master template for generated boat pages. Elementor template sync will be disabled.', 'maradigma'); ?>
                                                </span>
                                            </span>
                                        </label>

                                        <label style="display:flex;gap:10px;align-items:flex-start;margin-top:10px;">
                                            <input
                                                type="radio"
                                                name="<?php echo esc_attr(self::OPTION_KEY); ?>[boat_layout_builder]"
                                                value="wpbakery"
                                                <?php checked($boatLayoutBuilder, 'wpbakery'); ?> />
                                            <span>
                                                <strong><?php esc_html_e('WPBakery', 'maradigma'); ?></strong><br>
                                                <span class="description">
                                                    <?php esc_html_e('Use the editable WPBakery master template for generated boat pages. Elementor and Gutenberg template sync will be disabled.', 'maradigma'); ?>
                                                </span>
                                            </span>
                                        </label>
                                    </td>
                                </tr>
                            <?php endif; ?>

                            <?php do_action('maradigma_settings_tab_after_connection_fields', $settings); ?>
                        </tbody>
                    </table>
                    <?php
                    $eligibleBindingPostTypes = self::getEligibleBoatBindingPostTypes();
                    $enabledBindingPostTypes  = self::getEnabledBoatBindingPostTypes();
                    ?>

                    <div class="maradigma-settings-subsection">
                        <h3><?php esc_html_e('Boat binding metabox', 'maradigma'); ?></h3>

                        <p class="description" style="margin-bottom:12px;">
                            <?php esc_html_e('Choose in which post types the Maradigma boat selector should appear. This is intended for custom pages/posts created by the client, not for the internal Maradigma boat CPT.', 'maradigma'); ?>
                        </p>

                        <?php if (empty($eligibleBindingPostTypes)) : ?>
                            <p class="description"><?php esc_html_e('No eligible public post types were detected.', 'maradigma'); ?></p>
                        <?php else : ?>
                            <input
                                type="hidden"
                                name="<?php echo esc_attr(self::OPTION_KEY); ?>[boat_binding_post_types_present]"
                                value="1" />

                            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;">
                                <?php foreach ($eligibleBindingPostTypes as $postType => $postTypeData) : ?>
                                    <label class="maradigma-settings-check-card">
                                        <input
                                            type="checkbox"
                                            name="<?php echo esc_attr(self::OPTION_KEY); ?>[boat_binding_post_types][]"
                                            value="<?php echo esc_attr($postType); ?>"
                                            <?php checked(in_array($postType, $enabledBindingPostTypes, true)); ?> />
                                        <span>
                                            <strong><?php echo esc_html((string) $postTypeData['label']); ?></strong><br>
                                            <code><?php echo esc_html($postType); ?></code>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php
                    $settings = self::getSettings();
                    $deleteDataOnUninstall    = !empty($settings['delete_data_on_uninstall']);
                    $deleteUploadsOnUninstall = !empty($settings['delete_uploads_on_uninstall']);
                    ?>

                    <div class="maradigma-settings-subsection maradigma-settings-subsection--danger">
                        <h3><?php esc_html_e('Advanced / Uninstall', 'maradigma'); ?></h3>
                        <label style="display:flex;gap:10px;align-items:flex-start;margin:8px 0;">
                            <input
                                type="hidden"
                                name="<?php echo esc_attr(self::OPTION_KEY); ?>[delete_data_on_uninstall]"
                                value="0" />
                            <input
                                type="checkbox"
                                name="<?php echo esc_attr(self::OPTION_KEY); ?>[delete_data_on_uninstall]"
                                value="1"
                                <?php checked($deleteDataOnUninstall); ?> />
                            <span>
                                <strong><?php esc_html_e('Delete plugin data when uninstalling', 'maradigma'); ?></strong><br>
                                <span class="description">
                                    <?php esc_html_e('If enabled, deleting the plugin will remove boats CPT posts, postmeta, options and cache. Recommended OFF unless you want a full wipe.', 'maradigma'); ?>
                                </span>
                            </span>
                        </label>
                        <label style="display:flex;gap:10px;align-items:flex-start;margin:8px 0;opacity:<?php echo $deleteDataOnUninstall ? '1' : '0.5'; ?>;">
                            <input
                                type="hidden"
                                name="<?php echo esc_attr(self::OPTION_KEY); ?>[delete_uploads_on_uninstall]"
                                value="0" />
                            <input
                                type="checkbox"
                                name="<?php echo esc_attr(self::OPTION_KEY); ?>[delete_uploads_on_uninstall]"
                                value="1"
                                <?php checked($deleteUploadsOnUninstall); ?>
                                <?php disabled(!$deleteDataOnUninstall); ?> />
                            <span>
                                <strong><?php esc_html_e('Also delete uploads folder (optional)', 'maradigma'); ?></strong><br>
                                <span class="description">
                                    <?php esc_html_e('Only if you store files under /wp-content/uploads/maradigma/. Default OFF (recommended).', 'maradigma'); ?>
                                </span>
                            </span>
                        </label>
                    </div>

                    <?php submit_button(__('Save changes', 'maradigma')); ?>
                </form>

                <div id="maradigma-section-sync" class="maradigma-settings-section" data-maradigma-boat-sync-panel="1" data-maradigma-boat-sync-status="<?php echo esc_attr($boatSyncStatus); ?>">
                    <h3><?php esc_html_e('Sync (one page per boat)', 'maradigma'); ?></h3>
                    <p class="description">
                        <?php esc_html_e('Use these tools after the connection is ready. They keep boat pages, images and cached data aligned with your Maradigma account.', 'maradigma'); ?>
                    </p>

                    <?php if (empty($settings['enable_boat_pages_sync'])) : ?>
                        <div class="notice notice-warning inline">
                            <p>
                                <strong><?php esc_html_e('Sync is disabled. Enable "Boat pages sync (opt-in)" and save changes first.', 'maradigma'); ?></strong>
                            </p>
                        </div>
                    <?php else : ?>
                        <div style="display:flex;gap:18px;flex-wrap:wrap;margin:10px 0;">
                            <div>
                                <strong><?php esc_html_e('Last sync:', 'maradigma'); ?></strong><br>
                                <code data-md-boat-sync-field="last_sync"><?php echo esc_html($lastSyncHuman); ?></code>
                            </div>

                            <div>
                                <strong><?php esc_html_e('Boats synced:', 'maradigma'); ?></strong><br>
                                <code data-md-boat-sync-field="boats_synced"><?php echo esc_html((string) $lastBoatsTotal); ?></code>
                            </div>

                            <div>
                                <strong><?php esc_html_e('Pages touched:', 'maradigma'); ?></strong><br>
                                <code data-md-boat-sync-field="pages_touched"><?php echo esc_html((string) $lastPostsTotal); ?></code>
                            </div>

                            <div>
                                <strong><?php esc_html_e('Created:', 'maradigma'); ?></strong><br>
                                <code data-md-boat-sync-field="created"><?php echo esc_html((string) $lastPostsCreated); ?></code>
                            </div>

                            <div>
                                <strong><?php esc_html_e('Updated:', 'maradigma'); ?></strong><br>
                                <code data-md-boat-sync-field="updated"><?php echo esc_html((string) $lastPostsUpdated); ?></code>
                            </div>

                            <div>
                                <strong><?php esc_html_e('Sync status:', 'maradigma'); ?></strong><br>
                                <code data-md-boat-sync-field="status"><?php echo esc_html(self::translateSyncStatus($boatSyncStatus)); ?></code>
                            </div>

                            <div>
                                <strong><?php esc_html_e('Current phase:', 'maradigma'); ?></strong><br>
                                <code data-md-boat-sync-field="current_phase"><?php echo esc_html((string) ($boatSyncState['current_phase'] ?? '-')); ?></code>
                            </div>

                            <div>
                                <strong><?php esc_html_e('Progress:', 'maradigma'); ?></strong><br>
                                <code data-md-boat-sync-field="progress"><?php echo esc_html((int) ($boatSyncState['boats_done'] ?? 0) . ' / ' . ((int) ($boatSyncState['boats_total'] ?? 0) > 0 ? (int) $boatSyncState['boats_total'] : '?')); ?></code>
                            </div>

                            <div>
                                <strong><?php esc_html_e('Next WP-Cron run:', 'maradigma'); ?></strong><br>
                                <code data-md-boat-sync-field="next_cron"><?php echo esc_html((string) (\Maradigma\BoatSyncService::getUiStatusPayload()['next_cron_label'] ?? __('Not scheduled', 'maradigma'))); ?></code>
                            </div>
                        </div>

                        <p class="description" data-md-boat-sync-field="message" <?php echo !empty($boatSyncState['message']) ? '' : 'style="display:none;"'; ?>>
                            <?php echo esc_html((string) ($boatSyncState['message'] ?? '')); ?>
                        </p>

                        <?php if (!empty($perLang)) : ?>
                            <div style="margin-top:10px;">
                                <strong><?php esc_html_e('Pages per language:', 'maradigma'); ?></strong>
                                <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:6px;">
                                    <?php foreach ($perLang as $lang => $row) :
                                        $c = (int) ($row['created'] ?? 0);
                                        $u = (int) ($row['updated'] ?? 0);
                                        $t = (int) ($row['total'] ?? ($c + $u));
                                    ?>
                                        <span style="display:inline-flex;gap:6px;align-items:center;padding:6px 10px;border:1px solid #e5e5e5;border-radius:999px;background:#fff;">
                                            <code><?php echo esc_html(strtoupper((string) $lang)); ?></code>
                                            <span class="description" style="margin:0;">
                                                <?php
                                                echo esc_html(sprintf(
                                                    /* translators: 1: total pages, 2: created pages, 3: updated pages. */
                                                    __('total %1$d | +%2$d | ~%3$d', 'maradigma'),
                                                    $t,
                                                    $c,
                                                    $u
                                                ));
                                                ?>
                                            </span>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($lastCleanup) && (string) ($lastCleanup['action'] ?? 'none') !== 'none') : ?>
                            <div style="margin-top:10px;">
                                <strong><?php esc_html_e('Obsolete page cleanup:', 'maradigma'); ?></strong>
                                <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:6px;">
                                    <span style="display:inline-flex;gap:6px;align-items:center;padding:6px 10px;border:1px solid #e5e5e5;border-radius:999px;background:#fff;">
                                        <code><?php echo esc_html((string) ($lastCleanup['status'] ?? '-')); ?></code>
                                        <span class="description" style="margin:0;">
                                            <?php
                                            echo esc_html(sprintf(
                                                /* translators: 1: cleanup action, 2: candidate pages, 3: changed pages, 4: failed pages. */
                                                __('action %1$s | candidates %2$d | changed %3$d | failed %4$d', 'maradigma'),
                                                (string) ($lastCleanup['action'] ?? '-'),
                                                (int) ($lastCleanup['candidates'] ?? 0),
                                                (int) ($lastCleanup['changed'] ?? 0),
                                                (int) ($lastCleanup['failed'] ?? 0)
                                            ));
                                            if ((int) ($lastCleanup['images_deleted'] ?? 0) > 0 || (int) ($lastCleanup['images_failed'] ?? 0) > 0) {
                                                echo ' ';
                                                echo esc_html(sprintf(
                                                    /* translators: 1: deleted images, 2: failed image deletions. */
                                                    __('images deleted %1$d | image errors %2$d', 'maradigma'),
                                                    (int) ($lastCleanup['images_deleted'] ?? 0),
                                                    (int) ($lastCleanup['images_failed'] ?? 0)
                                                ));
                                            }
                                            ?>
                                        </span>
                                    </span>
                                </div>
                                <?php if (!empty($lastCleanup['message'])) : ?>
                                    <p class="description" style="margin-top:6px;">
                                        <?php echo esc_html(self::translateCleanupMessage($lastCleanup)); ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <form method="post" action="<?php echo esc_url($adminPostUrl); ?>">
                            <?php wp_nonce_field('maradigma_sync_boats_action', 'maradigma_sync_nonce'); ?>
                            <input type="hidden" name="action" value="maradigma_sync_boats">

                            <fieldset class="maradigma-settings-subsection">
                                <legend style="padding:0 6px;font-weight:600;">
                                    <?php esc_html_e('Sync options', 'maradigma'); ?>
                                </legend>

                                <label style="display:block;margin:6px 0;">
                                    <input type="checkbox" name="sync[update_post_title_slug]" value="1" checked>
                                    <?php esc_html_e('Update post title & slug', 'maradigma'); ?>
                                </label>

                                <label style="display:block;margin:6px 0;">
                                    <input type="checkbox" name="sync[update_payload]" value="1" checked>
                                    <?php esc_html_e('Update payload (cached boat data)', 'maradigma'); ?>
                                </label>

                                <label style="display:block;margin:6px 0;">
                                    <input type="checkbox" name="sync[sync_images]" value="1" checked>
                                    <?php esc_html_e('Sync images (download/attach)', 'maradigma'); ?>
                                </label>

                                <label style="display:block;margin:10px 0 6px 0;font-weight:600;">
                                    <?php esc_html_e('Yoast SEO', 'maradigma'); ?>
                                </label>
                                <select name="sync[yoast_mode]">
                                    <option value="fix_multilang" selected><?php esc_html_e('Fix multi-language values (recommended)', 'maradigma'); ?></option>
                                    <option value="missing"><?php esc_html_e('Only if missing', 'maradigma'); ?></option>
                                    <option value="skip"><?php esc_html_e('Do not touch', 'maradigma'); ?></option>
                                    <option value="force"><?php esc_html_e('Force overwrite (danger)', 'maradigma'); ?></option>
                                </select>

                                <?php $boatLayoutBuilder = (string) ($settings['boat_layout_builder'] ?? 'elementor'); ?>
                                <input type="hidden" name="sync[layout_builder]" value="<?php echo esc_attr($boatLayoutBuilder); ?>">

                                <?php if ($boatLayoutBuilder === 'gutenberg') : ?>
                                    <label style="display:block;margin:10px 0 6px 0;font-weight:600;">
                                        <?php esc_html_e('Gutenberg template', 'maradigma'); ?>
                                    </label>
                                    <select name="sync[gutenberg_mode]">
                                        <option value="seed_missing" selected><?php esc_html_e('Seed only if content is empty', 'maradigma'); ?></option>
                                        <option value="overwrite"><?php esc_html_e('Overwrite managed Gutenberg layouts only', 'maradigma'); ?></option>
                                        <option value="skip"><?php esc_html_e('Do not touch Gutenberg content', 'maradigma'); ?></option>
                                    </select>
                                <?php elseif ($boatLayoutBuilder === 'wpbakery') : ?>
                                    <label style="display:block;margin:10px 0 6px 0;font-weight:600;">
                                        <?php esc_html_e('WPBakery template', 'maradigma'); ?>
                                    </label>
                                    <select name="sync[wpbakery_mode]">
                                        <option value="seed_missing" selected><?php esc_html_e('Seed only if content is empty', 'maradigma'); ?></option>
                                        <option value="overwrite"><?php esc_html_e('Overwrite managed WPBakery layouts only', 'maradigma'); ?></option>
                                        <option value="skip"><?php esc_html_e('Do not touch WPBakery content', 'maradigma'); ?></option>
                                    </select>
                                <?php else : ?>
                                    <label style="display:block;margin:10px 0 6px 0;font-weight:600;">
                                        <?php esc_html_e('Elementor template', 'maradigma'); ?>
                                    </label>
                                    <select name="sync[elementor_mode]">
                                        <option value="seed_missing" selected><?php esc_html_e('Seed only if missing', 'maradigma'); ?></option>
                                        <option value="overwrite"><?php esc_html_e('Overwrite non-custom Elementor layouts', 'maradigma'); ?></option>
                                        <option value="skip"><?php esc_html_e('Do not touch Elementor data', 'maradigma'); ?></option>
                                    </select>
                                <?php endif; ?>
                            </fieldset>

                            <fieldset class="maradigma-settings-subsection">
                                <legend style="padding:0 6px;font-weight:600;">
                                    <?php esc_html_e('Obsolete boat pages', 'maradigma'); ?>
                                </legend>

                                <p class="description" style="margin:6px 0 10px 0;">
                                    <?php esc_html_e('Optional cleanup for managed boat pages whose boat ID no longer appears in the Maradigma API response. It only runs after a valid non-empty API list has been processed.', 'maradigma'); ?>
                                </p>

                                <label style="display:block;margin:10px 0 6px 0;font-weight:600;" for="maradigma_cleanup_obsolete">
                                    <?php esc_html_e('When a synced boat no longer exists in Maradigma', 'maradigma'); ?>
                                </label>
                                <select id="maradigma_cleanup_obsolete" name="sync[cleanup_obsolete]">
                                    <option value="none" selected><?php esc_html_e('Do nothing', 'maradigma'); ?></option>
                                    <option value="trash"><?php esc_html_e('Move managed pages to trash', 'maradigma'); ?></option>
                                    <option value="draft"><?php esc_html_e('Unpublish managed pages', 'maradigma'); ?></option>
                                    <option value="delete"><?php esc_html_e('Delete managed pages permanently', 'maradigma'); ?></option>
                                </select>

                                <label style="display:flex;gap:8px;align-items:flex-start;margin:10px 0;">
                                    <input type="checkbox" name="sync[cleanup_delete_confirm]" value="1">
                                    <span>
                                        <strong><?php esc_html_e('I understand permanent deletion cannot be undone.', 'maradigma'); ?></strong><br>
                                        <span class="description">
                                            <?php esc_html_e('Required only if you choose permanent deletion. Pages marked as custom/no-sync are never cleaned automatically.', 'maradigma'); ?>
                                        </span>
                                    </span>
                                </label>

                                <label style="display:flex;gap:8px;align-items:flex-start;margin:10px 0;">
                                    <input type="checkbox" name="sync[cleanup_delete_images]" value="1">
                                    <span>
                                        <strong><?php esc_html_e('Also delete locally cached images for obsolete boats.', 'maradigma'); ?></strong><br>
                                        <span class="description">
                                            <?php esc_html_e('Only applies when permanent deletion is selected. It deletes Media Library attachments tagged with the obsolete Maradigma boat ID.', 'maradigma'); ?>
                                        </span>
                                    </span>
                                </label>
                            </fieldset>

                            <p style="margin:14px 0 0 0;">
                                <button type="submit" class="button button-primary">
                                    <?php esc_html_e('Sync boats now', 'maradigma'); ?>
                                </button>
                                <button type="submit" class="button" name="sync_restart" value="1">
                                    <?php esc_html_e('Restart sync', 'maradigma'); ?>
                                </button>
                                <button type="button" class="button" data-md-boat-sync-stop="1" <?php echo $boatSyncStatus === 'running' ? '' : 'style="display:none;"'; ?>>
                                    <?php esc_html_e('Stop sync', 'maradigma'); ?>
                                </button>
                            </p>
                        </form>
                    <?php endif; ?>
                </div>

                <?php if (!empty($settings['store_boat_images_locally'])) : ?>
                    <?php
                    $mediaState = \Maradigma\BoatImagesSyncService::getState();
                    $mediaStats = \Maradigma\BoatImagesSyncService::getCacheStats();
                    $mediaUi    = \Maradigma\BoatImagesSyncService::getUiStatusPayload();

                    $st         = (string) ($mediaState['status'] ?? 'idle');
                    $boatsTotal = (int) ($mediaState['boats_total'] ?? 0);
                    $boatsDone  = (int) ($mediaState['boats_processed'] ?? 0);
                    $imported   = (int) ($mediaState['images_imported'] ?? 0);
                    $reused     = (int) ($mediaState['images_reused'] ?? 0);
                    $failed     = (int) ($mediaState['images_failed'] ?? 0);
                    $msg        = (string) ($mediaState['message'] ?? '');
                    $lastRunAt  = (int) ($mediaState['last_run_at'] ?? 0);
                    $lastRunLabel = $lastRunAt > 0
                        ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $lastRunAt)
                        : __('Never', 'maradigma');
                    $nextCronLabel = (string) ($mediaUi['next_cron_label'] ?? __('Not scheduled', 'maradigma'));
                    $workerLabel = self::translateImageWorkerLabel((string) ($mediaUi['worker_label'] ?? ''));
                    $asyncKickLabel = self::translateAsyncKickLabel((string) ($mediaUi['async_kick_label'] ?? ''));
                    $asyncKickError = (string) ($mediaUi['async_kick_error'] ?? '');

                    $currentBoatId         = (string) ($mediaState['current_boat_id'] ?? '');
                    $currentBoatImages     = (int) ($mediaState['current_boat_images_count'] ?? 0);
                    $currentImageIndex     = (int) ($mediaState['current_image_index'] ?? 0);
                    $hasCurrentBoatRunning = (
                        $st === 'running'
                        && $currentBoatId !== ''
                        && $currentBoatImages > 0
                    );
                    ?>
                <div class="maradigma-settings-section" data-maradigma-image-sync-panel="1" data-maradigma-image-sync-status="<?php echo esc_attr($st); ?>">
                    <h3><?php esc_html_e('Boat images (Media Library)', 'maradigma'); ?></h3>

                        <div style="display:flex;gap:18px;flex-wrap:wrap;margin:10px 0;">
                            <div>
                                <strong><?php esc_html_e('Cached boats:', 'maradigma'); ?></strong><br>
                                <code data-md-image-sync-field="cached_boats"><?php echo esc_html((string) $mediaStats['boats']); ?></code>
                            </div>
                            <div>
                                <strong><?php esc_html_e('Cached images:', 'maradigma'); ?></strong><br>
                                <code data-md-image-sync-field="cached_images"><?php echo esc_html((string) $mediaStats['attachments']); ?></code>
                            </div>
                            <div>
                                <strong><?php esc_html_e('Sync status:', 'maradigma'); ?></strong><br>
                                <code data-md-image-sync-field="status"><?php echo esc_html(self::translateSyncStatus($st)); ?></code>
                            </div>
                            <div>
                                <strong><?php esc_html_e('Last image sync run:', 'maradigma'); ?></strong><br>
                                <code data-md-image-sync-field="last_run"><?php echo esc_html($lastRunLabel); ?></code>
                            </div>
                            <div>
                                <strong><?php esc_html_e('Next WP-Cron run:', 'maradigma'); ?></strong><br>
                                <code data-md-image-sync-field="next_cron"><?php echo esc_html($nextCronLabel); ?></code>
                            </div>
                            <div>
                                <strong><?php esc_html_e('Worker state:', 'maradigma'); ?></strong><br>
                                <code data-md-image-sync-field="worker_state"><?php echo esc_html($workerLabel); ?></code>
                            </div>
                            <div>
                                <strong><?php esc_html_e('Async kick:', 'maradigma'); ?></strong><br>
                                <code data-md-image-sync-field="async_kick"><?php echo esc_html($asyncKickLabel); ?></code>
                            </div>

                            <?php if ($st === 'running' || $st === 'done') : ?>
                                <div>
                                    <strong><?php esc_html_e('Progress:', 'maradigma'); ?></strong><br>
                                    <code data-md-image-sync-field="progress"><?php echo esc_html($boatsDone . ' / ' . ($boatsTotal > 0 ? $boatsTotal : '?')); ?></code>
                                </div>

                                <div data-md-image-sync-current-row="1" <?php echo $hasCurrentBoatRunning ? '' : 'style="display:none;"'; ?>>
                                    <strong><?php esc_html_e('Current boat:', 'maradigma'); ?></strong><br>
                                    <code data-md-image-sync-field="current_boat"><?php echo esc_html($hasCurrentBoatRunning ? $currentBoatId : ''); ?></code>
                                </div>
                                <div data-md-image-sync-current-row="1" <?php echo $hasCurrentBoatRunning ? '' : 'style="display:none;"'; ?>>
                                    <strong><?php esc_html_e('Current boat images:', 'maradigma'); ?></strong><br>
                                    <code data-md-image-sync-field="current_images"><?php echo esc_html($hasCurrentBoatRunning ? ($currentImageIndex . ' / ' . $currentBoatImages) : ''); ?></code>
                                </div>

                                <div>
                                    <strong><?php esc_html_e('This run:', 'maradigma'); ?></strong><br>
                                    <code data-md-image-sync-field="run_counts">
                                        <?php
                                        echo esc_html(sprintf(
                                            /* translators: 1: imported images, 2: reused images, 3: failed images. */
                                            __('imported %1$d | reused %2$d | failed %3$d', 'maradigma'),
                                            $imported,
                                            $reused,
                                            $failed
                                        ));
                                        ?>
                                    </code>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($msg !== '') : ?>
                            <p class="description" data-md-image-sync-field="message"><?php echo esc_html(self::translateSyncMessage($msg)); ?></p>
                        <?php else : ?>
                            <p class="description" data-md-image-sync-field="message" style="display:none;"></p>
                        <?php endif; ?>

                        <?php if ($asyncKickError !== '') : ?>
                            <p class="description" data-md-image-sync-field="async_error"><?php echo esc_html($asyncKickError); ?></p>
                        <?php else : ?>
                            <p class="description" data-md-image-sync-field="async_error" style="display:none;"></p>
                        <?php endif; ?>

                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:10px;">
                            <?php wp_nonce_field('maradigma_images_sync_action', 'maradigma_images_sync_nonce'); ?>
                            <input type="hidden" name="action" value="maradigma_images_sync_start">
                            <label style="margin-right:10px;">
                                <input type="checkbox" name="force_resync" value="1">
                                <?php esc_html_e('Force re-download (use only if needed)', 'maradigma'); ?>
                            </label>
                            <button type="submit" class="button button-primary">
                                <?php echo ($st === 'running') ? esc_html__('Resume / Keep syncing', 'maradigma') : esc_html__('Sync images now', 'maradigma'); ?>
                            </button>
                        </form>

                        <?php if ($st === 'running') : ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
                                <?php wp_nonce_field('maradigma_images_sync_action', 'maradigma_images_sync_nonce'); ?>
                                <input type="hidden" name="action" value="maradigma_images_sync_stop">
                                <button type="submit" class="button">
                                    <?php esc_html_e('Stop', 'maradigma'); ?>
                                </button>
                            </form>
                        <?php endif; ?>

                        <form method="post"
                            action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                            style="display:inline-block;margin-right:10px;"
                            onsubmit="return confirm('<?php echo esc_js(__('This will DELETE all cached Maradigma images from the Media Library and rebuild them. Continue?', 'maradigma')); ?>');">
                            <?php wp_nonce_field('maradigma_images_sync_action', 'maradigma_images_sync_nonce'); ?>
                            <input type="hidden" name="action" value="maradigma_images_sync_reset">

                            <label style="margin-right:10px;">
                                <input type="checkbox" name="reset_and_start" value="1" checked>
                                <?php esc_html_e('Reset and start syncing again', 'maradigma'); ?>
                            </label>

                            <button type="submit" class="button button-danger" style="margin-left:6px;">
                                <?php esc_html_e('Reset images cache (nuclear)', 'maradigma'); ?>
                            </button>
                        </form>

                        <p class="description" style="margin-top:8px;">
                            <?php esc_html_e('This runs in small batches to avoid timeouts. You can close this page; it will continue via WP-Cron.', 'maradigma'); ?>
                        </p>
                    </div>
                <?php endif; ?>

                <div class="maradigma-settings-section">
                    <h3><?php esc_html_e('Maintenance tools', 'maradigma'); ?></h3>
                    <p class="description">
                        <?php esc_html_e('Use these actions only when cached data looks outdated or when you need to rebuild generated boat pages.', 'maradigma'); ?>
                    </p>

                    <form method="post" action="<?php echo esc_url($adminPostUrl); ?>"
                        onsubmit="return confirm('<?php echo esc_js(__('This will clear Maradigma cache. Continue?', 'maradigma')); ?>');">
                        <?php wp_nonce_field('maradigma_flush_cache_action', 'maradigma_flush_cache_nonce'); ?>
                        <input type="hidden" name="action" value="maradigma_flush_cache">
                        <button type="submit" class="button">
                            <?php esc_html_e('Clear plugin cache', 'maradigma'); ?>
                        </button>
                    </form>
                    <p class="description" style="margin:6px 0 0 0;">
                        <?php esc_html_e('Clears cached boats list and boat details. Use if you changed settings or data looks outdated.', 'maradigma'); ?>
                    </p>

                    <div class="maradigma-settings-subsection maradigma-settings-subsection--danger">
                    <form method="post" action="<?php echo esc_url($adminPostUrl); ?>"
                        onsubmit="return confirm('<?php echo esc_js(__('This will DELETE all boat pages created by Maradigma (all languages). Continue?', 'maradigma')); ?>');">
                        <?php wp_nonce_field('maradigma_delete_all_boats_action', 'maradigma_delete_all_boats_nonce'); ?>
                        <input type="hidden" name="action" value="maradigma_delete_all_boats">

                        <label style="display:block;margin:6px 0;">
                            <input type="checkbox" name="delete[force]" value="1">
                            <?php esc_html_e('Delete permanently (skip trash)', 'maradigma'); ?>
                        </label>

                        <button type="submit" class="button button-secondary">
                            <?php esc_html_e('Reset boats (delete all boat pages)', 'maradigma'); ?>
                        </button>
                    </form>

                    <p class="description" style="margin:6px 0 0 0;">
                        <?php esc_html_e('Deletes ALL maradigma_boat posts (including translations). Useful to start sync from scratch.', 'maradigma'); ?>
                    </p>
                    </div>
                </div>

                <?php if (!empty($settings['enable_boat_pages_sync'])) : ?>
                <?php
                $boatLayoutBuilder = (string) ($settings['boat_layout_builder'] ?? 'elementor');
                $tplState      = \Maradigma\BoatTemplateSyncAllService::getDisplayState();
                $tplStatus     = (string) ($tplState['status'] ?? 'idle');
                $templateStats = SettingsTemplateStats::getSyncStats();

                $masterId        = (int) ($templateStats['master_id'] ?? 0);
                $masterHash      = (string) ($templateStats['master_hash'] ?? '');
                $totalBoats      = (int) ($templateStats['total'] ?? 0);
                $syncedBoats     = (int) ($templateStats['synced'] ?? 0);
                $notSyncedBoats  = (int) ($templateStats['not_synced'] ?? 0);
                $customBoats     = (int) ($templateStats['custom'] ?? 0);
                $missingData     = (int) ($templateStats['missing_data'] ?? 0);
                $masterEditUrl   = (string) ($templateStats['master_edit_url'] ?? '');
                $builderLabel    = $boatLayoutBuilder === 'gutenberg' ? __('Gutenberg', 'maradigma') : ($boatLayoutBuilder === 'wpbakery' ? __('WPBakery', 'maradigma') : __('Elementor', 'maradigma'));
                $elementorTemplateCandidates = $boatLayoutBuilder === 'elementor'
                    ? SettingsTemplateStats::getElementorLayoutCandidates()
                    : [];
                ?>

                <div id="maradigma-section-templates" class="maradigma-settings-section">
                    <h3>
                        <?php
                        echo esc_html(sprintf(
                            /* translators: %s: layout builder name, such as Elementor or Gutenberg. */
                            __('%s template status (boats)', 'maradigma'),
                            $builderLabel
                        ));
                        ?>
                    </h3>

                    <div style="display:flex;gap:18px;flex-wrap:wrap;margin:10px 0;">
                        <div>
                            <strong><?php esc_html_e('Master template ID:', 'maradigma'); ?></strong><br>
                            <code><?php echo esc_html($masterId > 0 ? (string) $masterId : '-'); ?></code>
                        </div>

                        <div>
                            <strong><?php esc_html_e('Synced boats:', 'maradigma'); ?></strong><br>
                            <code><?php echo esc_html((string) $syncedBoats); ?></code>
                        </div>

                        <div>
                            <strong><?php esc_html_e('Not synced boats:', 'maradigma'); ?></strong><br>
                            <code><?php echo esc_html((string) $notSyncedBoats); ?></code>
                        </div>

                        <div>
                            <strong><?php esc_html_e('Custom layout boats:', 'maradigma'); ?></strong><br>
                            <code><?php echo esc_html((string) $customBoats); ?></code>
                        </div>

                        <div>
                            <strong><?php esc_html_e('Total boats:', 'maradigma'); ?></strong><br>
                            <code><?php echo esc_html((string) $totalBoats); ?></code>
                        </div>
                    </div>

                    <?php if ($missingData > 0) : ?>
                        <p class="description" style="margin:0 0 10px 0;">
                            <?php
                            echo esc_html(sprintf(
                                $boatLayoutBuilder === 'gutenberg'
                                    /* translators: %d: number of boats without Gutenberg content. */
                                    ? __('Note: %d boats have no Gutenberg content yet.', 'maradigma')
                                    /* translators: %d: number of boats without Elementor data. */
                                    : __('Note: %d boats have no Elementor data yet (_elementor_data empty).', 'maradigma'),
                                $missingData
                            ));
                            ?>
                        </p>
                    <?php endif; ?>

                    <?php if ($boatLayoutBuilder === 'gutenberg') : ?>
                        <div style="margin:10px 0 0 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin:0;">
                                <?php wp_nonce_field('maradigma_open_gutenberg_template_action', 'maradigma_open_gutenberg_template_nonce'); ?>
                                <input type="hidden" name="action" value="maradigma_open_gutenberg_template">

                                <button type="submit" class="button button-primary">
                                    <?php esc_html_e('Edit Gutenberg boat template', 'maradigma'); ?>
                                </button>
                            </form>

                            <?php if ($masterId > 0 && $masterEditUrl !== '') : ?>
                                <a class="button" href="<?php echo esc_url($masterEditUrl); ?>">
                                    <?php esc_html_e('Open directly', 'maradigma'); ?>
                                </a>
                            <?php endif; ?>

                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin:0;">
                                <?php wp_nonce_field('maradigma_reset_gutenberg_template_action', 'maradigma_reset_gutenberg_template_nonce'); ?>
                                <input type="hidden" name="action" value="maradigma_reset_gutenberg_template">

                                <button type="submit" class="button"
                                    onclick="return confirm('<?php echo esc_js(__('This will replace the editable Gutenberg master template with the plugin default layout. Boat pages are not changed until you apply the template to all boats. Continue?', 'maradigma')); ?>');">
                                    <?php esc_html_e('Restore default Gutenberg template', 'maradigma'); ?>
                                </button>
                            </form>
                        </div>

                        <p class="description" style="margin:8px 0 0 0;">
                            <?php esc_html_e('Edit the master template in Gutenberg, or restore it to the plugin default layout. Restoring the master template does not update existing boat pages until you apply the template below.', 'maradigma'); ?>
                        </p>
                    <?php elseif ($boatLayoutBuilder === 'wpbakery') : ?>
                        <?php if ($masterId > 0 && $masterEditUrl !== '') : ?>
                            <p style="margin:10px 0 0 0;">
                                <a class="button button-primary" href="<?php echo esc_url($masterEditUrl); ?>">
                                    <?php esc_html_e('Edit WPBakery boat template', 'maradigma'); ?>
                                </a>
                            </p>
                        <?php endif; ?>
                        <p class="description" style="margin:8px 0 0 0;">
                            <?php esc_html_e('Edit the master template with WPBakery. Existing boat pages are updated only when you apply the template below.', 'maradigma'); ?>
                        </p>
                    <?php elseif ($masterId > 0 && $masterEditUrl !== '') : ?>
                        <p style="margin:10px 0 0 0;">
                            <a class="button" href="<?php echo esc_url($masterEditUrl); ?>">
                                <?php
                                echo esc_html(sprintf(
                                    /* translators: %s: layout builder name, such as Elementor. */
                                    __('Edit master template in %s', 'maradigma'),
                                    $builderLabel
                                ));
                                ?>
                            </a>
                        </p>
                        <?php if (!empty($elementorTemplateCandidates)) : ?>
                            <div style="margin:12px 0 0 0;padding:12px;border:1px solid rgba(0,0,0,.08);border-radius:8px;background:#fff;">
                                <strong><?php esc_html_e('Replace master template with an existing boat layout', 'maradigma'); ?></strong>
                                <p class="description" style="margin:6px 0 10px 0;">
                                    <?php esc_html_e('Choose a boat that already has the Elementor structure you want to reuse. This only updates the master template; boats are not overwritten until you run the apply action below.', 'maradigma'); ?>
                                </p>

                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;">
                                    <?php wp_nonce_field('maradigma_import_boat_elementor_template_action', 'maradigma_import_boat_elementor_template_nonce'); ?>
                                    <input type="hidden" name="action" value="maradigma_import_boat_elementor_template">

                                    <label style="display:flex;flex-direction:column;gap:6px;min-width:340px;max-width:100%;">
                                        <span class="description" style="margin:0;"><?php esc_html_e('Source boat', 'maradigma'); ?></span>
                                        <select name="source_boat_post_id" style="min-width:340px;max-width:100%;">
                                            <option value=""><?php esc_html_e('Select a boat layout?', 'maradigma'); ?></option>
                                            <?php foreach ($elementorTemplateCandidates as $candidateId => $candidateLabel) : ?>
                                                <option value="<?php echo esc_attr((string) $candidateId); ?>"><?php echo esc_html($candidateLabel); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>

                                    <button type="submit" class="button"
                                        onclick="return confirm('<?php echo esc_js(__('This will replace the current Elementor master template with the layout from the selected boat. Continue?', 'maradigma')); ?>');">
                                        <?php esc_html_e('Use boat layout as master template', 'maradigma'); ?>
                                    </button>
                                </form>
                            </div>
                        <?php endif; ?>
                    <?php else : ?>
                        <div class="notice notice-warning inline" style="margin-top:10px;">
                            <p><strong><?php
                                echo esc_html(sprintf(
                                    /* translators: %s: layout builder name, such as Elementor. */
                                    __('Master template not found yet. Run a boats sync in %s mode to create it.', 'maradigma'),
                                    $builderLabel
                                ));
                            ?></strong></p>
                        </div>
                    <?php endif; ?>

                    <?php if ($masterHash !== '') : ?>
                        <p class="description" style="margin-top:8px;">
                            <?php esc_html_e('Master hash:', 'maradigma'); ?>
                            <code><?php echo esc_html($masterHash); ?></code>
                        </p>
                    <?php endif; ?>

                    <div class="maradigma-settings-subsection"
                        data-maradigma-template-sync-panel="1"
                        data-maradigma-template-sync-status="<?php echo esc_attr($tplStatus); ?>">
                        <strong><?php
                            echo esc_html(sprintf(
                                /* translators: %s: layout builder name, such as Elementor or Gutenberg. */
                                __('Apply %s master template to all boats', 'maradigma'),
                                $builderLabel
                            ));
                        ?></strong>
                        <p class="description" style="margin:6px 0 10px 0;">
                            <?php
                            echo esc_html(
                                $boatLayoutBuilder === 'gutenberg'
                                    ? __('This will apply the current Gutenberg master template to boat pages. Non-forced mode only overwrites managed Gutenberg layouts.', 'maradigma')
                                    : ($boatLayoutBuilder === 'wpbakery'
                                        ? __('This will apply the current WPBakery master template to boat pages. Non-forced mode only overwrites managed WPBakery layouts.', 'maradigma')
                                        : __('This will overwrite Elementor layout on all boats except those marked as Custom layout.', 'maradigma'))
                            );
                            ?>
                        </p>

                        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-flex;gap:8px;align-items:center;flex-wrap:wrap;">
                                <?php wp_nonce_field('maradigma_force_template_sync_action', 'maradigma_force_template_sync_nonce'); ?>
                                <input type="hidden" name="action" value="maradigma_force_template_sync">
                                <input type="hidden" name="layout_builder" value="<?php echo esc_attr($boatLayoutBuilder); ?>">

                                <label class="description" style="margin:0;">
                                    <?php esc_html_e('Mode:', 'maradigma'); ?>
                                    <select name="template_mode">
                                        <option value="overwrite" selected><?php esc_html_e('Overwrite managed/non-custom layouts', 'maradigma'); ?></option>
                                        <option value="force"><?php esc_html_e('Force overwrite all boats', 'maradigma'); ?></option>
                                    </select>
                                </label>

                                <button type="submit" class="button button-primary"
                                    onclick="return confirm('<?php echo esc_js(__('This can overwrite boat page layouts. Continue?', 'maradigma')); ?>');">
                                    <?php esc_html_e('Apply template to all boats', 'maradigma'); ?>
                                </button>
                            </form>

                            <form method="post"
                                action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                                data-md-template-sync-stop="1"
                                style="display:<?php echo $tplStatus === 'running' ? 'inline-flex' : 'none'; ?>;gap:8px;align-items:center;">
                                <?php wp_nonce_field('maradigma_force_template_sync_action', 'maradigma_force_template_sync_nonce'); ?>
                                <input type="hidden" name="action" value="maradigma_force_template_sync_stop">
                                <button type="submit" class="button">
                                    <?php esc_html_e('Stop', 'maradigma'); ?>
                                </button>
                            </form>
                        </div>

                        <div style="margin-top:10px;">
                            <strong><?php esc_html_e('Status:', 'maradigma'); ?></strong>
                            <code data-md-template-sync-field="status"><?php echo esc_html(self::translateSyncStatus($tplStatus)); ?></code>

                            <?php if (!empty($tplState)) : ?>
                                <span class="description" style="margin-left:8px;">
                                    <?php
                                    $scanned = (int) ($tplState['pages_scanned'] ?? ($tplState['boats_scanned'] ?? 0));
                                    $total   = (int) ($tplState['pages_total'] ?? ($tplState['boats_total'] ?? 0));
                                    $boatsTotal = (int) ($tplState['boats_total'] ?? 0);
                                    $languagesOnPages = (int) ($tplState['languages_on_pages'] ?? 0);
                                    $languageCodes = isset($tplState['language_codes']) && is_array($tplState['language_codes']) ? $tplState['language_codes'] : [];
                                    $upd     = (int) ($tplState['pages_updated'] ?? ($tplState['boats_updated'] ?? 0));
                                    $skip    = (int) ($tplState['pages_skipped'] ?? ($tplState['boats_skipped'] ?? ($tplState['boats_skipped_custom'] ?? 0)));
                                    $fail    = (int) ($tplState['pages_failed'] ?? ($tplState['boats_failed'] ?? 0));
                                    $msg     = (string) ($tplState['message'] ?? '');
                                    esc_html_e('pages scanned', 'maradigma');
                                    ?>
                                    <span data-md-template-sync-field="progress"><?php echo esc_html($scanned . ' / ' . ($total > 0 ? (string) $total : '?')); ?></span>
                                    |
                                    <?php esc_html_e('unique boats', 'maradigma'); ?>
                                    <span data-md-template-sync-field="boats_total"><?php echo esc_html((string) $boatsTotal); ?></span>
                                    |
                                    <?php esc_html_e('languages', 'maradigma'); ?>
                                    <span data-md-template-sync-field="languages_on_pages"><?php echo esc_html($languagesOnPages . ($languageCodes !== [] ? ' (' . implode(', ', array_map('strtoupper', $languageCodes)) . ')' : '')); ?></span>
                                    |
                                    <?php esc_html_e('pages updated', 'maradigma'); ?>
                                    <span data-md-template-sync-field="updated"><?php echo esc_html((string) $upd); ?></span>
                                    |
                                    <?php esc_html_e('pages skipped', 'maradigma'); ?>
                                    <span data-md-template-sync-field="skipped"><?php echo esc_html((string) $skip); ?></span>
                                    |
                                    <?php esc_html_e('pages failed', 'maradigma'); ?>
                                    <span data-md-template-sync-field="failed"><?php echo esc_html((string) $fail); ?></span>
                                </span>
                                <?php if ($msg !== '') : ?>
                                    <div class="description" style="margin-top:6px;" data-md-template-sync-field="message"><?php echo esc_html(self::translateSyncMessage($msg)); ?></div>
                                <?php else : ?>
                                    <div class="description" style="margin-top:6px;" data-md-template-sync-field="message"></div>
                                <?php endif; ?>
                                <div class="description" style="margin-top:6px;" data-md-template-sync-field="skip_reasons">
                                    <?php echo esc_html(self::formatTemplateSkipReasons($tplState['skip_reasons'] ?? [])); ?>
                                </div>
                                <div class="description" style="margin-top:6px;">
                                    <?php esc_html_e('Last run:', 'maradigma'); ?>
                                    <span data-md-template-sync-field="last_run"><?php echo esc_html(!empty($tplState['last_run_at']) ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), (int) $tplState['last_run_at']) : __('Never', 'maradigma')); ?></span>
                                    |
                                    <?php esc_html_e('Next scheduled run:', 'maradigma'); ?>
                                    <span data-md-template-sync-field="next_cron"><?php
                                        $tplNextCron = \Maradigma\BoatTemplateSyncAllService::getNextScheduledAt();
                                        echo esc_html($tplNextCron > 0 ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $tplNextCron) : __('Not scheduled', 'maradigma'));
                                    ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                    </div>

                    <div class="maradigma-card">
                        <h2 id="maradigma-section-compatibility">
                            <span class="maradigma-dot"></span>
                            <?php esc_html_e('Quick summary', 'maradigma'); ?>
                        </h2>

                        <ul class="maradigma-feature-list">
                            <li><?php esc_html_e('Boat catalog synced with your Maradigma account.', 'maradigma'); ?></li>
                            <li><?php esc_html_e('Online bookings directly from your website.', 'maradigma'); ?></li>
                            <li><?php esc_html_e('SEO-ready content: friendly URLs and one page per boat.', 'maradigma'); ?></li>
                            <li><?php esc_html_e('Easy integration via shortcodes or your theme templates.', 'maradigma'); ?></li>
                        </ul>

                        <h3 style="margin-top:14px;"><?php esc_html_e('Compatibility detected', 'maradigma'); ?></h3>

                        <ul class="maradigma-compat-list">
                            <li class="<?php echo !empty($compat['elementor']) ? 'is-ok' : 'is-off'; ?>">
                                <?php
                                echo !empty($compat['elementor'])
                                    ? '<span aria-hidden="true">&#10004;</span> ' . esc_html__('Elementor detected - Widgets enabled', 'maradigma')
                                    : '<span aria-hidden="true">&#9888;</span> ' . esc_html__('Elementor not detected', 'maradigma');
                                ?>
                            </li>

                            <li class="<?php echo !empty($compat['yoast']) ? 'is-ok' : 'is-off'; ?>">
                                <?php
                                echo !empty($compat['yoast'])
                                    ? '<span aria-hidden="true">&#10004;</span> ' . esc_html__('Yoast SEO detected - SEO integration available', 'maradigma')
                                    : '<span aria-hidden="true">&#9888;</span> ' . esc_html__('Yoast SEO not detected', 'maradigma');
                                ?>
                            </li>

                            <?php
                            $providerCompat        = strtolower(trim((string) ($compat['multilang_provider'] ?? '')));
                            $boatsTranslatable     = !empty($compat['boats_translatable']);
                            ?>

                            <?php if ($providerCompat === '') : ?>
                                <li class="is-off">
                                    <?php echo '<span aria-hidden="true">&#9888;</span> ' . esc_html__('No multilingual plugin detected (Polylang/WPML)', 'maradigma'); ?>
                                </li>

                            <?php else : ?>

                                <li class="is-ok">
                                    <?php
                                    echo $providerCompat === 'polylang'
                                        ? '<span aria-hidden="true">&#10004;</span> ' . esc_html__('Polylang detected - Multilang active', 'maradigma')
                                        : ($providerCompat === 'wpml'
                                            ? '<span aria-hidden="true">&#10004;</span> ' . esc_html__('WPML detected - Multilang active', 'maradigma')
                                            : '<span aria-hidden="true">&#10004;</span> ' . esc_html__('Multilang detected', 'maradigma'));
                                    ?>
                                </li>

                                <li class="<?php echo $boatsTranslatable ? 'is-ok' : 'is-off'; ?>">
                                    <?php
                                    if ($boatsTranslatable) {
                                        echo '<span aria-hidden="true">&#10004;</span> ' . esc_html__('Boats post type is translatable in Polylang (language columns enabled).', 'maradigma');
                                    } else {
                                        if ($providerCompat === 'polylang') {
                                            echo '<span aria-hidden="true">&#9888;</span> ' . esc_html__('Boats post type is NOT enabled for translation in Polylang. Go to Languages > Settings > Custom post types and enable "Boats (maradigma_boat)", then save.', 'maradigma');
                                        } elseif ($providerCompat === 'wpml') {
                                            echo '<span aria-hidden="true">&#9888;</span> ' . esc_html__('Boats post type is NOT marked as translatable in WPML. Enable it in WPML settings or add wpml-config.xml.', 'maradigma');
                                        } else {
                                            echo '<span aria-hidden="true">&#9888;</span> ' . esc_html__('Boats post type is NOT marked as translatable.', 'maradigma');
                                        }
                                    }
                                    ?>
                                </li>

                            <?php endif; ?>
                        </ul>

                        <div class="maradigma-cta-buttons">
                            <a href="https://maradigma.com" target="_blank" rel="noopener" class="maradigma-cta-primary">
                                <?php esc_html_e('See more about Maradigma', 'maradigma'); ?>
                            </a>
                            <a href="https://maradigma.com/contacto" target="_blank" rel="noopener" class="maradigma-cta-secondary">
                                <?php esc_html_e('Request information or a demo', 'maradigma'); ?>
                            </a>
                        </div>
                    </div>
                </div>

            <?php elseif ($activeTab === 'booking') : ?>

                <div class="maradigma-settings-grid">
                    <div class="maradigma-card">
                        <h2>
                            <span class="maradigma-dot"></span>
                            <?php esc_html_e('Booking settings', 'maradigma'); ?>
                        </h2>

                        <p class="maradigma-p-compact">
                            <?php esc_html_e(
                                'Configure global options used by the booking experience on your website.',
                                'maradigma'
                            ); ?>
                        </p>

                        <form method="post" action="options.php">
                            <?php settings_fields('maradigma_settings_group'); ?>

                            <?php
                            $bookingPaymentIntroTexts = self::normalizeLocalizedTextMap($settings['booking_payment_intro_texts'] ?? []);
                            $bookingTextLanguages = self::getBookingSettingsLanguages();
                            ?>
                            <div id="maradigma-section-online-booking" class="maradigma-settings-subsection">
                                <h3><?php esc_html_e('Payment step texts', 'maradigma'); ?></h3>

                                <p class="description" style="margin-bottom:12px;">
                                    <?php esc_html_e('This text is shown above the payment method selector in the booking modal. Leave a language empty to use the fallback language.', 'maradigma'); ?>
                                </p>

                                <table class="form-table" role="presentation">
                                    <tbody>
                                        <?php foreach ($bookingTextLanguages as $language) : ?>
                                            <tr>
                                                <th scope="row">
                                                    <label for="maradigma-booking-payment-intro-<?php echo esc_attr($language); ?>">
                                                        <?php
                                                        printf(
                                                            /* translators: %s: language code. */
                                                            esc_html__('Payment method intro (%s)', 'maradigma'),
                                                            esc_html(strtoupper($language))
                                                        );
                                                        ?>
                                                    </label>
                                                </th>
                                                <td>
                                                    <textarea
                                                        id="maradigma-booking-payment-intro-<?php echo esc_attr($language); ?>"
                                                        name="<?php echo esc_attr(self::OPTION_KEY); ?>[booking_payment_intro_texts][<?php echo esc_attr($language); ?>]"
                                                        rows="3"
                                                        class="large-text"
                                                        style="max-width:720px;"
                                                        placeholder="<?php esc_attr_e('Example: Your reservation is almost ready. Select your secure payment method to continue.', 'maradigma'); ?>"><?php echo esc_textarea((string) ($bookingPaymentIntroTexts[$language] ?? '')); ?></textarea>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <?php submit_button(__('Save changes', 'maradigma')); ?>
                        </form>
                    </div>
                </div>

            <?php elseif ($activeTab === 'seo') : ?>

                <div class="maradigma-settings-grid">
                    <div class="maradigma-card">
                        <h2>
                            <span class="maradigma-dot"></span>
                            <?php esc_html_e('Dynamic SEO (per boat)', 'maradigma'); ?>
                        </h2>

                        <p class="maradigma-p-compact">
                            <?php esc_html_e(
                                'Configure the default language, the base slug for the boats section, and the SEO templates (title/description/H1). Supports simple multi-language format such as: "es:barcos,en:boats,fr:bateaux".',
                                'maradigma'
                            ); ?>
                        </p>

                        <form method="post" action="options.php">
                            <?php settings_fields('maradigma_settings_group'); ?>

                            <table class="form-table" role="presentation">
                                <tbody>

                                    <tr>
                                        <th scope="row">
                                            <label for="maradigma-default-language">
                                                <?php esc_html_e('Default language', 'maradigma'); ?>
                                            </label>
                                        </th>
                                        <td>
                                            <select
                                                id="maradigma-default-language"
                                                name="<?php echo esc_attr(self::OPTION_KEY); ?>[default_language]">
                                                <?php foreach ($languageOptions as $opt) : ?>
                                                    <option value="<?php echo esc_attr($opt['value']); ?>" <?php selected($defaultLang, $opt['value']); ?>>
                                                        <?php echo esc_html($opt['label']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>

                                            <p class="description">
                                                <?php esc_html_e('Used for SEO when the language cannot be detected (or there is no mapping).', 'maradigma'); ?>
                                            </p>
                                        </td>
                                    </tr>

                                    <tr>
                                        <th scope="row">
                                            <label for="maradigma-boats-base-slug">
                                                <?php esc_html_e('Base slug for boats (SEO)', 'maradigma'); ?>
                                            </label>
                                        </th>
                                        <td>
                                            <input
                                                type="text"
                                                id="maradigma-boats-base-slug"
                                                name="<?php echo esc_attr(self::OPTION_KEY); ?>[boats_base_slug]"
                                                value="<?php echo esc_attr($boatsBaseSlug); ?>"
                                                class="regular-text"
                                                style="width:100%;max-width:520px;"
                                                placeholder="boats / alquiler-barcos / es:barcos,en:boats,fr:bateaux" />

                                            <p class="description">
                                                <?php esc_html_e('Examples: "boats" or "alquiler-barcos". Simple multi-language: "es:barcos,en:boats,fr:bateaux".', 'maradigma'); ?>
                                            </p>
                                        </td>
                                    </tr>

                                    <tr>
                                        <th scope="row">
                                            <label for="maradigma-seo-title-template">
                                                <?php esc_html_e('Meta title template', 'maradigma'); ?>
                                            </label>
                                        </th>
                                        <td>
                                            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                                                <input
                                                    type="text"
                                                    id="maradigma-seo-title-template"
                                                    name="<?php echo esc_attr(self::OPTION_KEY); ?>[seo_title_template]"
                                                    value="<?php echo esc_attr($seoTitleTpl); ?>"
                                                    class="regular-text"
                                                    style="width:100%;max-width:820px;flex:1 1 520px;"
                                                    placeholder="Boat hire {{service_name}}" />

                                                <button
                                                    type="button"
                                                    class="button"
                                                    data-maradigma-open-token-picker
                                                    data-target="#maradigma-seo-title-template">
                                                    <?php esc_html_e('Insert variable', 'maradigma'); ?>
                                                </button>
                                            </div>

                                            <p class="description">
                                                <?php esc_html_e('Recommended: "Boat hire {{service_name}} | {{port}}".', 'maradigma'); ?>
                                            </p>
                                        </td>
                                    </tr>

                                    <tr>
                                        <th scope="row">
                                            <label for="maradigma-seo-meta-description-template">
                                                <?php esc_html_e('Meta description template', 'maradigma'); ?>
                                            </label>
                                        </th>
                                        <td>
                                            <div style="display:flex;gap:10px;align-items:flex-start;flex-wrap:wrap;">
                                                <textarea
                                                    id="maradigma-seo-meta-description-template"
                                                    name="<?php echo esc_attr(self::OPTION_KEY); ?>[seo_meta_description_template]"
                                                    rows="4"
                                                    class="large-text"
                                                    style="width:100%;max-width:820px;flex:1 1 520px;"
                                                    placeholder="Rent the {{service_name}} in {{port}}. Capacity {{pax}} people, from {{price_from}}."><?php echo esc_textarea($seoDescTpl); ?></textarea>

                                                <button
                                                    type="button"
                                                    class="button"
                                                    data-maradigma-open-token-picker
                                                    data-target="#maradigma-seo-meta-description-template"
                                                    style="margin-top:2px;">
                                                    <?php esc_html_e('Insert variable', 'maradigma'); ?>
                                                </button>
                                            </div>

                                            <p class="description">
                                                <?php esc_html_e('Recommended: 120-160 characters (Google truncates if you go over).', 'maradigma'); ?>
                                            </p>
                                        </td>
                                    </tr>

                                    <tr>
                                        <th scope="row">
                                            <label for="maradigma-seo-h1-template">
                                                <?php esc_html_e('H1 template (optional)', 'maradigma'); ?>
                                            </label>
                                        </th>
                                        <td>
                                            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                                                <input
                                                    type="text"
                                                    id="maradigma-seo-h1-template"
                                                    name="<?php echo esc_attr(self::OPTION_KEY); ?>[seo_h1_template]"
                                                    value="<?php echo esc_attr($seoH1Tpl); ?>"
                                                    class="regular-text"
                                                    style="width:100%;max-width:820px;flex:1 1 520px;"
                                                    placeholder="{{service_name}}" />

                                                <button
                                                    type="button"
                                                    class="button"
                                                    data-maradigma-open-token-picker
                                                    data-target="#maradigma-seo-h1-template">
                                                    <?php esc_html_e('Insert variable', 'maradigma'); ?>
                                                </button>
                                            </div>

                                            <p class="description">
                                                <?php esc_html_e('If your page template already defines its own H1, leave this empty.', 'maradigma'); ?>
                                            </p>
                                        </td>
                                    </tr>

                                </tbody>
                            </table>

                            <?php submit_button(__('Save changes', 'maradigma')); ?>
                        </form>

                        <div
                            id="maradigma-token-modal"
                            style="display:none;position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,.45);">
                            <div
                                style="position:absolute;left:50%;top:90px;transform:translateX(-50%);width:96%;max-width:560px;background:#fff;border-radius:10px;box-shadow:0 10px 30px rgba(0,0,0,.25);overflow:hidden;">
                                <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid #e5e5e5;">
                                    <strong style="font-size:14px;">
                                        <?php esc_html_e('Insert variable', 'maradigma'); ?>
                                    </strong>
                                    <button type="button" class="button" id="maradigma-token-modal-close">
                                        <?php esc_html_e('Close', 'maradigma'); ?>
                                    </button>
                                </div>

                                <div style="padding:16px;">
                                    <p class="description" style="margin:0 0 10px 0;">
                                            <option value=""><?php esc_html_e('Select a variable...', 'maradigma'); ?></option>
                                    </p>

                                    <div style="display:flex;gap:10px;align-items:center;">
                                        <select id="maradigma-seo-token-select" style="width:100%;max-width:100%;">
                                            <option value=""><?php esc_html_e('Select a variable...', 'maradigma'); ?></option>
                                            <?php foreach ($placeholders as $value => $label) : ?>
                                                <option value="<?php echo esc_attr($value); ?>">
                                                    <?php echo esc_html($label . ' - ' . $value); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>

                                        <button type="button" class="button button-primary" id="maradigma-token-modal-insert">
                                            <?php esc_html_e('Insert', 'maradigma'); ?>
                                        </button>
                                    </div>

                                    <div style="margin-top:12px;">
                                        <small class="description">
                                            <?php esc_html_e('Tip: insert multiple variables in a row without closing the modal.', 'maradigma'); ?>
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="maradigma-help-callout maradigma-mt-12">
                            <strong><?php esc_html_e('SEO Notes:', 'maradigma'); ?></strong>
                            <ul style="margin:8px 0 0 18px;">
                                <li><?php esc_html_e('The base slug is used to build URLs like /boats/{boat-slug}/ (or /barcos/... depending on language).', 'maradigma'); ?></li>
                                <li><?php esc_html_e('The simple multi-language format is intentionally straightforward: "es:barcos,en:boats".', 'maradigma'); ?></li>
                            </ul>
                        </div>
                    </div>

                    <div class="maradigma-card">
                        <h2>
                            <span class="maradigma-dot"></span>
                            <?php esc_html_e('Quick examples', 'maradigma'); ?>
                        </h2>

                        <ul class="maradigma-feature-list">
                            <li><strong><?php esc_html_e('Default language:', 'maradigma'); ?></strong> EN</li>
                            <li><strong><?php esc_html_e('Base slug:', 'maradigma'); ?></strong> <code>boats</code></li>
                            <li><strong><?php esc_html_e('Multi-language base slug:', 'maradigma'); ?></strong> <code>es:barcos,en:boats,fr:bateaux</code></li>
                        </ul>

                        <p class="maradigma-p-compact">
                            <?php esc_html_e(
                                'If you do not use multi-language, a single slug is enough. If you do, the system will choose the slug based on the language (otherwise it falls back to the default language).',
                                'maradigma'
                            ); ?>
                        </p>
                    </div>
                </div>

            <?php elseif ($activeTab === 'cards') : ?>

                <?php
                $cards     = \Maradigma\BoatCardRepository::listCards();
                $defaultId = \Maradigma\BoatCardRepository::getDefaultCardId();

                // Read-only editor selection; mutations use their own nonce-protected actions.
                // phpcs:disable WordPress.Security.NonceVerification.Recommended
                $editIdRaw = isset($_GET['edit_card'])
                    ? sanitize_text_field((string) wp_unslash($_GET['edit_card']))
                    : '';
                // phpcs:enable WordPress.Security.NonceVerification.Recommended
                $editIdRaw = trim($editIdRaw);

                $isEditing = ($editIdRaw !== '');
                $isNew     = ($editIdRaw === 'new');

                $editCard = null;
                if ($isNew) {
                    $defaultCard = \Maradigma\BoatCardRepository::getCard(\Maradigma\BoatCardRepository::DEFAULT_CARD_ID);
                    $editCard = [
                        'id'       => '',
                        'name'     => '',
                        'template' => (string) ($defaultCard['template'] ?? ''),
                    ];
                } elseif ($editIdRaw !== '') {
                    $editCard = \Maradigma\BoatCardRepository::getCard($editIdRaw);
                }

                $placeholders = \Maradigma\BoatCardEngine::getPlaceholdersLabels();
                foreach (\Maradigma\BoatCardEngine::listTokens() as $token) {
                    $key = '{{' . $token . '}}';
                    if (!isset($placeholders[$key])) {
                        $placeholders[$key] = $token;
                    }
                }

                uksort($placeholders, static function (string $a, string $b) use ($placeholders): int {
                    $aHuman = $placeholders[$a] !== trim($a, '{}');
                    $bHuman = $placeholders[$b] !== trim($b, '{}');

                    if ($aHuman !== $bHuman) {
                        return $aHuman ? -1 : 1;
                    }

                    return strcasecmp($a, $b);
                });

                $cardsListUrl = esc_url(add_query_arg(['tab' => 'cards'], $pageUrl));

                // The notice parameter only selects a read-only admin message.
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                $noticeCards = isset($_GET['notice']) ? sanitize_key((string) wp_unslash($_GET['notice'])) : '';
                $noticeHtml = '';

                if ($noticeCards === 'saved') {
                    $noticeHtml = '<div class="notice notice-success inline"><p><strong>' . esc_html__('Boat card saved.', 'maradigma') . '</strong></p></div>';
                } elseif ($noticeCards === 'deleted') {
                    $noticeHtml = '<div class="notice notice-success inline"><p><strong>' . esc_html__('Boat card deleted.', 'maradigma') . '</strong></p></div>';
                } elseif ($noticeCards === 'default') {
                    $noticeHtml = '<div class="notice notice-success inline"><p><strong>' . esc_html__('Default boat card updated.', 'maradigma') . '</strong></p></div>';
                } elseif ($noticeCards === 'restored') {
                    $noticeHtml = '<div class="notice notice-success inline"><p><strong>' . esc_html__('Default boat card template restored.', 'maradigma') . '</strong></p></div>';
                } elseif ($noticeCards === 'invalid') {
                    $noticeHtml = '<div class="notice notice-error inline"><p><strong>' . esc_html__('Card ID and Template HTML are required.', 'maradigma') . '</strong></p></div>';
                }
                ?>

                <?php if (!$isEditing) : ?>
                    <div class="maradigma-card maradigma-mt-18">
                        <h2>
                            <span class="maradigma-dot"></span>
                            <?php esc_html_e('Boat cards', 'maradigma'); ?>
                        </h2>

                        <?php echo $noticeHtml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped 
                        ?>

                        <p class="maradigma-p-compact">
                            <?php esc_html_e(
                                'Create different HTML templates for the boat cards in the list. Select which one to use with the card="" attribute in the shortcode.',
                                'maradigma'
                            ); ?>
                        </p>

                        <p>
                            <a class="button button-primary"
                                href="<?php echo esc_url(add_query_arg(['tab' => 'cards', 'edit_card' => 'new'], $pageUrl)); ?>">
                                <?php esc_html_e('Add new', 'maradigma'); ?>
                            </a>
                        </p>

                        <table class="widefat striped">
                            <thead>
                                <tr>
                                    <th style="width:18%"><?php esc_html_e('ID', 'maradigma'); ?></th>
                                    <th><?php esc_html_e('Name', 'maradigma'); ?></th>
                                    <th style="width:12%"><?php esc_html_e('Default', 'maradigma'); ?></th>
                                    <th style="width:28%"><?php esc_html_e('Actions', 'maradigma'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($cards)) : ?>
                                    <tr>
                                        <td colspan="4"><?php esc_html_e('No cards yet.', 'maradigma'); ?></td>
                                    </tr>
                                <?php else : ?>
                                    <?php foreach ($cards as $c) : ?>
                                        <tr>
                                            <td><code><?php echo esc_html($c['id']); ?></code></td>
                                            <td><?php echo esc_html((string) $c['name']); ?></td>
                                            <td><?php echo $c['id'] === $defaultId ? '<strong>Yes</strong>' : '-'; ?></td>
                                            <td>
                                                <a class="button"
                                                    href="<?php echo esc_url(add_query_arg(['tab' => 'cards', 'edit_card' => $c['id']], $pageUrl)); ?>">
                                                    <?php esc_html_e('Edit', 'maradigma'); ?>
                                                </a>

                                                <?php if ($c['id'] !== $defaultId) : ?>
                                                    <form method="post" action="<?php echo esc_url($adminPostUrl); ?>" style="display:inline-block;margin-left:6px;">
                                                        <?php wp_nonce_field('maradigma_boat_cards_action', 'maradigma_nonce'); ?>
                                                        <input type="hidden" name="action" value="maradigma_boat_cards">
                                                        <input type="hidden" name="maradigma_action" value="set_default">
                                                        <input type="hidden" name="card_id" value="<?php echo esc_attr($c['id']); ?>">
                                                        <button type="submit" class="button">
                                                            <?php esc_html_e('Set default', 'maradigma'); ?>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>

                                                <?php if ($c['id'] !== \Maradigma\BoatCardRepository::DEFAULT_CARD_ID) : ?>
                                                    <form method="post" action="<?php echo esc_url($adminPostUrl); ?>" style="display:inline-block;margin-left:6px;"
                                                        onsubmit="return confirm('Delete this card?');">
                                                        <?php wp_nonce_field('maradigma_boat_cards_action', 'maradigma_nonce'); ?>
                                                        <input type="hidden" name="action" value="maradigma_boat_cards">
                                                        <input type="hidden" name="maradigma_action" value="delete_card">
                                                        <input type="hidden" name="card_id" value="<?php echo esc_attr($c['id']); ?>">
                                                        <button type="submit" class="button button-link-delete">
                                                            <?php esc_html_e('Delete', 'maradigma'); ?>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>

                        <div class="maradigma-help-callout maradigma-mt-12">
                            <strong><?php esc_html_e('Use:', 'maradigma'); ?></strong>
                            <pre class="maradigma-pre"><code>[maradigma_boats card="<?php echo esc_html($defaultId); ?>"]</code></pre>
                        </div>
                    </div>

                <?php else : ?>

                    <div class="maradigma-card maradigma-mt-18">
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
                            <h2 style="margin:0;">
                                <span class="maradigma-dot"></span>
                                <?php echo $isNew ? esc_html__('New boat card', 'maradigma') : esc_html__('Edit boat card', 'maradigma'); ?>
                            </h2>

                            <a class="button" href="<?php echo esc_url($cardsListUrl); ?>">
                                &larr; <?php esc_html_e('Back to list', 'maradigma'); ?>
                            </a>
                        </div>

                        <?php echo $noticeHtml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped 
                        ?>

                        <?php if ($editCard === null) : ?>
                            <p class="maradigma-muted">
                                <?php esc_html_e('Card not found.', 'maradigma'); ?>
                            </p>
                        <?php else : ?>

                            <form method="post" action="<?php echo esc_url($adminPostUrl); ?>" style="margin-top:14px;">
                                <?php wp_nonce_field('maradigma_boat_cards_action', 'maradigma_nonce'); ?>
                                <input type="hidden" name="action" value="maradigma_boat_cards">
                                <input type="hidden" name="maradigma_action" value="save_card">

                                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                                    <div>
                                        <label><strong><?php esc_html_e('Card ID', 'maradigma'); ?></strong></label><br>
                                        <input type="text" name="card_id" class="regular-text" style="width:100%;"
                                            value="<?php echo esc_attr((string) ($editCard['id'] ?? '')); ?>"
                                            placeholder="e.g. compact, luxury, grid_2">
                                        <div class="description" style="margin-top:6px;">
                                            <?php esc_html_e('Stable ID. Avoid spaces. Example: compact, luxury, grid_2.', 'maradigma'); ?>
                                        </div>
                                    </div>

                                    <div>
                                        <label><strong><?php esc_html_e('Name', 'maradigma'); ?></strong></label><br>
                                        <input type="text" name="card_name" class="regular-text" style="width:100%;"
                                            value="<?php echo esc_attr((string) ($editCard['name'] ?? '')); ?>"
                                            placeholder="e.g. Compact card">
                                    </div>
                                </div>

                                <div style="margin-top:14px;">
                                    <label><strong><?php esc_html_e('Template HTML', 'maradigma'); ?></strong></label><br>

                                    <div class="maradigma-card-editor-tools" style="display:flex;gap:10px;align-items:center;margin-top:10px;">
                                        <label for="maradigma-placeholder-select" style="font-weight:600;margin:0;">
                                            <?php esc_html_e('Insert variable', 'maradigma'); ?>
                                        </label>

                                        <select id="maradigma-placeholder-select" style="min-width:360px;">
                                            <option value=""><?php esc_html_e('Select a variable...', 'maradigma'); ?></option>
                                            <?php foreach ($placeholders as $value => $label) : ?>
                                                <option value="<?php echo esc_attr($value); ?>">
                                                    <?php echo esc_html($label . ' - ' . $value); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>

                                        <button type="button" class="button" id="maradigma-insert-placeholder-btn">
                                            <?php esc_html_e('Insert', 'maradigma'); ?>
                                        </button>

                                        <button type="button" class="button" id="maradigma-refresh-preview-btn" style="margin-left:auto;">
                                            <?php esc_html_e('Refresh preview', 'maradigma'); ?>
                                        </button>
                                    </div>

                                    <textarea
                                        id="maradigma-card-template"
                                        name="card_template"
                                        rows="22"
                                        class="large-text code"
                                        style="min-height:520px;"
                                        placeholder="<article>... {{name}}"><?php echo esc_textarea((string) ($editCard['template'] ?? '')); ?></textarea>

                                    <div class="maradigma-card-preview" style="margin-top:12px;">
                                        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;">
                                            <strong><?php esc_html_e('Preview', 'maradigma'); ?></strong>
                                        </div>

                                        <iframe id="maradigma-card-preview-iframe"
                                            src="about:blank"
                                            sandbox="allow-same-origin"
                                            style="width:100%;min-height:520px;border:1px solid #e5e5e5;border-radius:6px;background:#fff;"></iframe>
                                    </div>
                                </div>

                                <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:14px;">
                                    <label style="display:flex;align-items:center;gap:8px;">
                                        <input type="checkbox" name="set_default" value="1"
                                            <?php checked(((string) ($editCard['id'] ?? '')) === $defaultId || ($isNew && $defaultId === \Maradigma\BoatCardRepository::DEFAULT_CARD_ID)); ?>>
                                        <span><?php esc_html_e('Set as default', 'maradigma'); ?></span>
                                    </label>

                                    <div style="display:flex;gap:8px;align-items:center;">
                                        <?php submit_button(__('Save card', 'maradigma'), 'primary', 'submit', false); ?>
                                    </div>
                                </div>
                            </form>

                            <?php if (!$isNew && ((string) ($editCard['id'] ?? '') === \Maradigma\BoatCardRepository::DEFAULT_CARD_ID)) : ?>
                                <form method="post" action="<?php echo esc_url($adminPostUrl); ?>" style="margin-top:10px;"
                                    onsubmit="return confirm('<?php echo esc_js(__('This will replace the current default card HTML with the plugin default template. Continue?', 'maradigma')); ?>');">
                                    <?php wp_nonce_field('maradigma_boat_cards_action', 'maradigma_nonce'); ?>
                                    <input type="hidden" name="action" value="maradigma_boat_cards">
                                    <input type="hidden" name="maradigma_action" value="restore_default_card">
                                    <button type="submit" class="button">
                                        <?php esc_html_e('Restore default template', 'maradigma'); ?>
                                    </button>
                                </form>
                            <?php endif; ?>

                            <?php if (!$isNew && ((string) ($editCard['id'] ?? '') !== \Maradigma\BoatCardRepository::DEFAULT_CARD_ID)) : ?>
                                <form method="post" action="<?php echo esc_url($adminPostUrl); ?>" style="margin-top:10px;"
                                    onsubmit="return confirm('Delete this card?');">
                                    <?php wp_nonce_field('maradigma_boat_cards_action', 'maradigma_nonce'); ?>
                                    <input type="hidden" name="action" value="maradigma_boat_cards">
                                    <input type="hidden" name="maradigma_action" value="delete_card">
                                    <input type="hidden" name="card_id" value="<?php echo esc_attr((string) ($editCard['id'] ?? '')); ?>">
                                    <button type="submit" class="button button-link-delete">
                                        <?php esc_html_e('Delete', 'maradigma'); ?>
                                    </button>
                                </form>
                            <?php endif; ?>

                        <?php endif; ?>
                    </div>
                <?php endif; ?>

            <?php elseif ($activeTab === 'advanced') : ?>

                <div class="maradigma-card maradigma-mt-18">
                    <h2>
                        <span class="maradigma-dot"></span>
                        <?php esc_html_e('Advanced settings', 'maradigma'); ?>
                    </h2>

                    <p class="maradigma-p-compact">
                        <?php esc_html_e(
                            'This section helps diagnose multilingual and translation issues, including locale detection, textdomain loading and gettext results.',
                            'maradigma'
                        ); ?>
                    </p>
                </div>

                <div class="maradigma-card" style="margin-top:18px;">
                    <h2>
                        <span class="maradigma-dot"></span>
                        <?php esc_html_e('Translation diagnostics', 'maradigma'); ?>
                    </h2>

                    <table class="widefat striped" style="max-width:900px;">
                        <tbody>
                            <tr>
                                <th style="width:240px;"><?php esc_html_e('WordPress locale', 'maradigma'); ?></th>
                                <td><code><?php echo esc_html($wpLocale); ?></code></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Current language', 'maradigma'); ?></th>
                                <td><code><?php echo esc_html($currentLanguage !== '' ? $currentLanguage : '(empty)'); ?></code></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Default language', 'maradigma'); ?></th>
                                <td><code><?php echo esc_html($defaultLanguage !== '' ? $defaultLanguage : '(empty)'); ?></code></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Multilang provider', 'maradigma'); ?></th>
                                <td><code><?php echo esc_html($provider !== '' ? $provider : '(none)'); ?></code></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Textdomain loaded', 'maradigma'); ?></th>
                                <td><strong><?php echo esc_html($textdomainLoaded); ?></strong></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Expected MO file', 'maradigma'); ?></th>
                                <td><code><?php echo esc_html($expectedMo); ?></code></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Expected MO exists', 'maradigma'); ?></th>
                                <td><strong><?php echo esc_html($expectedMoExists); ?></strong></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Expected PO file', 'maradigma'); ?></th>
                                <td><code><?php echo esc_html($expectedPo); ?></code></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Expected PO exists', 'maradigma'); ?></th>
                                <td><strong><?php echo esc_html($expectedPoExists); ?></strong></td>
                            </tr>
                        </tbody>
                    </table>

                    <h3 style="margin-top:16px;"><?php esc_html_e('Gettext probes', 'maradigma'); ?></h3>

                    <table class="widefat striped" style="max-width:900px;">
                        <thead>
                            <tr>
                                <th style="width:260px;"><?php esc_html_e('Original string', 'maradigma'); ?></th>
                                <th><?php esc_html_e('Translation returned by WordPress', 'maradigma'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($probes as $original => $translated) : ?>
                                <tr>
                                    <td><code><?php echo esc_html($original); ?></code></td>
                                    <td><code><?php echo esc_html($translated); ?></code></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php
                $knownLogChannels = [
                    'booking-online'        => __('Booking online', 'maradigma'),
                    'external-api'          => __('External API', 'maradigma'),
                    'frontend-errors'       => __('Frontend errors', 'maradigma'),
                    'booking-service-price' => __('Booking price', 'maradigma'),
                    'boat-images-sync'      => __('Boat images sync', 'maradigma'),
                ];

                $availableLogFiles = \Maradigma\Support\Debugger::listFiles();
                foreach ($availableLogFiles as $availableLogFile) {
                    $availableLogName = isset($availableLogFile['name']) ? (string) $availableLogFile['name'] : '';
                    if ($availableLogName === '' || !str_ends_with($availableLogName, '.log')) {
                        continue;
                    }

                    $availableLogChannel = sanitize_key(substr($availableLogName, 0, -4));
                    if ($availableLogChannel !== '' && !isset($knownLogChannels[$availableLogChannel])) {
                        $knownLogChannels[$availableLogChannel] = $availableLogChannel;
                    }
                }

                // The channel parameter only selects an existing read-only diagnostic log.
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                $requestedLogChannel = isset($_GET['log_channel']) ? sanitize_key((string) wp_unslash($_GET['log_channel'])) : '';
                $logChannel = isset($knownLogChannels[$requestedLogChannel]) ? $requestedLogChannel : 'booking-online';
                $logExists  = \Maradigma\Support\Debugger::exists($logChannel);
                $logSize    = \Maradigma\Support\Debugger::getSize($logChannel);
                $logTail    = \Maradigma\Support\Debugger::readTailBytes($logChannel, 80000);
                ?>

                <div class="maradigma-card" style="margin-top:18px;">
                    <h2>
                        <span class="maradigma-dot"></span>
                        <?php esc_html_e('Debug logs', 'maradigma'); ?>
                    </h2>

                    <p class="description">
                        <?php esc_html_e('Here you can inspect the latest plugin logs generated by the image sync and debugging tools.', 'maradigma'); ?>
                    </p>

                    <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin:12px 0;">
                        <input type="hidden" name="page" value="maradigma-settings">
                        <input type="hidden" name="tab" value="advanced">
                        <label for="maradigma-log-channel">
                            <strong><?php esc_html_e('Log channel', 'maradigma'); ?></strong><br>
                            <select id="maradigma-log-channel" name="log_channel">
                                <?php foreach ($knownLogChannels as $channel => $label) : ?>
                                    <option value="<?php echo esc_attr($channel); ?>" <?php selected($logChannel, $channel); ?>>
                                        <?php echo esc_html($label); ?> (<?php echo esc_html($channel); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <button type="submit" class="button">
                            <?php esc_html_e('View log', 'maradigma'); ?>
                        </button>
                    </form>

                    <div style="display:flex;gap:18px;flex-wrap:wrap;margin:10px 0;">
                        <div>
                            <strong><?php esc_html_e('Channel', 'maradigma'); ?></strong><br>
                            <code><?php echo esc_html($logChannel); ?></code>
                        </div>
                        <div>
                            <strong><?php esc_html_e('File exists', 'maradigma'); ?></strong><br>
                            <code><?php echo $logExists ? 'YES' : 'NO'; ?></code>
                        </div>
                        <div>
                            <strong><?php esc_html_e('Size', 'maradigma'); ?></strong><br>
                            <code><?php echo esc_html((string) size_format($logSize)); ?></code>
                        </div>
                        <div>
                            <strong><?php esc_html_e('Path', 'maradigma'); ?></strong><br>
                            <code><?php echo esc_html(\Maradigma\Support\Debugger::getLogFilePath($logChannel)); ?></code>
                        </div>
                    </div>

                    <div style="display:flex;gap:10px;flex-wrap:wrap;margin:12px 0;">
                        <a class="button" href="<?php echo esc_url(add_query_arg([
                                                    'page' => 'maradigma-settings',
                                                    'tab'  => 'advanced',
                                                    'log_channel' => $logChannel,
                                                ], admin_url('admin.php'))); ?>">
                            <?php esc_html_e('Refresh log view', 'maradigma'); ?>
                        </a>

                        <form method="get" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
                            <?php wp_nonce_field('maradigma_log_action', 'maradigma_log_nonce'); ?>
                            <input type="hidden" name="action" value="maradigma_download_log">
                            <input type="hidden" name="channel" value="<?php echo esc_attr($logChannel); ?>">
                            <button type="submit" class="button button-primary">
                                <?php esc_html_e('Download log', 'maradigma'); ?>
                            </button>
                        </form>

                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;"
                            onsubmit="return confirm('<?php echo esc_js(__('This will clear the selected log file. Continue?', 'maradigma')); ?>');">
                            <?php wp_nonce_field('maradigma_log_action', 'maradigma_log_nonce'); ?>
                            <input type="hidden" name="action" value="maradigma_clear_log">
                            <input type="hidden" name="channel" value="<?php echo esc_attr($logChannel); ?>">
                            <button type="submit" class="button button-secondary">
                                <?php esc_html_e('Clear log', 'maradigma'); ?>
                            </button>
                        </form>
                    </div>

                    <textarea
                        readonly
                        spellcheck="false"
                        style="width:100%;min-height:420px;font-family:monospace;font-size:12px;line-height:1.45;background:#111;color:#eaeaea;padding:14px;border-radius:8px;"><?php echo esc_textarea($logTail !== '' ? $logTail : 'No log content available yet.'); ?></textarea>

                    <p class="description" style="margin-top:8px;">
                        <?php esc_html_e('Showing the last part of the log file for performance reasons.', 'maradigma'); ?>
                    </p>
                </div>
            <?php elseif ($activeTab === 'about') : ?>

                <div class="maradigma-card maradigma-mt-18">
                    <h2>
                        <span class="maradigma-dot"></span>
                        <?php esc_html_e('About Maradigma', 'maradigma'); ?>
                    </h2>

                    <p>
                        <?php esc_html_e(
                            'Maradigma is a platform specialized in managing and marketing boat charters. We help charter companies centralize bookings, availability, and payments in one place.',
                            'maradigma'
                        ); ?>
                    </p>

                    <h3><?php esc_html_e('What you can do with this plugin', 'maradigma'); ?></h3>
                    <ul class="maradigma-feature-list">
                        <li><?php esc_html_e('Show your boat fleet directly on your WordPress website.', 'maradigma'); ?></li>
                        <li><?php esc_html_e('Allow your customers to send online booking requests.', 'maradigma'); ?></li>
                        <li><?php esc_html_e('Keep your website always synced with Maradigma information.', 'maradigma'); ?></li>
                    </ul>

                    <h3><?php esc_html_e('Who is it for?', 'maradigma'); ?></h3>
                    <ul class="maradigma-feature-list">
                        <li><?php esc_html_e('Boat charter companies already working with Maradigma.', 'maradigma'); ?></li>
                        <li><?php esc_html_e('Agencies managing multiple brands or fleets.', 'maradigma'); ?></li>
                        <li><?php esc_html_e('Projects that need a quick integration without custom development.', 'maradigma'); ?></li>
                    </ul>

                    <div class="maradigma-cta-buttons">
                        <a href="https://maradigma.com" target="_blank" rel="noopener" class="maradigma-cta-primary">
                            <?php esc_html_e('Learn more about Maradigma', 'maradigma'); ?>
                        </a>
                        <a href="https://maradigma.com/contacto" target="_blank" rel="noopener" class="maradigma-cta-secondary">
                            <?php esc_html_e('Talk to our team', 'maradigma'); ?>
                        </a>
                    </div>
                </div>

            <?php elseif ($activeTab === 'help') : ?>
                <?php SettingsHelpTab::render($pageUrl); ?>
            <?php endif; ?>

        </div>
    <?php
    }

    /**
     * Returns eligible boat binding post types.
     */
    public static function getEligibleBoatBindingPostTypes(): array
    {
        $objects = get_post_types(
            [
                'public'  => true,
                'show_ui' => true,
            ],
            'objects'
        );

        if (!is_array($objects)) {
            return [];
        }

        $excluded = [
            'attachment',
            'revision',
            'nav_menu_item',
            'custom_css',
            'customize_changeset',
            'oembed_cache',
            'user_request',
            'wp_block',
            'wp_template',
            'wp_template_part',
            'wp_navigation',
            'elementor_library',
            'acf-field',
            'acf-field-group',
            \Maradigma\BoatPostType::POST_TYPE,
        ];

        $postTypes = [];

        foreach ($objects as $postType => $object) {
            if (!is_string($postType) || $postType === '') {
                continue;
            }

            if (in_array($postType, $excluded, true)) {
                continue;
            }

            // Excluir CPTs técnicos de Elementor como e-floating-buttons
            if (str_starts_with($postType, 'e-')) {
                continue;
            }

            if (!$object instanceof \WP_Post_Type) {
                continue;
            }

            if (empty($object->show_ui)) {
                continue;
            }

            $postTypes[$postType] = [
                'name'  => $postType,
                'label' => (string) ($object->labels->singular_name ?? $object->label ?? $postType),
            ];
        }

        $postTypes = apply_filters('maradigma_eligible_boat_binding_post_types', $postTypes, $objects);
        if (!is_array($postTypes)) {
            $postTypes = [];
        }

        ksort($postTypes);

        return $postTypes;
    }

    /**
     * Returns enabled boat binding post types.
     */
    public static function getEnabledBoatBindingPostTypes(): array
    {
        $settings = self::getSettings();

        $saved = $settings['boat_binding_post_types'] ?? [];
        if (!is_array($saved)) {
            $saved = [];
        }

        $saved = array_map(
            static fn($postType): string => sanitize_key((string) $postType),
            $saved
        );

        $saved = array_filter(
            $saved,
            static fn(string $postType): bool => $postType !== ''
        );

        $eligible = array_keys(self::getEligibleBoatBindingPostTypes());
        $enabled = array_values(array_intersect($saved, $eligible));

        $enabled = apply_filters('maradigma_enabled_boat_binding_post_types', $enabled, $eligible, $settings);
        if (!is_array($enabled)) {
            return [];
        }

        $enabled = array_map(
            static fn($postType): string => sanitize_key((string) $postType),
            $enabled
        );

        return array_values(array_unique(array_intersect($enabled, $eligible)));
    }

    /**
     * Handles images sync start post.
     */
    public static function handleImagesSyncStartPost(): void
    {
        SettingsImageSyncActions::start();
    }

    /**
     * Handles images sync stop post.
     */
    public static function handleImagesSyncStopPost(): void
    {
        SettingsImageSyncActions::stop();
    }

    /**
     * Image cache reset entry point kept for backward compatibility.
     */
    public static function handleImagesSyncResetPost(): void
    {
        SettingsImageSyncActions::reset();
    }

    /**
     * Handles boat cards post.
     */
    public static function handleBoatCardsPost(): void
    {
        BoatCardsActions::handle();
    }

    /**
     * Handles delete all boats post.
     */
    public static function handleDeleteAllBoatsPost(): void
    {
        SettingsSyncActions::deleteAllBoats();
    }

    /**
     * Handles open Gutenberg template post.
     */
    public static function handleOpenGutenbergTemplatePost(): void
    {
        SettingsTemplateActions::openGutenbergTemplate();
    }

    /**
     * Restores the editable Gutenberg master template to the plugin default layout.
     */
    public static function handleResetGutenbergTemplatePost(): void
    {
        SettingsTemplateActions::resetGutenbergTemplate();
    }

    /**
     * Handles force template sync post.
     */
    public static function handleForceTemplateSyncPost(): void
    {
        SettingsTemplateActions::startTemplateSync();
    }

    /**
     * Handles force template sync stop post.
     */
    public static function handleForceTemplateSyncStopPost(): void
    {
        SettingsTemplateActions::stopTemplateSync();
    }

    /**
     * Renders the external API base URL settings field.
     */
    public static function fieldApiBaseUrl(): void
    {
        $settings = self::getSettings();
        $baseUrl  = (string) ($settings['external_api_base_url'] ?? '');

        echo '<code>' . esc_html($baseUrl) . '</code>';
    ?>
        <input type="hidden"
            name="<?php echo esc_attr(self::OPTION_KEY); ?>[external_api_base_url]"
            value="<?php echo esc_attr($baseUrl); ?>" />
    <?php
    }

    /**
     * Renders the public API key settings field.
     */
    public static function fieldApiKey(): void
    {
        $settings = self::getSettings();
    ?>
        <input type="text"
            name="<?php echo esc_attr(self::OPTION_KEY); ?>[external_api_key]"
            value="<?php echo esc_attr((string) ($settings['external_api_key'] ?? '')); ?>"
            class="regular-text" />
    <?php
    }

    /**
     * Renders the default language settings field.
     */
    public static function fieldDefaultLanguage(): void
    {
        $settings = self::getSettings();
    ?>
        <input type="text"
            name="<?php echo esc_attr(self::OPTION_KEY); ?>[default_language]"
            value="<?php echo esc_attr((string) ($settings['default_language'] ?? '')); ?>"
            class="small-text" /> (EN, ES, ...)
    <?php
    }

    /**
     * Renders the boat archive base slug settings field.
     */
    public static function fieldBoatsBaseSlug(): void
    {
        $settings = self::getSettings();
        $value   = (string) ($settings['boats_base_slug'] ?? 'boats');
    ?>
        <input type="text"
            name="<?php echo esc_attr(self::OPTION_KEY); ?>[boats_base_slug]"
            value="<?php echo esc_attr($value); ?>"
            class="regular-text" />
        <p class="description">
            <?php esc_html_e('E.g.: "boats" or "alquiler-barcos". Simple multi-language: "es:barcos,en:boats,fr:bateaux".', 'maradigma'); ?>
        </p>
<?php
    }

    /**
     * Returns boats base slug for current locale.
     */
    public static function getBoatsBaseSlugForCurrentLocale(): string
    {
        $settings = self::getSettings();
        $raw = trim((string)($settings['boats_base_slug'] ?? 'boats'));

        if ($raw === '') {
            return 'boats';
        }

        if (strpos($raw, ':') === false) {
            return trim($raw, '/');
        }

        $map = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part === '' || strpos($part, ':') === false) {
                continue;
            }
            [$k, $v] = array_map('trim', explode(':', $part, 2));
            $k = strtolower($k);
            $v = trim($v, '/');
            if ($k !== '' && $v !== '') {
                $map[$k] = $v;
            }
        }

        if (empty($map)) {
            return trim($raw, '/');
        }

        $locale = (string) get_locale();
        $short  = strtolower(substr($locale, 0, 2));

        $first = (string) reset($map);
        return $map[$short] ?? ($first !== '' ? $first : 'boats');
    }

    /**
     * Selects localized value.
     */
    public static function pickLocalizedValue(string $raw, string $lang, string $fallbackLang = 'en'): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        // If not multilingual syntax, return as-is
        if (strpos($raw, ':') === false) {
            return $raw;
        }

        $map = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part === '' || strpos($part, ':') === false) {
                continue;
            }

            [$k, $v] = array_map('trim', explode(':', $part, 2));
            $k = strtolower($k);
            $v = trim($v);

            if ($k !== '' && $v !== '') {
                $map[$k] = $v;
            }
        }

        if (empty($map)) {
            return $raw;
        }

        $lang = strtolower(trim($lang));
        $fallbackLang = strtolower(trim($fallbackLang));

        // Prefer exact lang, else fallback, else first
        if (isset($map[$lang])) {
            return $map[$lang];
        }
        if ($fallbackLang !== '' && isset($map[$fallbackLang])) {
            return $map[$fallbackLang];
        }

        $first = (string) reset($map);
        return $first !== '' ? $first : $raw;
    }

    /**
     * Returns booking payment intro text.
     */
    public static function getBookingPaymentIntroText(?string $language = null): string
    {
        $settings = self::getSettings();
        $texts = self::normalizeLocalizedTextMap($settings['booking_payment_intro_texts'] ?? []);

        if ($texts === []) {
            return '';
        }

        $currentLanguage = self::normalizeLanguageCode((string) ($language ?? MultilangAdapter::getCurrentLanguage()));
        $defaultLanguage = self::normalizeLanguageCode(MultilangAdapter::getDefaultLanguage());
        $settingsLanguage = self::normalizeLanguageCode((string) ($settings['default_language'] ?? ''));
        $localeLanguage = self::normalizeLanguageCode((string) get_locale());

        $candidates = array_values(array_unique(array_filter([
            $currentLanguage,
            $defaultLanguage,
            $settingsLanguage,
            $localeLanguage,
        ])));

        foreach ($candidates as $candidate) {
            if (isset($texts[$candidate]) && trim((string) $texts[$candidate]) !== '') {
                return (string) $texts[$candidate];
            }
        }

        foreach ($texts as $text) {
            $text = trim((string) $text);
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    /**
     * @return list<string>
     */
    private static function getBookingSettingsLanguages(): array
    {
        $languages = [];

        foreach (MultilangAdapter::getActiveLanguages() as $language) {
            $language = self::normalizeLanguageCode((string) $language);
            if ($language !== '') {
                $languages[] = $language;
            }
        }

        foreach ([MultilangAdapter::getDefaultLanguage(), (string) get_locale(), 'es', 'en', 'ca', 'de'] as $language) {
            $language = self::normalizeLanguageCode((string) $language);
            if ($language !== '') {
                $languages[] = $language;
            }
        }

        return array_values(array_unique($languages));
    }

    /**
     * Normalizes language code.
     */
    private static function normalizeLanguageCode(string $language): string
    {
        $language = strtolower(trim(str_replace('_', '-', $language)));
        if ($language === '') {
            return '';
        }

        $parts = explode('-', $language);
        $language = strtolower(trim((string) ($parts[0] ?? $language)));

        return preg_replace('/[^a-z0-9]/', '', $language) ?: '';
    }

    /**
     * @param mixed $value
     * @return array<string,string>
     */
    private static function normalizeLocalizedTextMap($value): array
    {
        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                return [];
            }

            $fallbackLanguage = self::normalizeLanguageCode(MultilangAdapter::getDefaultLanguage()) ?: 'es';
            return [$fallbackLanguage => $value];
        }

        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $language => $text) {
            $language = self::normalizeLanguageCode((string) $language);
            if ($language === '') {
                continue;
            }

            $out[$language] = trim((string) $text);
        }

        return $out;
    }

    /**
     * Translates sync status.
     */
    private static function translateSyncStatus(string $status): string
    {
        switch (strtolower(trim($status))) {
            case 'running':
                return __('running', 'maradigma');
            case 'done':
                return __('done', 'maradigma');
            case 'stopped':
                return __('stopped', 'maradigma');
            case 'error':
                return __('error', 'maradigma');
            case 'idle':
                return __('idle', 'maradigma');
            default:
                return $status;
        }
    }

    /**
     * Translates sync message.
     */
    private static function translateSyncMessage(string $message): string
    {
        switch (trim($message)) {
            case 'Waiting for another image sync worker to finish':
                return __('Waiting for another image sync worker to finish', 'maradigma');
            case 'Tick paused by soft time budget during image processing':
                return __('Tick paused by soft time budget during image processing', 'maradigma');
            case 'All boats processed.':
                return __('All boats processed.', 'maradigma');
            case 'All boats processed successfully':
                return __('All boats processed successfully', 'maradigma');
            case 'No more boats to process':
                return __('No more boats to process', 'maradigma');
            case 'Boat skipped: missing id':
                return __('Boat skipped: missing id', 'maradigma');
            default:
                return $message;
        }
    }

    /**
     * @param mixed $reasons
     */
    private static function formatTemplateSkipReasons($reasons): string
    {
        if (!is_array($reasons) || $reasons === []) {
            return '';
        }

        ksort($reasons);

        $items = [];
        foreach ($reasons as $reason => $count) {
            $items[] = sanitize_key((string) $reason) . ': ' . (string) (int) $count;
        }

        return implode(' | ', $items);
    }

    /**
     * @param array<string,mixed> $cleanup
     */
    private static function translateCleanupMessage(array $cleanup): string
    {
        $message = trim((string) ($cleanup['message'] ?? ''));

        switch ($message) {
            case 'No obsolete-page cleanup selected.':
                return __('No obsolete-page cleanup selected.', 'maradigma');
            case 'Permanent delete was selected without explicit confirmation.':
                return __('Permanent delete was selected without explicit confirmation.', 'maradigma');
            case 'Cleanup skipped because the API list was empty or not fully trusted.':
                return __('Cleanup skipped because the API list was empty or not fully trusted.', 'maradigma');
            case 'Cleanup skipped because the boat sync did not finish successfully.':
                return __('Cleanup skipped because the boat sync did not finish successfully.', 'maradigma');
        }

        if (str_starts_with($message, 'Obsolete-page cleanup finished:')) {
            return sprintf(
                /* translators: 1: cleanup action, 2: candidate pages, 3: changed pages, 4: failed pages. */
                __('Obsolete-page cleanup finished: %1$s, candidates %2$d, changed %3$d, failed %4$d.', 'maradigma'),
                (string) ($cleanup['action'] ?? '-'),
                (int) ($cleanup['candidates'] ?? 0),
                (int) ($cleanup['changed'] ?? 0),
                (int) ($cleanup['failed'] ?? 0)
            );
        }

        return $message;
    }

    /**
     * Translates image worker label.
     */
    private static function translateImageWorkerLabel(string $label): string
    {
        switch (strtolower(trim($label))) {
            case 'processing':
                return __('processing', 'maradigma');
            case 'waiting for current worker':
                return __('waiting for current worker', 'maradigma');
            case 'waiting for wp-cron':
                return __('waiting for WP-Cron', 'maradigma');
            case 'running, waiting for next kick':
                return __('running, waiting for next kick', 'maradigma');
            case 'running':
                return __('running', 'maradigma');
            case 'done':
                return __('done', 'maradigma');
            case 'stopped':
                return __('stopped', 'maradigma');
            case 'error':
                return __('error', 'maradigma');
            case 'idle':
                return __('idle', 'maradigma');
            default:
                return $label;
        }
    }

    /**
     * Translates async kick label.
     */
    private static function translateAsyncKickLabel(string $label): string
    {
        switch (strtolower(trim($label))) {
            case 'ok':
                return __('ok', 'maradigma');
            case 'failed':
                return __('failed', 'maradigma');
            case 'not attempted':
                return __('not attempted', 'maradigma');
            default:
                return $label;
        }
    }

    /**
     * Handles sync boats post.
     */
    public static function handleSyncBoatsPost(): void
    {
        SettingsSyncActions::syncBoats();
    }

    /**
     * Handle "Flush cache" admin POST.
     */
    public static function handleFlushCachePost(): void
    {
        SettingsSyncActions::flushCache();
    }

    /**
     * Returns the master Elementor template ID used for boats (elementor_library).
     */
    public static function getBoatMasterTemplateId(): int
    {
        return SettingsTemplateStats::getMasterTemplateId();
    }

    /**
     * Stats of selected builder template propagation status for boat posts.
     *
     * @return array{
     *   builder:string,
     *   master_id:int,
     *   master_hash:string,
     *   master_edit_url:string,
     *   total:int,
     *   synced:int,
     *   not_synced:int,
     *   custom:int,
     *   missing_data:int
     * }
     */
    public static function getBoatTemplateSyncStats(): array
    {
        return SettingsTemplateStats::getSyncStats();
    }

    /**
     * Handles download log post.
     */
    public static function handleDownloadLogPost(): void
    {
        SettingsLogActions::download();
    }

    /**
     * Handles clear log post.
     */
    public static function handleClearLogPost(): void
    {
        SettingsLogActions::clear();
    }
}

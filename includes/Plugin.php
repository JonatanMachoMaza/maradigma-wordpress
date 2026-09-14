<?php

declare(strict_types=1);

namespace Maradigma;

if (!defined('ABSPATH')) {
    exit;
}

use Maradigma\Support\Logger;
use Maradigma\Support\MultilangAdapter;
use Maradigma\Support\PublicBookingGuard;

/**
 * Main plugin bootstrap.
 *
 * Responsibilities:
 * - Register core components (CPT, settings, router, shortcodes, etc.)
 * - Load builder integrations (Gutenberg / Elementor)
 * - Keep rewrite rules updated when SEO slug changes
 * - Ensure Elementor CPT support is ADDED safely (never overwriting pages/posts)
 *
 * IMPORTANT:
 * - BoatsAdminPage MUST NOT be booted anymore (legacy metaboxes/UI).
 * - MetaManager MUST be booted (new universal binding metabox).
 */
final class Plugin
{
    private static bool $bootstrapped = false;

    /**
     * Registers the component's WordPress hooks.
     */
    public static function init(): void
    {
        if (self::$bootstrapped) {
            return;
        }

        self::$bootstrapped = true;

        MultilangAdapter::ensurePostTypeTranslatable(\Maradigma\BoatPostType::POST_TYPE);
        PublicBookingGuard::registerHooks();

        \register_activation_hook(MARADIGMA_PLUGIN_FILE, [__CLASS__, 'onActivate']);
        \register_deactivation_hook(MARADIGMA_PLUGIN_FILE, [__CLASS__, 'onDeactivate']);

        \add_action('plugins_loaded', [__CLASS__, 'onPluginsLoaded'], 0);
        \add_action('init', [__CLASS__, 'loadTextDomain'], 0);
        \add_action('update_option_' . SettingsPage::OPTION_KEY, [__CLASS__, 'onSettingsUpdated'], 10, 3);
    }

    /**
     * Loads the bundled translations after WordPress has initialized.
     *
     * Registering the text domain on `init` keeps translation loading compatible
     * with WordPress 6.7 and later while making the plugin's bundled MO files
     * available outside WordPress.org language packs.
     */
    public static function loadTextDomain(): void
    {
        \load_plugin_textdomain(
            'maradigma',
            false,
            \dirname(\plugin_basename(MARADIGMA_PLUGIN_FILE)) . '/languages'
        );
    }

    /**
     * Re-registers the CPT and schedules a rewrite refresh whenever either:
     * - boats_base_slug changes
     * - enable_boat_pages_sync changes
     *
     * @param mixed  $oldValue
     * @param mixed  $newValue
     * @param string $optionName
     */
    public static function onSettingsUpdated($oldValue, $newValue, string $optionName): void
    {
        if ($optionName !== SettingsPage::OPTION_KEY) {
            return;
        }

        $old = \is_array($oldValue) ? $oldValue : [];
        $new = \is_array($newValue) ? $newValue : [];

        $oldSlug = \trim((string) ($old['boats_base_slug'] ?? 'boats'));
        $newSlug = \trim((string) ($new['boats_base_slug'] ?? 'boats'));

        $oldEnabled = !empty($old['enable_boat_pages_sync']);
        $newEnabled = !empty($new['enable_boat_pages_sync']);

        if ($oldSlug !== $newSlug || $oldEnabled !== $newEnabled) {
            /**
             * IMPORTANT:
             * The CPT may already have been registered earlier in this same request.
             * Re-register it with the updated settings before flushing rewrites.
             */
            if (\post_type_exists(BoatPostType::POST_TYPE) && \function_exists('unregister_post_type')) {
                \unregister_post_type(BoatPostType::POST_TYPE);
            }

            if (\class_exists(BoatPostType::class) && \method_exists(BoatPostType::class, 'register')) {
                BoatPostType::register();
            }

            if (\class_exists(BoatPermalinks::class)) {
                BoatPermalinks::scheduleRewriteRulesFlush();
            }
        }
    }

    /**
     * Initializes integrations after all WordPress plugins have loaded.
     */
    public static function onPluginsLoaded(): void
    {
        try {
            if (\class_exists(\Maradigma\Support\MultilangAdapter::class)) {
                \Maradigma\Support\MultilangAdapter::ensurePostTypeTranslatable(\Maradigma\BoatPostType::POST_TYPE);
            }

            if (\class_exists(BoatPostType::class) && \method_exists(BoatPostType::class, 'init')) {
                BoatPostType::init();
            }

            if (\class_exists(\Maradigma\BoatPermalinks::class) && \method_exists(\Maradigma\BoatPermalinks::class, 'init')) {
                \Maradigma\BoatPermalinks::init();
            }

            if (\class_exists(SettingsPage::class) && \method_exists(SettingsPage::class, 'init')) {
                SettingsPage::init();
            }

            \add_action('admin_bar_menu', [__CLASS__, 'addAdminBarNodes'], 80);

            if (\class_exists(AssetsManager::class) && \method_exists(AssetsManager::class, 'init')) {
                AssetsManager::init();
            }

            if (\class_exists(Router::class) && \method_exists(Router::class, 'init')) {
                Router::init();
            }

            if (\class_exists(AjaxController::class) && \method_exists(AjaxController::class, 'init')) {
                AjaxController::init();
            }

            if (\class_exists(ShortcodeRegistry::class) && \method_exists(ShortcodeRegistry::class, 'init')) {
                ShortcodeRegistry::init();
            }

            if (\class_exists(MetaManager::class) && \method_exists(MetaManager::class, 'init')) {
                MetaManager::init();
            }

            if (\class_exists(\Maradigma\BoatImagesSyncService::class) && \method_exists(\Maradigma\BoatImagesSyncService::class, 'init')) {
                \Maradigma\BoatImagesSyncService::init();
            }

            if (\class_exists(\Maradigma\BoatSyncService::class) && \method_exists(\Maradigma\BoatSyncService::class, 'init')) {
                \Maradigma\BoatSyncService::init();
            }

            if (\class_exists(\Maradigma\BoatTemplateSyncAllService::class) && \method_exists(\Maradigma\BoatTemplateSyncAllService::class, 'init')) {
                \Maradigma\BoatTemplateSyncAllService::init();
            }

            $pluginDir = \plugin_dir_path(MARADIGMA_PLUGIN_FILE);

            $loadIntegration = static function (
                string $relativeFile,
                string $fqcn,
                array $methods = ['init', 'register']
            ) use ($pluginDir): void {
                $file = $pluginDir . \ltrim($relativeFile, '/');
                if (!\is_readable($file)) {
                    return;
                }

                require_once $file;

                if (!\class_exists($fqcn)) {
                    return;
                }

                foreach ($methods as $m) {
                    if (\method_exists($fqcn, $m)) {
                        $fqcn::$m();
                        return;
                    }
                }
            };

            \add_action('init', static function () use ($loadIntegration): void {
                $loadIntegration(
                    'integrations/Gutenberg/GutenbergIntegration.php',
                    \Maradigma\Integrations\Gutenberg\GutenbergIntegration::class,
                    ['register', 'init']
                );
            }, 5);

            \add_action('init', static function () use ($loadIntegration): void {
                $loadIntegration(
                    'integrations/GeneratePress/GeneratePressIntegration.php',
                    \Maradigma\Integrations\GeneratePress\GeneratePressIntegration::class,
                    ['register', 'init']
                );
            }, 6);

            \add_action('init', static function () use ($loadIntegration): void {
                $loadIntegration(
                    'integrations/GenerateBlocks/GenerateBlocksIntegration.php',
                    \Maradigma\Integrations\GenerateBlocks\GenerateBlocksIntegration::class,
                    ['register', 'init']
                );
            }, 7);

            \add_action('init', static function () use ($loadIntegration): void {
                $loadIntegration(
                    'integrations/Gutenverse/GutenverseIntegration.php',
                    \Maradigma\Integrations\Gutenverse\GutenverseIntegration::class,
                    ['register', 'init']
                );
            }, 8);


            \add_action('init', static function () use ($loadIntegration): void {
                $loadIntegration(
                    'integrations/WPBakery/WPBakeryIntegration.php',
                    \Maradigma\Integrations\WPBakery\WPBakeryIntegration::class,
                    ['register', 'init']
                );
            }, 9);

            \add_action('init', static function () use ($loadIntegration): void {
                $loadIntegration(
                    'integrations/WooCommerce/WooCommerceIntegration.php',
                    \Maradigma\Integrations\WooCommerce\WooCommerceIntegration::class,
                    ['register', 'init']
                );
            }, 9);

            $bootElementor = static function () use ($loadIntegration): void {
                self::ensureElementorCptSupport();

                $loadIntegration(
                    'integrations/Elementor/ElementorIntegration.php',
                    \Maradigma\Integrations\Elementor\ElementorIntegration::class,
                    ['init', 'register']
                );

                if (\class_exists(\Maradigma\BoatTemplatePropagator::class) && \method_exists(\Maradigma\BoatTemplatePropagator::class, 'init')) {
                    \Maradigma\BoatTemplatePropagator::init();
                }
            };

            if (\did_action('elementor/loaded')) {
                $bootElementor();
            } else {
                \add_action('elementor/loaded', $bootElementor, 1);
            }
        } catch (\Throwable $e) {
            if (\class_exists(Logger::class)) {
                Logger::exception($e, ['where' => 'Plugin::onPluginsLoaded']);
            } elseif (\defined('MARADIGMA_PLUGIN_DEBUG') && MARADIGMA_PLUGIN_DEBUG === true) {
                \maradigma_debug_log('[Maradigma] Exception in Plugin::onPluginsLoaded: ' . $e->getMessage());
            }
        }
    }

    /**
     * Adds admin bar nodes.
     */
    public static function addAdminBarNodes(\WP_Admin_Bar $adminBar): void
    {
        if (!\is_admin_bar_showing() || !\current_user_can('manage_options')) {
            return;
        }

        $settingsUrl = \admin_url('admin.php?page=maradigma-settings');
        $flushCacheUrl = \wp_nonce_url(
            \admin_url('admin-post.php?action=maradigma_flush_cache'),
            'maradigma_flush_cache_action',
            'maradigma_flush_cache_nonce'
        );

        $adminBar->add_node([
            'id'    => 'maradigma',
            'title' => \esc_html__('Maradigma', 'maradigma'),
            'href'  => $settingsUrl,
            'meta'  => [
                'title' => \esc_attr__('Maradigma', 'maradigma'),
            ],
        ]);

        $adminBar->add_node([
            'id'     => 'maradigma-settings',
            'parent' => 'maradigma',
            'title'  => \esc_html__('Settings', 'maradigma'),
            'href'   => $settingsUrl,
        ]);

        $adminBar->add_node([
            'id'     => 'maradigma-flush-cache',
            'parent' => 'maradigma',
            'title'  => \esc_html__('Clear cache', 'maradigma'),
            'href'   => $flushCacheUrl,
            'meta'   => [
                'onclick' => 'return confirm("' . \esc_js(__('This will clear Maradigma cache. Continue?', 'maradigma')) . '");',
            ],
        ]);
    }

    /**
     * Ensures Elementor cpt support is available and correctly configured.
     */
    private static function ensureElementorCptSupport(): void
    {
        \add_action('elementor/init', static function (): void {
            $postType = BoatPostType::POST_TYPE;

            if (\function_exists('add_post_type_support')) {
                \add_post_type_support($postType, 'elementor');
            }
        }, 20);
    }

    /**
     * Runs plugin activation tasks.
     */
    public static function onActivate(): void
    {
        if (\class_exists(\Maradigma\Support\MultilangAdapter::class)) {
            \Maradigma\Support\MultilangAdapter::ensurePostTypeTranslatable(\Maradigma\BoatPostType::POST_TYPE);
        }

        if (\class_exists(BoatPostType::class) && \method_exists(BoatPostType::class, 'register')) {
            BoatPostType::register();
        }

        \flush_rewrite_rules();
    }

    /**
     * Runs plugin deactivation tasks.
     */
    public static function onDeactivate(): void
    {
        \flush_rewrite_rules();
    }
}

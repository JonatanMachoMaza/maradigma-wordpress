<?php

declare(strict_types=1);

namespace Maradigma\Integrations\Elementor;

if (!defined('ABSPATH')) {
    exit;
}

use Maradigma\Support\Logger;

final class ElementorIntegration
{
    public static function init(): void
    {
        // Elementor must be loaded
        if (!\did_action('elementor/loaded')) {
            return;
        }

        // Categoría (hook correcto en Elementor actual)
        \add_action('elementor/elements/categories_registered', [__CLASS__, 'registerCategory']);

        // Widgets
        \add_action('elementor/widgets/register', [__CLASS__, 'registerWidgets']);

        // Editor panel assets
        \add_action('elementor/editor/after_enqueue_scripts', [__CLASS__, 'enqueueEditorAssets']);
    }

    public static function registerCategory($elementsManager): void
    {
        try {
            // $elementsManager viene como argumento en este hook
            $elementsManager->add_category(
                'maradigma',
                [
                    'title' => \esc_html__('Maradigma', 'maradigma'),
                    'icon'  => 'fa fa-plug',
                ]
            );
        } catch (\Throwable $e) {
            if (\class_exists(Logger::class)) {
                Logger::exception($e, ['where' => 'ElementorIntegration::registerCategory']);
            } elseif (\defined('WP_DEBUG') && WP_DEBUG) {
                \maradigma_debug_log('[Maradigma] ElementorIntegration::registerCategory error: ' . $e->getMessage());
            }
        }
    }

    public static function registerWidgets($widgetsManager): void
    {
        try {
            $base = \plugin_dir_path(MARADIGMA_PLUGIN_FILE);

            // ─────────────────────────────────────────────
            // Files (absolute paths)
            // ─────────────────────────────────────────────

            // Existing widgets
            $boatsFile  = $base . 'integrations/Elementor/Widgets/BoatsArchiveWidget.php';
            $searchFile = $base . 'integrations/Elementor/Widgets/SearchWidget.php';

            // Single Boat base (required)
            $singleBaseFile = $base . 'integrations/Elementor/Widgets/SingleBoat/BaseSingleBoatWidget.php';

            // Single Boat widgets
            $singleTitleFile       = $base . 'integrations/Elementor/Widgets/SingleBoat/BoatTitleWidget.php';
            $singleGalleryFile     = $base . 'integrations/Elementor/Widgets/SingleBoat/BoatGalleryWidget.php';
            $singlePriceFile       = $base . 'integrations/Elementor/Widgets/SingleBoat/BoatPriceWidget.php';
            $singleSpecsFile       = $base . 'integrations/Elementor/Widgets/SingleBoat/BoatSpecsWidget.php';
            $singleDescriptionFile = $base . 'integrations/Elementor/Widgets/SingleBoat/BoatDescriptionWidget.php';
            $singleAdditionalsFile = $base . 'integrations/Elementor/Widgets/SingleBoat/BoatAdditionalServicesWidget.php';

            // ✅ New Single Boat widgets
            $singleEquipmentsFile   = $base . 'integrations/Elementor/Widgets/SingleBoat/BoatEquipmentsWidget.php';
            $singleIncludedFile     = $base . 'integrations/Elementor/Widgets/SingleBoat/BoatIncludedWidget.php';
            $singleNotIncludedFile  = $base . 'integrations/Elementor/Widgets/SingleBoat/BoatNotIncludedWidget.php';
            $singlePdfDownloadFile  = $base . 'integrations/Elementor/Widgets/SingleBoat/BoatPdfDownloadWidget.php';
            $singleBookNowFile      = $base . 'integrations/Elementor/Widgets/SingleBoat/BoatBookNowWidget.php';

            // ✅ New Single Boat Calendar widget
            $singleCalendarFile     = $base . 'integrations/Elementor/Widgets/SingleBoat/BoatCalendarWidget.php';

            // ─────────────────────────────────────────────
            // 1) Register existing widgets (independent)
            // ─────────────────────────────────────────────
            if (\is_readable($boatsFile)) {
                require_once $boatsFile;

                $fqcn = \Maradigma\Integrations\Elementor\Widgets\BoatsArchiveWidget::class;
                if (\class_exists($fqcn)) {
                    $widgetsManager->register(new $fqcn());
                } else {
                    \maradigma_debug_log('[Maradigma] BoatsArchiveWidget class not found after require_once. Check namespace/class name inside file.');
                }
            } else {
                \maradigma_debug_log('[Maradigma] BoatsArchiveWidget.php not readable (skipping): ' . $boatsFile);
            }

            if (\is_readable($searchFile)) {
                require_once $searchFile;

                $fqcn = \Maradigma\Integrations\Elementor\Widgets\SearchWidget::class;
                if (\class_exists($fqcn)) {
                    $widgetsManager->register(new $fqcn());
                } else {
                    \maradigma_debug_log('[Maradigma] SearchWidget class not found after require_once. Check namespace/class name inside file.');
                }
            } else {
                \maradigma_debug_log('[Maradigma] SearchWidget.php not readable (skipping): ' . $searchFile);
            }

            // ─────────────────────────────────────────────
            // 2) Single Boat base (required dependency)
            // ─────────────────────────────────────────────
            if (!\is_readable($singleBaseFile)) {
                \maradigma_debug_log('[Maradigma] BaseSingleBoatWidget.php not readable: ' . $singleBaseFile);
                return;
            }
            require_once $singleBaseFile;

            // ─────────────────────────────────────────────
            // 3) Load Single Boat widget files (requires base)
            // ─────────────────────────────────────────────
            $singleFiles = [
                'BoatTitleWidget'              => $singleTitleFile,
                'BoatGalleryWidget'            => $singleGalleryFile,
                'BoatPriceWidget'              => $singlePriceFile,
                'BoatSpecsWidget'              => $singleSpecsFile,
                'BoatDescriptionWidget'        => $singleDescriptionFile,
                'BoatAdditionalServicesWidget' => $singleAdditionalsFile,

                // ✅ New
                'BoatEquipmentsWidget'         => $singleEquipmentsFile,
                'BoatIncludedWidget'           => $singleIncludedFile,
                'BoatNotIncludedWidget'        => $singleNotIncludedFile,
                'BoatPdfDownloadWidget'        => $singlePdfDownloadFile,
                'BoatBookNowWidget'            => $singleBookNowFile,

                // ✅ Calendar
                'BoatCalendarWidget'           => $singleCalendarFile,
            ];

            foreach ($singleFiles as $label => $filePath) {
                if (!\is_readable($filePath)) {
                    \maradigma_debug_log('[Maradigma] ' . $label . '.php not readable (skipping): ' . $filePath);
                    continue;
                }
                require_once $filePath;
            }

            // ─────────────────────────────────────────────
            // 4) Instantiate + register Single Boat widgets
            // ─────────────────────────────────────────────
            $singleClasses = [
                \Maradigma\Integrations\Elementor\Widgets\SingleBoat\BoatTitleWidget::class,
                \Maradigma\Integrations\Elementor\Widgets\SingleBoat\BoatGalleryWidget::class,
                \Maradigma\Integrations\Elementor\Widgets\SingleBoat\BoatPriceWidget::class,
                \Maradigma\Integrations\Elementor\Widgets\SingleBoat\BoatSpecsWidget::class,
                \Maradigma\Integrations\Elementor\Widgets\SingleBoat\BoatDescriptionWidget::class,
                \Maradigma\Integrations\Elementor\Widgets\SingleBoat\BoatAdditionalServicesWidget::class,

                // ✅ New
                \Maradigma\Integrations\Elementor\Widgets\SingleBoat\BoatEquipmentsWidget::class,
                \Maradigma\Integrations\Elementor\Widgets\SingleBoat\BoatIncludedWidget::class,
                \Maradigma\Integrations\Elementor\Widgets\SingleBoat\BoatNotIncludedWidget::class,
                \Maradigma\Integrations\Elementor\Widgets\SingleBoat\BoatPdfDownloadWidget::class,
                \Maradigma\Integrations\Elementor\Widgets\SingleBoat\BoatBookNowWidget::class,

                // ✅ Calendar
                \Maradigma\Integrations\Elementor\Widgets\SingleBoat\BoatCalendarWidget::class,
            ];

            foreach ($singleClasses as $fqcn) {
                if (\class_exists($fqcn)) {
                    $widgetsManager->register(new $fqcn());
                } else {
                    \maradigma_debug_log('[Maradigma] SingleBoat widget class not found (skipping): ' . $fqcn);
                }
            }
        } catch (\Throwable $e) {
            if (\class_exists(\Maradigma\Support\Logger::class)) {
                \Maradigma\Support\Logger::exception($e, ['where' => 'ElementorIntegration::registerWidgets']);
            } elseif (\defined('WP_DEBUG') && WP_DEBUG) {
                \maradigma_debug_log('[Maradigma] ElementorIntegration::registerWidgets error: ' . $e->getMessage());
            }
        }
    }

    public static function enqueueEditorAssets(): void
    {
        try {
            $baseUrl = \plugin_dir_url(MARADIGMA_PLUGIN_FILE);

            \wp_enqueue_script(
                'maradigma-elementor-panel',
                $baseUrl . 'assets/js/elementor/maradigma-panel.js',
                ['jquery', 'wp-data', 'wp-api-fetch'],
                MARADIGMA_PLUGIN_VERSION,
                true
            );

            // Opcional (si quieres estilos)
            \wp_enqueue_style(
                'maradigma-elementor-panel',
                $baseUrl . 'assets/css/elementor/maradigma-panel.css',
                [],
                MARADIGMA_PLUGIN_VERSION
            );

            // Pásale las meta keys que vas a tocar (y labels)
            \wp_localize_script('maradigma-elementor-panel', 'MaradigmaElementorPanel', [
                'metaKeys' => [
                    'useCustomLayout' => '_maradigma_use_custom_elementor_layout',
                    'boatId'          => '_maradigma_boat_id',
                ],
                'i18n' => [
                    'tabTitle'        => 'Maradigma',
                    'useCustomLayout' => 'Use custom Elementor layout',
                    'useCustomHelp'   => 'When enabled, sync will never overwrite this boat’s Elementor layout. Boat data, images and SEO will still be synced.',
                    'boatIdLabel'     => 'Boat ID',
                    'saveHint'        => 'Changes are saved as post meta.',
                ],
            ]);
        } catch (\Throwable $e) {
            if (\class_exists(Logger::class)) {
                Logger::exception($e, ['where' => 'ElementorIntegration::enqueueEditorAssets']);
            }
        }
    }
}

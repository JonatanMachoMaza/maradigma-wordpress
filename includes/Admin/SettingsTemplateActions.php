<?php

declare(strict_types=1);

namespace Maradigma\Admin;

if (!defined('ABSPATH')) {
    exit;
}

final class SettingsTemplateActions
{
    public static function importBoatIntoElementorMasterTemplate(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }

        check_admin_referer(
            'maradigma_import_boat_elementor_template_action',
            'maradigma_import_boat_elementor_template_nonce'
        );

        $sourcePostId = isset($_POST['source_boat_post_id'])
            ? absint(wp_unslash($_POST['source_boat_post_id']))
            : 0;

        if ($sourcePostId <= 0) {
            self::redirect('elementor_template_import_invalid_source');
        }

        $sourcePost = get_post($sourcePostId);
        if (!$sourcePost || $sourcePost->post_type !== \Maradigma\BoatPostType::POST_TYPE) {
            self::redirect('elementor_template_import_invalid_source');
        }

        $sourceData = (string) get_post_meta($sourcePostId, '_elementor_data', true);
        if (\trim($sourceData) === '') {
            self::redirect('elementor_template_import_empty_source');
        }

        $masterTemplateId = \Maradigma\BoatSyncService::ensureElementorMasterTemplate();
        if ($masterTemplateId <= 0 || !get_post($masterTemplateId)) {
            self::redirect('elementor_template_import_master_error');
        }

        update_post_meta($masterTemplateId, '_elementor_data', wp_slash($sourceData));

        $pageSettings = get_post_meta($sourcePostId, '_elementor_page_settings', true);
        if ($pageSettings !== '' && $pageSettings !== [] && $pageSettings !== null) {
            update_post_meta($masterTemplateId, '_elementor_page_settings', $pageSettings);
        } else {
            delete_post_meta($masterTemplateId, '_elementor_page_settings');
        }

        update_post_meta($masterTemplateId, '_elementor_edit_mode', 'builder');
        update_post_meta($masterTemplateId, '_elementor_template_type', 'page');
        update_post_meta($masterTemplateId, '_elementor_document_type', 'page');
        if (defined('ELEMENTOR_VERSION')) {
            update_post_meta($masterTemplateId, '_elementor_version', ELEMENTOR_VERSION);
        }

        self::regenerateElementorCssSafe($masterTemplateId);
        self::clearElementorCacheSafe();

        self::redirect('elementor_template_imported');
    }

    public static function openGutenbergTemplate(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }

        check_admin_referer(
            'maradigma_open_gutenberg_template_action',
            'maradigma_open_gutenberg_template_nonce'
        );

        $class = self::ensureGutenbergIntegrationClass();

        if ($class === '' || !method_exists($class, 'ensureGutenbergMasterTemplate')) {
            self::redirect('gutenberg_template_error');
        }

        $templateId = (int) $class::ensureGutenbergMasterTemplate();
        if ($templateId <= 0 || !get_post($templateId)) {
            self::redirect('gutenberg_template_error');
        }

        wp_safe_redirect(admin_url('post.php?post=' . $templateId . '&action=edit'));
        exit;
    }

    public static function resetGutenbergTemplate(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }

        check_admin_referer(
            'maradigma_reset_gutenberg_template_action',
            'maradigma_reset_gutenberg_template_nonce'
        );

        $class = self::ensureGutenbergIntegrationClass();
        $ok = $class !== ''
            && method_exists($class, 'resetGutenbergMasterTemplateToDefault')
            && (int) $class::resetGutenbergMasterTemplateToDefault() > 0;

        self::redirect($ok ? 'gutenberg_template_reset' : 'gutenberg_template_reset_error');
    }

    public static function startTemplateSync(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }

        check_admin_referer('maradigma_force_template_sync_action', 'maradigma_force_template_sync_nonce');

        $settings = \Maradigma\SettingsPage::getSettings();
        $layoutBuilder = isset($_POST['layout_builder'])
            ? sanitize_key((string) wp_unslash($_POST['layout_builder']))
            : sanitize_key((string) ($settings['boat_layout_builder'] ?? 'elementor'));

        if (!in_array($layoutBuilder, ['elementor', 'gutenberg', 'wpbakery'], true)) {
            $layoutBuilder = 'elementor';
        }

        $templateMode = isset($_POST['template_mode']) ? sanitize_key((string) wp_unslash($_POST['template_mode'])) : 'overwrite';
        if (!in_array($templateMode, ['overwrite', 'force'], true)) {
            $templateMode = 'overwrite';
        }

        \Maradigma\BoatTemplateSyncAllService::start(100, $layoutBuilder, $templateMode);

        self::redirect('template_sync_started');
    }

    public static function stopTemplateSync(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }

        check_admin_referer('maradigma_force_template_sync_action', 'maradigma_force_template_sync_nonce');

        \Maradigma\BoatTemplateSyncAllService::stop();

        self::redirect('template_sync_stopped');
    }

    /**
     * @return class-string|'' Gutenberg integration class name when available.
     */
    private static function ensureGutenbergIntegrationClass(): string
    {
        $class = \Maradigma\Integrations\Gutenberg\GutenbergIntegration::class;

        if (!class_exists($class)) {
            $file = trailingslashit(MARADIGMA_PLUGIN_DIR) . 'integrations/Gutenberg/GutenbergIntegration.php';
            if (is_readable($file)) {
                require_once $file;
            }
        }

        return class_exists($class) ? $class : '';
    }

    private static function redirect(string $notice): void
    {
        wp_safe_redirect(add_query_arg([
            'page'   => 'maradigma-settings',
            'tab'    => 'settings',
            'notice' => $notice,
        ], admin_url('admin.php')));
        exit;
    }

    private static function regenerateElementorCssSafe(int $postId): void
    {
        try {
            if (!class_exists('\\Elementor\\Core\\Files\\CSS\\Post')) {
                return;
            }

            \Elementor\Core\Files\CSS\Post::create($postId)->update();
        } catch (\Throwable $e) {
            unset($e);
        }
    }

    private static function clearElementorCacheSafe(): void
    {
        try {
            if (!class_exists('\\Elementor\\Plugin')) {
                return;
            }

            $plugin = \Elementor\Plugin::$instance ?? null;
            if (isset($plugin->files_manager) && method_exists($plugin->files_manager, 'clear_cache')) {
                $plugin->files_manager->clear_cache();
            }
        } catch (\Throwable $e) {
            unset($e);
        }
    }
}

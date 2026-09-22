<?php

declare(strict_types=1);

namespace Maradigma\Admin;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles administrative actions for settings sync.
 */
final class SettingsSyncActions
{
    /**
     * Synchronizes boats.
     */
    public static function syncBoats(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }

        check_admin_referer('maradigma_sync_boats_action', 'maradigma_sync_nonce');

        $settings = \Maradigma\SettingsPage::getSettings();
        if (empty($settings['enable_boat_pages_sync'])) {
            self::redirect('sync_disabled');
        }

        $syncInput = null;
        if (isset($_POST['sync']) && is_array($_POST['sync'])) {
            $syncInput = map_deep((array) wp_unslash($_POST['sync']), 'sanitize_text_field');
        }

        $syncOptions = self::buildSyncOptions($settings, $syncInput);
        $restart = isset($_POST['sync_restart'])
            && sanitize_key((string) wp_unslash($_POST['sync_restart'])) === '1';

        try {
            if ($restart && method_exists(\Maradigma\BoatSyncService::class, 'stop')) {
                \Maradigma\BoatSyncService::stop();
            }

            \Maradigma\BoatSyncService::start($syncOptions);

            $state = \Maradigma\BoatSyncService::getState();
            \Maradigma\SettingsPage::storeBoatSyncProgress($state, [
                'notice'       => 'scheduled',
                'options'      => $syncOptions,
                'scheduled_at' => time(),
            ]);

            self::redirect('sync_ok');
        } catch (\Throwable $e) {
            maradigma_debug_log('[Maradigma] boats sync schedule error: ' . $e->getMessage());
            set_transient('maradigma_last_sync_error', (string) $e->getMessage(), 60);
            self::redirect('sync_error');
        }
    }

    /**
     * Flushes cache.
     */
    public static function flushCache(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }

        check_admin_referer('maradigma_flush_cache_action', 'maradigma_flush_cache_nonce');

        try {
            \Maradigma\Cache::flushAll();
            self::redirect('cache_flushed');
        } catch (\Throwable $e) {
            maradigma_debug_log('[Maradigma] cache flush error: ' . $e->getMessage());
            self::redirect('cache_flush_error');
        }
    }

    /**
     * Deletes all boats.
     */
    public static function deleteAllBoats(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }

        check_admin_referer('maradigma_delete_all_boats_action', 'maradigma_delete_all_boats_nonce');

        $delete = isset($_POST['delete']) && is_array($_POST['delete'])
            ? map_deep((array) wp_unslash($_POST['delete']), 'sanitize_text_field')
            : [];
        $force = is_array($delete) && !empty($delete['force']);

        try {
            $query = new \WP_Query([
                'post_type'      => \Maradigma\BoatPostType::POST_TYPE,
                // A permanent reset also empties boats already in the trash.
                'post_status'    => $force ? ['any', 'trash'] : 'any',
                'fields'         => 'ids',
                'posts_per_page' => -1,
                'no_found_rows'  => true,
                // Every language, whatever the admin language filter (translations included).
                'lang'           => '',
                // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters
                'suppress_filters' => true,
            ]);

            $ids = is_array($query->posts) ? $query->posts : [];
            foreach ($ids as $id) {
                $id = (int) $id;
                if ($id > 0) {
                    // wp_delete_post() only trashes posts and pages; boats need wp_trash_post().
                    if ($force) {
                        wp_delete_post($id, true);
                    } else {
                        wp_trash_post($id);
                    }
                }
            }

            update_option('maradigma_boats_last_sync_at', 0, false);
            update_option('maradigma_boats_last_sync_count', 0, false);

            try {
                \Maradigma\Cache::flushAll();
            } catch (\Throwable $e) {
                unset($e);
            }

            flush_rewrite_rules(false);
            self::redirect('boats_deleted');
        } catch (\Throwable $e) {
            maradigma_debug_log('[Maradigma] delete all boats error: ' . $e->getMessage());
            self::redirect('boats_delete_error');
        }
    }

    /**
     * @param array<string,mixed> $settings
     * @param array<string,mixed>|null $syncInput
     * @return array<string,mixed>
     */
    private static function buildSyncOptions(array $settings, ?array $syncInput): array
    {
        if ($syncInput === null) {
            return [
                'update_post_title_slug' => true,
                'update_payload'         => true,
                'sync_images'            => true,
                'yoast_mode'             => 'fix_multilang',
                'layout_builder'         => sanitize_key((string) ($settings['boat_layout_builder'] ?? 'elementor')),
                'elementor_mode'         => 'seed_missing',
                'gutenberg_mode'         => 'seed_missing',
                'wpbakery_mode'         => 'seed_missing',
                'cleanup_obsolete'       => 'none',
                'cleanup_delete_confirm' => false,
                'cleanup_delete_images'  => false,
                'cleanup_duplicates'     => 'none',
            ];
        }

        $sync = $syncInput;
        $options = [
            'update_post_title_slug' => !empty($sync['update_post_title_slug']),
            'update_payload'         => !empty($sync['update_payload']),
            'sync_images'            => !empty($sync['sync_images']),
            'yoast_mode'             => isset($sync['yoast_mode']) ? sanitize_key((string) $sync['yoast_mode']) : 'fix_multilang',
            'layout_builder'         => isset($sync['layout_builder']) ? sanitize_key((string) $sync['layout_builder']) : sanitize_key((string) ($settings['boat_layout_builder'] ?? 'elementor')),
            'elementor_mode'         => isset($sync['elementor_mode']) ? sanitize_key((string) $sync['elementor_mode']) : 'seed_missing',
            'gutenberg_mode'         => isset($sync['gutenberg_mode']) ? sanitize_key((string) $sync['gutenberg_mode']) : 'seed_missing',
            'wpbakery_mode'         => isset($sync['wpbakery_mode']) ? sanitize_key((string) $sync['wpbakery_mode']) : 'seed_missing',
            'cleanup_obsolete'       => isset($sync['cleanup_obsolete']) ? sanitize_key((string) $sync['cleanup_obsolete']) : 'none',
            'cleanup_delete_confirm' => !empty($sync['cleanup_delete_confirm']),
            'cleanup_delete_images'  => !empty($sync['cleanup_delete_images']),
            'cleanup_duplicates'     => isset($sync['cleanup_duplicates']) ? sanitize_key((string) $sync['cleanup_duplicates']) : 'none',
        ];

        if (!in_array($options['yoast_mode'], ['fix_multilang', 'skip'], true)) {
            $options['yoast_mode'] = 'fix_multilang';
        }

        if (!in_array($options['layout_builder'], ['elementor', 'gutenberg', 'wpbakery'], true)) {
            $options['layout_builder'] = 'elementor';
        }

        if (!in_array($options['elementor_mode'], ['seed_missing', 'overwrite', 'skip'], true)) {
            $options['elementor_mode'] = 'seed_missing';
        }

        if (!in_array($options['gutenberg_mode'], ['seed_missing', 'overwrite', 'force', 'skip'], true)) {
            $options['gutenberg_mode'] = 'seed_missing';
        }

        if (!in_array($options['wpbakery_mode'], ['seed_missing', 'overwrite', 'force', 'skip'], true)) {
            $options['wpbakery_mode'] = 'seed_missing';
        }

        if (!in_array($options['cleanup_obsolete'], ['none', 'trash', 'draft', 'delete'], true)) {
            $options['cleanup_obsolete'] = 'none';
        }

        if ($options['cleanup_obsolete'] === 'delete' && empty($options['cleanup_delete_confirm'])) {
            $options['cleanup_obsolete'] = 'none';
            $options['cleanup_delete_images'] = false;
        }

        if ($options['cleanup_obsolete'] !== 'delete') {
            $options['cleanup_delete_images'] = false;
        }

        if (!in_array($options['cleanup_duplicates'], ['none', 'trash', 'draft'], true)) {
            $options['cleanup_duplicates'] = 'none';
        }

        return $options;
    }

    /**
     * Redirects back to the settings page with a status notice.
     */
    private static function redirect(string $notice): void
    {
        wp_safe_redirect(add_query_arg([
            'page'   => 'maradigma-settings',
            'tab'    => 'settings',
            'notice' => $notice,
        ], admin_url('admin.php')));
        exit;
    }
}

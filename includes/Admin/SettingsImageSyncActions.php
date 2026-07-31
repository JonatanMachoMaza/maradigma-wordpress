<?php

declare(strict_types=1);

namespace Maradigma\Admin;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles administrative actions for settings image sync.
 */
final class SettingsImageSyncActions
{
    /**
     * Starts the background workflow.
     */
    public static function start(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }

        check_admin_referer('maradigma_images_sync_action', 'maradigma_images_sync_nonce');

        $settings = \Maradigma\SettingsPage::getSettings();
        if (empty($settings['store_boat_images_locally'])) {
            self::redirect('images_sync_disabled');
        }

        $force = isset($_POST['force_resync'])
            && sanitize_key((string) wp_unslash($_POST['force_resync'])) === '1';
        \Maradigma\BoatImagesSyncService::start($force);

        self::redirect('images_sync_started');
    }

    /**
     * Stops the background workflow and clears pending work.
     */
    public static function stop(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }

        check_admin_referer('maradigma_images_sync_action', 'maradigma_images_sync_nonce');

        \Maradigma\BoatImagesSyncService::stop();

        self::redirect('images_sync_stopped');
    }

    /**
     * Resets the component state.
     */
    public static function reset(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }

        check_admin_referer('maradigma_images_sync_action', 'maradigma_images_sync_nonce');

        $alsoStart = isset($_POST['reset_and_start'])
            && sanitize_key((string) wp_unslash($_POST['reset_and_start'])) === '1';

        \Maradigma\BoatImagesSyncService::resetCache(true);
        if ($alsoStart) {
            \Maradigma\BoatImagesSyncService::start(true);
        }

        self::redirect($alsoStart ? 'images_sync_reset_started' : 'images_sync_reset');
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

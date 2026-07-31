<?php

declare(strict_types=1);

namespace Maradigma\Admin;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles administrative actions for settings log.
 */
final class SettingsLogActions
{
    /**
     * Streams the selected log file as a download.
     */
    public static function download(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }

        check_admin_referer('maradigma_log_action', 'maradigma_log_nonce');

        $channel = isset($_GET['channel']) ? sanitize_key((string) wp_unslash($_GET['channel'])) : 'boat-images-sync';
        $file    = \Maradigma\Support\Debugger::getLogFilePath($channel);

        if ($file === '' || !is_readable($file)) {
            wp_safe_redirect(add_query_arg([
                'page'   => 'maradigma-settings',
                'tab'    => 'advanced',
                'notice' => 'log_not_found',
            ], admin_url('admin.php')));
            exit;
        }

        $filesize = @filesize($file);
        if ($filesize === false) {
            $filesize = 0;
        }

        nocache_headers();
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name(basename($file)) . '"');
        header('Content-Length: ' . (string) $filesize);

        require_once ABSPATH . 'wp-admin/includes/file.php';
        global $wp_filesystem;

        if (!WP_Filesystem() || !is_object($wp_filesystem)) {
            wp_die(esc_html__('Unable to initialize the WordPress filesystem.', 'maradigma'));
        }

        $contents = $wp_filesystem->get_contents($file);
        echo esc_html(is_string($contents) ? $contents : '');
        exit;
    }

    /**
     * Clears the stored data for this component.
     */
    public static function clear(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }

        check_admin_referer('maradigma_log_action', 'maradigma_log_nonce');

        $channel = isset($_POST['channel']) ? sanitize_key((string) wp_unslash($_POST['channel'])) : 'boat-images-sync';
        $file    = \Maradigma\Support\Debugger::getLogFilePath($channel);

        $notice = 'log_clear_error';

        if ($file !== '') {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            global $wp_filesystem;

            if (WP_Filesystem() && is_object($wp_filesystem)) {
                if ($wp_filesystem->put_contents($file, '', FS_CHMOD_FILE)) {
                    $notice = 'log_cleared';
                }
            }
        }

        wp_safe_redirect(add_query_arg([
            'page'   => 'maradigma-settings',
            'tab'    => 'advanced',
            'notice' => $notice,
        ], admin_url('admin.php')));
        exit;
    }
}

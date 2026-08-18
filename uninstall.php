<?php
declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

(static function (): void {
    $optionKey = 'maradigma_settings';

    // Read settings (single-site) and also network settings (multisite).
    $settings = get_option($optionKey, []);
    $networkSettings = is_multisite() ? get_site_option($optionKey, []) : [];

    $deleteData = false;
    $deleteUploads = false;

    // Prefer single-site settings; fallback to network settings.
    if (is_array($settings) && !empty($settings)) {
        $deleteData = !empty($settings['delete_data_on_uninstall']);
        $deleteUploads = !empty($settings['delete_uploads_on_uninstall']);
    } elseif (is_array($networkSettings) && !empty($networkSettings)) {
        $deleteData = !empty($networkSettings['delete_data_on_uninstall']);
        $deleteUploads = !empty($networkSettings['delete_uploads_on_uninstall']);
    }

    // Safety guard: do nothing unless explicitly enabled.
    if ($deleteData !== true) {
        return;
    }

    global $wpdb;

    // 1) Delete options (single-site + network).
    delete_option($optionKey);
    if (is_multisite()) {
        delete_site_option($optionKey);
    }

    $otherOptions = [
        'maradigma_boats_last_sync_at',
        'maradigma_boats_last_sync_count',
        'maradigma_cache_policy',
        'maradigma_version',
    ];

    foreach ($otherOptions as $opt) {
        delete_option($opt);
        if (is_multisite()) {
            delete_site_option($opt);
        }
    }

    // 2) Delete transients (best-effort by prefix).
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            '_transient_maradigma_%',
            '_transient_timeout_maradigma_%'
        )
    );

    if (is_multisite()) {
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s OR meta_key LIKE %s",
                '_site_transient_maradigma_%',
                '_site_transient_timeout_maradigma_%'
            )
        );
    }

    // 3) Delete CPT posts + meta (boats).
    $postType = 'maradigma_boat';

    $postIds = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s",
            $postType
        )
    );

    if (!empty($postIds)) {
        foreach ($postIds as $postId) {
            $postId = absint($postId);
            if ($postId > 0) {
                wp_delete_post($postId, true);
            }
        }
    }

    // 4) Optional: delete uploads folder (default OFF).
    if ($deleteUploads === true && function_exists('wp_upload_dir')) {
        $uploads = wp_upload_dir();
        $baseDir = (string) ($uploads['basedir'] ?? '');

        if ($baseDir !== '') {
            $targetDir = rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR . 'maradigma';
            require_once ABSPATH . 'wp-admin/includes/file.php';

            if (WP_Filesystem()) {
                global $wp_filesystem;
                if ($wp_filesystem instanceof WP_Filesystem_Base) {
                    $wp_filesystem->delete($targetDir, true, 'd');
                }
            }
        }
    }

    // 6) Flush rewrite rules (best-effort).
    if (function_exists('flush_rewrite_rules')) {
        flush_rewrite_rules(false);
    }
})();

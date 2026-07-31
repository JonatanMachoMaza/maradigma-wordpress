<?php

declare(strict_types=1);

namespace Maradigma\Admin;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Builds escaped administrative notices for settings actions.
 */
final class SettingsNotices
{
    /**
     * Builds escaped HTML for an administrative notice.
     */
    public static function html(string $type, string $message): string
    {
        $type = sanitize_key($type);

        $class = 'notice notice-error inline';
        if ($type === 'updated' || $type === 'success') {
            $class = 'notice notice-success inline';
        } elseif ($type === 'warning') {
            $class = 'notice notice-warning inline';
        } elseif ($type === 'info') {
            $class = 'notice notice-info inline';
        }

        return '<div class="' . esc_attr($class) . '"><p><strong>' . esc_html($message) . '</strong></p></div>';
    }
}

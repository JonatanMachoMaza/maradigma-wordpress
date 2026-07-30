<?php

declare(strict_types=1);

namespace Maradigma\Admin;

if (!defined('ABSPATH')) {
    exit;
}

final class SettingsHelpTab
{
    public static function render(string $pageUrl): void
    {
        $activeSection = self::getActiveSection();

        echo self::renderNav($pageUrl, $activeSection); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

        echo '<div class="maradigma-card maradigma-mt-18">';

        switch ($activeSection) {
            case 'shortcodes':
                echo '<h2><span class="maradigma-dot"></span>' . esc_html__('Shortcodes', 'maradigma') . '</h2>';
                echo '<p class="description">' . esc_html__('Shortcodes documentation for webmasters and developers.', 'maradigma') . '</p>';
                echo self::renderHtmlFileInIframe('docs/MARADIGMA_SHORTCODES.html'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                break;

            case 'troubleshooting':
                echo '<h2><span class="maradigma-dot"></span>' . esc_html__('Troubleshooting', 'maradigma') . '</h2>';
                echo '<ul class="maradigma-feature-list">';
                echo '<li>' . esc_html__('If boats do not appear, check API keys and connection status in Configuration tab.', 'maradigma') . '</li>';
                echo '<li>' . esc_html__('If cache looks stale, use "Clear plugin cache".', 'maradigma') . '</li>';
                echo '<li>' . esc_html__('If Elementor widgets or Gutenberg blocks do not show, verify the builder is active.', 'maradigma') . '</li>';
                echo '</ul>';
                break;

            case 'support':
                echo '<h2><span class="maradigma-dot"></span>' . esc_html__('Support', 'maradigma') . '</h2>';
                echo '<p>' . esc_html__('Need help? Contact Maradigma support.', 'maradigma') . '</p>';
                echo '<p><a class="button button-primary" href="https://maradigma.com/contacto" target="_blank" rel="noopener">' .
                    esc_html__('Open support form', 'maradigma') . '</a></p>';
                break;

            case 'getting-started':
            default:
                echo '<h2><span class="maradigma-dot"></span>' . esc_html__('Getting started', 'maradigma') . '</h2>';
                echo '<ol style="margin-left:18px;">';
                echo '<li>' . esc_html__('Go to Configuration tab and set your Public key + Secret key.', 'maradigma') . '</li>';
                echo '<li>' . esc_html__('Verify connection shows "Ready".', 'maradigma') . '</li>';
                echo '<li>' . esc_html__('Add [maradigma_boats] into a page to list boats.', 'maradigma') . '</li>';
                echo '</ol>';
                break;
        }

        echo '</div>';
    }

    private static function getActiveSection(): string
    {
        // Read-only navigation within the help tab.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $section = isset($_GET['help']) ? sanitize_key((string) wp_unslash($_GET['help'])) : 'getting-started';

        $valid = [
            'getting-started',
            'shortcodes',
            'troubleshooting',
            'support',
        ];

        if (!in_array($section, $valid, true)) {
            $section = 'getting-started';
        }

        return $section;
    }

    private static function renderNav(string $pageUrl, string $activeSection): string
    {
        $items = [
            'getting-started' => __('Getting started', 'maradigma'),
            'shortcodes'      => __('Shortcodes', 'maradigma'),
            'troubleshooting' => __('Troubleshooting', 'maradigma'),
            'support'         => __('Support', 'maradigma'),
        ];

        $html = '<div class="maradigma-subtabs" style="margin:12px 0 16px 0;display:flex;gap:8px;flex-wrap:wrap;">';

        foreach ($items as $key => $label) {
            $url = add_query_arg(['tab' => 'help', 'help' => $key], $pageUrl);
            $cls = ($key === $activeSection) ? 'button button-primary' : 'button';

            $html .= '<a class="' . esc_attr($cls) . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
        }

        $html .= '</div>';

        return $html;
    }

    private static function renderHtmlFileInIframe(string $relativePath): string
    {
        $file = plugin_dir_path(MARADIGMA_PLUGIN_FILE) . ltrim($relativePath, '/');

        if (!is_readable($file)) {
            return '<div class="notice notice-error inline"><p><strong>' .
                esc_html__('Documentation file not found.', 'maradigma') .
                '</strong></p></div>';
        }

        $content = file_get_contents($file);
        if (!is_string($content) || trim($content) === '') {
            return '<div class="notice notice-warning inline"><p><strong>' .
                esc_html__('Documentation file is empty.', 'maradigma') .
                '</strong></p></div>';
        }

        $content = preg_replace('#<script\b[^>]*>(.*?)</script>#is', '', $content);
        $content = preg_replace('#\son\w+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)#i', '', (string) $content);

        $docUrl = plugins_url(ltrim($relativePath, '/'), MARADIGMA_PLUGIN_FILE);
        $iframeId = 'maradigma-doc-iframe-' . wp_rand(1000, 9999);

        return '
        <div class="maradigma-doc-filebar" style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin:0 0 12px 0;padding:12px 14px;border:1px solid #e5e5e5;border-radius:10px;background:#fff;">
            <div>
                <strong>' . esc_html__('Documentation', 'maradigma') . '</strong><br>
                <span class="description">' . esc_html__('Readable HTML format for webmasters and developers.', 'maradigma') . '</span>
            </div>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <span class="description" style="margin:0;white-space:nowrap;">' . esc_html__('File:', 'maradigma') . ' <code>' . esc_html($relativePath) . '</code></span>
                <a class="button" href="' . esc_url($docUrl) . '" target="_blank" rel="noopener">' . esc_html__('Open in new tab', 'maradigma') . '</a>
            </div>
        </div>

        <iframe
            id="' . esc_attr($iframeId) . '"
            title="' . esc_attr__('Documentation', 'maradigma') . '"
            srcdoc="' . esc_attr((string) $content) . '"
            sandbox="allow-same-origin"
            style="width:100%;min-height:72vh;border:1px solid #e5e5e5;border-radius:10px;background:#fff;"
        ></iframe>
    ';
    }

}

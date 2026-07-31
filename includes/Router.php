<?php

declare(strict_types=1);

namespace Maradigma;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Template routing for Maradigma CPT pages.
 */
final class Router
{
    /**
     * Registers the component's WordPress hooks.
     */
    public static function init(): void
    {
        add_filter('template_include', [__CLASS__, 'filterTemplate']);
    }

    /**
     * Selects the plugin template for synchronized boat detail requests.
     */
    public static function filterTemplate(string $template): string
    {
        if (is_post_type_archive(BoatPostType::POST_TYPE)) {
            $archiveTemplate = MARADIGMA_PLUGIN_DIR . 'templates/archive-maradigma_boat.php';
            if (file_exists($archiveTemplate)) {
                return $archiveTemplate;
            }
        }

        return $template;
    }
}

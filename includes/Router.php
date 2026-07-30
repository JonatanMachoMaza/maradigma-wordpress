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
    public static function init(): void
    {
        add_filter('template_include', [__CLASS__, 'filterTemplate']);
    }

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

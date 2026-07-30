<?php

declare(strict_types=1);

namespace Maradigma\Integrations\Gutenberg\Blocks;

if (!defined('ABSPATH')) {
    exit;
}

use Maradigma\Integrations\Gutenberg\GutenbergIntegration;

final class BoatGalleryBlock
{
    /**
     * @param array<string,mixed> $attributes
     * @param string              $content
     * @param mixed               $block
     */
    public static function render(array $attributes = [], string $content = '', $block = null): string
    {
        $attributes = GutenbergIntegration::withContextIdentifier($attributes, $block);
        $shortcodeAttributes = GutenbergIntegration::pickAttributes($attributes, ['id', 'slug', 'layout', 'columns', 'enable_lightbox', 'start_index', 'end_index', 'max_images', 'prefer_size_key', 'prefer_wp_cache', 'gap', 'radius', 'slider_autoplay', 'slider_delay', 'slider_loop', 'slider_speed', 'slider_space_between', 'slider_per_view_desktop', 'slider_per_view_tablet', 'slider_per_view_mobile', 'slider_navigation', 'slider_pagination']);

        return GutenbergIntegration::renderShortcode(
            'maradigma_boat_gallery',
            $shortcodeAttributes,
            ''
        );
    }
}

GutenbergIntegration::registerBlock(__DIR__, [BoatGalleryBlock::class, 'render']);

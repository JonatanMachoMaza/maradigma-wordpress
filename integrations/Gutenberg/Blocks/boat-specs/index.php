<?php

declare(strict_types=1);

namespace Maradigma\Integrations\Gutenberg\Blocks;

if (!defined('ABSPATH')) {
    exit;
}

use Maradigma\Integrations\Gutenberg\GutenbergIntegration;

final class BoatSpecsBlock
{
    /**
     * @param array<string,mixed> $attributes
     * @param string              $content
     * @param mixed               $block
     */
    public static function render(array $attributes = [], string $content = '', $block = null): string
    {
        $attributes = GutenbergIntegration::withContextIdentifier($attributes, $block);
        $shortcodeAttributes = GutenbergIntegration::pickAttributes($attributes, ['id', 'slug', 'layout', 'show_labels', 'show_icons', 'icons', 'fields']);

        return GutenbergIntegration::renderShortcode(
            'maradigma_boat_specs',
            $shortcodeAttributes,
            (string) ($attributes['items_json'] ?? '')
        );
    }
}

GutenbergIntegration::registerBlock(__DIR__, [BoatSpecsBlock::class, 'render']);

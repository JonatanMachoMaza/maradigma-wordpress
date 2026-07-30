<?php

declare(strict_types=1);

namespace Maradigma\Integrations\Gutenberg\Blocks;

if (!defined('ABSPATH')) {
    exit;
}

use Maradigma\Integrations\Gutenberg\GutenbergIntegration;

final class BoatDescriptionBlock
{
    /**
     * @param array<string,mixed> $attributes
     * @param string              $content
     * @param mixed               $block
     */
    public static function render(array $attributes = [], string $content = '', $block = null): string
    {
        $attributes = GutenbergIntegration::withContextIdentifier($attributes, $block);
        $shortcodeAttributes = GutenbergIntegration::pickAttributes($attributes, ['id', 'slug', 'type', 'esc', 'allow_html', 'max_words', 'fallback']);

        return GutenbergIntegration::renderShortcode(
            'maradigma_boat_description',
            $shortcodeAttributes,
            ''
        );
    }
}

GutenbergIntegration::registerBlock(__DIR__, [BoatDescriptionBlock::class, 'render']);

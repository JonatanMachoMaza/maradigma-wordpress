<?php

declare(strict_types=1);

namespace Maradigma\Integrations\Gutenberg\Blocks;

if (!defined('ABSPATH')) {
    exit;
}

use Maradigma\Integrations\Gutenberg\GutenbergIntegration;

final class BoatLengthBlock
{
    /**
     * @param array<string,mixed> $attributes
     * @param string              $content
     * @param mixed               $block
     */
    public static function render(array $attributes = [], string $content = '', $block = null): string
    {
        $attributes = GutenbergIntegration::withContextIdentifier($attributes, $block);
        $shortcodeAttributes = GutenbergIntegration::pickAttributes($attributes, ['id', 'slug', 'fallback']);

        return GutenbergIntegration::renderShortcode(
            'maradigma_boat_length',
            $shortcodeAttributes,
            ''
        );
    }
}

GutenbergIntegration::registerBlock(__DIR__, [BoatLengthBlock::class, 'render']);

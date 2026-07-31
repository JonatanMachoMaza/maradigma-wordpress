<?php

declare(strict_types=1);

namespace Maradigma\Integrations\Gutenberg\Blocks;

if (!defined('ABSPATH')) {
    exit;
}

use Maradigma\Integrations\Gutenberg\GutenbergIntegration;

/**
 * Registers and renders the dynamic Gutenberg block for boat price.
 */
final class BoatPriceBlock
{
    /**
     * @param array<string,mixed> $attributes
     * @param string              $content
     * @param mixed               $block
     */
    public static function render(array $attributes = [], string $content = '', $block = null): string
    {
        $attributes = GutenbergIntegration::withContextIdentifier($attributes, $block);
        $shortcodeAttributes = GutenbergIntegration::pickAttributes($attributes, [
            'id',
            'slug',
            'title',
            'show_title',
            'layout',
            'range_mode',
            'order_by',
            'fallback',
            'show_headers',
            'row_gap',
            'vat_mode',
            'vat_position',
            'vat_text_included',
            'vat_text_excluded',
            'currency_display',
            'decimals_mode',
            'thousands_sep',
            'decimal_sep',
        ]);

        return GutenbergIntegration::renderShortcode(
            'maradigma_boat_prices',
            $shortcodeAttributes,
            ''
        );
    }
}

GutenbergIntegration::registerBlock(__DIR__, [BoatPriceBlock::class, 'render']);

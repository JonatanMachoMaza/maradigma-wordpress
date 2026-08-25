<?php

declare(strict_types=1);

namespace Maradigma\Integrations\Gutenberg\Blocks;

if (!defined('ABSPATH')) {
    exit;
}

use Maradigma\Integrations\Gutenberg\GutenbergIntegration;

/**
 * Registers and renders the dynamic Gutenberg block for boat booking.
 */
final class BoatBookingBlock
{
    /**
     * @param array<string,mixed> $attributes
     * @param string              $content
     * @param mixed               $block
     */
    public static function render(array $attributes = [], string $content = '', $block = null): string
    {
        $attributes = GutenbergIntegration::withContextIdentifier($attributes, $block);
        $shortcodeAttributes = GutenbergIntegration::pickAttributes($attributes, ['id', 'slug', 'button_text', 'redirect_url_success', 'calendar_display', 'calendar_months', 'calendar_selection_mode', 'show_schedule_text', 'show_promo_code', 'show_children_included', 'free_additional_label', 'buttons_position']);

        return GutenbergIntegration::renderShortcode(
            'maradigma_boat_booking',
            $shortcodeAttributes,
            ''
        );
    }
}

GutenbergIntegration::registerBlock(__DIR__, [BoatBookingBlock::class, 'render']);

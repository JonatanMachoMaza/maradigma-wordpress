<?php

declare(strict_types=1);

namespace Maradigma\Integrations\Gutenberg\Blocks;

if (!defined('ABSPATH')) {
    exit;
}

use Maradigma\Integrations\Gutenberg\GutenbergIntegration;

final class BoatTitleBlock
{
    /**
     * @param array<string,mixed> $attributes
     * @param string              $content
     * @param mixed               $block
     */
    public static function render(array $attributes = [], string $content = '', $block = null): string
    {
        $attributes = GutenbergIntegration::withContextIdentifier($attributes, $block);
        $shortcodeAttributes = GutenbergIntegration::pickAttributes($attributes, ['id', 'slug', 'fallback', 'show_builder', 'show_model', 'show_alias', 'quote_alias', 'fallback_service_name', 'separator']);

        $title = GutenbergIntegration::renderShortcode(
            'maradigma_boat_title',
            $shortcodeAttributes,
            ''
        );

        $title = \trim((string) $title);
        if ($title === '') {
            return '';
        }

        $tag = \strtolower(\trim((string) ($attributes['tag'] ?? 'h1')));
        if (!\in_array($tag, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'span'], true)) {
            $tag = 'h1';
        }

        return '<' . $tag . ' class="maradigma-boat-title">' . \wp_kses_post($title) . '</' . $tag . '>';
    }
}

GutenbergIntegration::registerBlock(__DIR__, [BoatTitleBlock::class, 'render']);

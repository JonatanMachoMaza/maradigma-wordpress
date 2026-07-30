<?php

declare(strict_types=1);

namespace Maradigma\Integrations\Gutenberg\Blocks;

if (!defined('ABSPATH')) {
    exit;
}

use Maradigma\Integrations\Gutenberg\GutenbergIntegration;

final class BoatPdfDownloadBlock
{
    /**
     * @param array<string,mixed> $attributes
     * @param string              $content
     * @param mixed               $block
     */
    public static function render(array $attributes = [], string $content = '', $block = null): string
    {
        $attributes = GutenbergIntegration::withContextIdentifier($attributes, $block);
        $shortcodeAttributes = GutenbergIntegration::pickAttributes($attributes, ['id', 'slug', 'button_text', 'open_in_new_tab', 'force_download', 'fallback']);

        return GutenbergIntegration::renderShortcode(
            'maradigma_boat_pdf_download',
            $shortcodeAttributes,
            ''
        );
    }
}

GutenbergIntegration::registerBlock(__DIR__, [BoatPdfDownloadBlock::class, 'render']);

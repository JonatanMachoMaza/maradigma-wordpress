<?php

declare(strict_types=1);

namespace Maradigma\Integrations\Gutenberg\Blocks;

if (!defined('ABSPATH')) {
    exit;
}

use Maradigma\Integrations\Gutenberg\GutenbergIntegration;

final class BoatAdditionalServicesBlock
{
    /**
     * @param array<string,mixed> $attributes
     * @param string              $content
     * @param mixed               $block
     */
    public static function render(array $attributes = [], string $content = '', $block = null): string
    {
        return GutenbergIntegration::renderBoatAdditionalServices($attributes, $block);
    }
}

GutenbergIntegration::registerBlock(__DIR__, [BoatAdditionalServicesBlock::class, 'render']);

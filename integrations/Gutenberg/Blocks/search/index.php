<?php

declare(strict_types=1);

namespace Maradigma\Integrations\Gutenberg\Blocks;

if (!defined('ABSPATH')) {
    exit;
}

use Maradigma\Integrations\Gutenberg\GutenbergIntegration;

final class SearchBlock
{
    /**
     * @param array<string,mixed> $attributes
     */
    public static function render(array $attributes = []): string
    {
        return GutenbergIntegration::renderSearchForm($attributes);
    }
}

GutenbergIntegration::registerBlock(__DIR__, [SearchBlock::class, 'render']);

<?php

declare(strict_types=1);

namespace Maradigma\Integrations\Gutenberg\Blocks;

if (!defined('ABSPATH')) {
    exit;
}

use Maradigma\Integrations\Gutenberg\GutenbergIntegration;

final class BoatsArchiveBlock
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
            'id_group',
            'limit_services',
            'offset_services',
            'show_filters',
            'autosubmit_filters',
            'allow_url_filters',
            'order_by',
            'card',
            'image_token',
            'filters_ui_fields',
            'filters_ui_fields_offcanvas',
            'filters_ui_layout',
            'filters_ui_submit_mode',
            'filters_ui_show_reset',
            'show_more_filters_button',
            'more_filters_button_text',
            'more_filters_offcanvas_title',
            'date_picker_mode',
            'term',
            'ids_gi',
            'featured',
            'ins_book',
            'min_price',
            'max_price',
            'boat_capacity',
            'boat_type_id',
            'builders',
            'gc_type',
            'date_start',
            'date_end',
            'search_own_managment',
            'only_calendarization',
            'ignore_date_range',
        ]);

        return GutenbergIntegration::renderShortcode(
            'maradigma_boats',
            $shortcodeAttributes,
            ''
        );
    }
}

GutenbergIntegration::registerBlock(__DIR__, [BoatsArchiveBlock::class, 'render']);

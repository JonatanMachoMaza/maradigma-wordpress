<?php

declare(strict_types=1);

namespace Maradigma\Integrations\Elementor\Widgets;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Repeater;
use Elementor\Group_Control_Typography;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Box_Shadow;
use Maradigma\ShortcodeRegistry;

final class BoatsArchiveWidget extends Widget_Base
{
    public function get_name(): string
    {
        return 'maradigma_boats_archive';
    }

    public function get_title(): string
    {
        return \esc_html__('Maradigma - Boats Archive', 'maradigma');
    }

    public function get_icon(): string
    {
        return 'eicon-posts-grid';
    }

    public function get_categories(): array
    {
        return ['maradigma'];
    }

    protected function register_controls(): void
    {
        // ─────────────────────────────────────────────
        // SECTION: Configuration (listing behavior)
        // ─────────────────────────────────────────────
        $this->start_controls_section('section_config', [
            'label' => \esc_html__('Configuration', 'maradigma'),
            'tab'   => Controls_Manager::TAB_CONTENT,
        ]);

        $cardOptions = ['' => \esc_html__('Default (plugin setting)', 'maradigma')];
        if (\class_exists(\Maradigma\BoatCardRepository::class)) {
            $cards = \Maradigma\BoatCardRepository::listCards();
            foreach ($cards as $id => $card) {
                $name = (string) ($card['name'] ?? $id);
                $cardOptions[(string) $id] = \sprintf('%s (%s)', $name, (string) $id);
            }
        }

        $this->add_control('card', [
            'label'   => \esc_html__('Boat card template (card)', 'maradigma'),
            'type'    => Controls_Manager::SELECT,
            'default' => '',
            'options' => $cardOptions,
        ]);

        $imageTokenOptions = [
            'image_main' => \esc_html__('Main image (auto)', 'maradigma'),
        ];

        if (
            \class_exists(\Maradigma\BoatImagesSyncService::class)
            && \method_exists(\Maradigma\BoatImagesSyncService::class, 'getElementorImageTokenOptions')
        ) {
            $opts = \Maradigma\BoatImagesSyncService::getElementorImageTokenOptions();
            if (\is_array($opts) && !empty($opts)) {
                $imageTokenOptions = $opts;
            }
        }

        $defaultImageToken = 'image_main';
        if (isset($imageTokenOptions['image_maradigma_card_471x273'])) {
            $defaultImageToken = 'image_maradigma_card_471x273';
        }

        $this->add_control('image_token', [
            'label'   => \esc_html__('Card image token (image_token)', 'maradigma'),
            'type'    => Controls_Manager::SELECT,
            'default' => $defaultImageToken,
            'options' => $imageTokenOptions,
        ]);

        $this->add_control('limit_services', [
            'label'   => \esc_html__('Items per page (limit_services)', 'maradigma'),
            'type'    => Controls_Manager::NUMBER,
            'min'     => 1,
            'max'     => 60,
            'step'    => 1,
            'default' => 10,
        ]);

        $this->add_control('order_by', [
            'label'   => \esc_html__('Default order (order_by)', 'maradigma'),
            'type'    => Controls_Manager::SELECT,
            'default' => '0',
            'options' => [
                '0' => \esc_html__('Relevance', 'maradigma'),
                '1' => \esc_html__('Price: low to high', 'maradigma'),
                '2' => \esc_html__('Price: high to low', 'maradigma'),
                '6' => \esc_html__('Length: low to high', 'maradigma'),
                '5' => \esc_html__('Length: high to low', 'maradigma'),
                '3' => \esc_html__('Featured first', 'maradigma'),
                '4' => \esc_html__('Newest first', 'maradigma'),
            ],
        ]);

        $this->add_control('show_filters', [
            'label'        => \esc_html__('Show filters form (show_filters)', 'maradigma'),
            'type'         => Controls_Manager::SWITCHER,
            'label_on'     => \esc_html__('Yes', 'maradigma'),
            'label_off'    => \esc_html__('No', 'maradigma'),
            'return_value' => '1',
            'default'      => '',
        ]);

        $this->add_control('autosubmit_filters', [
            'label'        => \esc_html__('Autosubmit on change (autosubmit_filters)', 'maradigma'),
            'type'         => Controls_Manager::SWITCHER,
            'label_on'     => \esc_html__('Yes', 'maradigma'),
            'label_off'    => \esc_html__('No', 'maradigma'),
            'return_value' => '1',
            'default'      => '1',
        ]);

        $this->add_control('allow_url_filters', [
            'label'        => \esc_html__('Allow URL filters override widget defaults (allow_url_filters)', 'maradigma'),
            'description'  => \esc_html__('Needed for GET form filters.', 'maradigma'),
            'type'         => Controls_Manager::SWITCHER,
            'label_on'     => \esc_html__('Yes', 'maradigma'),
            'label_off'    => \esc_html__('No', 'maradigma'),
            'return_value' => '1',
            'default'      => '1',
            'condition'    => ['show_filters' => '1'],
        ]);

        $this->end_controls_section();

        // ─────────────────────────────────────────────
        // SECTION: Filters UI (what the user sees)
        // ─────────────────────────────────────────────
        $this->start_controls_section('section_filters_ui', [
            'label'     => \esc_html__('Filters UI (frontend)', 'maradigma'),
            'tab'       => Controls_Manager::TAB_CONTENT,
            'condition' => ['show_filters' => '1'],
        ]);

        $this->add_control('filters_ui_layout', [
            'label'   => \esc_html__('Layout', 'maradigma'),
            'type'    => Controls_Manager::SELECT,
            'default' => 'horizontal',
            'options' => [
                'horizontal' => \esc_html__('Horizontal', 'maradigma'),
                'vertical'   => \esc_html__('Vertical', 'maradigma'),
            ],
        ]);

        $this->add_control('filters_ui_submit_mode', [
            'label'   => \esc_html__('Submit mode', 'maradigma'),
            'type'    => Controls_Manager::SELECT,
            'default' => 'auto',
            'options' => [
                'auto'   => \esc_html__('Auto (JS on change)', 'maradigma'),
                'button' => \esc_html__('Button (manual submit)', 'maradigma'),
            ],
        ]);

        $this->add_control('filters_ui_show_reset', [
            'label'        => \esc_html__('Show reset link', 'maradigma'),
            'type'         => Controls_Manager::SWITCHER,
            'label_on'     => \esc_html__('Yes', 'maradigma'),
            'label_off'    => \esc_html__('No', 'maradigma'),
            'return_value' => '1',
            'default'      => '1',
        ]);

        $this->add_control('show_more_filters_button', [
            'label'        => \esc_html__('Show "More filters" button', 'maradigma'),
            'type'         => Controls_Manager::SWITCHER,
            'label_on'     => \esc_html__('Yes', 'maradigma'),
            'label_off'    => \esc_html__('No', 'maradigma'),
            'return_value' => '1',
            'default'      => '1',
        ]);

        $this->add_control('more_filters_button_text', [
            'label'     => \esc_html__('"More filters" button text', 'maradigma'),
            'type'      => Controls_Manager::TEXT,
            'default'   => 'More filters',
            'condition' => ['show_more_filters_button' => '1'],
        ]);

        $this->add_control('more_filters_offcanvas_title', [
            'label'     => \esc_html__('Offcanvas title', 'maradigma'),
            'type'      => Controls_Manager::TEXT,
            'default'   => 'More filters',
            'condition' => ['show_more_filters_button' => '1'],
        ]);

        $fieldsOptions = [
            'term'          => \esc_html__('Search term', 'maradigma'),
            'boat_capacity' => \esc_html__('Min pax', 'maradigma'),
            'order_by'      => \esc_html__('Order by', 'maradigma'),
            'featured'      => \esc_html__('Featured', 'maradigma'),
            'ins_book'      => \esc_html__('Instant booking', 'maradigma'),
            'min_price'     => \esc_html__('Min price', 'maradigma'),
            'max_price'     => \esc_html__('Max price', 'maradigma'),
            'boat_type_id'  => \esc_html__('Boat type', 'maradigma'),
            'builders'      => \esc_html__('Builders', 'maradigma'),
            'ids_gi'        => \esc_html__('Specific boats', 'maradigma'),
            'date_start'    => \esc_html__('Availability dates', 'maradigma'),
            'date_end'      => \esc_html__('Date end (separate mode only)', 'maradigma'),
        ];

        $repeater = new Repeater();

        $repeater->add_control('field', [
            'label'       => \esc_html__('Field', 'maradigma'),
            'type'        => Controls_Manager::SELECT,
            'default'     => 'term',
            'options'     => $fieldsOptions,
            'label_block' => true,
        ]);

        $repeater->add_control('enabled', [
            'label'        => \esc_html__('Enabled', 'maradigma'),
            'type'         => Controls_Manager::SWITCHER,
            'label_on'     => \esc_html__('Yes', 'maradigma'),
            'label_off'    => \esc_html__('No', 'maradigma'),
            'return_value' => '1',
            'default'      => '1',
        ]);

        $titleTemplate = implode("\n", [
    '<# ',
    'var labels = {',
    '  term: \'Search term\',',
    '  boat_capacity: \'Min pax\',',
    '  order_by: \'Order by\',',
    '  featured: \'Featured\',',
    '  ins_book: \'Instant booking\',',
    '  min_price: \'Min price\',',
    '  max_price: \'Max price\',',
    '  boat_type_id: \'Boat type\',',
    '  builders: \'Builders\',',
    '  ids_gi: \'Specific boats\',',
    '  date_start: \'Availability dates\',',
    '  date_end: \'Date end (separate mode only)\',',
    '};',
    'var key = field || \'\';',
    'var label = labels[key] ? labels[key] : key;',
    '#>',
    '{{{ label }}}',
]);

        $this->add_control('filters_ui_fields_repeater', [
            'label'       => \esc_html__('Fields order (drag & drop)', 'maradigma'),
            'type'        => Controls_Manager::REPEATER,
            'fields'      => $repeater->get_controls(),
            'title_field' => $titleTemplate,
            'default'     => [
                ['field' => 'date_start',    'enabled' => '1'],
                ['field' => 'boat_capacity', 'enabled' => '1'],
                ['field' => 'min_price',     'enabled' => '1'],
                ['field' => 'max_price',     'enabled' => '1'],
            ],
        ]);

        $repeaterOff = new Repeater();

        $repeaterOff->add_control('field', [
            'label'       => \esc_html__('Field', 'maradigma'),
            'type'        => Controls_Manager::SELECT,
            'default'     => 'boat_type_id',
            'options'     => $fieldsOptions,
            'label_block' => true,
        ]);

        $repeaterOff->add_control('enabled', [
            'label'        => \esc_html__('Enabled', 'maradigma'),
            'type'         => Controls_Manager::SWITCHER,
            'label_on'     => \esc_html__('Yes', 'maradigma'),
            'label_off'    => \esc_html__('No', 'maradigma'),
            'return_value' => '1',
            'default'      => '1',
        ]);

        $this->add_control('filters_ui_fields_offcanvas_repeater', [
            'label'       => \esc_html__('Fields order offcanvas (drag & drop)', 'maradigma'),
            'type'        => Controls_Manager::REPEATER,
            'fields'      => $repeaterOff->get_controls(),
            'title_field' => $titleTemplate,
            'condition'   => ['show_more_filters_button' => '1'],
            'default'     => [
                ['field' => 'boat_type_id', 'enabled' => '1'],
                ['field' => 'builders',     'enabled' => '1'],
                ['field' => 'ids_gi',       'enabled' => '1'],
            ],
        ]);

        $this->end_controls_section();

        // ─────────────────────────────────────────────
        // SECTION: Listing defaults
        // ─────────────────────────────────────────────
        $this->start_controls_section('section_defaults', [
            'label' => \esc_html__('Listing defaults', 'maradigma'),
            'tab'   => Controls_Manager::TAB_CONTENT,
        ]);

        $this->add_control('term', [
            'label'   => \esc_html__('Search term (term)', 'maradigma'),
            'type'    => Controls_Manager::TEXT,
            'default' => '',
        ]);

        $this->add_control('boat_capacity', [
            'label'   => \esc_html__('Min pax (boat_capacity)', 'maradigma'),
            'type'    => Controls_Manager::NUMBER,
            'min'     => 1,
            'max'     => 100,
            'step'    => 1,
            'default' => '',
        ]);

        $this->add_control('featured', [
            'label'        => \esc_html__('Featured only (featured)', 'maradigma'),
            'type'         => Controls_Manager::SWITCHER,
            'label_on'     => \esc_html__('Yes', 'maradigma'),
            'label_off'    => \esc_html__('No', 'maradigma'),
            'return_value' => '1',
            'default'      => '',
        ]);

        $this->add_control('ins_book', [
            'label'        => \esc_html__('Instant booking only (ins_book)', 'maradigma'),
            'type'         => Controls_Manager::SWITCHER,
            'label_on'     => \esc_html__('Yes', 'maradigma'),
            'label_off'    => \esc_html__('No', 'maradigma'),
            'return_value' => '1',
            'default'      => '',
        ]);

        $this->add_control('min_price', [
            'label'   => \esc_html__('Min price (min_price)', 'maradigma'),
            'type'    => Controls_Manager::NUMBER,
            'min'     => 0,
            'step'    => 1,
            'default' => '',
        ]);

        $this->add_control('max_price', [
            'label'   => \esc_html__('Max price (max_price)', 'maradigma'),
            'type'    => Controls_Manager::NUMBER,
            'min'     => 0,
            'step'    => 1,
            'default' => '',
        ]);

        $this->add_control('boat_type_id', [
            'label'   => \esc_html__('Boat type ID (boat_type_id)', 'maradigma'),
            'type'    => Controls_Manager::HIDDEN,
            'default' => '',
        ]);

        $this->add_control('boat_type_id_ui', [
            'type' => Controls_Manager::RAW_HTML,
            'raw'  => '<div class="maradigma-boat-type-ui">
            <label style="display:block;margin:0 0 6px;font-weight:600;">' . \esc_html__('Boat type', 'maradigma') . '</label>
            <select class="maradigma-el-remote-select"
                    data-maradigma-source="boat_types"
                    data-setting-key="boat_type_id"
                    data-multiple="0"
                    style="width:100%;"></select>
        </div>',
        ]);

        $this->add_control('builders', [
            'label'   => \esc_html__('Builders (builders)', 'maradigma'),
            'type'    => Controls_Manager::HIDDEN,
            'default' => '',
        ]);

        $this->add_control('builders_ui', [
            'type' => Controls_Manager::RAW_HTML,
            'raw'  => '<div class="maradigma-builders-ui">
            <label style="display:block;margin:0 0 6px;font-weight:600;">' . \esc_html__('Builders', 'maradigma') . '</label>
            <select class="maradigma-el-remote-select"
                    data-maradigma-source="builders"
                    data-setting-key="builders"
                    data-multiple="1"
                    style="width:100%;"></select>
        </div>',
        ]);

        $this->add_control('ids_gi', [
            'label'   => \esc_html__('Specific boats IDs (ids_gi)', 'maradigma'),
            'type'    => Controls_Manager::HIDDEN,
            'default' => '',
        ]);

        $this->add_control('ids_gi_ui', [
            'type' => Controls_Manager::RAW_HTML,
            'raw'  => '<div class="maradigma-ids-gi-ui">
            <label style="display:block;margin:0 0 6px;font-weight:600;">' . \esc_html__('Specific boats', 'maradigma') . '</label>
            <select class="maradigma-el-remote-select"
                    data-maradigma-source="boats"
                    data-setting-key="ids_gi"
                    data-multiple="1"
                    style="width:100%;"></select>
        </div>',
        ]);

        $this->end_controls_section();

        // ─────────────────────────────────────────────
        // SECTION: Price format
        // ─────────────────────────────────────────────
        $this->start_controls_section('section_price_format', [
            'label' => \esc_html__('Price format (cards)', 'maradigma'),
            'tab'   => Controls_Manager::TAB_CONTENT,
        ]);

        $this->add_control('price_currency_display', [
            'label'   => \esc_html__('Currency display', 'maradigma'),
            'type'    => Controls_Manager::SELECT,
            'default' => 'symbol',
            'options' => [
                'symbol' => \esc_html__('Symbol (€, $, £)', 'maradigma'),
                'iso'    => \esc_html__('ISO (EUR, USD...)', 'maradigma'),
            ],
        ]);

        $this->add_control('price_decimals_mode', [
            'label'   => \esc_html__('Decimals', 'maradigma'),
            'type'    => Controls_Manager::SELECT,
            'default' => 'auto',
            'options' => [
                'auto' => \esc_html__('Auto', 'maradigma'),
                '0'    => \esc_html__('0', 'maradigma'),
                '2'    => \esc_html__('2', 'maradigma'),
            ],
        ]);

        $this->add_control('price_thousands_sep', [
            'label'       => \esc_html__('Thousands separator', 'maradigma'),
            'type'        => Controls_Manager::TEXT,
            'default'     => '.',
            'placeholder' => '.',
        ]);

        $this->add_control('price_decimal_sep', [
            'label'       => \esc_html__('Decimal separator', 'maradigma'),
            'type'        => Controls_Manager::TEXT,
            'default'     => ',',
            'placeholder' => ',',
        ]);

        $this->add_control('date_picker_mode', [
            'label'   => \esc_html__('Date selector', 'maradigma'),
            'type'    => Controls_Manager::SELECT,
            'default' => 'range',
            'options' => [
                'range'    => \esc_html__('One field (date range)', 'maradigma'),
                'separate' => \esc_html__('Two fields (start / end)', 'maradigma'),
            ],
            'condition' => ['show_filters' => '1'],
        ]);

        $this->end_controls_section();

        // ============================================================
        // STYLE TAB
        // ============================================================

        // ─────────────────────────────────────────────
        // STYLE: Wrapper
        // ─────────────────────────────────────────────
        $this->start_controls_section('section_style_wrapper', [
            'label' => \esc_html__('Wrapper', 'maradigma'),
            'tab'   => Controls_Manager::TAB_STYLE,
        ]);

        $this->add_responsive_control('wrapper_max_width', [
            'label'      => \esc_html__('Max width', 'maradigma'),
            'type'       => Controls_Manager::SLIDER,
            'size_units' => ['px', '%', 'vw'],
            'selectors'  => [
                '{{WRAPPER}} .maradigma-boats-shortcode-list' => 'max-width: {{SIZE}}{{UNIT}};',
            ],
        ]);

        $this->add_responsive_control('wrapper_alignment', [
            'label'   => \esc_html__('Alignment', 'maradigma'),
            'type'    => Controls_Manager::CHOOSE,
            'options' => [
                'flex-start' => [
                    'title' => \esc_html__('Left', 'maradigma'),
                    'icon'  => 'eicon-text-align-left',
                ],
                'center' => [
                    'title' => \esc_html__('Center', 'maradigma'),
                    'icon'  => 'eicon-text-align-center',
                ],
                'flex-end' => [
                    'title' => \esc_html__('Right', 'maradigma'),
                    'icon'  => 'eicon-text-align-right',
                ],
            ],
            'selectors' => [
                '{{WRAPPER}}' => 'display:flex; justify-content: {{VALUE}};',
            ],
        ]);

        $this->add_control('wrapper_background', [
            'label'     => \esc_html__('Background color', 'maradigma'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .maradigma-boats-shortcode-list' => 'background-color: {{VALUE}};',
            ],
        ]);

        $this->add_group_control(
            Group_Control_Border::get_type(),
            [
                'name'     => 'wrapper_border',
                'selector' => '{{WRAPPER}} .maradigma-boats-shortcode-list',
            ]
        );

        $this->add_responsive_control('wrapper_border_radius', [
            'label'      => \esc_html__('Border radius', 'maradigma'),
            'type'       => Controls_Manager::DIMENSIONS,
            'size_units' => ['px', '%'],
            'selectors'  => [
                '{{WRAPPER}} .maradigma-boats-shortcode-list' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
        ]);

        $this->add_group_control(
            Group_Control_Box_Shadow::get_type(),
            [
                'name'     => 'wrapper_box_shadow',
                'selector' => '{{WRAPPER}} .maradigma-boats-shortcode-list',
            ]
        );

        $this->add_responsive_control('wrapper_padding', [
            'label'      => \esc_html__('Padding', 'maradigma'),
            'type'       => Controls_Manager::DIMENSIONS,
            'size_units' => ['px', '%', 'em', 'rem'],
            'selectors'  => [
                '{{WRAPPER}} .maradigma-boats-shortcode-list' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
        ]);

        $this->add_responsive_control('wrapper_margin', [
            'label'      => \esc_html__('Margin', 'maradigma'),
            'type'       => Controls_Manager::DIMENSIONS,
            'size_units' => ['px', '%', 'em', 'rem'],
            'selectors'  => [
                '{{WRAPPER}} .maradigma-boats-shortcode-list' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
        ]);

        $this->end_controls_section();

        // ─────────────────────────────────────────────
        // STYLE: Filters form
        // ─────────────────────────────────────────────
        $this->start_controls_section('section_style_filters_form', [
            'label'     => \esc_html__('Filters form', 'maradigma'),
            'tab'       => Controls_Manager::TAB_STYLE,
            'condition' => ['show_filters' => '1'],
        ]);

        $this->add_control('filters_form_background', [
            'label'     => \esc_html__('Background color', 'maradigma'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .maradigma-boats-filters' => 'background-color: {{VALUE}};',
            ],
        ]);

        $this->add_group_control(
            Group_Control_Border::get_type(),
            [
                'name'     => 'filters_form_border',
                'selector' => '{{WRAPPER}} .maradigma-boats-filters',
            ]
        );

        $this->add_responsive_control('filters_form_radius', [
            'label'      => \esc_html__('Border radius', 'maradigma'),
            'type'       => Controls_Manager::DIMENSIONS,
            'size_units' => ['px', '%'],
            'selectors'  => [
                '{{WRAPPER}} .maradigma-boats-filters' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
        ]);

        $this->add_group_control(
            Group_Control_Box_Shadow::get_type(),
            [
                'name'     => 'filters_form_shadow',
                'selector' => '{{WRAPPER}} .maradigma-boats-filters',
            ]
        );

        $this->add_responsive_control('filters_form_padding', [
            'label'      => \esc_html__('Padding', 'maradigma'),
            'type'       => Controls_Manager::DIMENSIONS,
            'size_units' => ['px', 'em', 'rem', '%'],
            'selectors'  => [
                '{{WRAPPER}} .maradigma-boats-filters' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
        ]);

        $this->add_responsive_control('filters_form_margin', [
            'label'      => \esc_html__('Margin', 'maradigma'),
            'type'       => Controls_Manager::DIMENSIONS,
            'size_units' => ['px', 'em', 'rem', '%'],
            'selectors'  => [
                '{{WRAPPER}} .maradigma-boats-filters' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
        ]);

        $this->end_controls_section();

        // ─────────────────────────────────────────────
        // STYLE: Filter bar layout
        // ─────────────────────────────────────────────
        $this->start_controls_section('section_style_filterbar', [
            'label'     => \esc_html__('Filter bar', 'maradigma'),
            'tab'       => Controls_Manager::TAB_STYLE,
            'condition' => ['show_filters' => '1'],
        ]);

        $this->add_responsive_control('filterbar_gap', [
            'label'      => \esc_html__('Gap', 'maradigma'),
            'type'       => Controls_Manager::SLIDER,
            'size_units' => ['px', 'em', 'rem'],
            'selectors'  => [
                '{{WRAPPER}} .maradigma-boats-filters .md-filterbar'        => 'gap: {{SIZE}}{{UNIT}};',
                '{{WRAPPER}} .maradigma-boats-filters .md-filterbar__left'  => 'gap: {{SIZE}}{{UNIT}};',
                '{{WRAPPER}} .maradigma-boats-filters .md-drawer__grid'     => 'gap: {{SIZE}}{{UNIT}};',
            ],
        ]);

        $this->add_responsive_control('filterbar_left_alignment', [
            'label'   => \esc_html__('Fields alignment', 'maradigma'),
            'type'    => Controls_Manager::CHOOSE,
            'options' => [
                'flex-start' => [
                    'title' => \esc_html__('Start', 'maradigma'),
                    'icon'  => 'eicon-h-align-left',
                ],
                'center' => [
                    'title' => \esc_html__('Center', 'maradigma'),
                    'icon'  => 'eicon-h-align-center',
                ],
                'flex-end' => [
                    'title' => \esc_html__('End', 'maradigma'),
                    'icon'  => 'eicon-h-align-right',
                ],
                'space-between' => [
                    'title' => \esc_html__('Space between', 'maradigma'),
                    'icon'  => 'eicon-justify-space-between-h',
                ],
            ],
            'selectors' => [
                '{{WRAPPER}} .maradigma-boats-filters .md-filterbar__left' => 'justify-content: {{VALUE}};',
            ],
        ]);

        $this->add_responsive_control('filterbar_right_alignment', [
            'label'   => \esc_html__('Sort alignment', 'maradigma'),
            'type'    => Controls_Manager::CHOOSE,
            'options' => [
                'flex-start' => [
                    'title' => \esc_html__('Start', 'maradigma'),
                    'icon'  => 'eicon-h-align-left',
                ],
                'center' => [
                    'title' => \esc_html__('Center', 'maradigma'),
                    'icon'  => 'eicon-h-align-center',
                ],
                'flex-end' => [
                    'title' => \esc_html__('End', 'maradigma'),
                    'icon'  => 'eicon-h-align-right',
                ],
            ],
            'selectors' => [
                '{{WRAPPER}} .maradigma-boats-filters .md-filterbar__right' => 'justify-content: {{VALUE}};',
            ],
        ]);

        $this->end_controls_section();

        // ─────────────────────────────────────────────
        // STYLE: Labels
        // ─────────────────────────────────────────────
        $this->start_controls_section('section_style_labels', [
            'label'     => \esc_html__('Labels', 'maradigma'),
            'tab'       => Controls_Manager::TAB_STYLE,
            'condition' => ['show_filters' => '1'],
        ]);

        $this->add_group_control(
            Group_Control_Typography::get_type(),
            [
                'name'     => 'labels_typography',
                'selector' => '{{WRAPPER}} .maradigma-boats-filters .md-label, {{WRAPPER}} .maradigma-boats-filters .md-sortby__label',
            ]
        );

        $this->add_control('labels_color', [
            'label'     => \esc_html__('Text color', 'maradigma'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .maradigma-boats-filters .md-label'        => 'color: {{VALUE}};',
                '{{WRAPPER}} .maradigma-boats-filters .md-sortby__label' => 'color: {{VALUE}};',
            ],
        ]);

        $this->add_responsive_control('labels_spacing', [
            'label'      => \esc_html__('Bottom spacing', 'maradigma'),
            'type'       => Controls_Manager::SLIDER,
            'size_units' => ['px', 'em', 'rem'],
            'selectors'  => [
                '{{WRAPPER}} .maradigma-boats-filters .md-label' => 'margin-bottom: {{SIZE}}{{UNIT}};',
            ],
        ]);

        $this->end_controls_section();

        // ─────────────────────────────────────────────
        // STYLE: Input & select fields
        // ─────────────────────────────────────────────
        $this->start_controls_section('section_style_fields', [
            'label'     => \esc_html__('Inputs & selects', 'maradigma'),
            'tab'       => Controls_Manager::TAB_STYLE,
            'condition' => ['show_filters' => '1'],
        ]);

        $this->add_group_control(
            Group_Control_Typography::get_type(),
            [
                'name'     => 'fields_typography',
                'selector' => '{{WRAPPER}} .maradigma-boats-filters .md-input, {{WRAPPER}} .maradigma-boats-filters .md-select',
            ]
        );

        $this->start_controls_tabs('tabs_fields_states');

        $this->start_controls_tab('tab_fields_normal', [
            'label' => \esc_html__('Normal', 'maradigma'),
        ]);

        $this->add_control('fields_text_color', [
            'label'     => \esc_html__('Text color', 'maradigma'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .maradigma-boats-filters .md-input'  => 'color: {{VALUE}};',
                '{{WRAPPER}} .maradigma-boats-filters .md-select' => 'color: {{VALUE}};',
            ],
        ]);

        $this->add_control('fields_placeholder_color', [
            'label'     => \esc_html__('Placeholder color', 'maradigma'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .maradigma-boats-filters .md-input::placeholder' => 'color: {{VALUE}};',
            ],
        ]);

        $this->add_control('fields_background', [
            'label'     => \esc_html__('Background color', 'maradigma'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .maradigma-boats-filters .md-input'  => 'background-color: {{VALUE}};',
                '{{WRAPPER}} .maradigma-boats-filters .md-select' => 'background-color: {{VALUE}};',
            ],
        ]);

        $this->end_controls_tab();

        $this->start_controls_tab('tab_fields_focus', [
            'label' => \esc_html__('Focus', 'maradigma'),
        ]);

        $this->add_control('fields_focus_text_color', [
            'label'     => \esc_html__('Text color', 'maradigma'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .maradigma-boats-filters .md-input:focus'  => 'color: {{VALUE}};',
                '{{WRAPPER}} .maradigma-boats-filters .md-select:focus' => 'color: {{VALUE}};',
            ],
        ]);

        $this->add_control('fields_focus_background', [
            'label'     => \esc_html__('Background color', 'maradigma'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .maradigma-boats-filters .md-input:focus'  => 'background-color: {{VALUE}};',
                '{{WRAPPER}} .maradigma-boats-filters .md-select:focus' => 'background-color: {{VALUE}};',
            ],
        ]);

        $this->add_control('fields_focus_border_color', [
            'label'     => \esc_html__('Border color', 'maradigma'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .maradigma-boats-filters .md-input:focus'  => 'border-color: {{VALUE}};',
                '{{WRAPPER}} .maradigma-boats-filters .md-select:focus' => 'border-color: {{VALUE}};',
            ],
        ]);

        $this->add_control('fields_focus_box_shadow_color', [
            'label'     => \esc_html__('Focus shadow color', 'maradigma'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .maradigma-boats-filters .md-input:focus'  => 'box-shadow: 0 0 0 1px {{VALUE}};',
                '{{WRAPPER}} .maradigma-boats-filters .md-select:focus' => 'box-shadow: 0 0 0 1px {{VALUE}};',
            ],
        ]);

        $this->end_controls_tab();

        $this->end_controls_tabs();

        $this->add_group_control(
            Group_Control_Border::get_type(),
            [
                'name'     => 'fields_border',
                'selector' => '{{WRAPPER}} .maradigma-boats-filters .md-input, {{WRAPPER}} .maradigma-boats-filters .md-select',
            ]
        );

        $this->add_responsive_control('fields_border_radius', [
            'label'      => \esc_html__('Border radius', 'maradigma'),
            'type'       => Controls_Manager::DIMENSIONS,
            'size_units' => ['px', '%'],
            'selectors'  => [
                '{{WRAPPER}} .maradigma-boats-filters .md-input, {{WRAPPER}} .maradigma-boats-filters .md-select' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
        ]);

        $this->add_responsive_control('fields_padding', [
            'label'      => \esc_html__('Padding', 'maradigma'),
            'type'       => Controls_Manager::DIMENSIONS,
            'size_units' => ['px', 'em', 'rem'],
            'selectors'  => [
                '{{WRAPPER}} .maradigma-boats-filters .md-input, {{WRAPPER}} .maradigma-boats-filters .md-select' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
        ]);

        $this->add_responsive_control('fields_min_height', [
            'label'      => \esc_html__('Min height', 'maradigma'),
            'type'       => Controls_Manager::SLIDER,
            'size_units' => ['px', 'em', 'rem'],
            'selectors'  => [
                '{{WRAPPER}} .maradigma-boats-filters .md-input, {{WRAPPER}} .maradigma-boats-filters .md-select' => 'min-height: {{SIZE}}{{UNIT}};',
            ],
        ]);

        $this->end_controls_section();

        // ─────────────────────────────────────────────
        // STYLE: Dropdown pills
        // ─────────────────────────────────────────────
        $this->start_controls_section('section_style_dropdown_pills', [
            'label'     => \esc_html__('Dropdown pills', 'maradigma'),
            'tab'       => Controls_Manager::TAB_STYLE,
            'condition' => ['show_filters' => '1'],
        ]);

        $this->add_group_control(
            Group_Control_Typography::get_type(),
            [
                'name'     => 'dropdown_pills_typography',
                'selector' => '{{WRAPPER}} .maradigma-boats-filters .md-filter-pill',
            ]
        );

        $this->start_controls_tabs('tabs_dropdown_pill_states');

        $this->start_controls_tab('tab_dropdown_pill_normal', [
            'label' => \esc_html__('Normal', 'maradigma'),
        ]);

        $this->add_control('dropdown_pill_text_color', [
            'label'     => \esc_html__('Text color', 'maradigma'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .maradigma-boats-filters .md-filter-pill' => 'color: {{VALUE}};',
            ],
        ]);

        $this->add_control('dropdown_pill_bg_color', [
            'label'     => \esc_html__('Background color', 'maradigma'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .maradigma-boats-filters .md-filter-pill' => 'background-color: {{VALUE}};',
            ],
        ]);

        $this->end_controls_tab();

        $this->start_controls_tab('tab_dropdown_pill_hover', [
            'label' => \esc_html__('Hover', 'maradigma'),
        ]);

        $this->add_control('dropdown_pill_hover_text_color', [
            'label'     => \esc_html__('Text color', 'maradigma'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .maradigma-boats-filters .md-filter-pill:hover' => 'color: {{VALUE}};',
            ],
        ]);

        $this->add_control('dropdown_pill_hover_bg_color', [
            'label'     => \esc_html__('Background color', 'maradigma'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .maradigma-boats-filters .md-filter-pill:hover' => 'background-color: {{VALUE}};',
            ],
        ]);

        $this->add_control('dropdown_pill_hover_border_color', [
            'label'     => \esc_html__('Border color', 'maradigma'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .maradigma-boats-filters .md-filter-pill:hover' => 'border-color: {{VALUE}};',
            ],
        ]);

        $this->end_controls_tab();

        $this->end_controls_tabs();

        $this->add_group_control(
            Group_Control_Border::get_type(),
            [
                'name'     => 'dropdown_pill_border',
                'selector' => '{{WRAPPER}} .maradigma-boats-filters .md-filter-pill',
            ]
        );

        $this->add_responsive_control('dropdown_pill_radius', [
            'label'      => \esc_html__('Border radius', 'maradigma'),
            'type'       => Controls_Manager::DIMENSIONS,
            'size_units' => ['px', '%'],
            'selectors'  => [
                '{{WRAPPER}} .maradigma-boats-filters .md-filter-pill' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
        ]);

        $this->add_responsive_control('dropdown_pill_padding', [
            'label'      => \esc_html__('Padding', 'maradigma'),
            'type'       => Controls_Manager::DIMENSIONS,
            'size_units' => ['px', 'em', 'rem'],
            'selectors'  => [
                '{{WRAPPER}} .maradigma-boats-filters .md-filter-pill' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
        ]);

        $this->end_controls_section();

        // ─────────────────────────────────────────────
        // STYLE: Dropdown panels
        // ─────────────────────────────────────────────
        $this->start_controls_section('section_style_dropdown_panels', [
            'label'     => \esc_html__('Dropdown panels', 'maradigma'),
            'tab'       => Controls_Manager::TAB_STYLE,
            'condition' => ['show_filters' => '1'],
        ]);

        $this->add_control('dropdown_panel_bg', [
            'label'     => \esc_html__('Background color', 'maradigma'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .maradigma-boats-filters .md-filter-menu' => 'background-color: {{VALUE}};',
            ],
        ]);

        $this->add_control('dropdown_panel_text_color', [
            'label'     => \esc_html__('Text color', 'maradigma'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .maradigma-boats-filters .md-filter-menu'        => 'color: {{VALUE}};',
                '{{WRAPPER}} .maradigma-boats-filters .md-filter-menu__title' => 'color: {{VALUE}};',
            ],
        ]);

        $this->add_group_control(
            Group_Control_Typography::get_type(),
            [
                'name'     => 'dropdown_panel_title_typography',
                'selector' => '{{WRAPPER}} .maradigma-boats-filters .md-filter-menu__title',
            ]
        );

        $this->add_group_control(
            Group_Control_Border::get_type(),
            [
                'name'     => 'dropdown_panel_border',
                'selector' => '{{WRAPPER}} .maradigma-boats-filters .md-filter-menu',
            ]
        );

        $this->add_responsive_control('dropdown_panel_radius', [
            'label'      => \esc_html__('Border radius', 'maradigma'),
            'type'       => Controls_Manager::DIMENSIONS,
            'size_units' => ['px', '%'],
            'selectors'  => [
                '{{WRAPPER}} .maradigma-boats-filters .md-filter-menu' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
        ]);

        $this->add_group_control(
            Group_Control_Box_Shadow::get_type(),
            [
                'name'     => 'dropdown_panel_shadow',
                'selector' => '{{WRAPPER}} .maradigma-boats-filters .md-filter-menu',
            ]
        );

        $this->add_responsive_control('dropdown_panel_padding_inner', [
            'label'      => \esc_html__('Inner padding', 'maradigma'),
            'type'       => Controls_Manager::DIMENSIONS,
            'size_units' => ['px', 'em', 'rem'],
            'selectors'  => [
                '{{WRAPPER}} .maradigma-boats-filters .md-filter-menu__inner'  => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                '{{WRAPPER}} .maradigma-boats-filters .md-filter-menu__footer' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
        ]);

        $this->end_controls_section();

        // ─────────────────────────────────────────────
        // STYLE: Checkboxes
        // ─────────────────────────────────────────────
        $this->start_controls_section('section_style_checkboxes', [
            'label'     => \esc_html__('Checkboxes', 'maradigma'),
            'tab'       => Controls_Manager::TAB_STYLE,
            'condition' => ['show_filters' => '1'],
        ]);

        $this->add_group_control(
            Group_Control_Typography::get_type(),
            [
                'name'     => 'checkboxes_typography',
                'selector' => '{{WRAPPER}} .maradigma-boats-filters .md-check span',
            ]
        );

        $this->add_control('checkboxes_text_color', [
            'label'     => \esc_html__('Text color', 'maradigma'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .maradigma-boats-filters .md-check span' => 'color: {{VALUE}};',
            ],
        ]);

        $this->add_control('checkboxes_accent_color', [
            'label'     => \esc_html__('Accent color', 'maradigma'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .maradigma-boats-filters .md-check input[type="checkbox"]' => 'accent-color: {{VALUE}};',
            ],
        ]);

        $this->add_responsive_control('checkboxes_gap', [
            'label'      => \esc_html__('Gap', 'maradigma'),
            'type'       => Controls_Manager::SLIDER,
            'size_units' => ['px', 'em', 'rem'],
            'selectors'  => [
                '{{WRAPPER}} .maradigma-boats-filters .md-check' => 'gap: {{SIZE}}{{UNIT}};',
            ],
        ]);

        $this->end_controls_section();

        // ─────────────────────────────────────────────
        // STYLE: Buttons
        // ─────────────────────────────────────────────
        $this->start_controls_section('section_style_buttons', [
            'label'     => \esc_html__('Buttons', 'maradigma'),
            'tab'       => Controls_Manager::TAB_STYLE,
            'condition' => ['show_filters' => '1'],
        ]);

        $this->add_group_control(
            Group_Control_Typography::get_type(),
            [
                'name'     => 'buttons_typography',
                'selector' => '{{WRAPPER}} .maradigma-boats-filters .md-btn, {{WRAPPER}} .maradigma-boats-filters .md-dd-clear, {{WRAPPER}} .maradigma-boats-filters .md-dd-apply',
            ]
        );

        $this->start_controls_tabs('tabs_buttons_states');

        $this->start_controls_tab('tab_buttons_normal', [
            'label' => \esc_html__('Normal', 'maradigma'),
        ]);

        $this->add_control('buttons_text_color', [
            'label'     => \esc_html__('Text color', 'maradigma'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .maradigma-boats-filters .md-btn'      => 'color: {{VALUE}};',
                '{{WRAPPER}} .maradigma-boats-filters .md-dd-clear' => 'color: {{VALUE}};',
                '{{WRAPPER}} .maradigma-boats-filters .md-dd-apply' => 'color: {{VALUE}};',
            ],
        ]);

        $this->add_control('buttons_bg_color', [
            'label'     => \esc_html__('Background color', 'maradigma'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .maradigma-boats-filters .md-btn'      => 'background-color: {{VALUE}};',
                '{{WRAPPER}} .maradigma-boats-filters .md-dd-clear' => 'background-color: {{VALUE}};',
                '{{WRAPPER}} .maradigma-boats-filters .md-dd-apply' => 'background-color: {{VALUE}};',
            ],
        ]);

        $this->end_controls_tab();

        $this->start_controls_tab('tab_buttons_hover', [
            'label' => \esc_html__('Hover', 'maradigma'),
        ]);

        $this->add_control('buttons_hover_text_color', [
            'label'     => \esc_html__('Text color', 'maradigma'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .maradigma-boats-filters .md-btn:hover'      => 'color: {{VALUE}};',
                '{{WRAPPER}} .maradigma-boats-filters .md-dd-clear:hover' => 'color: {{VALUE}};',
                '{{WRAPPER}} .maradigma-boats-filters .md-dd-apply:hover' => 'color: {{VALUE}};',
            ],
        ]);

        $this->add_control('buttons_hover_bg_color', [
            'label'     => \esc_html__('Background color', 'maradigma'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .maradigma-boats-filters .md-btn:hover'      => 'background-color: {{VALUE}};',
                '{{WRAPPER}} .maradigma-boats-filters .md-dd-clear:hover' => 'background-color: {{VALUE}};',
                '{{WRAPPER}} .maradigma-boats-filters .md-dd-apply:hover' => 'background-color: {{VALUE}};',
            ],
        ]);

        $this->add_control('buttons_hover_border_color', [
            'label'     => \esc_html__('Border color', 'maradigma'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .maradigma-boats-filters .md-btn:hover'      => 'border-color: {{VALUE}};',
                '{{WRAPPER}} .maradigma-boats-filters .md-dd-clear:hover' => 'border-color: {{VALUE}};',
                '{{WRAPPER}} .maradigma-boats-filters .md-dd-apply:hover' => 'border-color: {{VALUE}};',
            ],
        ]);

        $this->add_control('buttons_hover_animation', [
            'label' => \esc_html__('Hover animation', 'maradigma'),
            'type'  => Controls_Manager::HOVER_ANIMATION,
        ]);

        $this->end_controls_tab();

        $this->end_controls_tabs();

        $this->add_group_control(
            Group_Control_Border::get_type(),
            [
                'name'     => 'buttons_border',
                'selector' => '{{WRAPPER}} .maradigma-boats-filters .md-btn, {{WRAPPER}} .maradigma-boats-filters .md-dd-clear, {{WRAPPER}} .maradigma-boats-filters .md-dd-apply',
            ]
        );

        $this->add_responsive_control('buttons_radius', [
            'label'      => \esc_html__('Border radius', 'maradigma'),
            'type'       => Controls_Manager::DIMENSIONS,
            'size_units' => ['px', '%'],
            'selectors'  => [
                '{{WRAPPER}} .maradigma-boats-filters .md-btn, {{WRAPPER}} .maradigma-boats-filters .md-dd-clear, {{WRAPPER}} .maradigma-boats-filters .md-dd-apply' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
        ]);

        $this->add_responsive_control('buttons_padding', [
            'label'      => \esc_html__('Padding', 'maradigma'),
            'type'       => Controls_Manager::DIMENSIONS,
            'size_units' => ['px', 'em', 'rem'],
            'selectors'  => [
                '{{WRAPPER}} .maradigma-boats-filters .md-btn, {{WRAPPER}} .maradigma-boats-filters .md-dd-clear, {{WRAPPER}} .maradigma-boats-filters .md-dd-apply' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
        ]);

        $this->add_responsive_control('buttons_gap', [
            'label'      => \esc_html__('Button spacing', 'maradigma'),
            'type'       => Controls_Manager::SLIDER,
            'size_units' => ['px', 'em', 'rem'],
            'selectors'  => [
                '{{WRAPPER}} .maradigma-boats-filters .md-filterbar__left'  => 'gap: {{SIZE}}{{UNIT}};',
                '{{WRAPPER}} .maradigma-boats-filters .md-filter-menu__footer' => 'gap: {{SIZE}}{{UNIT}};',
            ],
        ]);

        $this->end_controls_section();

        // ─────────────────────────────────────────────
        // STYLE: More filters button
        // ─────────────────────────────────────────────
        $this->start_controls_section('section_style_more_filters_button', [
            'label'     => \esc_html__('More filters button', 'maradigma'),
            'tab'       => Controls_Manager::TAB_STYLE,
            'condition' => [
                'show_filters' => '1',
                'show_more_filters_button' => '1',
            ],
        ]);

        $this->add_group_control(
            Group_Control_Typography::get_type(),
            [
                'name'     => 'more_filters_btn_typography',
                'selector' => '{{WRAPPER}} .maradigma-boats-filters .md-filterbar__more',
            ]
        );

        $this->start_controls_tabs('tabs_more_filters_btn_states');

        $this->start_controls_tab('tab_more_filters_btn_normal', [
            'label' => \esc_html__('Normal', 'maradigma'),
        ]);

        $this->add_control('more_filters_btn_text_color', [
            'label'     => \esc_html__('Text color', 'maradigma'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .maradigma-boats-filters .md-filterbar__more' => 'color: {{VALUE}};',
            ],
        ]);

        $this->add_control('more_filters_btn_bg_color', [
            'label'     => \esc_html__('Background color', 'maradigma'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .maradigma-boats-filters .md-filterbar__more' => 'background-color: {{VALUE}};',
            ],
        ]);

        $this->end_controls_tab();

        $this->start_controls_tab('tab_more_filters_btn_hover', [
            'label' => \esc_html__('Hover', 'maradigma'),
        ]);

        $this->add_control('more_filters_btn_hover_text_color', [
            'label'     => \esc_html__('Text color', 'maradigma'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .maradigma-boats-filters .md-filterbar__more:hover' => 'color: {{VALUE}};',
            ],
        ]);

        $this->add_control('more_filters_btn_hover_bg_color', [
            'label'     => \esc_html__('Background color', 'maradigma'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .maradigma-boats-filters .md-filterbar__more:hover' => 'background-color: {{VALUE}};',
            ],
        ]);

        $this->add_control('more_filters_btn_hover_border_color', [
            'label'     => \esc_html__('Border color', 'maradigma'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .maradigma-boats-filters .md-filterbar__more:hover' => 'border-color: {{VALUE}};',
            ],
        ]);

        $this->end_controls_tab();

        $this->end_controls_tabs();

        $this->add_group_control(
            Group_Control_Border::get_type(),
            [
                'name'     => 'more_filters_btn_border',
                'selector' => '{{WRAPPER}} .maradigma-boats-filters .md-filterbar__more',
            ]
        );

        $this->add_responsive_control('more_filters_btn_radius', [
            'label'      => \esc_html__('Border radius', 'maradigma'),
            'type'       => Controls_Manager::DIMENSIONS,
            'size_units' => ['px', '%'],
            'selectors'  => [
                '{{WRAPPER}} .maradigma-boats-filters .md-filterbar__more' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
        ]);

        $this->add_responsive_control('more_filters_btn_padding', [
            'label'      => \esc_html__('Padding', 'maradigma'),
            'type'       => Controls_Manager::DIMENSIONS,
            'size_units' => ['px', 'em', 'rem'],
            'selectors'  => [
                '{{WRAPPER}} .maradigma-boats-filters .md-filterbar__more' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
        ]);

        $this->end_controls_section();

        // ─────────────────────────────────────────────
        // STYLE: Drawer / offcanvas
        // ─────────────────────────────────────────────
        $this->start_controls_section('section_style_drawer', [
            'label'     => \esc_html__('Drawer / Offcanvas', 'maradigma'),
            'tab'       => Controls_Manager::TAB_STYLE,
            'condition' => [
                'show_filters' => '1',
                'show_more_filters_button' => '1',
            ],
        ]);

        $this->add_control('drawer_overlay_color', [
            'label'     => \esc_html__('Overlay color', 'maradigma'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .maradigma-boats-filters .md-drawer-overlay' => 'background-color: {{VALUE}};',
            ],
        ]);

        $this->add_control('drawer_background', [
            'label'     => \esc_html__('Drawer background', 'maradigma'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .maradigma-boats-filters .md-drawer' => 'background-color: {{VALUE}};',
            ],
        ]);

        $this->add_control('drawer_text_color', [
            'label'     => \esc_html__('Text color', 'maradigma'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .maradigma-boats-filters .md-drawer'        => 'color: {{VALUE}};',
                '{{WRAPPER}} .maradigma-boats-filters .md-drawer__title' => 'color: {{VALUE}};',
            ],
        ]);

        $this->add_group_control(
            Group_Control_Typography::get_type(),
            [
                'name'     => 'drawer_title_typography',
                'selector' => '{{WRAPPER}} .maradigma-boats-filters .md-drawer__title',
            ]
        );

        $this->add_group_control(
            Group_Control_Border::get_type(),
            [
                'name'     => 'drawer_border',
                'selector' => '{{WRAPPER}} .maradigma-boats-filters .md-drawer',
            ]
        );

        $this->add_responsive_control('drawer_radius', [
            'label'      => \esc_html__('Border radius', 'maradigma'),
            'type'       => Controls_Manager::DIMENSIONS,
            'size_units' => ['px', '%'],
            'selectors'  => [
                '{{WRAPPER}} .maradigma-boats-filters .md-drawer' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
        ]);

        $this->add_group_control(
            Group_Control_Box_Shadow::get_type(),
            [
                'name'     => 'drawer_shadow',
                'selector' => '{{WRAPPER}} .maradigma-boats-filters .md-drawer',
            ]
        );

        $this->add_responsive_control('drawer_width', [
            'label'      => \esc_html__('Width', 'maradigma'),
            'type'       => Controls_Manager::SLIDER,
            'size_units' => ['px', '%', 'vw'],
            'selectors'  => [
                '{{WRAPPER}} .maradigma-boats-filters .md-drawer' => 'width: {{SIZE}}{{UNIT}};',
            ],
        ]);

        $this->add_responsive_control('drawer_body_padding', [
            'label'      => \esc_html__('Body padding', 'maradigma'),
            'type'       => Controls_Manager::DIMENSIONS,
            'size_units' => ['px', 'em', 'rem'],
            'selectors'  => [
                '{{WRAPPER}} .maradigma-boats-filters .md-drawer__header' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                '{{WRAPPER}} .maradigma-boats-filters .md-drawer__body'   => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                '{{WRAPPER}} .maradigma-boats-filters .md-drawer__footer' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
        ]);

        $this->end_controls_section();

        // ─────────────────────────────────────────────
        // STYLE: Results grid
        // ─────────────────────────────────────────────
        $this->start_controls_section('section_style_results', [
            'label' => \esc_html__('Results grid', 'maradigma'),
            'tab'   => Controls_Manager::TAB_STYLE,
        ]);

        $this->add_responsive_control('results_columns', [
            'label'   => \esc_html__('Columns', 'maradigma'),
            'type'    => Controls_Manager::SELECT,
            'default' => '3',
            'tablet_default' => '2',
            'mobile_default' => '1',
            'options' => [
                '1' => '1',
                '2' => '2',
                '3' => '3',
                '4' => '4',
            ],
            'selectors' => [
                '{{WRAPPER}} .maradigma-boats-archive' => '--mrd-results-columns: {{VALUE}};',
            ],
        ]);

        $this->add_responsive_control('results_gap', [
            'label'      => \esc_html__('Grid gap', 'maradigma'),
            'type'       => Controls_Manager::SLIDER,
            'size_units' => ['px', 'em', 'rem'],
            'selectors'  => [
                '{{WRAPPER}} .maradigma-boats' => 'gap: {{SIZE}}{{UNIT}};',
            ],
        ]);

        $this->add_responsive_control('results_margin_top', [
            'label'      => \esc_html__('Top spacing', 'maradigma'),
            'type'       => Controls_Manager::SLIDER,
            'size_units' => ['px', 'em', 'rem'],
            'selectors'  => [
                '{{WRAPPER}} .maradigma-boats' => 'margin-top: {{SIZE}}{{UNIT}};',
            ],
        ]);

        $this->end_controls_section();
        // ─────────────────────────────────────────────
        // STYLE: Boat cards
        // ─────────────────────────────────────────────
        $this->start_controls_section('section_style_cards', [
            'label' => \esc_html__('Boat cards', 'maradigma'),
            'tab'   => Controls_Manager::TAB_STYLE,
        ]);

        $this->add_control('cards_note', [
            'type'            => Controls_Manager::RAW_HTML,
            'raw'             => \esc_html__('These controls affect the default card selectors rendered by the shortcode/card engine.', 'maradigma'),
            'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
        ]);

        $this->add_control('cards_background', [
            'label'     => \esc_html__('Background color', 'maradigma'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .maradigma-boats .maradigma-boat-card' => 'background-color: {{VALUE}};',
            ],
        ]);

        $this->add_control('cards_text_color', [
            'label'     => \esc_html__('Text color', 'maradigma'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .maradigma-boats .maradigma-boat-card'   => 'color: {{VALUE}};',
                '{{WRAPPER}} .maradigma-boats .maradigma-boat-card *' => 'color: {{VALUE}};',
            ],
        ]);

        $this->add_group_control(
            Group_Control_Border::get_type(),
            [
                'name'     => 'cards_border',
                'selector' => '{{WRAPPER}} .maradigma-boats .maradigma-boat-card',
            ]
        );

        $this->add_responsive_control('cards_radius', [
            'label'      => \esc_html__('Border radius', 'maradigma'),
            'type'       => Controls_Manager::DIMENSIONS,
            'size_units' => ['px', '%'],
            'selectors'  => [
                '{{WRAPPER}} .maradigma-boats .maradigma-boat-card' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}}; overflow:hidden;',
            ],
        ]);

        $this->add_group_control(
            Group_Control_Box_Shadow::get_type(),
            [
                'name'     => 'cards_shadow',
                'selector' => '{{WRAPPER}} .maradigma-boats .maradigma-boat-card',
            ]
        );

        $this->add_responsive_control('cards_padding', [
            'label'      => \esc_html__('Inner padding', 'maradigma'),
            'type'       => Controls_Manager::DIMENSIONS,
            'size_units' => ['px', 'em', 'rem'],
            'selectors'  => [
                '{{WRAPPER}} .maradigma-boats .maradigma-boat-card' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
        ]);

        // ─────────────────────────────────────────────
        // STYLE: Card image overlay
        // ─────────────────────────────────────────────
        $this->add_control('cards_image_overlay_heading', [
            'type'            => Controls_Manager::RAW_HTML,
            'raw'             => '<hr style="margin:12px 0;"><strong>' . \esc_html__('Card image overlay', 'maradigma') . '</strong>',
            'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
        ]);

        $this->add_control('cards_image_overlay_enable', [
            'label'        => \esc_html__('Enable image overlay', 'maradigma'),
            'type'         => Controls_Manager::SWITCHER,
            'label_on'     => \esc_html__('Yes', 'maradigma'),
            'label_off'    => \esc_html__('No', 'maradigma'),
            'return_value' => '1',
            'default'      => '1',
        ]);

        $this->add_control('cards_image_overlay_type', [
            'label'     => \esc_html__('Overlay type', 'maradigma'),
            'type'      => Controls_Manager::SELECT,
            'default'   => 'gradient',
            'options'   => [
                'solid'    => \esc_html__('Solid', 'maradigma'),
                'gradient' => \esc_html__('Gradient', 'maradigma'),
            ],
            'condition' => [
                'cards_image_overlay_enable' => '1',
            ],
        ]);

        $this->add_control('cards_image_overlay_color_1', [
            'label'     => \esc_html__('Overlay color 1', 'maradigma'),
            'type'      => Controls_Manager::COLOR,
            'default'   => 'rgba(0,0,0,0.55)',
            'selectors' => [
                '{{WRAPPER}}' => '--mrd-card-image-overlay-color-1: {{VALUE}};',
            ],
            'condition' => [
                'cards_image_overlay_enable' => '1',
            ],
        ]);

        $this->add_control('cards_image_overlay_color_2', [
            'label'     => \esc_html__('Overlay color 2', 'maradigma'),
            'type'      => Controls_Manager::COLOR,
            'default'   => 'rgba(0,0,0,0)',
            'selectors' => [
                '{{WRAPPER}}' => '--mrd-card-image-overlay-color-2: {{VALUE}};',
            ],
            'condition' => [
                'cards_image_overlay_enable' => '1',
                'cards_image_overlay_type'   => 'gradient',
            ],
        ]);

        $this->add_control('cards_image_overlay_direction', [
            'label'     => \esc_html__('Gradient direction', 'maradigma'),
            'type'      => Controls_Manager::SELECT,
            'default'   => 'to top',
            'options'   => [
                'to top'    => \esc_html__('Bottom to top', 'maradigma'),
                'to bottom' => \esc_html__('Top to bottom', 'maradigma'),
                'to right'  => \esc_html__('Left to right', 'maradigma'),
                'to left'   => \esc_html__('Right to left', 'maradigma'),
            ],
            'selectors' => [
                '{{WRAPPER}}' => '--mrd-card-image-overlay-direction: {{VALUE}};',
            ],
            'condition' => [
                'cards_image_overlay_enable' => '1',
                'cards_image_overlay_type'   => 'gradient',
            ],
        ]);

        $this->add_responsive_control('cards_image_overlay_opacity', [
            'label'      => \esc_html__('Overlay opacity', 'maradigma'),
            'type'       => Controls_Manager::SLIDER,
            'size_units' => [''],
            'range'      => [
                '' => [
                    'min'  => 0,
                    'max'  => 1,
                    'step' => 0.05,
                ],
            ],
            'default' => [
                'size' => 1,
            ],
            'selectors' => [
                '{{WRAPPER}} .maradigma-boats .maradigma-boat-card__image-overlay' => 'opacity: {{SIZE}};',
            ],
            'condition' => [
                'cards_image_overlay_enable' => '1',
            ],
        ]);

        $this->add_responsive_control('cards_image_overlay_radius', [
            'label'      => \esc_html__('Overlay border radius', 'maradigma'),
            'type'       => Controls_Manager::DIMENSIONS,
            'size_units' => ['px', '%'],
            'selectors'  => [
                '{{WRAPPER}} .maradigma-boats .maradigma-boat-card__image-overlay' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
            'condition' => [
                'cards_image_overlay_enable' => '1',
            ],
        ]);

        $this->add_control('cards_image_overlay_blend_mode', [
            'label'     => \esc_html__('Overlay blend mode', 'maradigma'),
            'type'      => Controls_Manager::SELECT,
            'default'   => 'normal',
            'options'   => [
                'normal'     => \esc_html__('Normal', 'maradigma'),
                'multiply'   => \esc_html__('Multiply', 'maradigma'),
                'overlay'    => \esc_html__('Overlay', 'maradigma'),
                'darken'     => \esc_html__('Darken', 'maradigma'),
                'soft-light' => \esc_html__('Soft light', 'maradigma'),
            ],
            'selectors' => [
                '{{WRAPPER}} .maradigma-boats .maradigma-boat-card__image-overlay' => 'mix-blend-mode: {{VALUE}};',
            ],
            'condition' => [
                'cards_image_overlay_enable' => '1',
            ],
        ]);

        $this->add_control('cards_image_overlay_display', [
            'type'      => Controls_Manager::HIDDEN,
            'default'   => 'block',
            'selectors' => [
                '{{WRAPPER}} .maradigma-boats .maradigma-boat-card__image-overlay' => 'display: block;',
            ],
            'condition' => [
                'cards_image_overlay_enable' => '1',
            ],
        ]);

        $this->add_control('cards_image_overlay_display_off', [
            'type'      => Controls_Manager::HIDDEN,
            'default'   => 'none',
            'selectors' => [
                '{{WRAPPER}} .maradigma-boats .maradigma-boat-card__image-overlay' => 'display: none;',
            ],
            'condition' => [
                'cards_image_overlay_enable!' => '1',
            ],
        ]);

        $this->end_controls_section();

        // ─────────────────────────────────────────────
        // STYLE: Pagination
        // ─────────────────────────────────────────────
        $this->start_controls_section('section_style_pagination', [
            'label' => \esc_html__('Pagination', 'maradigma'),
            'tab'   => Controls_Manager::TAB_STYLE,
        ]);

        $this->add_responsive_control('pagination_alignment', [
            'label'   => \esc_html__('Alignment', 'maradigma'),
            'type'    => Controls_Manager::CHOOSE,
            'options' => [
                'left' => [
                    'title' => \esc_html__('Left', 'maradigma'),
                    'icon'  => 'eicon-text-align-left',
                ],
                'center' => [
                    'title' => \esc_html__('Center', 'maradigma'),
                    'icon'  => 'eicon-text-align-center',
                ],
                'right' => [
                    'title' => \esc_html__('Right', 'maradigma'),
                    'icon'  => 'eicon-text-align-right',
                ],
            ],
            'selectors' => [
                '{{WRAPPER}} .maradigma-pagination' => 'text-align: {{VALUE}};',
            ],
        ]);

        $this->add_responsive_control('pagination_spacing_top', [
            'label'      => \esc_html__('Top spacing', 'maradigma'),
            'type'       => Controls_Manager::SLIDER,
            'size_units' => ['px', 'em', 'rem'],
            'selectors'  => [
                '{{WRAPPER}} .maradigma-pagination' => 'margin-top: {{SIZE}}{{UNIT}};',
            ],
        ]);

        $this->add_group_control(
            Group_Control_Typography::get_type(),
            [
                'name'     => 'pagination_typography',
                'selector' => '{{WRAPPER}} .maradigma-pagination a, {{WRAPPER}} .maradigma-pagination span',
            ]
        );

        $this->start_controls_tabs('tabs_pagination_states');

        $this->start_controls_tab('tab_pagination_normal', [
            'label' => \esc_html__('Normal', 'maradigma'),
        ]);

        $this->add_control('pagination_text_color', [
            'label'     => \esc_html__('Text color', 'maradigma'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .maradigma-pagination a, {{WRAPPER}} .maradigma-pagination span' => 'color: {{VALUE}};',
            ],
        ]);

        $this->add_control('pagination_bg_color', [
            'label'     => \esc_html__('Background color', 'maradigma'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .maradigma-pagination a, {{WRAPPER}} .maradigma-pagination span' => 'background-color: {{VALUE}};',
            ],
        ]);

        $this->end_controls_tab();

        $this->start_controls_tab('tab_pagination_hover', [
            'label' => \esc_html__('Hover / Current', 'maradigma'),
        ]);

        $this->add_control('pagination_hover_text_color', [
            'label'     => \esc_html__('Text color', 'maradigma'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .maradigma-pagination a:hover' => 'color: {{VALUE}};',
                '{{WRAPPER}} .maradigma-pagination .current' => 'color: {{VALUE}};',
            ],
        ]);

        $this->add_control('pagination_hover_bg_color', [
            'label'     => \esc_html__('Background color', 'maradigma'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .maradigma-pagination a:hover' => 'background-color: {{VALUE}};',
                '{{WRAPPER}} .maradigma-pagination .current' => 'background-color: {{VALUE}};',
            ],
        ]);

        $this->add_control('pagination_hover_border_color', [
            'label'     => \esc_html__('Border color', 'maradigma'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .maradigma-pagination a:hover' => 'border-color: {{VALUE}};',
                '{{WRAPPER}} .maradigma-pagination .current' => 'border-color: {{VALUE}};',
            ],
        ]);

        $this->end_controls_tab();

        $this->end_controls_tabs();

        $this->add_group_control(
            Group_Control_Border::get_type(),
            [
                'name'     => 'pagination_border',
                'selector' => '{{WRAPPER}} .maradigma-pagination a, {{WRAPPER}} .maradigma-pagination span',
            ]
        );

        $this->add_responsive_control('pagination_radius', [
            'label'      => \esc_html__('Border radius', 'maradigma'),
            'type'       => Controls_Manager::DIMENSIONS,
            'size_units' => ['px', '%'],
            'selectors'  => [
                '{{WRAPPER}} .maradigma-pagination a, {{WRAPPER}} .maradigma-pagination span' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
        ]);

        $this->add_responsive_control('pagination_padding', [
            'label'      => \esc_html__('Padding', 'maradigma'),
            'type'       => Controls_Manager::DIMENSIONS,
            'size_units' => ['px', 'em', 'rem'],
            'selectors'  => [
                '{{WRAPPER}} .maradigma-pagination a, {{WRAPPER}} .maradigma-pagination span' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
        ]);

        $this->add_responsive_control('pagination_gap', [
            'label'      => \esc_html__('Gap', 'maradigma'),
            'type'       => Controls_Manager::SLIDER,
            'size_units' => ['px', 'em', 'rem'],
            'selectors'  => [
                '{{WRAPPER}} .maradigma-pagination ul' => 'gap: {{SIZE}}{{UNIT}};',
            ],
        ]);

        $this->end_controls_section();

        // ─────────────────────────────────────────────
        // STYLE: Empty state
        // ─────────────────────────────────────────────
        $this->start_controls_section('section_style_empty_state', [
            'label' => \esc_html__('Empty state', 'maradigma'),
            'tab'   => Controls_Manager::TAB_STYLE,
        ]);

        $this->add_group_control(
            Group_Control_Typography::get_type(),
            [
                'name'     => 'empty_typography',
                'selector' => '{{WRAPPER}} .maradigma-boats-shortcode-list > p',
            ]
        );

        $this->add_control('empty_text_color', [
            'label'     => \esc_html__('Text color', 'maradigma'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .maradigma-boats-shortcode-list > p' => 'color: {{VALUE}};',
            ],
        ]);

        $this->add_control('empty_background', [
            'label'     => \esc_html__('Background color', 'maradigma'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .maradigma-boats-shortcode-list > p' => 'background-color: {{VALUE}};',
            ],
        ]);

        $this->add_group_control(
            Group_Control_Border::get_type(),
            [
                'name'     => 'empty_border',
                'selector' => '{{WRAPPER}} .maradigma-boats-shortcode-list > p',
            ]
        );

        $this->add_responsive_control('empty_radius', [
            'label'      => \esc_html__('Border radius', 'maradigma'),
            'type'       => Controls_Manager::DIMENSIONS,
            'size_units' => ['px', '%'],
            'selectors'  => [
                '{{WRAPPER}} .maradigma-boats-shortcode-list > p' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
        ]);

        $this->add_responsive_control('empty_padding', [
            'label'      => \esc_html__('Padding', 'maradigma'),
            'type'       => Controls_Manager::DIMENSIONS,
            'size_units' => ['px', 'em', 'rem'],
            'selectors'  => [
                '{{WRAPPER}} .maradigma-boats-shortcode-list > p' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
        ]);

        $this->add_responsive_control('empty_alignment', [
            'label'   => \esc_html__('Text alignment', 'maradigma'),
            'type'    => Controls_Manager::CHOOSE,
            'options' => [
                'left' => [
                    'title' => \esc_html__('Left', 'maradigma'),
                    'icon'  => 'eicon-text-align-left',
                ],
                'center' => [
                    'title' => \esc_html__('Center', 'maradigma'),
                    'icon'  => 'eicon-text-align-center',
                ],
                'right' => [
                    'title' => \esc_html__('Right', 'maradigma'),
                    'icon'  => 'eicon-text-align-right',
                ],
            ],
            'selectors' => [
                '{{WRAPPER}} .maradigma-boats-shortcode-list > p' => 'text-align: {{VALUE}};',
            ],
        ]);

        $this->end_controls_section();
    }

    /**
     * In range mode one visible "Availability dates" control owns both hidden dates.
     *
     * @param list<string> $fields
     * @return list<string>
     */
    private static function normalizeArchiveDateRangeFields(array $fields): array
    {
        $normalized = [];
        $hasDateRange = \in_array('date_start', $fields, true) || \in_array('date_end', $fields, true);

        foreach ($fields as $field) {
            $field = (string) $field;

            if ($field === 'date_start' || $field === 'date_end') {
                if ($hasDateRange && !\in_array('date_start', $normalized, true)) {
                    $normalized[] = 'date_start';
                }

                continue;
            }

            if (!\in_array($field, $normalized, true)) {
                $normalized[] = $field;
            }
        }

        return $normalized;
    }

    protected function render(): void
    {
        $settings = $this->get_settings_for_display();

        $rows = $settings['filters_ui_fields_repeater'] ?? [];
        if (!\is_array($rows)) {
            $rows = [];
        }

        $fields = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $enabledRaw = $row['enabled'] ?? '1';
            $enabled = \trim((string) $enabledRaw);
            $isEnabled = ($enabled === '' || $enabled === '1' || $enabled === 'yes');

            if (!$isEnabled) {
                continue;
            }

            $field = \trim((string) ($row['field'] ?? ''));
            if ($field === '') {
                continue;
            }

            if (!\in_array($field, $fields, true)) {
                $fields[] = $field;
            }
        }

        if (empty($fields)) {
            $fields = ['date_start', 'boat_capacity', 'min_price', 'max_price'];
        }

        $datePickerMode = ((string) ($settings['date_picker_mode'] ?? 'range') === 'separate') ? 'separate' : 'range';
        if ($datePickerMode === 'range') {
            $fields = self::normalizeArchiveDateRangeFields($fields);
        }

        $rowsOff = $settings['filters_ui_fields_offcanvas_repeater'] ?? [];
        if (!\is_array($rowsOff)) {
            $rowsOff = [];
        }

        $fieldsOffcanvas = [];
        foreach ($rowsOff as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $enabledRaw = $row['enabled'] ?? '1';
            $enabled = \trim((string) $enabledRaw);
            $isEnabled = ($enabled === '' || $enabled === '1' || $enabled === 'yes');

            if (!$isEnabled) {
                continue;
            }

            $field = \trim((string) ($row['field'] ?? ''));
            if ($field === '') {
                continue;
            }

            if (!\in_array($field, $fieldsOffcanvas, true)) {
                $fieldsOffcanvas[] = $field;
            }
        }

        if (empty($fieldsOffcanvas)) {
            $fieldsOffcanvas = ['boat_type_id', 'builders', 'ids_gi'];
        }

        if ($datePickerMode === 'range') {
            $fieldsOffcanvas = self::normalizeArchiveDateRangeFields($fieldsOffcanvas);
        }

        $minPrice = \trim((string) ($settings['min_price'] ?? ''));
        $maxPrice = \trim((string) ($settings['max_price'] ?? ''));

        if ($minPrice === '' || !\is_numeric($minPrice)) {
            $minPrice = '0';
        }

        if ($maxPrice === '' || !\is_numeric($maxPrice)) {
            $maxPrice = '5000';
        }

        if ((float) $maxPrice <= (float) $minPrice) {
            $maxPrice = (string) ((float) $minPrice + 1000);
        }

        $showMoreBtn = ((string) ($settings['show_more_filters_button'] ?? '') === '1');

        $moreBtnText = (string) ($settings['more_filters_button_text'] ?? '');
        $moreBtnText = \trim($moreBtnText);
        if ($moreBtnText === '') {
            $moreBtnText = \esc_html__('More filters', 'maradigma');
        }

        $overlayEnabled = ((string) ($settings['cards_image_overlay_enable'] ?? '1') === '1');

        $overlayType = (string) ($settings['cards_image_overlay_type'] ?? 'gradient');
        $overlayType = \in_array($overlayType, ['gradient', 'solid'], true) ? $overlayType : 'gradient';

        $atts = [
            'id_group' => 'boats',

            'card'                 => (string) ($settings['card'] ?? ''),
            'image_token'          => (string) ($settings['image_token'] ?? 'image_main'),
            'limit_services'       => (string) ($settings['limit_services'] ?? '10'),
            'order_by'             => (string) ($settings['order_by'] ?? '0'),
            'show_filters'         => (string) ($settings['show_filters'] ?? ''),
            'autosubmit_filters'   => (string) ($settings['autosubmit_filters'] ?? ''),
            'allow_url_filters'    => (string) ($settings['allow_url_filters'] ?? ''),

            'filters_ui_fields'           => \implode(',', $fields),
            'filters_ui_fields_offcanvas' => \implode(',', $fieldsOffcanvas),
            'filters_ui_layout'           => (string) ($settings['filters_ui_layout'] ?? 'horizontal'),
            'filters_ui_submit_mode'      => (string) ($settings['filters_ui_submit_mode'] ?? 'auto'),
            'filters_ui_show_reset'       => (string) ($settings['filters_ui_show_reset'] ?? '1'),

            'show_more_filters_button'     => $showMoreBtn ? '1' : '',
            'more_filters_button_text'     => $moreBtnText,
            'more_filters_offcanvas_title' => (string) ($settings['more_filters_offcanvas_title'] ?? ''),

            'term'             => (string) ($settings['term'] ?? ''),
            'boat_capacity'    => (string) ($settings['boat_capacity'] ?? ''),
            'featured'         => (string) ($settings['featured'] ?? ''),
            'ins_book'         => (string) ($settings['ins_book'] ?? ''),
            'min_price'        => $minPrice,
            'max_price'        => $maxPrice,

            'date_start'       => '',
            'date_end'         => '',

            'boat_type_id'     => (string) ($settings['boat_type_id'] ?? ''),
            'builders'         => (string) ($settings['builders'] ?? ''),
            'ids_gi'           => (string) ($settings['ids_gi'] ?? ''),
            'date_picker_mode' => $datePickerMode,
        ];

        $priceFmt = [
            'currency_display' => (string) ($settings['price_currency_display'] ?? 'symbol'),
            'decimals_mode'    => (string) ($settings['price_decimals_mode'] ?? 'auto'),
            'thousands_sep'    => (string) ($settings['price_thousands_sep'] ?? '.'),
            'decimal_sep'      => (string) ($settings['price_decimal_sep'] ?? ','),
        ];

        $wrapperClasses = [
            'maradigma-boats-archive-widget',
            $overlayEnabled ? 'has-card-image-overlay' : 'has-no-card-image-overlay',
            'overlay-type-' . \sanitize_html_class($overlayType),
        ];

        $pushed = false;

        if (
            \class_exists(\Maradigma\BoatCardEngine::class)
            && \method_exists(\Maradigma\BoatCardEngine::class, 'pushMoneyFormat')
            && \method_exists(\Maradigma\BoatCardEngine::class, 'popMoneyFormat')
        ) {
            \Maradigma\BoatCardEngine::pushMoneyFormat($priceFmt);
            $pushed = true;
        }

        try {
            echo '<div class="' . \esc_attr(\implode(' ', $wrapperClasses)) . '">';
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shortcode renderer returns plugin-generated markup with escaped attributes/content, including form controls.
            echo ShortcodeRegistry::renderBoatsListing($atts);
            echo '</div>';
        } finally {
            if ($pushed) {
                \Maradigma\BoatCardEngine::popMoneyFormat();
            }
        }
    }
}

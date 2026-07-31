<?php
// integrations/Elementor/Widgets/SingleBoat/BoatSpecsWidget.php

declare(strict_types=1);

namespace Maradigma\Integrations\Elementor\Widgets\SingleBoat;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Background;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Box_Shadow;
use Elementor\Group_Control_Typography;
use Elementor\Repeater;
use Maradigma\Support\BoatSpecsRenderer;

/**
 * Elementor Widget: Maradigma Boat Specs
 *
 * Displays a configurable list of boat specifications (e.g., builder, model, length, beam, etc.)
 * using the "Single Boat" context resolved by {@see BaseSingleBoatWidget::resolveContext()}.
 *
 * This widget is the Elementor equivalent of the {@code [maradigma_boat_specs]} shortcode and
 * delegates the final data formatting and HTML generation to {@see BoatSpecsRenderer}.
 *
 * Design philosophy:
 * - Content tab controls what is rendered.
 * - Style tab controls how it looks using standard Elementor selectors.
 *
 * HTML contract expected from BoatSpecsRenderer:
 * - .maradigma-boat-specs
 * - .maradigma-boat-specs--two-cols / .maradigma-boat-specs--list
 * - .maradigma-boat-specs__item
 * - .maradigma-boat-specs__icon
 * - .maradigma-boat-specs__content
 * - .maradigma-boat-specs__label
 * - .maradigma-boat-specs__value
 */
final class BoatSpecsWidget extends BaseSingleBoatWidget
{
    /**
     * Returns the widget's stable Elementor identifier.
     */
    public function get_name(): string
    {
        return 'maradigma_boat_specs';
    }

    /**
     * Returns the widget title shown in Elementor.
     */
    public function get_title(): string
    {
        return \esc_html__('Maradigma Boat Specs', 'maradigma');
    }

    /**
     * Returns the Elementor icon identifier for the widget.
     */
    public function get_icon(): string
    {
        return 'eicon-editor-list-ul';
    }

    /**
     * Returns the Elementor categories assigned to the widget.
     */
    public function get_categories(): array
    {
        return ['maradigma'];
    }

    /**
     * Registers the controls exposed by the widget.
     */
    protected function register_controls(): void
    {
        $this->registerContentControls();
        $this->registerStyleWrapperControls();
        $this->registerStyleItemControls();
        $this->registerStyleIconControls();
        $this->registerStyleLabelControls();
        $this->registerStyleValueControls();
    }

    /**
     * Registers content controls.
     */
    private function registerContentControls(): void
    {
        $this->start_controls_section(
            'section_content',
            [
                'label' => \esc_html__('Content', 'maradigma'),
                'tab'   => Controls_Manager::TAB_CONTENT,
            ]
        );

        $this->add_control(
            'layout',
            [
                'label'   => \esc_html__('Layout', 'maradigma'),
                'type'    => Controls_Manager::SELECT,
                'default' => 'two_cols',
                'options' => [
                    'two_cols' => \esc_html__('Two columns', 'maradigma'),
                    'list'     => \esc_html__('List', 'maradigma'),
                ],
            ]
        );

        $this->add_control(
            'show_labels',
            [
                'label'        => \esc_html__('Show labels', 'maradigma'),
                'type'         => Controls_Manager::SWITCHER,
                'label_on'     => \esc_html__('Yes', 'maradigma'),
                'label_off'    => \esc_html__('No', 'maradigma'),
                'return_value' => 'yes',
                'default'      => 'yes',
            ]
        );

        $this->add_control(
            'show_icons',
            [
                'label'        => \esc_html__('Show icons', 'maradigma'),
                'type'         => Controls_Manager::SWITCHER,
                'label_on'     => \esc_html__('Yes', 'maradigma'),
                'label_off'    => \esc_html__('No', 'maradigma'),
                'return_value' => 'yes',
                'default'      => 'yes',
            ]
        );

        $repeater = new Repeater();

        $repeater->add_control(
            'field',
            [
                'label'   => \esc_html__('Field', 'maradigma'),
                'type'    => Controls_Manager::SELECT,
                'default' => '',
                'options' => $this->getFieldOptions(),
            ]
        );

        $repeater->add_control(
            'custom_key',
            [
                'label'       => \esc_html__('Custom key', 'maradigma'),
                'type'        => Controls_Manager::TEXT,
                'placeholder' => 'boat_length',
                'condition'   => [
                    'field' => 'custom',
                ],
            ]
        );

        $repeater->add_control(
            'label',
            [
                'label'       => \esc_html__('Label', 'maradigma'),
                'type'        => Controls_Manager::TEXT,
                'default'     => '',
                'placeholder' => \esc_html__('e.g. Length', 'maradigma'),
            ]
        );

        $repeater->add_control(
            'format',
            [
                'label'   => \esc_html__('Format', 'maradigma'),
                'type'    => Controls_Manager::SELECT,
                'default' => 'text',
                'options' => [
                    'text'    => \esc_html__('Text', 'maradigma'),
                    'int'     => \esc_html__('Integer', 'maradigma'),
                    'meters'  => \esc_html__('Meters (add "m")', 'maradigma'),
                    'boolean' => \esc_html__('Boolean (Yes/No)', 'maradigma'),
                ],
            ]
        );

        $repeater->add_control(
            'prefix',
            [
                'label'       => \esc_html__('Prefix', 'maradigma'),
                'type'        => Controls_Manager::TEXT,
                'default'     => '',
                'placeholder' => \esc_html__('e.g. ~', 'maradigma'),
            ]
        );

        $repeater->add_control(
            'suffix',
            [
                'label'       => \esc_html__('Suffix', 'maradigma'),
                'type'        => Controls_Manager::TEXT,
                'default'     => '',
                'placeholder' => \esc_html__('e.g. L/H', 'maradigma'),
            ]
        );

        $repeater->add_control(
            'fallback',
            [
                'label'       => \esc_html__('Fallback (if empty)', 'maradigma'),
                'type'        => Controls_Manager::TEXT,
                'default'     => '',
                'placeholder' => \esc_html__('e.g. -', 'maradigma'),
            ]
        );

        $this->add_control(
            'items',
            [
                'label'       => \esc_html__('Specs to show', 'maradigma'),
                'type'        => Controls_Manager::REPEATER,
                'fields'      => $repeater->get_controls(),
                'title_field' => '{{{ label || field }}}',
                'default'     => $this->getDefaultItems(),
            ]
        );

        $this->end_controls_section();
    }

    /**
     * Registers style wrapper controls.
     */
    private function registerStyleWrapperControls(): void
    {
        $this->start_controls_section(
            'section_style_wrapper',
            [
                'label' => \esc_html__('Wrapper', 'maradigma'),
                'tab'   => Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_responsive_control(
            'columns',
            [
                'label'          => \esc_html__('Columns', 'maradigma'),
                'type'           => Controls_Manager::SELECT,
                'default'        => '2',
                'tablet_default' => '2',
                'mobile_default' => '1',
                'options'        => [
                    '1' => '1',
                    '2' => '2',
                    '3' => '3',
                    '4' => '4',
                ],
                'condition'      => [
                    'layout' => 'two_cols',
                ],
                'selectors'      => [
                    '{{WRAPPER}} .maradigma-boat-specs' => 'grid-template-columns: repeat({{VALUE}}, minmax(0, 1fr));',
                ],
            ]
        );

        $this->add_responsive_control(
            'wrapper_gap_column',
            [
                'label'      => \esc_html__('Column gap', 'maradigma'),
                'type'       => Controls_Manager::SLIDER,
                'size_units' => ['px', 'rem'],
                'range'      => [
                    'px' => ['min' => 0, 'max' => 80],
                    'rem' => ['min' => 0, 'max' => 6, 'step' => 0.1],
                ],
                'selectors'  => [
                    '{{WRAPPER}} .maradigma-boat-specs' => 'column-gap: {{SIZE}}{{UNIT}};',
                ],
            ]
        );

        $this->add_responsive_control(
            'wrapper_gap_row',
            [
                'label'      => \esc_html__('Row gap', 'maradigma'),
                'type'       => Controls_Manager::SLIDER,
                'size_units' => ['px', 'rem'],
                'range'      => [
                    'px' => ['min' => 0, 'max' => 80],
                    'rem' => ['min' => 0, 'max' => 6, 'step' => 0.1],
                ],
                'selectors'  => [
                    '{{WRAPPER}} .maradigma-boat-specs' => 'row-gap: {{SIZE}}{{UNIT}};',
                ],
            ]
        );

        $this->add_responsive_control(
            'wrapper_align',
            [
                'label'     => \esc_html__('Alignment', 'maradigma'),
                'type'      => Controls_Manager::CHOOSE,
                'options'   => [
                    'flex-start' => [
                        'title' => \esc_html__('Start', 'maradigma'),
                        'icon'  => 'eicon-text-align-left',
                    ],
                    'center' => [
                        'title' => \esc_html__('Center', 'maradigma'),
                        'icon'  => 'eicon-text-align-center',
                    ],
                    'flex-end' => [
                        'title' => \esc_html__('End', 'maradigma'),
                        'icon'  => 'eicon-text-align-right',
                    ],
                ],
                'selectors' => [
                    '{{WRAPPER}} .maradigma-boat-specs__item' => 'align-items: {{VALUE}};',
                ],
            ]
        );

        $this->add_responsive_control(
            'wrapper_padding',
            [
                'label'      => \esc_html__('Padding', 'maradigma'),
                'type'       => Controls_Manager::DIMENSIONS,
                'size_units' => ['px', '%', 'rem'],
                'selectors'  => [
                    '{{WRAPPER}} .maradigma-boat-specs' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        $this->add_responsive_control(
            'wrapper_margin',
            [
                'label'      => \esc_html__('Margin', 'maradigma'),
                'type'       => Controls_Manager::DIMENSIONS,
                'size_units' => ['px', '%', 'rem'],
                'selectors'  => [
                    '{{WRAPPER}} .maradigma-boat-specs' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Background::get_type(),
            [
                'name'     => 'wrapper_background',
                'selector' => '{{WRAPPER}} .maradigma-boat-specs',
            ]
        );

        $this->add_group_control(
            Group_Control_Border::get_type(),
            [
                'name'     => 'wrapper_border',
                'selector' => '{{WRAPPER}} .maradigma-boat-specs',
            ]
        );

        $this->add_responsive_control(
            'wrapper_border_radius',
            [
                'label'      => \esc_html__('Border radius', 'maradigma'),
                'type'       => Controls_Manager::DIMENSIONS,
                'size_units' => ['px', '%', 'rem'],
                'selectors'  => [
                    '{{WRAPPER}} .maradigma-boat-specs' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Box_Shadow::get_type(),
            [
                'name'     => 'wrapper_box_shadow',
                'selector' => '{{WRAPPER}} .maradigma-boat-specs',
            ]
        );

        $this->end_controls_section();
    }

    /**
     * Registers style item controls.
     */
    private function registerStyleItemControls(): void
    {
        $this->start_controls_section(
            'section_style_item',
            [
                'label' => \esc_html__('Item', 'maradigma'),
                'tab'   => Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_responsive_control(
            'item_direction',
            [
                'label'     => \esc_html__('Direction', 'maradigma'),
                'type'      => Controls_Manager::CHOOSE,
                'options'   => [
                    'row' => [
                        'title' => \esc_html__('Row', 'maradigma'),
                        'icon'  => 'eicon-h-align-left',
                    ],
                    'column' => [
                        'title' => \esc_html__('Column', 'maradigma'),
                        'icon'  => 'eicon-v-align-top',
                    ],
                ],
                'default'   => 'row',
                'selectors' => [
                    '{{WRAPPER}} .maradigma-boat-specs__item' => 'flex-direction: {{VALUE}};',
                ],
            ]
        );

        $this->add_responsive_control(
            'item_gap',
            [
                'label'      => \esc_html__('Inner gap', 'maradigma'),
                'type'       => Controls_Manager::SLIDER,
                'size_units' => ['px', 'rem'],
                'range'      => [
                    'px' => ['min' => 0, 'max' => 50],
                    'rem' => ['min' => 0, 'max' => 4, 'step' => 0.1],
                ],
                'selectors'  => [
                    '{{WRAPPER}} .maradigma-boat-specs__item' => 'gap: {{SIZE}}{{UNIT}};',
                ],
            ]
        );

        $this->add_responsive_control(
            'item_padding',
            [
                'label'      => \esc_html__('Padding', 'maradigma'),
                'type'       => Controls_Manager::DIMENSIONS,
                'size_units' => ['px', '%', 'rem'],
                'selectors'  => [
                    '{{WRAPPER}} .maradigma-boat-specs__item' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Background::get_type(),
            [
                'name'     => 'item_background',
                'selector' => '{{WRAPPER}} .maradigma-boat-specs__item',
            ]
        );

        $this->add_group_control(
            Group_Control_Border::get_type(),
            [
                'name'     => 'item_border',
                'selector' => '{{WRAPPER}} .maradigma-boat-specs__item',
            ]
        );

        $this->add_responsive_control(
            'item_border_radius',
            [
                'label'      => \esc_html__('Border radius', 'maradigma'),
                'type'       => Controls_Manager::DIMENSIONS,
                'size_units' => ['px', '%', 'rem'],
                'selectors'  => [
                    '{{WRAPPER}} .maradigma-boat-specs__item' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Box_Shadow::get_type(),
            [
                'name'     => 'item_box_shadow',
                'selector' => '{{WRAPPER}} .maradigma-boat-specs__item',
            ]
        );

        $this->end_controls_section();
    }

    /**
     * Registers style icon controls.
     */
    private function registerStyleIconControls(): void
    {
        $this->start_controls_section(
            'section_style_icon',
            [
                'label' => \esc_html__('Icon', 'maradigma'),
                'tab'   => Controls_Manager::TAB_STYLE,
                'condition' => [
                    'show_icons' => 'yes',
                ],
            ]
        );

        $this->add_control(
            'icon_color',
            [
                'label'     => \esc_html__('Color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .maradigma-boat-specs__icon' => 'color: {{VALUE}};',
                    '{{WRAPPER}} .maradigma-boat-specs__icon svg' => 'fill: currentColor;',
                ],
            ]
        );

        $this->add_responsive_control(
            'icon_size',
            [
                'label'      => \esc_html__('Size', 'maradigma'),
                'type'       => Controls_Manager::SLIDER,
                'size_units' => ['px', 'rem'],
                'range'      => [
                    'px' => ['min' => 8, 'max' => 80],
                    'rem' => ['min' => 0.5, 'max' => 5, 'step' => 0.1],
                ],
                'selectors'  => [
                    '{{WRAPPER}} .maradigma-boat-specs__icon' => 'font-size: {{SIZE}}{{UNIT}};',
                    '{{WRAPPER}} .maradigma-boat-specs__icon svg' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};',
                ],
            ]
        );

        $this->add_responsive_control(
            'icon_spacing',
            [
                'label'      => \esc_html__('Spacing', 'maradigma'),
                'type'       => Controls_Manager::SLIDER,
                'size_units' => ['px', 'rem'],
                'range'      => [
                    'px' => ['min' => 0, 'max' => 40],
                    'rem' => ['min' => 0, 'max' => 3, 'step' => 0.1],
                ],
                'selectors'  => [
                    '{{WRAPPER}} .maradigma-boat-specs__icon' => 'margin-right: {{SIZE}}{{UNIT}};',
                ],
                'condition'  => [
                    'item_direction!' => 'column',
                ],
            ]
        );

        $this->end_controls_section();
    }

    /**
     * Registers style label controls.
     */
    private function registerStyleLabelControls(): void
    {
        $this->start_controls_section(
            'section_style_label',
            [
                'label' => \esc_html__('Label', 'maradigma'),
                'tab'   => Controls_Manager::TAB_STYLE,
                'condition' => [
                    'show_labels' => 'yes',
                ],
            ]
        );

        $this->add_control(
            'label_color',
            [
                'label'     => \esc_html__('Color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .maradigma-boat-specs__label' => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Typography::get_type(),
            [
                'name'     => 'label_typography',
                'selector' => '{{WRAPPER}} .maradigma-boat-specs__label',
            ]
        );

        $this->add_responsive_control(
            'label_spacing',
            [
                'label'      => \esc_html__('Bottom spacing', 'maradigma'),
                'type'       => Controls_Manager::SLIDER,
                'size_units' => ['px', 'rem'],
                'range'      => [
                    'px' => ['min' => 0, 'max' => 30],
                    'rem' => ['min' => 0, 'max' => 2, 'step' => 0.1],
                ],
                'selectors'  => [
                    '{{WRAPPER}} .maradigma-boat-specs__label' => 'margin-bottom: {{SIZE}}{{UNIT}};',
                ],
            ]
        );

        $this->end_controls_section();
    }

    /**
     * Registers style value controls.
     */
    private function registerStyleValueControls(): void
    {
        $this->start_controls_section(
            'section_style_value',
            [
                'label' => \esc_html__('Value', 'maradigma'),
                'tab'   => Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_control(
            'value_color',
            [
                'label'     => \esc_html__('Color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .maradigma-boat-specs__value' => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Typography::get_type(),
            [
                'name'     => 'value_typography',
                'selector' => '{{WRAPPER}} .maradigma-boat-specs__value',
            ]
        );

        $this->end_controls_section();
    }

    /**
     * Renders the component output.
     */
    protected function render(): void
    {
        $ctx = $this->resolveContext();
        if (!($ctx['ok'] ?? false) || !\is_array($ctx['data'] ?? null)) {
            return;
        }

        /** @var array<string,mixed> $data */
        $data = (array) $ctx['data'];

        $layout = (string) $this->get_settings_for_display('layout');
        if (!\in_array($layout, ['two_cols', 'list'], true)) {
            $layout = 'two_cols';
        }

        $showLabels = ((string) $this->get_settings_for_display('show_labels')) === 'yes';
        $showIcons  = ((string) $this->get_settings_for_display('show_icons')) === 'yes';

        $items = $this->get_settings_for_display('items');
        if (!\is_array($items) || $items === []) {
            echo '<div class="maradigma-boat-specs maradigma-boat-specs--empty">';
            echo \esc_html__('No specs configured.', 'maradigma');
            echo '</div>';
            return;
        }

        $normalizedItems = [];

        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }

            $field = \trim((string) ($item['field'] ?? ''));
            $customKey = \trim((string) ($item['custom_key'] ?? ''));

            if ($field === '') {
                continue;
            }

            if ($field === 'custom' && $customKey === '') {
                continue;
            }

            $normalizedItems[] = $item;
        }

        if ($normalizedItems === []) {
            echo '<div class="maradigma-boat-specs maradigma-boat-specs--empty">';
            echo \esc_html__('No specs configured.', 'maradigma');
            echo '</div>';
            return;
        }

        $html = BoatSpecsRenderer::render(
            $data,
            $normalizedItems,
            [
                'layout'      => $layout,
                'show_labels' => $showLabels,
                'show_icons'  => $showIcons,
                'icons_map'   => [],
            ]
        );

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- BoatSpecsRenderer escapes values and returns the complete widget markup.
        echo $html;
    }

    /**
     * Returns available field options for the repeater "field" selector.
     *
     * @return array<string,string>
     */
    private function getFieldOptions(): array
    {
        $config  = BoatSpecsRenderer::getAvailableFieldsConfig();
        $options = [
            '' => \esc_html__('Select a field', 'maradigma'),
        ];

        foreach ($config as $key => $fieldConfig) {
            $options[$key] = (string) ($fieldConfig['label'] ?? $key);
        }

        $options['custom'] = \esc_html__('Custom key…', 'maradigma');

        return $options;
    }

    /**
     * Default repeater items.
     *
     * @return array<int,array<string,string>>
     */
    private function getDefaultItems(): array
    {
        $config = BoatSpecsRenderer::getAvailableFieldsConfig();

        $defaultKeys = [
            'boat_type',
            'boat_builder',
            'boat_model',
            'boat_capacity',
            'boat_cabins',
            'boat_length',
            'boat_beam',
            'boat_consumption',
            'boat_base_port_name',
        ];

        $items = [];

        foreach ($defaultKeys as $key) {
            if (!isset($config[$key])) {
                continue;
            }

            $fieldConfig = $config[$key];

            $items[] = [
                'field'    => $key,
                'label'    => (string) ($fieldConfig['label'] ?? $key),
                'format'   => (string) ($fieldConfig['format'] ?? 'text'),
                'prefix'   => (string) ($fieldConfig['prefix'] ?? ''),
                'suffix'   => (string) ($fieldConfig['suffix'] ?? ''),
                'fallback' => (string) ($fieldConfig['fallback'] ?? ''),
            ];
        }

        return $items;
    }
}

<?php
// integrations/Elementor/Widgets/SingleBoat/BoatTitleWidget.php

declare(strict_types=1);

namespace Maradigma\Integrations\Elementor\Widgets\SingleBoat;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Text_Shadow;
use Elementor\Group_Control_Typography;

/**
 * Elementor widget: Maradigma Boat Title
 */
final class BoatTitleWidget extends BaseSingleBoatWidget
{
    /**
     * Returns the widget's stable Elementor identifier.
     */
    public function get_name(): string
    {
        return 'maradigma_boat_title';
    }

    /**
     * Returns the widget title shown in Elementor.
     */
    public function get_title(): string
    {
        return \esc_html__('Maradigma Boat Title', 'maradigma');
    }

    /**
     * Returns the Elementor icon identifier for the widget.
     */
    public function get_icon(): string
    {
        return 'eicon-t-letter';
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
        $this->start_controls_section(
            'section_content',
            [
                'label' => \esc_html__('Content', 'maradigma'),
                'tab'   => Controls_Manager::TAB_CONTENT,
            ]
        );

        $this->add_control(
            'boat_id_override',
            [
                'label'       => \esc_html__('Boat ID override', 'maradigma'),
                'type'        => Controls_Manager::TEXT,
                'default'     => '',
                'placeholder' => '304',
                'description' => \esc_html__('Optional. Leave empty to use the current boat context.', 'maradigma'),
            ]
        );

        $this->add_control(
            'slug',
            [
                'label'       => \esc_html__('Boat slug override', 'maradigma'),
                'type'        => Controls_Manager::TEXT,
                'default'     => '',
                'placeholder' => 'alfastreet-marine-28-sanfil',
                'description' => \esc_html__('Optional. If set, the slug takes priority over the boat ID.', 'maradigma'),
            ]
        );

        $this->add_control(
            'show_builder',
            [
                'label'        => \esc_html__('Show builder', 'maradigma'),
                'type'         => Controls_Manager::SWITCHER,
                'label_on'     => \esc_html__('Yes', 'maradigma'),
                'label_off'    => \esc_html__('No', 'maradigma'),
                'return_value' => '1',
                'default'      => '1',
            ]
        );

        $this->add_control(
            'show_model',
            [
                'label'        => \esc_html__('Show model', 'maradigma'),
                'type'         => Controls_Manager::SWITCHER,
                'label_on'     => \esc_html__('Yes', 'maradigma'),
                'label_off'    => \esc_html__('No', 'maradigma'),
                'return_value' => '1',
                'default'      => '1',
            ]
        );

        $this->add_control(
            'show_alias',
            [
                'label'        => \esc_html__('Show alias', 'maradigma'),
                'type'         => Controls_Manager::SWITCHER,
                'label_on'     => \esc_html__('Yes', 'maradigma'),
                'label_off'    => \esc_html__('No', 'maradigma'),
                'return_value' => '1',
                'default'      => '1',
            ]
        );

        $this->add_control(
            'quote_alias',
            [
                'label'        => \esc_html__('Quote alias', 'maradigma'),
                'type'         => Controls_Manager::SWITCHER,
                'label_on'     => \esc_html__('Yes', 'maradigma'),
                'label_off'    => \esc_html__('No', 'maradigma'),
                'return_value' => '1',
                'default'      => '1',
                'condition'    => [
                    'show_alias' => '1',
                ],
            ]
        );

        $this->add_control(
            'separator',
            [
                'label'       => \esc_html__('Separator', 'maradigma'),
                'type'        => Controls_Manager::TEXT,
                'default'     => ' ',
                'placeholder' => ' ',
            ]
        );

        $this->add_control(
            'fallback_service_name',
            [
                'label'        => \esc_html__('Use service name as fallback', 'maradigma'),
                'type'         => Controls_Manager::SWITCHER,
                'label_on'     => \esc_html__('Yes', 'maradigma'),
                'label_off'    => \esc_html__('No', 'maradigma'),
                'return_value' => '1',
                'default'      => '1',
            ]
        );

        $this->add_control(
            'fallback',
            [
                'label'       => \esc_html__('Fallback text', 'maradigma'),
                'type'        => Controls_Manager::TEXT,
                'default'     => '',
                'placeholder' => 'Boat',
            ]
        );

        $this->add_control(
            'html_tag',
            [
                'label'   => \esc_html__('HTML Tag', 'maradigma'),
                'type'    => Controls_Manager::SELECT,
                'default' => 'h2',
                'options' => [
                    'h1' => 'H1',
                    'h2' => 'H2',
                    'h3' => 'H3',
                    'div' => 'DIV',
                    'p'  => 'P',
                ],
            ]
        );

        $this->end_controls_section();

        $this->start_controls_section(
            'section_style_title',
            [
                'label' => \esc_html__('Title', 'maradigma'),
                'tab'   => Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_responsive_control(
            'title_alignment',
            [
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
                    'justify' => [
                        'title' => \esc_html__('Justified', 'maradigma'),
                        'icon'  => 'eicon-text-align-justify',
                    ],
                ],
                'selectors' => [
                    '{{WRAPPER}} .maradigma-boat-title' => 'text-align: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'title_color',
            [
                'label'     => \esc_html__('Text color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .maradigma-boat-title' => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Typography::get_type(),
            [
                'name'     => 'title_typography',
                'selector' => '{{WRAPPER}} .maradigma-boat-title',
            ]
        );

        $this->add_group_control(
            Group_Control_Text_Shadow::get_type(),
            [
                'name'     => 'title_text_shadow',
                'selector' => '{{WRAPPER}} .maradigma-boat-title',
            ]
        );

        $this->add_responsive_control(
            'title_margin',
            [
                'label'      => \esc_html__('Margin', 'maradigma'),
                'type'       => Controls_Manager::DIMENSIONS,
                'size_units' => ['px', '%', 'em', 'rem'],
                'selectors'  => [
                    '{{WRAPPER}} .maradigma-boat-title' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        $this->end_controls_section();
    }

    /**
     * Renders the component output.
     */
    protected function render(): void
    {
        $tag = (string) $this->get_settings_for_display('html_tag');
        if (!\in_array($tag, ['h1', 'h2', 'h3', 'div', 'p'], true)) {
            $tag = 'h2';
        }

        $id = \trim((string) $this->get_settings_for_display('boat_id_override'));
        if ($id === '') {
            $rawSettings = $this->get_data('settings');
            if (\is_array($rawSettings)) {
                // Compatibility with widgets saved before the reserved `id`
                // setting was renamed to avoid colliding with Elementor's model.
                $id = \trim((string) ($rawSettings['id'] ?? ''));
            }
        }
        if ($id === '') {
            $id = \trim((string) $this->getBoatIdFromCurrentPost());
        }

        $atts = [
            'id'                    => $id,
            'slug'                  => \trim((string) $this->get_settings_for_display('slug')),
            'fallback'              => \trim((string) $this->get_settings_for_display('fallback')),
            'show_builder'          => (string) $this->get_settings_for_display('show_builder') === '1' ? '1' : '0',
            'show_model'            => (string) $this->get_settings_for_display('show_model') === '1' ? '1' : '0',
            'show_alias'            => (string) $this->get_settings_for_display('show_alias') === '1' ? '1' : '0',
            'quote_alias'           => (string) $this->get_settings_for_display('quote_alias') === '1' ? '1' : '0',
            'fallback_service_name' => (string) $this->get_settings_for_display('fallback_service_name') === '1' ? '1' : '0',
            'separator'             => (string) $this->get_settings_for_display('separator'),
        ];

        $title = \Maradigma\ShortcodeRegistry::renderBoatTitle($atts);
        if ($title === '') {
            return;
        }

        echo '<' . \esc_attr($tag) . ' class="maradigma-boat-title">';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderBoatTitle returns an esc_html()-escaped string.
        echo $title;
        echo '</' . \esc_attr($tag) . '>';
    }
}

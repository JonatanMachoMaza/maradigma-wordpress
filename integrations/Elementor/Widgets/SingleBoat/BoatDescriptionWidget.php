<?php

declare(strict_types=1);

namespace Maradigma\Integrations\Elementor\Widgets\SingleBoat;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Background;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Box_Shadow;
use Elementor\Group_Control_Typography;

/**
 * Elementor widget: Maradigma Boat Description
 */
final class BoatDescriptionWidget extends BaseSingleBoatWidget
{
    /**
     * Returns the widget's stable Elementor identifier.
     */
    public function get_name(): string
    {
        return 'maradigma_boat_description';
    }

    /**
     * Returns the widget title shown in Elementor.
     */
    public function get_title(): string
    {
        return esc_html__('Maradigma Boat Description', 'maradigma');
    }

    /**
     * Returns the Elementor icon identifier for the widget.
     */
    public function get_icon(): string
    {
        return 'eicon-editor-paragraph';
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
                'label' => esc_html__('Content', 'maradigma'),
                'tab'   => Controls_Manager::TAB_CONTENT,
            ]
        );

        $this->add_control(
            'description_type',
            [
                'label'   => esc_html__('Description type', 'maradigma'),
                'type'    => Controls_Manager::SELECT,
                'default' => 'auto',
                'options' => [
                    'auto'  => esc_html__('Auto (prefer long)', 'maradigma'),
                    'short' => esc_html__('Short description', 'maradigma'),
                    'long'  => esc_html__('Long description', 'maradigma'),
                ],
            ]
        );

        $this->add_control(
            'allow_html',
            [
                'label'        => esc_html__('Allow basic HTML', 'maradigma'),
                'type'         => Controls_Manager::SWITCHER,
                'label_on'     => esc_html__('Yes', 'maradigma'),
                'label_off'    => esc_html__('No', 'maradigma'),
                'return_value' => 'yes',
                'default'      => '',
            ]
        );

        $this->add_control(
            'max_words',
            [
                'label'       => esc_html__('Max words (0 = unlimited)', 'maradigma'),
                'type'        => Controls_Manager::NUMBER,
                'min'         => 0,
                'max'         => 5000,
                'step'        => 10,
                'default'     => 0,
                'description' => esc_html__('If set, the description will be trimmed.', 'maradigma'),
            ]
        );

        $this->end_controls_section();

        $this->start_controls_section(
            'section_style_text',
            [
                'label' => esc_html__('Text', 'maradigma'),
                'tab'   => Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_group_control(
            Group_Control_Typography::get_type(),
            [
                'name'     => 'text_typography',
                'selector' => '{{WRAPPER}} .maradigma-boat-description',
            ]
        );

        $this->add_control(
            'text_color',
            [
                'label'     => esc_html__('Text color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .maradigma-boat-description' => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->add_responsive_control(
            'text_alignment',
            [
                'label'   => esc_html__('Alignment', 'maradigma'),
                'type'    => Controls_Manager::CHOOSE,
                'options' => [
                    'left' => [
                        'title' => esc_html__('Left', 'maradigma'),
                        'icon'  => 'eicon-text-align-left',
                    ],
                    'center' => [
                        'title' => esc_html__('Center', 'maradigma'),
                        'icon'  => 'eicon-text-align-center',
                    ],
                    'right' => [
                        'title' => esc_html__('Right', 'maradigma'),
                        'icon'  => 'eicon-text-align-right',
                    ],
                    'justify' => [
                        'title' => esc_html__('Justified', 'maradigma'),
                        'icon'  => 'eicon-text-align-justify',
                    ],
                ],
                'selectors' => [
                    '{{WRAPPER}} .maradigma-boat-description' => 'text-align: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'link_color',
            [
                'label'     => esc_html__('Link color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .maradigma-boat-description a' => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'link_hover_color',
            [
                'label'     => esc_html__('Link hover color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .maradigma-boat-description a:hover, {{WRAPPER}} .maradigma-boat-description a:focus' => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->add_responsive_control(
            'paragraph_spacing',
            [
                'label'      => esc_html__('Paragraph spacing', 'maradigma'),
                'type'       => Controls_Manager::SLIDER,
                'size_units' => ['px', 'em', 'rem'],
                'range'      => [
                    'px' => ['min' => 0, 'max' => 100],
                ],
                'selectors'  => [
                    '{{WRAPPER}} .maradigma-boat-description p:not(:last-child)' => 'margin-bottom: {{SIZE}}{{UNIT}};',
                ],
            ]
        );

        $this->end_controls_section();

        $this->start_controls_section(
            'section_style_container',
            [
                'label' => esc_html__('Container', 'maradigma'),
                'tab'   => Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_group_control(
            Group_Control_Background::get_type(),
            [
                'name'     => 'container_background',
                'selector' => '{{WRAPPER}} .maradigma-boat-description',
            ]
        );

        $this->add_group_control(
            Group_Control_Border::get_type(),
            [
                'name'     => 'container_border',
                'selector' => '{{WRAPPER}} .maradigma-boat-description',
            ]
        );

        $this->add_responsive_control(
            'container_border_radius',
            [
                'label'      => esc_html__('Border radius', 'maradigma'),
                'type'       => Controls_Manager::DIMENSIONS,
                'size_units' => ['px', '%', 'em', 'rem'],
                'selectors'  => [
                    '{{WRAPPER}} .maradigma-boat-description' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Box_Shadow::get_type(),
            [
                'name'     => 'container_shadow',
                'selector' => '{{WRAPPER}} .maradigma-boat-description',
            ]
        );

        $this->add_responsive_control(
            'container_padding',
            [
                'label'      => esc_html__('Padding', 'maradigma'),
                'type'       => Controls_Manager::DIMENSIONS,
                'size_units' => ['px', '%', 'em', 'rem'],
                'selectors'  => [
                    '{{WRAPPER}} .maradigma-boat-description' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
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
        $ctx = $this->resolveContext(['service_descriptions']);
        if (!($ctx['ok'] ?? false) || !is_array($ctx['data'] ?? null)) {
            return;
        }

        $boat = (array) $ctx['data'];
        $boatId = (string) ($boat['id'] ?? '');

        if ($boatId === '') {
            return;
        }

        $type = (string) $this->get_settings_for_display('description_type');
        $allowHtml = ((string) $this->get_settings_for_display('allow_html')) === 'yes' ? '1' : '0';
        $maxWords = (int) $this->get_settings_for_display('max_words');

        $description = \Maradigma\ShortcodeRegistry::renderBoatDescription([
            'id'         => $boatId,
            'type'       => $type,
            'allow_html' => $allowHtml,
            'max_words'  => (string) $maxWords,
        ]);

        if ($description === '') {
            return;
        }

        echo '<div class="maradigma-boat-description">';
        echo wp_kses_post($description);
        echo '</div>';
    }
}

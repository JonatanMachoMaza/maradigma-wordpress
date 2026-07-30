<?php

declare(strict_types=1);

namespace Maradigma\Integrations\Elementor\Widgets\SingleBoat;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Box_Shadow;
use Elementor\Group_Control_Typography;

final class BoatVideosWidget extends BaseSingleBoatWidget
{
    public function get_name(): string
    {
        return 'maradigma_boat_videos';
    }

    public function get_title(): string
    {
        return esc_html__('Maradigma Boat Videos', 'maradigma');
    }

    public function get_icon(): string
    {
        return 'eicon-video-camera';
    }

    public function get_categories(): array
    {
        return ['maradigma'];
    }

    protected function register_controls(): void
    {
        // -------------------------------------------------
        // CONTENT
        // -------------------------------------------------
        $this->start_controls_section(
            'section_content',
            [
                'label' => esc_html__('Content', 'maradigma'),
                'tab'   => Controls_Manager::TAB_CONTENT,
            ]
        );

        $this->add_control(
            'layout',
            [
                'label'   => esc_html__('Layout', 'maradigma'),
                'type'    => Controls_Manager::SELECT,
                'default' => 'grid',
                'options' => [
                    'grid' => esc_html__('Grid', 'maradigma'),
                    'list' => esc_html__('List', 'maradigma'),
                ],
            ]
        );

        $this->add_control(
            'mode',
            [
                'label'   => esc_html__('Display mode', 'maradigma'),
                'type'    => Controls_Manager::SELECT,
                'default' => 'embed',
                'options' => [
                    'embed'     => esc_html__('Embedded video', 'maradigma'),
                    'thumbnail' => esc_html__('Thumbnail + link', 'maradigma'),
                ],
            ]
        );

        $this->add_control(
            'columns',
            [
                'label'     => esc_html__('Columns', 'maradigma'),
                'type'      => Controls_Manager::SELECT,
                'default'   => '2',
                'options'   => [
                    '1' => '1',
                    '2' => '2',
                    '3' => '3',
                    '4' => '4',
                ],
                'condition' => [
                    'layout' => 'grid',
                ],
            ]
        );

        $this->add_control(
            'max_videos',
            [
                'label'   => esc_html__('Maximum videos', 'maradigma'),
                'type'    => Controls_Manager::NUMBER,
                'min'     => 1,
                'max'     => 50,
                'step'    => 1,
                'default' => 12,
            ]
        );

        $this->add_control(
            'show_title',
            [
                'label'        => esc_html__('Show video title', 'maradigma'),
                'type'         => Controls_Manager::SWITCHER,
                'label_on'     => esc_html__('Yes', 'maradigma'),
                'label_off'    => esc_html__('No', 'maradigma'),
                'return_value' => '1',
                'default'      => '0',
            ]
        );

        $this->add_control(
            'empty_text',
            [
                'label'       => esc_html__('Empty text', 'maradigma'),
                'type'        => Controls_Manager::TEXT,
                'default'     => 'Videos not available.',
                'placeholder' => 'Videos not available.',
            ]
        );

        $this->end_controls_section();

        // -------------------------------------------------
        // WRAPPER
        // -------------------------------------------------
        $this->start_controls_section(
            'section_style_wrapper',
            [
                'label' => esc_html__('Wrapper', 'maradigma'),
                'tab'   => Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_responsive_control(
            'wrapper_gap',
            [
                'label'      => esc_html__('Gap', 'maradigma'),
                'type'       => Controls_Manager::SLIDER,
                'size_units' => ['px', 'rem'],
                'range'      => [
                    'px'  => ['min' => 0, 'max' => 80],
                    'rem' => ['min' => 0, 'max' => 10, 'step' => 0.1],
                ],
                'selectors'  => [
                    '{{WRAPPER}} .maradigma-boat-videos' => 'gap: {{SIZE}}{{UNIT}};',
                ],
            ]
        );

        $this->add_responsive_control(
            'wrapper_columns_style',
            [
                'label'     => esc_html__('Columns (style)', 'maradigma'),
                'type'      => Controls_Manager::SELECT,
                'default'   => '2',
                'options'   => [
                    '1' => '1',
                    '2' => '2',
                    '3' => '3',
                    '4' => '4',
                ],
                'selectors' => [
                    '{{WRAPPER}} .maradigma-boat-videos--grid' => 'grid-template-columns: repeat({{VALUE}}, minmax(0, 1fr));',
                ],
                'condition' => [
                    'layout' => 'grid',
                ],
            ]
        );

        $this->add_responsive_control(
            'wrapper_align',
            [
                'label'     => esc_html__('Alignment', 'maradigma'),
                'type'      => Controls_Manager::CHOOSE,
                'options'   => [
                    'flex-start' => [
                        'title' => esc_html__('Left', 'maradigma'),
                        'icon'  => 'eicon-text-align-left',
                    ],
                    'center' => [
                        'title' => esc_html__('Center', 'maradigma'),
                        'icon'  => 'eicon-text-align-center',
                    ],
                    'flex-end' => [
                        'title' => esc_html__('Right', 'maradigma'),
                        'icon'  => 'eicon-text-align-right',
                    ],
                ],
                'selectors' => [
                    '{{WRAPPER}} .maradigma-boat-videos__item' => 'align-items: {{VALUE}};',
                ],
            ]
        );

        $this->add_responsive_control(
            'wrapper_max_width',
            [
                'label'      => esc_html__('Max width', 'maradigma'),
                'type'       => Controls_Manager::SLIDER,
                'size_units' => ['px', '%', 'vw'],
                'range'      => [
                    'px' => ['min' => 100, 'max' => 2000],
                    '%'  => ['min' => 1, 'max' => 100],
                    'vw' => ['min' => 1, 'max' => 100],
                ],
                'selectors'  => [
                    '{{WRAPPER}} .maradigma-boat-videos' => 'max-width: {{SIZE}}{{UNIT}};',
                ],
            ]
        );

        $this->end_controls_section();

        // -------------------------------------------------
        // ITEM
        // -------------------------------------------------
        $this->start_controls_section(
            'section_style_item',
            [
                'label' => esc_html__('Item', 'maradigma'),
                'tab'   => Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_responsive_control(
            'item_padding',
            [
                'label'      => esc_html__('Padding', 'maradigma'),
                'type'       => Controls_Manager::DIMENSIONS,
                'size_units' => ['px', '%', 'em', 'rem'],
                'selectors'  => [
                    '{{WRAPPER}} .maradigma-boat-videos__item' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        $this->add_responsive_control(
            'item_gap',
            [
                'label'      => esc_html__('Inner gap', 'maradigma'),
                'type'       => Controls_Manager::SLIDER,
                'size_units' => ['px', 'rem'],
                'range'      => [
                    'px'  => ['min' => 0, 'max' => 60],
                    'rem' => ['min' => 0, 'max' => 6, 'step' => 0.1],
                ],
                'selectors'  => [
                    '{{WRAPPER}} .maradigma-boat-videos__item' => 'gap: {{SIZE}}{{UNIT}};',
                ],
            ]
        );

        $this->start_controls_tabs('item_style_tabs');

        $this->start_controls_tab(
            'item_style_tab_normal',
            [
                'label' => esc_html__('Normal', 'maradigma'),
            ]
        );

        $this->add_control(
            'item_background',
            [
                'label'     => esc_html__('Background color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .maradigma-boat-videos__item' => 'background-color: {{VALUE}};',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Border::get_type(),
            [
                'name'     => 'item_border',
                'selector' => '{{WRAPPER}} .maradigma-boat-videos__item',
            ]
        );

        $this->add_responsive_control(
            'item_border_radius',
            [
                'label'      => esc_html__('Border radius', 'maradigma'),
                'type'       => Controls_Manager::DIMENSIONS,
                'size_units' => ['px', '%'],
                'selectors'  => [
                    '{{WRAPPER}} .maradigma-boat-videos__item' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}}; overflow: hidden;',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Box_Shadow::get_type(),
            [
                'name'     => 'item_shadow',
                'selector' => '{{WRAPPER}} .maradigma-boat-videos__item',
            ]
        );

        $this->end_controls_tab();

        $this->start_controls_tab(
            'item_style_tab_hover',
            [
                'label' => esc_html__('Hover', 'maradigma'),
            ]
        );

        $this->add_control(
            'item_background_hover',
            [
                'label'     => esc_html__('Background color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .maradigma-boat-videos__item:hover' => 'background-color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'item_border_color_hover',
            [
                'label'     => esc_html__('Border color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .maradigma-boat-videos__item:hover' => 'border-color: {{VALUE}};',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Box_Shadow::get_type(),
            [
                'name'     => 'item_shadow_hover',
                'selector' => '{{WRAPPER}} .maradigma-boat-videos__item:hover',
            ]
        );

        $this->add_responsive_control(
            'item_translate_y_hover',
            [
                'label'      => esc_html__('Translate Y', 'maradigma'),
                'type'       => Controls_Manager::SLIDER,
                'size_units' => ['px'],
                'range'      => [
                    'px' => ['min' => -50, 'max' => 50],
                ],
                'selectors'  => [
                    '{{WRAPPER}} .maradigma-boat-videos__item:hover' => 'transform: translateY({{SIZE}}{{UNIT}});',
                ],
            ]
        );

        $this->end_controls_tab();

        $this->end_controls_tabs();

        $this->add_control(
            'item_transition_heading',
            [
                'label'     => esc_html__('Transition', 'maradigma'),
                'type'      => Controls_Manager::HEADING,
                'separator' => 'before',
            ]
        );

        $this->add_responsive_control(
            'item_transition_duration',
            [
                'label'      => esc_html__('Duration (ms)', 'maradigma'),
                'type'       => Controls_Manager::SLIDER,
                'size_units' => ['ms'],
                'range'      => [
                    'ms' => ['min' => 0, 'max' => 2000, 'step' => 50],
                ],
                'default'    => [
                    'unit' => 'ms',
                    'size' => 250,
                ],
                'selectors'  => [
                    '{{WRAPPER}} .maradigma-boat-videos__item' => 'transition: all {{SIZE}}{{UNIT}} ease;',
                ],
            ]
        );

        $this->end_controls_section();

        // -------------------------------------------------
        // MEDIA
        // -------------------------------------------------
        $this->start_controls_section(
            'section_style_media',
            [
                'label' => esc_html__('Video / Thumbnail', 'maradigma'),
                'tab'   => Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_responsive_control(
            'media_border_radius',
            [
                'label'      => esc_html__('Media border radius', 'maradigma'),
                'type'       => Controls_Manager::DIMENSIONS,
                'size_units' => ['px', '%'],
                'selectors'  => [
                    '{{WRAPPER}} .maradigma-boat-videos__embed' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                    '{{WRAPPER}} .maradigma-boat-videos__thumb' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                    '{{WRAPPER}} .maradigma-boat-videos__thumb-placeholder' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        $this->add_responsive_control(
            'media_aspect_ratio',
            [
                'label'       => esc_html__('Aspect ratio (%)', 'maradigma'),
                'description' => esc_html__('56.25 = 16:9, 75 = 4:3, 100 = square', 'maradigma'),
                'type'        => Controls_Manager::NUMBER,
                'min'         => 20,
                'max'         => 200,
                'step'        => 0.01,
                'default'     => 56.25,
                'selectors'   => [
                    '{{WRAPPER}} .maradigma-boat-videos__embed' => 'padding-bottom: {{VALUE}}%;',
                ],
                'condition'   => [
                    'mode' => 'embed',
                ],
            ]
        );

        $this->add_responsive_control(
            'thumb_height',
            [
                'label'      => esc_html__('Thumbnail height', 'maradigma'),
                'type'       => Controls_Manager::SLIDER,
                'size_units' => ['px', 'vh'],
                'range'      => [
                    'px' => ['min' => 80, 'max' => 800],
                    'vh' => ['min' => 10, 'max' => 100],
                ],
                'selectors'  => [
                    '{{WRAPPER}} .maradigma-boat-videos__thumb' => 'height: {{SIZE}}{{UNIT}}; object-fit: cover;',
                    '{{WRAPPER}} .maradigma-boat-videos__thumb-placeholder' => 'min-height: {{SIZE}}{{UNIT}};',
                ],
                'condition'  => [
                    'mode' => 'thumbnail',
                ],
            ]
        );

        $this->add_control(
            'thumb_placeholder_background',
            [
                'label'     => esc_html__('Placeholder background', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .maradigma-boat-videos__thumb-placeholder' => 'background-color: {{VALUE}};',
                ],
                'condition' => [
                    'mode' => 'thumbnail',
                ],
            ]
        );

        $this->add_control(
            'thumb_placeholder_color',
            [
                'label'     => esc_html__('Placeholder text color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .maradigma-boat-videos__thumb-placeholder' => 'color: {{VALUE}};',
                ],
                'condition' => [
                    'mode' => 'thumbnail',
                ],
            ]
        );

        $this->add_control(
            'thumb_link_overlay_color',
            [
                'label'     => esc_html__('Thumbnail overlay color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .maradigma-boat-videos__thumb-link' => 'position: relative;',
                    '{{WRAPPER}} .maradigma-boat-videos__thumb-link::after' => 'content: ""; position: absolute; inset: 0; background-color: {{VALUE}}; pointer-events: none;',
                ],
                'condition' => [
                    'mode' => 'thumbnail',
                ],
            ]
        );

        $this->add_responsive_control(
            'thumb_overlay_opacity',
            [
                'label'      => esc_html__('Thumbnail overlay opacity', 'maradigma'),
                'type'       => Controls_Manager::SLIDER,
                'size_units' => [''],
                'range'      => [
                    '' => ['min' => 0, 'max' => 1, 'step' => 0.05],
                ],
                'selectors'  => [
                    '{{WRAPPER}} .maradigma-boat-videos__thumb-link::after' => 'opacity: {{SIZE}};',
                ],
                'condition'  => [
                    'mode' => 'thumbnail',
                ],
            ]
        );

        $this->end_controls_section();

        // -------------------------------------------------
        // TITLE
        // -------------------------------------------------
        $this->start_controls_section(
            'section_style_title',
            [
                'label'     => esc_html__('Title', 'maradigma'),
                'tab'       => Controls_Manager::TAB_STYLE,
                'condition' => [
                    'show_title' => '1',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Typography::get_type(),
            [
                'name'     => 'title_typography',
                'selector' => '{{WRAPPER}} .maradigma-boat-videos__title',
            ]
        );

        $this->start_controls_tabs('title_style_tabs');

        $this->start_controls_tab(
            'title_style_tab_normal',
            [
                'label' => esc_html__('Normal', 'maradigma'),
            ]
        );

        $this->add_control(
            'title_color',
            [
                'label'     => esc_html__('Color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .maradigma-boat-videos__title' => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->end_controls_tab();

        $this->start_controls_tab(
            'title_style_tab_hover',
            [
                'label' => esc_html__('Hover', 'maradigma'),
            ]
        );

        $this->add_control(
            'title_color_hover',
            [
                'label'     => esc_html__('Color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .maradigma-boat-videos__item:hover .maradigma-boat-videos__title' => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->end_controls_tab();

        $this->end_controls_tabs();

        $this->add_responsive_control(
            'title_margin',
            [
                'label'      => esc_html__('Margin', 'maradigma'),
                'type'       => Controls_Manager::DIMENSIONS,
                'size_units' => ['px', 'em', 'rem', '%'],
                'selectors'  => [
                    '{{WRAPPER}} .maradigma-boat-videos__title' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        $this->add_control(
            'title_align',
            [
                'label'     => esc_html__('Alignment', 'maradigma'),
                'type'      => Controls_Manager::CHOOSE,
                'options'   => [
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
                ],
                'selectors' => [
                    '{{WRAPPER}} .maradigma-boat-videos__title' => 'text-align: {{VALUE}};',
                ],
            ]
        );

        $this->end_controls_section();
    }

    protected function render(): void
    {
        $boatId = $this->getBoatIdFromCurrentPost();

        if ($boatId === '') {
            if ($this->isElementorEditor()) {
                $this->renderEditorHint(
                    __('Maradigma Boat Videos', 'maradigma'),
                    __('Select a Maradigma boat in this post first.', 'maradigma')
                );
            }

            return;
        }

        $layout = (string) $this->get_settings_for_display('layout');
        $mode = (string) $this->get_settings_for_display('mode');
        $columns = (string) $this->get_settings_for_display('columns');
        $maxVideos = (int) ($this->get_settings_for_display('max_videos') ?? 12);
        $showTitle = (string) $this->get_settings_for_display('show_title');
        $emptyText = trim((string) $this->get_settings_for_display('empty_text'));

        if (!in_array($layout, ['grid', 'list'], true)) {
            $layout = 'grid';
        }

        if (!in_array($mode, ['embed', 'thumbnail'], true)) {
            $mode = 'embed';
        }

        if (!in_array($columns, ['1', '2', '3', '4'], true)) {
            $columns = '2';
        }

        if ($maxVideos < 1) {
            $maxVideos = 12;
        }

        if ($emptyText === '') {
            $emptyText = __('Videos not available.', 'maradigma');
        }

        $wrapperClasses = [
            'maradigma-boat-videos-widget',
            'maradigma-boat-videos-widget--layout-' . $layout,
            'maradigma-boat-videos-widget--mode-' . $mode,
        ];

        $shortcode = sprintf(
            '[maradigma_boat_videos id="%s" layout="%s" mode="%s" columns="%s" max_videos="%d" show_title="%s" class="%s"]',
            esc_attr($boatId),
            esc_attr($layout),
            esc_attr($mode),
            esc_attr($columns),
            $maxVideos,
            esc_attr($showTitle === '1' ? '1' : '0'),
            esc_attr('maradigma-boat-videos--elementor')
        );

        $html = do_shortcode($shortcode);

        if (!is_string($html) || trim($html) === '') {
            if ($this->isElementorEditor()) {
                echo '<div class="' . esc_attr(implode(' ', array_merge($wrapperClasses, ['maradigma-boat-videos-widget--empty']))) . '">';
                echo esc_html($emptyText);
                echo '</div>';
            }

            return;
        }

        echo '<div class="' . esc_attr(implode(' ', $wrapperClasses)) . '">';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WordPress shortcode output may legitimately contain iframe and provider-specific markup.
        echo $html;
        echo '</div>';
    }
}

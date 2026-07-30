<?php

declare(strict_types=1);

namespace Maradigma\Integrations\Elementor\Widgets\SingleBoat;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Box_Shadow;
use Elementor\Group_Control_Typography;

/**
 * Elementor widget: Maradigma Boat PDF Download
 *
 * This widget resolves the current boat context and renders a customizable
 * PDF download button directly from Elementor, allowing full style control
 * from the editor.
 *
 * Source of truth:
 * - API expand: service_pdf
 * - Expected payload key: url_pdf
 */
final class BoatPdfDownloadWidget extends BaseSingleBoatWidget
{
    public function get_name(): string
    {
        return 'maradigma_boat_pdf_download';
    }

    public function get_title(): string
    {
        return esc_html__('Maradigma Boat PDF Download', 'maradigma');
    }

    public function get_icon(): string
    {
        return 'eicon-file-download';
    }

    public function get_categories(): array
    {
        return ['maradigma'];
    }

    protected function register_controls(): void
    {
        $this->registerContentControls();
        $this->registerStyleControls();
    }

    /**
     * Registers content controls.
     *
     * @return void
     */
    private function registerContentControls(): void
    {
        $this->start_controls_section(
            'section_content',
            [
                'label' => esc_html__('Content', 'maradigma'),
                'tab'   => Controls_Manager::TAB_CONTENT,
            ]
        );

        $this->add_control(
            'button_text',
            [
                'label'       => esc_html__('Button text', 'maradigma'),
                'type'        => Controls_Manager::TEXT,
                'default'     => 'Download PDF',
                'placeholder' => 'Download PDF',
            ]
        );

        $this->add_responsive_control(
            'button_align',
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
                'default'   => 'left',
                'selectors' => [
                    '{{WRAPPER}} .maradigma-boat-pdf-download' => 'text-align: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'open_in_new_tab',
            [
                'label'        => esc_html__('Open in new tab', 'maradigma'),
                'type'         => Controls_Manager::SWITCHER,
                'label_on'     => esc_html__('Yes', 'maradigma'),
                'label_off'    => esc_html__('No', 'maradigma'),
                'return_value' => 'yes',
                'default'      => 'yes',
            ]
        );

        $this->add_control(
            'force_download',
            [
                'label'        => esc_html__('Force download (download attribute)', 'maradigma'),
                'type'         => Controls_Manager::SWITCHER,
                'label_on'     => esc_html__('Yes', 'maradigma'),
                'label_off'    => esc_html__('No', 'maradigma'),
                'return_value' => 'yes',
                'default'      => '',
            ]
        );

        $this->add_control(
            'fallback',
            [
                'label'       => esc_html__('Fallback text if PDF does not exist', 'maradigma'),
                'type'        => Controls_Manager::TEXT,
                'default'     => '',
                'placeholder' => esc_html__('Optional fallback text', 'maradigma'),
            ]
        );

        $this->end_controls_section();
    }

    /**
     * Registers style controls.
     *
     * @return void
     */
    private function registerStyleControls(): void
    {
        $this->start_controls_section(
            'section_style_button',
            [
                'label' => esc_html__('Button', 'maradigma'),
                'tab'   => Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_responsive_control(
            'button_width',
            [
                'label'      => esc_html__('Width', 'maradigma'),
                'type'       => Controls_Manager::SELECT,
                'default'    => 'inline-block',
                'options'    => [
                    'inline-block' => esc_html__('Inline', 'maradigma'),
                    'block'        => esc_html__('Full width', 'maradigma'),
                ],
                'selectors'  => [
                    '{{WRAPPER}} .maradigma-boat-pdf-download__btn' => 'display: {{VALUE}};',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Typography::get_type(),
            [
                'name'     => 'button_typography',
                'selector' => '{{WRAPPER}} .maradigma-boat-pdf-download__btn',
            ]
        );

        $this->start_controls_tabs('tabs_button_style');

        $this->start_controls_tab(
            'tab_button_normal',
            [
                'label' => esc_html__('Normal', 'maradigma'),
            ]
        );

        $this->add_control(
            'button_text_color',
            [
                'label'     => esc_html__('Text color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .maradigma-boat-pdf-download__btn' => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'button_background_color',
            [
                'label'     => esc_html__('Background color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .maradigma-boat-pdf-download__btn' => 'background-color: {{VALUE}};',
                ],
            ]
        );

        $this->end_controls_tab();

        $this->start_controls_tab(
            'tab_button_hover',
            [
                'label' => esc_html__('Hover', 'maradigma'),
            ]
        );

        $this->add_control(
            'button_text_color_hover',
            [
                'label'     => esc_html__('Text color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .maradigma-boat-pdf-download__btn:hover' => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'button_background_color_hover',
            [
                'label'     => esc_html__('Background color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .maradigma-boat-pdf-download__btn:hover' => 'background-color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'button_border_color_hover',
            [
                'label'     => esc_html__('Border color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .maradigma-boat-pdf-download__btn:hover' => 'border-color: {{VALUE}};',
                ],
            ]
        );

        $this->end_controls_tab();

        $this->end_controls_tabs();

        $this->add_group_control(
            Group_Control_Border::get_type(),
            [
                'name'     => 'button_border',
                'selector' => '{{WRAPPER}} .maradigma-boat-pdf-download__btn',
            ]
        );

        $this->add_responsive_control(
            'button_border_radius',
            [
                'label'      => esc_html__('Border radius', 'maradigma'),
                'type'       => Controls_Manager::DIMENSIONS,
                'size_units' => ['px', '%', 'em', 'rem'],
                'selectors'  => [
                    '{{WRAPPER}} .maradigma-boat-pdf-download__btn' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        $this->add_responsive_control(
            'button_padding',
            [
                'label'      => esc_html__('Padding', 'maradigma'),
                'type'       => Controls_Manager::DIMENSIONS,
                'size_units' => ['px', 'em', 'rem'],
                'selectors'  => [
                    '{{WRAPPER}} .maradigma-boat-pdf-download__btn' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        $this->add_responsive_control(
            'button_margin',
            [
                'label'      => esc_html__('Margin', 'maradigma'),
                'type'       => Controls_Manager::DIMENSIONS,
                'size_units' => ['px', 'em', 'rem'],
                'selectors'  => [
                    '{{WRAPPER}} .maradigma-boat-pdf-download__btn' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Box_Shadow::get_type(),
            [
                'name'     => 'button_box_shadow',
                'selector' => '{{WRAPPER}} .maradigma-boat-pdf-download__btn',
            ]
        );

        $this->end_controls_section();
    }

    protected function render(): void
    {
        $ctx = $this->resolveContext(['service_pdf']);
        if (!($ctx['ok'] ?? false) || !is_array($ctx['data'] ?? null)) {
            return;
        }

        $boat = (array) $ctx['data'];
        $boatId = trim((string) ($boat['id'] ?? ''));

        if ($boatId === '') {
            return;
        }

        $pdfUrl = '';
        if (isset($boat['url_pdf']) && is_string($boat['url_pdf'])) {
            $pdfUrl = trim((string) $boat['url_pdf']);
        }

        $fallback = trim((string) $this->get_settings_for_display('fallback'));

        if ($pdfUrl === '') {
            if ($fallback !== '') {
                echo '<div class="maradigma-boat-pdf-download maradigma-boat-pdf-download--empty">';
                echo esc_html($fallback);
                echo '</div>';
            }

            return;
        }

        $buttonText = trim((string) $this->get_settings_for_display('button_text'));
        if ($buttonText === '') {
            $buttonText = (string) __('Download PDF', 'maradigma');
        }

        $buttonText = \Maradigma\Support\MultilangAdapter::translateEditableString(
            $buttonText,
            'Maradigma Elementor Widgets',
            'boat_pdf_download_widget_button_text',
            'maradigma'
        );

        $openInNewTab = ((string) $this->get_settings_for_display('open_in_new_tab')) === 'yes';
        $forceDownload = ((string) $this->get_settings_for_display('force_download')) === 'yes';

        $this->add_render_attribute('wrapper', 'class', 'maradigma-boat-pdf-download');

        $this->add_render_attribute('button', 'class', 'maradigma-boat-pdf-download__btn');
        $this->add_render_attribute('button', 'href', esc_url($pdfUrl));

        if ($openInNewTab) {
            $this->add_render_attribute('button', 'target', '_blank');
            $this->add_render_attribute('button', 'rel', 'noopener noreferrer');
        }

        if ($forceDownload) {
            $this->add_render_attribute('button', 'download', 'download');
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Elementor builds and escapes this widget-owned attribute string.
        echo '<div ' . $this->get_render_attribute_string('wrapper') . '>';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Elementor builds and escapes this widget-owned attribute string.
        echo '<a ' . $this->get_render_attribute_string('button') . '>';
        echo esc_html($buttonText);
        echo '</a>';
        echo '</div>';
    }
}

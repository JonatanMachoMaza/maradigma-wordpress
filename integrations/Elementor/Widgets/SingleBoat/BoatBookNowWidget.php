<?php

declare(strict_types=1);

namespace Maradigma\Integrations\Elementor\Widgets\SingleBoat;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Background;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Box_Shadow;
use Elementor\Group_Control_Typography;

/**
 * Elementor widget: Maradigma Boat Book Now
 *
 * Exposes Elementor controls to style the booking CTA button and the modal UI
 * rendered by the shared Maradigma booking renderer.
 */
final class BoatBookNowWidget extends BaseSingleBoatWidget
{
    /**
     * Returns the widget's stable Elementor identifier.
     */
    public function get_name(): string
    {
        return 'maradigma_boat_book_now';
    }

    /**
     * Returns the widget title shown in Elementor.
     */
    public function get_title(): string
    {
        return esc_html__('Maradigma Boat Book Now', 'maradigma');
    }

    /**
     * Returns the Elementor icon identifier for the widget.
     */
    public function get_icon(): string
    {
        return 'eicon-button';
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
        $shellSelector = '{{WRAPPER}} .md-booking-widget-shell';

        $buttonSelector = '{{WRAPPER}} .md-booking-widget-shell .md-btn[data-md-open]';
        $modalSelector = '{{WRAPPER}} .md-booking-widget-shell .md-modal__panel';
        $headerSelector = '{{WRAPPER}} .md-booking-widget-shell .md-modal__header';
        $bodySelector = '{{WRAPPER}} .md-booking-widget-shell .md-modal__body';
        $footerSelector = '{{WRAPPER}} .md-booking-widget-shell .md-modal__footer';
        $titleSelector = '{{WRAPPER}} .md-booking-widget-shell .md-modal__title';
        $stepSelector = '{{WRAPPER}} .md-booking-widget-shell .md-step';
        $stepActiveSelector = '{{WRAPPER}} .md-booking-widget-shell .md-step.is-active';
        $stepCompleteSelector = '{{WRAPPER}} .md-booking-widget-shell .md-step.is-complete';
        $fieldLabelSelector = '{{WRAPPER}} .md-booking-widget-shell .md-field label, {{WRAPPER}} .md-booking-widget-shell .md-label';
        $fieldInputSelector = '{{WRAPPER}} .md-booking-widget-shell .md-field input:not([type="checkbox"]), {{WRAPPER}} .md-booking-widget-shell .md-field select, {{WRAPPER}} .md-booking-widget-shell .md-field textarea, {{WRAPPER}} .md-booking-widget-shell .md-input';
        $sidebarCardSelector = '{{WRAPPER}} .md-booking-widget-shell .md-card';
        $sidebarTitleSelector = '{{WRAPPER}} .md-booking-widget-shell .md-side__title, {{WRAPPER}} .md-booking-widget-shell .md-card .md-side__section-title';
        $sidebarTextSelector = '{{WRAPPER}} .md-booking-widget-shell .md-side__meta, {{WRAPPER}} .md-booking-widget-shell .md-side__section, {{WRAPPER}} .md-booking-widget-shell .md-side__info, {{WRAPPER}} .md-booking-widget-shell .md-side__sub';
        $sidebarMoneySelector = '{{WRAPPER}} .md-booking-widget-shell .md-side__money, {{WRAPPER}} .md-booking-widget-shell .md-side__total-money';
        $finishCardSelector = '{{WRAPPER}} .md-booking-widget-shell .md-finish__card';
        $finishTitleSelector = '{{WRAPPER}} .md-booking-widget-shell .md-finish__title';
        $finishTextSelector = '{{WRAPPER}} .md-booking-widget-shell .md-finish__text, {{WRAPPER}} .md-booking-widget-shell .md-finish__label, {{WRAPPER}} .md-booking-widget-shell .md-finish__value';
        $innerPrimaryButtonSelector = '{{WRAPPER}} .md-booking-widget-shell .md-modal .md-btn--primary:not([data-md-open])';
        $innerGhostButtonSelector = '{{WRAPPER}} .md-booking-widget-shell .md-modal .md-btn--ghost';
        $innerSecondaryButtonSelector = '{{WRAPPER}} .md-booking-widget-shell .md-modal .md-btn--secondary';

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
                'default'     => 'Book now',
                'placeholder' => 'Book now',
            ]
        );

        $this->add_control(
            'calendar_display',
            [
                'label'   => esc_html__('Calendar display', 'maradigma'),
                'type'    => Controls_Manager::SELECT,
                'default' => 'inline',
                'options' => [
                    'popup'  => esc_html__('Popup', 'maradigma'),
                    'inline' => esc_html__('Inline', 'maradigma'),
                ],
            ]
        );

        $this->add_control(
            'calendar_months',
            [
                'label'   => esc_html__('Visible months', 'maradigma'),
                'type'    => Controls_Manager::SELECT,
                'default' => '1',
                'options' => [
                    '1' => esc_html__('1 month', 'maradigma'),
                    '2' => esc_html__('2 months', 'maradigma'),
                ],
                'condition' => [
                    'calendar_display' => 'popup',
                ],
            ]
        );

        $this->add_control(
            'calendar_selection_mode',
            [
                'label'   => esc_html__('Date selection mode', 'maradigma'),
                'type'    => Controls_Manager::SELECT,
                'default' => 'range',
                'options' => [
                    'range'  => esc_html__('Range', 'maradigma'),
                    'single' => esc_html__('Single day', 'maradigma'),
                ],
            ]
        );

        $this->add_control(
            'show_schedule_text',
            [
                'label'        => esc_html__('Show entry and exit time', 'maradigma'),
                'type'         => Controls_Manager::SWITCHER,
                'label_on'     => esc_html__('Show', 'maradigma'),
                'label_off'    => esc_html__('Hide', 'maradigma'),
                'return_value' => '1',
                'default'      => '1',
            ]
        );

        $this->add_control(
            'show_promo_code',
            [
                'label'        => esc_html__('Show promo code block', 'maradigma'),
                'type'         => Controls_Manager::SWITCHER,
                'label_on'     => esc_html__('Show', 'maradigma'),
                'label_off'    => esc_html__('Hide', 'maradigma'),
                'return_value' => '1',
                'default'      => '1',
            ]
        );

        $this->add_control(
            'show_children_included',
            [
                'label'        => esc_html__('Show children onboard option', 'maradigma'),
                'type'         => Controls_Manager::SWITCHER,
                'label_on'     => esc_html__('Show', 'maradigma'),
                'label_off'    => esc_html__('Hide', 'maradigma'),
                'return_value' => '1',
                'default'      => '1',
            ]
        );

        $this->add_control(
            'free_additional_label',
            [
                'label'   => esc_html__('Free additional services label', 'maradigma'),
                'type'    => Controls_Manager::SELECT,
                'default' => 'free',
                'options' => [
                    'free'     => esc_html__('Free', 'maradigma'),
                    'included' => esc_html__('Included', 'maradigma'),
                ],
            ]
        );

        $this->add_control(
            'buttons_position',
            [
                'label'   => esc_html__('Navigation buttons position', 'maradigma'),
                'type'    => Controls_Manager::SELECT,
                'default' => 'inline',
                'options' => [
                    'footer' => esc_html__('Modal footer', 'maradigma'),
                    'inline' => esc_html__('Inline with modal content', 'maradigma'),
                ],
            ]
        );

        $this->add_control(
            'redirect_url_after_booking',
            [
                'label'         => esc_html__('Redirect URL after booking', 'maradigma'),
                'type'          => Controls_Manager::URL,
                'placeholder'   => 'https://example.com/thank-you',
                'show_external' => false,
                'default'       => [
                    'url'         => '',
                    'is_external' => false,
                    'nofollow'    => false,
                ],
                'description'   => esc_html__('Optional. Used when the booking succeeds and no payment URL is returned by the API.', 'maradigma'),
            ]
        );

        $this->add_control(
            'api_expand',
            [
                'label'       => esc_html__('API expand (optional)', 'maradigma'),
                'type'        => Controls_Manager::TEXT,
                'default'     => '',
                'placeholder' => 'service_prices,service_additional_services',
            ]
        );

        $this->end_controls_section();

        /**
         * Global theme variables
         */
        $this->start_controls_section(
            'section_style_theme_variables',
            [
                'label' => esc_html__('Theme variables', 'maradigma'),
                'tab'   => Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_control(
            'theme_colors_heading',
            [
                'label' => esc_html__('Main colors', 'maradigma'),
                'type'  => Controls_Manager::HEADING,
            ]
        );

        $this->add_control(
            'theme_primary_color',
            [
                'label'     => esc_html__('Primary color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    $shellSelector => '--md-color-btn-primary-bg: {{VALUE}}; --md-color-payment-selected: {{VALUE}}; --md-color-text-link: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'theme_primary_text_color',
            [
                'label'     => esc_html__('Primary button text', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    $shellSelector => '--md-color-btn-primary-text: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'theme_ghost_bg_color',
            [
                'label'     => esc_html__('Ghost button background', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    $shellSelector => '--md-color-btn-ghost-bg: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'theme_ghost_text_color',
            [
                'label'     => esc_html__('Ghost button text', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    $shellSelector => '--md-color-btn-ghost-text: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'theme_title_color',
            [
                'label'     => esc_html__('Heading color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    $shellSelector => '--md-color-text-title: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'theme_body_color',
            [
                'label'     => esc_html__('Body text color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    $shellSelector => '--md-color-text-body: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'theme_muted_color',
            [
                'label'     => esc_html__('Muted text color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    $shellSelector => '--md-color-text-muted: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'theme_surface_color',
            [
                'label'     => esc_html__('Surface color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    $shellSelector => '--md-color-surface: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'theme_surface_soft_color',
            [
                'label'     => esc_html__('Soft surface color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    $shellSelector => '--md-color-surface-soft: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'theme_border_color',
            [
                'label'     => esc_html__('Base border color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    $shellSelector => '--md-color-border-base: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'theme_input_border_color',
            [
                'label'     => esc_html__('Input border color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    $shellSelector => '--md-color-border-input: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'theme_input_focus_border_color',
            [
                'label'     => esc_html__('Input focus color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    $shellSelector => '--md-color-border-input-focus: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'theme_overlay_color',
            [
                'label'     => esc_html__('Overlay color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    $shellSelector => '--md-color-overlay: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'theme_paynow_bg_color',
            [
                'label'     => esc_html__('Pay now box background', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    $shellSelector => '--md-color-surface-paynow: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'theme_paynow_border_color',
            [
                'label'     => esc_html__('Pay now box border', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    $shellSelector => '--md-color-surface-paynow-border: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'theme_payment_selected_bg_color',
            [
                'label'     => esc_html__('Selected payment background', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    $shellSelector => '--md-color-payment-selected-bg: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'theme_toggle_link_color',
            [
                'label'     => esc_html__('Toggle link color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    $shellSelector => '--md-color-toggle-link: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'theme_toggle_link_hover_color',
            [
                'label'     => esc_html__('Toggle link hover color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    $shellSelector => '--md-color-toggle-link-hover: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'theme_success_color',
            [
                'label'     => esc_html__('Success color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    $shellSelector => '--md-color-success: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'theme_error_color',
            [
                'label'     => esc_html__('Error color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    $shellSelector => '--md-color-error: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'theme_radius_heading',
            [
                'label'     => esc_html__('Radius and spacing', 'maradigma'),
                'type'      => Controls_Manager::HEADING,
                'separator' => 'before',
            ]
        );

        $this->add_responsive_control(
            'theme_button_radius_var',
            [
                'label'      => esc_html__('Button radius', 'maradigma'),
                'type'       => Controls_Manager::SLIDER,
                'size_units' => ['px', 'em', 'rem'],
                'range'      => [
                    'px' => ['min' => 0, 'max' => 80],
                ],
                'selectors'  => [
                    $shellSelector => '--md-btn-border-radius: {{SIZE}}{{UNIT}};',
                ],
            ]
        );

        $this->add_responsive_control(
            'theme_input_radius_var',
            [
                'label'      => esc_html__('Input radius', 'maradigma'),
                'type'       => Controls_Manager::SLIDER,
                'size_units' => ['px', 'em', 'rem'],
                'range'      => [
                    'px' => ['min' => 0, 'max' => 80],
                ],
                'selectors'  => [
                    $shellSelector => '--md-input-border-radius: {{SIZE}}{{UNIT}};',
                ],
            ]
        );

        $this->add_responsive_control(
            'theme_modal_radius_var',
            [
                'label'      => esc_html__('Modal/card radius', 'maradigma'),
                'type'       => Controls_Manager::SLIDER,
                'size_units' => ['px', 'em', 'rem'],
                'range'      => [
                    'px' => ['min' => 0, 'max' => 80],
                ],
                'selectors'  => [
                    $shellSelector => '--md-radius-lg: {{SIZE}}{{UNIT}};',
                ],
            ]
        );

        $this->add_responsive_control(
            'theme_modal_max_width_var',
            [
                'label'      => esc_html__('Modal max width', 'maradigma'),
                'type'       => Controls_Manager::SLIDER,
                'size_units' => ['px', '%'],
                'range'      => [
                    'px' => ['min' => 320, 'max' => 1600],
                    '%'  => ['min' => 30, 'max' => 100],
                ],
                'selectors'  => [
                    $shellSelector => '--md-modal-max-width: {{SIZE}}{{UNIT}};',
                ],
            ]
        );

        $this->add_responsive_control(
            'theme_sidebar_width_var',
            [
                'label'      => esc_html__('Sidebar width', 'maradigma'),
                'type'       => Controls_Manager::SLIDER,
                'size_units' => ['px', '%'],
                'range'      => [
                    'px' => ['min' => 240, 'max' => 600],
                    '%'  => ['min' => 20, 'max' => 50],
                ],
                'selectors'  => [
                    $shellSelector => '--md-step1-sidebar-width: {{SIZE}}{{UNIT}};',
                ],
            ]
        );

        $this->add_control(
            'theme_typography_heading',
            [
                'label'     => esc_html__('Typography variables', 'maradigma'),
                'type'      => Controls_Manager::HEADING,
                'separator' => 'before',
            ]
        );

        $this->add_control(
            'theme_font_family_var',
            [
                'label'     => esc_html__('Font family', 'maradigma'),
                'type'      => Controls_Manager::FONT,
                'selectors' => [
                    $shellSelector => '--md-font-family: {{VALUE}};',
                ],
            ]
        );

        $this->add_responsive_control(
            'theme_body_font_size_var',
            [
                'label'      => esc_html__('Body font size', 'maradigma'),
                'type'       => Controls_Manager::SLIDER,
                'size_units' => ['px', 'em', 'rem'],
                'range'      => [
                    'px' => ['min' => 10, 'max' => 28],
                ],
                'selectors'  => [
                    $shellSelector => '--md-body-font-size: {{SIZE}}{{UNIT}}; --md-font-size-base: {{SIZE}}{{UNIT}};',
                ],
            ]
        );

        $this->add_responsive_control(
            'theme_label_font_size_var',
            [
                'label'      => esc_html__('Label font size', 'maradigma'),
                'type'       => Controls_Manager::SLIDER,
                'size_units' => ['px', 'em', 'rem'],
                'range'      => [
                    'px' => ['min' => 10, 'max' => 28],
                ],
                'selectors'  => [
                    $shellSelector => '--md-label-font-size: {{SIZE}}{{UNIT}};',
                ],
            ]
        );

        $this->add_responsive_control(
            'theme_section_title_font_size_var',
            [
                'label'      => esc_html__('Section title size', 'maradigma'),
                'type'       => Controls_Manager::SLIDER,
                'size_units' => ['px', 'em', 'rem'],
                'range'      => [
                    'px' => ['min' => 12, 'max' => 36],
                ],
                'selectors'  => [
                    $shellSelector => '--md-section-title-font-size: {{SIZE}}{{UNIT}};',
                ],
            ]
        );

        $this->end_controls_section();

        /**
         * Open button
         */
        $this->start_controls_section(
            'section_style_open_button',
            [
                'label' => esc_html__('Open button', 'maradigma'),
                'tab'   => Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_responsive_control(
            'button_alignment',
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
                    'justify' => [
                        'title' => esc_html__('Justified', 'maradigma'),
                        'icon'  => 'eicon-text-align-justify',
                    ],
                ],
                'default'   => 'left',
                'selectors' => [
                    $shellSelector => 'text-align: {{VALUE}};',
                    $buttonSelector => 'display:inline-flex;',
                ],
            ]
        );

        $this->add_responsive_control(
            'button_width',
            [
                'label'      => esc_html__('Width', 'maradigma'),
                'type'       => Controls_Manager::SLIDER,
                'size_units' => ['%', 'px'],
                'range'      => [
                    '%' => ['min' => 1, 'max' => 100],
                    'px' => ['min' => 40, 'max' => 1200],
                ],
                'selectors'  => [
                    $buttonSelector => 'width: {{SIZE}}{{UNIT}};',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Typography::get_type(),
            [
                'name'     => 'button_typography',
                'selector' => $buttonSelector,
            ]
        );

        $this->add_responsive_control(
            'button_padding',
            [
                'label'      => esc_html__('Padding', 'maradigma'),
                'type'       => Controls_Manager::DIMENSIONS,
                'size_units' => ['px', 'em', 'rem'],
                'selectors'  => [
                    $buttonSelector => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        $this->add_responsive_control(
            'button_border_radius',
            [
                'label'      => esc_html__('Border radius', 'maradigma'),
                'type'       => Controls_Manager::DIMENSIONS,
                'size_units' => ['px', '%', 'em', 'rem'],
                'selectors'  => [
                    $buttonSelector => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Border::get_type(),
            [
                'name'     => 'button_border',
                'selector' => $buttonSelector,
            ]
        );

        $this->add_group_control(
            Group_Control_Box_Shadow::get_type(),
            [
                'name'     => 'button_shadow',
                'selector' => $buttonSelector,
            ]
        );

        $this->start_controls_tabs('tabs_open_button_states');

        $this->start_controls_tab(
            'tab_open_button_normal',
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
                    $buttonSelector => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'button_background_color',
            [
                'label'     => esc_html__('Background color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    $buttonSelector => 'background-color: {{VALUE}};',
                ],
            ]
        );

        $this->end_controls_tab();

        $this->start_controls_tab(
            'tab_open_button_hover',
            [
                'label' => esc_html__('Hover', 'maradigma'),
            ]
        );

        $this->add_control(
            'button_hover_text_color',
            [
                'label'     => esc_html__('Text color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    $buttonSelector . ':hover, ' . $buttonSelector . ':focus' => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'button_hover_background_color',
            [
                'label'     => esc_html__('Background color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    $buttonSelector . ':hover, ' . $buttonSelector . ':focus' => 'background-color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'button_hover_border_color',
            [
                'label'     => esc_html__('Border color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    $buttonSelector . ':hover, ' . $buttonSelector . ':focus' => 'border-color: {{VALUE}};',
                ],
            ]
        );

        $this->end_controls_tab();

        $this->start_controls_tab(
            'tab_open_button_disabled',
            [
                'label' => esc_html__('Disabled / Loading', 'maradigma'),
            ]
        );

        $this->add_control(
            'button_disabled_text_color',
            [
                'label'     => esc_html__('Text color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    $buttonSelector . '[disabled], ' . $buttonSelector . '.is-loading' => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'button_disabled_background_color',
            [
                'label'     => esc_html__('Background color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    $buttonSelector . '[disabled], ' . $buttonSelector . '.is-loading' => 'background-color: {{VALUE}};',
                ],
            ]
        );

        $this->end_controls_tab();

        $this->end_controls_tabs();
        $this->end_controls_section();

        /**
         * Modal panel
         */
        $this->start_controls_section(
            'section_style_modal_panel',
            [
                'label' => esc_html__('Modal panel', 'maradigma'),
                'tab'   => Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_control(
            'modal_overlay_color',
            [
                'label'     => esc_html__('Overlay color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .md-booking-widget-shell .md-modal__overlay' => 'background: {{VALUE}};',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Background::get_type(),
            [
                'name'     => 'modal_panel_background',
                'selector' => $modalSelector,
            ]
        );

        $this->add_responsive_control(
            'modal_panel_max_width',
            [
                'label'      => esc_html__('Max width', 'maradigma'),
                'type'       => Controls_Manager::SLIDER,
                'size_units' => ['px', '%'],
                'range'      => [
                    'px' => ['min' => 280, 'max' => 1600],
                    '%' => ['min' => 30, 'max' => 100],
                ],
                'selectors'  => [
                    $modalSelector => 'max-width: {{SIZE}}{{UNIT}};',
                ],
            ]
        );

        $this->add_responsive_control(
            'modal_panel_border_radius',
            [
                'label'      => esc_html__('Border radius', 'maradigma'),
                'type'       => Controls_Manager::DIMENSIONS,
                'size_units' => ['px', '%', 'em', 'rem'],
                'selectors'  => [
                    $modalSelector => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Border::get_type(),
            [
                'name'     => 'modal_panel_border',
                'selector' => $modalSelector,
            ]
        );

        $this->add_group_control(
            Group_Control_Box_Shadow::get_type(),
            [
                'name'     => 'modal_panel_shadow',
                'selector' => $modalSelector,
            ]
        );

        $this->end_controls_section();

        /**
         * Modal header
         */
        $this->start_controls_section(
            'section_style_modal_header',
            [
                'label' => esc_html__('Modal header', 'maradigma'),
                'tab'   => Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_group_control(
            Group_Control_Background::get_type(),
            [
                'name'     => 'modal_header_background',
                'selector' => $headerSelector,
            ]
        );

        $this->add_responsive_control(
            'modal_header_padding',
            [
                'label'      => esc_html__('Padding', 'maradigma'),
                'type'       => Controls_Manager::DIMENSIONS,
                'size_units' => ['px', 'em', 'rem'],
                'selectors'  => [
                    $headerSelector => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        $this->add_control(
            'modal_header_border_color',
            [
                'label'     => esc_html__('Bottom border color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    $headerSelector => 'border-bottom-color: {{VALUE}};',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Typography::get_type(),
            [
                'name'     => 'modal_title_typography',
                'selector' => $titleSelector,
            ]
        );

        $this->add_control(
            'modal_title_color',
            [
                'label'     => esc_html__('Title color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    $titleSelector => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'modal_close_color',
            [
                'label'     => esc_html__('Close icon color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .md-booking-widget-shell .md-modal__close' => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->end_controls_section();

        /**
         * Progress steps
         */
        $this->start_controls_section(
            'section_style_progress',
            [
                'label' => esc_html__('Progress steps', 'maradigma'),
                'tab'   => Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_group_control(
            Group_Control_Typography::get_type(),
            [
                'name'     => 'progress_label_typography',
                'selector' => '{{WRAPPER}} .md-booking-widget-shell .md-step__label',
            ]
        );

        $this->add_control(
            'progress_text_color',
            [
                'label'     => esc_html__('Text color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    $stepSelector . ', {{WRAPPER}} .md-booking-widget-shell .md-step__label' => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'progress_background_color',
            [
                'label'     => esc_html__('Background color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    $stepSelector => 'background-color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'progress_border_color',
            [
                'label'     => esc_html__('Border color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    $stepSelector => 'border-color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'progress_badge_background',
            [
                'label'     => esc_html__('Badge background', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .md-booking-widget-shell .md-step__badge' => 'background-color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'progress_badge_text_color',
            [
                'label'     => esc_html__('Badge text color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .md-booking-widget-shell .md-step__badge' => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'progress_active_background_color',
            [
                'label'     => esc_html__('Active background', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    $stepActiveSelector => 'background-color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'progress_active_border_color',
            [
                'label'     => esc_html__('Active border', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    $stepActiveSelector => 'border-color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'progress_active_badge_background',
            [
                'label'     => esc_html__('Active badge background', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    $stepActiveSelector . ' .md-step__badge' => 'background-color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'progress_active_badge_text_color',
            [
                'label'     => esc_html__('Active badge text color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    $stepActiveSelector . ' .md-step__badge' => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'progress_complete_badge_background',
            [
                'label'     => esc_html__('Completed badge background', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    $stepCompleteSelector . ' .md-step__badge' => 'background-color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'progress_complete_badge_text_color',
            [
                'label'     => esc_html__('Completed badge text color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    $stepCompleteSelector . ' .md-step__badge' => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->end_controls_section();

        /**
         * Modal body
         */
        $this->start_controls_section(
            'section_style_modal_body',
            [
                'label' => esc_html__('Modal body', 'maradigma'),
                'tab'   => Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_group_control(
            Group_Control_Background::get_type(),
            [
                'name'     => 'modal_body_background',
                'selector' => $bodySelector,
            ]
        );

        $this->add_responsive_control(
            'modal_body_padding',
            [
                'label'      => esc_html__('Padding', 'maradigma'),
                'type'       => Controls_Manager::DIMENSIONS,
                'size_units' => ['px', 'em', 'rem'],
                'selectors'  => [
                    $bodySelector => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        $this->add_control(
            'step_title_color',
            [
                'label'     => esc_html__('Step title color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .md-booking-widget-shell .md-step-title' => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Typography::get_type(),
            [
                'name'     => 'step_title_typography',
                'selector' => '{{WRAPPER}} .md-booking-widget-shell .md-step-title',
            ]
        );

        $this->end_controls_section();

        /**
         * Fields
         */
        $this->start_controls_section(
            'section_style_fields',
            [
                'label' => esc_html__('Fields', 'maradigma'),
                'tab'   => Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_control(
            'field_label_color',
            [
                'label'     => esc_html__('Label color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    $fieldLabelSelector => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Typography::get_type(),
            [
                'name'     => 'field_label_typography',
                'selector' => $fieldLabelSelector,
            ]
        );

        $this->add_control(
            'field_text_color',
            [
                'label'     => esc_html__('Input text color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    $fieldInputSelector => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'field_placeholder_color',
            [
                'label'     => esc_html__('Placeholder color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    $fieldInputSelector . '::placeholder' => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'field_background_color',
            [
                'label'     => esc_html__('Background color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    $fieldInputSelector => 'background-color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'field_border_color',
            [
                'label'     => esc_html__('Border color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    $fieldInputSelector => 'border-color: {{VALUE}};',
                ],
            ]
        );

        $this->add_responsive_control(
            'field_border_radius',
            [
                'label'      => esc_html__('Border radius', 'maradigma'),
                'type'       => Controls_Manager::DIMENSIONS,
                'size_units' => ['px', '%', 'em', 'rem'],
                'selectors'  => [
                    $fieldInputSelector => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        $this->add_responsive_control(
            'field_padding',
            [
                'label'      => esc_html__('Padding', 'maradigma'),
                'type'       => Controls_Manager::DIMENSIONS,
                'size_units' => ['px', 'em', 'rem'],
                'selectors'  => [
                    $fieldInputSelector => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        $this->add_control(
            'field_focus_border_color',
            [
                'label'     => esc_html__('Focus border color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    $fieldInputSelector . ':focus' => 'border-color: {{VALUE}};',
                ],
            ]
        );

        $this->end_controls_section();

        /**
         * Sidebar summary
         */
        $this->start_controls_section(
            'section_style_sidebar_card',
            [
                'label' => esc_html__('Summary card', 'maradigma'),
                'tab'   => Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_group_control(
            Group_Control_Background::get_type(),
            [
                'name'     => 'sidebar_card_background',
                'selector' => $sidebarCardSelector,
            ]
        );

        $this->add_group_control(
            Group_Control_Border::get_type(),
            [
                'name'     => 'sidebar_card_border',
                'selector' => $sidebarCardSelector,
            ]
        );

        $this->add_responsive_control(
            'sidebar_card_border_radius',
            [
                'label'      => esc_html__('Border radius', 'maradigma'),
                'type'       => Controls_Manager::DIMENSIONS,
                'size_units' => ['px', '%', 'em', 'rem'],
                'selectors'  => [
                    $sidebarCardSelector => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Box_Shadow::get_type(),
            [
                'name'     => 'sidebar_card_shadow',
                'selector' => $sidebarCardSelector,
            ]
        );

        $this->add_control(
            'sidebar_title_color',
            [
                'label'     => esc_html__('Title color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    $sidebarTitleSelector => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'sidebar_text_color',
            [
                'label'     => esc_html__('Text color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    $sidebarTextSelector => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'sidebar_money_color',
            [
                'label'     => esc_html__('Price color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    $sidebarMoneySelector => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->end_controls_section();

        /**
         * Footer buttons
         */
        $this->start_controls_section(
            'section_style_modal_buttons',
            [
                'label' => esc_html__('Modal buttons', 'maradigma'),
                'tab'   => Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_control(
            'modal_primary_buttons_heading',
            [
                'label' => esc_html__('Primary buttons', 'maradigma'),
                'type'  => Controls_Manager::HEADING,
            ]
        );

        $this->add_control(
            'modal_primary_button_text_color',
            [
                'label'     => esc_html__('Text color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    $innerPrimaryButtonSelector => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'modal_primary_button_background',
            [
                'label'     => esc_html__('Background color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    $innerPrimaryButtonSelector => 'background-color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'modal_ghost_buttons_heading',
            [
                'label' => esc_html__('Ghost buttons', 'maradigma'),
                'type'  => Controls_Manager::HEADING,
                'separator' => 'before',
            ]
        );

        $this->add_control(
            'modal_ghost_button_text_color',
            [
                'label'     => esc_html__('Text color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    $innerGhostButtonSelector => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'modal_ghost_button_background',
            [
                'label'     => esc_html__('Background color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    $innerGhostButtonSelector => 'background-color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'modal_secondary_buttons_heading',
            [
                'label' => esc_html__('Secondary buttons', 'maradigma'),
                'type'  => Controls_Manager::HEADING,
                'separator' => 'before',
            ]
        );

        $this->add_control(
            'modal_secondary_button_text_color',
            [
                'label'     => esc_html__('Text color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    $innerSecondaryButtonSelector => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'modal_secondary_button_background',
            [
                'label'     => esc_html__('Background color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    $innerSecondaryButtonSelector => 'background-color: {{VALUE}};',
                ],
            ]
        );

        $this->end_controls_section();

        /**
         * Success / finish step
         */
        $this->start_controls_section(
            'section_style_finish',
            [
                'label' => esc_html__('Success step', 'maradigma'),
                'tab'   => Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_group_control(
            Group_Control_Background::get_type(),
            [
                'name'     => 'finish_card_background',
                'selector' => $finishCardSelector,
            ]
        );

        $this->add_group_control(
            Group_Control_Border::get_type(),
            [
                'name'     => 'finish_card_border',
                'selector' => $finishCardSelector,
            ]
        );

        $this->add_group_control(
            Group_Control_Box_Shadow::get_type(),
            [
                'name'     => 'finish_card_shadow',
                'selector' => $finishCardSelector,
            ]
        );

        $this->add_control(
            'finish_title_color',
            [
                'label'     => esc_html__('Title color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    $finishTitleSelector => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'finish_text_color',
            [
                'label'     => esc_html__('Text color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    $finishTextSelector => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->end_controls_section();
    }

    /**
     * Renders the component output.
     */
    protected function render(): void {
        $expandRaw = trim((string) $this->get_settings_for_display('api_expand'));

        /**
         * Allow comma-separated API expand values in Elementor control.
         * Example:
         * service_prices,service_additional_services
         *
         * Previous behavior wrapped the whole string as a single entry.
         */
        $expand = [];
        if ($expandRaw !== '') {
            $expand = array_values(array_filter(array_map(
                static fn($value): string => trim((string) $value),
                explode(',', $expandRaw)
            ), static fn(string $value): bool => $value !== ''));
        }

        $boatId = trim((string) $this->getBoatIdFromCurrentPost());

        $btnText = trim((string) $this->get_settings_for_display('button_text'));
        if ($btnText === '') {
            $btnText = 'Book now';
        }

        $redirectUrlControl = $this->get_settings_for_display('redirect_url_after_booking');
        $redirectUrl = '';

        if (is_array($redirectUrlControl) && !empty($redirectUrlControl['url'])) {
            $redirectUrl = (string) $redirectUrlControl['url'];
        }

        $calendarDisplay = trim((string) $this->get_settings_for_display('calendar_display'));
        if ($calendarDisplay === '' || !in_array($calendarDisplay, ['popup', 'inline'], true)) {
            $calendarDisplay = 'inline';
        }

        $calendarMonths = trim((string) $this->get_settings_for_display('calendar_months'));
        if ($calendarMonths === '' || !in_array($calendarMonths, ['1', '2'], true)) {
            $calendarMonths = '1';
        }

        if ($calendarDisplay === 'inline') {
            $calendarMonths = '1';
        }

        $calendarSelectionMode = trim((string) $this->get_settings_for_display('calendar_selection_mode'));
        if ($calendarSelectionMode === '' || !in_array($calendarSelectionMode, ['range', 'single'], true)) {
            $calendarSelectionMode = 'range';
        }

        $showScheduleText = (string) $this->get_settings_for_display('show_schedule_text');
        $showPromoCode = (string) $this->get_settings_for_display('show_promo_code');
        $showChildrenIncluded = (string) $this->get_settings_for_display('show_children_included');
        $freeAdditionalLabel = trim((string) $this->get_settings_for_display('free_additional_label'));
        $buttonsPosition = trim((string) $this->get_settings_for_display('buttons_position'));

        $showScheduleText = $showScheduleText === '1' ? '1' : '0';
        $showPromoCode = $showPromoCode === '1' ? '1' : '0';
        $showChildrenIncluded = $showChildrenIncluded === '1' ? '1' : '0';

        if ($freeAdditionalLabel === '' || !in_array($freeAdditionalLabel, ['free', 'included'], true)) {
            $freeAdditionalLabel = 'free';
        }

        if ($buttonsPosition === '' || !in_array($buttonsPosition, ['footer', 'inline'], true)) {
            $buttonsPosition = 'inline';
        }

        /**
         * 1) Real boat context available:
         *    render the real booking widget/shortcode.
         */
        if ($boatId !== '') {
            echo '<div class="md-booking-widget-shell">';

            $bookingHtml = \Maradigma\ShortcodeRegistry::renderBoatBooking([
                'id'                      => $boatId,
                'button_text'             => (string) $btnText,
                'redirect_url_success'    => $redirectUrl,
                'calendar_display'        => $calendarDisplay,
                'calendar_months'         => $calendarMonths,
                'calendar_selection_mode' => $calendarSelectionMode,
                'show_schedule_text'      => $showScheduleText,
                'show_promo_code'         => $showPromoCode,
                'show_children_included'  => $showChildrenIncluded,
                'free_additional_label'   => $freeAdditionalLabel,
                'buttons_position'        => $buttonsPosition,
            ]);

            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Booking renderer returns plugin-generated markup with escaped attributes and content.
            echo $bookingHtml;

            echo '</div>';
            return;
        }

        /**
         * 2) Elementor editor / template without boat context:
         *    render a design-time preview button so the user can style it.
         *
         * IMPORTANT:
         * - Keep the same shell/class structure used by style controls:
         *   .md-booking-widget-shell .md-btn[data-md-open]
        * - Do not render the real booking shortcode here, because it would
        *   output the "missing id/slug" notice and block the design workflow.
        */
        if ($this->isElementorEditor()) {
            $btnText = \Maradigma\Support\MultilangAdapter::translateEditableDefault(
                $btnText,
                'Book now',
                (string) __('Book now', 'maradigma'),
                'Maradigma Elementor Widgets',
                'boat_book_now_button_text'
            );

            $wrapperAttributes = [
                'class'         => 'md-btn md-btn--primary md-booking-widget-preview-button',
                'type'          => 'button',
                'data-md-open'  => 'preview',
                'aria-disabled' => 'true',
                'disabled'      => 'disabled',
            ];

            echo '<div class="md-booking-widget-shell md-booking-widget-shell--preview">';

            echo '<button';
            foreach ($wrapperAttributes as $attrKey => $attrValue) {
                echo ' ' . esc_attr((string) $attrKey) . '="' . esc_attr((string) $attrValue) . '"';
            }
            echo '>';
            echo esc_html($btnText);
            echo '</button>';

            echo '<div class="maradigma-elementor-notice" style="margin-top:10px;padding:10px 12px;border:1px solid #e5e5e5;background:#fff;border-radius:6px;">';
            echo '<strong style="display:block;margin:0 0 4px;">' . esc_html__('Maradigma', 'maradigma') . '</strong>';
            echo '<span>' . esc_html__('Preview mode: open a post linked to a Maradigma boat to test the real booking flow.', 'maradigma') . '</span>';
            echo '</div>';

            echo '</div>';
            return;
        }

        /**
         * 3) Frontend without boat context:
         *    show a real notice because this is a configuration/content issue.
         */
        $this->renderMissingContextNotice(
            (string) __('Select a Maradigma boat in this post first.', 'maradigma')
        );
    }
}

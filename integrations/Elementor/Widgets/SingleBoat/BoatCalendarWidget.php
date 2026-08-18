<?php

declare(strict_types=1);

namespace Maradigma\Integrations\Elementor\Widgets\SingleBoat;

use Elementor\Controls_Manager;
use Maradigma\BoatCardEngine;

/**
 * Elementor widget: Maradigma Boat Calendar
 *
 * Renders the availability calendar for the current boat context.
 *
 * Behavior (client-friendly):
 * - Always shows Availability vs Not available.
 * - Optional "Option" (provisional reservation) highlighting can be enabled/disabled.
 *   - If disabled: option days are treated as "Booked" (not available color).
 *   - If enabled: option days use the "Option" color (yellow) when booking status matches option_statuses.
 *
 * Implementation notes:
 * - Delegates rendering to the shortcode {@see \Maradigma\ShortcodeRegistry::renderBoatCalendar()}
 *   so Elementor output stays consistent with shortcode behavior.
 * - The calendar loads ranges via the WP REST proxy:
 *   GET /wp-json/maradigma/v1/calendar?boat=... (AjaxController route).
 */
final class BoatCalendarWidget extends BaseSingleBoatWidget
{
    /**
     * Returns the widget's stable Elementor identifier.
     */
    public function get_name(): string
    {
        return 'maradigma_boat_calendar';
    }

    /**
     * Returns the widget title shown in Elementor.
     */
    public function get_title(): string
    {
        return esc_html__('Maradigma Boat Calendar', 'maradigma');
    }

    /**
     * Returns the Elementor icon identifier for the widget.
     */
    public function get_icon(): string
    {
        return 'eicon-calendar';
    }

    /**
     * Returns the Elementor categories assigned to the widget.
     */
    public function get_categories(): array
    {
        return ['maradigma'];
    }

    /**
     * Widget controls (Elementor editor panel).
     *
     * Client expectations:
     * - Simple controls: months, start month, legend, colors.
     * - Single switch to show/hide the "Option" state.
     * - No need to expose numeric status codes to the client (we keep defaults).
     */
    protected function register_controls(): void
    {
        // ─────────────────────────────────────────────
        // Content
        // ─────────────────────────────────────────────
        $this->start_controls_section(
            'section_content',
            [
                'label' => esc_html__('Calendar', 'maradigma'),
                'tab'   => Controls_Manager::TAB_CONTENT,
            ]
        );

        $this->add_control(
            'months',
            [
                'label'       => esc_html__('Months to show', 'maradigma'),
                'type'        => Controls_Manager::NUMBER,
                'min'         => 1,
                'max'         => 24,
                'step'        => 1,
                'default'     => 12,
                'description' => esc_html__('How many months are available in the month selector.', 'maradigma'),
            ]
        );

        $this->add_control(
            'start_month',
            [
                'label'   => esc_html__('Start month', 'maradigma'),
                'type'    => Controls_Manager::SELECT,
                'default' => 'current',
                'options' => [
                    'current' => esc_html__('Current month', 'maradigma'),
                    'next'    => esc_html__('Next month', 'maradigma'),
                ],
            ]
        );

        $this->add_control(
            'show_legend',
            [
                'label'        => esc_html__('Show legend', 'maradigma'),
                'type'         => Controls_Manager::SWITCHER,
                'label_on'     => esc_html__('Yes', 'maradigma'),
                'label_off'    => esc_html__('No', 'maradigma'),
                'return_value' => '1',
                'default'      => '1',
            ]
        );

        $this->add_control(
            'show_option_state',
            [
                'label'        => esc_html__('Show "Option" state', 'maradigma'),
                'type'         => Controls_Manager::SWITCHER,
                'label_on'     => esc_html__('Yes', 'maradigma'),
                'label_off'    => esc_html__('No', 'maradigma'),
                'return_value' => '1',
                'default'      => '1',
                'description'  => esc_html__('If disabled, provisional reservations will be displayed as "Booked".', 'maradigma'),
            ]
        );

        $this->end_controls_section();

        // ─────────────────────────────────────────────
        // Style
        // ─────────────────────────────────────────────
        $this->start_controls_section(
            'section_style',
            [
                'label' => esc_html__('Colors', 'maradigma'),
                'tab'   => Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_control(
            'color_available',
            [
                'label'   => esc_html__('Available color', 'maradigma'),
                'type'    => Controls_Manager::COLOR,
                'default' => '#d4edda',
            ]
        );

        $this->add_control(
            'color_booked',
            [
                'label'   => esc_html__('Not available color', 'maradigma'),
                'type'    => Controls_Manager::COLOR,
                'default' => '#ffc0bd',
            ]
        );

        $this->add_control(
            'color_option',
            [
                'label'     => esc_html__('Option color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'default'   => '#ffe8a1',
                'condition' => [
                    'show_option_state' => '1',
                ],
            ]
        );

        $this->end_controls_section();
    }

    /**
     * Frontend render.
     *
     * Resolves the boat context (id/slug) and prints the calendar shortcode.
     *
     * Important:
     * - The widget decides presentation options only (months, legend, colors, whether
     *   the "Option" state should be visible).
     * - The widget must NOT decide which backend booking status codes represent an option.
     *   That responsibility belongs to the shortcode/backend defaults.
     */
    protected function render(): void
    {
        // We only need a valid context to know which boat we're in.
        $ctx = $this->resolveContext([]);

        if (!($ctx['ok'] ?? false) || !is_array($ctx['data'] ?? null)) {
            echo '<div class="maradigma-boat-calendar maradigma-boat-calendar--empty">';
            echo esc_html__('Boat context not available.', 'maradigma');
            echo '</div>';
            return;
        }

        $boat = (array) $ctx['data'];

        // Best-effort identifier resolution (keep consistent with your API payload keys)
        $identifier = (string) (
            $boat['id_group_item']
            ?? $boat['id_gi']
            ?? $boat['id']
            ?? $boat['slug']
            ?? ''
        );

        if ($identifier === '') {
            echo '<div class="maradigma-boat-calendar maradigma-boat-calendar--empty">';
            echo esc_html__('Boat identifier not available.', 'maradigma');
            echo '</div>';
            return;
        }

        // Settings
        $months     = (int) $this->get_settings_for_display('months');
        $startMonth = (string) $this->get_settings_for_display('start_month');

        $showLegend = ((string) $this->get_settings_for_display('show_legend')) === '1' ? '1' : '0';

        // Single switch for option visibility
        $showOptionState = ((string) $this->get_settings_for_display('show_option_state')) === '1';

        // Clamp months (defense-in-depth)
        if ($months < 1) {
            $months = 12;
        }
        if ($months > 24) {
            $months = 24;
        }

        $colorAvailable = (string) $this->get_settings_for_display('color_available');
        $colorBooked    = (string) $this->get_settings_for_display('color_booked');
        $colorOption    = (string) $this->get_settings_for_display('color_option');

        // If the Option state is disabled, we do not request booking status from backend.
        // In that scenario the calendar will treat option bookings as standard booked days.
        $includeBookingStatus = $showOptionState ? '1' : '0';

        // Build shortcode
        // NOTE: We intentionally do NOT pass option_statuses here.
        // The shortcode/backend owns that decision and applies its own default.
        $shortcode = sprintf(
            '[maradigma_boat_calendar id="%s" months="%d" start_month="%s" show_legend="%s" include_booking_status="%s" color_available="%s" color_booked="%s" color_option="%s"]',
            esc_attr($identifier),
            $months,
            esc_attr($startMonth === 'next' ? 'next' : 'current'),
            esc_attr($showLegend),
            esc_attr($includeBookingStatus),
            esc_attr($colorAvailable !== '' ? $colorAvailable : '#d4edda'),
            esc_attr($colorBooked !== '' ? $colorBooked : '#ffc0bd'),
            esc_attr($colorOption !== '' ? $colorOption : '#ffe8a1')
        );

        echo '<div class="maradigma-boat-calendar">';
        echo wp_kses((string) do_shortcode($shortcode), BoatCardEngine::getAllowedHtml());
        echo '</div>';
    }
}

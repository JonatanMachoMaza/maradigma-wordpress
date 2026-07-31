<?php

declare(strict_types=1);

namespace Maradigma\Integrations\Elementor\Widgets\SingleBoat;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Background;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Box_Shadow;
use Elementor\Group_Control_Typography;
use Maradigma\Support\BoatAdditionalServicesRenderer;

/**
 * Elementor widget: Maradigma Boat Additional Services
 *
 * UX improvements:
 * - Customer-friendly badge labels (Required/Optional/Free, Per booking/Per day, Pay online/At the port/At the office).
 * - Optional tooltip text (title attribute) explaining each badge.
 * - Separator between badges to avoid "MandatoryBookingPay..." glued output even under aggressive theme CSS.
 *
 * VAT improvements:
 * - Same VAT UX as BoatPriceWidget: vat_mode + vat_position + custom texts.
 * - Can show base or total amount depending on vat_mode (no calculations; uses API fields).
 */
final class BoatAdditionalServicesWidget extends BaseSingleBoatWidget
{
    /**
     * Returns the widget's stable Elementor identifier.
     */
    public function get_name(): string
    {
        return 'maradigma_boat_additional_services';
    }

    /**
     * Returns the widget title shown in Elementor.
     */
    public function get_title(): string
    {
        return esc_html__('Maradigma Boat Additional Services', 'maradigma');
    }

    /**
     * Returns the Elementor icon identifier for the widget.
     */
    public function get_icon(): string
    {
        return 'eicon-cart';
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
    // -----------------------
    // CONTENT
    // -----------------------
    $this->start_controls_section(
        'section_content',
        [
            'label' => esc_html__('Content', 'maradigma'),
            'tab'   => Controls_Manager::TAB_CONTENT,
        ]
    );

    $this->add_control(
        'title',
        [
            'label'   => esc_html__('Title', 'maradigma'),
            'type'    => Controls_Manager::TEXT,
            'default' => 'Additional services',
        ]
    );

    $this->add_control(
        'show_title',
        [
            'label'        => esc_html__('Show title', 'maradigma'),
            'type'         => Controls_Manager::SWITCHER,
            'label_on'     => esc_html__('Yes', 'maradigma'),
            'label_off'    => esc_html__('No', 'maradigma'),
            'return_value' => 'yes',
            'default'      => 'yes',
        ]
    );

    $this->add_control(
        'layout',
        [
            'label'   => esc_html__('Layout', 'maradigma'),
            'type'    => Controls_Manager::SELECT,
            'default' => 'blocks',
            'options' => [
                'blocks' => esc_html__('Blocks (list)', 'maradigma'),
                'table'  => esc_html__('Table', 'maradigma'),
            ],
        ]
    );

    $this->add_control(
        'show_headers',
        [
            'label'        => esc_html__('Show table headers', 'maradigma'),
            'type'         => Controls_Manager::SWITCHER,
            'label_on'     => esc_html__('Yes', 'maradigma'),
            'label_off'    => esc_html__('No', 'maradigma'),
            'return_value' => 'yes',
            'default'      => 'yes',
            'condition'    => [
                'layout' => 'table',
            ],
        ]
    );

    $this->add_control(
        'group_by_category',
        [
            'label'        => esc_html__('Group by category', 'maradigma'),
            'type'         => Controls_Manager::SWITCHER,
            'label_on'     => esc_html__('Yes', 'maradigma'),
            'label_off'    => esc_html__('No', 'maradigma'),
            'return_value' => 'yes',
            'default'      => 'yes',
        ]
    );

    $this->add_control(
        'show_category_title',
        [
            'label'        => esc_html__('Show category title', 'maradigma'),
            'type'         => Controls_Manager::SWITCHER,
            'label_on'     => esc_html__('Yes', 'maradigma'),
            'label_off'    => esc_html__('No', 'maradigma'),
            'return_value' => 'yes',
            'default'      => 'yes',
            'condition'    => [
                'group_by_category' => 'yes',
            ],
        ]
    );

    // ✅ Master (compat) + switches separados
    $this->add_control(
        'show_badges',
        [
            'label'        => esc_html__('Show badges', 'maradigma'),
            'type'         => Controls_Manager::SWITCHER,
            'label_on'     => esc_html__('Yes', 'maradigma'),
            'label_off'    => esc_html__('No', 'maradigma'),
            'return_value' => 'yes',
            'default'      => 'yes',
        ]
    );

    $this->add_control(
        'show_badge_optional_type',
        [
            'label'        => esc_html__('Show badge: Required / Optional / Free', 'maradigma'),
            'type'         => Controls_Manager::SWITCHER,
            'label_on'     => esc_html__('Yes', 'maradigma'),
            'label_off'    => esc_html__('No', 'maradigma'),
            'return_value' => 'yes',
            'default'      => 'yes',
            'condition'    => [
                'show_badges' => 'yes',
            ],
        ]
    );

    $this->add_control(
        'show_badge_price_type',
        [
            'label'        => esc_html__('Show badge: Per booking / Per day', 'maradigma'),
            'type'         => Controls_Manager::SWITCHER,
            'label_on'     => esc_html__('Yes', 'maradigma'),
            'label_off'    => esc_html__('No', 'maradigma'),
            'return_value' => 'yes',
            'default'      => 'yes',
            'condition'    => [
                'show_badges' => 'yes',
            ],
        ]
    );

    $this->add_control(
        'show_badge_payment',
        [
            'label'        => esc_html__('Show badge: Payment (online/port/office)', 'maradigma'),
            'type'         => Controls_Manager::SWITCHER,
            'label_on'     => esc_html__('Yes', 'maradigma'),
            'label_off'    => esc_html__('No', 'maradigma'),
            'return_value' => 'yes',
            'default'      => 'yes',
            'condition'    => [
                'show_badges' => 'yes',
            ],
        ]
    );

    $this->add_control(
        'badge_style',
        [
            'label'   => esc_html__('Badge style', 'maradigma'),
            'type'    => Controls_Manager::SELECT,
            'default' => 'friendly',
            'options' => [
                'friendly' => esc_html__('Friendly (recommended)', 'maradigma'),
                'raw'      => esc_html__('Raw (API names)', 'maradigma'),
            ],
            'condition' => [
                'show_badges' => 'yes',
            ],
        ]
    );

    $this->add_control(
        'show_description',
        [
            'label'        => esc_html__('Show description (if provided)', 'maradigma'),
            'type'         => Controls_Manager::SWITCHER,
            'label_on'     => esc_html__('Yes', 'maradigma'),
            'label_off'    => esc_html__('No', 'maradigma'),
            'return_value' => 'yes',
            'default'      => 'no',
        ]
    );

    $this->add_control(
        'show_quantity',
        [
            'label'        => esc_html__('Show quantity (if > 1)', 'maradigma'),
            'type'         => Controls_Manager::SWITCHER,
            'label_on'     => esc_html__('Yes', 'maradigma'),
            'label_off'    => esc_html__('No', 'maradigma'),
            'return_value' => 'yes',
            'default'      => 'yes',
        ]
    );

    $this->add_control(
        'price_display',
        [
            'label'   => esc_html__('Price display (source preference)', 'maradigma'),
            'type'    => Controls_Manager::SELECT,
            'default' => 'total_html',
            'options' => [
                'total_html' => esc_html__('Prefer total (formatted/html)', 'maradigma'),
                'total'      => esc_html__('Prefer total (formatted)', 'maradigma'),
                'base'       => esc_html__('Prefer base (formatted)', 'maradigma'),
                'vat'        => esc_html__('Prefer VAT (formatted)', 'maradigma'),
                'raw_total'  => esc_html__('Prefer raw total_price', 'maradigma'),
                'raw_base'   => esc_html__('Prefer raw price', 'maradigma'),
            ],
        ]
    );

    $this->end_controls_section();

    // -----------------------
    // VAT (KEY)
    // -----------------------
    $this->start_controls_section(
        'section_vat',
        [
            'label' => esc_html__('VAT', 'maradigma'),
            'tab'   => Controls_Manager::TAB_CONTENT,
        ]
    );

    $this->add_control(
        'vat_mode',
        [
            'label'   => esc_html__('VAT display mode', 'maradigma'),
            'type'    => Controls_Manager::SELECT,
            'default' => 'included',
            'options' => [
                'included'     => esc_html__('Show "VAT included"', 'maradigma'),
                'excluded'     => esc_html__('Show "+ VAT"', 'maradigma'),
                'hidden_base'  => esc_html__('Hide VAT reference (base price)', 'maradigma'),
                'hidden_total' => esc_html__('Hide VAT reference (total price)', 'maradigma'),
            ],
        ]
    );

    $this->add_control(
        'vat_position',
        [
            'label'   => esc_html__('VAT text position', 'maradigma'),
            'type'    => Controls_Manager::SELECT,
            'default' => 'below',
            'options' => [
                'below'   => esc_html__('Below price (new line)', 'maradigma'),
                'inline'  => esc_html__('Same line (inline)', 'maradigma'),
                'tooltip' => esc_html__('Tooltip / info icon', 'maradigma'),
            ],
            'condition' => [
                'vat_mode' => ['included', 'excluded'],
            ],
        ]
    );

    $this->add_control(
        'vat_text_included',
        [
            'label'       => esc_html__('VAT included text', 'maradigma'),
            'type'        => Controls_Manager::TEXT,
            'default'     => 'VAT included',
            'placeholder' => 'VAT included',
            'condition'   => [
                'vat_mode' => ['included', 'excluded'],
            ],
        ]
    );

    $this->add_control(
        'vat_text_excluded',
        [
            'label'       => esc_html__('VAT excluded text', 'maradigma'),
            'type'        => Controls_Manager::TEXT,
            'default'     => '+ VAT',
            'placeholder' => '+ VAT',
            'condition'   => [
                'vat_mode' => ['included', 'excluded'],
            ],
        ]
    );

    $this->end_controls_section();

    // -----------------------
    // PRICE FORMAT
    // -----------------------
    $this->start_controls_section(
        'section_format',
        [
            'label' => esc_html__('Price format', 'maradigma'),
            'tab'   => Controls_Manager::TAB_CONTENT,
        ]
    );

    $this->add_control(
        'currency_display',
        [
            'label'   => esc_html__('Currency display', 'maradigma'),
            'type'    => Controls_Manager::SELECT,
            'default' => 'symbol',
            'options' => [
                'symbol' => esc_html__('Symbol (€, $, £)', 'maradigma'),
                'iso'    => esc_html__('ISO (EUR, USD...)', 'maradigma'),
            ],
        ]
    );

    $this->add_control(
        'decimals_mode',
        [
            'label'   => esc_html__('Decimals', 'maradigma'),
            'type'    => Controls_Manager::SELECT,
            'default' => 'auto',
            'options' => [
                'auto' => esc_html__('Auto', 'maradigma'),
                '0'    => esc_html__('0', 'maradigma'),
                '2'    => esc_html__('2', 'maradigma'),
            ],
        ]
    );

    $this->add_control(
        'thousands_sep',
        [
            'label'       => esc_html__('Thousands separator', 'maradigma'),
            'type'        => Controls_Manager::TEXT,
            'default'     => '.',
            'placeholder' => '.',
        ]
    );

    $this->add_control(
        'decimal_sep',
        [
            'label'       => esc_html__('Decimal separator', 'maradigma'),
            'type'        => Controls_Manager::TEXT,
            'default'     => ',',
            'placeholder' => ',',
        ]
    );

    $this->end_controls_section();

    // -----------------------
    // STYLE: HEADINGS
    // -----------------------
    $this->start_controls_section(
        'section_style_headings',
        [
            'label' => esc_html__('Headings', 'maradigma'),
            'tab'   => Controls_Manager::TAB_STYLE,
        ]
    );

    $this->add_control(
        'main_title_heading',
        [
            'label' => esc_html__('Main title', 'maradigma'),
            'type'  => Controls_Manager::HEADING,
            'condition' => [
                'show_title' => 'yes',
            ],
        ]
    );

    $this->add_group_control(
        Group_Control_Typography::get_type(),
        [
            'name'      => 'title_typography',
            'selector'  => '{{WRAPPER}} .maradigma-boat-additionals__title',
            'condition' => [
                'show_title' => 'yes',
            ],
        ]
    );

    $this->add_control(
        'title_color',
        [
            'label'     => esc_html__('Title color', 'maradigma'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .maradigma-boat-additionals' => '--maradigma-boat-additionals-title-color: {{VALUE}};',
            ],
            'condition' => [
                'show_title' => 'yes',
            ],
        ]
    );

    $this->add_responsive_control(
        'title_spacing',
        [
            'label'      => esc_html__('Title bottom spacing', 'maradigma'),
            'type'       => Controls_Manager::SLIDER,
            'size_units' => ['px', 'em', 'rem'],
            'range'      => [
                'px' => ['min' => 0, 'max' => 100],
            ],
            'selectors'  => [
                '{{WRAPPER}} .maradigma-boat-additionals__title' => 'margin-bottom: {{SIZE}}{{UNIT}};',
            ],
            'condition' => [
                'show_title' => 'yes',
            ],
        ]
    );

    $this->add_control(
        'category_heading',
        [
            'label'     => esc_html__('Category title', 'maradigma'),
            'type'      => Controls_Manager::HEADING,
            'separator' => 'before',
            'condition' => [
                'group_by_category'   => 'yes',
                'show_category_title' => 'yes',
            ],
        ]
    );

    $this->add_group_control(
        Group_Control_Typography::get_type(),
        [
            'name'      => 'category_typography',
            'selector'  => '{{WRAPPER}} .maradigma-boat-additionals__category',
            'condition' => [
                'group_by_category'   => 'yes',
                'show_category_title' => 'yes',
            ],
        ]
    );

    $this->add_control(
        'category_color',
        [
            'label'     => esc_html__('Category color', 'maradigma'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .maradigma-boat-additionals' => '--maradigma-boat-additionals-category-color: {{VALUE}};',
            ],
            'condition' => [
                'group_by_category'   => 'yes',
                'show_category_title' => 'yes',
            ],
        ]
    );

    $this->add_responsive_control(
        'category_spacing',
        [
            'label'      => esc_html__('Category spacing', 'maradigma'),
            'type'       => Controls_Manager::DIMENSIONS,
            'size_units' => ['px', 'em', 'rem'],
            'selectors'  => [
                '{{WRAPPER}} .maradigma-boat-additionals__category' => 'margin-top: {{TOP}}{{UNIT}}; margin-bottom: {{BOTTOM}}{{UNIT}};',
            ],
            'condition' => [
                'group_by_category'   => 'yes',
                'show_category_title' => 'yes',
            ],
        ]
    );

    $this->end_controls_section();

    // -----------------------
    // STYLE: ROWS / TABLE
    // -----------------------
    $this->start_controls_section(
        'section_style_container',
        [
            'label' => esc_html__('Rows and table', 'maradigma'),
            'tab'   => Controls_Manager::TAB_STYLE,
        ]
    );

    $this->add_responsive_control(
        'rows_gap',
        [
            'label'      => esc_html__('Rows gap', 'maradigma'),
            'type'       => Controls_Manager::SLIDER,
            'size_units' => ['px', 'em', 'rem'],
            'range'      => [
                'px' => ['min' => 0, 'max' => 100],
            ],
            'selectors'  => [
                '{{WRAPPER}} .maradigma-boat-additionals' => '--maradigma-boat-additionals-row-gap: {{SIZE}}{{UNIT}};',
            ],
            'condition' => [
                'layout' => 'blocks',
            ],
        ]
    );

    $this->add_group_control(
        Group_Control_Background::get_type(),
        [
            'name'     => 'row_background',
            'selector' => '{{WRAPPER}} .maradigma-boat-additionals__row, {{WRAPPER}} .maradigma-boat-additionals__table',
        ]
    );

    $this->add_group_control(
        Group_Control_Border::get_type(),
        [
            'name'     => 'row_border',
            'selector' => '{{WRAPPER}} .maradigma-boat-additionals__row, {{WRAPPER}} .maradigma-boat-additionals__table',
        ]
    );

    $this->add_responsive_control(
        'row_border_radius',
        [
            'label'      => esc_html__('Border radius', 'maradigma'),
            'type'       => Controls_Manager::DIMENSIONS,
            'size_units' => ['px', '%', 'em', 'rem'],
            'selectors'  => [
                '{{WRAPPER}} .maradigma-boat-additionals__row, {{WRAPPER}} .maradigma-boat-additionals__table' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
        ]
    );

    $this->add_group_control(
        Group_Control_Box_Shadow::get_type(),
        [
            'name'     => 'row_shadow',
            'selector' => '{{WRAPPER}} .maradigma-boat-additionals__row, {{WRAPPER}} .maradigma-boat-additionals__table',
        ]
    );

    $this->add_responsive_control(
        'row_padding',
        [
            'label'      => esc_html__('Row padding', 'maradigma'),
            'type'       => Controls_Manager::DIMENSIONS,
            'size_units' => ['px', 'em', 'rem'],
            'selectors'  => [
                '{{WRAPPER}} .maradigma-boat-additionals__row' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
            'condition' => [
                'layout' => 'blocks',
            ],
        ]
    );

    $this->add_responsive_control(
        'table_cell_padding',
        [
            'label'      => esc_html__('Cell padding', 'maradigma'),
            'type'       => Controls_Manager::DIMENSIONS,
            'size_units' => ['px', 'em', 'rem'],
            'selectors'  => [
                '{{WRAPPER}} .maradigma-boat-additionals__table th, {{WRAPPER}} .maradigma-boat-additionals__td' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
            'condition' => [
                'layout' => 'table',
            ],
        ]
    );

    $this->end_controls_section();

    // -----------------------
    // STYLE: CONTENT
    // -----------------------
    $this->start_controls_section(
        'section_style_content',
        [
            'label' => esc_html__('Service content', 'maradigma'),
            'tab'   => Controls_Manager::TAB_STYLE,
        ]
    );

    $this->add_group_control(
        Group_Control_Typography::get_type(),
        [
            'name'     => 'service_name_typography',
            'selector' => '{{WRAPPER}} .maradigma-boat-additionals__name',
        ]
    );

    $this->add_control(
        'service_name_color',
        [
            'label'     => esc_html__('Service name color', 'maradigma'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .maradigma-boat-additionals__name' => 'color: {{VALUE}};',
            ],
        ]
    );

    $this->add_group_control(
        Group_Control_Typography::get_type(),
        [
            'name'      => 'description_typography',
            'selector'  => '{{WRAPPER}} .maradigma-boat-additionals__desc',
            'condition' => [
                'show_description' => 'yes',
            ],
        ]
    );

    $this->add_control(
        'muted_color',
        [
            'label'     => esc_html__('Description, quantity and VAT color', 'maradigma'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .maradigma-boat-additionals' => '--maradigma-boat-additionals-muted-color: {{VALUE}};',
            ],
        ]
    );

    $this->add_group_control(
        Group_Control_Typography::get_type(),
        [
            'name'     => 'price_typography',
            'selector' => '{{WRAPPER}} .maradigma-boat-additionals__price',
        ]
    );

    $this->add_control(
        'price_color',
        [
            'label'     => esc_html__('Price color', 'maradigma'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .maradigma-boat-additionals' => '--maradigma-boat-additionals-price-color: {{VALUE}};',
            ],
        ]
    );

    $this->end_controls_section();

    // -----------------------
    // STYLE: BADGES
    // -----------------------
    $this->start_controls_section(
        'section_style_badges',
        [
            'label'     => esc_html__('Badges', 'maradigma'),
            'tab'       => Controls_Manager::TAB_STYLE,
            'condition' => [
                'show_badges' => 'yes',
            ],
        ]
    );

    $this->add_group_control(
        Group_Control_Typography::get_type(),
        [
            'name'     => 'badge_typography',
            'selector' => '{{WRAPPER}} .maradigma-boat-additionals__badge',
        ]
    );

    $this->add_control(
        'badge_text_color',
        [
            'label'     => esc_html__('Text color', 'maradigma'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .maradigma-boat-additionals' => '--maradigma-boat-additionals-badge-color: {{VALUE}};',
            ],
        ]
    );

    $this->add_control(
        'badge_background_color',
        [
            'label'     => esc_html__('Background color', 'maradigma'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .maradigma-boat-additionals' => '--maradigma-boat-additionals-badge-bg: {{VALUE}};',
            ],
        ]
    );

    $this->add_responsive_control(
        'badge_radius',
        [
            'label'      => esc_html__('Border radius', 'maradigma'),
            'type'       => Controls_Manager::SLIDER,
            'size_units' => ['px', '%', 'em', 'rem'],
            'range'      => [
                'px' => ['min' => 0, 'max' => 100],
            ],
            'selectors'  => [
                '{{WRAPPER}} .maradigma-boat-additionals' => '--maradigma-boat-additionals-badge-radius: {{SIZE}}{{UNIT}};',
            ],
        ]
    );

    $this->add_responsive_control(
        'badge_padding',
        [
            'label'      => esc_html__('Padding', 'maradigma'),
            'type'       => Controls_Manager::DIMENSIONS,
            'size_units' => ['px', 'em', 'rem'],
            'selectors'  => [
                '{{WRAPPER}} .maradigma-boat-additionals__badge' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
        ]
    );

    $this->end_controls_section();

    // -----------------------
    // STYLE: TABLE HEADER
    // -----------------------
    $this->start_controls_section(
        'section_style_table_header',
        [
            'label'     => esc_html__('Table header', 'maradigma'),
            'tab'       => Controls_Manager::TAB_STYLE,
            'condition' => [
                'layout'       => 'table',
                'show_headers' => 'yes',
            ],
        ]
    );

    $this->add_group_control(
        Group_Control_Typography::get_type(),
        [
            'name'     => 'table_header_typography',
            'selector' => '{{WRAPPER}} .maradigma-boat-additionals__table th',
        ]
    );

    $this->add_control(
        'table_header_text_color',
        [
            'label'     => esc_html__('Text color', 'maradigma'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .maradigma-boat-additionals__table th' => 'color: {{VALUE}};',
            ],
        ]
    );

    $this->add_control(
        'table_header_background_color',
        [
            'label'     => esc_html__('Background color', 'maradigma'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .maradigma-boat-additionals' => '--maradigma-boat-additionals-table-header-bg: {{VALUE}};',
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
        $ctx = $this->resolveContext(['service_additional_services']);
        if (!($ctx['ok'] ?? false) || !is_array($ctx['data'] ?? null)) {
            return;
        }

        $data = (array) $ctx['data'];

        echo wp_kses_post(BoatAdditionalServicesRenderer::render(
            $data,
            [
                'language'                 => $this->getCurrentLanguageForApi(),
                'title'                    => $this->get_settings_for_display('title'),
                'show_title'               => ((string) $this->get_settings_for_display('show_title')) === 'yes',
                'layout'                   => $this->get_settings_for_display('layout'),
                'show_headers'             => ((string) $this->get_settings_for_display('show_headers')) === 'yes',
                'group_by_category'        => ((string) $this->get_settings_for_display('group_by_category')) === 'yes',
                'show_category_title'      => ((string) $this->get_settings_for_display('show_category_title')) === 'yes',
                'show_badges'              => ((string) $this->get_settings_for_display('show_badges')) === 'yes',
                'show_badge_optional_type' => ((string) $this->get_settings_for_display('show_badge_optional_type')) === 'yes',
                'show_badge_price_type'    => ((string) $this->get_settings_for_display('show_badge_price_type')) === 'yes',
                'show_badge_payment'       => ((string) $this->get_settings_for_display('show_badge_payment')) === 'yes',
                'badge_style'              => $this->get_settings_for_display('badge_style'),
                'show_description'         => ((string) $this->get_settings_for_display('show_description')) === 'yes',
                'show_quantity'            => ((string) $this->get_settings_for_display('show_quantity')) === 'yes',
                'price_display'            => $this->get_settings_for_display('price_display'),
                'vat_mode'                 => $this->get_settings_for_display('vat_mode'),
                'vat_position'             => $this->get_settings_for_display('vat_position'),
                'vat_text_included'        => $this->get_settings_for_display('vat_text_included'),
                'vat_text_excluded'        => $this->get_settings_for_display('vat_text_excluded'),
                'currency_display'         => $this->get_settings_for_display('currency_display'),
                'decimals_mode'            => $this->get_settings_for_display('decimals_mode'),
                'thousands_sep'            => $this->get_settings_for_display('thousands_sep'),
                'decimal_sep'              => $this->get_settings_for_display('decimal_sep'),
            ]
        ));
        return;

        $services = $this->extractAdditionalServices($data);

        if ($services === []) {
            echo '<div class="maradigma-boat-additionals maradigma-boat-additionals--empty">';
            echo esc_html__('No additional services available.', 'maradigma');
            echo '</div>';
            return;
        }

        $showTitle         = ((string) $this->get_settings_for_display('show_title')) === 'yes';
        $title             = trim((string) $this->get_settings_for_display('title'));
        $groupByCategory   = ((string) $this->get_settings_for_display('group_by_category')) === 'yes';
        $showCategoryTitle = ((string) $this->get_settings_for_display('show_category_title')) === 'yes';

        // ✅ badges separados
        $showBadgesMaster  = ((string) $this->get_settings_for_display('show_badges')) === 'yes';
        $showBadgeOpt      = $showBadgesMaster && ((string) $this->get_settings_for_display('show_badge_optional_type')) === 'yes';
        $showBadgePrice    = $showBadgesMaster && ((string) $this->get_settings_for_display('show_badge_price_type')) === 'yes';
        $showBadgePayment  = $showBadgesMaster && ((string) $this->get_settings_for_display('show_badge_payment')) === 'yes';
        $badgeStyle        = (string) $this->get_settings_for_display('badge_style');

        $showDescription   = ((string) $this->get_settings_for_display('show_description')) === 'yes';
        $showQuantity      = ((string) $this->get_settings_for_display('show_quantity')) === 'yes';
        $priceDisplay      = (string) $this->get_settings_for_display('price_display');

        $layout      = (string) $this->get_settings_for_display('layout');
        $showHeaders = ((string) $this->get_settings_for_display('show_headers')) === 'yes';

        echo '<div class="maradigma-boat-additionals">';

        if ($showTitle && $title !== '') {
            echo '<h3 class="maradigma-boat-additionals__title">' . esc_html($title) . '</h3>';
        }

        if ($groupByCategory) {
            $grouped = $this->groupServicesByCategory($services);

            foreach ($grouped as $categoryLabel => $rows) {
                if ($showCategoryTitle && $categoryLabel !== '') {
                    echo '<h4 class="maradigma-boat-additionals__category">' . esc_html($categoryLabel) . '</h4>';
                }

                if ($layout === 'table') {
                    $this->renderAdditionalServicesTable(
                        $rows,
                        $showHeaders,
                        $showBadgeOpt,
                        $showBadgePrice,
                        $showBadgePayment,
                        $badgeStyle,
                        $showDescription,
                        $showQuantity,
                        $priceDisplay
                    );
                } else {
                    echo '<ul class="maradigma-boat-additionals__list">';
                    foreach ($rows as $row) {
                        $this->renderAdditionalServiceRow(
                            $row,
                            $showBadgeOpt,
                            $showBadgePrice,
                            $showBadgePayment,
                            $badgeStyle,
                            $showDescription,
                            $showQuantity,
                            $priceDisplay
                        );
                    }
                    echo '</ul>';
                }
            }
        } else {
            if ($layout === 'table') {
                $this->renderAdditionalServicesTable(
                    $services,
                    $showHeaders,
                    $showBadgeOpt,
                    $showBadgePrice,
                    $showBadgePayment,
                    $badgeStyle,
                    $showDescription,
                    $showQuantity,
                    $priceDisplay
                );
            } else {
                echo '<ul class="maradigma-boat-additionals__list">';
                foreach ($services as $row) {
                    $this->renderAdditionalServiceRow(
                        $row,
                        $showBadgeOpt,
                        $showBadgePrice,
                        $showBadgePayment,
                        $badgeStyle,
                        $showDescription,
                        $showQuantity,
                        $priceDisplay
                    );
                }
                echo '</ul>';
            }
        }

        echo '</div>';
    }


    /**
     * @param array<string,mixed> $data
     * @return array<int,array<string,mixed>>
     */
    private function extractAdditionalServices(array $data): array
    {
        $keys = ['additional_services', 'additionals'];

        $node = null;
        foreach ($keys as $k) {
            if (isset($data[$k]) && is_array($data[$k])) {
                $node = $data[$k];
                break;
            }
        }

        if (!is_array($node) || $node === []) {
            return [];
        }

        $out  = [];
        $lang = strtoupper($this->getCurrentLanguageForApi());

        foreach ($node as $item) {
            if (!is_array($item)) {
                continue;
            }

            $name = $this->pickTranslatedString(
                $item['name'] ?? null,
                $item['translations'] ?? null,
                $lang
            );
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }

            $catLabel = '';
            if (isset($item['cat_data']) && is_array($item['cat_data'])) {
                $catLabel = $this->pickTranslatedString(
                    $item['cat_data']['name'] ?? null,
                    $item['cat_data']['translations'] ?? null,
                    $lang
                );
                $catLabel = trim((string) $catLabel);
            }

            $prices = (isset($item['prices']) && is_array($item['prices'])) ? $item['prices'] : [];
            $names  = (isset($item['names']) && is_array($item['names'])) ? $item['names'] : [];

            $optionalTypeRaw = isset($item['optional_type']) ? (int) $item['optional_type'] : null;
            $paymentRaw      = isset($item['payment']) ? (int) $item['payment'] : null;
            $priceTypeRaw    = isset($item['price_type']) ? (int) $item['price_type'] : null;

            $row = [
                'id'          => isset($item['id']) ? (int) $item['id'] : null,
                'name'        => $name,
                'description' => isset($item['description']) && is_string($item['description']) ? trim($item['description']) : '',
                'quantity'    => isset($item['quantity']) ? (int) $item['quantity'] : 1,

                'optional_type_name' => isset($names['optional_type']) && is_string($names['optional_type']) ? trim($names['optional_type']) : '',
                'payment_name'       => isset($names['payment']) && is_string($names['payment']) ? trim($names['payment']) : '',
                'price_type_name'    => isset($names['price_type']) && is_string($names['price_type']) ? trim($names['price_type']) : '',

                'optional_type' => $optionalTypeRaw,
                'payment'       => $paymentRaw,
                'price_type'    => $priceTypeRaw,

                'category' => $catLabel,

                'prices'       => $prices,
                'raw_price'    => isset($item['price']) ? (string) $item['price'] : '',
                'raw_total'    => isset($item['total_price']) ? (string) $item['total_price'] : '',
                'raw_vat'      => isset($item['vat_price']) ? (string) $item['vat_price'] : '',
                'raw_subtotal' => isset($item['subtotal_price']) ? (string) $item['subtotal_price'] : '',
            ];

            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param array<int,array<string,mixed>> $services
     * @return array<string,array<int,array<string,mixed>>>
     */
    private function groupServicesByCategory(array $services): array
    {
        $grouped = [];

        foreach ($services as $row) {
            $cat = isset($row['category']) && is_string($row['category']) ? trim($row['category']) : '';
            if ($cat === '') {
                $cat = esc_html__('Other', 'maradigma');
            }
            if (!isset($grouped[$cat])) {
                $grouped[$cat] = [];
            }
            $grouped[$cat][] = $row;
        }

        ksort($grouped);

        return $grouped;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function renderAdditionalServiceRow(
        array $row,
        bool $showBadgeOptionalType,
        bool $showBadgePriceType,
        bool $showBadgePayment,
        string $badgeStyle,
        bool $showDescription,
        bool $showQuantity,
        string $priceDisplay
    ): void {
        $name        = (string) ($row['name'] ?? '');
        $description = (string) ($row['description'] ?? '');
        $quantity    = (int) ($row['quantity'] ?? 1);

        echo '<li class="maradigma-boat-additionals__item">';
        echo '<div class="maradigma-boat-additionals__row">';

        echo '<div class="maradigma-boat-additionals__left">';
        echo '<span class="maradigma-boat-additionals__name">' . esc_html($name) . '</span>';

        if ($showQuantity && $quantity > 1) {
            echo ' <span class="maradigma-boat-additionals__qty">x' . esc_html((string) $quantity) . '</span>';
        }

        $anyBadge = ($showBadgeOptionalType || $showBadgePriceType || $showBadgePayment);

        if ($anyBadge) {
            $badgeTriplet = $this->buildBadgeTriplet($row, $badgeStyle, $showBadgeOptionalType, $showBadgePriceType, $showBadgePayment);

            if ($badgeTriplet !== []) {
                echo '<div class="maradigma-boat-additionals__badges">';

                $i = 0;
                foreach ($badgeTriplet as $badge) {
                    if (!is_array($badge)) continue;

                    $label = isset($badge['label']) ? trim((string) $badge['label']) : '';
                    if ($label === '') continue;

                    $title = isset($badge['title']) ? trim((string) $badge['title']) : '';

                    if ($i > 0) {
                        echo '<span class="maradigma-boat-additionals__badge-sep" aria-hidden="true">·</span>';
                    }

                    echo '<span class="maradigma-boat-additionals__badge"'
                        . ($title !== '' ? ' title="' . esc_attr($title) . '"' : '')
                        . '>'
                        . esc_html($label)
                        . '</span>';

                    $i++;
                }

                echo '</div>';
            }
        }

        if ($showDescription && $description !== '') {
            echo '<div class="maradigma-boat-additionals__desc">' . esc_html($description) . '</div>';
        }

        echo '</div>'; // left

        echo '<div class="maradigma-boat-additionals__right">';
        echo '<div class="maradigma-boat-additionals__price">';
        $this->renderAdditionalPriceAndVat($row, $priceDisplay);
        echo '</div>';
        echo '</div>'; // right

        echo '</div>'; // row
        echo '</li>';
    }


    /**
     * Render additional services as a table.
     *
     * @param array<int,array<string,mixed>> $rows
     */
    private function renderAdditionalServicesTable(
        array $rows,
        bool $showHeaders,
        bool $showBadgeOptionalType,
        bool $showBadgePriceType,
        bool $showBadgePayment,
        string $badgeStyle,
        bool $showDescription,
        bool $showQuantity,
        string $priceDisplay
    ): void {
        $anyBadge = ($showBadgeOptionalType || $showBadgePriceType || $showBadgePayment);

        echo '<table class="maradigma-boat-additionals__table">';

        if ($showHeaders) {
            echo '<thead><tr>';
            echo '<th>' . esc_html__('Service', 'maradigma') . '</th>';

            if ($anyBadge) {
                echo '<th>' . esc_html__('Details', 'maradigma') . '</th>';
            }

            echo '<th style="text-align:right;">' . esc_html__('Price', 'maradigma') . '</th>';
            echo '</tr></thead>';
        }

        echo '<tbody>';

        foreach ($rows as $row) {
            if (!is_array($row)) continue;

            $name = (string) ($row['name'] ?? '');
            if ($name === '') continue;

            $description = (string) ($row['description'] ?? '');
            $quantity    = (int) ($row['quantity'] ?? 1);

            echo '<tr class="maradigma-boat-additionals__tr">';

            // Col 1: Name (+ qty + desc)
            echo '<td class="maradigma-boat-additionals__td maradigma-boat-additionals__td--name">';
            echo '<div class="maradigma-boat-additionals__name">' . esc_html($name) . '</div>';

            if ($showQuantity && $quantity > 1) {
                echo '<div class="maradigma-boat-additionals__qty">x' . esc_html((string) $quantity) . '</div>';
            }

            if ($showDescription && $description !== '') {
                echo '<div class="maradigma-boat-additionals__desc">' . esc_html($description) . '</div>';
            }

            echo '</td>';

            // Col 2: Badges (solo si alguno activo)
            if ($anyBadge) {
                $badgeTriplet = $this->buildBadgeTriplet(
                    $row,
                    $badgeStyle,
                    $showBadgeOptionalType,
                    $showBadgePriceType,
                    $showBadgePayment
                );

                echo '<td class="maradigma-boat-additionals__td maradigma-boat-additionals__td--badges">';

                if ($badgeTriplet !== []) {
                    echo '<div class="maradigma-boat-additionals__badges">';
                    $i = 0;
                    foreach ($badgeTriplet as $badge) {
                        if (!is_array($badge)) continue;

                        $label = isset($badge['label']) ? trim((string) $badge['label']) : '';
                        if ($label === '') continue;

                        $title = isset($badge['title']) ? trim((string) $badge['title']) : '';

                        if ($i > 0) {
                            echo '<span class="maradigma-boat-additionals__badge-sep" aria-hidden="true">·</span>';
                        }

                        echo '<span class="maradigma-boat-additionals__badge"'
                            . ($title !== '' ? ' title="' . esc_attr($title) . '"' : '')
                            . '>'
                            . esc_html($label)
                            . '</span>';

                        $i++;
                    }
                    echo '</div>';
                }

                echo '</td>';
            }

            // Col 3: Price (⚠️ div wrapper, no span)
            echo '<td class="maradigma-boat-additionals__td maradigma-boat-additionals__td--price" style="text-align:right;">';
            echo '<div class="maradigma-boat-additionals__price">';
            $this->renderAdditionalPriceAndVat($row, $priceDisplay);
            echo '</div>';
            echo '</td>';

            echo '</tr>';
        }

        echo '</tbody></table>';
    }



    /**
     * RENDER PRICE + VAT (igual UX que BoatPriceWidget)
     * - No calcula IVA.
     * - Usa base/total si vienen del backend.
     *
     * @param array<string,mixed> $row
     */
    private function renderAdditionalPriceAndVat(array $row, string $priceDisplay): void
    {
        $vatMode     = (string) $this->get_settings_for_display('vat_mode');
        $vatPosition = (string) $this->get_settings_for_display('vat_position');

        $textIncluded = trim((string) $this->get_settings_for_display('vat_text_included'));
        $textExcluded = trim((string) $this->get_settings_for_display('vat_text_excluded'));

        if ($textIncluded === '') $textIncluded = __('VAT included', 'maradigma');
        if ($textExcluded === '') $textExcluded = __('+ VAT', 'maradigma');

        // --- Extraer números (prioridad: raw -> pformat -> subtotal)
        $prices  = (isset($row['prices']) && is_array($row['prices'])) ? $row['prices'] : [];
        $pformat = (isset($prices['pformat']) && is_array($prices['pformat'])) ? $prices['pformat'] : [];

        $baseAmount = $this->toFloatOrNull($row['raw_price'] ?? null);
        if ($baseAmount === null) {
            $baseAmount = $this->toFloatOrNull($pformat['base'] ?? null);
        }
        if ($baseAmount === null) {
            $baseAmount = $this->toFloatOrNull($row['raw_subtotal'] ?? null);
        }

        $totalAmount = $this->toFloatOrNull($row['raw_total'] ?? null);
        if ($totalAmount === null) {
            $totalAmount = $this->toFloatOrNull($pformat['total'] ?? null);
        }

        // Si no hay número parseable, cae al antiguo string (compat)
        if ($baseAmount === null && $totalAmount === null) {
            $fallback = $this->formatAdditionalServicePrice($row, $priceDisplay);
            if ($fallback !== '') {
                echo esc_html($fallback);
            }
            return;
        }

        // Currency (si no viene, EUR)
        $currency = $this->guessCurrencyFromRow($row);

        // --- Elegir cantidad a mostrar según VAT mode
        $amountToShow = $baseAmount ?? 0.0;

        switch ($vatMode) {
            case 'included':
                $amountToShow = ($totalAmount !== null && $totalAmount > 0) ? $totalAmount : ($baseAmount ?? 0.0);
                break;

            case 'excluded':
                $amountToShow = ($baseAmount !== null && $baseAmount > 0) ? $baseAmount : ($totalAmount ?? 0.0);
                break;

            case 'hidden_total':
                $amountToShow = ($totalAmount !== null && $totalAmount > 0) ? $totalAmount : ($baseAmount ?? 0.0);
                break;

            case 'hidden_base':
            default:
                $amountToShow = ($baseAmount !== null && $baseAmount > 0) ? $baseAmount : ($totalAmount ?? 0.0);
                break;
        }

        $money = $this->formatMoneyAdvanced((float) $amountToShow, (string) $currency);

        // VAT text SOLO para included/excluded
        $vatText = '';
        if ($vatMode === 'included') {
            $vatText = $textIncluded;
        } elseif ($vatMode === 'excluded') {
            $vatText = $textExcluded;
        }

        echo '<span class="maradigma-boat-additionals__value">' . esc_html($money) . '</span>';

        if ($vatText === '') {
            return;
        }

        if ($vatPosition === 'inline') {
            echo ' <small class="maradigma-boat-additionals__vat">' . esc_html($vatText) . '</small>';
            return;
        }

        if ($vatPosition === 'tooltip') {
            echo ' <span class="maradigma-boat-additionals__vat maradigma-boat-additionals__vat--tooltip" title="' . esc_attr($vatText) . '">ⓘ</span>';
            return;
        }

        // below
        echo '<br><small class="maradigma-boat-additionals__vat">' . esc_html($vatText) . '</small>';
    }

    /**
     * Best-effort currency detection from row/prices.
     * @param array<string,mixed> $row
     */
    private function guessCurrencyFromRow(array $row): string
    {
        // If your API ever includes a currency field, pick it up.
        if (isset($row['currency']) && is_string($row['currency']) && trim($row['currency']) !== '') {
            return strtoupper(trim($row['currency']));
        }

        $prices = (isset($row['prices']) && is_array($row['prices'])) ? $row['prices'] : [];
        if (isset($prices['currency']) && is_string($prices['currency']) && trim($prices['currency']) !== '') {
            return strtoupper(trim($prices['currency']));
        }

        // Default
        return 'EUR';
    }

    /**
     * Advanced money formatting (no recalculation).
     */
    private function formatMoneyAdvanced(float $amount, string $currency): string
    {
        $currency = strtoupper(trim($currency));
        if ($currency === '') {
            $currency = 'EUR';
        }

        $display = (string) $this->get_settings_for_display('currency_display');
        $decMode = (string) $this->get_settings_for_display('decimals_mode');

        $thousands = (string) $this->get_settings_for_display('thousands_sep');
        $decimal   = (string) $this->get_settings_for_display('decimal_sep');

        $thousands = $thousands !== '' ? $thousands : '.';
        $decimal   = $decimal !== '' ? $decimal : ',';

        $decimals = 2;
        if ($decMode === '0') $decimals = 0;
        if ($decMode === '2') $decimals = 2;
        if ($decMode === 'auto') {
            $decimals = (abs($amount - round($amount)) < 0.00001) ? 0 : 2;
        }

        $num = number_format($amount, $decimals, $decimal, $thousands);

        if ($display === 'iso') {
            return $num . ' ' . $currency;
        }

        $symbol = $this->currencySymbol($currency);
        if ($symbol !== '') {
            if ($currency === 'EUR') {
                return $num . ' ' . $symbol;
            }
            return $symbol . ' ' . $num;
        }

        return $num . ' ' . $currency;
    }

    /**
     * Returns the display symbol for a currency code.
     */
    private function currencySymbol(string $currency): string
    {
        switch (strtoupper($currency)) {
            case 'EUR':
                return '€';
            case 'USD':
                return '$';
            case 'GBP':
                return '£';
            case 'CHF':
                return 'CHF';
            case 'DKK':
            case 'NOK':
            case 'SEK':
                return 'kr';
            default:
                return '';
        }
    }


    /**
     * @param mixed $v
     */
    private function toFloatOrNull($v): ?float
    {
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }

        if (!is_string($v)) {
            return null;
        }

        $s = trim($v);
        if ($s === '') {
            return null;
        }

        // Remove common symbols/spaces (incl. NBSP)
        $s = str_replace(["\xc2\xa0", ' ', '€', '$', '£'], '', $s);

        // ✅ Remove ISO currency like EUR/USD/GBP if appended
        $s = preg_replace('/[A-Za-z]{3}/', '', $s);
        $s = trim($s);

        // Handle "4.750,00" vs "4,750.00"
        if (strpos($s, ',') !== false && strpos($s, '.') !== false) {
            // assume thousand sep '.' and decimal ','
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        } elseif (strpos($s, ',') !== false && strpos($s, '.') === false) {
            $s = str_replace(',', '.', $s);
        }

        if (!is_numeric($s)) {
            return null;
        }

        return (float) $s;
    }



    /**
     * Builds a customer-friendly triplet of badges (option, per, payment).
     *
     * @param array<string,mixed> $row
     * @return array<int,array{label:string,title:string}>
     */
    private function buildBadgeTriplet(
        array $row,
        string $badgeStyle,
        bool $showBadgeOptionalType,
        bool $showBadgePriceType,
        bool $showBadgePayment
    ): array {
        $opt = isset($row['optional_type_name']) ? trim((string) $row['optional_type_name']) : '';
        $pt  = isset($row['price_type_name']) ? trim((string) $row['price_type_name']) : '';
        $pay = isset($row['payment_name']) ? trim((string) $row['payment_name']) : '';

        if ($badgeStyle === 'raw') {
            $out = [];

            if ($showBadgeOptionalType && $opt !== '') $out[] = ['label' => $opt, 'title' => ''];
            if ($showBadgePriceType && $pt !== '')     $out[] = ['label' => $pt,  'title' => ''];
            if ($showBadgePayment && $pay !== '')      $out[] = ['label' => $pay, 'title' => ''];

            return $out;
        }

        $out = [];

        if ($showBadgeOptionalType) {
            $friendlyOpt = $this->normalizeOptionalTypeBadge($row, $opt);
            if ($friendlyOpt !== null) $out[] = $friendlyOpt;
        }

        if ($showBadgePriceType) {
            $friendlyPt  = $this->normalizePriceTypeBadge($row, $pt);
            if ($friendlyPt !== null) $out[] = $friendlyPt;
        }

        if ($showBadgePayment) {
            $friendlyPay = $this->normalizePaymentBadge($row, $pay);
            if ($friendlyPay !== null) $out[] = $friendlyPay;
        }

        return $out;
    }


    /**
     * @param array<string,mixed> $row
     * @return array{label:string,title:string}|null
     */
    private function normalizeOptionalTypeBadge(array $row, string $rawLabel): ?array
    {
        $code = isset($row['optional_type']) ? (int) $row['optional_type'] : null;

        if ($code === 1 || stripos($rawLabel, 'mandatory') !== false || stripos($rawLabel, 'oblig') !== false) {
            return [
                'label' => esc_html__('Required', 'maradigma'),
                'title' => esc_html__('This extra is required for this booking.', 'maradigma'),
            ];
        }

        if ($code === 2 || stripos($rawLabel, 'optional') !== false || stripos($rawLabel, 'opcional') !== false) {
            return [
                'label' => esc_html__('Optional', 'maradigma'),
                'title' => esc_html__('This extra can be added if you want.', 'maradigma'),
            ];
        }

        if ($code === 3 || stripos($rawLabel, 'free') !== false || stripos($rawLabel, 'gratis') !== false) {
            return [
                'label' => esc_html__('Free', 'maradigma'),
                'title' => esc_html__('This extra has no additional cost.', 'maradigma'),
            ];
        }

        return null;
    }

    /**
     * @param array<string,mixed> $row
     * @return array{label:string,title:string}|null
     */
    private function normalizePriceTypeBadge(array $row, string $rawLabel): ?array
    {
        $code = isset($row['price_type']) ? (int) $row['price_type'] : null;

        if ($code === 1 || stripos($rawLabel, 'booking') !== false || stripos($rawLabel, 'reserva') !== false) {
            return [
                'label' => esc_html__('Per booking', 'maradigma'),
                'title' => esc_html__('Charged once for the whole reservation.', 'maradigma'),
            ];
        }

        if ($code === 2 || stripos($rawLabel, 'day') !== false || stripos($rawLabel, 'día') !== false || stripos($rawLabel, 'dia') !== false) {
            return [
                'label' => esc_html__('Per day', 'maradigma'),
                'title' => esc_html__('Charged for each day of the reservation.', 'maradigma'),
            ];
        }

        return null;
    }

    /**
     * @param array<string,mixed> $row
     * @return array{label:string,title:string}|null
     */
    private function normalizePaymentBadge(array $row, string $rawLabel): ?array
    {
        $code = isset($row['payment']) ? (int) $row['payment'] : null;

        if ($code === 1 || stripos($rawLabel, 'online') !== false) {
            return [
                'label' => esc_html__('Pay online', 'maradigma'),
                'title' => esc_html__('Payment is made online during booking.', 'maradigma'),
            ];
        }

        if ($code === 2 || stripos($rawLabel, 'port') !== false || stripos($rawLabel, 'puerto') !== false) {
            return [
                'label' => esc_html__('Pay at the port', 'maradigma'),
                'title' => esc_html__('Payment is made at the port on the rental day.', 'maradigma'),
            ];
        }

        if ($code === 3 || stripos($rawLabel, 'office') !== false || stripos($rawLabel, 'oficina') !== false) {
            return [
                'label' => esc_html__('Pay at the office', 'maradigma'),
                'title' => esc_html__('Payment is made at the office.', 'maradigma'),
            ];
        }

        return null;
    }

    /**
     * (Se mantiene) Fallback / compat string-based formatter.
     * @param array<string,mixed> $row
     */
    private function formatAdditionalServicePrice(array $row, string $priceDisplay): string
    {
        $prices  = (isset($row['prices']) && is_array($row['prices'])) ? $row['prices'] : [];
        $pformat = (isset($prices['pformat']) && is_array($prices['pformat'])) ? $prices['pformat'] : [];
        $html    = (isset($prices['html']) && is_array($prices['html'])) ? $prices['html'] : [];

        switch ($priceDisplay) {
            case 'total_html':
                if (isset($html['total']) && is_string($html['total'])) {
                    return trim($html['total']);
                }
                if (isset($pformat['total']) && is_string($pformat['total'])) {
                    return trim($pformat['total']);
                }
                break;

            case 'total':
                if (isset($pformat['total']) && is_string($pformat['total'])) {
                    return trim($pformat['total']);
                }
                if (isset($html['total']) && is_string($html['total'])) {
                    return trim($html['total']);
                }
                break;

            case 'base':
                if (isset($pformat['base']) && is_string($pformat['base'])) {
                    return trim($pformat['base']);
                }
                if (isset($html['base']) && is_string($html['base'])) {
                    return trim($html['base']);
                }
                break;

            case 'vat':
                if (isset($pformat['vat']) && is_string($pformat['vat'])) {
                    return trim($pformat['vat']);
                }
                break;

            case 'raw_total':
                return trim((string) ($row['raw_total'] ?? ''));

            case 'raw_base':
                return trim((string) ($row['raw_price'] ?? ''));

            default:
                break;
        }

        $rawTotal = trim((string) ($row['raw_total'] ?? ''));
        if ($rawTotal !== '') {
            return $rawTotal;
        }

        $rawBase = trim((string) ($row['raw_price'] ?? ''));
        return $rawBase;
    }

    /**
     * Picks a translated string from:
     * - $base: string|null
     * - $translations: array<string,string>|mixed
     */
    private function pickTranslatedString($base, $translations, string $lang): string
    {
        if (is_array($translations)) {
            if (isset($translations[$lang]) && is_string($translations[$lang]) && trim($translations[$lang]) !== '') {
                return trim($translations[$lang]);
            }
            foreach ($translations as $v) {
                if (is_string($v) && trim($v) !== '') {
                    return trim($v);
                }
            }
        }

        return is_string($base) ? trim($base) : '';
    }
}

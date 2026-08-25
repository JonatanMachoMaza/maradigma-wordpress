<?php

declare(strict_types=1);

namespace Maradigma\Integrations\Elementor\Widgets\SingleBoat;

use Elementor\Controls_Manager;
use Maradigma\Support\BoatPricesRenderer;

/**
 * Elementor widget: Maradigma Boat Season Prices
 *
 * Shows boat prices defined by date ranges (seasons).
 * IMPORTANT:
 * - Does NOT calculate taxes.
 * - Does NOT recalculate prices.
 * - VAT text is purely informational/visual.
 */
final class BoatPriceWidget extends BaseSingleBoatWidget
{
    /**
     * Returns the widget's stable Elementor identifier.
     */
    public function get_name(): string
    {
        return 'maradigma_boat_price';
    }

    /**
     * Returns the widget title shown in Elementor.
     */
    public function get_title(): string
    {
        return esc_html__('Maradigma Boat Prices', 'maradigma');
    }

    /**
     * Returns the Elementor icon identifier for the widget.
     */
    public function get_icon(): string
    {
        return 'eicon-price-table';
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
                'label'       => esc_html__('Title', 'maradigma'),
                'type'        => Controls_Manager::TEXT,
                'default'     => 'Prices',
                'placeholder' => 'Prices',
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

        // Range representation
        $this->add_control(
            'range_mode',
            [
                'label'   => esc_html__('Season range display', 'maradigma'),
                'type'    => Controls_Manager::SELECT,
                'default' => 'dates_short',
                'options' => [
                    'month'       => esc_html__('Month / Months', 'maradigma'),
                    'dates_short' => esc_html__('Dates (short)', 'maradigma'), // 01 Jul - 31 Aug
                    'dates_long'  => esc_html__('Dates (long)', 'maradigma'),  // 01 July 2026 - 31 August 2026
                ],
            ]
        );

        // Layout
        $this->add_control(
            'layout',
            [
                'label'   => esc_html__('Layout', 'maradigma'),
                'type'    => Controls_Manager::SELECT,
                'default' => 'blocks',
                'options' => [
                    'table_vertical' => esc_html__('Table (vertical)', 'maradigma'),
                    'blocks'         => esc_html__('Cards', 'maradigma'),
                ],
            ]
        );

        // Ordering
        $this->add_control(
            'order_by',
            [
                'label'   => esc_html__('Order by', 'maradigma'),
                'type'    => Controls_Manager::SELECT,
                'default' => 'date_from_asc',
                'options' => [
                    'date_from_asc'  => esc_html__('Start date (ASC)', 'maradigma'),
                    'date_from_desc' => esc_html__('Start date (DESC)', 'maradigma'),
                    'price_asc'      => esc_html__('Price (ASC)', 'maradigma'),
                    'price_desc'     => esc_html__('Price (DESC)', 'maradigma'),
                ],
            ]
        );

        // Empty state
        $this->add_control(
            'empty_text',
            [
                'label'       => esc_html__('Empty text', 'maradigma'),
                'type'        => Controls_Manager::TEXT,
                'default'     => 'Prices not available.',
                'placeholder' => 'Prices not available.',
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
        // BASIC STYLES
        // -----------------------
        $this->start_controls_section(
            'section_style',
            [
                'label' => esc_html__('Style', 'maradigma'),
                'tab'   => Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_control(
            'row_gap',
            [
                'label' => esc_html__('Row gap (px)', 'maradigma'),
                'type'  => Controls_Manager::NUMBER,
                'min'   => 0,
                'max'   => 80,
                'step'  => 1,
                'default' => 8,
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
                    'layout' => 'table_vertical',
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
        $ctx = $this->resolveContext(['service_prices']);
        if (!($ctx['ok'] ?? false) || !is_array($ctx['data'] ?? null)) {
            return;
        }

        $data = (array) $ctx['data'];

        $title     = trim((string) $this->get_settings_for_display('title'));
        $title     = \Maradigma\Support\MultilangAdapter::translateEditableDefault(
            $title,
            'Prices',
            (string) __('Prices', 'maradigma'),
            'Maradigma Elementor Widgets',
            'boat_price_title_' . $this->get_id()
        );

        $empty = trim((string) $this->get_settings_for_display('empty_text'));
        $empty = \Maradigma\Support\MultilangAdapter::translateEditableDefault(
            $empty,
            'Prices not available.',
            (string) __('Prices not available.', 'maradigma'),
            'Maradigma Elementor Widgets',
            'boat_price_empty_text_' . $this->get_id()
        );

        $vatIncluded = trim((string) $this->get_settings_for_display('vat_text_included'));
        $vatIncluded = \Maradigma\Support\MultilangAdapter::translateEditableDefault(
            $vatIncluded,
            'VAT included',
            (string) __('VAT included', 'maradigma'),
            'Maradigma Elementor Widgets',
            'boat_price_vat_text_included_' . $this->get_id()
        );

        $vatExcluded = trim((string) $this->get_settings_for_display('vat_text_excluded'));
        $vatExcluded = \Maradigma\Support\MultilangAdapter::translateEditableDefault(
            $vatExcluded,
            '+ VAT',
            (string) __('+ VAT', 'maradigma'),
            'Maradigma Elementor Widgets',
            'boat_price_vat_text_excluded_' . $this->get_id()
        );

        echo wp_kses_post(BoatPricesRenderer::render($data, [
            'title'             => $title,
            'show_title'        => ((string) $this->get_settings_for_display('show_title')) === 'yes',
            'fallback'          => $empty,
            'layout'            => (string) $this->get_settings_for_display('layout'),
            'range_mode'        => (string) $this->get_settings_for_display('range_mode'),
            'order_by'          => (string) $this->get_settings_for_display('order_by'),
            'show_headers'      => ((string) $this->get_settings_for_display('show_headers')) === 'yes',
            'row_gap'           => (int) ($this->get_settings_for_display('row_gap') ?? 10),
            'vat_mode'          => (string) $this->get_settings_for_display('vat_mode'),
            'vat_use_backend'   => ((string) $this->get_settings_for_display('vat_use_backend')) === 'yes',
            'vat_position'      => (string) $this->get_settings_for_display('vat_position'),
            'vat_text_included' => $vatIncluded,
            'vat_text_excluded' => $vatExcluded,
            'currency_display'  => (string) $this->get_settings_for_display('currency_display'),
            'decimals_mode'     => (string) $this->get_settings_for_display('decimals_mode'),
            'thousands_sep'     => (string) $this->get_settings_for_display('thousands_sep'),
            'decimal_sep'       => (string) $this->get_settings_for_display('decimal_sep'),
        ]));
    }

    /**
     * @param array<int,array<string,mixed>> $seasons
     */
    private function renderTableVertical(array $seasons, string $rangeMode, string $currencyFallback, bool $showHeaders): void
    {
        echo '<table class="maradigma-boat-prices__table">';
        if ($showHeaders) {
            echo '<thead><tr>';
            echo '<th>' . esc_html__('Season', 'maradigma') . '</th>';
            echo '<th>' . esc_html__('Price', 'maradigma') . '</th>';
            echo '</tr></thead>';
        }
        echo '<tbody>';

        foreach ($seasons as $row) {
            $range = $this->formatRange(
                $row['date_from'] ?? null,
                $row['date_to'] ?? null,
                $rangeMode,
                (string) ($row['date_from_raw'] ?? ''),
                (string) ($row['date_to_raw'] ?? '')
            );

            $baseAmount  = (float) ($row['price'] ?? 0);
            $totalAmount = isset($row['total_price']) ? (float) $row['total_price'] : null;
            $currency = (string) ($row['currency'] ?? $currencyFallback);

            echo '<tr class="maradigma-boat-prices__row">';
            echo '<td class="maradigma-boat-prices__season">' . esc_html($range) . '</td>';
            echo '<td class="maradigma-boat-prices__price">';
            $this->renderPriceAndVat($baseAmount, $currency, $row['vat_included'] ?? null, $totalAmount);
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * @param array<int,array<string,mixed>> $seasons
     */
    private function renderBlocks(array $seasons, string $rangeMode, string $currencyFallback): void
    {
        echo '<div class="maradigma-boat-prices__blocks">';
        foreach ($seasons as $row) {
            $range = $this->formatRange(
                $row['date_from'] ?? null,
                $row['date_to'] ?? null,
                $rangeMode,
                (string) ($row['date_from_raw'] ?? ''),
                (string) ($row['date_to_raw'] ?? '')
            );

            $baseAmount  = (float) ($row['price'] ?? 0);
            $totalAmount = isset($row['total_price']) ? (float) $row['total_price'] : null;
            $currency = (string) ($row['currency'] ?? $currencyFallback);

            echo '<div class="maradigma-boat-prices__block">';
            echo '<div class="maradigma-boat-prices__season">' . esc_html($range) . '</div>';
            echo '<div class="maradigma-boat-prices__price">';
            $this->renderPriceAndVat($baseAmount, $currency, $row['vat_included'] ?? null, $totalAmount);
            echo '</div>';
            echo '</div>';
        }
        echo '</div>';
    }

    /**
     * Render amount + VAT label (no calculations; uses API fields if provided).
     *
     * @param bool|null $backendVatIncluded Whether backend indicates VAT is included.
     */
    private function renderPriceAndVat(float $baseAmount, string $currency, $backendVatIncluded, ?float $totalAmount = null): void
    {
        $vatMode       = (string) $this->get_settings_for_display('vat_mode');
        $vatUseBackend = ((string) $this->get_settings_for_display('vat_use_backend')) === 'yes';
        $vatPosition   = (string) $this->get_settings_for_display('vat_position');

        // If backend defines vat_included and widget is configured to respect it, use backend mode.
        if ($vatUseBackend && is_bool($backendVatIncluded)) {
            $vatMode = $backendVatIncluded ? 'included' : 'excluded';
        }

        // Editable texts from Elementor
        $textIncluded = trim((string) $this->get_settings_for_display('vat_text_included'));
        $textExcluded = trim((string) $this->get_settings_for_display('vat_text_excluded'));

        $textIncluded = \Maradigma\Support\MultilangAdapter::translateEditableDefault(
            $textIncluded,
            'VAT included',
            (string) __('VAT included', 'maradigma'),
            'Maradigma Elementor Widgets',
            'boat_price_vat_text_included_' . $this->get_id()
        );

        $textExcluded = \Maradigma\Support\MultilangAdapter::translateEditableDefault(
            $textExcluded,
            '+ VAT',
            (string) __('+ VAT', 'maradigma'),
            'Maradigma Elementor Widgets',
            'boat_price_vat_text_excluded_' . $this->get_id()
        );

        if ($textIncluded === '') {
            $textIncluded = __('VAT included', 'maradigma');
        }

        if ($textExcluded === '') {
            $textExcluded = __('+ VAT', 'maradigma');
        }

        // Choose displayed amount according to VAT mode
        $amountToShow = $baseAmount;

        switch ($vatMode) {
            case 'included':
                $amountToShow = (is_float($totalAmount) && $totalAmount > 0) ? $totalAmount : $baseAmount;
                break;

            case 'excluded':
                $amountToShow = $baseAmount;
                break;

            case 'hidden_total':
                $amountToShow = (is_float($totalAmount) && $totalAmount > 0) ? $totalAmount : $baseAmount;
                break;

            case 'hidden_base':
            default:
                $amountToShow = $baseAmount;
                break;
        }

        $money = $this->formatMoneyAdvanced($amountToShow, $currency);

        $vatText = '';
        if ($vatMode === 'included') {
            $vatText = $textIncluded;
        } elseif ($vatMode === 'excluded') {
            $vatText = $textExcluded;
        }

        echo '<span class="maradigma-boat-prices__value">' . esc_html($money) . '</span>';

        if ($vatText === '') {
            return;
        }

        if ($vatPosition === 'inline') {
            echo ' <small class="maradigma-boat-prices__vat">' . esc_html($vatText) . '</small>';
            return;
        }

        if ($vatPosition === 'tooltip') {
            echo ' <span class="maradigma-boat-prices__vat maradigma-boat-prices__vat--tooltip" title="' . esc_attr($vatText) . '">ⓘ</span>';
            return;
        }

        echo '<br><small class="maradigma-boat-prices__vat">' . esc_html($vatText) . '</small>';
    }

    /**
     * Extract season prices from any nested payload shape.
     *
     * Output rows:
     *  - date_from/date_to as DateTimeImmutable|null
     *  - date_from_raw/date_to_raw as string (fallback display)
     *  - price float
     *  - currency string
     *  - vat_included bool|null (optional)
     *
     * @param mixed $pricesPayload
     * @return array<int,array<string,mixed>>
     */
    private function extractSeasonPrices($pricesPayload, string $currencyFallback): array
    {
        if (!is_array($pricesPayload)) {
            return [];
        }

        // ✅ Tu payload real: prices['range'] contiene los rangos
        $range = $pricesPayload['range'] ?? null;
        if (!is_array($range) || $range === []) {
            return [];
        }

        $rows = [];

        foreach ($range as $id => $row) {
            if (!is_array($row)) {
                continue;
            }

            $dfRaw = (string) ($row['date_start'] ?? '');
            $dtRaw = (string) ($row['date_end'] ?? '');

            $df = $this->parseDateOrNull($dfRaw);
            $dt = $this->parseDateOrNull($dtRaw);

            // Si no hay fechas válidas, no es “season range”
            if (!($df instanceof \DateTimeImmutable) || !($dt instanceof \DateTimeImmutable)) {
                continue;
            }

            // Precio base del rango
            $price = $this->toFloatOrNull($row['price'] ?? null);
            if ($price === null || $price <= 0) {
                continue;
            }

            // Currency: en tu payload no viene, así que usamos fallback
            $currency = strtoupper(trim((string) ($row['currency'] ?? $currencyFallback)));
            if ($currency === '') {
                $currency = $currencyFallback;
            }

            /**
             * VAT:
             * Tu backend da vat_percent, vat_price, total_price.
             * El widget NO calcula impuestos, solo muestra texto.
             *
             * Si quieres seguir usando vat_mode "included/excluded",
             * aquí solo podemos inferir un bool si existe total_price:
             * - si total_price > price => asumimos IVA incluido en total, pero el "price" que enseñas es sin IVA.
             * Yo por defecto lo dejo null para que mande el control del widget.
             */
            $vatIncluded = null;

            $rows[] = [
                'date_from'     => $df,
                'date_to'       => $dt,
                'date_from_raw' => $dfRaw,
                'date_to_raw'   => $dtRaw,
                'price'         => (float) $price,
                'currency'      => $currency,
                'vat_included'  => $vatIncluded,

                // Extra (por si luego quieres mostrar desglose sin recalcular)
                'vat_percent'   => $this->toFloatOrNull($row['vat_percent'] ?? null),
                'vat_price'     => $this->toFloatOrNull($row['vat_price'] ?? null),
                'total_price'   => $this->toFloatOrNull($row['total_price'] ?? null),
            ];
        }

        // Orden estable por fecha inicio (por si el array viene por id)
        usort($rows, function (array $a, array $b): int {
            $aTs = ($a['date_from'] instanceof \DateTimeImmutable) ? $a['date_from']->getTimestamp() : 0;
            $bTs = ($b['date_from'] instanceof \DateTimeImmutable) ? $b['date_from']->getTimestamp() : 0;
            return $aTs <=> $bTs;
        });

        return $rows;
    }

    /**
     * @param array<int,array<string,mixed>> $seasons
     */
    private function sortSeasons(array &$seasons, string $orderBy): void
    {
        usort($seasons, function (array $a, array $b) use ($orderBy): int {
            $aDate = $a['date_from'] ?? null;
            $bDate = $b['date_from'] ?? null;

            $aTs = ($aDate instanceof \DateTimeImmutable) ? $aDate->getTimestamp() : PHP_INT_MIN;
            $bTs = ($bDate instanceof \DateTimeImmutable) ? $bDate->getTimestamp() : PHP_INT_MIN;

            $aPrice = (float) ($a['price'] ?? 0);
            $bPrice = (float) ($b['price'] ?? 0);

            switch ($orderBy) {
                case 'date_from_desc':
                    return $bTs <=> $aTs;
                case 'price_asc':
                    return $aPrice <=> $bPrice;
                case 'price_desc':
                    return $bPrice <=> $aPrice;
                case 'date_from_asc':
                default:
                    return $aTs <=> $bTs;
            }
        });
    }

    /**
     * Formats range.
     */
    private function formatRange(
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $to,
        string $mode,
        string $fromRaw,
        string $toRaw
    ): string {
        if (!($from instanceof \DateTimeImmutable) || !($to instanceof \DateTimeImmutable)) {
            // If dates couldn't be parsed, show raw strings
            $fromRaw = trim($fromRaw);
            $toRaw = trim($toRaw);
            if ($fromRaw !== '' && $toRaw !== '') {
                return $fromRaw . ' - ' . $toRaw;
            }
            return $fromRaw !== '' ? $fromRaw : ($toRaw !== '' ? $toRaw : __('Season', 'maradigma'));
        }

        if ($mode === 'month') {
            // If it's exactly one full month, show Month name.
            $isSameMonth = $from->format('Y-m') === $to->format('Y-m');
            $isFullMonth = ((int) $from->format('d') === 1) && ((int) $to->format('d') === (int) $to->format('t'));

            if ($isSameMonth && $isFullMonth) {
                return $this->i18nMonthName($from);
            }

            // If spans multiple months, show "May - Aug"
            $start = $this->i18nMonthName($from);
            $end   = $this->i18nMonthName($to);
            if ($start !== '' && $end !== '' && $start !== $end) {
                return $start . ' - ' . $end;
            }
            if ($start !== '') {
                return $start;
            }
        }

        if ($mode === 'dates_long') {
            $a = $this->i18nDate($from, 'd F Y');
            $b = $this->i18nDate($to, 'd F Y');
            return $a . ' - ' . $b;
        }

        // dates_short
        $a = $this->i18nDate($from, 'd M');
        $b = $this->i18nDate($to, 'd M');
        return $a . ' - ' . $b;
    }

    /**
     * Returns the localized month name for a date.
     */
    private function i18nMonthName(\DateTimeImmutable $d): string
    {
        $ts = $d->getTimestamp();
        $s  = function_exists('date_i18n') ? (string) date_i18n('F', $ts) : $d->format('F');
        $s  = trim($s);

        if ($s === '') {
            return '';
        }

        // Capitaliza primera letra (UTF-8)
        $first = mb_substr($s, 0, 1, 'UTF-8');
        $rest  = mb_substr($s, 1, null, 'UTF-8');

        return mb_strtoupper($first, 'UTF-8') . $rest;
    }

    /**
     * Formats a date using WordPress localization.
     */
    private function i18nDate(\DateTimeImmutable $d, string $format): string
    {
        $ts = $d->getTimestamp();
        $s = function_exists('date_i18n') ? (string) date_i18n($format, $ts) : $d->format($format);
        return trim($s);
    }

    /**
     * Parses a date value or returns null when it is invalid.
     */
    private function parseDateOrNull(string $v): ?\DateTimeImmutable
    {
        $v = trim($v);
        if ($v === '') {
            return null;
        }

        // Timestamp?
        if (ctype_digit($v)) {
            $ts = (int) $v;
            if ($ts > 0) {
                return (new \DateTimeImmutable('@' . $ts))->setTimezone(wp_timezone());
            }
        }

        // Common formats
        $fmts = ['Y-m-d', 'Y-m-d H:i:s', 'd/m/Y', 'd-m-Y'];
        foreach ($fmts as $f) {
            $dt = \DateTimeImmutable::createFromFormat($f, $v, wp_timezone());
            if ($dt instanceof \DateTimeImmutable) {
                return $dt;
            }
        }

        // Last resort
        try {
            return new \DateTimeImmutable($v, wp_timezone());
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Selects first numeric.
     */
    private function pickFirstNumeric(array $node, array $keys): ?float
    {
        foreach ($keys as $k) {
            if (array_key_exists($k, $node)) {
                $n = $this->toFloatOrNull($node[$k] ?? null);
                if ($n !== null) {
                    return $n;
                }
            }
        }
        return null;
    }

    /**
     * Selects first string.
     */
    private function pickFirstString(array $node, array $keys): string
    {
        foreach ($keys as $k) {
            if (array_key_exists($k, $node) && is_string($node[$k])) {
                $s = trim((string) $node[$k]);
                if ($s !== '') {
                    return $s;
                }
            }
        }
        return '';
    }

    /**
     * Selects first bool or null.
     */
    private function pickFirstBoolOrNull(array $node, array $keys): ?bool
    {
        foreach ($keys as $k) {
            if (!array_key_exists($k, $node)) {
                continue;
            }
            $v = $node[$k];

            if (is_bool($v)) {
                return $v;
            }
            if (is_int($v)) {
                return $v === 1;
            }
            if (is_string($v)) {
                $s = strtolower(trim($v));
                if (in_array($s, ['1', 'true', 'yes', 'y', 'on'], true)) return true;
                if (in_array($s, ['0', 'false', 'no', 'n', 'off'], true)) return false;
            }
        }
        return null;
    }

    /**
     * @param array<string,mixed> $data
     * @param mixed $pricesPayload
     */
    private function guessCurrency(array $data, $pricesPayload): string
    {
        if (!empty($data['currency']) && is_string($data['currency'])) {
            return strtoupper(trim($data['currency']));
        }

        if (is_array($pricesPayload)) {
            $v = $this->findFirstScalarByKeyRecursive($pricesPayload, 'currency');
            if (is_string($v) && $v !== '') {
                return strtoupper(trim($v));
            }
            $v = $this->findFirstScalarByKeyRecursive($pricesPayload, 'currency_code');
            if (is_string($v) && $v !== '') {
                return strtoupper(trim($v));
            }
        }

        return 'EUR';
    }

    /**
     * @param array<mixed> $arr
     * @return mixed
     */
    private function findFirstScalarByKeyRecursive(array $arr, string $key)
    {
        foreach ($arr as $k => $v) {
            if ($k === $key && (is_string($v) || is_int($v) || is_float($v))) {
                return $v;
            }
            if (is_array($v)) {
                $found = $this->findFirstScalarByKeyRecursive($v, $key);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
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

        // Basic sane defaults
        $thousands = $thousands !== '' ? $thousands : '.';
        $decimal   = $decimal !== '' ? $decimal : ',';

        $decimals = 2;
        if ($decMode === '0') $decimals = 0;
        if ($decMode === '2') $decimals = 2;
        if ($decMode === 'auto') {
            // auto: if integer-ish => 0 else 2
            $decimals = (abs($amount - round($amount)) < 0.00001) ? 0 : 2;
        }

        $num = number_format($amount, $decimals, $decimal, $thousands);

        if ($display === 'iso') {
            return $num . ' ' . $currency;
        }

        $symbol = $this->currencySymbol($currency);
        if ($symbol !== '') {
            // Common EU formatting: "4.750 €"
            if ($currency === 'EUR') {
                return $num . ' ' . $symbol;
            }
            // "$ 4,750.00" is common, but we keep it simple/consistent:
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
                return 'kr';
            case 'NOK':
                return 'kr';
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

        $s = str_replace([' ', '€'], '', $s);

        if (strpos($s, ',') !== false && strpos($s, '.') !== false) {
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
}

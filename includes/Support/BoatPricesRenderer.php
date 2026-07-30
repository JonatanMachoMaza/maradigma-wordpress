<?php

declare(strict_types=1);

namespace Maradigma\Support;

final class BoatPricesRenderer
{
    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $options
     */
    public static function render(array $data, array $options = []): string
    {
        $options = self::normalizeOptions($options);

        $pricesPayload = $data['prices'] ?? null;
        $currency      = self::guessCurrency($data, $pricesPayload);
        $rows          = self::extractSeasonPrices($pricesPayload, $currency);

        if ($rows === []) {
            $fallbackPrices = [
                ['key' => 'base_price', 'label' => \__('Base price', 'maradigma'), 'total_key' => 'total_price'],
                ['key' => 'base_week_price', 'label' => \__('Week', 'maradigma'), 'total_key' => ''],
                ['key' => 'base_hour_price', 'label' => \__('Hour', 'maradigma'), 'total_key' => ''],
            ];

            foreach ($fallbackPrices as $fallbackPrice) {
                $amount = self::toFloatOrNull($data[$fallbackPrice['key']] ?? null);

                if ($amount === null || $amount <= 0) {
                    continue;
                }

                $rows[] = [
                    'label'       => $fallbackPrice['label'],
                    'price'       => $amount,
                    'currency'    => $currency,
                    'total_price' => $fallbackPrice['total_key'] !== ''
                        ? self::toFloatOrNull($data[$fallbackPrice['total_key']] ?? null)
                        : null,
                ];
            }
        }

        if ($rows === []) {
            $fallback = \trim((string) $options['fallback']);

            if ($fallback === '') {
                $fallback = \__('Prices not available.', 'maradigma');
            }

            return '<div class="maradigma-boat-prices maradigma-boat-prices--empty">' . \esc_html($fallback) . '</div>';
        }

        self::sortPriceRows($rows, (string) $options['order_by']);

        \ob_start();

        $style = '';
        if ((int) $options['row_gap'] >= 0) {
            $style = '--maradigma-prices-gap:' . (int) $options['row_gap'] . 'px;';
        }

        echo '<div class="maradigma-boat-prices maradigma-boat-prices--' . \esc_attr((string) $options['layout']) . '"' .
            ($style !== '' ? ' style="' . \esc_attr($style) . '"' : '') .
            '>';

        if (!empty($options['show_title']) && \trim((string) $options['title']) !== '') {
            echo '<h3 class="maradigma-boat-prices__title">' . \esc_html((string) $options['title']) . '</h3>';
        }

        if ($options['layout'] === 'cards') {
            echo '<div class="maradigma-boat-prices__cards">';

            foreach ($rows as $row) {
                echo '<div class="maradigma-boat-prices__card">';
                echo '<div class="maradigma-boat-prices__season">' . \esc_html(self::formatPriceRangeLabel($row, (string) $options['range_mode'])) . '</div>';
                echo '<div class="maradigma-boat-prices__price">' . \wp_kses_post(self::formatPriceAmountHtml($row, $currency, $options)) . '</div>';
                echo '</div>';
            }

            echo '</div>';
        } else {
            echo '<table class="maradigma-boat-prices__table">';

            if (!empty($options['show_headers'])) {
                echo '<thead><tr>';
                echo '<th>' . \esc_html__('Season', 'maradigma') . '</th>';
                echo '<th>' . \esc_html__('Price', 'maradigma') . '</th>';
                echo '</tr></thead>';
            }

            echo '<tbody>';

            foreach ($rows as $row) {
                echo '<tr class="maradigma-boat-prices__row">';
                echo '<td class="maradigma-boat-prices__season">' . \esc_html(self::formatPriceRangeLabel($row, (string) $options['range_mode'])) . '</td>';
                echo '<td class="maradigma-boat-prices__price">' . \wp_kses_post(self::formatPriceAmountHtml($row, $currency, $options)) . '</td>';
                echo '</tr>';
            }

            echo '</tbody>';
            echo '</table>';
        }

        echo '</div>';

        return (string) \ob_get_clean();
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private static function normalizeOptions(array $options): array
    {
        $layout = \trim((string) ($options['layout'] ?? 'cards'));
        if (\in_array($layout, ['blocks', 'table_vertical'], true)) {
            $layout = $layout === 'blocks' ? 'cards' : 'table';
        }
        if (!\in_array($layout, ['table', 'cards'], true)) {
            $layout = 'cards';
        }

        $rangeMode = \trim((string) ($options['range_mode'] ?? 'dates_short'));
        if (!\in_array($rangeMode, ['dates_short', 'dates_long', 'month'], true)) {
            $rangeMode = 'dates_short';
        }

        $orderBy = \trim((string) ($options['order_by'] ?? 'date_from_asc'));
        if (!\in_array($orderBy, ['date_from_asc', 'date_from_desc', 'price_asc', 'price_desc'], true)) {
            $orderBy = 'date_from_asc';
        }

        $vatMode = \trim((string) ($options['vat_mode'] ?? 'included'));
        if (!\in_array($vatMode, ['included', 'excluded', 'hidden_base', 'hidden_total'], true)) {
            $vatMode = 'included';
        }

        $vatPosition = \trim((string) ($options['vat_position'] ?? 'below'));
        if (!\in_array($vatPosition, ['below', 'inline', 'tooltip'], true)) {
            $vatPosition = 'below';
        }

        $currencyDisplay = \trim((string) ($options['currency_display'] ?? 'symbol'));
        if (!\in_array($currencyDisplay, ['symbol', 'iso'], true)) {
            $currencyDisplay = 'symbol';
        }

        $decimalsMode = \trim((string) ($options['decimals_mode'] ?? 'auto'));
        if (!\in_array($decimalsMode, ['auto', '0', '2'], true)) {
            $decimalsMode = 'auto';
        }

        return [
            'title'             => (string) ($options['title'] ?? \__('Prices', 'maradigma')),
            'show_title'        => self::truthy($options['show_title'] ?? true),
            'fallback'          => (string) ($options['fallback'] ?? $options['empty_text'] ?? ''),
            'layout'            => $layout,
            'range_mode'        => $rangeMode,
            'order_by'          => $orderBy,
            'show_headers'      => self::truthy($options['show_headers'] ?? true),
            'row_gap'           => \max(0, (int) ($options['row_gap'] ?? 10)),
            'vat_mode'          => $vatMode,
            'vat_use_backend'   => self::truthy($options['vat_use_backend'] ?? false),
            'vat_position'      => $vatPosition,
            'vat_text_included' => (string) ($options['vat_text_included'] ?? \__('VAT included', 'maradigma')),
            'vat_text_excluded' => (string) ($options['vat_text_excluded'] ?? \__('+ VAT', 'maradigma')),
            'currency_display'  => $currencyDisplay,
            'decimals_mode'     => $decimalsMode,
            'thousands_sep'     => (string) ($options['thousands_sep'] ?? '.'),
            'decimal_sep'       => (string) ($options['decimal_sep'] ?? ','),
        ];
    }

    /** @param mixed $value */
    private static function truthy($value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        return \in_array(\strtolower(\trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    /** @param mixed $value */
    private static function toFloatOrNull($value): ?float
    {
        if (\is_int($value) || \is_float($value)) {
            return (float) $value;
        }

        if (\is_string($value)) {
            $value = \str_replace(',', '.', \trim($value));

            if (\is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }

    /**
     * @param mixed $pricesPayload
     * @return array<int,array<string,mixed>>
     */
    private static function extractSeasonPrices($pricesPayload, string $currencyFallback): array
    {
        if (!\is_array($pricesPayload)) {
            return [];
        }

        $range = $pricesPayload['range'] ?? null;

        if (!\is_array($range) || $range === []) {
            return [];
        }

        $rows = [];

        foreach ($range as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $price = self::toFloatOrNull($row['price'] ?? null);

            if ($price === null || $price <= 0) {
                continue;
            }

            $dfRaw = \trim((string) ($row['date_start'] ?? ''));
            $dtRaw = \trim((string) ($row['date_end'] ?? ''));
            $currency = \strtoupper(\trim((string) ($row['currency'] ?? $row['currency_code'] ?? $currencyFallback)));

            $rows[] = [
                'date_from'     => self::parseDateOrNull($dfRaw),
                'date_to'       => self::parseDateOrNull($dtRaw),
                'date_from_raw' => $dfRaw,
                'date_to_raw'   => $dtRaw,
                'price'         => $price,
                'currency'      => $currency !== '' ? $currency : $currencyFallback,
                'vat_included'  => self::pickBoolOrNull($row, ['vat_included', 'tax_included', 'iva_included']),
                'vat_percent'   => self::toFloatOrNull($row['vat_percent'] ?? null),
                'total_price'   => self::toFloatOrNull($row['total_price'] ?? null),
            ];
        }

        return $rows;
    }

    /** @param array<int,array<string,mixed>> $rows */
    private static function sortPriceRows(array &$rows, string $orderBy): void
    {
        \usort($rows, static function (array $a, array $b) use ($orderBy): int {
            $aTs = ($a['date_from'] ?? null) instanceof \DateTimeImmutable ? $a['date_from']->getTimestamp() : 0;
            $bTs = ($b['date_from'] ?? null) instanceof \DateTimeImmutable ? $b['date_from']->getTimestamp() : 0;
            $aPrice = (float) ($a['price'] ?? 0);
            $bPrice = (float) ($b['price'] ?? 0);

            return match ($orderBy) {
                'date_from_desc' => $bTs <=> $aTs,
                'price_asc'      => $aPrice <=> $bPrice,
                'price_desc'     => $bPrice <=> $aPrice,
                default          => $aTs <=> $bTs,
            };
        });
    }

    /** @param array<string,mixed> $row */
    private static function formatPriceRangeLabel(array $row, string $mode): string
    {
        if (isset($row['label'])) {
            return (string) $row['label'];
        }

        $from = $row['date_from'] ?? null;
        $to   = $row['date_to'] ?? null;

        if (!($from instanceof \DateTimeImmutable) || !($to instanceof \DateTimeImmutable)) {
            $fromRaw = \trim((string) ($row['date_from_raw'] ?? ''));
            $toRaw   = \trim((string) ($row['date_to_raw'] ?? ''));

            if ($fromRaw !== '' && $toRaw !== '') {
                return $fromRaw . ' - ' . $toRaw;
            }

            return $fromRaw !== '' ? $fromRaw : ($toRaw !== '' ? $toRaw : \__('Season', 'maradigma'));
        }

        if ($mode === 'month') {
            $start = self::i18nMonthName($from);
            $end   = self::i18nMonthName($to);

            if ($start !== '' && $end !== '' && $start !== $end) {
                return $start . ' - ' . $end;
            }

            return $start !== '' ? $start : \__('Season', 'maradigma');
        }

        if ($mode === 'dates_long') {
            return self::i18nDate($from, 'd F Y') . ' - ' . self::i18nDate($to, 'd F Y');
        }

        return self::i18nDate($from, 'd M') . ' - ' . self::i18nDate($to, 'd M');
    }

    /** @param array<string,mixed> $row */
    /**
     * @param array<string,mixed> $options
     */
    private static function formatPriceAmountHtml(array $row, string $currencyFallback, array $options): string
    {
        $basePrice = self::toFloatOrNull($row['price'] ?? null);

        if ($basePrice === null) {
            return '';
        }

        $currency = \strtoupper(\trim((string) ($row['currency'] ?? $currencyFallback)));
        $currency = $currency !== '' ? $currency : $currencyFallback;

        $vatMode = (string) $options['vat_mode'];
        if (!empty($options['vat_use_backend']) && \is_bool($row['vat_included'] ?? null)) {
            $vatMode = !empty($row['vat_included']) ? 'included' : 'excluded';
        }

        $totalPrice = self::toFloatOrNull($row['total_price'] ?? null);
        $amount = $basePrice;

        if (($vatMode === 'included' || $vatMode === 'hidden_total') && $totalPrice !== null && $totalPrice > 0) {
            $amount = $totalPrice;
        }

        $html = '<span class="maradigma-boat-prices__amount">' .
            \esc_html(self::formatMoney($amount, $currency, $options)) .
            '</span>';

        $vatText = '';
        if ($vatMode === 'included') {
            $vatText = \trim((string) $options['vat_text_included']);
        } elseif ($vatMode === 'excluded') {
            $vatText = \trim((string) $options['vat_text_excluded']);
        }

        if ($vatText === '') {
            return $html;
        }

        if ($options['vat_position'] === 'inline') {
            return $html . ' <small class="maradigma-boat-prices__vat">' . \esc_html($vatText) . '</small>';
        }

        if ($options['vat_position'] === 'tooltip') {
            return $html . ' <span class="maradigma-boat-prices__vat maradigma-boat-prices__vat--tooltip" title="' . \esc_attr($vatText) . '">&#9432;</span>';
        }

        return $html . '<br><small class="maradigma-boat-prices__vat">' . \esc_html($vatText) . '</small>';
    }

    private static function parseDateOrNull(string $value): ?\DateTimeImmutable
    {
        $value = \trim($value);

        if ($value === '') {
            return null;
        }

        if (\ctype_digit($value)) {
            $timestamp = (int) $value;
            if ($timestamp > 0) {
                return (new \DateTimeImmutable('@' . $timestamp))->setTimezone(\wp_timezone());
            }
        }

        foreach (['Y-m-d', 'Y-m-d H:i:s', 'd/m/Y', 'd-m-Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value, \wp_timezone());
            if ($date instanceof \DateTimeImmutable) {
                return $date;
            }
        }

        try {
            return new \DateTimeImmutable($value, \wp_timezone());
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function i18nMonthName(\DateTimeImmutable $date): string
    {
        $value = \function_exists('date_i18n')
            ? (string) \date_i18n('F', $date->getTimestamp())
            : $date->format('F');

        $value = \trim($value);

        if ($value === '') {
            return '';
        }

        if (\function_exists('mb_substr') && \function_exists('mb_strtoupper')) {
            return \mb_strtoupper(\mb_substr($value, 0, 1, 'UTF-8'), 'UTF-8') .
                \mb_substr($value, 1, null, 'UTF-8');
        }

        return \ucfirst($value);
    }

    private static function i18nDate(\DateTimeImmutable $date, string $format): string
    {
        return \trim(\function_exists('date_i18n') ? (string) \date_i18n($format, $date->getTimestamp()) : $date->format($format));
    }

    /**
     * @param array<string,mixed> $options
     */
    private static function formatMoney(float $amount, string $currency, array $options): string
    {
        $decimals = 2;
        if ($options['decimals_mode'] === '0') {
            $decimals = 0;
        } elseif ($options['decimals_mode'] === 'auto') {
            $decimals = \abs($amount - \round($amount)) < 0.00001 ? 0 : 2;
        }

        $thousands = (string) $options['thousands_sep'];
        $decimal = (string) $options['decimal_sep'];
        $thousands = $thousands !== '' ? $thousands : '.';
        $decimal = $decimal !== '' ? $decimal : ',';

        $formatted = \number_format($amount, $decimals, $decimal, $thousands);

        if ($options['currency_display'] === 'iso') {
            return \trim($formatted . ' ' . $currency);
        }

        $symbol = self::currencySymbol($currency);
        if ($symbol === '') {
            return \trim($formatted . ' ' . $currency);
        }

        if ($currency === 'EUR' || \in_array($currency, ['CHF', 'DKK', 'NOK', 'SEK'], true)) {
            return \trim($formatted . ' ' . $symbol);
        }

        return \trim($symbol . ' ' . $formatted);
    }

    private static function currencySymbol(string $currency): string
    {
        return match (\strtoupper(\trim($currency))) {
            'EUR' => \html_entity_decode('&euro;', \ENT_QUOTES, 'UTF-8'),
            'USD' => '$',
            'GBP' => \html_entity_decode('&pound;', \ENT_QUOTES, 'UTF-8'),
            'CHF' => 'CHF',
            'DKK', 'NOK', 'SEK' => 'kr',
            default => '',
        };
    }

    /**
     * @param array<string,mixed> $node
     * @param array<int,string> $keys
     */
    private static function pickBoolOrNull(array $node, array $keys): ?bool
    {
        foreach ($keys as $key) {
            if (!\array_key_exists($key, $node)) {
                continue;
            }

            $value = $node[$key];
            if (\is_bool($value)) {
                return $value;
            }

            if (\is_int($value)) {
                return $value === 1;
            }

            if (\is_string($value)) {
                $value = \strtolower(\trim($value));
                if (\in_array($value, ['1', 'true', 'yes', 'y', 'on'], true)) {
                    return true;
                }
                if (\in_array($value, ['0', 'false', 'no', 'n', 'off'], true)) {
                    return false;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $data
     * @param mixed               $pricesPayload
     */
    private static function guessCurrency(array $data, $pricesPayload): string
    {
        foreach (['currency', 'currency_code'] as $key) {
            if (!empty($data[$key]) && \is_string($data[$key])) {
                $currency = \strtoupper(\trim((string) $data[$key]));
                if ($currency !== '') {
                    return $currency;
                }
            }
        }

        if (\is_array($pricesPayload)) {
            foreach (['currency', 'currency_code'] as $key) {
                $currency = self::findFirstScalarByKeyRecursive($pricesPayload, $key);
                if (\is_string($currency) && \trim($currency) !== '') {
                    return \strtoupper(\trim($currency));
                }
            }
        }

        return 'EUR';
    }

    /**
     * @param mixed $node
     * @return mixed
     */
    private static function findFirstScalarByKeyRecursive($node, string $key)
    {
        if (!\is_array($node)) {
            return null;
        }

        if (\array_key_exists($key, $node) && \is_scalar($node[$key])) {
            return $node[$key];
        }

        foreach ($node as $child) {
            if (\is_array($child)) {
                $found = self::findFirstScalarByKeyRecursive($child, $key);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }
}

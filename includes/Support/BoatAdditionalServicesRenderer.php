<?php

declare(strict_types=1);

namespace Maradigma\Support;

/**
 * Shared renderer for Maradigma boat additional services.
 *
 * This renderer is intentionally independent from Elementor and Gutenberg. It
 * centralizes the HTML structure and CSS class names used to render additional
 * services so shortcodes, Gutenberg blocks and Elementor widgets can reuse the
 * same markup and therefore the same frontend styles.
 *
 * Main wrapper/classes are aligned with the existing Elementor widget:
 * - .maradigma-boat-additionals
 * - .maradigma-boat-additionals__list
 * - .maradigma-boat-additionals__row
 * - .maradigma-boat-additionals__name
 * - .maradigma-boat-additionals__price
 */
final class BoatAdditionalServicesRenderer
{
    /**
     * Renders additional services from a full boat payload.
     *
     * Expected payload nodes supported:
     * - additional_services
     * - additionals
     *
     * @param array<string,mixed> $data
     * @param array<string,mixed> $options
     *
     * @return string
     */
    public static function render(array $data, array $options = []): string
    {
        $options = self::normalizeOptions($options);
        $services = self::extractAdditionalServices($data, (string) $options['language']);

        if ($services === []) {
            return '<div class="maradigma-boat-additionals maradigma-boat-additionals--empty">' .
                \esc_html((string) $options['fallback']) .
                '</div>';
        }

        \ob_start();

        echo \wp_kses_post(self::renderWrapperOpen($options));

        if ((bool) $options['show_title'] && (string) $options['title'] !== '') {
            echo '<h3 class="maradigma-boat-additionals__title">' . \esc_html((string) $options['title']) . '</h3>';
        }

        if ((bool) $options['group_by_category']) {
            $grouped = self::groupServicesByCategory($services);

            foreach ($grouped as $categoryLabel => $rows) {
                if ((bool) $options['show_category_title'] && $categoryLabel !== '') {
                    echo '<h4 class="maradigma-boat-additionals__category">' . \esc_html($categoryLabel) . '</h4>';
                }

                self::renderRows($rows, $options);
            }
        } else {
            self::renderRows($services, $options);
        }

        echo '</div>';

        return (string) \ob_get_clean();
    }

    /**
     * Renders already normalized additional-service rows.
     *
     * @param array<int,array<string,mixed>> $services
     * @param array<string,mixed>            $options
     *
     * @return string
     */
    public static function renderNormalized(array $services, array $options = []): string
    {
        $options = self::normalizeOptions($options);

        if ($services === []) {
            return '<div class="maradigma-boat-additionals maradigma-boat-additionals--empty">' .
                \esc_html((string) $options['fallback']) .
                '</div>';
        }

        \ob_start();

        echo \wp_kses_post(self::renderWrapperOpen($options));

        if ((bool) $options['show_title'] && (string) $options['title'] !== '') {
            echo '<h3 class="maradigma-boat-additionals__title">' . \esc_html((string) $options['title']) . '</h3>';
        }

        if ((bool) $options['group_by_category']) {
            foreach (self::groupServicesByCategory($services) as $categoryLabel => $rows) {
                if ((bool) $options['show_category_title'] && $categoryLabel !== '') {
                    echo '<h4 class="maradigma-boat-additionals__category">' . \esc_html($categoryLabel) . '</h4>';
                }

                self::renderRows($rows, $options);
            }
        } else {
            self::renderRows($services, $options);
        }

        echo '</div>';

        return (string) \ob_get_clean();
    }

    /**
     * Extracts and normalizes additional services from a full boat payload.
     *
     * @param array<string,mixed> $data
     * @param string              $language Current API/editor language, e.g. EN, ES.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function extractAdditionalServices(array $data, string $language = 'EN'): array
    {
        $node = null;

        foreach (['additional_services', 'additionals'] as $key) {
            if (isset($data[$key]) && \is_array($data[$key])) {
                $node = $data[$key];
                break;
            }
        }

        if (!\is_array($node) || $node === []) {
            return [];
        }

        $language = \strtoupper(\trim($language));
        if ($language === '') {
            $language = 'EN';
        }

        $out = [];

        foreach ($node as $item) {
            if (!\is_array($item)) {
                continue;
            }

            $name = self::pickTranslatedString(
                $item['name'] ?? $item['title'] ?? $item['label'] ?? $item['service_name'] ?? null,
                $item['translations'] ?? null,
                $language
            );

            $name = \trim($name);
            if ($name === '') {
                continue;
            }

            $category = '';
            if (isset($item['cat_data']) && \is_array($item['cat_data'])) {
                $category = self::pickTranslatedString(
                    $item['cat_data']['name'] ?? $item['cat_data']['title'] ?? null,
                    $item['cat_data']['translations'] ?? null,
                    $language
                );
            }

            if ($category === '' && isset($item['category']) && \is_scalar($item['category'])) {
                $category = \trim((string) $item['category']);
            }

            $description = self::pickTranslatedString(
                $item['description'] ?? $item['desc'] ?? null,
                $item['description_translations'] ?? $item['translations_description'] ?? null,
                $language
            );

            $prices = isset($item['prices']) && \is_array($item['prices']) ? $item['prices'] : [];
            $names  = isset($item['names']) && \is_array($item['names']) ? $item['names'] : [];

            $out[] = [
                'id'          => isset($item['id']) && \is_numeric($item['id']) ? (int) $item['id'] : null,
                'name'        => $name,
                'description' => \trim($description),
                'quantity'    => isset($item['quantity']) && \is_numeric($item['quantity']) ? (int) $item['quantity'] : 1,
                'category'    => \trim($category),

                'optional_type' => isset($item['optional_type']) && \is_numeric($item['optional_type']) ? (int) $item['optional_type'] : null,
                'payment'       => isset($item['payment']) && \is_numeric($item['payment']) ? (int) $item['payment'] : null,
                'price_type'    => isset($item['price_type']) && \is_numeric($item['price_type']) ? (int) $item['price_type'] : null,

                'optional_type_name' => self::pickBadgeName($item, $names, 'optional_type'),
                'payment_name'       => self::pickBadgeName($item, $names, 'payment'),
                'price_type_name'    => self::pickBadgeName($item, $names, 'price_type'),

                'prices'       => $prices,
                'currency'     => isset($item['currency']) && \is_scalar($item['currency']) ? \strtoupper(\trim((string) $item['currency'])) : '',
                'raw_price'    => isset($item['price']) ? (string) $item['price'] : (isset($item['base_price']) ? (string) $item['base_price'] : ''),
                'raw_total'    => isset($item['total_price']) ? (string) $item['total_price'] : '',
                'raw_vat'      => isset($item['vat_price']) ? (string) $item['vat_price'] : '',
                'raw_subtotal' => isset($item['subtotal_price']) ? (string) $item['subtotal_price'] : '',
            ];
        }

        return $out;
    }

    /**
     * Returns renderer defaults. Useful for Elementor/Gutenberg/shortcodes.
     *
     * @return array<string,mixed>
     */
    public static function getDefaultOptions(): array
    {
        return [
            'language' => 'EN',

            'title'      => \__('Additional services', 'maradigma'),
            'show_title' => true,
            'fallback'   => \__('No additional services available.', 'maradigma'),
            'free_text'  => \__('This additional service is free.', 'maradigma'),
            'wrapper_attributes' => '',

            'layout'              => 'blocks', // blocks|table
            'show_headers'        => true,
            'group_by_category'   => true,
            'show_category_title' => true,

            'show_badges'              => true,
            'show_badge_optional_type' => true,
            'show_badge_price_type'    => true,
            'show_badge_payment'       => true,
            'badge_style'              => 'friendly', // friendly|raw

            'show_description' => false,
            'show_quantity'    => true,
            'price_display'    => 'total_html', // total_html|total|base|vat|raw_total|raw_base

            'vat_mode'          => 'included', // included|excluded|hidden_total|hidden_base
            'vat_position'      => 'below',    // below|inline|tooltip
            'vat_text_included' => \__('VAT included', 'maradigma'),
            'vat_text_excluded' => \__('+ VAT', 'maradigma'),

            'currency_display' => 'symbol', // symbol|iso
            'decimals_mode'    => 'auto',   // auto|0|2
            'thousands_sep'    => '.',
            'decimal_sep'      => ',',
        ];
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public static function normalizeOptions(array $options): array
    {
        $defaults = self::getDefaultOptions();
        $options = \array_merge($defaults, $options);

        $options['language'] = \strtoupper(\trim((string) ($options['language'] ?? $defaults['language'])));
        if ($options['language'] === '') {
            $options['language'] = $defaults['language'];
        }

        $options['title'] = \trim((string) ($options['title'] ?? $defaults['title']));
        $options['fallback'] = \trim((string) ($options['fallback'] ?? $defaults['fallback']));
        $options['free_text'] = \trim((string) ($options['free_text'] ?? $defaults['free_text']));
        if ($options['fallback'] === '') {
            $options['fallback'] = $defaults['fallback'];
        }

        if ($options['free_text'] === '') {
            $options['free_text'] = $defaults['free_text'];
        }

        $options['title'] = MultilangAdapter::translateEditableDefault(
            (string) $options['title'],
            'Additional services',
            (string) $defaults['title'],
            'Maradigma Elementor Widgets',
            'boat_additional_services_title'
        );
        $options['fallback'] = MultilangAdapter::translateEditableDefault(
            (string) $options['fallback'],
            'No additional services available.',
            (string) $defaults['fallback'],
            'Maradigma Elementor Widgets',
            'boat_additional_services_fallback'
        );
        $options['free_text'] = MultilangAdapter::translateEditableDefault(
            (string) $options['free_text'],
            'This additional service is free.',
            (string) $defaults['free_text'],
            'Maradigma Elementor Widgets',
            'boat_additional_services_free_text'
        );

        foreach ([
            'show_title',
            'show_headers',
            'group_by_category',
            'show_category_title',
            'show_badges',
            'show_badge_optional_type',
            'show_badge_price_type',
            'show_badge_payment',
            'show_description',
            'show_quantity',
        ] as $key) {
            $options[$key] = self::toBool($options[$key] ?? $defaults[$key]);
        }

        $layout = \trim((string) ($options['layout'] ?? 'blocks'));
        $options['layout'] = \in_array($layout, ['blocks', 'table'], true) ? $layout : 'blocks';

        $badgeStyle = \trim((string) ($options['badge_style'] ?? 'friendly'));
        $options['badge_style'] = \in_array($badgeStyle, ['friendly', 'raw'], true) ? $badgeStyle : 'friendly';

        $priceDisplay = \trim((string) ($options['price_display'] ?? 'total_html'));
        $options['price_display'] = \in_array($priceDisplay, ['total_html', 'total', 'base', 'vat', 'raw_total', 'raw_base'], true)
            ? $priceDisplay
            : 'total_html';

        $vatMode = \trim((string) ($options['vat_mode'] ?? 'included'));
        $options['vat_mode'] = \in_array($vatMode, ['included', 'excluded', 'hidden_total', 'hidden_base'], true)
            ? $vatMode
            : 'included';

        $vatPosition = \trim((string) ($options['vat_position'] ?? 'below'));
        $options['vat_position'] = \in_array($vatPosition, ['below', 'inline', 'tooltip'], true)
            ? $vatPosition
            : 'below';

        $currencyDisplay = \trim((string) ($options['currency_display'] ?? 'symbol'));
        $options['currency_display'] = \in_array($currencyDisplay, ['symbol', 'iso'], true)
            ? $currencyDisplay
            : 'symbol';

        $decimalsMode = \trim((string) ($options['decimals_mode'] ?? 'auto'));
        $options['decimals_mode'] = \in_array($decimalsMode, ['auto', '0', '2'], true)
            ? $decimalsMode
            : 'auto';

        $options['vat_text_included'] = \trim((string) ($options['vat_text_included'] ?? $defaults['vat_text_included']));
        $options['vat_text_excluded'] = \trim((string) ($options['vat_text_excluded'] ?? $defaults['vat_text_excluded']));
        $options['thousands_sep'] = (string) ($options['thousands_sep'] ?? '.');
        $options['decimal_sep'] = (string) ($options['decimal_sep'] ?? ',');
        $options['wrapper_attributes'] = \trim((string) ($options['wrapper_attributes'] ?? ''));

        if ($options['vat_text_included'] === '') {
            $options['vat_text_included'] = $defaults['vat_text_included'];
        }

        if ($options['vat_text_excluded'] === '') {
            $options['vat_text_excluded'] = $defaults['vat_text_excluded'];
        }

        $options['vat_text_included'] = MultilangAdapter::translateEditableDefault(
            (string) $options['vat_text_included'],
            'VAT included',
            (string) $defaults['vat_text_included'],
            'Maradigma Elementor Widgets',
            'boat_additional_services_vat_included'
        );
        $options['vat_text_excluded'] = MultilangAdapter::translateEditableDefault(
            (string) $options['vat_text_excluded'],
            '+ VAT',
            (string) $defaults['vat_text_excluded'],
            'Maradigma Elementor Widgets',
            'boat_additional_services_vat_excluded'
        );

        if (!(bool) $options['show_badges']) {
            $options['show_badge_optional_type'] = false;
            $options['show_badge_price_type'] = false;
            $options['show_badge_payment'] = false;
        }

        return $options;
    }

    /**
     * @param array<string,mixed> $options
     */
    private static function renderWrapperOpen(array $options): string
    {
        $wrapperAttributes = \trim((string) ($options['wrapper_attributes'] ?? ''));
        if ($wrapperAttributes !== '') {
            return '<div ' . $wrapperAttributes . '>';
        }

        return '<div class="maradigma-boat-additionals maradigma-boat-additionals--' .
            \esc_attr((string) $options['layout']) .
            '">';
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,mixed>            $options
     */
    private static function renderRows(array $rows, array $options): void
    {
        if ((string) $options['layout'] === 'table') {
            self::renderTable($rows, $options);
            return;
        }

        echo '<ul class="maradigma-boat-additionals__list">';

        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            self::renderRow($row, $options);
        }

        echo '</ul>';
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $options
     */
    private static function renderRow(array $row, array $options): void
    {
        $name = \trim((string) ($row['name'] ?? ''));
        if ($name === '') {
            return;
        }

        $description = \trim((string) ($row['description'] ?? ''));
        $quantity = isset($row['quantity']) && \is_numeric($row['quantity']) ? (int) $row['quantity'] : 1;

        echo '<li class="maradigma-boat-additionals__item">';
        echo '<div class="maradigma-boat-additionals__row">';

        echo '<div class="maradigma-boat-additionals__left">';
        echo '<span class="maradigma-boat-additionals__name">' . \esc_html($name) . '</span>';

        if ((bool) $options['show_quantity'] && $quantity > 1) {
            echo ' <span class="maradigma-boat-additionals__qty">x' . \esc_html((string) $quantity) . '</span>';
        }

        self::renderBadges($row, $options);

        if ((bool) $options['show_description'] && $description !== '') {
            echo '<div class="maradigma-boat-additionals__desc">' . \esc_html($description) . '</div>';
        }

        echo '</div>';

        echo '<div class="maradigma-boat-additionals__right">';
        echo '<div class="maradigma-boat-additionals__price">';
        self::renderPriceAndVat($row, $options);
        echo '</div>';
        echo '</div>';

        echo '</div>';
        echo '</li>';
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,mixed>            $options
     */
    private static function renderTable(array $rows, array $options): void
    {
        $anyBadge = self::shouldRenderAnyBadge($options);

        echo '<table class="maradigma-boat-additionals__table">';

        if ((bool) $options['show_headers']) {
            echo '<thead><tr>';
            echo '<th>' . \esc_html__('Service', 'maradigma') . '</th>';

            if ($anyBadge) {
                echo '<th>' . \esc_html__('Details', 'maradigma') . '</th>';
            }

            echo '<th style="text-align:right;">' . \esc_html__('Price', 'maradigma') . '</th>';
            echo '</tr></thead>';
        }

        echo '<tbody>';

        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $name = \trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $description = \trim((string) ($row['description'] ?? ''));
            $quantity = isset($row['quantity']) && \is_numeric($row['quantity']) ? (int) $row['quantity'] : 1;

            echo '<tr class="maradigma-boat-additionals__tr">';

            echo '<td class="maradigma-boat-additionals__td maradigma-boat-additionals__td--name">';
            echo '<div class="maradigma-boat-additionals__name">' . \esc_html($name) . '</div>';

            if ((bool) $options['show_quantity'] && $quantity > 1) {
                echo '<div class="maradigma-boat-additionals__qty">x' . \esc_html((string) $quantity) . '</div>';
            }

            if ((bool) $options['show_description'] && $description !== '') {
                echo '<div class="maradigma-boat-additionals__desc">' . \esc_html($description) . '</div>';
            }

            echo '</td>';

            if ($anyBadge) {
                echo '<td class="maradigma-boat-additionals__td maradigma-boat-additionals__td--badges">';
                self::renderBadges($row, $options);
                echo '</td>';
            }

            echo '<td class="maradigma-boat-additionals__td maradigma-boat-additionals__td--price" style="text-align:right;">';
            echo '<div class="maradigma-boat-additionals__price">';
            self::renderPriceAndVat($row, $options);
            echo '</div>';
            echo '</td>';

            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $options
     */
    private static function renderBadges(array $row, array $options): void
    {
        if (!self::shouldRenderAnyBadge($options)) {
            return;
        }

        $badges = self::buildBadgeTriplet($row, $options);
        if ($badges === []) {
            return;
        }

        echo '<div class="maradigma-boat-additionals__badges">';

        $index = 0;
        foreach ($badges as $badge) {
            $label = \trim((string) ($badge['label'] ?? ''));
            if ($label === '') {
                continue;
            }

            $title = \trim((string) ($badge['title'] ?? ''));

            if ($index > 0) {
                echo '<span class="maradigma-boat-additionals__badge-sep" aria-hidden="true">·</span>';
            }

            echo '<span class="maradigma-boat-additionals__badge"' .
                ($title !== '' ? ' title="' . \esc_attr($title) . '"' : '') .
                '>' . \esc_html($label) . '</span>';

            $index++;
        }

        echo '</div>';
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $options
     */
    private static function renderPriceAndVat(array $row, array $options): void
    {
        $baseAmount = self::resolveBaseAmount($row);
        $totalAmount = self::resolveTotalAmount($row);

        if (self::isFreeService($row, $baseAmount, $totalAmount)) {
            echo '<span class="maradigma-boat-additionals__value maradigma-boat-additionals__value--included">' .
                \esc_html(\__('Included', 'maradigma')) .
                '</span>';

            if ((string) $options['free_text'] !== '') {
                echo '<br><small class="maradigma-boat-additionals__vat maradigma-boat-additionals__vat--free">' .
                    \esc_html((string) $options['free_text']) .
                    '</small>';
            }

            return;
        }

        if ($baseAmount === null && $totalAmount === null) {
            $fallback = self::formatAdditionalServicePrice($row, (string) $options['price_display']);
            if ($fallback !== '') {
                echo \esc_html($fallback);
            }
            return;
        }

        $amountToShow = self::chooseAmountToShow($baseAmount, $totalAmount, (string) $options['vat_mode']);
        $currency = self::guessCurrencyFromRow($row);

        echo '<span class="maradigma-boat-additionals__value">' .
            \esc_html(self::formatMoney((float) $amountToShow, $currency, $options)) .
            '</span>';

        $vatText = '';
        if ((string) $options['vat_mode'] === 'included') {
            $vatText = (string) $options['vat_text_included'];
        } elseif ((string) $options['vat_mode'] === 'excluded') {
            $vatText = (string) $options['vat_text_excluded'];
        }

        if ($vatText === '') {
            return;
        }

        if ((string) $options['vat_position'] === 'inline') {
            echo ' <small class="maradigma-boat-additionals__vat">' . \esc_html($vatText) . '</small>';
            return;
        }

        if ((string) $options['vat_position'] === 'tooltip') {
            echo ' <span class="maradigma-boat-additionals__vat maradigma-boat-additionals__vat--tooltip" title="' . \esc_attr($vatText) . '">ⓘ</span>';
            return;
        }

        echo '<br><small class="maradigma-boat-additionals__vat">' . \esc_html($vatText) . '</small>';
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function isFreeService(array $row, ?float $baseAmount, ?float $totalAmount): bool
    {
        $optionalType = isset($row['optional_type']) && \is_numeric($row['optional_type']) ? (int) $row['optional_type'] : null;
        $optionalTypeName = self::trimScalar($row['optional_type_name'] ?? '');

        if (
            $optionalType === 3 ||
            \stripos($optionalTypeName, 'free') !== false ||
            \stripos($optionalTypeName, 'gratis') !== false
        ) {
            return true;
        }

        if ($totalAmount !== null) {
            return \abs($totalAmount) < 0.00001;
        }

        if ($baseAmount !== null) {
            return \abs($baseAmount) < 0.00001;
        }

        return false;
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function resolveBaseAmount(array $row): ?float
    {
        $prices = isset($row['prices']) && \is_array($row['prices']) ? $row['prices'] : [];
        $pformat = isset($prices['pformat']) && \is_array($prices['pformat']) ? $prices['pformat'] : [];

        return self::firstFloat([
            $row['raw_price'] ?? null,
            $pformat['base'] ?? null,
            $row['raw_subtotal'] ?? null,
        ]);
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function resolveTotalAmount(array $row): ?float
    {
        $prices = isset($row['prices']) && \is_array($row['prices']) ? $row['prices'] : [];
        $pformat = isset($prices['pformat']) && \is_array($prices['pformat']) ? $prices['pformat'] : [];

        return self::firstFloat([
            $row['raw_total'] ?? null,
            $pformat['total'] ?? null,
        ]);
    }

    /**
     * @param array<int,mixed> $values
     */
    private static function firstFloat(array $values): ?float
    {
        foreach ($values as $value) {
            $float = self::toFloatOrNull($value);
            if ($float !== null) {
                return $float;
            }
        }

        return null;
    }

    /**
     * Selects amount to show.
     */
    private static function chooseAmountToShow(?float $baseAmount, ?float $totalAmount, string $vatMode): float
    {
        return match ($vatMode) {
            'included', 'hidden_total' => ($totalAmount !== null && $totalAmount > 0.0) ? $totalAmount : (float) ($baseAmount ?? 0.0),
            'excluded', 'hidden_base'  => ($baseAmount !== null && $baseAmount > 0.0) ? $baseAmount : (float) ($totalAmount ?? 0.0),
            default                    => ($totalAmount !== null && $totalAmount > 0.0) ? $totalAmount : (float) ($baseAmount ?? 0.0),
        };
    }

    /**
     * @param array<string,mixed> $row
     * @return array<int,array{label:string,title:string}>
     */
    private static function buildBadgeTriplet(array $row, array $options): array
    {
        if ((string) $options['badge_style'] === 'raw') {
            $out = [];

            if ((bool) $options['show_badge_optional_type'] && self::trimScalar($row['optional_type_name'] ?? '') !== '') {
                $out[] = ['label' => self::trimScalar($row['optional_type_name'] ?? ''), 'title' => ''];
            }
            if ((bool) $options['show_badge_price_type'] && self::trimScalar($row['price_type_name'] ?? '') !== '') {
                $out[] = ['label' => self::trimScalar($row['price_type_name'] ?? ''), 'title' => ''];
            }
            if ((bool) $options['show_badge_payment'] && self::trimScalar($row['payment_name'] ?? '') !== '') {
                $out[] = ['label' => self::trimScalar($row['payment_name'] ?? ''), 'title' => ''];
            }

            return $out;
        }

        $out = [];

        if ((bool) $options['show_badge_optional_type']) {
            $badge = self::normalizeOptionalTypeBadge($row, self::trimScalar($row['optional_type_name'] ?? ''));
            if ($badge !== null) {
                $out[] = $badge;
            }
        }

        if ((bool) $options['show_badge_price_type']) {
            $badge = self::normalizePriceTypeBadge($row, self::trimScalar($row['price_type_name'] ?? ''));
            if ($badge !== null) {
                $out[] = $badge;
            }
        }

        if ((bool) $options['show_badge_payment']) {
            $badge = self::normalizePaymentBadge($row, self::trimScalar($row['payment_name'] ?? ''));
            if ($badge !== null) {
                $out[] = $badge;
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $row
     * @return array{label:string,title:string}|null
     */
    private static function normalizeOptionalTypeBadge(array $row, string $rawLabel): ?array
    {
        $code = isset($row['optional_type']) && \is_numeric($row['optional_type']) ? (int) $row['optional_type'] : null;

        if ($code === 1 || \stripos($rawLabel, 'mandatory') !== false || \stripos($rawLabel, 'oblig') !== false) {
            return [
                'label' => \__('Required', 'maradigma'),
                'title' => \__('This extra is required for this booking.', 'maradigma'),
            ];
        }

        if ($code === 2 || \stripos($rawLabel, 'optional') !== false || \stripos($rawLabel, 'opcional') !== false) {
            return [
                'label' => \__('Optional', 'maradigma'),
                'title' => \__('This extra can be added if you want.', 'maradigma'),
            ];
        }

        if ($code === 3 || \stripos($rawLabel, 'free') !== false || \stripos($rawLabel, 'gratis') !== false) {
            return [
                'label' => \__('Free', 'maradigma'),
                'title' => \__('This extra has no additional cost.', 'maradigma'),
            ];
        }

        return null;
    }

    /**
     * @param array<string,mixed> $row
     * @return array{label:string,title:string}|null
     */
    private static function normalizePriceTypeBadge(array $row, string $rawLabel): ?array
    {
        $code = isset($row['price_type']) && \is_numeric($row['price_type']) ? (int) $row['price_type'] : null;

        if ($code === 1 || \stripos($rawLabel, 'booking') !== false || \stripos($rawLabel, 'reserva') !== false) {
            return [
                'label' => \__('Per booking', 'maradigma'),
                'title' => \__('Charged once for the whole reservation.', 'maradigma'),
            ];
        }

        if ($code === 2 || \stripos($rawLabel, 'day') !== false || \stripos($rawLabel, 'día') !== false || \stripos($rawLabel, 'dia') !== false) {
            return [
                'label' => \__('Per day', 'maradigma'),
                'title' => \__('Charged for each day of the reservation.', 'maradigma'),
            ];
        }

        return null;
    }

    /**
     * @param array<string,mixed> $row
     * @return array{label:string,title:string}|null
     */
    private static function normalizePaymentBadge(array $row, string $rawLabel): ?array
    {
        $code = isset($row['payment']) && \is_numeric($row['payment']) ? (int) $row['payment'] : null;

        if ($code === 1 || \stripos($rawLabel, 'online') !== false) {
            return [
                'label' => \__('Pay online', 'maradigma'),
                'title' => \__('Payment is made online during booking.', 'maradigma'),
            ];
        }

        if ($code === 2 || \stripos($rawLabel, 'port') !== false || \stripos($rawLabel, 'puerto') !== false) {
            return [
                'label' => \__('Pay at the port', 'maradigma'),
                'title' => \__('Payment is made at the port on the rental day.', 'maradigma'),
            ];
        }

        if ($code === 3 || \stripos($rawLabel, 'office') !== false || \stripos($rawLabel, 'oficina') !== false) {
            return [
                'label' => \__('Pay at the office', 'maradigma'),
                'title' => \__('Payment is made at the office.', 'maradigma'),
            ];
        }

        return null;
    }

    /**
     * @param array<int,array<string,mixed>> $services
     * @return array<string,array<int,array<string,mixed>>>
     */
    private static function groupServicesByCategory(array $services): array
    {
        $grouped = [];

        foreach ($services as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $category = \trim((string) ($row['category'] ?? ''));
            if ($category === '') {
                $category = \__('Other', 'maradigma');
            }

            if (!isset($grouped[$category])) {
                $grouped[$category] = [];
            }

            $grouped[$category][] = $row;
        }

        \ksort($grouped);

        return $grouped;
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function formatAdditionalServicePrice(array $row, string $priceDisplay): string
    {
        $prices = isset($row['prices']) && \is_array($row['prices']) ? $row['prices'] : [];
        $pformat = isset($prices['pformat']) && \is_array($prices['pformat']) ? $prices['pformat'] : [];
        $html = isset($prices['html']) && \is_array($prices['html']) ? $prices['html'] : [];

        switch ($priceDisplay) {
            case 'total_html':
                return self::firstString([$html['total'] ?? null, $pformat['total'] ?? null, $row['raw_total'] ?? null, $row['raw_price'] ?? null]);

            case 'total':
                return self::firstString([$pformat['total'] ?? null, $html['total'] ?? null, $row['raw_total'] ?? null, $row['raw_price'] ?? null]);

            case 'base':
                return self::firstString([$pformat['base'] ?? null, $html['base'] ?? null, $row['raw_price'] ?? null, $row['raw_total'] ?? null]);

            case 'vat':
                return self::firstString([$pformat['vat'] ?? null, $html['vat'] ?? null, $row['raw_vat'] ?? null]);

            case 'raw_total':
                return \trim((string) ($row['raw_total'] ?? ''));

            case 'raw_base':
                return \trim((string) ($row['raw_price'] ?? ''));

            default:
                return self::firstString([$row['raw_total'] ?? null, $row['raw_price'] ?? null]);
        }
    }

    /**
     * @param array<int,mixed> $values
     */
    private static function firstString(array $values): string
    {
        foreach ($values as $value) {
            if (\is_scalar($value)) {
                $string = \trim((string) $value);
                if ($string !== '') {
                    return \wp_strip_all_tags($string);
                }
            }
        }

        return '';
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function guessCurrencyFromRow(array $row): string
    {
        if (isset($row['currency']) && \is_string($row['currency']) && \trim($row['currency']) !== '') {
            return \strtoupper(\trim($row['currency']));
        }

        $prices = isset($row['prices']) && \is_array($row['prices']) ? $row['prices'] : [];
        foreach (['currency', 'currency_code'] as $key) {
            if (isset($prices[$key]) && \is_string($prices[$key]) && \trim($prices[$key]) !== '') {
                return \strtoupper(\trim($prices[$key]));
            }
        }

        return 'EUR';
    }

    /**
     * @param array<string,mixed> $options
     */
    private static function formatMoney(float $amount, string $currency, array $options): string
    {
        $currency = \strtoupper(\trim($currency));
        if ($currency === '') {
            $currency = 'EUR';
        }

        $decimalsMode = (string) ($options['decimals_mode'] ?? 'auto');
        $decimals = 2;
        if ($decimalsMode === '0') {
            $decimals = 0;
        } elseif ($decimalsMode === '2') {
            $decimals = 2;
        } elseif ($decimalsMode === 'auto') {
            $decimals = (\abs($amount - \round($amount)) < 0.00001) ? 0 : 2;
        }

        $thousands = (string) ($options['thousands_sep'] ?? '.');
        $decimal = (string) ($options['decimal_sep'] ?? ',');
        $thousands = $thousands !== '' ? $thousands : '.';
        $decimal = $decimal !== '' ? $decimal : ',';

        $number = \number_format($amount, $decimals, $decimal, $thousands);

        if ((string) ($options['currency_display'] ?? 'symbol') === 'iso') {
            return \trim($number . ' ' . $currency);
        }

        $symbol = self::currencySymbol($currency);
        if ($symbol !== '') {
            return $currency === 'EUR' ? \trim($number . ' ' . $symbol) : \trim($symbol . ' ' . $number);
        }

        return \trim($number . ' ' . $currency);
    }

    /**
     * Returns the display symbol for a currency code.
     */
    private static function currencySymbol(string $currency): string
    {
        return match (\strtoupper($currency)) {
            'EUR' => '€',
            'USD' => '$',
            'GBP' => '£',
            'CHF' => 'CHF',
            'DKK', 'NOK', 'SEK' => 'kr',
            default => '',
        };
    }

    /**
     * @param mixed $value
     */
    private static function toFloatOrNull($value): ?float
    {
        if (\is_int($value) || \is_float($value)) {
            return (float) $value;
        }

        if (!\is_string($value)) {
            return null;
        }

        $value = \trim($value);
        if ($value === '') {
            return null;
        }

        $value = \str_replace(["\xc2\xa0", ' ', '€', '$', '£'], '', $value);
        $value = (string) \preg_replace('/[A-Za-z]{3}/', '', $value);
        $value = \trim($value);

        if (\strpos($value, ',') !== false && \strpos($value, '.') !== false) {
            $value = \str_replace('.', '', $value);
            $value = \str_replace(',', '.', $value);
        } elseif (\strpos($value, ',') !== false && \strpos($value, '.') === false) {
            $value = \str_replace(',', '.', $value);
        }

        return \is_numeric($value) ? (float) $value : null;
    }

    /**
     * @param mixed $base
     * @param mixed $translations
     */
    private static function pickTranslatedString($base, $translations, string $language): string
    {
        $language = \strtoupper(\trim($language));

        if (\is_array($translations)) {
            if (isset($translations[$language]) && \is_string($translations[$language]) && \trim($translations[$language]) !== '') {
                return \trim($translations[$language]);
            }

            $languageLower = \strtolower($language);
            if (isset($translations[$languageLower]) && \is_string($translations[$languageLower]) && \trim($translations[$languageLower]) !== '') {
                return \trim($translations[$languageLower]);
            }

            foreach ($translations as $translation) {
                if (\is_string($translation) && \trim($translation) !== '') {
                    return \trim($translation);
                }
            }
        }

        return \is_scalar($base) ? \trim((string) $base) : '';
    }

    /**
     * @param array<string,mixed> $item
     * @param array<string,mixed> $names
     */
    private static function pickBadgeName(array $item, array $names, string $key): string
    {
        if (isset($names[$key]) && \is_scalar($names[$key])) {
            return \trim((string) $names[$key]);
        }

        $directKey = $key . '_name';
        if (isset($item[$directKey]) && \is_scalar($item[$directKey])) {
            return \trim((string) $item[$directKey]);
        }

        return '';
    }

    /**
     * @param mixed $value
     */
    private static function trimScalar($value): string
    {
        return \is_scalar($value) ? \trim((string) $value) : '';
    }

    /**
     * @param mixed $value
     */
    private static function toBool($value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        if (\is_int($value) || \is_float($value)) {
            return (int) $value === 1;
        }

        $value = \strtolower(\trim((string) $value));

        return \in_array($value, ['1', 'true', 'yes', 'on', 'y', 'si', 'sí'], true);
    }

    /**
     * @param array<string,mixed> $options
     */
    private static function shouldRenderAnyBadge(array $options): bool
    {
        return (bool) $options['show_badges'] && (
            (bool) $options['show_badge_optional_type'] ||
            (bool) $options['show_badge_price_type'] ||
            (bool) $options['show_badge_payment']
        );
    }
}

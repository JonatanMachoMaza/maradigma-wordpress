<?php

declare(strict_types=1);

namespace Maradigma\Support;

/**
 * Small general-purpose helpers used across the plugin.
 *
 * Keep this class strict: only pure helpers, no business logic.
 */
final class Utils
{
    /**
     * Safe array getter with default.
     *
     * @template TDefault
     * @param array<string,mixed> $arr
     * @param string $key
     * @param TDefault $default
     * @return mixed|TDefault
     */
    public static function arrayGet(array $arr, string $key, mixed $default = null): mixed
    {
        return \array_key_exists($key, $arr) ? $arr[$key] : $default;
    }

    /**
     * Builds a stable query string from an associative array.
     * Keys are sorted to ensure stable output (useful for cache keys).
     *
     * @param array<string,mixed> $params
     * @return string
     */
    public static function buildStableQuery(array $params): string
    {
        \ksort($params);

        // Remove null/empty values
        $clean = [];
        foreach ($params as $k => $v) {
            if (!\is_string($k) || $k === '') continue;
            if ($v === null || $v === '') continue;
            $clean[$k] = $v;
        }

        return \http_build_query($clean, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Safe JSON decode returning associative arrays.
     *
     * @param string $json
     * @return array<string,mixed>
     */
    public static function jsonDecodeAssoc(string $json): array
    {
        $json = \trim($json);
        if ($json === '') return [];

        try {
            $decoded = \json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            return \is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Returns plugin version if available (falls back to '0.0.0').
     *
     * @return string
     */
    public static function pluginVersion(): string
    {
        if (\defined('MARADIGMA_PLUGIN_VERSION')) {
            return (string) MARADIGMA_PLUGIN_VERSION;
        }
        return '0.0.0';
    }

    /**
     * Format a money amount for card rendering (plain text).
     *
     * Notes:
     * - Keep it framework-agnostic (no esc_* here).
     * - Escape/sanitize on output layer (wp_kses_post / esc_html) where needed.
     *
     * @param float $amount
     * @param string $currency ISO code (e.g. EUR, USD)
     * @param int $decimals Number of decimals for non-EUR currencies (default 2)
     * @return string
     */
    public static function formatMoney(float $amount, string $currency = 'EUR', int $decimals = 2): string
    {
        $currency = \strtoupper(\trim($currency));

        if ($currency === 'EUR') {
            // 650€ (0 decimals, Spanish thousands/decimal separators)
            return \number_format($amount, 0, ',', '.') . '€';
        }

        // 650.00 USD
        return \number_format($amount, $decimals, '.', '') . ' ' . $currency;
    }

    public static function formatMoneyAdvanced(float $amount, string $currency, array $fmt = []): string
    {
        $currency = strtoupper(trim($currency ?: 'EUR'));

        $display   = (string)($fmt['currency_display'] ?? 'symbol'); // symbol|iso
        $decMode   = (string)($fmt['decimals_mode'] ?? 'auto');      // auto|0|2
        $thousands = (string)($fmt['thousands_sep'] ?? '.');
        $decimal   = (string)($fmt['decimal_sep'] ?? ',');

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

        $symbol = self::currencySymbol($currency);
        if ($symbol !== '') {
            return ($currency === 'EUR') ? ($num . ' ' . $symbol) : ($symbol . ' ' . $num);
        }

        return $num . ' ' . $currency;
    }

    private static function currencySymbol(string $currency): string
    {
        return match (strtoupper($currency)) {
            'EUR' => '€',
            'USD' => '$',
            'GBP' => '£',
            'CHF' => 'CHF',
            'DKK', 'NOK', 'SEK' => 'kr',
            default => '',
        };
    }

    public static function toFloatOrNull(mixed $v): ?float
    {
        if (is_int($v) || is_float($v)) return (float)$v;
        if (!is_string($v)) return null;

        $s = trim($v);
        if ($s === '') return null;

        $s = str_replace([' ', '€'], '', $s);

        if (strpos($s, ',') !== false && strpos($s, '.') !== false) {
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        } elseif (strpos($s, ',') !== false) {
            $s = str_replace(',', '.', $s);
        }

        return is_numeric($s) ? (float)$s : null;
    }
}

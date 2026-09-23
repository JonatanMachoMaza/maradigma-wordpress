<?php
declare(strict_types=1);

namespace Maradigma\Support;

/**
 * Centralized sanitization and validation for shortcode/builder attributes.
 *
 * Any UI integration (Elementor/Gutenberg) should pass its attributes through
 * this class to keep a single security/validation policy and avoid XSS/logic bugs.
 */
final class Sanitizer
{
    /**
     * Sanitizes an integer ID (>= 1) or returns null.
     *
     * @param mixed $value
     * @return int|null
     */
    public static function id(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $int = (int) $value;
        return $int > 0 ? $int : null;
    }

    /**
     * Sanitizes a slug string (letters, numbers, dashes, underscores) or returns null.
     *
     * @param mixed $value
     * @return string|null
     */
    public static function slug(mixed $value): ?string
    {
        if (!\is_string($value) || $value === '') {
            return null;
        }

        $s = \trim($value);

        // Prefer WP native sanitize_title if available
        if (\function_exists('sanitize_title')) {
            $s = (string) \sanitize_title($s);
        } else {
            $s = \strtolower($s);
            $s = \preg_replace('/[^a-z0-9\-_]+/i', '-', $s) ?? '';
            $s = \trim($s, '-');
        }

        return $s !== '' ? $s : null;
    }

    /**
     * Sanitizes a boolean-like value.
     *
     * @param mixed $value
     * @return bool
     */
    public static function bool(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        if (\is_numeric($value)) {
            return ((int) $value) === 1;
        }

        if (\is_string($value)) {
            $v = \strtolower(\trim($value));
            return \in_array($v, ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    /**
     * Sanitizes a layout value within an allow-list.
     *
     * @param mixed $value
     * @param array<int,string> $allowed
     * @param string $default
     * @return string
     */
    public static function layout(mixed $value, array $allowed, string $default): string
    {
        if (!\is_string($value) || $value === '') {
            return $default;
        }

        $v = \strtolower(\trim($value));
        return \in_array($v, $allowed, true) ? $v : $default;
    }

    /**
     * Sanitizes language code. Returns a 2-letter lower-case code.
     *
     * @param mixed $value
     * @return string
     */
    public static function language(mixed $value): string
    {
        if (\is_string($value) && $value !== '') {
            $v = \strtolower(\trim($value));
            $v = \preg_replace('/[^a-z]/', '', $v) ?? '';
            if (\strlen($v) >= 2) {
                return \substr($v, 0, 2);
            }
        }

        return RuntimeContext::getLanguage();
    }

    /**
     * Sanitizes an associative array of attributes (string keys).
     *
     * @param mixed $attrs
     * @return array<string,mixed>
     */
    public static function attrs(mixed $attrs): array
    {
        if (!\is_array($attrs)) {
            return [];
        }

        $out = [];
        foreach ($attrs as $k => $v) {
            if (!\is_string($k) || $k === '') {
                continue;
            }
            $out[$k] = $v;
        }
        return $out;
    }

    /**
     * Sanitizes HTML class attribute (space-separated tokens) or returns empty string.
     *
     * @param mixed $value
     * @return string
     */
    public static function cssClass(mixed $value): string
    {
        if (!\is_string($value) || $value === '') {
            return '';
        }

        $value  = \trim($value);
        $value  = \preg_replace('/\s+/', ' ', $value) ?? '';
        $tokens = \explode(' ', $value);

        $safe = [];
        foreach ($tokens as $t) {
            $t = \preg_replace('/[^a-zA-Z0-9\-_]/', '', $t) ?? '';
            if ($t !== '') {
                $safe[] = $t;
            }
        }

        return \implode(' ', $safe);
    }

    /* ============================================================
     * BOATS SEARCH NORMALIZATION (canonical contract)
     * ============================================================ */

    /**
     * Build API filters from raw attributes using a canonical contract doc.
     *
     * Rules:
     * - Only attrs declared in $docSearchBoatsAttrs are allowed.
     * - Aliases are normalized (price-min -> min_price, boat_lenght -> boat_length, etc.)
     * - Backward compatibility:
     *   - q      -> term
     *   - people -> boat_capacity
     *   - port   -> boat_base_port (if numeric) otherwise appended to term
     * - Types are casted (int, float, bool, Y-m-d, csv/int[]).
     * - Empty values are removed.
     * - Applies defaults from DOC when default is non-empty.
     *
     * @param array<string,mixed> $rawAtts
     * @param array<int,array{
     *   attr:string,
     *   type:string,
     *   default:string,
     *   description:string,
     *   notes?:string
     * }> $docSearchBoatsAttrs
     *
     * @return array<string,mixed> Filters ready for ExternalApiClient::searchServices()
     */
    public static function normalizeBoatsSearchAtts(array $rawAtts, array $docSearchBoatsAttrs): array
    {
        $atts = self::attrs($rawAtts);

        // Cast scalar values to string where possible (builders often pass ints/bools).
        $str = [];
        foreach ($atts as $k => $v) {
            if ($v === null) {
                continue;
            }
            if (\is_array($v)) {
                $str[(string)$k] = $v;
                continue;
            }
            if (\is_bool($v)) {
                $str[(string)$k] = $v ? 'true' : 'false';
                continue;
            }
            $str[(string)$k] = (string)$v;
        }

        $str = self::mapBoatsAliases($str);

        // ─────────────────────────────────────────
        // Backward compatibility (old shortcode attrs)
        // ─────────────────────────────────────────
        $q = \trim((string)($str['q'] ?? ''));
        if ($q !== '' && empty($str['term'])) {
            $str['term'] = $q;
        }

        $people = \trim((string)($str['people'] ?? ''));
        if ($people !== '' && empty($str['boat_capacity'])) {
            $str['boat_capacity'] = $people;
        }

        // Legacy port: a port id, or a port:/destination: token. The API's text search
        // does not look at places, so a place name is ignored.
        $port = \trim((string)($str['port'] ?? ''));
        if ($port !== '' && empty($str['boat_base_port'])) {
            if (\ctype_digit($port)) {
                $str['boat_base_port'] = $port;
            } elseif (
                \str_contains($port, ':')
                && self::normalizeLocationToken($port) !== null
                && \trim((string)($str['departure_location'] ?? '')) === ''
            ) {
                $str['departure_location'] = (string)self::normalizeLocationToken($port);
            }
        }

        // Build allowed map from DOC
        $docByAttr = [];
        foreach ($docSearchBoatsAttrs as $row) {
            $docByAttr[(string)$row['attr']] = $row;
        }

        $filters = [];

        // Apply defaults from DOC first (only if non-empty)
        foreach ($docByAttr as $attr => $row) {
            $default = (string)($row['default'] ?? '');
            if ($default === '') {
                continue;
            }

            $casted = self::castBoatsSearchValue((string)$row['type'], $default);
            if ($casted === null) {
                continue;
            }

            $filters[$attr] = $casted;
        }

        // Override with user-provided atts (only allowed attrs)
        foreach ($str as $key => $value) {
            $key = (string)$key;
            if (!isset($docByAttr[$key])) {
                continue;
            }

            $raw = \is_array($value) ? $value : \trim((string)$value);
            if ($raw === '' || $raw === []) {
                continue;
            }

            $casted = self::castBoatsSearchValue((string)$docByAttr[$key]['type'], $raw);

            if ($casted === null) {
                continue;
            }
            if (\is_string($casted) && \trim($casted) === '') {
                continue;
            }
            if (\is_array($casted) && $casted === []) {
                continue;
            }

            $filters[$key] = $casted;
        }

        // Enforce boats catalog if missing/empty
        if (empty($filters['id_group'])) {
            $filters['id_group'] = 'boats';
        }

        // If service_name provided and term missing, use service_name
        if (!empty($filters['service_name']) && empty($filters['term'])) {
            $filters['term'] = (string)$filters['service_name'];
        }

        // Only canonical keys reach the API: it prefers service_name over term and
        // price-min/price-max over min_price/max_price, which would hide a visitor's value.
        unset($filters['service_name'], $filters['price-min'], $filters['price-max'], $filters['boat_lenght']);

        // Stable ordering for cache keys
        \ksort($filters);

        return $filters;
    }

    /**
     * Normalize known aliases to canonical attributes.
     *
     * @param array<string,string> $atts
     * @return array<string,string>
     */
    public static function mapBoatsAliases(array $atts): array
    {
        // price aliases
        if (isset($atts['price-min']) && \trim((string)($atts['min_price'] ?? '')) === '') {
            $atts['min_price'] = (string)$atts['price-min'];
        }
        if (isset($atts['price-max']) && \trim((string)($atts['max_price'] ?? '')) === '') {
            $atts['max_price'] = (string)$atts['price-max'];
        }

        // legacy typo
        if (isset($atts['boat_lenght']) && \trim((string)($atts['boat_length'] ?? '')) === '') {
            $atts['boat_length'] = (string)$atts['boat_lenght'];
        }

        // Public plugin/UI name. The Maradigma API expects id_group_content_type
        // for one type and id_group_multiple_content_type for several types.
        if (
            isset($atts['boat_type_id'])
            && \trim((string)$atts['boat_type_id']) !== ''
            && empty($atts['id_group_content_type'])
            && empty($atts['id_group_multiple_content_type'])
        ) {
            $parts = \preg_split('/\s*,\s*/', (string)$atts['boat_type_id']) ?: [];
            $ids = [];

            foreach ($parts as $part) {
                $part = \trim((string)$part);

                if ($part !== '' && \is_numeric($part) && (int)$part > 0) {
                    $ids[] = (int)$part;
                }
            }

            $ids = \array_values(\array_unique($ids));

            if (\count($ids) > 1) {
                $atts['id_group_multiple_content_type'] = $ids;
            } elseif (\count($ids) === 1) {
                $atts['id_group_content_type'] = (string)$ids[0];
            }
        }

        // Public plugin/UI name. The Maradigma API expects a departure_location token.
        if (
            isset($atts['destination'])
            && \trim((string)$atts['destination']) !== ''
            && empty($atts['departure_location'])
        ) {
            $token = self::normalizeLocationToken((string)$atts['destination']);
            if ($token !== null) {
                $atts['departure_location'] = $token;
            }
        }

        // Public plugin/UI name. The Maradigma API expects boat_id_builder.
        if (
            isset($atts['builders'])
            && \trim((string)$atts['builders']) !== ''
            && empty($atts['boat_id_builder'])
        ) {
            $parts = \preg_split('/\s*,\s*/', (string)$atts['builders']) ?: [];
            $ids = [];

            foreach ($parts as $part) {
                $part = \trim((string)$part);

                if ($part !== '' && \is_numeric($part) && (int)$part > 0) {
                    $ids[] = (int)$part;
                }
            }

            $ids = \array_values(\array_unique($ids));

            if (\count($ids) > 1) {
                $atts['boat_id_builder'] = $ids;
            } elseif (\count($ids) === 1) {
                $atts['boat_id_builder'] = (string)$ids[0];
            }
        }

        return $atts;
    }

    /**
     * Normalizes a Maradigma departure-location token.
     *
     * The search API filters boats by `departure_location`, either a geographic
     * destination (`destination:{id}`, which covers every base port inside it) or
     * one base port (`port:{id}`). A bare positive number is a destination ID.
     *
     * @return string|null The canonical token, or null when the value is not one.
     */
    public static function normalizeLocationToken(string $value): ?string
    {
        $value = \strtolower(\trim($value));

        if (\preg_match('/^[1-9]\d*$/', $value) === 1) {
            return 'destination:' . $value;
        }

        if (\preg_match('/^(destination|port)\s*:\s*([1-9]\d*)$/', $value, $matches) === 1) {
            return $matches[1] . ':' . $matches[2];
        }

        return null;
    }

    /**
     * Cast a boats search value to the canonical type.
     *
     * NOTE:
     * For list types (int[], csv) we return an int array so form-encoded
     * requests become field[0]=1&field[1]=2, which PHP receives as $_POST arrays.
     *
     * @param string $type
     * @param mixed $value
     * @return mixed|null
     */
    public static function castBoatsSearchValue(string $type, $value)
    {
        $type  = \strtolower(\trim($type));
        $rawValue = $value;
        $value = \is_array($value) ? '' : \trim((string)$value);

        if ($value === '' && !\is_array($rawValue)) {
            return null;
        }

        // Departure location token: destination:{id} or port:{id}
        if ($type === 'location') {
            return self::normalizeLocationToken($value);
        }

        // Date validation
        if ($type === 'y-m-d') {
            $dt = \DateTime::createFromFormat('Y-m-d', $value);
            if ($dt === false || $dt->format('Y-m-d') !== $value) {
                return null;
            }
            return $value;
        }

        // Bool (accepts 1/0, true/false, on/off, yes/no)
        if ($type === 'bool' || \str_contains($type, 'bool')) {
            $v = \strtolower($value);
            if (\in_array($v, ['1', 'true', 'on', 'yes'], true)) {
                return true;
            }
            if (\in_array($v, ['0', 'false', 'off', 'no'], true)) {
                return false;
            }
            if (\is_numeric($value)) {
                return ((int)$value) > 0;
            }
            return null;
        }

        // Int
        if ($type === 'int') {
            if (!\is_numeric($value)) {
                return null;
            }
            return (int)$value;
        }

        // Float / number
        if ($type === 'float' || $type === 'number') {
            if (!\is_numeric($value)) {
                return null;
            }
            return (float)$value;
        }

        // Integer lists.
        if ($type === 'int[]' || $type === 'csv' || $type === 'int|int[]') {
            $parts = \is_array($rawValue)
                ? $rawValue
                : (\preg_split('/\s*,\s*/', $value) ?: []);
            $ints  = [];

            foreach ($parts as $p) {
                $p = \trim((string)$p);
                if ($p === '' || !\is_numeric($p)) {
                    continue;
                }
                $i = (int)$p;
                if ($i > 0) {
                    $ints[] = $i;
                }
            }

            $ints = \array_values(\array_unique($ints));
            if ($ints === []) {
                return null;
            }

            if ($type === 'int|int[]' && \count($ints) === 1) {
                return $ints[0];
            }

            return $ints;
        }

        // Mixed declarations: try int/bool, then fallback string
        if (\str_contains($type, '|')) {
            if (\is_numeric($value) && \preg_match('/\bint\b/', $type)) {
                return (int)$value;
            }
            if (\preg_match('/\bbool\b/', $type)) {
                $maybeBool = self::castBoatsSearchValue('bool', $value);
                if ($maybeBool !== null) {
                    return $maybeBool;
                }
            }
            return \function_exists('sanitize_text_field')
                ? (string)\sanitize_text_field($value)
                : $value;
        }

        // string (default)
        return \function_exists('sanitize_text_field')
            ? (string)\sanitize_text_field($value)
            : $value;
    }
}

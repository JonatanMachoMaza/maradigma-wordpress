<?php

declare(strict_types=1);

namespace Maradigma\Support;

/**
 * Builds the front-end markup for boat specs.
 */
final class BoatSpecsRenderer
{
    /**
     * Default icons per key (SVG symbols from your sprite).
     * You can extend this map safely.
     *
     * @var array<string,string>
     */
    private static array $defaultIcons = [
        'boat_type'                    => 'svg-motorboat',
        'boat_builder'                 => 'svg-users',
        'boat_model'                   => 'svg-tags',
        'boat_alias'                   => 'svg-tags',
        'boat_capacity'                => 'svg-users',
        'boat_capacity_crew'           => 'svg-users',
        'boat_capacity_pernocta'       => 'svg-cabin',
        'boat_cabins'                  => 'svg-cabin',
        'boat_length'                  => 'svg-ruler',
        'boat_beam'                    => 'svg-ruler',
        'boat_consumption'             => 'svg-engine-power',
        'boat_engines'                 => 'svg-engine-power',
        'boat_base_port_name'          => 'svg-map-marker',
        'boat_year_construction'       => 'svg-clock-rotate-left',
        'boat_year_refit'              => 'svg-clock-rotate-left',
        'boat_security_deposit'        => 'svg-lock',
        'boat_deposit_with_captain'    => 'svg-lock',
        'boat_deposit_without_captain' => 'svg-lock',
    ];

    /**
     * Central field catalog used by:
     * - default renderer labels
     * - Elementor field selector
     * - Elementor default items
     *
     * @return array<string,array{
     *   label:string,
     *   format:string,
     *   prefix?:string,
     *   suffix?:string,
     *   fallback?:string
     * }>
     */
    public static function getAvailableFieldsConfig(): array
    {
        return [
            'boat_type' => [
                'label'  => __('Boat type', 'maradigma'),
                'format' => 'text',
            ],
            'boat_builder' => [
                'label'  => __('Builder', 'maradigma'),
                'format' => 'text',
            ],
            'boat_model' => [
                'label'  => __('Model', 'maradigma'),
                'format' => 'text',
            ],
            'boat_alias' => [
                'label'  => __('Alias', 'maradigma'),
                'format' => 'text',
            ],
            'boat_capacity' => [
                'label'  => __('Capacity', 'maradigma'),
                'format' => 'int',
            ],
            'boat_capacity_crew' => [
                'label'  => __('Crew', 'maradigma'),
                'format' => 'int',
            ],
            'boat_capacity_pernocta' => [
                'label'  => __('Sleeps', 'maradigma'),
                'format' => 'int',
            ],
            'boat_cabins' => [
                'label'  => __('Cabins', 'maradigma'),
                'format' => 'int',
            ],
            'boat_length' => [
                'label'  => __('Length', 'maradigma'),
                'format' => 'meters',
            ],
            'boat_beam' => [
                'label'  => __('Beam', 'maradigma'),
                'format' => 'meters',
            ],
            'boat_consumption' => [
                'label'    => __('Fuel consumption', 'maradigma'),
                'format'   => 'text',
                'suffix'   => ' L/H',
                'fallback' => '',
            ],
            'boat_engines' => [
                'label'  => __('Engines', 'maradigma'),
                'format' => 'text',
            ],
            'boat_base_port_name' => [
                'label'  => __('Base port', 'maradigma'),
                'format' => 'text',
            ],
            'boat_year_construction' => [
                'label'  => __('Year', 'maradigma'),
                'format' => 'text',
            ],
            'boat_year_refit' => [
                'label'  => __('Refit', 'maradigma'),
                'format' => 'text',
            ],
            'boat_security_deposit' => [
                'label'  => __('Deposit', 'maradigma'),
                'format' => 'text',
            ],
            'boat_deposit_with_captain' => [
                'label'  => __('Deposit with skipper', 'maradigma'),
                'format' => 'text',
            ],
            'boat_deposit_without_captain' => [
                'label'  => __('Deposit without skipper', 'maradigma'),
                'format' => 'text',
            ],
        ];
    }

    /**
     * @param array<string,mixed> $boat
     * @param array<int,array<string,mixed>> $items
     * @param array{
     *   layout?:string,
     *   show_labels?:bool,
     *   show_icons?:bool,
     *   icons_map?:array<string,string>
     * } $opts
     */
    public static function render(array $boat, array $items, array $opts = []): string
    {
        $layout     = isset($opts['layout']) && in_array((string)$opts['layout'], ['two_cols', 'list'], true) ? (string)$opts['layout'] : 'two_cols';
        $showLabels = (bool)($opts['show_labels'] ?? true);
        $showIcons  = (bool)($opts['show_icons'] ?? true);

        /** @var array<string,string> $iconsMap */
        $iconsMap = is_array($opts['icons_map'] ?? null) ? (array)$opts['icons_map'] : [];
        $iconsMap = self::normalizeIconsMap($iconsMap);

        $rows = self::buildRows($items, $boat, $showLabels);
        if ($rows === []) {
            return '<div class="maradigma-boat-specs maradigma-boat-specs--empty">' . esc_html__('Specs not available.', 'maradigma') . '</div>';
        }

        ob_start();
        ?>
        <div class="maradigma-boat-specs maradigma-boat-specs--<?php echo esc_attr($layout); ?> <?php echo $showIcons ? 'maradigma-boat-specs--with-icons' : 'maradigma-boat-specs--no-icons'; ?>">
            <dl class="maradigma-boat-specs__dl">
                <?php foreach ($rows as $row): ?>
                    <div class="maradigma-boat-specs__row">
                        <?php if ($showIcons): ?>
                            <?php
                            $iconId = self::resolveIconId((string)$row['key'], $iconsMap);
                            if ($iconId !== ''):
                            ?>
                                <span class="maradigma-boat-specs__icon" aria-hidden="true">
                                    <svg class="maradigma-boat-specs__svg" width="16" height="16" focusable="false" aria-hidden="true">
                                        <use href="#<?php echo esc_attr($iconId); ?>" xlink:href="#<?php echo esc_attr($iconId); ?>"></use>
                                    </svg>
                                </span>
                            <?php else: ?>
                                <span class="maradigma-boat-specs__icon maradigma-boat-specs__icon--empty" aria-hidden="true"></span>
                            <?php endif; ?>
                        <?php endif; ?>

                        <div class="maradigma-boat-specs__content">
                            <?php if ($showLabels && $row['label'] !== ''): ?>
                                <dt class="maradigma-boat-specs__dt"><?php echo esc_html($row['label']); ?></dt>
                            <?php endif; ?>
                            <dd class="maradigma-boat-specs__dd"><?php echo esc_html($row['value']); ?></dd>
                        </div>
                    </div>
                <?php endforeach; ?>
            </dl>
        </div>
        <?php
        return (string)ob_get_clean();
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @param array<string,mixed> $data
     * @return array<int,array{key:string,label:string,value:string}>
     */
    private static function buildRows(array $items, array $data, bool $showLabels): array
    {
        $out = [];
        $config = self::getAvailableFieldsConfig();

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $field    = isset($item['field']) ? trim((string) $item['field']) : '';
            $custom   = isset($item['custom_key']) ? trim((string) $item['custom_key']) : '';
            $label    = isset($item['label']) ? trim((string) $item['label']) : '';
            $format   = isset($item['format']) ? trim((string) $item['format']) : 'text';
            $prefix   = isset($item['prefix']) ? (string) $item['prefix'] : '';
            $suffix   = isset($item['suffix']) ? (string) $item['suffix'] : '';
            $fallback = isset($item['fallback']) ? (string) $item['fallback'] : '';

            $isCustom = ($field === 'custom');
            $key      = $isCustom ? $custom : $field;
            $key      = trim($key);

            if ($key === '') {
                continue;
            }

            /**
             * Label resolution rules:
             * - Standard fields: always use the translated catalog label from getAvailableFieldsConfig().
             *   This prevents legacy Elementor-saved labels (e.g. "Model") from freezing the UI in English.
             * - Custom fields: respect the manually configured label; fallback to key if empty.
             */
            if ($showLabels) {
                if (!$isCustom && isset($config[$key]['label']) && is_string($config[$key]['label'])) {
                    $label = (string) $config[$key]['label'];
                } elseif ($label === '') {
                    $label = self::getDefaultLabelForKey($key);
                }
            } else {
                $label = '';
            }

            if ($key === 'boat_type') {
                $raw = self::extractFirstScalarByKeys($data, [
                    'boat_type',
                    'boat_type_name',
                    'boat_type_slug',
                    'type',
                    'type_name',
                    'boat_category',
                    'category',
                ]);
            } else {
                $raw = self::extractScalarValue($data, $key);
            }

            $val = self::formatValue($raw, $format);

            if ($val === '') {
                $val = trim($fallback);
            }

            if ($val === '') {
                continue;
            }

            if ($key === 'boat_consumption' && trim($suffix) === '') {
                if (!preg_match('/\b(l\/h|lh|lph|l\/hr|liters?\/hour)\b/i', $val)) {
                    $suffix = ' L/H';
                }
            }

            $val = trim($prefix . $val . $suffix);

            $out[] = [
                'key'   => $key,
                'label' => $label,
                'value' => $val,
            ];
        }

        return $out;
    }

    /** @param array<string,mixed> $data */
    private static function extractScalarValue(array $data, string $key): mixed
    {
        if (!array_key_exists($key, $data)) {
            return null;
        }

        $v = $data[$key];
        if (is_array($v) || is_object($v)) {
            return null;
        }

        return $v;
    }

    /**
     * @param array<string,mixed> $data
     * @param array<int,string> $keys
     */
    private static function extractFirstScalarByKeys(array $data, array $keys): mixed
    {
        foreach ($keys as $k) {
            if (!array_key_exists($k, $data)) {
                continue;
            }

            $v = $data[$k];
            if (is_array($v) || is_object($v)) {
                continue;
            }

            if (is_string($v)) {
                $s = trim($v);
                if ($s !== '') {
                    return $s;
                }
                continue;
            }

            if (is_int($v) || is_float($v) || is_bool($v)) {
                return $v;
            }
        }

        return null;
    }

    /**
     * Formats value.
     */
    private static function formatValue(mixed $raw, string $format): string
    {
        if ($raw === null) {
            return '';
        }

        if ($format === 'boolean') {
            $b = null;

            if (is_bool($raw)) {
                $b = $raw;
            } elseif (is_int($raw) || is_float($raw)) {
                $b = ((int)$raw) === 1;
            } elseif (is_string($raw)) {
                $s = strtolower(trim($raw));
                if (in_array($s, ['1', 'true', 'yes', 'y', 'on'], true)) {
                    $b = true;
                } elseif (in_array($s, ['0', 'false', 'no', 'n', 'off'], true)) {
                    $b = false;
                }
            }

            if ($b === null) {
                return '';
            }

            return $b ? __('Yes', 'maradigma') : __('No', 'maradigma');
        }

        $s = is_string($raw) ? trim($raw) : (string)$raw;
        if ($s === '') {
            return '';
        }

        if ($format === 'int') {
            if (preg_match('/-?\d+/', $s, $m)) {
                return (string)((int)$m[0]);
            }
            return '';
        }

        if ($format === 'meters') {
            if (preg_match('/\bm\b/i', $s)) {
                return $s;
            }
            return $s . ' m';
        }

        return $s;
    }

    /**
     * Returns default label for key.
     */
    private static function getDefaultLabelForKey(string $key): string
    {
        $config = self::getAvailableFieldsConfig();
        return isset($config[$key]['label']) ? (string)$config[$key]['label'] : $key;
    }

    /** @param array<string,string> $iconsMap */
    private static function normalizeIconsMap(array $iconsMap): array
    {
        $out = [];

        foreach ($iconsMap as $k => $v) {
            $k = trim((string)$k);
            $v = trim((string)$v);

            if ($k === '' || $v === '') {
                continue;
            }

            if (!str_starts_with($v, 'svg-')) {
                $v = 'svg-' . ltrim($v, '#');
            }

            $out[$k] = $v;
        }

        return $out;
    }

    /** @param array<string,string> $iconsMap */
    private static function resolveIconId(string $key, array $iconsMap): string
    {
        if (isset($iconsMap[$key])) {
            return (string)$iconsMap[$key];
        }

        return self::$defaultIcons[$key] ?? '';
    }
}
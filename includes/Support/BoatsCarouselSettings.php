<?php

declare(strict_types=1);

namespace Maradigma\Support;

/**
 * Settings of the [maradigma_boats_carousel] shortcode, normalized from its attributes.
 *
 * The carousel owns its layout and loading attributes; every other attribute is a
 * boat search attribute and goes to the listing behind the carousel unchanged.
 */
final class BoatsCarouselSettings
{
    /** Cards visible at once on each screen size; decimals show part of the next card. */
    public const DEFAULT_PER_VIEW = [
        'mobile'  => 1.2,
        'tablet'  => 2.5,
        'desktop' => 4.0,
    ];

    public const MIN_PER_VIEW = 1.0;
    public const MAX_PER_VIEW = 8.0;
    public const DEFAULT_GAP = 16;
    public const MAX_GAP = 64;
    public const MAX_BATCH = 24;
    public const MAX_BOATS = 200;

    /** Attributes owned by the carousel; they never reach the boat search. */
    public const ATTRIBUTES = [
        'slides_mobile',
        'slides_tablet',
        'slides_desktop',
        'gap',
        'batch',
        'max_boats',
        'navigation',
        'bleed',
        'label',
        'class',
        'empty_message',
    ];

    /**
     * @param array<string,mixed> $atts Shortcode attributes.
     *
     * @return array{
     *     per_view: array{mobile: float, tablet: float, desktop: float},
     *     gap: int,
     *     batch: int,
     *     max_boats: int,
     *     navigation: bool,
     *     bleed: ''|'edges'|'full',
     *     label: string,
     *     class: string,
     *     empty_message: string
     * }
     */
    public static function fromAtts(array $atts): array
    {
        $perView = [
            'mobile'  => self::perView($atts['slides_mobile'] ?? null, self::DEFAULT_PER_VIEW['mobile']),
            'tablet'  => self::perView($atts['slides_tablet'] ?? null, self::DEFAULT_PER_VIEW['tablet']),
            'desktop' => self::perView($atts['slides_desktop'] ?? null, self::DEFAULT_PER_VIEW['desktop']),
        ];

        $maxBoats = self::intInRange($atts['max_boats'] ?? null, 0, 0, self::MAX_BOATS);

        // Two views of the widest layout per request, so the next view is ready
        // before the visitor reaches it.
        $defaultBatch = (int) \min(self::MAX_BATCH, \max(4, 2 * (int) \ceil($perView['desktop'])));
        $batch = self::intInRange($atts['batch'] ?? null, $defaultBatch, 1, self::MAX_BATCH);

        if ($maxBoats > 0) {
            $batch = \min($batch, $maxBoats);
        }

        return [
            'per_view'      => $perView,
            'gap'           => self::intInRange($atts['gap'] ?? null, self::DEFAULT_GAP, 0, self::MAX_GAP),
            'batch'         => $batch,
            'max_boats'     => $maxBoats,
            'navigation'    => !self::isOff($atts['navigation'] ?? null),
            'bleed'         => self::bleed($atts['bleed'] ?? null),
            'label'         => self::text($atts['label'] ?? null),
            'class'         => self::classList($atts['class'] ?? null),
            'empty_message' => self::text($atts['empty_message'] ?? null),
        ];
    }

    /**
     * Search attributes of the listing behind the carousel.
     *
     * The carousel is a fixed selection: it shows no filters, ignores the md_* URL
     * parameters of the page and always starts at its first boat.
     *
     * @param array<string,mixed> $atts Shortcode attributes.
     *
     * @return array<string,mixed>
     */
    public static function searchAtts(array $atts, int $batch): array
    {
        $search = \array_diff_key($atts, \array_flip(self::ATTRIBUTES));

        $search['show_filters']       = '0';
        $search['allow_url_filters']  = '0';
        $search['autosubmit_filters'] = '0';
        $search['limit_services']     = (string) \max(1, $batch);
        $search['offset_services']    = '0';

        $orderBy = $search['order_by'] ?? '';
        if (!\is_scalar($orderBy) || \trim((string) $orderBy) === '') {
            $search['order_by'] = '0';
        }

        return $search;
    }

    /**
     * CSS custom properties of the layout, for the carousel's style attribute.
     *
     * @param array{per_view: array{mobile: float, tablet: float, desktop: float}, gap: int} $settings
     */
    public static function styleVariables(array $settings): string
    {
        return \sprintf(
            '--md-carousel-per-view-mobile:%s;--md-carousel-per-view-tablet:%s;--md-carousel-per-view-desktop:%s;--md-carousel-gap:%dpx',
            self::formatNumber($settings['per_view']['mobile']),
            self::formatNumber($settings['per_view']['tablet']),
            self::formatNumber($settings['per_view']['desktop']),
            $settings['gap']
        );
    }

    /** @param mixed $value */
    private static function perView($value, float $default): float
    {
        if (!\is_scalar($value)) {
            return $default;
        }

        $value = \str_replace(',', '.', \trim((string) $value));

        if ($value === '' || !\is_numeric($value)) {
            return $default;
        }

        return \max(self::MIN_PER_VIEW, \min(self::MAX_PER_VIEW, \round((float) $value, 2)));
    }

    /** @param mixed $value */
    private static function intInRange($value, int $default, int $min, int $max): int
    {
        if (!\is_scalar($value)) {
            return $default;
        }

        $value = \trim((string) $value);

        if ($value === '' || \preg_match('/^\d+$/', $value) !== 1) {
            return $default;
        }

        return \max($min, \min($max, (int) $value));
    }

    /** @param mixed $value */
    private static function isOff($value): bool
    {
        if (!\is_scalar($value)) {
            return false;
        }

        return \in_array(\strtolower(\trim((string) $value)), ['0', 'false', 'no', 'off'], true);
    }

    /**
     * How far the carousel reaches past its place in the page.
     *
     * "edges" (bleed="1"): the row of cards reaches both edges of the screen while
     * the first card stays aligned with the content. "full" (bleed="full"): the
     * carousel takes the whole width of the screen, as in a full-width section.
     *
     * @param mixed $value
     *
     * @return ''|'edges'|'full'
     */
    private static function bleed($value): string
    {
        if (!\is_scalar($value)) {
            return '';
        }

        $value = \strtolower(\trim((string) $value));

        if ($value === 'full') {
            return 'full';
        }

        return \in_array($value, ['1', 'true', 'yes', 'on'], true) ? 'edges' : '';
    }

    /** @param mixed $value */
    private static function text($value): string
    {
        return \is_scalar($value) ? \trim(\strip_tags((string) $value)) : '';
    }

    /** @param mixed $value */
    private static function classList($value): string
    {
        if (!\is_scalar($value)) {
            return '';
        }

        $classes = [];

        foreach (\preg_split('/\s+/', \trim((string) $value)) ?: [] as $class) {
            $class = (string) \preg_replace('/[^A-Za-z0-9_-]/', '', $class);

            if ($class !== '') {
                $classes[$class] = $class;
            }
        }

        return \implode(' ', $classes);
    }

    private static function formatNumber(float $value): string
    {
        return \rtrim(\rtrim(\number_format($value, 2, '.', ''), '0'), '.');
    }
}

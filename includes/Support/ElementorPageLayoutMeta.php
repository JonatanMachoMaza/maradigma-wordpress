<?php

declare(strict_types=1);

namespace Maradigma\Support;

/**
 * Carries the Elementor page layout alongside a boat's `_elementor_data`.
 *
 * Elementor keeps how it wraps a page (Page Layout such as "Elementor Full
 * Width", plus the other page settings) outside `_elementor_data`: the layout
 * lives in `_wp_page_template` and the rest in `_elementor_page_settings`.
 * Copying only `_elementor_data` therefore reproduces the widgets but renders
 * them inside the theme's default template.
 */
final class ElementorPageLayoutMeta
{
    public const PAGE_TEMPLATE = '_wp_page_template';
    public const PAGE_SETTINGS = '_elementor_page_settings';

    /**
     * Reads the layout meta of `$fromPostId` and writes what it holds onto
     * `$toPostId`.
     *
     * With `$mirror` the target ends up identical to the source: keys the
     * source does not set are removed. Without it they are left alone, so a
     * master that sets no layout never erases one a boat already has.
     */
    public static function copy(int $fromPostId, int $toPostId, bool $mirror = false): void
    {
        if ($fromPostId <= 0 || $toPostId <= 0 || $fromPostId === $toPostId) {
            return;
        }

        $source = [];
        foreach (self::keys() as $key) {
            $source[$key] = get_post_meta($fromPostId, $key, true);
        }

        $inheritable = self::inheritable($source);

        foreach (self::keys() as $key) {
            if (array_key_exists($key, $inheritable)) {
                // update_post_meta() unslashes its input; slash it so backslashes
                // (custom CSS, escaped JSON) survive the round trip.
                update_post_meta($toPostId, $key, wp_slash($inheritable[$key]));
            } elseif ($mirror) {
                delete_post_meta($toPostId, $key);
            }
        }
    }

    /**
     * Picks the layout meta worth passing on from raw `get_post_meta()` values.
     *
     * @param array<string,mixed> $sourceMeta Meta key => stored value.
     * @return array<string,mixed> Meta key => value, only for keys that carry a layout.
     */
    public static function inheritable(array $sourceMeta): array
    {
        $out = [];

        $template = $sourceMeta[self::PAGE_TEMPLATE] ?? '';
        if (is_string($template)) {
            $template = trim($template);
            // "default" is WordPress' own name for "no template".
            if ($template !== '' && $template !== 'default') {
                $out[self::PAGE_TEMPLATE] = $template;
            }
        }

        $settings = $sourceMeta[self::PAGE_SETTINGS] ?? null;
        if (is_array($settings) && $settings !== []) {
            $out[self::PAGE_SETTINGS] = $settings;
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function keys(): array
    {
        return [self::PAGE_TEMPLATE, self::PAGE_SETTINGS];
    }
}

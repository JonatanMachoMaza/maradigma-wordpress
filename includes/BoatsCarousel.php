<?php

declare(strict_types=1);

namespace Maradigma;

use Maradigma\Support\BoatsCarouselSettings;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * [maradigma_boats_carousel]: a horizontal carousel of boat cards that loads its
 * boats in batches while the visitor moves through it.
 *
 * The first batch is rendered on the server with the same search, permalinks and
 * card templates as [maradigma_boats]. The next batches come from the boats archive
 * REST endpoint with the listing's signed scope, so featured boats, a portfolio or
 * any other selection is never loaded in full when the page opens, and the visitor
 * cannot change which boats the carousel shows.
 */
final class BoatsCarousel
{
    /**
     * @param array<string,mixed>|string $atts Shortcode attributes.
     */
    public static function render($atts = []): string
    {
        if (!\function_exists('shortcode_atts')) {
            return '';
        }

        $atts     = \is_array($atts) ? $atts : [];
        $settings = BoatsCarouselSettings::fromAtts($atts);

        // An explicit first page keeps the md_page of a listing on the same page out of the carousel.
        $context = ShortcodeRegistry::buildBoatsArchiveContext(
            BoatsCarouselSettings::searchAtts($atts, $settings['batch']),
            ['md_page' => '1']
        );

        if (empty($context)) {
            return '';
        }

        $cards = ShortcodeRegistry::renderBoatsArchiveCards($context);

        if ($settings['max_boats'] > 0) {
            $cards = \array_slice($cards, 0, $settings['max_boats']);
        }

        if ($cards === []) {
            return $settings['empty_message'] === ''
                ? ''
                : '<p class="maradigma-boats-carousel__empty">' . \esc_html($settings['empty_message']) . '</p>';
        }

        AssetsManager::enqueueBoatsCarouselAssets();

        $attsUsed = (array) ($context['atts'] ?? []);
        $filters  = (array) ($context['filters'] ?? []);
        $label    = $settings['label'] !== '' ? $settings['label'] : \__('Boats', 'maradigma');
        // maradigma-boats keeps the rules that themes and the plugin already apply around boat cards.
        $classes  = \trim(
            'maradigma-boats maradigma-boats-carousel '
            . ($settings['bleed'] ? 'maradigma-boats-carousel--bleed ' : '')
            . $settings['class']
        );

        $data = [
            'data-md-boats-carousel'         => '1',
            'data-md-carousel-url'           => (string) \rest_url('maradigma/v1/boats-archive'),
            'data-md-carousel-scope'         => (string) ($context['scope_token'] ?? ''),
            'data-md-carousel-id-group'      => (string) ($attsUsed['id_group'] ?? 'boats'),
            'data-md-carousel-limit'         => (string) $settings['batch'],
            'data-md-carousel-order-by'      => (string) ($filters['order_by'] ?? $attsUsed['order_by'] ?? '0'),
            'data-md-carousel-card'          => (string) ($attsUsed['card'] ?? ''),
            'data-md-carousel-image-token'   => (string) ($attsUsed['image_token'] ?? ''),
            'data-md-carousel-lang'          => (string) ($context['current_lang'] ?? ''),
            'data-md-carousel-page'          => '1',
            'data-md-carousel-total-pages'   => (string) \max(1, (int) ($context['total_pages'] ?? 1)),
            'data-md-carousel-max'           => (string) $settings['max_boats'],
        ];

        $attributes = '';
        foreach ($data as $name => $value) {
            $attributes .= ' ' . $name . '="' . \esc_attr($value) . '"';
        }

        $slides = '';
        foreach ($cards as $cardHtml) {
            $slides .= '<li class="maradigma-boats-carousel__slide">' . $cardHtml . '</li>';
        }

        \ob_start();
        ?>
        <section
            class="<?php echo \esc_attr($classes); ?>"
            aria-roledescription="carousel"
            aria-label="<?php echo \esc_attr($label); ?>"
            style="<?php echo \esc_attr(BoatsCarouselSettings::styleVariables($settings)); ?>"<?php
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every attribute value was escaped with esc_attr() above.
            echo $attributes;
            ?>>
            <div class="maradigma-boats-carousel__viewport">
                <ul class="maradigma-boats-carousel__track" role="list" data-md-carousel-track>
                    <?php
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Slides wrap cards sanitized in renderBoatsArchiveCards().
                    echo $slides;
                    ?>
                </ul>
            </div>
            <?php if ($settings['navigation']) : ?>
                <button type="button" class="maradigma-boats-carousel__button maradigma-boats-carousel__button--prev" data-md-carousel-prev aria-label="<?php echo \esc_attr__('Previous', 'maradigma'); ?>" disabled>
                    <svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><path d="M15 5l-7 7 7 7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
                <button type="button" class="maradigma-boats-carousel__button maradigma-boats-carousel__button--next" data-md-carousel-next aria-label="<?php echo \esc_attr__('Next', 'maradigma'); ?>">
                    <svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><path d="M9 5l7 7-7 7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
            <?php endif; ?>
        </section>
        <?php

        return (string) \ob_get_clean();
    }
}

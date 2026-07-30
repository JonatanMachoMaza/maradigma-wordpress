<?php
if (!defined('ABSPATH')) exit;

get_header();

if (have_posts()) :
    while (have_posts()) : the_post();

        $maradigma_template_id = (int) get_option('maradigma_elementor_master_page_id');

        if (
            $maradigma_template_id &&
            did_action('elementor/loaded')
        ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Elementor returns the fully rendered builder document.
            echo \Elementor\Plugin::instance()
                ->frontend
                ->get_builder_content_for_display($maradigma_template_id, true);
        } else {
            echo '<p>Plantilla Elementor no disponible.</p>';
        }

    endwhile;
endif;

get_footer();

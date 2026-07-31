<?php

declare(strict_types=1);

namespace Maradigma\Integrations\GeneratePress;

if (!defined('ABSPATH')) {
    exit;
}

use Maradigma\Integrations\Gutenberg\GutenbergIntegration;

/**
 * Integrates Maradigma with GeneratePress.
 */
final class GeneratePressIntegration
{
    private const META_TEMPLATE_SIDEBAR_LAYOUT = '_maradigma_generatepress_sidebar_layout';
    private const META_TEMPLATE_CONTENT_LAYOUT = '_maradigma_generatepress_content_layout';
    private const META_TEMPLATE_FOOTER_WIDGETS = '_maradigma_generatepress_footer_widgets';
    private const META_TEMPLATE_DISABLE_CONTENT_TITLE = '_maradigma_generatepress_disable_content_title';

    private const GP_META_SIDEBAR_LAYOUT = '_generate-sidebar-layout-meta';
    private const GP_META_CONTENT_LAYOUT = '_generate-content-container';
    private const GP_META_FOOTER_WIDGETS = '_generate-footer-widget-meta';
    private const GP_META_DISABLE_HEADLINE = '_generate-disable-headline';

    private const LEGACY_GP_META_SIDEBAR_LAYOUT = '_generate-sidebar-layout';
    private const LEGACY_GP_META_CONTENT_LAYOUT = '_generate-content-layout';

    private const NONCE_ACTION = 'maradigma_generatepress_template_layout';
    private const NONCE_NAME = 'maradigma_generatepress_template_layout_nonce';

    private static bool $registered = false;

    /**
     * Registers the component with WordPress.
     */
    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        self::$registered = true;

        \add_action('add_meta_boxes_' . self::getGutenbergTemplatePostType(), [self::class, 'addTemplateLayoutMetaBox']);
        \add_action('save_post_' . self::getGutenbergTemplatePostType(), [self::class, 'saveTemplateLayoutMetaBox'], 10, 2);
        \add_action('maradigma_gutenberg_template_applied_to_boat', [self::class, 'onGutenbergTemplateAppliedToBoat'], 10, 2);
    }

    /**
     * Registers the component's WordPress hooks.
     */
    public static function init(): void
    {
        self::register();
    }

    /**
     * Adds template layout meta box.
     */
    public static function addTemplateLayoutMetaBox(): void
    {
        \add_meta_box(
            'maradigma-generatepress-layout',
            \__('Diseño', 'maradigma'),
            [self::class, 'renderTemplateLayoutMetaBox'],
            self::getGutenbergTemplatePostType(),
            'side',
            'default'
        );
    }

    /**
     * Renders template layout meta box.
     */
    public static function renderTemplateLayoutMetaBox(\WP_Post $post): void
    {
        \wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);

        $sidebarLayout = self::getTemplateSidebarLayout($post->ID);
        $contentLayout = self::getTemplateContentLayout($post->ID);
        $footerWidgets = self::getTemplateFooterWidgets($post->ID);
        $disableContentTitle = self::getTemplateDisableContentTitle($post->ID);

        $sidebarOptions = [
            'no-sidebar'    => \__('Sin barras laterales', 'maradigma'),
            'default'       => \__('Por defecto', 'maradigma'),
            'right-sidebar' => \__('Barra lateral derecha', 'maradigma'),
            'left-sidebar'  => \__('Barra lateral izquierda', 'maradigma'),
            'both-sidebars' => \__('Ambas barras laterales', 'maradigma'),
            'both-left'     => \__('Ambas barras a la izquierda', 'maradigma'),
            'both-right'    => \__('Ambas barras a la derecha', 'maradigma'),
        ];

        $contentOptions = [
            'default'    => \__('Por defecto', 'maradigma'),
            'contained'  => \__('Contenido contenido', 'maradigma'),
            'full-width' => \__('Ancho completo', 'maradigma'),
        ];

        $footerOptions = [
            'default' => \__('Por defecto', 'maradigma'),
            '0'       => '0',
            '1'       => '1',
            '2'       => '2',
            '3'       => '3',
            '4'       => '4',
            '5'       => '5',
        ];
        ?>
        <p>
            <label for="maradigma-generatepress-sidebar-layout">
                <strong><?php echo \esc_html__('Diseño de la barra lateral', 'maradigma'); ?></strong>
            </label>
            <select id="maradigma-generatepress-sidebar-layout" name="maradigma_generatepress_sidebar_layout" style="width:100%;max-width:100%;box-sizing:border-box;">
                <?php foreach ($sidebarOptions as $value => $label) : ?>
                    <option value="<?php echo \esc_attr($value); ?>" <?php \selected($sidebarLayout, $value); ?>>
                        <?php echo \esc_html($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>

        <p>
            <label for="maradigma-generatepress-content-layout">
                <strong><?php echo \esc_html__('Contenedor del contenido', 'maradigma'); ?></strong>
            </label>
            <select id="maradigma-generatepress-content-layout" name="maradigma_generatepress_content_layout" style="width:100%;max-width:100%;box-sizing:border-box;">
                <?php foreach ($contentOptions as $value => $label) : ?>
                    <option value="<?php echo \esc_attr($value); ?>" <?php \selected($contentLayout, $value); ?>>
                        <?php echo \esc_html($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>

        <p>
            <label for="maradigma-generatepress-footer-widgets">
                <strong><?php echo \esc_html__('Widgets del pie de página', 'maradigma'); ?></strong>
            </label>
            <select id="maradigma-generatepress-footer-widgets" name="maradigma_generatepress_footer_widgets" style="width:100%;max-width:100%;box-sizing:border-box;">
                <?php foreach ($footerOptions as $value => $label) : ?>
                    <option value="<?php echo \esc_attr($value); ?>" <?php \selected($footerWidgets, $value); ?>>
                        <?php echo \esc_html($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>

        <p>
            <strong><?php echo \esc_html__('Desactivar elementos', 'maradigma'); ?></strong>
            <br>
            <label>
                <input type="checkbox" name="maradigma_generatepress_disable_content_title" value="1" <?php \checked($disableContentTitle); ?>>
                <?php echo \esc_html__('Título del contenido', 'maradigma'); ?>
            </label>
        </p>

        <?php if (!self::isGeneratePressActive()) : ?>
            <p class="description">
                <?php echo \esc_html__('GeneratePress no es el tema activo. Estos ajustes se guardan y se aplican cuando el sitio usa GeneratePress.', 'maradigma'); ?>
            </p>
        <?php else : ?>
            <p class="description">
                <?php echo \esc_html__('Estos ajustes se copian a las paginas de barcos gestionadas al aplicar la plantilla Gutenberg.', 'maradigma'); ?>
            </p>
        <?php endif; ?>
        <?php
    }

    /**
     * Persists template layout meta box.
     */
    public static function saveTemplateLayoutMetaBox(int $postId, \WP_Post $post): void
    {
        if ($post->post_type !== self::getGutenbergTemplatePostType()) {
            return;
        }

        if (\defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        $nonce = isset($_POST[self::NONCE_NAME])
            ? \sanitize_text_field((string) \wp_unslash($_POST[self::NONCE_NAME]))
            : '';

        if ($nonce === '' || !\wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            return;
        }

        if (!\current_user_can('edit_post', $postId)) {
            return;
        }

        $sidebarLayout = isset($_POST['maradigma_generatepress_sidebar_layout'])
            ? \sanitize_key((string) \wp_unslash($_POST['maradigma_generatepress_sidebar_layout']))
            : 'no-sidebar';

        $contentLayout = isset($_POST['maradigma_generatepress_content_layout'])
            ? \sanitize_key((string) \wp_unslash($_POST['maradigma_generatepress_content_layout']))
            : 'default';

        $footerWidgets = isset($_POST['maradigma_generatepress_footer_widgets'])
            ? \sanitize_key((string) \wp_unslash($_POST['maradigma_generatepress_footer_widgets']))
            : 'default';

        $disableContentTitle = !empty($_POST['maradigma_generatepress_disable_content_title']);

        \update_post_meta($postId, self::META_TEMPLATE_SIDEBAR_LAYOUT, self::normalizeSidebarLayout($sidebarLayout));
        \update_post_meta($postId, self::META_TEMPLATE_CONTENT_LAYOUT, self::normalizeContentLayout($contentLayout));
        \update_post_meta($postId, self::META_TEMPLATE_FOOTER_WIDGETS, self::normalizeFooterWidgets($footerWidgets));
        \update_post_meta($postId, self::META_TEMPLATE_DISABLE_CONTENT_TITLE, $disableContentTitle ? '1' : '0');
    }

    /**
     * Responds when Gutenberg template applied to boat.
     */
    public static function onGutenbergTemplateAppliedToBoat(int $boatPostId, int $templatePostId): void
    {
        self::applyTemplateLayoutToBoatPost($boatPostId, $templatePostId);
    }

    /**
     * Applies template layout to boat post.
     */
    public static function applyTemplateLayoutToBoatPost(int $boatPostId, int $templatePostId): void
    {
        if ($boatPostId <= 0 || $templatePostId <= 0) {
            return;
        }

        $sidebarLayout = self::getTemplateSidebarLayout($templatePostId);
        if ($sidebarLayout === 'default') {
            \delete_post_meta($boatPostId, self::GP_META_SIDEBAR_LAYOUT);
            \delete_post_meta($boatPostId, self::LEGACY_GP_META_SIDEBAR_LAYOUT);
        } else {
            \update_post_meta($boatPostId, self::GP_META_SIDEBAR_LAYOUT, $sidebarLayout);
            \update_post_meta($boatPostId, self::LEGACY_GP_META_SIDEBAR_LAYOUT, $sidebarLayout);
        }

        $contentLayout = self::getTemplateContentLayout($templatePostId);
        if ($contentLayout === 'default') {
            \delete_post_meta($boatPostId, self::GP_META_CONTENT_LAYOUT);
            \delete_post_meta($boatPostId, self::LEGACY_GP_META_CONTENT_LAYOUT);
        } else {
            \update_post_meta($boatPostId, self::GP_META_CONTENT_LAYOUT, $contentLayout);
            \update_post_meta($boatPostId, self::LEGACY_GP_META_CONTENT_LAYOUT, $contentLayout);
        }

        $footerWidgets = self::getTemplateFooterWidgets($templatePostId);
        if ($footerWidgets === 'default') {
            \delete_post_meta($boatPostId, self::GP_META_FOOTER_WIDGETS);
        } else {
            \update_post_meta($boatPostId, self::GP_META_FOOTER_WIDGETS, $footerWidgets);
        }

        if (self::getTemplateDisableContentTitle($templatePostId)) {
            \update_post_meta($boatPostId, self::GP_META_DISABLE_HEADLINE, 'true');
        } else {
            \delete_post_meta($boatPostId, self::GP_META_DISABLE_HEADLINE);
        }
    }

    /**
     * Determines whether GeneratePress active.
     */
    public static function isGeneratePressActive(): bool
    {
        $theme = \wp_get_theme();
        $template = \strtolower((string) $theme->get_template());
        $stylesheet = \strtolower((string) $theme->get_stylesheet());

        return $template === 'generatepress' || $stylesheet === 'generatepress';
    }

    /**
     * Returns template sidebar layout.
     */
    private static function getTemplateSidebarLayout(int $templatePostId): string
    {
        $value = (string) \get_post_meta($templatePostId, self::META_TEMPLATE_SIDEBAR_LAYOUT, true);

        return self::normalizeSidebarLayout($value !== '' ? $value : 'no-sidebar');
    }

    /**
     * Returns template content layout.
     */
    private static function getTemplateContentLayout(int $templatePostId): string
    {
        $value = (string) \get_post_meta($templatePostId, self::META_TEMPLATE_CONTENT_LAYOUT, true);

        return self::normalizeContentLayout($value !== '' ? $value : 'default');
    }

    /**
     * Returns template footer widgets.
     */
    private static function getTemplateFooterWidgets(int $templatePostId): string
    {
        $value = (string) \get_post_meta($templatePostId, self::META_TEMPLATE_FOOTER_WIDGETS, true);

        return self::normalizeFooterWidgets($value !== '' ? $value : 'default');
    }

    /**
     * Returns template disable content title.
     */
    private static function getTemplateDisableContentTitle(int $templatePostId): bool
    {
        return (string) \get_post_meta($templatePostId, self::META_TEMPLATE_DISABLE_CONTENT_TITLE, true) === '1';
    }

    /**
     * Normalizes sidebar layout.
     */
    private static function normalizeSidebarLayout(string $value): string
    {
        $value = \sanitize_key($value);

        return \in_array($value, ['default', 'no-sidebar', 'right-sidebar', 'left-sidebar', 'both-sidebars', 'both-left', 'both-right'], true)
            ? $value
            : 'no-sidebar';
    }

    /**
     * Normalizes content layout.
     */
    private static function normalizeContentLayout(string $value): string
    {
        $value = \sanitize_key($value);

        return \in_array($value, ['default', 'contained', 'full-width'], true)
            ? $value
            : 'default';
    }

    /**
     * Normalizes footer widgets.
     */
    private static function normalizeFooterWidgets(string $value): string
    {
        $value = \sanitize_key($value);

        return \in_array($value, ['default', '0', '1', '2', '3', '4', '5'], true)
            ? $value
            : 'default';
    }

    /**
     * Returns Gutenberg template post type.
     */
    private static function getGutenbergTemplatePostType(): string
    {
        return \class_exists(GutenbergIntegration::class)
            ? GutenbergIntegration::TEMPLATE_POST_TYPE
            : 'maradigma_gb_tpl';
    }
}

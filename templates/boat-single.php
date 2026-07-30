<?php
/**
 * Maradigma single boat template (used by shortcodes/integrations).
 *
 * Template variables are provided by ShortcodeRegistry::renderSingleBoat().
 */

if (!defined('ABSPATH')) {
    exit;
}

/** @var array<string, mixed> $boat Boat payload. */
/** @var array<string, mixed> $settings Plugin settings. */
/** @var string $language Current language code. */
if (!isset($boat) || !is_array($boat)) {
    echo '<p>' . esc_html__('Boat not found.', 'maradigma') . '</p>';
    return;
}

$maradigma_name        = (string) ($boat['name'] ?? $boat['service_name'] ?? '');
$maradigma_description = (string) ($boat['description_html'] ?? $boat['description'] ?? '');
$maradigma_images      = is_array($boat['images'] ?? null) ? $boat['images'] : [];

?>
<article class="maradigma-boat-single">
    <h1><?php echo esc_html($maradigma_name); ?></h1>

    <?php if (!empty($maradigma_images)) : ?>
        <div class="maradigma-boat-gallery">
            <?php foreach ($maradigma_images as $maradigma_image) : ?>
                <?php
                $maradigma_image_url = is_array($maradigma_image) && isset($maradigma_image['url'])
                    ? (string) $maradigma_image['url']
                    : '';
                if ($maradigma_image_url === '') {
                    continue;
                }
                ?>
                <img src="<?php echo esc_url($maradigma_image_url); ?>" alt="<?php echo esc_attr($maradigma_name); ?>" />
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($maradigma_description !== '') : ?>
        <div class="maradigma-boat-description">
            <?php echo wp_kses_post($maradigma_description); ?>
        </div>
    <?php endif; ?>
</article>

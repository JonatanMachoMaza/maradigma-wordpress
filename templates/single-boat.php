<?php
/**
 * Maradigma single boat template (used by shortcodes/integrations).
 *
 * Expected variables (provided by ShortcodeRegistry::renderSingleBoat()):
 * - $boat (array) Boat payload
 * - $settings (array) Plugin settings
 * - $language (string)
 */
if (!defined('ABSPATH')) {
    exit;
}

/*
if (!defined('ABSPATH')) {
    exit;
}

if (!isset($boat) || !is_array($boat)) {
    echo '<p>' . esc_html__('Boat not found.', 'maradigma') . '</p>';
    return;
}

$name        = (string) ($boat['name'] ?? $boat['service_name'] ?? '');
$description = (string) ($boat['description_html'] ?? $boat['description'] ?? '');
$images      = is_array($boat['images'] ?? null) ? $boat['images'] : [];

?>
<article class="maradigma-boat-single">
    <h1><?php echo esc_html($name); ?></h1>

    <?php if (!empty($images)) : ?>
        <div class="maradigma-boat-gallery">
            <?php foreach ($images as $img) : ?>
                <?php
                $url = is_array($img) && isset($img['url']) ? (string) $img['url'] : '';
                if (!$url) { continue; }
                ?>
                <img src="<?php echo esc_url($url); ?>" alt="<?php echo esc_attr($name); ?>" />
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($description !== '') : ?>
        <div class="maradigma-boat-description">
            <?php echo wp_kses_post($description); ?>
        </div>
    <?php endif; ?>
</article>
<?php 
*/
?>

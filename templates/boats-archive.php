<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** @var array<string,mixed> $vm */
$maradigma_items_html = (string) ($vm['itemsHtml'] ?? '');
?>

<div class="maradigma-boats maradigma-boats-archive" data-maradigma="boats">
  <?php echo wp_kses_post($maradigma_items_html); ?>
</div>

<?php

declare(strict_types=1);

namespace Maradigma\Integrations\GenerateBlocks;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Integrates Maradigma with GenerateBlocks.
 */
final class GenerateBlocksIntegration
{
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
     * Responds when Gutenberg template applied to boat.
     */
    public static function onGutenbergTemplateAppliedToBoat(int $boatPostId, int $templatePostId): void
    {
        if ($boatPostId <= 0) {
            return;
        }

        $post = \get_post($boatPostId);
        $content = $post instanceof \WP_Post ? (string) $post->post_content : '';

        if (\strpos($content, 'wp:generateblocks') !== false) {
            \update_post_meta($boatPostId, '_generateblocks_dynamic_css_version', self::getGenerateBlocksVersion());
            self::syncReusableBlockReferences($boatPostId, $content);
        } else {
            \delete_post_meta($boatPostId, '_generateblocks_dynamic_css_version');
            \delete_post_meta($boatPostId, '_generateblocks_reusable_blocks');
        }

        $generatedCssPosts = \get_option('generateblocks_dynamic_css_posts', []);
        if (\is_array($generatedCssPosts) && isset($generatedCssPosts[$boatPostId])) {
            unset($generatedCssPosts[$boatPostId]);
            \update_option('generateblocks_dynamic_css_posts', $generatedCssPosts);
        }

        \clean_post_cache($boatPostId);
    }

    /**
     * Synchronizes reusable block references.
     */
    private static function syncReusableBlockReferences(int $boatPostId, string $content): void
    {
        $matches = [];
        \preg_match_all('/wp:block {"ref":([^}]*)}/', $content, $matches);

        $refs = [];
        foreach (($matches[1] ?? []) as $match) {
            $refs[] = (int) $match;
        }

        $refs = \array_values(\array_unique(\array_filter($refs)));

        if ($refs === []) {
            \delete_post_meta($boatPostId, '_generateblocks_reusable_blocks');
            return;
        }

        \update_post_meta($boatPostId, '_generateblocks_reusable_blocks', $refs);
    }

    /**
     * Returns GenerateBlocks version.
     */
    private static function getGenerateBlocksVersion(): string
    {
        if (\defined('GENERATEBLOCKS_VERSION')) {
            return (string) GENERATEBLOCKS_VERSION;
        }

        return '1';
    }
}

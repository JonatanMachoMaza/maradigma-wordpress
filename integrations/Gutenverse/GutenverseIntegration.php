<?php

declare(strict_types=1);

namespace Maradigma\Integrations\Gutenverse;

if (!defined('ABSPATH')) {
    exit;
}

final class GutenverseIntegration
{
    private const CACHE_OPTION = 'gutenverse-style-cache-id';

    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        self::$registered = true;

        \add_action('maradigma_gutenberg_template_applied_to_boat', [self::class, 'onGutenbergTemplateAppliedToBoat'], 10, 2);
        \add_filter('gutenverse_conditional_script_handles', [self::class, 'normalizeConditionalHandles'], PHP_INT_MAX);
        \add_filter('gutenverse_conditional_style_handles', [self::class, 'normalizeConditionalHandles'], PHP_INT_MAX);
    }

    public static function init(): void
    {
        self::register();
    }

    /**
     * Gutenverse may pass null to its conditional handle filters on some pages.
     * Normalize it before Gutenverse calls array_unique().
     *
     * @param mixed $handles
     * @return list<string>
     */
    public static function normalizeConditionalHandles($handles): array
    {
        if (!is_array($handles)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($handle): string => is_scalar($handle) ? trim((string) $handle) : '',
            $handles
        )));
    }
    public static function onGutenbergTemplateAppliedToBoat(int $boatPostId, int $templatePostId): void
    {
        if ($boatPostId <= 0 || !self::isGutenverseActive()) {
            return;
        }

        $post = \get_post($boatPostId);
        $content = $post instanceof \WP_Post ? (string) $post->post_content : '';

        if (!self::contentHasGutenverseBlocks($content)) {
            self::deleteGeneratedFilesForPost($boatPostId);
            return;
        }

        self::rollStyleCacheId();
        self::deleteGeneratedFilesForPost($boatPostId);
        \clean_post_cache($boatPostId);
    }

    public static function isGutenverseActive(): bool
    {
        return \defined('GUTENVERSE')
            || \class_exists('\Gutenverse\Gutenverse')
            || \class_exists('\Gutenverse\Framework\Init');
    }

    private static function contentHasGutenverseBlocks(string $content): bool
    {
        return \strpos($content, 'wp:gutenverse/') !== false
            || \strpos($content, '"gutenverse/') !== false
            || \strpos($content, 'wp-block-gutenverse-') !== false;
    }

    private static function rollStyleCacheId(): void
    {
        $frontendCache = self::getFrontendCacheInstance();
        if (\is_object($frontendCache) && \method_exists($frontendCache, 'generate_style_cache_id')) {
            $frontendCache->generate_style_cache_id();
            return;
        }

        \update_option(self::CACHE_OPTION, \wp_rand(111111, 999999), true);
    }

    private static function getFrontendCacheInstance()
    {
        if (!\class_exists('\Gutenverse\Framework\Init') || !\method_exists('\Gutenverse\Framework\Init', 'instance')) {
            return null;
        }

        $init = \Gutenverse\Framework\Init::instance();

        return \is_object($init) && isset($init->frontend_cache)
            ? $init->frontend_cache
            : null;
    }

    private static function deleteGeneratedFilesForPost(int $postId): void
    {
        $prefix = 'gutenverse-content-' . $postId . '-';
        self::deleteGeneratedFilesWithPrefix(self::getCssPath(), $prefix);
        self::deleteGeneratedFilesWithPrefix(self::getConditionalPath(), $prefix);
    }

    private static function deleteGeneratedFilesWithPrefix(string $directory, string $prefix): void
    {
        $directory = \wp_normalize_path($directory);
        if ($directory === '' || !\is_dir($directory)) {
            return;
        }

        $base = \wp_normalize_path(\trailingslashit($directory));
        $files = \glob($base . $prefix . '*');
        if (!\is_array($files)) {
            return;
        }

        foreach ($files as $file) {
            $file = \wp_normalize_path((string) $file);
            if ($file === '' || \strpos($file, $base) !== 0 || !\is_file($file)) {
                continue;
            }

            \wp_delete_file($file);
        }
    }

    private static function getCssPath(): string
    {
        if (\function_exists('gutenverse_css_path')) {
            return (string) \gutenverse_css_path();
        }

        $uploadDir = \wp_upload_dir();

        return \trailingslashit((string) ($uploadDir['basedir'] ?? '')) . 'gutenverse/css';
    }

    private static function getConditionalPath(): string
    {
        if (\function_exists('gutenverse_conditional_path')) {
            return (string) \gutenverse_conditional_path();
        }

        $uploadDir = \wp_upload_dir();

        return \trailingslashit((string) ($uploadDir['basedir'] ?? '')) . 'gutenverse/conditional';
    }
}

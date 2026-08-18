<?php

declare(strict_types=1);

namespace Maradigma;

use Maradigma\Support\RuntimeContext;

/**
 * Resolves destination-aware public URLs for synchronized boats.
 *
 * The configured boat base may contain the {{destination}} placeholder, for
 * example "alquiler-barcos-{{destination}}". The destination is supplied by
 * the external API through the service_destination expansion.
 */
final class BoatUrlResolver
{
    public const DESTINATION_PLACEHOLDER = '{{destination}}';

    private const DESTINATION_PLACEHOLDER_ALIAS = '{{destination_slug}}';
    private const DESTINATION_MARKER = 'maradigmadestinationplaceholder';

    /**
     * Resolves a configured base-path template for a boat payload.
     *
     * @param array<string,mixed> $boat Boat API payload.
     */
    public static function resolveBasePath(string $template, array $boat = [], string $fallback = 'boats'): string
    {
        $template = self::normalizeBaseTemplate($template, $fallback);
        $destinationSlug = self::getDestinationSlug($boat);

        $resolved = str_replace(self::DESTINATION_PLACEHOLDER, $destinationSlug, $template);
        $resolved = self::normalizeResolvedPath($resolved);

        if ($resolved !== '') {
            return $resolved;
        }

        return self::normalizeResolvedPath($fallback) ?: 'boats';
    }

    /**
     * Resolves a base path from a synchronized boat post payload.
     */
    public static function resolveBasePathForPost(int $postId, string $language, string $fallback = 'boats'): string
    {
        $template = RuntimeContext::getBoatsBaseSlugForLang($language);

        return self::resolveBasePath($template, self::getBoatPayloadForPost($postId), $fallback);
    }

    /**
     * Builds an absolute public URL for a boat payload.
     *
     * @param array<string,mixed> $boat Boat API payload.
     */
    public static function buildBoatUrl(array $boat, string $language, string $identifier = ''): string
    {
        if (!RuntimeContext::isBoatPagesSyncEnabled()) {
            return '';
        }

        $language = self::normalizeLanguage($language);
        $basePath = self::resolveBasePath(RuntimeContext::getBoatsBaseSlugForLang($language), $boat);
        $baseUrl = rtrim(RuntimeContext::getHomeUrlForLanguage($language), '/') . '/' . trim($basePath, '/') . '/';

        if ($identifier === '') {
            $identifier = (string) ($boat['slug'] ?? $boat['service_slug'] ?? $boat['id'] ?? '');
        }

        $identifier = self::slugify($identifier);
        if ($identifier === '') {
            return $baseUrl;
        }

        return $baseUrl . $identifier . '/';
    }

    /**
     * Returns the URL-path regex and boat-slug capture index for a base template.
     *
     * @return array{regex:string,boat_match_index:int}
     */
    public static function buildRewritePattern(string $template, string $fallback = 'boats'): array
    {
        $template = self::normalizeBaseTemplate($template, $fallback);
        $placeholderCount = substr_count($template, self::DESTINATION_PLACEHOLDER);
        $quoted = preg_quote($template, '#');
        $placeholder = preg_quote(self::DESTINATION_PLACEHOLDER, '#');
        $baseRegex = str_replace($placeholder, '([^/]+)', $quoted);

        return [
            'regex' => $baseRegex,
            'boat_match_index' => $placeholderCount + 1,
        ];
    }

    /**
     * Returns whether a configured base uses a destination placeholder.
     */
    public static function hasDestinationPlaceholder(string $template): bool
    {
        return str_contains(self::canonicalizePlaceholders($template), self::DESTINATION_PLACEHOLDER);
    }

    /**
     * Returns the localized destination name from a boat payload.
     *
     * @param array<string,mixed> $boat Boat API payload.
     */
    public static function getDestinationName(array $boat): string
    {
        $destination = $boat['destination'] ?? null;
        if (is_array($destination)) {
            foreach (['text', 'name', 'slug'] as $key) {
                $value = trim((string) ($destination[$key] ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }
        }

        foreach (['boat_destination_name', 'destination_name'] as $key) {
            $value = trim((string) ($boat[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * Returns the URL-safe destination slug from a boat payload.
     *
     * @param array<string,mixed> $boat Boat API payload.
     */
    public static function getDestinationSlug(array $boat): string
    {
        $destination = $boat['destination'] ?? null;
        if (is_array($destination)) {
            $explicitSlug = trim((string) ($destination['slug'] ?? ''));
            if ($explicitSlug !== '') {
                return self::slugify($explicitSlug);
            }
        }

        foreach (['boat_destination_slug', 'destination_slug'] as $key) {
            $value = trim((string) ($boat[$key] ?? ''));
            if ($value !== '') {
                return self::slugify($value);
            }
        }

        return self::slugify(self::getDestinationName($boat));
    }

    /**
     * Normalizes a route template while preserving the destination placeholder.
     */
    public static function normalizeBaseTemplate(string $template, string $fallback = 'boats'): string
    {
        $template = self::canonicalizePlaceholders(trim($template));
        $template = preg_replace('~https?://[^/]+~i', '', $template) ?: $template;
        $template = trim($template, '/');

        if ($template === '') {
            $template = $fallback;
        }

        $template = str_replace(self::DESTINATION_PLACEHOLDER, self::DESTINATION_MARKER, $template);
        $segments = [];

        foreach (explode('/', $template) as $segment) {
            $segment = self::slugify($segment);
            if ($segment !== '') {
                $segments[] = str_replace(self::DESTINATION_MARKER, self::DESTINATION_PLACEHOLDER, $segment);
            }
        }

        $normalized = implode('/', $segments);
        if ($normalized !== '') {
            return $normalized;
        }

        return self::normalizeResolvedPath($fallback) ?: 'boats';
    }

    /**
     * Loads the API payload stored on a synchronized boat post.
     *
     * @return array<string,mixed>
     */
    private static function getBoatPayloadForPost(int $postId): array
    {
        if ($postId <= 0 || !function_exists('get_post_meta')) {
            return [];
        }

        $json = (string) get_post_meta($postId, '_maradigma_boat_payload', true);
        if ($json === '') {
            return [];
        }

        $payload = json_decode($json, true);

        return is_array($payload) ? $payload : [];
    }

    /**
     * Normalizes a resolved path and removes empty path segments.
     */
    private static function normalizeResolvedPath(string $path): string
    {
        $segments = [];

        foreach (explode('/', trim($path, '/')) as $segment) {
            $segment = self::slugify($segment);
            if ($segment !== '') {
                $segments[] = $segment;
            }
        }

        return implode('/', $segments);
    }

    /**
     * Converts supported placeholder aliases to the canonical token.
     */
    private static function canonicalizePlaceholders(string $template): string
    {
        return str_ireplace(
            [self::DESTINATION_PLACEHOLDER_ALIAS, self::DESTINATION_PLACEHOLDER],
            self::DESTINATION_PLACEHOLDER,
            $template
        );
    }

    /**
     * Produces a WordPress-compatible URL slug with a test-safe fallback.
     */
    private static function slugify(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (function_exists('sanitize_title')) {
            return (string) sanitize_title($value);
        }

        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?: '';

        return trim($value, '-');
    }

    /**
     * Normalizes a language code to its primary subtag.
     */
    private static function normalizeLanguage(string $language): string
    {
        $language = strtolower(trim($language));
        $language = (string) (preg_split('/[_-]/', $language)[0] ?? $language);

        return $language !== '' ? $language : RuntimeContext::detectCurrentLanguage();
    }
}

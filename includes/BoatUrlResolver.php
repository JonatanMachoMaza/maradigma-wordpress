<?php

declare(strict_types=1);

namespace Maradigma;

use Maradigma\Support\RuntimeContext;

/**
 * Resolves destination- and boat-type-aware public URLs for synchronized boats.
 *
 * The configured boat base may contain {{destination}} and {{boat_type}}, for
 * example "{{destination}}/alquiler-{{boat_type}}". Destinations are selected
 * from the API destinations catalogue and boat types from the service type
 * catalogue.
 */
final class BoatUrlResolver
{
    public const DESTINATION_PLACEHOLDER = '{{destination}}';
    public const BOAT_TYPE_PLACEHOLDER = '{{boat_type}}';

    private const DESTINATION_PLACEHOLDER_ALIAS = '{{destination_slug}}';
    private const BOAT_TYPE_PLACEHOLDER_ALIAS = '{{boat_type_slug}}';
    private const DESTINATION_MARKER = 'maradigmadestinationplaceholder';
    private const BOAT_TYPE_MARKER = 'maradigmaboattypeplaceholder';
    private const BOAT_TYPE_CATALOG_OPTION = 'maradigma_boat_type_url_catalog';

    /** @var array<string,int> Lower values identify better commercial SEO destinations. */
    private const DESTINATION_TYPE_PRIORITY = [
        'island' => 10,
        'archipelago' => 20,
        'locality' => 30,
        'sublocality' => 40,
        'neighborhood' => 50,
        'region' => 60,
        'administrative_area' => 70,
        'country' => 80,
        'marina' => 90,
        'port' => 100,
    ];

    /** @var array<string,array<string,array{name:string,slug:string}>> */
    private static array $boatTypeCatalog = [];

    /**
     * Resolves a configured base-path template for a boat payload.
     *
     * @param string              $template             Configured base-path template.
     * @param array<string,mixed> $boat                 Boat API payload.
     * @param string              $fallback             Static fallback base path.
     * @param string              $language             Language used to localize dynamic values.
     * @param bool                $requireDynamicValues Whether every configured placeholder must resolve.
     */
    public static function resolveBasePath(
        string $template,
        array $boat = [],
        string $fallback = 'boats',
        string $language = '',
        bool $requireDynamicValues = false
    ): string
    {
        $template = self::normalizeBaseTemplate($template, $fallback);
        $destinationSlug = self::getDestinationSlug($boat);
        $boatTypeSlug = self::getBoatTypeSlug($boat, $language);

        if (
            $requireDynamicValues
            && (
                (self::hasDestinationPlaceholder($template) && $destinationSlug === '')
                || (self::hasBoatTypePlaceholder($template) && $boatTypeSlug === '')
            )
        ) {
            return self::normalizeResolvedPath($fallback) ?: 'boats';
        }

        $resolved = str_replace(
            [self::DESTINATION_PLACEHOLDER, self::BOAT_TYPE_PLACEHOLDER],
            [$destinationSlug, $boatTypeSlug],
            $template
        );
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

        return self::resolveBasePath(
            $template,
            self::getBoatPayloadForPost($postId),
            $fallback,
            $language,
            true
        );
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
        $basePath = self::resolveBasePath(
            RuntimeContext::getBoatsBaseSlugForLang($language),
            $boat,
            'boats',
            $language,
            true
        );
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
        $placeholderCount = substr_count($template, self::DESTINATION_PLACEHOLDER)
            + substr_count($template, self::BOAT_TYPE_PLACEHOLDER);
        $quoted = preg_quote($template, '#');
        $baseRegex = str_replace(
            [
                preg_quote(self::DESTINATION_PLACEHOLDER, '#'),
                preg_quote(self::BOAT_TYPE_PLACEHOLDER, '#'),
            ],
            '([^/]+)',
            $quoted
        );

        return [
            'regex' => $baseRegex,
            'boat_match_index' => $placeholderCount + 1,
        ];
    }

    /**
     * Returns strict rewrite patterns for dynamic boat routes.
     *
     * Dynamic templates only match when every configured segment is present.
     * This prevents reduced variants from shadowing static ancestor pages such
     * as destination and boat-type landing pages. A separate static fallback
     * keeps boats with incomplete routing data reachable without ambiguity.
     *
     * @param string $template Configured base-path template.
     * @param string $fallback Static fallback base path.
     *
     * @return array<int,array{regex:string,boat_match_index:int}>
     */
    public static function buildRewritePatterns(string $template, string $fallback = 'boats'): array
    {
        $template = self::normalizeBaseTemplate($template, $fallback);
        $patterns = [self::buildRewritePattern($template, $fallback)];

        if (!self::hasDynamicPlaceholder($template)) {
            return $patterns;
        }

        $fallbackPattern = self::buildRewritePattern($fallback, $fallback);
        if ($fallbackPattern['regex'] !== $patterns[0]['regex']) {
            $patterns[] = $fallbackPattern;
        }

        return $patterns;
    }

    /**
     * Returns whether a configured base uses a destination placeholder.
     */
    public static function hasDestinationPlaceholder(string $template): bool
    {
        return str_contains(self::canonicalizePlaceholders($template), self::DESTINATION_PLACEHOLDER);
    }

    /**
     * Returns whether a configured base uses a boat type placeholder.
     */
    public static function hasBoatTypePlaceholder(string $template): bool
    {
        return str_contains(self::canonicalizePlaceholders($template), self::BOAT_TYPE_PLACEHOLDER);
    }

    /**
     * Returns whether a configured base contains any dynamic route placeholder.
     */
    public static function hasDynamicPlaceholder(string $template): bool
    {
        $template = self::canonicalizePlaceholders($template);

        return str_contains($template, self::DESTINATION_PLACEHOLDER)
            || str_contains($template, self::BOAT_TYPE_PLACEHOLDER);
    }

    /**
     * Returns the localized destination name from a boat payload.
     *
     * @param array<string,mixed> $boat Boat API payload.
     */
    public static function getDestinationName(array $boat): string
    {
        $destination = self::getPreferredDestination($boat);
        if ($destination !== null) {
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
        $destination = self::getPreferredDestination($boat);
        if ($destination !== null) {
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
     * Selects the destination that best represents a public boat route.
     *
     * The API returns all geographically related places ordered from the most
     * local one. Public charter URLs need the commercial destination instead,
     * so islands such as Mallorca and Ibiza take precedence over their cities
     * and ports. The legacy singular destination remains the fallback.
     *
     * @param array<string,mixed> $boat Boat API payload.
     * @return array<string,mixed>|null
     */
    private static function getPreferredDestination(array $boat): ?array
    {
        $destinations = is_array($boat['destinations'] ?? null)
            ? (array) $boat['destinations']
            : [];
        $preferred = null;
        $preferredPriority = PHP_INT_MAX;

        foreach ($destinations as $destination) {
            if (!is_array($destination) || self::getDestinationLabel($destination) === '') {
                continue;
            }

            $placeType = strtolower(trim((string) ($destination['place_type'] ?? '')));
            $priority = self::DESTINATION_TYPE_PRIORITY[$placeType] ?? 1000;
            if ($priority >= $preferredPriority) {
                continue;
            }

            $preferred = $destination;
            $preferredPriority = $priority;
        }

        if ($preferred !== null) {
            return $preferred;
        }

        $legacy = $boat['destination'] ?? null;

        return is_array($legacy) && self::getDestinationLabel($legacy) !== '' ? $legacy : null;
    }

    /**
     * Returns the first usable display value from a destination row.
     *
     * @param array<string,mixed> $destination Destination API row.
     */
    private static function getDestinationLabel(array $destination): string
    {
        foreach (['text', 'name', 'slug'] as $key) {
            $value = trim((string) ($destination[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * Stores the localized service type catalogue used by frontend URLs.
     *
     * @param array<int,array<string,mixed>> $types Service type rows returned by the API.
     */
    public static function storeBoatTypeCatalog(string $language, array $types): void
    {
        $language = self::normalizeLanguage($language);
        $catalog = self::loadBoatTypeCatalog();
        $catalog[$language] = [];

        foreach ($types as $type) {
            if (!is_array($type)) {
                continue;
            }

            $id = trim((string) ($type['id'] ?? ''));
            $name = trim((string) ($type['name'] ?? ''));
            if ($id === '' || $name === '') {
                continue;
            }

            $catalog[$language][$id] = [
                'name' => $name,
                'slug' => self::makeBoatTypeRouteSlug($id, $name, $language),
            ];
        }

        self::$boatTypeCatalog = $catalog;

        if (function_exists('update_option')) {
            update_option(self::BOAT_TYPE_CATALOG_OPTION, $catalog, false);
        }
    }

    /**
     * Adds localized boat type fields to an API boat payload when possible.
     *
     * @param array<string,mixed> $boat         Boat detail API payload.
     * @param array<string,mixed> $fallbackBoat Boat list payload used when the detail omits classification fields.
     * @return array<string,mixed>
     */
    public static function enrichBoatType(array $boat, string $language, array $fallbackBoat = []): array
    {
        $id = trim((string) (
            $boat['id_group_content_type']
            ?? $boat['boat_type_id']
            ?? $fallbackBoat['id_group_content_type']
            ?? $fallbackBoat['boat_type_id']
            ?? ''
        ));
        if ($id === '') {
            return $boat;
        }

        $entry = self::getBoatTypeCatalogEntry($id, $language);
        if ($entry === null) {
            return $boat;
        }

        $boat['id_group_content_type'] = (int) $id;
        $boat['boat_type'] = [
            'id' => (int) $id,
            'name' => $entry['name'],
            'slug' => $entry['slug'],
        ];
        $boat['boat_type_name'] = $entry['name'];
        $boat['boat_type_slug'] = $entry['slug'];

        return $boat;
    }

    /**
     * Returns the localized boat type name from a boat payload or catalogue.
     *
     * @param array<string,mixed> $boat Boat API payload.
     */
    public static function getBoatTypeName(array $boat, string $language = ''): string
    {
        $type = $boat['boat_type'] ?? null;
        if (is_array($type)) {
            foreach (['name', 'text', 'slug'] as $key) {
                $value = trim((string) ($type[$key] ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }
        }

        foreach (['boat_type_name', 'service_type_name', 'group_content_type_name'] as $key) {
            $value = trim((string) ($boat[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        $id = trim((string) ($boat['id_group_content_type'] ?? $boat['boat_type_id'] ?? ''));
        $entry = $id !== '' ? self::getBoatTypeCatalogEntry($id, $language) : null;

        return $entry['name'] ?? '';
    }

    /**
     * Returns the URL-safe localized boat type slug.
     *
     * @param array<string,mixed> $boat Boat API payload.
     */
    public static function getBoatTypeSlug(array $boat, string $language = ''): string
    {
        $type = $boat['boat_type'] ?? null;
        if (is_array($type)) {
            $explicitSlug = trim((string) ($type['slug'] ?? ''));
            if ($explicitSlug !== '') {
                return self::slugify($explicitSlug);
            }
        }

        foreach (['boat_type_slug', 'service_type_slug', 'group_content_type_slug'] as $key) {
            $value = trim((string) ($boat[$key] ?? ''));
            if ($value !== '') {
                return self::slugify($value);
            }
        }

        $id = trim((string) ($boat['id_group_content_type'] ?? $boat['boat_type_id'] ?? ''));
        $entry = $id !== '' ? self::getBoatTypeCatalogEntry($id, $language) : null;
        if ($entry !== null && $entry['slug'] !== '') {
            return $entry['slug'];
        }

        return self::slugify(self::getBoatTypeName($boat, $language));
    }

    /**
     * Normalizes a route template while preserving the destination placeholder.
     */
    public static function normalizeBaseTemplate(string $template, string $fallback = 'boats'): string
    {
        $template = self::canonicalizePlaceholders(trim($template));

        if (self::isMalformedLegacyBaseSlug($template)) {
            $template = $fallback;
        }

        $template = preg_replace('~https?://[^/]+~i', '', $template) ?: $template;
        $template = trim($template, '/');

        if ($template === '') {
            $template = $fallback;
        }

        $template = str_replace(
            [self::DESTINATION_PLACEHOLDER, self::BOAT_TYPE_PLACEHOLDER],
            [self::DESTINATION_MARKER, self::BOAT_TYPE_MARKER],
            $template
        );
        $segments = [];

        foreach (explode('/', $template) as $segment) {
            $segment = self::slugify($segment);
            if ($segment !== '') {
                $segments[] = str_replace(
                    [self::DESTINATION_MARKER, self::BOAT_TYPE_MARKER],
                    [self::DESTINATION_PLACEHOLDER, self::BOAT_TYPE_PLACEHOLDER],
                    $segment
                );
            }
        }

        $normalized = implode('/', $segments);
        if ($normalized !== '') {
            return $normalized;
        }

        return self::normalizeResolvedPath($fallback) ?: 'boats';
    }

    /**
     * Detects a multilingual slug map flattened by legacy sanitization.
     *
     * Older versions could pass a complete map such as
     * `es:producto,en:product,de:produkt,ca:producte` to `sanitize_title()`.
     * That produced a single invalid namespace such as
     * `esproductoenproductdeproduktcaproducte`, which must never be exposed in
     * public boat URLs.
     */
    public static function isMalformedLegacyBaseSlug(string $slug): bool
    {
        $slug = self::slugify($slug);
        if ($slug === '') {
            return false;
        }

        return (bool) preg_match(
            '/^(?:[a-z]{2,3}(?:product[a-z]*|produkt[a-z]*|producto[s]?|produit[s]?|prodotto|prodotti|produto[s]?)){2,}$/',
            $slug
        );
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
            [
                self::DESTINATION_PLACEHOLDER_ALIAS,
                self::DESTINATION_PLACEHOLDER,
                self::BOAT_TYPE_PLACEHOLDER_ALIAS,
                self::BOAT_TYPE_PLACEHOLDER,
            ],
            [
                self::DESTINATION_PLACEHOLDER,
                self::DESTINATION_PLACEHOLDER,
                self::BOAT_TYPE_PLACEHOLDER,
                self::BOAT_TYPE_PLACEHOLDER,
            ],
            $template
        );
    }

    /**
     * Loads the cached localized type catalogue.
     *
     * @return array<string,array<string,array{name:string,slug:string}>>
     */
    private static function loadBoatTypeCatalog(): array
    {
        if (self::$boatTypeCatalog !== []) {
            return self::$boatTypeCatalog;
        }

        if (!function_exists('get_option')) {
            return [];
        }

        $catalog = get_option(self::BOAT_TYPE_CATALOG_OPTION, []);
        self::$boatTypeCatalog = is_array($catalog) ? $catalog : [];

        return self::$boatTypeCatalog;
    }

    /**
     * Returns one localized type catalogue entry.
     *
     * @return array{name:string,slug:string}|null
     */
    private static function getBoatTypeCatalogEntry(string $id, string $language): ?array
    {
        $language = self::normalizeLanguage($language);
        $catalog = self::loadBoatTypeCatalog();
        $entry = $catalog[$language][$id] ?? null;

        if (!is_array($entry)) {
            return null;
        }

        $name = trim((string) ($entry['name'] ?? ''));
        $slug = self::slugify((string) ($entry['slug'] ?? $name));

        return $name !== '' && $slug !== '' ? ['name' => $name, 'slug' => $slug] : null;
    }

    /**
     * Produces concise SEO route slugs for the standard Maradigma boat types.
     */
    private static function makeBoatTypeRouteSlug(string $id, string $name, string $language): string
    {
        $standardSlugs = [
            'es' => [
                '1' => 'super-yate', '2' => 'yate', '3' => 'lancha', '4' => 'velero',
                '5' => 'neumatica', '29' => 'juguete-acuatico', '41' => 'catamaran',
            ],
            'en' => [
                '1' => 'super-yacht', '2' => 'yacht', '3' => 'motorboat', '4' => 'sailboat',
                '5' => 'rib', '29' => 'water-toy', '41' => 'catamaran',
            ],
            'ca' => [
                '1' => 'superiot', '2' => 'iot', '3' => 'llanxa', '4' => 'veler',
                '5' => 'semirigida', '29' => 'joguina-aquatica', '41' => 'catamara',
            ],
        ];

        $slug = $standardSlugs[$language][$id] ?? self::slugify($name);

        if (function_exists('apply_filters')) {
            $slug = (string) apply_filters('maradigma_boat_type_route_slug', $slug, $id, $name, $language);
        }

        return self::slugify($slug);
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

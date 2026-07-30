<?php

declare(strict_types=1);

namespace Maradigma\Integrations\WooCommerce;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WooCommerce integration for Maradigma boat bindings.
 *
 * This integration is intentionally inert unless WooCommerce is active. When it
 * is active, it adds WooCommerce products to the list of post types that can be
 * linked to an external Maradigma boat, while keeping WooCommerce system pages
 * and technical post types out of the boat-binding workflow.
 *
 * Main responsibilities:
 * - Allow published `product` posts to be selected as boat detail pages.
 * - Exclude WooCommerce internal post types such as orders, coupons, webhooks
 *   and product variations from Maradigma binding settings.
 * - Protect core WooCommerce pages such as cart, checkout and my account from
 *   being converted into boat detail pages by mistake.
 * - Prevent Maradigma boat base slugs from colliding with WooCommerce reserved
 *   URL namespaces.
 * - Rescue root-level product permalinks linked to boats when WordPress parses
 *   them as plain pages before WooCommerce resolves the product request.
 */
final class WooCommerceIntegration
{
    /**
     * WooCommerce post types that are not public boat-detail surfaces.
     *
     * These post types may exist in WordPress, but they represent operational
     * WooCommerce entities rather than customer-facing pages that should render
     * a Maradigma boat.
     *
     * @var list<string>
     */
    private const TECHNICAL_POST_TYPES = [
        'shop_order',
        'shop_order_placehold',
        'shop_order_refund',
        'shop_coupon',
        'shop_webhook',
        'product_variation',
    ];

    /**
     * URL namespaces reserved by WooCommerce.
     *
     * Maradigma's configurable boat base slug must not use any of these values,
     * otherwise WordPress rewrite rules can conflict with WooCommerce product,
     * taxonomy, checkout or account routes.
     *
     * @var list<string>
     */
    private const RESERVED_SLUGS = [
        'shop',
        'cart',
        'checkout',
        'my-account',
        'myaccount',
        'product',
        'product-category',
        'product-tag',
        'producto',
        'productos',
        'produkt',
        'produkte',
        'producte',
        'productes',
        'produit',
        'produits',
        'prodotto',
        'prodotti',
        'produto',
        'produtos',
    ];

    /**
     * WooCommerce option keys for pages that must not become boat pages.
     *
     * @var list<string>
     */
    private const PROTECTED_PAGE_KEYS = [
        'shop',
        'cart',
        'checkout',
        'myaccount',
        'terms',
        'privacy',
    ];

    /**
     * Register WooCommerce-specific hooks.
     *
     * The integration is loaded by the Maradigma bootstrap, but the hooks are
     * only registered when WooCommerce is actually present. This keeps the core
     * plugin independent from WooCommerce and avoids touching request routing on
     * sites that do not use it.
     */
    public static function register(): void
    {
        if (!self::isWooCommerceActive()) {
            return;
        }

        add_filter('maradigma_eligible_boat_binding_post_types', [self::class, 'filterEligibleBoatBindingPostTypes'], 10, 2);
        add_filter('maradigma_enabled_boat_binding_post_types', [self::class, 'filterEnabledBoatBindingPostTypes'], 10, 3);
        add_filter('maradigma_is_post_protected_from_boat_binding', [self::class, 'isPostProtectedFromBoatBinding'], 10, 3);
        add_filter('maradigma_boats_base_slug_before_save', [self::class, 'filterBoatsBaseSlugBeforeSave'], 10, 3);
        add_filter('maradigma_boats_base_slug_for_lang', [self::class, 'filterBoatsBaseSlugForLang'], 10, 3);
        add_filter('request', [self::class, 'maybeRouteBoundProductRequest'], 20);
    }

    /**
     * Compatibility entry point for the generic integration loader.
     */
    public static function init(): void
    {
        self::register();
    }

    /**
     * Add WooCommerce products as bindable post types and remove technical ones.
     *
     * Products are valid SEO landing/detail pages for clients that use
     * WooCommerce as their content layer. Orders, coupons, webhooks and product
     * variations are deliberately excluded because they are not public boat
     * detail pages.
     *
     * @param array<string,array{name:string,label:string}> $postTypes Current eligible post types indexed by post type name.
     * @param array<string,\WP_Post_Type>                  $objects   Registered public post type objects.
     * @return array<string,array{name:string,label:string}> Filtered eligible post types.
     */
    public static function filterEligibleBoatBindingPostTypes(array $postTypes, array $objects = []): array
    {
        foreach (self::TECHNICAL_POST_TYPES as $postType) {
            unset($postTypes[$postType]);
        }

        if (isset($objects['product']) && $objects['product'] instanceof \WP_Post_Type) {
            $product = $objects['product'];
            $postTypes['product'] = [
                'name'  => 'product',
                'label' => (string) ($product->labels->singular_name ?? $product->label ?? __('Product', 'maradigma')),
            ];
        }

        return $postTypes;
    }

    /**
     * Keep enabled binding post types aligned with the WooCommerce allow-list.
     *
     * This prevents stale settings from re-enabling technical WooCommerce post
     * types after plugins are activated/deactivated or settings are imported.
     *
     * @param list<string>        $enabled  Post types enabled in Maradigma settings.
     * @param list<string>        $eligible Current eligible post type names.
     * @param array<string,mixed> $settings Raw Maradigma settings array.
     * @return list<string> Enabled post types that are still eligible.
     */
    public static function filterEnabledBoatBindingPostTypes(array $enabled, array $eligible, array $settings = []): array
    {
        unset($settings);

        $enabled = array_values(array_diff($enabled, self::TECHNICAL_POST_TYPES));

        return array_values(array_intersect($enabled, $eligible));
    }

    /**
     * Protect WooCommerce system pages from Maradigma boat binding.
     *
     * Normal products are allowed. Only WordPress pages assigned to WooCommerce
     * as shop/cart/checkout/account/etc. are protected, because overwriting their
     * content semantics with a boat detail page would break WooCommerce flows.
     *
     * @param bool     $protected Whether another integration already protected this post.
     * @param int      $postId    Current post ID.
     * @param \WP_Post $post      Current post object.
     * @return bool True when Maradigma boat binding must be disabled for this post.
     */
    public static function isPostProtectedFromBoatBinding(bool $protected, int $postId, \WP_Post $post): bool
    {
        if ($protected) {
            return true;
        }

        if ($post->post_type !== 'page') {
            return false;
        }

        return in_array($postId, self::getProtectedWooCommercePageIds(), true);
    }

    /**
     * Prevent the Maradigma boat base slug from colliding with WooCommerce.
     *
     * If the submitted slug is reserved, the previous valid Maradigma slug is
     * kept. If there is no valid previous value, the safe fallback `boats` is
     * used.
     *
     * @param mixed               $slug            Submitted slug value.
     * @param array<string,mixed> $input           Raw settings input.
     * @param array<string,mixed> $currentSettings Current persisted settings.
     * @return string Sanitized, non-reserved base slug.
     */
    public static function filterBoatsBaseSlugBeforeSave($slug, array $input = [], array $currentSettings = []): string
    {
        unset($input);

        $raw = trim((string) $slug);
        if ($raw === '') {
            return 'boats';
        }

        $previousRaw = trim((string) ($currentSettings['boats_base_slug'] ?? ''));

        if (strpos($raw, ':') !== false) {
            return self::sanitizeLanguageMappedBoatsBaseSlug($raw, $previousRaw);
        }

        return self::sanitizeSingleBoatsBaseSlug($raw, $previousRaw, 'boats');
    }

    /**
     * Runtime guard for already-saved boat base slugs.
     *
     * This prevents a previously flattened language map such as
     * `esproductoenproductdeproduktcaproducte` from continuing to generate bad
     * public URLs after the sanitizer has been fixed.
     *
     * @param string $slug Resolved slug for the current language.
     * @param string $lang Current language code.
     * @param string $raw  Raw configured setting.
     */
    public static function filterBoatsBaseSlugForLang(string $slug, string $lang = '', string $raw = ''): string
    {
        unset($lang, $raw);

        return self::sanitizeSingleBoatsBaseSlug($slug, '', 'boats');
    }
    /**
     * Sanitize a simple or fallback boat-base slug and keep it away from Woo namespaces.
     */
    private static function sanitizeSingleBoatsBaseSlug(string $slug, string $previous = '', string $fallback = 'boats'): string
    {
        $slug = sanitize_title(trim($slug, "/ \t\n\r\0\x0B"));
        if ($slug !== '' && !self::isReservedSlug($slug)) {
            return $slug;
        }

        $previous = sanitize_title(trim($previous, "/ \t\n\r\0\x0B"));
        if ($previous !== '' && strpos($previous, ':') === false && !self::isReservedSlug($previous)) {
            return $previous;
        }

        $fallback = sanitize_title(trim($fallback, "/ \t\n\r\0\x0B"));
        return $fallback !== '' && !self::isReservedSlug($fallback) ? $fallback : 'boats';
    }

    /**
     * Sanitize a language map like `es:barcos,en:boats` without flattening it.
     */
    private static function sanitizeLanguageMappedBoatsBaseSlug(string $raw, string $previousRaw = ''): string
    {
        $previous = self::parseLanguageSlugMap($previousRaw);
        $out = [];

        foreach (self::parseLanguageSlugMap($raw) as $lang => $value) {
            $fallback = isset($previous[$lang]) ? (string) $previous[$lang] : 'boats';
            $clean = self::sanitizeSingleBoatsBaseSlug($value, $fallback, 'boats');
            $out[$lang] = $clean;
        }

        if (empty($out)) {
            return self::sanitizeSingleBoatsBaseSlug($raw, $previousRaw, 'boats');
        }

        $pairs = [];
        foreach ($out as $lang => $value) {
            $pairs[] = $lang . ':' . $value;
        }

        return implode(',', $pairs);
    }

    /**
     * Parse a language=>slug setting while preserving only safe language keys.
     *
     * @return array<string,string>
     */
    private static function parseLanguageSlugMap(string $raw): array
    {
        $map = [];

        foreach (array_filter(array_map('trim', explode(',', $raw))) as $pair) {
            $pos = strpos($pair, ':');
            if ($pos === false) {
                continue;
            }

            $lang = strtolower(trim(substr($pair, 0, $pos)));
            $lang = (string) (preg_split('/[_-]/', $lang)[0] ?? $lang);
            $lang = preg_replace('/[^a-z0-9]/', '', $lang) ?: '';

            $value = trim(substr($pair, $pos + 1));
            if ($lang === '' || $value === '') {
                continue;
            }

            $map[$lang] = $value;
        }

        return $map;
    }

    /**
     * Returns true when a slug is reserved by WooCommerce product namespaces.
     */
    private static function isReservedSlug(string $slug): bool
    {
        $slug = sanitize_title($slug);
        if ($slug === '') {
            return false;
        }

        return in_array($slug, self::getReservedSlugs(), true);
    }

    /**
     * Detects maps that were accidentally sanitized as one slug.
     */
    private static function looksLikeFlattenedReservedLanguageMap(string $slug): bool
    {
        $slug = sanitize_title($slug);
        if ($slug === '') {
            return false;
        }

        return (bool) preg_match(
            '/^(?:[a-z]{2,3}(?:product[a-z]*|produkt[a-z]*|producto[s]?|produit[s]?|prodotto|prodotti|produto[s]?)){2,}$/',
            $slug
        );
    }
    /**
     * Resolve static and configured WooCommerce permalink bases.
     *
     * @return list<string>
     */
    private static function getReservedSlugs(): array
    {
        $reserved = self::RESERVED_SLUGS;

        $permalinks = get_option('woocommerce_permalinks', []);
        if (is_array($permalinks)) {
            foreach (['product_base', 'category_base', 'tag_base', 'attribute_base'] as $key) {
                $value = trim((string) ($permalinks[$key] ?? ''));
                if ($value === '') {
                    continue;
                }

                foreach (explode('/', trim($value, '/')) as $part) {
                    $part = sanitize_title($part);
                    if ($part !== '') {
                        $reserved[] = $part;
                    }
                }
            }
        }

        return array_values(array_unique(array_filter($reserved)));
    }
    /**
     * Route root-level product URLs that are linked to Maradigma boats.
     *
     * Some client sites use product URLs such as `/riva-argo-90-ella/` even when
     * WooCommerce's configured product base is `/product`. WordPress may parse
     * that request as a regular page (`pagename`) and produce a 404 before
     * WooCommerce gets a clean product query. When the requested slug matches a
     * product that is explicitly bound to a Maradigma boat, this method rewrites
     * the query vars to the canonical product query.
     *
     * The rescue is intentionally narrow:
     * - frontend requests only;
     * - single path segment only;
     * - existing WooCommerce product only;
     * - product must already be bound to a Maradigma boat.
     *
     * @param array<string,mixed> $queryVars Parsed WordPress request query vars.
     * @return array<string,mixed> Possibly rewritten query vars.
     */
    public static function maybeRouteBoundProductRequest(array $queryVars): array
    {
        if (is_admin() || !post_type_exists('product')) {
            return $queryVars;
        }

        $slug = self::extractRequestedSingleSlug($queryVars);
        if ($slug === '') {
            return $queryVars;
        }

        $product = get_page_by_path($slug, OBJECT, 'product');
        if (!$product instanceof \WP_Post) {
            return $queryVars;
        }

        $boatId = self::getBoundBoatId($product);
        if ($boatId === '') {
            return $queryVars;
        }

        unset($queryVars['pagename'], $queryVars['page'], $queryVars['attachment'], $queryVars['attachment_id']);
        $queryVars['post_type'] = 'product';
        $queryVars['name'] = (string) $product->post_name;

        return $queryVars;
    }

    /**
     * Detect whether WooCommerce is available in the current WordPress runtime.
     *
     * @return bool True when WooCommerce has been loaded or is being loaded.
     */
    private static function isWooCommerceActive(): bool
    {
        return class_exists('WooCommerce') || function_exists('WC') || defined('WC_PLUGIN_FILE');
    }

    /**
     * Resolve all WooCommerce system page IDs configured in the shop.
     *
     * @return list<int> Unique positive page IDs.
     */
    private static function getProtectedWooCommercePageIds(): array
    {
        $ids = [];

        foreach (self::PROTECTED_PAGE_KEYS as $pageKey) {
            $id = self::getWooCommercePageId($pageKey);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Get a WooCommerce page ID by key with a safe option fallback.
     *
     * @param string $pageKey WooCommerce page key, for example `cart` or `checkout`.
     * @return int Positive page ID, or 0 when unavailable.
     */
    private static function getWooCommercePageId(string $pageKey): int
    {
        if (function_exists('wc_get_page_id')) {
            $id = (int) wc_get_page_id($pageKey);
            return $id > 0 ? $id : 0;
        }

        $optionKey = 'woocommerce_' . $pageKey . '_page_id';
        $id = (int) get_option($optionKey, 0);

        return $id > 0 ? $id : 0;
    }

    /**
     * Extract a safe single-segment slug from WordPress query vars or request URI.
     *
     * @param array<string,mixed> $queryVars Parsed WordPress request query vars.
     * @return string Sanitized slug, or an empty string when the request is not a single item route.
     */
    private static function extractRequestedSingleSlug(array $queryVars): string
    {
        $slug = '';

        foreach (['pagename', 'name', 'product'] as $key) {
            if (!empty($queryVars[$key]) && is_scalar($queryVars[$key])) {
                $slug = trim((string) $queryVars[$key]);
                break;
            }
        }

        if ($slug === '' && isset($_SERVER['REQUEST_URI'])) {
            $requestUri = sanitize_url((string) wp_unslash($_SERVER['REQUEST_URI']));
            $path = (string) wp_parse_url($requestUri, PHP_URL_PATH);
            $path = trim($path, '/');
            if ($path !== '' && strpos($path, '/') === false) {
                $slug = $path;
            }
        }

        $slug = trim($slug, '/');
        if ($slug === '' || strpos($slug, '/') !== false) {
            return '';
        }

        return sanitize_title($slug);
    }

    /**
     * Resolve the Maradigma boat ID bound to a product or other supported post.
     *
     * @param \WP_Post $post WordPress post object to inspect.
     * @return string External Maradigma boat ID, or an empty string when none is bound.
     */
    private static function getBoundBoatId(\WP_Post $post): string
    {
        if (class_exists(\Maradigma\MetaManager::class)) {
            return trim((string) \Maradigma\MetaManager::getBoundBoatIdForPost((int) $post->ID));
        }

        $isBoatPage = (bool) get_post_meta((int) $post->ID, '_maradigma_is_boat_page', true);
        if (!$isBoatPage) {
            return '';
        }

        return trim((string) get_post_meta((int) $post->ID, '_maradigma_page_boat_id', true));
    }
}

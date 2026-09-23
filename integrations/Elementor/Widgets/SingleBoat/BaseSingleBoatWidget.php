<?php
declare(strict_types=1);

namespace Maradigma\Integrations\Elementor\Widgets\SingleBoat;

use Elementor\Widget_Base;
use Maradigma\Cache;
use Maradigma\MetaManager;
use Maradigma\BoatPostType;

/**
 * Provides shared boat context and rendering helpers for single-boat Elementor widgets.
 */
abstract class BaseSingleBoatWidget extends Widget_Base
{
    /**
     * When editing the master Elementor template (elementor_library), widgets don't have a boat context.
     * We use a "preview" boat post so the editor can render with real data (images, price, etc.).
     */
    private const META_TEMPLATE_PREVIEW_BOAT_POST_ID = '_maradigma_preview_boat_post_id';

    /** Option fallback for the preview boat post id. */
    private const OPTION_PREVIEW_BOAT_POST_ID = 'maradigma_elementor_preview_boat_post_id';

    /**
     * Returns boat ID from current post.
     */
    protected function getBoatIdFromCurrentPost(): string
    {
        $postId = $this->resolveCurrentPostIdForElementor();
        if ($postId <= 0) {
            return '';
        }

        $postType = (string) get_post_type($postId);

        /**
         * Elementor master template preview.
         *
         * In this context the current post is usually an elementor_library entry,
         * not a real page/CPT bound to a boat. We therefore resolve a preview post
         * first and then delegate the real boat-id lookup to MetaManager.
         */
        if ($postType === 'elementor_library') {
            $previewBoatPostId = $this->resolvePreviewBoatPostIdForTemplate($postId);
            if ($previewBoatPostId <= 0) {
                return '';
            }

            return trim((string) MetaManager::getBoundBoatIdForPost($previewBoatPostId));
        }

        /**
         * Generic binding resolution.
         *
         * IMPORTANT:
         * - This supports:
         *   - classic pages manually linked to a boat
         *   - Maradigma Boat CPT posts
         *   - any custom post type enabled in MetaManager/Settings
         *
         * Centralizing this logic in MetaManager avoids hardcoding only "page"
         * or BoatPostType::POST_TYPE here.
         */
        return trim((string) MetaManager::getBoundBoatIdForPost($postId));
    }

    /**
     * Get a boat post id to use as preview when rendering widgets inside the master template.
     * - First tries template meta
     * - Then option fallback
     * - Then first available Boat CPT in DB
     * - Finally tries any supported binding post type with a valid bound boat id
     */
    private function resolvePreviewBoatPostIdForTemplate(int $templatePostId): int
    {
        $metaId = (int) get_post_meta($templatePostId, self::META_TEMPLATE_PREVIEW_BOAT_POST_ID, true);
        if ($metaId > 0 && $this->isValidPreviewBoatBindingPost($metaId)) {
            return $metaId;
        }

        $optId = (int) get_option(self::OPTION_PREVIEW_BOAT_POST_ID, 0);
        if ($optId > 0 && $this->isValidPreviewBoatBindingPost($optId)) {
            // Keep template meta in sync to avoid extra DB reads.
            update_post_meta($templatePostId, self::META_TEMPLATE_PREVIEW_BOAT_POST_ID, $optId);
            return $optId;
        }

        // Fallback 1: prefer a real Maradigma boat CPT post if available.
        $q = new \WP_Query([
            'post_type'      => BoatPostType::POST_TYPE,
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'posts_per_page' => 1,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);

        $found = !empty($q->posts[0]) ? (int) $q->posts[0] : 0;

        if ($found <= 0) {
            $q = new \WP_Query([
                'post_type'      => BoatPostType::POST_TYPE,
                'post_status'    => 'any',
                'fields'         => 'ids',
                'posts_per_page' => 1,
                'orderby'        => 'date',
                'order'          => 'DESC',
            ]);

            $found = !empty($q->posts[0]) ? (int) $q->posts[0] : 0;
        }

        if ($found > 0 && $this->isValidPreviewBoatBindingPost($found)) {
            update_option(self::OPTION_PREVIEW_BOAT_POST_ID, $found, false);
            update_post_meta($templatePostId, self::META_TEMPLATE_PREVIEW_BOAT_POST_ID, $found);
            return $found;
        }

        /**
         * Fallback 2:
         * try any supported binding post type that already resolves to a valid boat id.
         *
         * This is useful when a client uses a custom CPT instead of the native
         * maradigma_boat CPT as the source post for the Single template preview.
         */
        $supportedPostTypes = [];
        if (method_exists(MetaManager::class, 'getSupportedBoatBindingPostTypes')) {
            $supportedPostTypes = (array) MetaManager::getSupportedBoatBindingPostTypes();
        }

        $supportedPostTypes = array_values(array_unique(array_filter(array_map(
            static fn($value): string => trim((string) $value),
            $supportedPostTypes
        ))));

        if (!empty($supportedPostTypes)) {
            $q = new \WP_Query([
                'post_type'              => $supportedPostTypes,
                'post_status'            => ['publish', 'draft', 'private', 'pending', 'future'],
                'fields'                 => 'ids',
                'posts_per_page'         => 25,
                'orderby'                => 'date',
                'order'                  => 'DESC',
                'no_found_rows'          => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            ]);

            if (!empty($q->posts) && is_array($q->posts)) {
                foreach ($q->posts as $candidatePostId) {
                    $candidatePostId = (int) $candidatePostId;
                    if ($candidatePostId <= 0) {
                        continue;
                    }

                    if (!$this->isValidPreviewBoatBindingPost($candidatePostId)) {
                        continue;
                    }

                    update_option(self::OPTION_PREVIEW_BOAT_POST_ID, $candidatePostId, false);
                    update_post_meta($templatePostId, self::META_TEMPLATE_PREVIEW_BOAT_POST_ID, $candidatePostId);

                    return $candidatePostId;
                }
            }
        }

        return 0;
    }

    /**
     * Checks whether a post can be used as a valid preview source for Single Boat widgets.
     */
    private function isValidPreviewBoatBindingPost(int $postId): bool
    {
        if ($postId <= 0) {
            return false;
        }

        $postType = (string) get_post_type($postId);
        if ($postType === '') {
            return false;
        }

        $boatId = trim((string) MetaManager::getBoundBoatIdForPost($postId));
        if ($boatId === '') {
            return false;
        }

        return true;
    }

    /**
     * Elementor a veces no tiene “queried object” real.
     * Esto intenta sacar el ID correcto en editor/preview y en frontend.
     */
    protected function resolveCurrentPostIdForElementor(): int
    {
        // Elementor preview ID (cuando editas una Single Template)
        if (class_exists('\Elementor\Plugin')) {
            try {
                $plugin = \Elementor\Plugin::$instance;

                // En editor/preview suele existir este “post_id” real de preview
                if (isset($plugin->preview) && method_exists($plugin->preview, 'get_post_id')) {
                    $previewId = (int) $plugin->preview->get_post_id();
                    if ($previewId > 0) {
                        return $previewId;
                    }
                }
            } catch (\Throwable $e) {
                // noop
            }
        }

        // Frontend normal
        $postId = (int) get_queried_object_id();
        if ($postId > 0) {
            return $postId;
        }

        $postId = (int) get_the_ID();
        if ($postId > 0) {
            return $postId;
        }

        global $post;
        if ($post instanceof \WP_Post) {
            return (int) $post->ID;
        }

        return 0;
    }

    /**
     * Returns current language for API.
     */
    protected function getCurrentLanguageForApi(): string
    {
        // 1) Idioma ACTUAL de la página (Polylang/WPML) => 'es','en',...
        if (class_exists(\Maradigma\Support\MultilangAdapter::class)) {
            $cur = \Maradigma\Support\MultilangAdapter::getCurrentLanguage();
            $cur = strtoupper(trim($cur));
            if ($cur !== '') {
                return $cur;
            }
        }

        // 2) Fallback: settings del plugin (default_language: EN/ES)
        if (class_exists(\Maradigma\SettingsPage::class)) {
            $settings = \Maradigma\SettingsPage::getSettings();
            $lang = isset($settings['default_language']) ? (string) $settings['default_language'] : '';
            $lang = strtoupper(trim($lang));
            if ($lang !== '') {
                return $lang;
            }
        }

        // 3) Fallback final: locale de WP (es_ES -> ES)
        $locale = (string) get_locale();
        $parts  = preg_split('/[_-]/', $locale);
        $lang   = strtoupper((string) ($parts[0] ?? 'EN'));

        return $lang !== '' ? $lang : 'EN';
    }

    /**
     * @param array<int,string> $expand
     * @param array<string,mixed> $extraOptions
     * @return array{expand:array<int,string>, options:array<string,mixed>}
     */
    private function normalizeExpandAndOptions(array $expand, array $extraOptions): array
    {
        $optionsMap = [
            // ✅ estos NO van en expand, van como query bool override
            'service_url_images_main_domain' => ['url_images_main_domain' => 1],
            // si quisieras un alias interno (opcional):
            // 'force_images' => ['images' => 1],
        ];

        $normalizedExpand  = [];
        $normalizedOptions = is_array($extraOptions) ? $extraOptions : [];

        foreach ($expand as $e) {
            $e = trim((string) $e);
            if ($e === '') {
                continue;
            }

            if (isset($optionsMap[$e])) {
                $normalizedOptions = array_merge($normalizedOptions, $optionsMap[$e]);
                continue;
            }

            // ✅ IMPORTANTE: mantenemos el contract name (service_prices, etc.)
            $normalizedExpand[] = $e;
        }

        $normalizedExpand = array_values(array_unique($normalizedExpand));
        sort($normalizedExpand);

        ksort($normalizedOptions);

        return [
            'expand'  => $normalizedExpand,
            'options' => $normalizedOptions,
        ];
    }

    /**
     * Infer which keys must exist in result['data'] depending on requested expand.
     * This is used to invalidate incomplete cache payloads.
     *
     * Expand values are the PUBLIC API contract names (service_*).
     * Required keys are the REAL keys returned by Service_GroupItem::get_secure_data_gi().
     *
     * @param array<int,string> $normalizedExpand
     * @return array<int,string>
     */
    private function inferRequiredDataKeysFromExpand(array $normalizedExpand): array
    {
        // Public expand => expected data keys
        $map = [
            'service_accounting'             => ['accounting'],
            // The payload key is additional_services, null when the boat has no extras.
            'service_additional_services'    => [],
            'service_property_amenities'     => ['amenities'],
            'service_admin_tools'            => ['admin_tools'],
            'service_payment_methods'        => ['payment_methods'],
            'service_descriptions'           => ['descriptions'],
            'service_equipments'             => ['equipments'],
            'service_group_category'         => [],
            'service_images'                 => ['images'],
            'service_ical'                   => ['url_ical'],
            'service_owner'                  => ['owner'],
            'service_included_items'         => ['included'],
            'service_not_included_items'     => ['not_included'],
            'service_prices'                 => ['prices'],
            // Rates and time slots have no top-level key of their own.
            'service_price_rates'            => [],
            'service_price_time_slots'       => [],
            'service_public_urls'            => ['website_urls'],
            'service_unavailability_dates'   => ['unavailability_dates'],
            'service_real_unavailable_dates' => ['real_dates_not_available'],
        ];

        $required = [];

        foreach ($normalizedExpand as $e) {
            $e = strtolower(trim((string) $e));
            if ($e === '') {
                continue;
            }
            if (!isset($map[$e])) {
                continue;
            }
            foreach ($map[$e] as $k) {
                $required[] = $k;
            }
        }

        $required = array_values(array_unique($required));
        sort($required);

        return $required;
    }

    /**
     * Helper común para pedir datos del barco con expand/options.
     *
     * @param array<int,string> $expand
     * @param array<string,mixed> $extraOptions
     * @return array{ok:bool,message?:string,data?:array<string,mixed>}
     */
    protected function getBoatData(array $expand = [], array $extraOptions = []): array
    {
        $boatId = $this->getBoatIdFromCurrentPost();
        if ($boatId === '') {
            return [
                'ok' => false,
                'message' => __('Select a Maradigma boat in this post first.', 'maradigma'),
            ];
        }

        $language = $this->getCurrentLanguageForApi();

        // Normaliza expand (trim + remove empty)
        $expand = array_values(array_filter(array_map('trim', $expand), static fn($v) => $v !== ''));

        /**
         * Separa y normaliza:
         * - expand (flags reales de tu API: descriptions, prices, equipments, etc.)
         * - options (query params/flags: images, url_images_main_domain, only_load_cover_image, etc.)
         *
         * IMPORTANTE:
         * - "service_images" NO es expand en tu API (es $args["images"])
         * - "service_url_images_main_domain" NO es expand (es $args["url_images_main_domain"])
         */
        $norm = $this->normalizeExpandAndOptions($expand, $extraOptions);

        $expand  = $norm['expand'];
        $options = $norm['options'];

        // Asegura que 'expand' viaje en options
        $options = array_merge(
            [
                'expand' => $expand,
            ],
            $options
        );

        $cache = new Cache();

        // Requisitos mínimos según expand (para invalidar caché incompleta si tu Cache lo soporta)
        $requiredKeys = $this->inferRequiredDataKeysFromExpand($expand);

        try {
            $method = new \ReflectionMethod($cache, 'getBoatDetails');
            $argc   = $method->getNumberOfParameters();
        } catch (\Throwable $e) {
            $argc = 3;
        }

        if ($argc >= 5) {
            /** @var array<string,mixed> $result */
            $result = $cache->getBoatDetails($boatId, $language, $options, $requiredKeys, false);
        } elseif ($argc >= 4) {
            /** @var array<string,mixed> $result */
            $result = $cache->getBoatDetails($boatId, $language, $options, $requiredKeys);
        } else {
            /** @var array<string,mixed> $result */
            $result = $cache->getBoatDetails($boatId, $language, $options);
        }

        $ok = is_array($result)
            && (($result['status'] ?? '') === 'success')
            && is_array(($result['data'] ?? null));

        if (!$ok) {
            $resultJson = wp_json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            maradigma_debug_log(
                '[Maradigma] Unable to load Boat data (' . $boatId . '): ' .
                (is_string($resultJson) ? $resultJson : '')
            );

            return [
                'ok' => false,
                'message' => __('Unable to load boat data from Maradigma.', 'maradigma'),
            ];
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            $optionsJson = wp_json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            maradigma_debug_log('[Maradigma] BoatDetails request => boatId=' . $boatId . ' language=' . $language);
            maradigma_debug_log('[Maradigma] BoatDetails request => options=' . (is_string($optionsJson) ? $optionsJson : ''));
        }

        return [
            'ok'   => true,
            'data' => (array) $result['data'],
        ];
    }

    /**
     * Context “single boat” estándar para widgets.
     * Por defecto NO expande nada (cada widget pide lo suyo).
     *
     * @param array<int,string> $expand
     * @param array<string,mixed> $extraOptions
     * @return array{ok:bool,message?:string,data?:array<string,mixed>}
     */
    protected function getSingleBoatContext(array $expand = [], array $extraOptions = []): array
    {
        return $this->getBoatData($expand, $extraOptions);
    }

    /**
     * Renderiza un aviso amigable dentro del editor / frontend.
     */
    protected function renderMissingContextNotice(string $message): void
    {
        echo '<div class="maradigma-elementor-notice" style="padding:10px 12px;border:1px solid #e5e5e5;background:#fff;border-radius:6px;">';
        echo '<strong style="display:block;margin:0 0 4px;">' . esc_html__('Maradigma', 'maradigma') . '</strong>';
        echo '<span>' . esc_html($message) . '</span>';
        echo '</div>';
    }

    /**
     * Compat wrapper: algunos widgets llaman a resolveContext().
     * Devuelve un contexto estándar: ['boat_id' => string, 'data' => array|null, 'ok' => bool, 'message' => string]
     *
     * @param array<int,string> $expand
     * @param array<string,mixed> $extraOptions
     * @return array<string,mixed>
     */
    protected function resolveContext(array $expand = [], array $extraOptions = []): array
    {
        $boatId = $this->getBoatIdFromCurrentPost();

        $ctx = $this->getSingleBoatContext($expand, $extraOptions);

        if (!($ctx['ok'] ?? false)) {
            $this->renderMissingContextNotice((string) ($ctx['message'] ?? ''));
            return [
                'ok'      => false,
                'boat_id' => $boatId,
                'data'    => null,
                'message' => (string) ($ctx['message'] ?? ''),
            ];
        }

        return [
            'ok'      => true,
            'boat_id' => $boatId,
            'data'    => (array) ($ctx['data'] ?? []),
            'message' => '',
        ];
    }

    /**
     * Returns true when the widget is being rendered in Elementor editor/preview.
     * Useful to show placeholders instead of hiding empty widgets.
     */
    protected function isElementorEditor(): bool
    {
        if (!\did_action('elementor/loaded') || !\class_exists('\\Elementor\\Plugin')) {
            return false;
        }

        try {
            $plugin = \Elementor\Plugin::instance();

            if (isset($plugin->editor) && \method_exists($plugin->editor, 'is_edit_mode') && $plugin->editor->is_edit_mode()) {
                return true;
            }

            if (isset($plugin->preview) && \method_exists($plugin->preview, 'is_preview_mode') && $plugin->preview->is_preview_mode()) {
                return true;
            }
        } catch (\Throwable $e) {
            // Ignore - just treat as non-editor context.
        }

        return false;
    }

    /**
     * Small placeholder box shown only inside Elementor editor.
     */
    protected function renderEditorHint(string $title, string $description = ''): void
    {
        echo '<div class="maradigma-elementor-hint" style="padding:12px;border:1px dashed #bdbdbd;border-radius:8px;background:#fafafa;">';
        echo '<strong>' . \esc_html($title) . '</strong>';
        if ($description !== '') {
            echo '<div style="margin-top:6px;">' . \esc_html($description) . '</div>';
        }
        echo '</div>';
    }
}

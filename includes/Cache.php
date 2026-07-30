<?php

declare(strict_types=1);

namespace Maradigma;

/**
 * Caching layer around ExternalApiClient.
 *
 * Cache toggle:
 * - If MARADIGMA_CACHE_ENABLED is defined and true => uses WP transients.
 * - Otherwise => always calls ExternalApiClient directly (no transients).
 */
final class Cache
{
    private ExternalApiClient $client;

    public function __construct(?ExternalApiClient $client = null)
    {
        if ($client !== null) {
            $this->client = $client;
            return;
        }

        $settings     = SettingsPage::getSettings();
        $this->client = ExternalApiClient::fromSettings($settings);
    }

    /**
     * Global cache toggle.
     */
    private function isCacheEnabled(): bool
    {
        return \defined('MARADIGMA_CACHE_ENABLED') && MARADIGMA_CACHE_ENABLED === true;
    }

    /**
     * Small helper for "no-cache" mode or unexpected returns.
     *
     * @param mixed $result
     * @return array<string,mixed>
     */
    private function normalizeResult($result, string $mode = 'success'): array
    {
        if (\is_array($result)) {
            return $result;
        }

        // Keep your different payload conventions
        if ($mode === 'status') {
            return ['status' => 'error', 'data' => []];
        }

        return ['success' => false, 'data' => []];
    }

    /**
     * Generic transient getter used by REST/rendering helpers.
     *
     * @return mixed
     */
    public function get(string $key)
    {
        if (!$this->isCacheEnabled()) {
            return false;
        }

        return \get_transient($key);
    }

    /**
     * Generic transient setter used by REST/rendering helpers.
     *
     * @param mixed $value
     */
    public function set(string $key, $value, int $ttl): bool
    {
        if (!$this->isCacheEnabled()) {
            return false;
        }

        return \set_transient($key, $value, $ttl);
    }

    /**
     * Cached boats list for a given filter set.
     *
     * IMPORTANT:
     * - $filters must already be normalized/canonical (use Support\Sanitizer::normalizeBoatsSearchAtts()).
     * - Cache key is stable because filters are expected sorted (ksort).
     *
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public function getBoatsList(array $filters = []): array
    {
        // No-cache mode => direct call
        if (!$this->isCacheEnabled() || self::isPriceDebugRequest()) {
            return $this->normalizeResult($this->client->getBoatsList($filters), 'success');
        }

        $settings = SettingsPage::getSettings();

        // Ensure stable cache key
        \ksort($filters);

        $keyData = [
            'filters'  => $filters,
            'language' => \method_exists($this->client, 'getLanguage') ? $this->client->getLanguage() : '',
        ];

        $json         = \wp_json_encode($keyData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $transientKey = 'maradigma_boats_list_' . \md5($json ?: \serialize($keyData));

        $cached = \get_transient($transientKey);
        if (\is_array($cached)) {
            return $cached;
        }

        $result = $this->client->getBoatsList($filters);

        if (!empty($result['success']) && \is_array($result['data'] ?? null)) {
            \set_transient($transientKey, $result, 10 * MINUTE_IN_SECONDS);
        }

        return $this->normalizeResult($result, 'success');
    }

    private static function isPriceDebugRequest(): bool
    {
        // Read-only diagnostic flag; Debugger still controls whether output is recorded.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $enabled = isset($_GET['maradigma_price_debug'])
            && \sanitize_text_field((string) \wp_unslash($_GET['maradigma_price_debug'])) === '1';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        return $enabled;
    }

    /**
     * @param array<string,mixed> $options
     * @param array<int,string>   $requiredDataKeys  Keys que deben existir en result['data'] (ej: ['descriptions'])
     * @param bool                $forceRefresh      Si true, ignora caché
     * @return array<string,mixed>
     */
    public function getBoatDetails(
        string $boatIdOrSlug,
        string $language,
        array $options = [],
        array $requiredDataKeys = [],
        bool $forceRefresh = false
    ): array {

        if (!$this->isCacheEnabled()) {
            $result = $this->client->getBoatDetails(
                $boatIdOrSlug,
                array_merge(['language' => $language], $options)
            );
            return $this->normalizeResult($result, 'status');
        }

        // Normalizamos options para que la key sea estable
        $normalizedOptions = $options;

        if (isset($normalizedOptions['expand']) && is_array($normalizedOptions['expand'])) {
            $normalizedOptions['expand'] = array_values(array_unique(array_filter(array_map('trim', $normalizedOptions['expand']))));
            sort($normalizedOptions['expand']);
        }

        ksort($normalizedOptions);

        $keyData = [
            'boat'     => $boatIdOrSlug,
            'language' => $language,
            'options'  => $normalizedOptions,
        ];

        $transientKey = 'maradigma_boat_' . md5(wp_json_encode($keyData) ?: serialize($keyData));

        // ✅ Helper: valida si el payload tiene lo que necesitamos (y que no sea null/vacío)
        $hasRequired = static function (array $result) use ($requiredDataKeys): bool {
            if ($requiredDataKeys === []) return true;
            if (!is_array($result['data'] ?? null)) return false;

            foreach ($requiredDataKeys as $k) {
                if (!array_key_exists($k, $result['data'])) return false;

                $v = $result['data'][$k];

                // clave existe pero no sirve
                if ($v === null) return false;
                if (is_array($v) && $v === []) return false;
            }
            return true;
        };

        // ✅ Cache hit (solo uno)
        if (!$forceRefresh) {
            $cached = get_transient($transientKey);
            if (is_array($cached)) {
                if ($hasRequired($cached)) {
                    return $cached;
                }

                // Cache existe pero NO cumple requisitos => invalidamos
                delete_transient($transientKey);
                maradigma_debug_log('[Maradigma Cache] invalidated (missing/empty required keys): ' . $transientKey);
            }
        }

        // API call
        $result = $this->client->getBoatDetails(
            $boatIdOrSlug,
            array_merge(['language' => $language], $options)
        );

        if (($result['status'] ?? '') === 'success' && is_array($result['data'] ?? null)) {
            set_transient($transientKey, $result, 60 * MINUTE_IN_SECONDS);
        }

        return $this->normalizeResult($result, 'status');
    }

    /**
     * Cached service images for a specific service.
     *
     * @return array<string,mixed>
     */
    public function getServiceImages(
        string $group,
        string $identifier,
        string $language,
        bool $forceRefresh = false
    ): array {
        if (!$this->isCacheEnabled()) {
            $result = $this->client->getServiceImages($group, $identifier);
            return $this->normalizeResult($result, 'status');
        }

        $keyData = [
            'group' => $group,
            'identifier' => $identifier,
            'language' => $language,
        ];

        $transientKey = $this->buildTransientKey('maradigma_service_images_', $keyData);

        if (!$forceRefresh) {
            $cached = get_transient($transientKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $result = $this->client->getServiceImages($group, $identifier);

        if (($result['status'] ?? '') === 'success' && is_array($result['data'] ?? null)) {
            set_transient($transientKey, $result, 60 * MINUTE_IN_SECONDS);
        }

        return $this->normalizeResult($result, 'status');
    }

    /**
     * Cached service videos for a specific service.
     *
     * @return array<string,mixed>
     */
    public function getServiceVideos(
        string $group,
        string $identifier,
        string $language,
        bool $forceRefresh = false
    ): array {
        if (!$this->isCacheEnabled()) {
            $result = $this->client->getServiceVideos($group, $identifier);
            return $this->normalizeResult($result, 'status');
        }

        $keyData = [
            'group'      => $group,
            'identifier' => $identifier,
            'language'   => $language,
        ];

        $transientKey = $this->buildTransientKey('maradigma_service_videos_', $keyData);

        if (!$forceRefresh) {
            $cached = get_transient($transientKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $result = $this->client->getServiceVideos($group, $identifier);

        if (($result['status'] ?? '') === 'success' && is_array($result['data'] ?? null)) {
            set_transient($transientKey, $result, 60 * MINUTE_IN_SECONDS);
        }

        return $this->normalizeResult($result, 'status');
    }

    /**
     * Cached calendar (unavailability) for a service.
     *
     * @return array<string,mixed>
     */
    public function getServiceCalendar(string $group, string $identifier, string $language, array $query = [], bool $forceRefresh = false): array
    {
        if (!$this->isCacheEnabled()) {
            $result = $this->client->getServiceCalendar($group, $identifier, $query);
            return $this->normalizeResult($result, 'status');
        }

        ksort($query);

        $keyData = [
            'group'     => $group,
            'id'        => $identifier,
            'language'  => $language,
            'query'     => $query,
        ];

        $transientKey = $this->buildTransientKey('maradigma_service_calendar_', $keyData);

        if (!$forceRefresh) {
            $cached = get_transient($transientKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $result = $this->client->getServiceCalendar($group, $identifier, $query);

        if (($result['status'] ?? '') === 'success' && is_array($result['data'] ?? null)) {
            // Calendarios cambian, pero no cada segundo: 2 min OK
            set_transient($transientKey, $result, 2 * MINUTE_IN_SECONDS);
        }

        return $this->normalizeResult($result, 'status');
    }

    /**
     * Cached service types (e.g. getServiceTypes('boats')).
     *
     * @return array<string,mixed>
     */
    public function getServiceTypes(string $scope = 'boats', bool $forceRefresh = false): array
    {
        // No-cache mode => direct call
        if (!$this->isCacheEnabled()) {
            return $this->normalizeResult($this->client->getServiceTypes($scope), 'success');
        }

        $settings = SettingsPage::getSettings();

        $keyData = [
            'scope'   => $scope,
        ];

        $transientKey = $this->buildTransientKey('maradigma_service_types_', $keyData);

        if (!$forceRefresh) {
            $cached = \get_transient($transientKey);
            if (\is_array($cached)) {
                return $cached;
            }
        }

        $result = $this->client->getServiceTypes($scope);

        if ($this->isOkResultWithData($result)) {
            // Esto cambia muy poco => cache largo
            \set_transient($transientKey, $result, 12 * HOUR_IN_SECONDS);
        }

        return $this->normalizeResult($result, 'success');
    }

    /**
     * Cached boat builders list.
     *
     * @return array<string,mixed>
     */
    public function getBoatBuilders(bool $forceRefresh = false): array
    {
        // No-cache mode => direct call
        if (!$this->isCacheEnabled()) {
            return $this->normalizeResult($this->client->getBoatBuilders(), 'success');
        }

        $settings = SettingsPage::getSettings();

        $keyData = [];

        $transientKey = $this->buildTransientKey('maradigma_boat_builders_', $keyData);

        if (!$forceRefresh) {
            $cached = \get_transient($transientKey);
            if (\is_array($cached)) {
                return $cached;
            }
        }

        $result = $this->client->getBoatBuilders();

        if ($this->isOkResultWithData($result)) {
            \set_transient($transientKey, $result, 12 * HOUR_IN_SECONDS);
        }

        return $this->normalizeResult($result, 'success');
    }


    /**
     * Cached boat tags list.
     *
     * @return array<string,mixed>
     */
    public function getBoatTags(bool $forceRefresh = false): array
    {
        // No-cache mode => direct call
        if (!$this->isCacheEnabled()) {
            return $this->normalizeResult($this->client->getBoatTags(), 'success');
        }

        $settings = SettingsPage::getSettings();

        $keyData = [];

        $transientKey = $this->buildTransientKey('maradigma_boat_tags_', $keyData);

        if (!$forceRefresh) {
            $cached = \get_transient($transientKey);
            if (\is_array($cached)) {
                return $cached;
            }
        }

        $result = $this->client->getBoatTags();

        if ($this->isOkResultWithData($result)) {
            \set_transient($transientKey, $result, 12 * HOUR_IN_SECONDS);
        }

        return $this->normalizeResult($result, 'success');
    }

    /**
     * Cached boat base ports list.
     *
     * @return array<string,mixed>
     */
    public function getBoatBasePorts(bool $forceRefresh = false): array
    {
        // No-cache mode => direct call
        if (!$this->isCacheEnabled()) {
            return $this->normalizeResult($this->client->getBoatBasePorts(), 'success');
        }

        $settings = SettingsPage::getSettings();

        $keyData = [ ];

        $transientKey = $this->buildTransientKey('maradigma_boat_base_ports_', $keyData);

        if (!$forceRefresh) {
            $cached = \get_transient($transientKey);
            if (\is_array($cached)) {
                return $cached;
            }
        }

        $result = $this->client->getBoatBasePorts();

        if ($this->isOkResultWithData($result)) {
            \set_transient($transientKey, $result, 12 * HOUR_IN_SECONDS);
        }

        return $this->normalizeResult($result, 'success');
    }

    public static function flushAll(): void
    {
        global $wpdb;

        // 1) Single-site transients (wp_options)
        // Supports both legacy keys (maradigma_*) and CachePolicy keys (maradigma:*).
        $likeLegacy = $wpdb->esc_like('_transient_maradigma_') . '%';
        $likePolicy = $wpdb->esc_like('_transient_maradigma:') . '%';

        $rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $likeLegacy,
                $likePolicy
            )
        );

        if (is_array($rows) && $rows) {
            foreach ($rows as $optionName) {
                // option_name: _transient_{key}  OR  _transient_timeout_{key}
                if (strpos($optionName, '_transient_timeout_') === 0) {
                    $key = substr($optionName, strlen('_transient_timeout_'));
                    delete_transient($key);
                } elseif (strpos($optionName, '_transient_') === 0) {
                    $key = substr($optionName, strlen('_transient_'));
                    delete_transient($key);
                }
            }
        }

        // 2) Multisite: site transients (sitemeta)
        if (is_multisite()) {
            $likeSiteLegacy = $wpdb->esc_like('_site_transient_maradigma_') . '%';
            $likeSitePolicy = $wpdb->esc_like('_site_transient_maradigma:') . '%';

            $sitemeta = $wpdb->sitemeta;
            $rowsSite = $wpdb->get_col(
                $wpdb->prepare(
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core-owned table identifier; %i requires WordPress 6.2, while the plugin supports 6.0.
                    "SELECT meta_key FROM {$sitemeta} WHERE meta_key LIKE %s OR meta_key LIKE %s",
                    $likeSiteLegacy,
                    $likeSitePolicy
                )
            );

            if (is_array($rowsSite) && $rowsSite) {
                foreach ($rowsSite as $metaKey) {
                    if (strpos($metaKey, '_site_transient_timeout_') === 0) {
                        $key = substr($metaKey, strlen('_site_transient_timeout_'));
                        delete_site_transient($key);
                    } elseif (strpos($metaKey, '_site_transient_') === 0) {
                        $key = substr($metaKey, strlen('_site_transient_'));
                        delete_site_transient($key);
                    }
                }
            }
        }

        // 3) Opcional: si quieres limpiar TODO el object cache (ojo: global)
        // Si tu WP usa Redis Object Cache, esto vacía muchísimo más que Maradigma.
        // wp_cache_flush();
    }


    // -------------------------
    // Internals
    // -------------------------

    /**
     * @param string $prefix
     * @param array<string,mixed> $keyData
     */
    private function buildTransientKey(string $prefix, array $keyData): string
    {
        $json = \wp_json_encode($keyData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $prefix . \md5($json ?: \serialize($keyData));
    }

    /**
     * True when result is a "successful" payload and data is array.
     *
     * @param mixed $result
     */
    private function isOkResultWithData($result): bool
    {
        if (!\is_array($result)) {
            return false;
        }

        $ok = false;

        if (isset($result['success'])) {
            $ok = ($result['success'] === true || $result['success'] === 1 || $result['success'] === '1');
        } elseif (isset($result['status'])) {
            $ok = ((string)$result['status'] === 'success');
        }

        return $ok && \is_array($result['data'] ?? null);
    }
}

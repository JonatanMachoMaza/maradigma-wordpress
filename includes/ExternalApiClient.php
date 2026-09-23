<?php

declare(strict_types=1);

namespace Maradigma;
use Maradigma\Support\Debugger;
/**
 * Signs and sends requests to the Maradigma external API.
 */
class ExternalApiClient {
    private const BOAT_DETAILS_EXPAND_OPTIONS = [
        'service_accounting',
        'service_additional_services',
        'service_property_amenities',
        'service_admin_tools',
        'service_payment_methods',
        'service_descriptions',
        'service_destination',
        'service_destinations',
        'service_equipments',
        'service_group_category',
        'service_images',
        'service_ical',
        'service_pdf',
        'service_owner',
        'service_included_items',
        'service_not_included_items',
        'service_prices',
        'service_price_rates',
        'service_price_time_slots',
        'service_public_urls',
        'service_unavailability_dates',
        'service_real_unavailable_dates',
    ];

    private const BOAT_DETAILS_SCALAR_OPTIONS = [
        'accounting',
        'additionals',
        'additionals_build_html',
        'amenities',
        'admin_tools',
        'build_method_payment',
        'descriptions',
        'equipments',
        'gc_type',
        'gc_type_cache',
        'images',
        'ical',
        'only_load_cover_image',
        'url_images_main_domain',
        'owner',
        'included',
        'not_included',
        'prices',
        'price_rates',
        'price_time_slots',
        'website_urls',
    ];

    private string $baseUrl;
    private string $publicKey;   // X-API-KEY
    private string $secretKey;   // para X-SIGNATURE (HMAC-SHA256)
    private string $clientDomain;
    private string $language;
    private int $defaultTimeout;
    private int $lastStatusCode = 0;

    /**
     * Initializes the external API client.
     */
    public function __construct(
        string $baseUrl,
        string $publicKey,
        string $secretKey,
        string $clientDomain,
        ?string $language = null,
        int $defaultTimeout = 10
    ) {
        // Sin barra final, para luego añadir /auth, /services/boats, etc.
        $this->baseUrl       = rtrim($baseUrl, '/');
        $this->publicKey     = $publicKey;
        $this->secretKey     = $secretKey;
        $this->clientDomain  = $clientDomain;
        $this->language      = $language ?: get_locale(); // ej: es_ES → mandamos es-ES
        $this->defaultTimeout = $defaultTimeout;
    }

    /**
     * Locale sent as Accept-Language for a boat details language ("EN", "ca",
     * "es_ES"): the API takes the language from it and dates from its region.
     */
    public static function localeForDetailsLanguage(string $language): string
    {
        $language = trim($language);
        if (preg_match('/^[a-z]{2,3}[_-][a-z]{2}/i', $language) === 1) {
            return $language;
        }

        $locale = \Maradigma\Support\LocaleSwitcher::localeForLanguage($language, '');
        if ($locale === 'ca') {
            // WordPress's Catalan locale has no region.
            $locale = 'ca_ES';
        }

        return $locale !== '' ? $locale : strtolower($language);
    }

    /**
     * Returns the HTTP status of the last completed request on this client, or 0
     * when none completed.
     */
    public function getLastStatusCode(): int
    {
        return $this->lastStatusCode;
    }

    /**
     * Identifies the API connection (base URL and key) without exposing the key.
     */
    public function getSourceFingerprint(): string
    {
        return hash('sha256', strtolower($this->baseUrl) . '|' . trim($this->publicKey));
    }

    /**
     * Creates an API client from the stored plugin settings.
     */
    public static function fromSettings(array $settings): self
    {
        // Keys reales del SettingsPage
        $baseUrl   = (string) \Maradigma\Environment::getApiBaseUrl();
        $publicKey = (string) ($settings['external_api_key'] ?? '');
        $secretKey = (string) ($settings['api_secret'] ?? '');

        // Si no viene, lo calculamos (igual que SettingsPage::makeExternalApiClient)
        $clientDomain = (string) ($settings['client_domain'] ?? '');
        if ($clientDomain === '') {
            $clientDomain = (string) (wp_parse_url(home_url('/'), PHP_URL_HOST) ?? '');
        }

        $language = trim((string) ($settings['default_language'] ?? ''));
        if ($language === '') {
            $language = (string) get_locale();
        }

        return new self($baseUrl, $publicKey, $secretKey, $clientDomain, $language, 10);
    }

    /**
     * Sets language.
     */
    public function setLanguage(string $language): void
    {
        $language = trim($language);
        if ($language !== '') {
            $this->language = $language;
        }
    }

    /**
     * Returns language.
     */
    public function getLanguage(): string
    {
        return $this->language;
    }

    /**
     * @return array<int,string>
     */
    public static function getAllowedBoatDetailsExpandOptions(): array
    {
        return self::BOAT_DETAILS_EXPAND_OPTIONS;
    }

    /**
     * @return array<int,string>
     */
    public static function getAllowedBoatDetailsScalarOptions(): array
    {
        return self::BOAT_DETAILS_SCALAR_OPTIONS;
    }

    /**
     * @return array<int,string>
     */
    public static function normalizeBoatDetailsExpandOptions(mixed $expandRaw): array
    {
        if (\is_array($expandRaw)) {
            $tokens = $expandRaw;
        } elseif (\is_string($expandRaw)) {
            $tokens = \explode(',', $expandRaw);
        } else {
            return [];
        }

        $allowed = \array_flip(self::BOAT_DETAILS_EXPAND_OPTIONS);
        $out = [];

        foreach ($tokens as $token) {
            $key = \strtolower(\trim((string) $token));

            if ($key === '' || !\preg_match('/^[a-z0-9_]+$/', $key)) {
                continue;
            }

            if (!isset($allowed[$key]) || isset($out[$key])) {
                continue;
            }

            $out[$key] = $key;
        }

        return \array_values($out);
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public static function normalizeBoatDetailsOptions(array $options): array
    {
        $normalized = [];

        $expand = self::normalizeBoatDetailsExpandOptions($options['expand'] ?? null);
        if ($expand !== []) {
            $normalized['expand'] = $expand;
        }

        if (isset($options['fields']) && \is_string($options['fields'])) {
            $fields = \trim($options['fields']);
            if ($fields !== '') {
                $normalized['fields'] = $fields;
            }
        }

        $allowed = \array_flip(self::BOAT_DETAILS_SCALAR_OPTIONS);

        foreach ($options as $key => $value) {
            $key = \strtolower(\trim((string) $key));

            if (!isset($allowed[$key])) {
                continue;
            }

            if (\is_bool($value) || \is_int($value) || \is_float($value)) {
                $normalized[$key] = $value;
                continue;
            }

            if (!\is_string($value)) {
                continue;
            }

            $value = \trim($value);
            if ($value === '') {
                continue;
            }

            $lower = \strtolower($value);
            if (\in_array($lower, ['1', 'true', 'yes'], true)) {
                $normalized[$key] = 1;
                continue;
            }

            if (\in_array($lower, ['0', 'false', 'no'], true)) {
                $normalized[$key] = 0;
                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /**
     * Compatibility method used by Cache::getBoatsList().
     *
     * IMPORTANT:
     * This maps boats listing to your API:
     * - POST /search-services (form-encoded)
     * - id_group=boats enforced
     *
     * This method must return a stable shape:
     *   ['success' => bool, 'data' => array]
     *
     * And "data" MUST include (when available):
     * - search_result (array)
     * - total_results (int)  <-- total real del catálogo (no solo la página)
     * - limit (int)
     * - offset (int)
     * - calculated_page (int)
     *
     * @param array<string,mixed> $filters Normalized filters (canonical contract)
     * @return array<string,mixed>
     */
    public function getBoatsList(array $filters = []): array
    {
        // Enforce boats catalog if missing
        if (!isset($filters['id_group']) || (string)$filters['id_group'] === '') {
            $filters['id_group'] = 'boats';
        }

        if (!array_key_exists('only_calendarization', $filters)) {
            $filters['only_calendarization'] = false;
        } else {
            $bool = filter_var($filters['only_calendarization'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            $filters['only_calendarization'] = ($bool === null) ? false : $bool;
        }

        // Defaults coherentes con tu API (si no se pasan)
        if (!isset($filters['limit_services']) || !is_numeric($filters['limit_services'])) {
            $filters['limit_services'] = 10;
        }
        if (!isset($filters['offset_services']) || !is_numeric($filters['offset_services'])) {
            $filters['offset_services'] = 0;
        }

        // Normaliza por si te entra string
        $filters['limit_services']  = max(1, (int)$filters['limit_services']);
        $filters['offset_services'] = max(0, (int)$filters['offset_services']);

        try {
            // POST form + headers + signature
            $apiResponse = $this->searchServices($filters);

            if (!is_array($apiResponse)) {
                return [
                    'success' => false,
                    'data'    => [],
                    'error'   => 'Invalid API response type (expected array).',
                ];
            }

            /**
             * Tu API devuelve:
             *   ['status' => 'success', 'data' => [search_result=>[], total_results=>..., limit=>..., offset=>...]]
             */
            $status = isset($apiResponse['status']) ? (string)$apiResponse['status'] : '';
            $data   = (isset($apiResponse['data']) && is_array($apiResponse['data'])) ? $apiResponse['data'] : null;

            if ($status === 'success' && is_array($data)) {
                // Asegura shape estable y valores numéricos
                $normalized = [
                    'search_result'    => is_array($data['search_result'] ?? null) ? $data['search_result'] : [],
                    'total_results'    => isset($data['total_results']) ? (int)$data['total_results'] : 0,
                    'limit'            => isset($data['limit']) ? (int)$data['limit'] : (int)$filters['limit_services'],
                    'offset'           => isset($data['offset']) ? (int)$data['offset'] : (int)$filters['offset_services'],
                    'calculated_page'  => isset($data['calculated_page']) ? (int)$data['calculated_page'] : (
                        ((int)$filters['limit_services'] > 0)
                            ? (int)floor(((int)$filters['offset_services']) / ((int)$filters['limit_services'])) + 1
                            : 1
                    ),
                    // extra opcional si lo quieres conservar
                    'min_price'        => $data['min_price'] ?? null,
                    'max_price'        => $data['max_price'] ?? null,
                    'average_price'    => $data['average_price'] ?? null,
                    'available_boat_id_builders' => is_array($data['available_boat_id_builders'] ?? null) ? array_values(array_unique(array_filter(array_map('intval', $data['available_boat_id_builders']), static fn($id): bool => $id > 0))) : [],
                ];

                return [
                    'success' => true,
                    'data'    => $normalized,
                ];
            }

            // Errores típicos del backend
            $message = '';
            if (isset($apiResponse['message']) && is_string($apiResponse['message'])) {
                $message = $apiResponse['message'];
            } elseif (isset($apiResponse['error']) && is_string($apiResponse['error'])) {
                $message = $apiResponse['error'];
            }

            return [
                'success' => false,
                'data'    => [],
                'error'   => ($message !== '') ? $message : 'API returned non-success status.',
                'raw'     => $apiResponse,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'data'    => [],
                'error'   => $e->getMessage(),
            ];
        }
    }

    /**
     * Compatibility method used by Cache::getBoatDetails().
     *
     * This method maps $options to query params for:
     * GET /services/boats/{idOrSlug}?expand=...&images=1&only_load_cover_image=0...
     *
     * Rules:
     * - expand: array|string -> query['expand'] (CSV)
     * - bool/int(0|1)/"0"/"1": -> query[key]=0|1
     * - scalar string/int/float: -> query[key]=value
     * - arrays/objects are ignored by default (API currently expects expand + scalar flags).
     *
     * @param string $boatIdOrSlug
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function getBoatDetails(string $boatIdOrSlug, array $options = []): array
    {
        $prevLanguage = $this->language;

        if (isset($options['language']) && is_string($options['language']) && trim($options['language']) !== '') {
            $this->language = self::localeForDetailsLanguage($options['language']);
        }

        // 1) Build query dynamically from options
        $query = $this->buildBoatDetailsQueryFromOptions($options);

        try {
            return $this->getServiceByIdOrSlug('boats', $boatIdOrSlug, $query);
        } finally {
            $this->language = $prevLanguage;
        }
    }

    /**
     * Returns rental terms.
     */
    public function getRentalTerms(string $group, string $language = 'EN', bool $decodedHtml = true): array
    {
        $group = trim($group);
        $language = strtoupper(trim($language));

        if ($group === '') {
            throw new \RuntimeException('Missing required parameter: group.');
        }

        if ($language === '') {
            $language = 'EN';
        }

        $query = [
            'language'     => $language,
            'decoded_html' => $decodedHtml ? 'true' : 'false',
        ];

        return $this->requestJson(
            'GET',
            '/booking/rental-terms/' . rawurlencode($group),
            $query
        );
    }

    // ─────────────────────────────────────────────
    // Helpers internos
    // ─────────────────────────────────────────────

    /**
     * Build query params for getBoatDetails() from options.
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function buildBoatDetailsQueryFromOptions(array $options): array
    {
        $query = [];

        $normalizedExpand = [];

        // expand (array or string)
        if (array_key_exists('expand', $options)) {
            $normalizedExpand = self::normalizeBoatDetailsExpandOptions($options['expand']);

            if ($normalizedExpand !== []) {
                $query['expand'] = implode(',', $normalizedExpand);
            }
        }

        // ✅ Auto-enable absolute/main-domain image URLs when service_images is requested
        if (in_array('service_images', $normalizedExpand, true)) {
            $query['url_images_main_domain'] = 1;
        }

        // fields (string)
        if (isset($options['fields']) && is_string($options['fields']) && trim($options['fields']) !== '') {
            $query['fields'] = trim($options['fields']);
        }

        /**
         * Allowed scalar flags/params.
         * These match Service_GroupItem default_args and other "safe" query flags you may want.
         *
         * IMPORTANT:
         * - Keep this allowlist strict to avoid sending garbage to the API.
         * - Anything not in this list will be ignored (except expand/fields handled above).
         */
        $allowed = self::BOAT_DETAILS_SCALAR_OPTIONS;

        foreach ($allowed as $key) {
            if (!array_key_exists($key, $options)) {
                continue;
            }

            // ✅ Si ya lo hemos forzado automáticamente arriba, no lo pisamos aquí
            if (array_key_exists($key, $query)) {
                continue;
            }

            $value = $options[$key];

            if (is_bool($value)) {
                $query[$key] = $value ? 1 : 0;
                continue;
            }

            if (is_int($value)) {
                $query[$key] = $value;
                continue;
            }

            if (is_string($value)) {
                $v = trim($value);
                if ($v === '') {
                    continue;
                }

                $lower = strtolower($v);
                if ($lower === '1' || $lower === 'true' || $lower === 'yes') {
                    $query[$key] = 1;
                    continue;
                }
                if ($lower === '0' || $lower === 'false' || $lower === 'no') {
                    $query[$key] = 0;
                    continue;
                }

                $query[$key] = $v;
                continue;
            }
        }

        return $query;
    }

    /**
     * Normalizes language.
     */
    private function normaliseLanguage(string $locale): string
    {
        // es_ES → es-ES, en_US → en-US, etc.
        $parts = preg_split('/[_-]/', $locale);
        if (!$parts || count($parts) === 0) {
            return 'en-US';
        }
        if (count($parts) === 1) {
            return strtolower($parts[0]);
        }
        return strtolower($parts[0]) . '-' . strtoupper($parts[1]);
    }

    /**
     * Builds an absolute API URL with encoded query parameters.
     */
    private function buildUrl(string $path, array $query = []): string
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');

        if ($this->baseUrl === '' || !preg_match('#^https?://#i', $this->baseUrl)) {
            throw new \InvalidArgumentException(
                'Invalid API baseUrl. Expected absolute http(s) URL. Got: "' . esc_url_raw($this->baseUrl) . '"'
            );
        }

        if (!empty($query)) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return $url;
    }

    /**
     * Builds the HMAC signature for a request payload.
     */
    private function buildSignature(string $rawBody): string
    {
        return hash_hmac('sha256', $rawBody, $this->secretKey);
    }

    /**
     * Builds the signed HTTP headers required by the external API.
     */
    private function buildHeaders(string $rawBody, string $contentType): array
    {
        $acceptLanguage = $this->normaliseLanguage($this->language);

        return [
            'Accept'          => 'application/json',
            'Content-Type'    => $contentType,
            'X-API-KEY'       => $this->publicKey,
            'X-SIGNATURE'     => $this->buildSignature($rawBody),
            'X-Client-Domain' => $this->clientDomain,
            'Accept-Language' => $acceptLanguage,
        ];
    }

    /**
     * Helper generico bajo nivel.
     *
     * @throws RuntimeException
     */
    private function doRequest(
        string $method,
        string $path,
        array $query,
        string $rawBody,
        string $contentType,
        ?int $timeout = null
    ): array {
        $this->lastStatusCode = 0;
        $url     = $this->buildUrl($path, $query);
        $headers = $this->buildHeaders($rawBody, $contentType);

        $args = [
            'method'  => strtoupper($method),
            'headers' => $headers,
            'timeout' => $timeout ?? $this->defaultTimeout,
        ];

        if ($method !== 'GET') {
            $args['body'] = $rawBody;
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            throw new \RuntimeException('HTTP request error: ' . esc_html($response->get_error_message()));
        }

        $statusCode = (int) wp_remote_retrieve_response_code($response);
        $this->lastStatusCode = $statusCode;
        $body       = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            Debugger::log('external-api', 'invalid_json_response', [
                'method' => strtoupper($method),
                'path' => $path,
                'status_code' => $statusCode,
                'content_type' => (string) wp_remote_retrieve_header($response, 'content-type'),
                'body_excerpt' => self::makeLogExcerpt($body),
                'request' => self::sanitizeRequestForLog($rawBody, $contentType),
            ]);

            throw new \RuntimeException(
                sprintf(
                    'Invalid JSON response (%s): %s',
                    esc_html((string) $statusCode),
                    esc_html($body)
                )
            );
        }

        return $decoded;
    }

    /**
     * Enviar JSON (application/json; charset=utf-8).
     */
    private function requestJson(
        string $method,
        string $path,
        array $query = [],
        ?array $body = null,
        ?int $timeout = null
    ): array {
        $rawBody = $body !== null
            ? json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : '';

        return $this->doRequest(
            $method,
            $path,
            $query,
            $rawBody,
            'application/json; charset=utf-8',
            $timeout
        );
    }

    /**
     * Enviar formulario (application/x-www-form-urlencoded; charset=UTF-8).
     *
     * Esta es la que debes usar para:
     *  - POST /search-services
     *  - POST /booking/request
     *  - POST /booking/create-without-payment
     *  - POST /booking/online
     */
    private function requestForm(
        string $method,
        string $path,
        array $query = [],
        array $formData = [],
        ?int $timeout = null
    ): array {
        $rawBody = http_build_query($formData, '', '&', PHP_QUERY_RFC3986);

        return $this->doRequest(
            $method,
            $path,
            $query,
            $rawBody,
            'application/x-www-form-urlencoded; charset=UTF-8',
            $timeout
        );
    }

    /**
     * Creates log excerpt.
     */
    private static function makeLogExcerpt(string $text): string
    {
        $text = trim(wp_strip_all_tags($text));
        if ($text === '') {
            return '';
        }

        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, 800);
        }

        return substr($text, 0, 800);
    }

    /**
     * @return array<string,mixed>
     */
    private static function sanitizeRequestForLog(string $rawBody, string $contentType): array
    {
        if ($rawBody === '') {
            return [];
        }

        if (stripos($contentType, 'application/json') !== false) {
            $decoded = json_decode($rawBody, true);
            $payload = is_array($decoded) ? $decoded : ['raw' => self::makeLogExcerpt($rawBody)];
        } else {
            parse_str($rawBody, $payload);
            if (!is_array($payload)) {
                $payload = ['raw' => self::makeLogExcerpt($rawBody)];
            }
        }

        return self::redactSensitiveRequestValues($payload);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private static function redactSensitiveRequestValues(array $payload): array
    {
        $sensitive = [
            'first_name',
            'last_name',
            'email',
            'phone',
            'postcode',
            'city',
            'state',
            'message',
            'id_customer',
        ];

        foreach ($payload as $key => $value) {
            $keyString = is_string($key) ? $key : (string) $key;

            if (in_array($keyString, $sensitive, true)) {
                $payload[$key] = ($value === '' || $value === null) ? '' : '[redacted]';
                continue;
            }

            if (is_array($value)) {
                $payload[$key] = self::redactSensitiveRequestValues($value);
            }
        }

        return $payload;
    }

    // ─────────────────────────────────────────────
    // AUTH
    // ─────────────────────────────────────────────

    /**
     * GET /auth
     * Test de credenciales (lo que usa el botón "Guardar" del plugin)
     */
    public function testAuth(): array
    {
        // GET /auth, sin body → rawBody = ''
        return $this->requestJson('GET', '/auth', [], null, 5);
    }

    // ─────────────────────────────────────────────
    // SERVICES / SEARCH
    // ─────────────────────────────────────────────

    /**
     * POST /search-services
     *
     * IMPORTANT:
     * - This endpoint requires `application/x-www-form-urlencoded`.
     * - `$filters` should already be normalized/canonical (see Support\Sanitizer::normalizeBoatsSearchAtts()).
     *
     * Example response:
     * {
     *   "status": "success",
     *   "data": {
     *     "search_result": [ ... ],
     *     "min_price": "5000.00",
     *     "max_price": "20000.00",
     *     "average_price": "12000.00",
     *     "total_results": 1,
     *     "limit": 10,
     *     "offset": 0,
     *     "calculated_page": 1
     *   }
     * }
     *
     * @param array<string,mixed> $filters Canonical filters for /search-services.
     *
     * @return array{
     *   status?: 'success'|'error'|string,
     *   success?: bool,
     *   message?: string,
     *   data?: array{
     *     search_result?: array<int, array{
     *       id?: int,
     *       reference?: string,
     *       slug?: string,
     *       service_name?: string,
     *       boat_alias?: string,
     *       boat_model?: string,
     *       boat_builder?: string,
     *       boat_id_builder?: int,
     *       boat_year_construction?: int,
     *       boat_year_refit?: int,
     *       boat_length?: string|float|int,
     *       boat_beam?: string|float|int,
     *       boat_capacity?: int,
     *       boat_capacity_crew?: int,
     *       boat_capacity_pernocta?: int,
     *       boat_base_port_name?: string,
     *       base_price?: string|float|int,
     *       mandatory_skipper?: bool,
     *       is_bareboat?: bool,
     *       main_image?: string,
     *       images?: array<int, array{
     *         url?: string,
     *         url_sizes?: array<string,string>
     *       }>,
     *       is_owner?: int|bool,
     *       status?: int,
     *       ...<string,mixed>
     *     }>,
     *     min_price?: string|float|int,
     *     max_price?: string|float|int,
     *     average_price?: string|float|int,
     *     total_results?: int,
     *     limit?: int,
     *     offset?: int,
     *     calculated_page?: int
     *   }
     * }
     */
    public function searchServices(array $filters): array
    {
        return $this->requestForm(
            'POST',
            '/search-services',
            [],
            $filters
        );
    }

    /**
     * GET /services/{group}/types
     */
    public function getServiceTypes(string $group): array
    {
        return $this->requestJson(
            'GET',
            sprintf('/services/%s/types', rawurlencode($group))
        );
    }

    /**
     * GET /services/{group}/{identifier}
     *
     * Supports query params:
     * - expand=service_prices,service_images,...
     * - images=1
     * - fields[boats]=...
     *
     * @param array<string,mixed> $query
     */
    public function getServiceByIdOrSlug(string $group, string $identifier, array $query = []): array
    {
        return $this->requestJson(
            'GET',
            sprintf(
                '/services/%s/%s',
                rawurlencode($group),
                rawurlencode($identifier)
            ),
            $query
        );
    }

    /**
     * GET /services/{group}/{identifier}/prices
     */
    public function getServicePrices(string $group, string $identifier): array
    {
        return $this->requestJson(
            'GET',
            sprintf(
                '/services/%s/%s/prices',
                rawurlencode($group),
                rawurlencode($identifier)
            )
        );
    }

    /**
     * GET /services/{group}/{identifier}/videos
     */
    public function getServiceVideos(string $group, string $identifier): array
    {
        return $this->requestJson(
            'GET',
            sprintf(
                '/services/%s/%s/videos',
                rawurlencode($group),
                rawurlencode($identifier)
            )
        );
    }

    /**
     * GET /services/{group}/{identifier}/equipments
     */
    public function getServiceEquipments(string $group, string $identifier): array
    {
        return $this->requestJson(
            'GET',
            sprintf(
                '/services/%s/%s/equipments',
                rawurlencode($group),
                rawurlencode($identifier)
            )
        );
    }

    /**
     * GET /services/{group}/{identifier}/included
     */
    public function getServiceIncluded(string $group, string $identifier): array
    {
        return $this->requestJson(
            'GET',
            sprintf(
                '/services/%s/%s/included',
                rawurlencode($group),
                rawurlencode($identifier)
            )
        );
    }

    /**
     * GET /services/{group}/{identifier}/not-included
     */
    public function getServiceNotIncluded(string $group, string $identifier): array
    {
        return $this->requestJson(
            'GET',
            sprintf(
                '/services/%s/%s/not-included',
                rawurlencode($group),
                rawurlencode($identifier)
            )
        );
    }

    /**
     * GET /services/{group}/{identifier}/additional-services
     */
    public function getServiceAdditionalServices(string $group, string $identifier): array
    {
        return $this->requestJson(
            'GET',
            sprintf(
                '/services/%s/%s/additional-services',
                rawurlencode($group),
                rawurlencode($identifier)
            )
        );
    }

    /**
     * GET /services/{group}/{identifier}/images
     */
    public function getServiceImages(string $group, string $identifier): array
    {
        return $this->requestJson(
            'GET',
            sprintf(
                '/services/%s/%s/images',
                rawurlencode($group),
                rawurlencode($identifier)
            )
        );
    }

    /**
     * GET /services/{group}/{identifier}/descriptions
     */
    public function getServiceDescriptions(string $group, string $identifier): array
    {
        return $this->requestJson(
            'GET',
            sprintf(
                '/services/%s/%s/descriptions',
                rawurlencode($group),
                rawurlencode($identifier)
            )
        );
    }

    /**
     * GET /services/{group}/{identifier}/pdf
     */
    public function getServicePDF(string $group, string $identifier): array
    {
        return $this->requestJson(
            'GET',
            sprintf(
                '/services/%s/%s/pdf',
                rawurlencode($group),
                rawurlencode($identifier)
            )
        );
    }

    /**
     * GET /services/{group}/{identifier}/availability
     */
    public function getServiceAvailability(string $group, string $identifier): array
    {
        return $this->requestJson(
            'GET',
            sprintf(
                '/services/%s/%s/availability',
                rawurlencode($group),
                rawurlencode($identifier)
            )
        );
    }

    /**
     * GET /services/{group}/{identifier}/calendar
     */
    public function getServiceCalendar(string $group, string $identifier, array $query = []): array
    {
        return $this->requestJson(
            'GET',
            sprintf(
                '/services/%s/%s/calendar',
                rawurlencode($group),
                rawurlencode($identifier)
            ),
            $query
        );
    }

    /**
     * GET /services/{group}/{identifier}/booking-price?date_start=...&date_end=...
     */
    public function getServiceBookingPrice(
        string $group,
        string $identifier,
        string $dateStart,
        string $dateEnd
    ): array {
        $query = [
            'date_start' => $dateStart,
            'date_end'   => $dateEnd,
        ];

        return $this->requestJson(
            'GET',
            sprintf(
                '/services/%s/%s/booking-price',
                rawurlencode($group),
                rawurlencode($identifier)
            ),
            $query
        );
    }

    /**
     * GET /booking/service-price/{group}/{identifier}
     *
     * @param string $group
     * @param string $identifier
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    public function getServicePriceOnBooking(string $group, string $identifier, array $query): array
    {
        $dateStart = isset($query['date_start']) ? trim((string) $query['date_start']) : '';
        $dateEnd   = isset($query['date_end']) ? trim((string) $query['date_end']) : '';

        if ($dateStart === '' || $dateEnd === '') {
            throw new \InvalidArgumentException('Missing required query params: date_start, date_end');
        }

        foreach (['get_data_gi', 'customer_is_skipper'] as $boolKey) {
            if (!array_key_exists($boolKey, $query)) {
                continue;
            }

            $b = filter_var($query[$boolKey], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($b !== null) {
                $query[$boolKey] = $b ? 1 : 0;
            } else {
                unset($query[$boolKey]);
            }
        }

        try {
            $request = $this->requestJson(
                'GET',
                sprintf(
                    '/booking/service-price/%s/%s',
                    rawurlencode($group),
                    rawurlencode($identifier)
                ),
                $query
            );
            return $request;
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    // ─────────────────────────────────────────────
    // BOAT BUILDERS & BASE PORTS
    // ─────────────────────────────────────────────

    /**
     * GET /boat-builders
     */
    public function getBoatBuilders(): array
    {
        return $this->requestJson('GET', '/boat-builders');
    }

    /**
     * GET /boat-builder/{identifier}
     */
    public function getBoatBuilder(string $identifier): array
    {
        return $this->requestJson(
            'GET',
            sprintf('/boat-builder/%s', rawurlencode($identifier))
        );
    }

    /**
     * GET /boat-base-ports
     */
    public function getBoatBasePorts(): array
    {
        return $this->requestJson('GET', '/boat-base-ports');
    }

    /**
     * GET /boat-base-port/{identifier}
     */
    public function getBoatBasePort(string $identifier): array
    {
        return $this->requestJson(
            'GET',
            sprintf('/boat-base-port/%s', rawurlencode($identifier))
        );
    }

    // ─────────────────────────────────────────────
    // BOOKING & SHOP CART
    // ─────────────────────────────────────────────

    /**
     * POST /booking/request
     *
     * $booking, $customer y $additionalServices se mapean a:
     *  - booking[...]
     *  - customer[...]
     *  - additional_services[]
     */
    public function requestBooking(
        array $booking,
        array $customer = [],
        array $additionalServices = []
    ): array {
        $formData = [
            'booking'             => $booking,
            'customer'            => $customer,
            'additional_services' => $additionalServices,
        ];

        return $this->requestForm(
            'POST',
            '/booking/request',
            [],
            $formData
        );
    }

    /**
     * GET /booking/default-payment-method-online
     */
    public function getDefaultOnlinePaymentMethod(): array
    {
        return $this->requestJson(
            'GET',
            '/booking/default-payment-method-online'
        );
    }

    /**
     * POST /booking/online
     *
     * $payload debe incluir:
     *  - step
     *  - return_url_after_payment
     *  - payment_method (opcional, si no se manda usa el default en el backend)
     *  - booking[...] (id_group, id_group_item, dates, etc.)
     *  - customer[...] (datos cliente)
     *  - additional_services[] (opcional)
     *  - uuid_shop_cart (opcional para step=4)
     */
    public function bookingOnline(array $payload): array
    {
        // Aquí asumo que $payload ya viene con la estructura:
        // [
        //   'step' => 3,
        //   'return_url_after_payment' => 'https://...',
        //   'payment_method' => 'tpv',
        //   'booking' => [...],
        //   'customer' => [...],
        //   'additional_services' => [...],
        //   'uuid_shop_cart' => '...'
        // ]
        $requestForm = $this->requestForm(
            'POST',
            '/booking/online',
            [],
            $payload
        );

        return $requestForm;
    }

    /**
     * GET /shop-cart/{identifier}
     */
    public function validateShopCart(string $uuidShopCart): array
    {
        return $this->requestJson(
            'GET',
            sprintf('/shop-cart/%s', rawurlencode($uuidShopCart))
        );
    }
}

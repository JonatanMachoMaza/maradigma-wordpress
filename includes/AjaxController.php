<?php

declare(strict_types=1);

namespace Maradigma;

if (!defined('ABSPATH')) {
    exit;
}

use WP_REST_Request;
use WP_REST_Response;
use Maradigma\Settings;
use Maradigma\ExternalApiClient;
use Maradigma\Support\Debugger;
use Maradigma\Support\PublicBookingGuard;
use RuntimeException;

/**
 * Registers and handles the plugin's REST endpoints and AJAX actions.
 */
final class AjaxController
{
    /**
     * Registers the component's WordPress hooks.
     */
    public static function init(): void
    {
        // REST API (frontend / headless, etc.)
        add_action('rest_api_init', [__CLASS__, 'registerRoutes']);

        // AJAX para el admin (metabox de páginas con Select2)
        add_action('wp_ajax_maradigma_admin_search_boat_types', [__CLASS__, 'adminSearchBoatTypes']);
        add_action('wp_ajax_maradigma_admin_search_destinations', [__CLASS__, 'adminSearchDestinations']);
        add_action('wp_ajax_maradigma_admin_search_tags', [__CLASS__, 'adminSearchTags']);
        add_action('wp_ajax_maradigma_admin_search_builders', [__CLASS__, 'adminSearchBuilders']);
        add_action('wp_ajax_maradigma_admin_get_builder_by_id', [__CLASS__, 'adminGetBuilderById']);
        add_action('wp_ajax_maradigma_admin_search_boats', [__CLASS__, 'adminSearchBoats']);
        add_action('wp_ajax_maradigma_admin_get_boat_by_id', [__CLASS__, 'adminGetBoatById']);
        add_action('wp_ajax_maradigma_admin_search_base_ports', [__CLASS__, 'adminSearchBasePorts']);

        // Elementor widget
        add_action('wp_ajax_maradigma_elementor_search_boats', [__CLASS__, 'elementorSearchBoats']);
        add_action('wp_ajax_maradigma_elementor_search_boat_types', [__CLASS__, 'elementorSearchBoatTypes']);
        add_action('wp_ajax_maradigma_elementor_search_builders', [__CLASS__, 'elementorSearchBuilders']);
        add_action('wp_ajax_maradigma_elementor_search_destinations', [__CLASS__, 'elementorSearchDestinations']);
        add_action('wp_ajax_maradigma_get_boat_images_count', [__CLASS__, 'elementorGetBoatImagesCount']);

        add_action('wp_ajax_maradigma_front_search_boat_types', [__CLASS__, 'frontSearchBoatTypes']);
        add_action('wp_ajax_nopriv_maradigma_front_search_boat_types', [__CLASS__, 'frontSearchBoatTypes']);

        add_action('wp_ajax_maradigma_front_search_tags', [__CLASS__, 'frontSearchTags']);
        add_action('wp_ajax_nopriv_maradigma_front_search_tags', [__CLASS__, 'frontSearchTags']);

        add_action('wp_ajax_maradigma_front_search_builders', [__CLASS__, 'frontSearchBuilders']);
        add_action('wp_ajax_nopriv_maradigma_front_search_builders', [__CLASS__, 'frontSearchBuilders']);

        add_action('wp_ajax_maradigma_front_search_boats', [__CLASS__, 'frontSearchBoats']);
        add_action('wp_ajax_nopriv_maradigma_front_search_boats', [__CLASS__, 'frontSearchBoats']);

        add_action('wp_ajax_maradigma_front_search_base_ports', [__CLASS__, 'frontSearchBasePorts']);
        add_action('wp_ajax_nopriv_maradigma_front_search_base_ports', [__CLASS__, 'frontSearchBasePorts']);

        add_action('wp_ajax_maradigma_front_get_boat_by_id', [__CLASS__, 'frontGetBoatById']);
        add_action('wp_ajax_nopriv_maradigma_front_get_boat_by_id', [__CLASS__, 'frontGetBoatById']);

        add_action('wp_ajax_maradigma_frontend_log', [__CLASS__, 'frontendLog']);
        add_action('wp_ajax_nopriv_maradigma_frontend_log', [__CLASS__, 'frontendLog']);
    }

    /**
     * Handles the front-end end log request.
     */
    public static function frontendLog(): void
    {
        if (!check_ajax_referer('wp_rest', 'nonce', false)) {
            wp_send_json_error(
                [
                    'logged'  => 0,
                    'message' => __('Security check failed.', 'maradigma'),
                ],
                403
            );
        }

        $context = isset($_POST['context']) ? sanitize_key((string) wp_unslash($_POST['context'])) : 'frontend';
        $message = isset($_POST['message']) ? sanitize_text_field((string) wp_unslash($_POST['message'])) : '';
        $status = isset($_POST['status']) ? sanitize_text_field((string) wp_unslash($_POST['status'])) : '';
        $url = isset($_POST['url']) ? esc_url_raw((string) wp_unslash($_POST['url'])) : '';

        if ($message !== '') {
            Debugger::log('frontend-errors', 'frontend_error', [
                'context' => $context,
                'message' => function_exists('mb_substr') ? mb_substr($message, 0, 500) : substr($message, 0, 500),
                'status' => function_exists('mb_substr') ? mb_substr($status, 0, 32) : substr($status, 0, 32),
                'url' => function_exists('mb_substr') ? mb_substr($url, 0, 300) : substr($url, 0, 300),
            ]);
        }

        wp_send_json_success(['logged' => $message !== '' ? 1 : 0]);
    }

    // ─────────────────────────────────────────────
    // REST ROUTES
    // ─────────────────────────────────────────────

    /**
     * Extract the actual boat object from the different response shapes the API
     * can return for /services/boats/{id}. Some responses wrap the record in
     * data.data; using the outer data array makes the UI fall back to "Boat #ID".
     *
     * @param array<string,mixed> $response
     * @return array<string,mixed>
     */
    private static function extractBoatDataFromServiceResponse(array $response): array
    {
        $candidates = [];

        if (isset($response['data']['data']) && is_array($response['data']['data'])) {
            $candidates[] = $response['data']['data'];
        }

        if (isset($response['data']['data']['search_result'][0]) && is_array($response['data']['data']['search_result'][0])) {
            $candidates[] = $response['data']['data']['search_result'][0];
        }

        if (isset($response['data']['data'][0]) && is_array($response['data']['data'][0])) {
            $candidates[] = $response['data']['data'][0];
        }

        if (isset($response['data']['search_result'][0]) && is_array($response['data']['search_result'][0])) {
            $candidates[] = $response['data']['search_result'][0];
        }

        if (isset($response['data'][0]) && is_array($response['data'][0])) {
            $candidates[] = $response['data'][0];
        }

        if (isset($response['data']) && is_array($response['data'])) {
            $candidates[] = $response['data'];
        }

        if (isset($response['search_result'][0]) && is_array($response['search_result'][0])) {
            $candidates[] = $response['search_result'][0];
        }

        $candidates[] = $response;

        $boatKeys = [
            'id_group_item',
            'id_gi',
            'id',
            'boat_builder',
            'boat_model',
            'boat_modal',
            'boat_alias',
            'service_name',
            'name',
            'title',
        ];

        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            foreach ($boatKeys as $key) {
                if (array_key_exists($key, $candidate) && trim((string) $candidate[$key]) !== '') {
                    return $candidate;
                }
            }
        }

        return [];
    }

    /**
     * @param array<string,mixed> $boat
     */
    private static function formatBoatSelectLabel(array $boat, string $fallbackId): string
    {
        $builder = trim((string) ($boat['boat_builder'] ?? ''));
        $model   = trim((string) ($boat['boat_model'] ?? $boat['boat_modal'] ?? ''));
        $alias   = trim((string) ($boat['boat_alias'] ?? ''));
        $fallbackName = trim((string) ($boat['service_name'] ?? $boat['name'] ?? $boat['title'] ?? ''));

        $parts = [];

        if ($builder !== '') {
            $parts[] = $builder;
        }

        if ($model !== '') {
            $parts[] = $model;
        }

        if ($alias !== '') {
            $parts[] = $alias;
        }

        if (!empty($parts)) {
            return implode(' - ', $parts);
        }

        if ($fallbackName !== '') {
            return $fallbackName;
        }

        return 'Boat #' . $fallbackId;
    }

    /**
     * Registers routes.
     */
    public static function registerRoutes(): void
    {

        register_rest_route(
            'maradigma/v1',
            '/countries',
            [
                'methods'             => 'GET',
                'callback'            => [__CLASS__, 'handleCountriesGet'],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            'maradigma/v1',
            '/quote',
            [
                'methods'             => 'POST',
                'callback'            => [__CLASS__, 'handleQuote'],
                'permission_callback' => '__return_true',
            ]
        );

        \register_rest_route(
            'maradigma/v1',
            '/boats-archive',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [__CLASS__, 'handleBoatsArchive'],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            'maradigma/v1',
            '/boats/(?P<id>[\d]+)',
            [
                'methods'             => 'GET',
                'callback'            => [__CLASS__, 'handleBoatGet'],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            'maradigma/v1',
            '/calendar',
            [
                'methods'             => 'GET',
                'callback'            => [__CLASS__, 'handleCalendarGet'],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            'maradigma/v1',
            '/boat/price-on-booking',
            [
                'methods'             => 'POST',
                'callback'            => [__CLASS__, 'handleBoatPriceOnBooking'],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            'maradigma/v1',
            '/booking/security-token',
            [
                'methods'             => 'GET',
                'callback'            => [PublicBookingGuard::class, 'refreshNonce'],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            'maradigma/v1',
            '/booking',
            [
                'methods'             => 'POST',
                'callback'            => [__CLASS__, 'handleBooking'],
                'permission_callback' => [PublicBookingGuard::class, 'authorize'],
            ]
        );

        register_rest_route(
            'maradigma/v1',
            '/booking/online',
            [
                'methods'             => 'POST',
                'callback'            => [__CLASS__, 'handleBookingOnline'],
                'permission_callback' => [PublicBookingGuard::class, 'authorize'],
            ]
        );

        register_rest_route(
            'maradigma/v1',
            '/shop-cart/(?P<uuid>[0-9a-zA-Z_-]+)',
            [
                'methods' => 'GET',
                'callback' => [__CLASS__, 'handleShopCartValidate'],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            'maradigma/v1',
            '/booking/rental-terms/(?P<group>[A-Za-z0-9_-]+)',
            [
                'methods'             => 'GET',
                'callback'            => [__CLASS__, 'handleRentalTermsGet'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    /**
     * Crea un cliente de API usando la configuración guardada en ajustes.
     *
     * @throws RuntimeException
     */
    private static function createApiClient(): ExternalApiClient
    {
        // Centralizamos toda la lógica en SettingsPage::makeExternalApiClient()
        return SettingsPage::makeExternalApiClient();
    }

    /**
     * Normalizes request language candidate.
     */
    private static function normalizeRequestLanguageCandidate(string $language): string
    {
        $language = strtolower(trim(str_replace('-', '_', $language)));
        if ($language === '') {
            return '';
        }

        $base = preg_split('/[_]/', $language)[0] ?? '';
        $supported = [
            'en' => 'en_GB',
            'es' => 'es_ES',
            // The external API does not expose Catalan booking messages; use Spanish instead of sending "ca".
            'ca' => 'es_ES',
            'fr' => 'fr_FR',
            'de' => 'de_DE',
            'it' => 'it_IT',
            'nl' => 'nl_NL',
        ];

        return $supported[$base] ?? '';
    }

    /**
     * Detects language from URL.
     */
    private static function detectLanguageFromUrl(string $url): string
    {
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        if ($path === '') {
            return '';
        }

        $segments = array_values(array_filter(explode('/', trim($path, '/'))));
        return self::normalizeRequestLanguageCandidate((string) ($segments[0] ?? ''));
    }

    /**
     * Detects request language.
     */
    private static function detectRequestLanguage(
        string $acceptLanguage,
        string $pllCookie,
        string $preferredLanguage = '',
        string $returnUrl = ''
    ): string
    {
        // 1) Language sent by the frontend/page context.
        $preferred = self::normalizeRequestLanguageCandidate($preferredLanguage);
        if ($preferred !== '') {
            return $preferred;
        }

        // 2) Language inferred from the return URL, e.g. /en/...
        $urlLanguage = self::detectLanguageFromUrl($returnUrl);
        if ($urlLanguage !== '') {
            return $urlLanguage;
        }

        // 3) cookie Polylang (si existe)
        $pllCookie = strtolower(trim($pllCookie));
        if ($pllCookie !== '') {
            $cookieLanguage = self::normalizeRequestLanguageCandidate($pllCookie);
            if ($cookieLanguage !== '') {
                return $cookieLanguage;
            }
        }

        // 4) Accept-Language header
        // "es-ES,es;q=0.9" -> "es-ES"
        $first = trim(explode(',', $acceptLanguage)[0] ?? '');
        if ($first !== '') {
            $headerLanguage = self::normalizeRequestLanguageCandidate($first);
            if ($headerLanguage !== '') {
                return $headerLanguage;
            }
        }

        // 5) fallback WP
        $wpLanguage = self::normalizeRequestLanguageCandidate((string) determine_locale());
        return $wpLanguage !== '' ? $wpLanguage : 'en_GB';
    }

    /**
     * GET /wp-json/maradigma/v1/countries?lang=ES
     *
     * Returns a normalized list of countries for a selectbox:
     * [
     *   { iso2: "ES", name: "España", calling_code: "34" },
     *   ...
     * ]
     */
    public static function handleCountriesGet(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $lang = (string) $request->get_param('lang');
            $lang = strtoupper(trim($lang)) !== '' ? strtoupper(trim($lang)) : 'EN';

            // Normalize language to the dataset "translations" keys
            // Dataset example keys: es, fr, de, it, nl, pt, pt-BR, etc.
            $langKey = strtolower($lang);

            // Some common normalizations
            if ($langKey === 'en') $langKey = 'en'; // usually not present; will fallback to name
            if ($langKey === 'ca') $langKey = 'ca'; // if not present, will fallback

            $dataset = self::getCountriesDataset();
            if ($dataset === []) {
                return new WP_REST_Response(
                    ['success' => false, 'data' => [], 'message' => __('Countries dataset not found.', 'maradigma')],
                    500
                );
            }

            $out = [];

            foreach ($dataset as $c) {
                if (!is_array($c)) continue;

                $iso2 = isset($c['iso2']) ? strtoupper(trim((string) $c['iso2'])) : '';
                if ($iso2 === '') continue;

                // Name fallback chain:
                // 1) translations[langKey]
                // 2) translations['en'] (rarely present)
                // 3) name
                $name = '';
                if (isset($c['translations']) && is_array($c['translations'])) {
                    $tr = $c['translations'];

                    if (isset($tr[$langKey]) && is_string($tr[$langKey]) && trim($tr[$langKey]) !== '') {
                        $name = trim($tr[$langKey]);
                    } elseif (isset($tr['en']) && is_string($tr['en']) && trim($tr['en']) !== '') {
                        $name = trim($tr['en']);
                    }
                }

                if ($name === '') {
                    $name = isset($c['name']) ? trim((string) $c['name']) : '';
                }

                if ($name === '') continue;

                // Phone code normalization: "34" or "+358-18" etc.
                $phone = isset($c['phone_code']) ? (string) $c['phone_code'] : '';
                $phone = trim($phone);
                if ($phone !== '') {
                    // keep only digits, + and hyphen; then remove spaces
                    $phone = preg_replace('/[^\d\+\-]/', '', $phone) ?? '';
                }

                $out[] = [
                    'iso2'         => $iso2,
                    'name'         => $name,
                    'calling_code' => $phone,
                ];
            }

            // Sort by translated name (locale-aware sorting if available)
            usort($out, static function (array $a, array $b): int {
                return strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
            });

            return new WP_REST_Response(
                [
                    'success' => true,
                    'lang'    => $lang,
                    'data'    => $out,
                ],
                200
            );
        } catch (\Throwable $e) {
            return new WP_REST_Response(
                [
                    'success' => false,
                    'error'   => [
                        'code'    => 'countries_error',
                        'message' => $e->getMessage(),
                    ],
                ],
                500
            );
        }
    }

    /**
     * Loads the countries dataset from plugin assets/data/countries.json
     *
     * Expected formats supported:
     * 1) Array root: [ {...}, {...} ]
     * 2) Object root: { "items": [ {...}, {...} ] }
     *
     * @return array<int, array<string, mixed>>
     */
    private static function getCountriesDataset(): array
    {
        // Preferred: define this constant in your plugin bootstrap
        $basePath = MARADIGMA_PLUGIN_DIR;

        $file = rtrim($basePath, '/\\') . '/assets/data/countries.json';

        if (!is_file($file) || !is_readable($file)) {
            return [];
        }

        static $cached = null;
        if (is_array($cached)) {
            return $cached;
        }

        $raw = file_get_contents($file);
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        // Remove UTF-8 BOM if present
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            // Support { items: [...] }
            if (is_array($decoded) && isset($decoded['items']) && is_array($decoded['items'])) {
                $decoded = $decoded['items'];
            }

            if (!is_array($decoded)) {
                return [];
            }

            // Ensure numeric array of arrays
            $items = [];
            foreach ($decoded as $row) {
                if (is_array($row)) {
                    $items[] = $row;
                }
            }

            $cached = $items;
            return $cached;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * POST /wp-json/maradigma/v1/quote
     *
     * Calcula un "quote" para un barco concreto y rango de fechas,
     * usando el endpoint /services/boats/{id}/booking-price.
     */
    public static function handleQuote(WP_REST_Request $request): WP_REST_Response
    {
        $params = $request->get_json_params() ?? [];

        $boatId   = isset($params['boat_id']) ? (string) $params['boat_id'] : '';
        $fromDate = isset($params['from_date']) ? (string) $params['from_date'] : '';
        $toDate   = isset($params['to_date']) ? (string) $params['to_date'] : '';
        $people   = isset($params['people']) ? (int) $params['people'] : 0; // por si lo necesitas en el futuro

        try {
            if ($boatId === '' || $fromDate === '' || $toDate === '') {
                throw new \RuntimeException(__('Missing required parameters: boat_id, from_date, to_date.', 'maradigma'));
            }

            $client = self::createApiClient();

            // Map a tu External API:
            // GET /services/boats/{boatId}/booking-price?date_start=...&date_end=...
            $result = $client->getServiceBookingPrice('boats', $boatId, $fromDate, $toDate);

            // Si quieres, puedes añadir el número de personas a la respuesta:
            $result['requested_people'] = $people;

            return new WP_REST_Response($result, 200);
        } catch (\Throwable $e) {
            return new WP_REST_Response(
                [
                    'success' => false,
                    'error'   => [
                        'code'    => 'quote_error',
                        'message' => $e->getMessage(),
                    ],
                ],
                500
            );
        }
    }
    /**
     * GET /wp-json/maradigma/v1/boats-archive
     *
     * Returns the rendered archive results and pagination HTML for the boats archive.
     *
     * IMPORTANT:
     * - The frontend JS sends UI params using the "md_" prefix:
     *   md_page, md_term, md_boat_capacity, md_featured, md_ins_book, md_order_by,
     *   md_min_price, md_max_price, md_boat_type_id, md_builders, md_ids_gi,
     *   md_date_start, md_date_end, md_tags.
     * - For compatibility, this callback also accepts the non-prefixed variants:
     *   page, term, boat_capacity, featured, ins_book, order_by, min_price,
     *   max_price, boat_type_id, builders, ids_gi, date_start, date_end, tags.
     * - The callback reuses ShortcodeRegistry::buildBoatsArchiveContext() so the
     *   AJAX archive stays fully aligned with the server-side render.
     *
     * DESIGN NOTES:
     * - id_group defaults to "boats" and should not be required in the public URL.
     * - date_picker_mode defaults to "range" and should not be required in the public URL.
     * - offset_services defaults to "0" and is resolved again by the archive context.
     * - archive_base_url is optional and is used only to build clean pagination links.
     * - card and image_token are optional internal context values for archive rendering.
     *
     * Query params accepted:
     * - archive_base_url
     * - archive_scope (signed attributes fixed by the shortcode author; ignored unless the signature matches)
     * - limit_services
     * - offset_services
     * - card
     * - image_token
     * - id_group
     * - date_picker_mode
     * - filters_ui_fields
     * - filters_ui_fields_left
     * - filters_ui_fields_right
     * - filters_ui_fields_offcanvas
     * - filters_ui_layout
     * - filters_ui_submit_mode
     * - filters_ui_show_reset
     * - show_more_filters_button
     * - more_filters_button_text
     * - more_filters_offcanvas_title
     * - md_page | page
     * - md_term | term
     * - md_boat_capacity | boat_capacity
     * - md_featured | featured
     * - md_ins_book | ins_book
     * - md_order_by | order_by
     * - md_min_price | min_price
     * - md_max_price | max_price
     * - md_boat_type_id | boat_type_id
     * - md_builders | builders
     * - md_ids_gi | ids_gi
     * - md_date_start | date_start
     * - md_date_end | date_end
     * - md_tags | tags
     * - md_boat_cabins | boat_cabins
     * - md_boat_bathrooms | boat_bathrooms
     * - md_min_boat_length | min_boat_length
     * - md_max_boat_length | max_boat_length
     * - md_boat_skipper_option | boat_skipper_option
     *
     * Response shape:
     * {
     *   success: true,
     *   data: {
     *     results_html: string,
     *     pagination_html: string,
     *     current_page: int,
     *     total_pages: int,
     *     total_results: int
     *   }
     * }
     *
     * @param \WP_REST_Request $request REST request instance.
     *
     * @return \WP_REST_Response
     */
    public static function handleBoatsArchive(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            /**
             * Read a request param supporting both:
             * - prefixed UI key: md_*
             * - plain fallback key
             *
             * Example:
             * - md_page -> page
             * - md_term -> term
             *
             * @param string $prefixedKey Full prefixed key, e.g. "md_page".
             * @param string $fallbackKey Plain fallback key, e.g. "page".
             * @param string $defaultValue Default string value.
             *
             * @return string
             */
            $readParam = static function (
                string $prefixedKey,
                string $fallbackKey,
                string $defaultValue = ''
            ) use ($request): string {
                $prefixedValue = $request->get_param($prefixedKey);
                if ($prefixedValue !== null && !\is_array($prefixedValue)) {
                    return \trim((string) $prefixedValue);
                }

                $fallbackValue = $request->get_param($fallbackKey);
                if ($fallbackValue !== null && !\is_array($fallbackValue)) {
                    return \trim((string) $fallbackValue);
                }

                return $defaultValue;
            };

            /**
             * Read a simple non-UI request param.
             *
             * @param string $key Request param key.
             * @param string $defaultValue Default string value.
             *
             * @return string
             */
            $readSimpleParam = static function (
                string $key,
                string $defaultValue = ''
            ) use ($request): string {
                $value = $request->get_param($key);

                if ($value === null || \is_array($value)) {
                    return $defaultValue;
                }

                return \trim((string) $value);
            };

            /**
             * Normalize a positive integer string.
             *
             * @param string $value Raw numeric string.
             * @param string $defaultValue Default numeric string.
             * @param int    $min Minimum accepted integer value.
             *
             * @return string
             */
            $normalizePositiveIntString = static function (
                string $value,
                string $defaultValue,
                int $min = 0
            ): string {
                $value = \trim($value);

                if ($value === '' || !\preg_match('/^\d+$/', $value)) {
                    return $defaultValue;
                }

                $intValue = (int) $value;
                if ($intValue < $min) {
                    $intValue = $min;
                }

                return (string) $intValue;
            };

            $archiveBaseUrl = $readSimpleParam('archive_base_url', '');
            $requestLanguage = $readParam('md_lang', 'lang', '');

            /*
         * Archive configuration / SSR-compatible shortcode attributes.
         *
         * These values are consumed by ShortcodeRegistry::buildBoatsArchiveContext().
         * Keep the defaults here conservative and aligned with the frontend JS.
         */
            $atts = [
                'id_group'                     => $readSimpleParam('id_group', 'boats'),
                'lang'                         => $requestLanguage,
                'current_lang'                 => $requestLanguage,
                'md_lang'                      => $requestLanguage,
                'limit_services'               => $normalizePositiveIntString($readSimpleParam('limit_services', '10'), '10', 1),
                'offset_services'              => $normalizePositiveIntString($readSimpleParam('offset_services', '0'), '0', 0),
                'card'                         => $readSimpleParam('card', ''),
                'image_token'                  => $readSimpleParam('image_token', ''),
                'allow_url_filters'            => '0',
                'show_filters'                 => '0',
                'autosubmit_filters'           => '0',
                'filters_ui_fields'            => $readSimpleParam('filters_ui_fields', ''),
                'filters_ui_fields_left'       => $readSimpleParam('filters_ui_fields_left', ''),
                'filters_ui_fields_right'      => $readSimpleParam('filters_ui_fields_right', ''),
                'filters_ui_fields_offcanvas'  => $readSimpleParam('filters_ui_fields_offcanvas', ''),
                'filters_ui_layout'            => $readSimpleParam('filters_ui_layout', 'horizontal'),
                'filters_ui_submit_mode'       => $readSimpleParam('filters_ui_submit_mode', 'auto'),
                'filters_ui_show_reset'        => $readSimpleParam('filters_ui_show_reset', '1'),
                'show_more_filters_button'     => $readSimpleParam('show_more_filters_button', '0'),
                'more_filters_button_text'     => $readSimpleParam('more_filters_button_text', ''),
                'more_filters_offcanvas_title' => $readSimpleParam('more_filters_offcanvas_title', ''),
                'date_picker_mode'             => $readSimpleParam('date_picker_mode', 'range'),

                /*
             * Also populate normalized filter attrs directly so the archive context
             * can work even if some integrator reuses them outside the md_* UI flow.
             */
                'term'          => $readParam('md_term', 'term', ''),
                'boat_capacity' => $readParam('md_boat_capacity', 'boat_capacity', ''),
                'featured'      => $readParam('md_featured', 'featured', ''),
                'ins_book'      => $readParam('md_ins_book', 'ins_book', ''),
                'order_by'      => $readParam('md_order_by', 'order_by', ''),
                'min_price'     => $readParam('md_min_price', 'min_price', ''),
                'max_price'     => $readParam('md_max_price', 'max_price', ''),
                'boat_type_id'  => $readParam('md_boat_type_id', 'boat_type_id', ''),
                'builders'      => $readParam('md_builders', 'builders', ''),
                'builders_options' => $readSimpleParam('builders_options', 'api'),
                'ids_gi'        => $readParam('md_ids_gi', 'ids_gi', ''),
                'date_start'    => $readParam('md_date_start', 'date_start', ''),
                'date_end'      => $readParam('md_date_end', 'date_end', ''),
                'tags'          => $readParam('md_tags', 'tags', ''),
                'boat_cabins'         => $readParam('md_boat_cabins', 'boat_cabins', ''),
                'boat_bathrooms'      => $readParam('md_boat_bathrooms', 'boat_bathrooms', ''),
                'min_boat_length'     => $readParam('md_min_boat_length', 'min_boat_length', ''),
                'max_boat_length'     => $readParam('md_max_boat_length', 'max_boat_length', ''),
                'boat_skipper_option' => $readParam('md_boat_skipper_option', 'boat_skipper_option', ''),
            ];

            /*
         * UI query used by getUiQuery() and by buildBoatsArchiveContext()
         * to resolve the current page and preserve the frontend state.
         *
         * IMPORTANT:
         * - md_page must be read from md_page first.
         * - fallback to page only for compatibility.
         */
            $uiQuery = [
                'md_page'          => $normalizePositiveIntString($readParam('md_page', 'page', '1'), '1', 1),
                'md_term'          => $readParam('md_term', 'term', ''),
                'md_boat_capacity' => $readParam('md_boat_capacity', 'boat_capacity', ''),
                'md_featured'      => $readParam('md_featured', 'featured', ''),
                'md_ins_book'      => $readParam('md_ins_book', 'ins_book', ''),
                'md_order_by'      => $readParam('md_order_by', 'order_by', ''),
                'md_min_price'     => $readParam('md_min_price', 'min_price', ''),
                'md_max_price'     => $readParam('md_max_price', 'max_price', ''),
                'md_boat_type_id'  => $readParam('md_boat_type_id', 'boat_type_id', ''),
                'md_builders'      => $readParam('md_builders', 'builders', ''),
                'md_ids_gi'        => $readParam('md_ids_gi', 'ids_gi', ''),
                'md_date_start'    => $readParam('md_date_start', 'date_start', ''),
                'md_date_end'      => $readParam('md_date_end', 'date_end', ''),
                'md_tags'          => $readParam('md_tags', 'tags', ''),
                'md_boat_cabins'         => $readParam('md_boat_cabins', 'boat_cabins', ''),
                'md_boat_bathrooms'      => $readParam('md_boat_bathrooms', 'boat_bathrooms', ''),
                'md_min_boat_length'     => $readParam('md_min_boat_length', 'min_boat_length', ''),
                'md_max_boat_length'     => $readParam('md_max_boat_length', 'max_boat_length', ''),
                'md_boat_skipper_option' => $readParam('md_boat_skipper_option', 'boat_skipper_option', ''),
                'md_lang'          => $requestLanguage,
            ];

            $archiveContext = \Maradigma\ShortcodeRegistry::buildBoatsArchiveContext(
                $atts,
                $uiQuery,
                $readSimpleParam('archive_scope', '')
            );

            if (empty($archiveContext)) {
                return new \WP_REST_Response(
                    [
                        'success' => false,
                        'error'   => [
                            'code'    => 'empty_archive_context',
                            'message' => __('Archive context could not be built.', 'maradigma'),
                        ],
                    ],
                    500
                );
            }

            /*
         * Inject optional clean base URL into the archive context so pagination HTML
         * can be generated without leaking internal AJAX-only params.
         */
            if ($archiveBaseUrl !== '') {
                $archiveContext['archive_base_url'] = $archiveBaseUrl;
            }

            // This request is answered in the site locale; render the texts in the language of the page.
            $renderLanguage = (string) ($archiveContext['current_lang'] ?? $requestLanguage);

            $resultsHtml    = \Maradigma\Support\LocaleSwitcher::run(
                $renderLanguage,
                static fn (): string => \Maradigma\ShortcodeRegistry::renderBoatsArchiveResults($archiveContext)
            );
            $paginationHtml = \Maradigma\Support\LocaleSwitcher::run(
                $renderLanguage,
                static fn (): string => \Maradigma\ShortcodeRegistry::renderBoatsArchivePagination($archiveContext)
            );

            return new \WP_REST_Response(
                [
                    'success' => true,
                    'data'    => [
                        'results_html'    => $resultsHtml,
                        'pagination_html' => $paginationHtml,
                        'current_page'    => (int) ($archiveContext['page'] ?? 1),
                        'total_pages'     => (int) ($archiveContext['total_pages'] ?? 1),
                        'total_results'   => (int) ($archiveContext['total_results'] ?? 0),
                    ],
                ],
                200
            );
        } catch (\Throwable $e) {
            return new \WP_REST_Response(
                [
                    'success' => false,
                    'error'   => [
                        'code'    => 'boats_archive_error',
                        'message' => $e->getMessage(),
                    ],
                ],
                500
            );
        }
    }

    /**
     * Handles boat get.
     */
    public static function handleBoatGet(WP_REST_Request $request): WP_REST_Response
    {
        $boatId = (string) $request->get_param('id');
        if ($boatId === '') {
            return new WP_REST_Response(['success' => false, 'message' => 'Missing boat id'], 400);
        }

        $expandRaw = (string) $request->get_param('expand');
        $expandArr = [];

        if ($expandRaw !== '') {
            $expandArr = array_values(array_filter(array_map(
                static fn($v) => trim((string) $v),
                explode(',', $expandRaw)
            )));
        }

        $lang = (string) $request->get_param('lang');
        $lang = $lang !== '' ? strtoupper($lang) : 'EN';

        // ✅ Usa Cache (firma correcta)
        $cache = new \Maradigma\Cache();

        $options = [];
        if ($expandArr !== []) {
            $options['expand'] = $expandArr;
        }

        $result = $cache->getBoatDetails(
            $boatId,
            $lang,
            $options,
            [],     // requiredKeys
            false   // forceRefresh
        );

        return new WP_REST_Response($result, 200);
    }

    /**
     * GET /wp-json/maradigma/v1/calendar?boat=...&months=12&include_booking_status=1
     *
     * Proxies the External API "service calendar" endpoint for frontend usage without exposing
     * API secrets in the browser.
     *
     * This endpoint returns unavailable date ranges for the requested boat/service.
     * It is meant to be consumed by the frontend calendar widget (shortcode JS).
     *
     * Query parameters:
     * - boat (string) REQUIRED
     *   Boat identifier. Can be numeric id or slug depending on your external API routing.
     *
     * - months (int) OPTIONAL (default: 12, clamp: 1..24)
     *   Used to constrain the returned date window (optional optimization).
     *   If omitted, the external API will still return future ranges from today onward.
     *
     * - start_month (string) OPTIONAL ("current"|"next", default: "current")
     *   Used together with months to compute the date window.
     *
     * - include_booking_status (int) OPTIONAL (0|1, default: 1)
     *   If 0, booking status will be requested as null/omitted (privacy-friendly).
     *
     * Response:
     * {
     *   "success": true,
     *   "data": {
     *     "items": [
     *       { "type": "booking|unavailability|ical", "date_start":"YYYY-MM-DD", "date_end":"YYYY-MM-DD", "status": 1|null }
     *     ]
     *   }
     * }
     *
     * Notes:
     * - Uses {@see \Maradigma\Cache::getServiceCalendar()} for caching, if enabled.
     * - Language is derived from request headers/cookies similarly to bookingOnline handler.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function handleCalendarGet(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $boat = (string) $request->get_param('boat');
            $boat = trim($boat);

            if ($boat === '') {
                return new WP_REST_Response(
                    [
                        'success' => false,
                        'error'   => [
                            'code'    => 'missing_boat',
                            'message' => __('Missing required parameter: boat.', 'maradigma'),
                        ],
                    ],
                    400
                );
            }

            // months (optional, used for window optimization on the frontend)
            $months = (int) $request->get_param('months');
            if ($months <= 0) $months = 12;
            if ($months > 24) $months = 24;

            $startMonth = (string) $request->get_param('start_month');
            $startMonth = ($startMonth === 'next') ? 'next' : 'current';

            // Privacy: booking status exposure
            $includeBookingStatus = (int) $request->get_param('include_booking_status');
            $includeBookingStatus = ($includeBookingStatus === 0) ? 0 : 1;

            // Prefer the locale emitted by the shortcode/widget so anonymous and logged-in
            // visitors hit the same calendar language/cache key.
            $reqLang = trim((string) ($request->get_param('locale') ?: $request->get_param('lang') ?: ''));

            if ($reqLang === '') {
                // Fallback for older cached markup/JS.
                $accept    = (string) $request->get_header('accept-language');
                $pllCookie = isset($_COOKIE['pll_language'])
                    ? sanitize_key((string) wp_unslash($_COOKIE['pll_language']))
                    : '';
                $reqLang   = self::detectRequestLanguage($accept, $pllCookie); // e.g. es_ES
            }

            $reqLang = preg_replace('/[^A-Za-z_-]/', '', $reqLang) ?: 'en_US';

            // Convert "es_ES" to "ES" for your Cache::getBoatDetails usage pattern
            $langParts = preg_split('/[_-]/', $reqLang);
            $lang2     = strtoupper((string)($langParts[0] ?? 'EN'));
            if ($lang2 === '') $lang2 = 'EN';

            // Optional: compute a date window to potentially reduce results in the future
            // (Your current External API calendar endpoint does not accept date filters yet,
            //  so we only forward include_booking_status for now.)
            $query = [
                'include_booking_status' => $includeBookingStatus,
            ];

            $cache = new \Maradigma\Cache();

            // External API is grouped by "boats" here
            $api = $cache->getServiceCalendar('boats', $boat, $lang2, $query);
            $ok = is_array($api)
                && (($api['status'] ?? '') === 'success')
                && is_array(($api['data'] ?? null));

            if (!$ok) {
                return new WP_REST_Response(
                    [
                        'success' => false,
                        'error'   => [
                            'code'    => 'calendar_unavailable',
                            'message' => __('Calendar not available.', 'maradigma'),
                            'raw'     => $api,
                        ],
                    ],
                    502
                );
            }

            // We only return what the frontend needs (stable contract).
            $data  = (array) $api['data'];
            $items = isset($data['items']) && is_array($data['items']) ? $data['items'] : [];

            return new WP_REST_Response(
                [
                    'success' => true,
                    'data'    => [
                        'items' => $items,
                        'meta'  => [
                            'boat' => $boat,
                            'months' => $months,
                            'start_month' => $startMonth,
                            'include_booking_status' => $includeBookingStatus,
                            'lang' => $lang2,
                            'locale' => $reqLang,
                        ],
                    ],
                ],
                200
            );
        } catch (\Throwable $e) {
            return new WP_REST_Response(
                [
                    'success' => false,
                    'error'   => [
                        'code'    => 'calendar_error',
                        'message' => $e->getMessage(),
                    ],
                ],
                500
            );
        }
    }

    /**
     * POST /wp-json/maradigma/v1/boat/price-on-booking
     *
     * Calculates the booking price for a specific boat and date range using:
     * - External API: GET /booking/service-price/{group}/{identifier}
     * - Backend handler: External_API_BookingController::getServicePriceOnBooking()
     *
     * Expected JSON body:
     * {
     *   "boat_id": "732",
     *   "from_date": "2026-05-21",
     *   "to_date": "2026-05-25",
     *   "id_time_slot": 3,                       // optional
     *   "customer_is_skipper": true,             // optional
     *   "get_data_gi": false,                    // optional
     *   "selected_id_additional_services": [1,2] // optional (array or csv string)
     * }
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function handleBoatPriceOnBooking(WP_REST_Request $request): WP_REST_Response
    {
        $params = $request->get_json_params() ?? [];

        $boatId   = isset($params['boat_id']) ? trim((string) $params['boat_id']) : '';
        $fromDate = isset($params['from_date']) ? trim((string) $params['from_date']) : '';
        $toDate   = isset($params['to_date']) ? trim((string) $params['to_date']) : '';

        try {
            if ($boatId === '' || $fromDate === '' || $toDate === '') {
                throw new \RuntimeException(__('Missing required parameters: boat_id, from_date, to_date.', 'maradigma'));
            }

            $query = [
                'date_start' => $fromDate,
                'date_end'   => $toDate,
            ];

            // Optional params (pass-through; ExternalApiClient normalizes)
            if (array_key_exists('id_time_slot', $params)) {
                $query['id_time_slot'] = $params['id_time_slot'];
            }
            if (array_key_exists('customer_is_skipper', $params)) {
                $query['customer_is_skipper'] = $params['customer_is_skipper'];
            }
            if (array_key_exists('get_data_gi', $params)) {
                $query['get_data_gi'] = $params['get_data_gi'];
            }
            if (array_key_exists('selected_id_additional_services', $params)) {
                $query['selected_id_additional_services'] = $params['selected_id_additional_services'];
            }
            if (array_key_exists('selected_additional_services', $params)) {
                $query['selected_additional_services'] = $params['selected_additional_services'];
            }

            $client = self::createApiClient();

            // Calls your External API client method you asked for
            $result = $client->getServicePriceOnBooking('boats', $boatId, $query);

            return new WP_REST_Response($result, 200);
        } catch (\Throwable $e) {
            return new WP_REST_Response(
                [
                    'success' => false,
                    'error'   => [
                        'code'    => 'boat_price_on_booking_error',
                        'message' => $e->getMessage(),
                    ],
                ],
                500
            );
        }
    }

    /**
     * POST /wp-json/maradigma/v1/booking
     *
     * Crea una reserva SIN pago (ejemplo) usando createBookingWithoutPayment().
     * Si en el futuro quieres usar bookingOnline, sólo hay que mapear el payload.
     */
    public static function handleBooking(WP_REST_Request $request): WP_REST_Response
    {
        $params = $request->get_json_params() ?? [];
        $claim = PublicBookingGuard::beginIdempotentWrite($request);
        if ($claim instanceof \WP_Error) {
            $errorData = $claim->get_error_data();

            return new WP_REST_Response(
                [
                    'success' => false,
                    'error' => [
                        'code' => $claim->get_error_code(),
                        'message' => $claim->get_error_message(),
                    ],
                ],
                (int) ($errorData['status'] ?? 409)
            );
        }
        if (($claim['state'] ?? '') === 'replay') {
            $response = new WP_REST_Response($claim['response'] ?? null, (int) ($claim['status'] ?? 200));
            $response->header('X-Maradigma-Idempotent-Replay', 'true');

            return $response;
        }

        $boatId   = isset($params['boat_id']) ? (string) $params['boat_id'] : '';
        $fromDate = isset($params['from_date']) ? (string) $params['from_date'] : '';
        $toDate   = isset($params['to_date']) ? (string) $params['to_date'] : '';
        $people   = isset($params['people']) ? (int) $params['people'] : 0;

        $customer = [
            'first_name' => isset($params['first_name']) ? (string) $params['first_name'] : '',
            'last_name'  => isset($params['last_name']) ? (string) $params['last_name'] : '',
            'email'      => isset($params['email']) ? (string) $params['email'] : '',
            'phone'      => isset($params['phone']) ? (string) $params['phone'] : '',
        ];

        $acceptTerms = !empty($params['accept_terms']);

        try {
            if ($boatId === '' || $fromDate === '' || $toDate === '') {
                throw new \RuntimeException(__('Missing required parameters: boat_id, from_date, to_date.', 'maradigma'));
            }

            if (!$acceptTerms) {
                throw new \RuntimeException(__('You must accept terms and conditions to complete the booking.', 'maradigma'));
            }

            $client = self::createApiClient();

            // Mapeo mínimo para el backend:
            $booking = [
                'id_group'      => 'boats',
                'id_group_item' => $boatId,
                'date_start'    => $fromDate,
                'date_end'      => $toDate,
                'people'        => $people,
            ];

            // Sin servicios adicionales de momento:
            $additionalServices = [];

            $result = $client->createBookingWithoutPayment($booking, $customer, $additionalServices);
            if (PublicBookingGuard::isSuccessfulResult($result)) {
                PublicBookingGuard::completeIdempotentWrite($claim, $request, $result);
            } else {
                PublicBookingGuard::abortIdempotentWrite($claim);
            }

            return new WP_REST_Response($result, 200);
        } catch (\Throwable $e) {
            PublicBookingGuard::abortIdempotentWrite($claim);

            return new WP_REST_Response(
                [
                    'success' => false,
                    'error'   => [
                        'code'    => 'booking_error',
                        'message' => $e->getMessage(),
                    ],
                ],
                500
            );
        }
    }

    /**
     * Handles booking online.
     */
    public static function handleBookingOnline(WP_REST_Request $request): WP_REST_Response
    {
        $params = $request->get_json_params() ?? [];
        $claim = PublicBookingGuard::beginIdempotentWrite($request);
        if ($claim instanceof \WP_Error) {
            $errorData = $claim->get_error_data();

            return new WP_REST_Response(
                [
                    'success' => false,
                    'error' => [
                        'code' => $claim->get_error_code(),
                        'message' => $claim->get_error_message(),
                    ],
                ],
                (int) ($errorData['status'] ?? 409)
            );
        }
        if (($claim['state'] ?? '') === 'replay') {
            $response = new WP_REST_Response($claim['response'] ?? null, (int) ($claim['status'] ?? 200));
            $response->header('X-Maradigma-Idempotent-Replay', 'true');

            return $response;
        }

        try {
            $step = isset($params['step']) ? (int) $params['step'] : 0;
            if ($step < 1) {
                throw new \RuntimeException(__('Missing or invalid parameter: step.', 'maradigma'));
            }

            $returnUrl = isset($params['return_url_after_payment']) ? (string) $params['return_url_after_payment'] : '';
            $returnUrl = self::sanitizeReturnUrlAfterPayment($returnUrl);

            if ($step === 3 && $returnUrl === '') {
                throw new \RuntimeException(__('return_url_after_payment is required.', 'maradigma'));
            }

            $post = [
                'step' => $step,
                'return_url_after_payment' => $returnUrl,
                'payment_method' => (string)($params['payment_method'] ?? ''),
                'uuid_shop_cart' => (string)($params['uuid_shop_cart'] ?? ''),
                'id_time_slot' => (string)($params['id_time_slot'] ?? ''),

                'booking' => [
                    'execute' => ($step === 4) ? '1' : '0',
                    'id_group' => (string)($params['id_group'] ?? ''),
                    'id_group_item' => (string)($params['id_group_item'] ?? ''),
                    'date_start' => (string)($params['date_start'] ?? ''),
                    'date_end' => (string)($params['date_end'] ?? ''),
                    'time_start' => (string)($params['time_start'] ?? ''),
                    'time_end' => (string)($params['time_end'] ?? ''),
                    'id_time_slot' => (string)($params['id_time_slot'] ?? ''),
                    'pax' => (string)($params['pax'] ?? ''),
                    'pax_children' => (string)($params['pax_children'] ?? ''),
                    'customer_is_skipper' => (string)($params['customer_is_skipper'] ?? ''),
                    'message' => (string)($params['message'] ?? ''),
                ],

                'customer' => [
                    'id_customer' => (string)($params['id_customer'] ?? ''),
                    'country_code' => (string)($params['country_code'] ?? ''),
                    'state' => (string)($params['state'] ?? ''),
                    'postcode' => (string)($params['postcode'] ?? ''),
                    'city' => (string)($params['city'] ?? ''),
                    'first_name' => (string)($params['first_name'] ?? ''),
                    'last_name' => (string)($params['last_name'] ?? ''),
                    'email' => (string)($params['email'] ?? ''),
                    'phone' => (string)($params['phone'] ?? ''),
                ],
            ];

            if (array_key_exists('accept_terms', $params)) {
                $post['accept_terms'] = !empty($params['accept_terms']) ? '1' : '0';
            }

            foreach (['price', 'vat_percent', 'vat_price', 'total_price'] as $moneyKey) {
                if (array_key_exists($moneyKey, $params)) {
                    $post[$moneyKey] = (string) $params[$moneyKey];
                }
            }

            $add = $params['selected_id_additional_services'] ?? [];
            if (is_array($add) && $add !== []) {
                $post['additional_services'] = array_map('strval', $add);
                $post['selected_id_additional_services'] = array_map('strval', $add);
            }

            $client = self::createApiClient();

            $accept    = (string) $request->get_header('accept-language');
            $pllCookie = isset($_COOKIE['pll_language'])
                ? sanitize_key((string) wp_unslash($_COOKIE['pll_language']))
                : '';
            $payloadLanguage = (string) ($params['language'] ?? $params['lang'] ?? '');
            $reqLang   = self::detectRequestLanguage($accept, $pllCookie, $payloadLanguage, $returnUrl);

            maradigma_debug_log('[handleBookingOnline] Accept-Language header: ' . $accept);
            maradigma_debug_log('[handleBookingOnline] pll_language cookie: ' . $pllCookie);
            maradigma_debug_log('[handleBookingOnline] payload language: ' . $payloadLanguage);
            maradigma_debug_log('[handleBookingOnline] detected request language: ' . $reqLang);

            $client->setLanguage($reqLang);

            maradigma_debug_log('[handleBookingOnline] language sent to external API: ' . $reqLang);
            Debugger::log('booking-online', 'booking_online_request', [
                'step' => $step,
                'language' => $reqLang,
                'payload_language' => $payloadLanguage,
                'id_group' => $post['booking']['id_group'],
                'id_group_item' => $post['booking']['id_group_item'],
                'date_start' => $post['booking']['date_start'],
                'date_end' => $post['booking']['date_end'],
                'time_start' => $post['booking']['time_start'],
                'time_end' => $post['booking']['time_end'],
                'id_time_slot' => $post['booking']['id_time_slot'],
                'pax' => $post['booking']['pax'],
                'pax_children' => $post['booking']['pax_children'],
                'additional_services_count' => isset($post['additional_services']) && is_array($post['additional_services']) ? count($post['additional_services']) : 0,
                'has_uuid_shop_cart' => $post['uuid_shop_cart'] !== '' ? 1 : 0,
                'has_customer' => $post['customer']['email'] !== '' || $post['customer']['phone'] !== '' ? 1 : 0,
            ]);

            $result = $client->bookingOnline($post);
            if (PublicBookingGuard::isSuccessfulResult($result)) {
                PublicBookingGuard::completeIdempotentWrite($claim, $request, $result);
            } else {
                PublicBookingGuard::abortIdempotentWrite($claim);
            }

            return new WP_REST_Response($result, 200);
        } catch (\Throwable $e) {
            PublicBookingGuard::abortIdempotentWrite($claim);
            maradigma_debug_log('[handleBookingOnline] ERROR: ' . $e->getMessage());
            Debugger::log('booking-online', 'booking_online_error', [
                'message' => $e->getMessage(),
                'type' => $e::class,
            ]);

            return new WP_REST_Response([
                'success' => false,
                'error' => [
                    'code' => 'booking_online_error',
                    'message' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * Sanitizes return URL after payment.
     */
    private static function sanitizeReturnUrlAfterPayment(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            return '';
        }

        $url = esc_url_raw($url, ['http', 'https']);
        $url = wp_validate_redirect($url, '');

        if ($url === '') {
            return '';
        }

        $parts = wp_parse_url($url);

        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }

        $path = $parts['path'] ?? '/';
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        $cleanUrl = $parts['scheme'] . '://' . $parts['host'];

        if (!empty($parts['port'])) {
            $cleanUrl .= ':' . $parts['port'];
        }

        $cleanUrl .= $path;

        return $cleanUrl;
    }

    /**
     * Handles shop cart validate.
     */
    public static function handleShopCartValidate(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $uuid = (string)$request->get_param('uuid');
            if ($uuid === '') {
                return new WP_REST_Response(['success' => false, 'message' => 'Missing uuid'], 400);
            }

            $client = self::createApiClient();
            $result = $client->validateShopCart($uuid);

            return new WP_REST_Response($result, 200);
        } catch (\Throwable $e) {
            return new WP_REST_Response([
                'success' => false,
                'error' => [
                    'code' => 'shop_cart_validate_error',
                    'message' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * Handles rental terms get.
     */
    public static function handleRentalTermsGet(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $group = trim((string) $request->get_param('group'));

            if ($group === '') {
                return new WP_REST_Response(
                    [
                        'success' => false,
                        'error'   => [
                            'code'    => 'missing_group',
                            'message' => __('Missing required parameter: group.', 'maradigma'),
                        ],
                    ],
                    400
                );
            }

            $language = trim((string) $request->get_param('language'));
            if ($language === '') {
                $accept    = (string) $request->get_header('accept-language');
                $pllCookie = isset($_COOKIE['pll_language'])
                    ? sanitize_key((string) wp_unslash($_COOKIE['pll_language']))
                    : '';
                $reqLang   = self::detectRequestLanguage($accept, $pllCookie);

                $langParts = preg_split('/[_-]/', $reqLang);
                $language  = strtoupper((string) ($langParts[0] ?? 'EN'));
            } else {
                $language = strtoupper($language);
            }

            $decodedHtmlRaw = $request->get_param('decoded_html');
            $decodedHtml = filter_var($decodedHtmlRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            $decodedHtml = ($decodedHtml === null) ? true : $decodedHtml;

            $client = self::createApiClient();
            $result = $client->getRentalTerms($group, $language, $decodedHtml);

            $isSuccess =
                is_array($result) &&
                (
                    (($result['status'] ?? '') === 'success') ||
                    (($result['success'] ?? false) === true)
                );

            if (!$isSuccess) {
                $message =
                    $result['message'] ??
                    $result['error']['message'] ??
                    __('Rental terms not found.', 'maradigma');

                $statusCode = 404;

                return new WP_REST_Response(
                    [
                        'success' => false,
                        'error'   => [
                            'code'    => 'rental_terms_not_found',
                            'message' => (string) $message,
                        ],
                    ],
                    $statusCode
                );
            }

            $data = [];
            if (isset($result['data']) && is_array($result['data'])) {
                $data = $result['data'];
            }

            $rentalTermsRaw = (string) ($data['rental_terms'] ?? '');

            /*
         * Normalize HTML content:
         * - Some sources may return escaped HTML entities (&lt;p&gt;...&lt;/p&gt;)
         * - Some sources may already return raw HTML
         * - We decode entities only once and then sanitize allowed markup
         */
            $rentalTermsNormalized = $rentalTermsRaw;

            if ($rentalTermsNormalized !== '') {
                $rentalTermsNormalized = html_entity_decode(
                    $rentalTermsNormalized,
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8'
                );

                $rentalTermsNormalized = wp_kses(
                    $rentalTermsNormalized,
                    [
                        'p'      => ['class' => true, 'style' => true],
                        'br'     => [],
                        'strong' => [],
                        'b'      => [],
                        'em'     => [],
                        'i'      => [],
                        'u'      => [],
                        'ul'     => ['class' => true],
                        'ol'     => ['class' => true],
                        'li'     => ['class' => true],
                        'a'      => [
                            'href'   => true,
                            'title'  => true,
                            'target' => true,
                            'rel'    => true,
                            'class'  => true,
                        ],
                        'span'   => ['class' => true, 'style' => true],
                        'div'    => ['class' => true, 'style' => true],
                        'h1'     => ['class' => true],
                        'h2'     => ['class' => true],
                        'h3'     => ['class' => true],
                        'h4'     => ['class' => true],
                        'h5'     => ['class' => true],
                        'h6'     => ['class' => true],
                    ]
                );
            }

            $data['id_group']      = (string) ($data['id_group'] ?? $group);
            $data['language']      = (string) ($data['language'] ?? $language);
            $data['decoded_html']  = (bool) $decodedHtml;
            $data['rental_terms']  = $rentalTermsNormalized;

            return new WP_REST_Response(
                [
                    'success' => true,
                    'status'  => 'success',
                    'data'    => $data,
                ],
                200
            );
        } catch (\Throwable $e) {
            return new WP_REST_Response(
                [
                    'success' => false,
                    'error'   => [
                        'code'    => 'rental_terms_error',
                        'message' => $e->getMessage(),
                    ],
                ],
                500
            );
        }
    }

    // ─────────────────────────────────────────────
    // HELPERS COMUNES PARA AJAX ADMIN
    // ─────────────────────────────────────────────

    /**
     * Seguridad básica para las llamadas AJAX de admin (Select2 en metabox).
     */
    private static function checkAdminAjaxSecurity(): void
    {
        if (!current_user_can('edit_pages')) {
            wp_send_json([
                'success' => false,
                'results' => [],
                'error'   => [
                    'code'    => 'forbidden',
                    'message' => __('You are not allowed to perform this action.', 'maradigma'),
                ],
            ]);
        }

        if (!check_ajax_referer('maradigma_admin', 'nonce', false)) {
            wp_send_json([
                'success' => false,
                'results' => [],
                'error'   => [
                    'code'    => 'bad_nonce',
                    'message' => __('Invalid security token.', 'maradigma'),
                ],
            ], 400);
        }
    }

    /**
     * Normaliza el parámetro "q" (texto de búsqueda) desde $_REQUEST.
     */
    private static function getSearchTerm(): string
    {
        // Callers validate the corresponding public or admin AJAX nonce first.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $q = isset($_REQUEST['q'])
            ? sanitize_text_field((string) wp_unslash($_REQUEST['q']))
            : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        return trim($q);
    }

    /**
     * Devuelve la página para paginación (1, 2, 3...).
     */
    private static function getPageNumber(): int
    {
        // Callers validate the corresponding public or admin AJAX nonce first.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = isset($_REQUEST['page']) ? absint(wp_unslash($_REQUEST['page'])) : 1;
        return ($page > 0) ? $page : 1;
    }

    // ─────────────────────────────────────────────
    // AJAX ADMIN: BOAT TYPES
    // ─────────────────────────────────────────────

    /**
     * action: maradigma_admin_search_boat_types
     *
     * Devuelve tipos de servicio "boats" en formato Select2:
     * { results: [ {id, text}, ... ], pagination: { more: bool } }
     */
    public static function adminSearchBoatTypes(): void
    {
        self::checkAdminAjaxSecurity();

        $q    = self::getSearchTerm();
        $page = self::getPageNumber();

        try {
            $client   = self::createApiClient();
            $response = $client->getServiceTypes('boats');

            // Ejemplo esperado:
            // [
            //   'success' => true,
            //   'data'   => [
            //      ['id' => 3, 'name' => 'Motorboats'],
            //      ...
            //   ]
            // ]
            $items = [];
            $types = $response['data'] ?? [];

            foreach ($types as $type) {
                $id   = isset($type['id']) ? (int) $type['id'] : 0;
                $name = isset($type['name']) ? (string) $type['name'] : '';

                if ($id <= 0 || $name === '') {
                    continue;
                }

                if ($q !== '' && stripos($name, $q) === false) {
                    continue;
                }

                $items[] = [
                    'id'   => $id,
                    'text' => $name,
                ];
            }

            wp_send_json([
                'success'    => true,
                'results'    => $items,
                'pagination' => [
                    'more' => false, // si en el futuro paginas tipos, aquí lo controlas
                ],
            ]);
        } catch (\Throwable $e) {
            wp_send_json([
                'success' => false,
                'results' => [],
                'error'   => [
                    'code'    => 'api_error',
                    'message' => $e->getMessage(),
                ],
            ]);
        }
    }

    /**
     * action: maradigma_admin_search_destinations
     *
     * Destinations a boat listing can be limited to (block editor), in Select2 format:
     * { success: true, results: [ {id:'destination:{id}', text}, ... ], pagination: { more: false } }
     */
    public static function adminSearchDestinations(): void
    {
        self::checkAdminAjaxSecurity();

        $q = self::getSearchTerm();

        try {
            $items = [];

            foreach (\Maradigma\Support\BoatDestinationCatalog::search(self::getBoatDestinationCatalog(), $q) as $destination) {
                $items[] = [
                    'id'   => $destination['value'],
                    'text' => self::formatDestinationLabel($destination),
                ];
            }

            wp_send_json([
                'success'    => true,
                'results'    => $items,
                'pagination' => ['more' => false],
            ]);
        } catch (\Throwable $e) {
            wp_send_json([
                'success' => false,
                'results' => [],
                'error'   => [
                    'code'    => 'api_error',
                    'message' => $e->getMessage(),
                ],
            ]);
        }
    }

    // ─────────────────────────────────────────────
    // AJAX ADMIN: TAGS (BOATS)
    // ─────────────────────────────────────────────

    /**
     * action: maradigma_admin_search_tags
     *
     * Devuelve tags de barcos (Modern, Open, etc.) en formato Select2.
     */
    public static function adminSearchTags(): void
    {
        self::checkAdminAjaxSecurity();

        $q    = self::getSearchTerm();
        $page = self::getPageNumber();

        try {
            $client   = self::createApiClient();

            // Asegúrate de tener en ExternalApiClient:
            // public function getBoatTags(): array { return $this->requestJson('GET', '/boat-tags'); }
            $response = $client->getBoatTags();

            // TODO: ajustar a tu estructura real:
            // [
            //   'success' => true,
            //   'tags'    => [
            //      ['id' => 1, 'name' => 'Modern'],
            //      ...
            //   ]
            // ]
            $items = [];
            $tags  = $response['tags'] ?? [];

            foreach ($tags as $tag) {
                $id   = isset($tag['id']) ? (int) $tag['id'] : 0;
                $name = isset($tag['name']) ? (string) $tag['name'] : '';

                if ($id <= 0 || $name === '') {
                    continue;
                }

                if ($q !== '' && stripos($name, $q) === false) {
                    continue;
                }

                $items[] = [
                    'id'   => $id,
                    'text' => $name,
                ];
            }

            wp_send_json([
                'success'    => true,
                'results'    => $items,
                'pagination' => [
                    'more' => false,
                ],
            ]);
        } catch (\Throwable $e) {
            wp_send_json([
                'success' => false,
                'results' => [],
                'error'   => [
                    'code'    => 'api_error',
                    'message' => $e->getMessage(),
                ],
            ]);
        }
    }

    // ─────────────────────────────────────────────
    // AJAX ADMIN: BOAT BUILDERS
    // ─────────────────────────────────────────────

    /**
     * action: maradigma_admin_search_builders
     *
     * Devuelve constructores de barcos en formato Select2.
     */
    public static function adminSearchBuilders(): void
    {
        self::checkAdminAjaxSecurity();

        $q    = self::getSearchTerm();
        $page = self::getPageNumber();

        try {
            $client   = self::createApiClient();
            $response = $client->getBoatBuilders();

            // [
            //   'success'  => true,
            //   'data' => [
            //      ['id' => 1, 'name' => 'Azimut'],
            //      ...
            //   ]
            // ]
            $items    = [];
            $builders = $response['data'] ?? [];

            foreach ($builders as $builder) {
                $id   = isset($builder['id']) ? (int) $builder['id'] : 0;
                $name = isset($builder['name']) ? (string) $builder['name'] : '';

                if ($id <= 0 || $name === '') {
                    continue;
                }

                if ($q !== '' && stripos($name, $q) === false) {
                    continue;
                }

                $items[] = [
                    'id'   => $id,
                    'text' => $name,
                ];
            }

            wp_send_json([
                'success'    => true,
                'results'    => $items,
                'pagination' => [
                    'more' => false,
                ],
            ]);
        } catch (\Throwable $e) {
            wp_send_json([
                'success' => false,
                'results' => [],
                'error'   => [
                    'code'    => 'api_error',
                    'message' => $e->getMessage(),
                ],
            ]);
        }
    }

    // ─────────────────────────────────────────────
    // AJAX ADMIN: SPECIFIC BOATS (SEARCHABLE)
    // ─────────────────────────────────────────────

    /**
     * action: maradigma_admin_search_boats
     *
     * Busca barcos concretos (services) por texto, paginado.
     */
    public static function adminSearchBoats(): void
    {
        self::checkAdminAjaxSecurity();

        $q    = self::getSearchTerm();
        $page = self::getPageNumber();

        $perPage = 20;
        $offset  = ($page - 1) * $perPage;

        try {
            $client = self::createApiClient();

            $filters = [
                'id_group'               => 'boats',
                'limit_services'         => $perPage,
                'offset_services'        => $offset,
                'only_calendarization'   => false,
                'search_own_managment'   => false,
            ];

            if ($q !== '') {
                $filters['term'] = $q;
            }

            $response = $client->searchServices($filters);

            $items = [];

            $payload  = is_array($response['data'] ?? null) ? $response['data'] : [];
            $services = is_array($payload['search_result'] ?? null) ? $payload['search_result'] : [];
            $total    = isset($payload['total_results']) ? (int) $payload['total_results'] : count($services);

            foreach ($services as $service) {
                if (!is_array($service)) {
                    continue;
                }

                $id = (string) (
                    $service['id_group_item']
                    ?? $service['id_gi']
                    ?? $service['id']
                    ?? ''
                );

                if ($id === '') {
                    continue;
                }

                $builder = trim((string) ($service['boat_builder'] ?? ''));
                $model   = trim((string) ($service['boat_model'] ?? ''));
                $alias   = trim((string) ($service['boat_alias'] ?? ''));

                $parts = [];

                if ($builder !== '') {
                    $parts[] = $builder;
                }

                if ($model !== '') {
                    $parts[] = $model;
                }

                if ($alias !== '') {
                    $parts[] = $alias;
                }

                $text = !empty($parts)
                    ? implode(' · ', $parts)
                    : ('Boat #' . $id);

                $items[] = [
                    'id'   => $id,
                    'text' => $text,
                ];
            }

            $more = (($offset + $perPage) < $total);

            wp_send_json([
                'success'    => true,
                'results'    => $items,
                'pagination' => [
                    'more' => $more,
                ],
            ]);
        } catch (\Throwable $e) {
            wp_send_json([
                'success' => false,
                'results' => [],
                'error'   => [
                    'code'    => 'api_error',
                    'message' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * action: maradigma_admin_get_boat_by_id
     *
     * Usado para "hidratar" el label del select2 cuando editas una página/post
     * que ya tiene un boat_id guardado (evita ver "Boat #891").
     *
     * Return:
     * {
     *   success: true,
     *   item: { id: "891", text: "Astillero · Modelo · Alias (#891)" }
     * }
     */
    public static function adminGetBoatById(): void
    {
        self::checkAdminAjaxSecurity();

        if (!check_ajax_referer('maradigma_admin', 'nonce', false)) {
            wp_send_json([
                'success' => false,
                'item'    => null,
                'error'   => [
                    'code'    => 'bad_nonce',
                    'message' => __('Invalid security token.', 'maradigma'),
                ],
            ], 400);
        }

        $idRaw = isset($_REQUEST['id'])
            ? trim(sanitize_text_field((string) wp_unslash($_REQUEST['id'])))
            : '';

        if ($idRaw === '') {
            wp_send_json([
                'success' => false,
                'item'    => null,
                'error'   => [
                    'code'    => 'missing_id',
                    'message' => __('Missing required parameter: id.', 'maradigma'),
                ],
            ], 400);
        }

        try {
            $client = self::createApiClient();
            $response = $client->getServiceByIdOrSlug('boats', $idRaw);

            $data = is_array($response) ? self::extractBoatDataFromServiceResponse($response) : [];

            if (empty($data)) {
                wp_send_json([
                    'success' => false,
                    'item'    => null,
                    'error'   => [
                        'code'    => 'not_found',
                        'message' => __('Boat not found in API response.', 'maradigma'),
                    ],
                ], 404);
            }

            $id = (string) (
                $data['id_group_item']
                ?? $data['id_gi']
                ?? $data['id']
                ?? $idRaw
            );

            $text = self::formatBoatSelectLabel($data, $id);

            wp_send_json([
                'success' => true,
                'item'    => [
                    'id'   => $id,
                    'text' => $text,
                ],
            ]);
        } catch (\Throwable $e) {
            wp_send_json([
                'success' => false,
                'item'    => null,
                'error'   => [
                    'code'    => 'api_error',
                    'message' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    // ─────────────────────────────────────────────
    // AJAX ELEMENTOR
    // ─────────────────────────────────────────────

    /**
     * action: maradigma_elementor_search_boats
     *
     * Select2 AJAX for Elementor widget.
     * Returns: { results:[{id,text}], pagination:{more:true|false} }
     */
    public static function elementorSearchBoats(): void
    {
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'forbidden'], 403);
        }

        if (!check_ajax_referer('maradigma_elementor', 'nonce', false)) {
            wp_send_json_error(['message' => 'bad_nonce'], 400);
        }

        $q = isset($_REQUEST['q'])
            ? trim(sanitize_text_field((string) wp_unslash($_REQUEST['q'])))
            : '';

        $page = isset($_REQUEST['page']) ? (int) $_REQUEST['page'] : 1;
        if ($page < 1) {
            $page = 1;
        }

        $size = isset($_REQUEST['page_size']) ? (int) $_REQUEST['page_size'] : 20;
        if ($size < 5)  $size = 5;
        if ($size > 50) $size = 50;

        $filters = [
            'id_group'        => 'boats',
            'term'            => $q,
            'limit_services'  => (string) $size,
            'offset_services' => (string) (($page - 1) * $size),
            'order_by'        => '0',
        ];

        // Saved values: look each boat up by ID so the editor can show its name
        // (/search-services does not filter by ids_gi).
        $requestedIds = array_slice(array_values(array_filter(array_map('intval', self::readElementorRequestedIds()), static fn(int $id): bool => $id > 0)), 0, 20);
        if ($requestedIds !== []) {
            $out = [];
            $cache = new \Maradigma\Cache();

            foreach ($requestedIds as $requestedId) {
                try {
                    // Cached boat details, so reopening the section does not query the API again.
                    $details = $cache->getBoatDetails((string) $requestedId, (string) get_locale(), ['images' => 0]);
                    $boat = is_array($details['data'] ?? null) ? (array) $details['data'] : [];
                    $name = trim((string) ($boat['service_name'] ?? $boat['name'] ?? $boat['title'] ?? ''));

                    if ($name !== '') {
                        $out[] = ['id' => (string) $requestedId, 'text' => $name];
                    }
                } catch (\Throwable $e) {
                    unset($e); // Keep the other labels.
                }
            }

            wp_send_json([
                'results'    => $out,
                'pagination' => ['more' => false],
            ]);
        }

        try {
            $cache  = new \Maradigma\Cache();
            $result = $cache->getBoatsList($filters);

            $items = [];
            if (is_array($result['data']['search_result'] ?? null)) {
                $items = (array) $result['data']['search_result'];
            }

            $total = isset($result['data']['total_results'])
                ? (int) $result['data']['total_results']
                : count($items);

            $out = [];

            foreach ($items as $boat) {
                if (!is_array($boat)) {
                    continue;
                }

                // ⚠️ Ajusta keys si quieres (ahora con fallback)
                $id = (string)($boat['id_gi'] ?? $boat['id'] ?? $boat['id_group_item'] ?? '');
                if ($id === '') {
                    continue;
                }

                $name = (string)($boat['name'] ?? $boat['title'] ?? $boat['service_name'] ?? '');
                $text = $name !== '' ? $name : ('Boat #' . $id);

                $out[] = ['id' => $id, 'text' => $text];
            }

            wp_send_json([
                'results'    => $out,
                'pagination' => [
                    'more' => ($page * $size) < $total,
                ],
            ]);
        } catch (\Throwable $e) {
            wp_send_json_error([
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Handles Elementor's boat type search request.
     */
    public static function elementorSearchBoatTypes(): void
    {
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'forbidden'], 403);
        }

        if (!check_ajax_referer('maradigma_elementor', 'nonce', false)) {
            wp_send_json_error(['message' => 'bad_nonce'], 400);
        }

        $q = isset($_REQUEST['q'])
            ? trim(sanitize_text_field((string) wp_unslash($_REQUEST['q'])))
            : '';

        $requestedIds = self::readElementorRequestedIds();

        try {
            $client   = self::createApiClient();
            $response = $client->getServiceTypes('boats');

            $types = $response['data'] ?? [];
            $out   = [];

            foreach ($types as $type) {
                $id   = (string)($type['id'] ?? '');
                $name = (string)($type['name'] ?? '');

                if ($id === '' || $name === '') continue;
                if ($requestedIds !== [] && !in_array($id, $requestedIds, true)) continue;
                if ($requestedIds === [] && $q !== '' && stripos($name, $q) === false) continue;

                $out[] = ['id' => $id, 'text' => $name];
            }

            wp_send_json([
                'results'    => $out,
                'pagination' => ['more' => false],
            ]);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * action: maradigma_elementor_search_builders
     *
     * Select2 AJAX for Elementor (builders).
     * Returns: { results:[{id,text}], pagination:{more:true|false} }
     */
    public static function elementorSearchBuilders(): void
    {
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'forbidden'], 403);
        }

        if (!check_ajax_referer('maradigma_elementor', 'nonce', false)) {
            wp_send_json_error(['message' => 'bad_nonce'], 400);
        }

        $q = isset($_REQUEST['q'])
            ? trim(sanitize_text_field((string) wp_unslash($_REQUEST['q'])))
            : '';

        $page = isset($_REQUEST['page']) ? (int) $_REQUEST['page'] : 1;
        if ($page < 1) $page = 1;

        $size = isset($_REQUEST['page_size']) ? (int) $_REQUEST['page_size'] : 20;
        if ($size < 5)  $size = 5;
        if ($size > 50) $size = 50;

        $requestedIds = self::readElementorRequestedIds();

        try {
            $client = self::createApiClient();

            // Si tu ExternalApiClient ya tiene getBoatBuilders() perfecto:
            $response = $client->getBoatBuilders();

            // Esperado: ['success'=>true,'data'=>[['id'=>1,'name'=>'Azimut'],...]]
            $builders = $response['data'] ?? [];
            $out = [];

            foreach ($builders as $b) {
                if (!is_array($b)) continue;

                $id   = (string)($b['id'] ?? '');
                $name = (string)($b['name'] ?? '');

                if ($id === '' || $name === '') continue;

                if ($requestedIds !== []) {
                    if (!in_array($id, $requestedIds, true)) {
                        continue;
                    }
                } elseif ($q !== '' && stripos($name, $q) === false) {
                    continue;
                }

                $out[] = ['id' => $id, 'text' => $name];
            }

            // Si tu API no pagina builders, devolvemos more=false
            wp_send_json([
                'results'    => $out,
                'pagination' => ['more' => false],
            ]);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * action: maradigma_elementor_search_destinations
     *
     * Select2 AJAX for Elementor (destinations a boat listing can be limited to).
     * Built from the destinations of the boats in the catalogue; each value is a
     * `destination:{id}` token for the search API's departure_location filter.
     * Returns: { results:[{id,text}], pagination:{more:false} }
     */
    public static function elementorSearchDestinations(): void
    {
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'forbidden'], 403);
        }

        if (!check_ajax_referer('maradigma_elementor', 'nonce', false)) {
            wp_send_json_error(['message' => 'bad_nonce'], 400);
        }

        $q = isset($_REQUEST['q'])
            ? trim(sanitize_text_field((string) wp_unslash($_REQUEST['q'])))
            : '';

        $requestedIds = self::readElementorRequestedIds();

        try {
            $catalog = self::getBoatDestinationCatalog();
            $rows = $requestedIds !== []
                ? \Maradigma\Support\BoatDestinationCatalog::pick($catalog, $requestedIds)
                : \Maradigma\Support\BoatDestinationCatalog::search($catalog, $q);

            $out = [];
            foreach ($rows as $destination) {
                $out[] = [
                    'id'   => $destination['value'],
                    'text' => self::formatDestinationLabel($destination),
                ];
            }

            wp_send_json([
                'results'    => $out,
                'pagination' => ['more' => false],
            ]);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Returns the IDs an Elementor select asks labels for (its saved values), or [].
     *
     * @return list<string>
     */
    private static function readElementorRequestedIds(): array
    {
        // Callers validate the Elementor AJAX nonce before entering this helper.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $raw = isset($_REQUEST['ids']) && is_string($_REQUEST['ids'])
            ? sanitize_text_field(wp_unslash($_REQUEST['ids']))
            : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        $ids = array_values(array_unique(array_filter(array_map('trim', explode(',', $raw)), static fn(string $id): bool => $id !== '')));

        return array_slice($ids, 0, 50);
    }

    /**
     * Destinations of the boats in the catalogue, cached for ten minutes.
     *
     * @return list<array{id:int,value:string,text:string,place_type:string,subtitle:string,boats:int}>
     */
    private static function getBoatDestinationCatalog(): array
    {
        $transientKey = 'maradigma_boat_destinations_' . md5((string) wp_json_encode([
            (string) get_locale(),
            (string) (\Maradigma\SettingsPage::getSettings()['default_language'] ?? ''),
        ]));

        $cached = get_transient($transientKey);
        if (is_array($cached)) {
            return $cached;
        }

        $cache = new \Maradigma\Cache();
        $pageSize = 100;
        $boats = [];
        $failed = false;

        // Up to 2,000 boats; the list is built from the rows every page already carries.
        for ($page = 0; $page < 20; $page++) {
            $result = $cache->getBoatsList([
                'id_group'             => 'boats',
                'limit_services'       => $pageSize,
                'offset_services'      => $page * $pageSize,
                'only_calendarization' => false,
            ]);

            if (empty($result['success']) || !is_array($result['data']['search_result'] ?? null)) {
                if ($page === 0) {
                    throw new \RuntimeException('Boat list request failed.');
                }
                $failed = true;
                break;
            }

            $rows = (array) $result['data']['search_result'];
            array_push($boats, ...array_values($rows));

            $total = (int) ($result['data']['total_results'] ?? 0);
            if ($rows === [] || count($boats) >= $total) {
                break;
            }
        }

        $catalog = \Maradigma\Support\BoatDestinationCatalog::fromBoats($boats);

        // A catalogue cut short by a failed page is shown but retried soon.
        set_transient($transientKey, $catalog, $failed ? MINUTE_IN_SECONDS : 10 * MINUTE_IN_SECONDS);

        return $catalog;
    }

    /**
     * Returns the label of a destination option: name, kind of place and boats in it.
     *
     * @param array{text:string,place_type:string,boats:int} $destination
     */
    private static function formatDestinationLabel(array $destination): string
    {
        return sprintf(
            /* translators: 1: destination name, 2: kind of place (island, locality...), 3: number of boats. */
            __('%1$s (%2$s) — boats: %3$d', 'maradigma'),
            $destination['text'],
            self::translatePlaceType($destination['place_type']),
            $destination['boats']
        );
    }

    /**
     * Translates the kind of place of a destination returned by the API.
     */
    private static function translatePlaceType(string $placeType): string
    {
        switch ($placeType) {
            case 'island':
                return __('island', 'maradigma');
            case 'archipelago':
                return __('archipelago', 'maradigma');
            case 'locality':
                return __('locality', 'maradigma');
            case 'sublocality':
            case 'neighborhood':
                return __('area', 'maradigma');
            case 'region':
                return __('region', 'maradigma');
            case 'administrative_area':
                return __('administrative area', 'maradigma');
            case 'country':
                return __('country', 'maradigma');
            case 'marina':
                return __('marina', 'maradigma');
            case 'port':
                return __('port', 'maradigma');
            default:
                // 'point' and any kind the API adds later.
                return __('place', 'maradigma');
        }
    }

    /**
     * action: maradigma_get_boat_images_count
     *
     * Returns { success:true, data:{ count:int|null } }
     */
    public static function elementorGetBoatImagesCount(): void
    {
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'forbidden'], 403);
        }

        if (!check_ajax_referer('maradigma_elementor', 'nonce', false)) {
            wp_send_json_error(['message' => 'bad_nonce'], 400);
        }

        $boatId = isset($_POST['boat_id'])
            ? trim(sanitize_text_field((string) wp_unslash($_POST['boat_id'])))
            : '';

        if ($boatId === '') {
            wp_send_json_error(['message' => 'missing_boat_id'], 400);
        }

        try {
            // Pedimos detalles con imágenes (NO solo cover)
            $cache = new \Maradigma\Cache();

            // Ojo: tu Cache::getBoatDetails firma: (boatId, language, options)
            // Aquí language: puedes usar get_locale() o el que uses en tu plugin.
            $result = $cache->getBoatDetails(
                $boatId,
                (string) get_locale(),
                [
                    'expand' => ['service_images'],
                    'images' => 1,
                    'only_load_cover_image' => 0,
                    'url_images_main_domain' => 1,
                ]
            );

            $ok = is_array($result)
                && (($result['status'] ?? '') === 'success')
                && is_array(($result['data'] ?? null));

            if (!$ok) {
                wp_send_json_success(['count' => null]);
            }

            $data = (array) $result['data'];

            // Preferimos number_images si existe, si no contamos images
            $count = null;

            if (isset($data['number_images']) && is_numeric($data['number_images'])) {
                $count = (int) $data['number_images'];
            } elseif (isset($data['images']) && is_array($data['images'])) {
                $count = count($data['images']);
            }

            wp_send_json_success(['count' => $count]);
        } catch (\Throwable $e) {
            // No rompemos el panel, devolvemos desconocido
            wp_send_json_success(['count' => null]);
        }
    }

    /**
     * Checks public AJAX security.
     */
    private static function checkPublicAjaxSecurity(): void
    {
        /**
         * Public AJAX endpoints for frontend Select2.
         * Nonce is optional-hardening here. If invalid, fail softly.
         */
        $ok = check_ajax_referer('maradigma_remote_select2', 'nonce', false);

        if (!$ok) {
            wp_send_json([
                'success' => false,
                'results' => [],
                'error'   => [
                    'code'    => 'bad_nonce',
                    'message' => __('Invalid security token.', 'maradigma'),
                ],
            ], 400);
        }
    }

    /**
     * Handles the front-end search builders request.
     */
    public static function frontSearchBuilders(): void
    {
        self::checkPublicAjaxSecurity();

        $q = self::getSearchTerm();

        try {
            $client   = self::createApiClient();
            $response = $client->getBoatBuilders();

            $items    = [];
            $builders = $response['data'] ?? [];

            foreach ($builders as $builder) {
                $id   = isset($builder['id']) ? (int) $builder['id'] : 0;
                $name = isset($builder['name']) ? (string) $builder['name'] : '';

                if ($id <= 0 || $name === '') {
                    continue;
                }

                if ($q !== '' && stripos($name, $q) === false) {
                    continue;
                }

                $items[] = [
                    'id'   => $id,
                    'text' => $name,
                ];
            }

            wp_send_json([
                'success'    => true,
                'results'    => $items,
                'pagination' => [
                    'more' => false,
                ],
            ]);
        } catch (\Throwable $e) {
            wp_send_json([
                'success' => false,
                'results' => [],
                'error'   => [
                    'code'    => 'api_error',
                    'message' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * Handles the administrative get builder by ID request.
     */
    public static function adminGetBuilderById(): void
    {
        if (!current_user_can('edit_posts')) {
            wp_send_json([
                'success' => false,
                'item'    => null,
                'error'   => [
                    'code'    => 'forbidden',
                    'message' => __('You are not allowed to search builders.', 'maradigma'),
                ],
            ], 403);
        }

        self::checkAdminAjaxSecurity();
        self::sendBuilderByIdResponse();
    }

    /**
     * Sends builder by ID response.
     */
    private static function sendBuilderByIdResponse(): void
    {
        // The only caller validates an admin AJAX nonce before entering this helper.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $idRaw = isset($_REQUEST['id'])
            ? trim(sanitize_text_field((string) wp_unslash($_REQUEST['id'])))
            : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        if ($idRaw === '') {
            wp_send_json([
                'success' => false,
                'item'    => null,
                'error'   => [
                    'code'    => 'missing_id',
                    'message' => __('Missing required parameter: id.', 'maradigma'),
                ],
            ], 400);
        }

        try {
            $client = self::createApiClient();
            $response = $client->getBoatBuilder($idRaw);
            $builder = self::extractBuilderDataFromResponse($response);

            if (empty($builder)) {
                wp_send_json([
                    'success' => false,
                    'item'    => null,
                    'error'   => [
                        'code'    => 'not_found',
                        'message' => __('Builder not found in API response.', 'maradigma'),
                    ],
                ], 404);
            }

            $id = (string) ($builder['id'] ?? $idRaw);
            $text = trim((string) ($builder['name'] ?? ''));

            if ($text === '') {
                $text = sprintf('Builder #%s', $id);
            }

            wp_send_json([
                'success' => true,
                'item'    => [
                    'id'   => $id,
                    'text' => $text,
                ],
            ]);
        } catch (\Throwable $e) {
            wp_send_json([
                'success' => false,
                'item'    => null,
                'error'   => [
                    'code'    => 'api_error',
                    'message' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * @param array<string,mixed> $response
     * @return array<string,mixed>
     */
    private static function extractBuilderDataFromResponse(array $response): array
    {
        if (isset($response['data']) && is_array($response['data'])) {
            if (isset($response['data']['id']) || isset($response['data']['name'])) {
                return $response['data'];
            }

            if (isset($response['data'][0]) && is_array($response['data'][0])) {
                return $response['data'][0];
            }
        }

        if (isset($response['id']) || isset($response['name'])) {
            return $response;
        }

        return [];
    }

    /**
     * Handles the front-end search boat types request.
     */
    public static function frontSearchBoatTypes(): void
    {
        self::checkPublicAjaxSecurity();

        $q = self::getSearchTerm();

        try {
            $client   = self::createApiClient();
            $response = $client->getServiceTypes('boats');

            $items = [];
            $types = $response['data'] ?? [];

            foreach ($types as $type) {
                $id   = isset($type['id']) ? (int) $type['id'] : 0;
                $name = isset($type['name']) ? (string) $type['name'] : '';

                if ($id <= 0 || $name === '') {
                    continue;
                }

                if ($q !== '' && stripos($name, $q) === false) {
                    continue;
                }

                $items[] = [
                    'id'   => $id,
                    'text' => $name,
                ];
            }

            wp_send_json([
                'success'    => true,
                'results'    => $items,
                'pagination' => [
                    'more' => false,
                ],
            ]);
        } catch (\Throwable $e) {
            wp_send_json([
                'success' => false,
                'results' => [],
                'error'   => [
                    'code'    => 'api_error',
                    'message' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * Handles the front-end search boats request.
     */
    public static function frontSearchBoats(): void
    {
        self::checkPublicAjaxSecurity();

        $q    = self::getSearchTerm();
        $page = self::getPageNumber();

        $perPage = 20;
        $offset  = ($page - 1) * $perPage;

        try {
            $client = self::createApiClient();

            $filters = [
                'id_group'        => 'boats',
                'limit_services'  => $perPage,
                'offset_services' => $offset,
            ];

            if ($q !== '') {
                $filters['term'] = $q;
            }

            $response = $client->searchServices($filters);

            maradigma_debug_log('[Maradigma][frontSearchBoats] q=' . $q);
            maradigma_debug_log('[Maradigma][frontSearchBoats] filters=' . wp_json_encode($filters));
            maradigma_debug_log('[Maradigma][frontSearchBoats] raw response=' . wp_json_encode($response));

            $items = [];

            $payload  = is_array($response['data'] ?? null) ? $response['data'] : [];
            $services = is_array($payload['search_result'] ?? null) ? $payload['search_result'] : [];
            $total    = isset($payload['total_results']) ? (int) $payload['total_results'] : count($services);

            foreach ($services as $service) {
                if (!is_array($service)) {
                    continue;
                }

                $id = (string) (
                    $service['id_group_item']
                    ?? $service['id_gi']
                    ?? $service['id']
                    ?? ''
                );

                if ($id === '') {
                    continue;
                }

                $builder = trim((string) ($service['boat_builder'] ?? ''));
                $model   = trim((string) ($service['boat_model'] ?? ''));
                $alias   = trim((string) ($service['boat_alias'] ?? ''));

                $parts = [];

                if ($builder !== '') {
                    $parts[] = $builder;
                }

                if ($model !== '') {
                    $parts[] = $model;
                }

                if ($alias !== '') {
                    $parts[] = $alias;
                }

                $text = !empty($parts)
                    ? implode(' · ', $parts)
                    : ('Boat #' . $id);

                $items[] = [
                    'id'   => $id,
                    'text' => $text,
                ];
            }

            $more = (($offset + $perPage) < $total);

            wp_send_json([
                'success'    => true,
                'results'    => $items,
                'pagination' => [
                    'more' => $more,
                ],
            ]);
        } catch (\Throwable $e) {
            wp_send_json([
                'success' => false,
                'results' => [],
                'error'   => [
                    'code'    => 'api_error',
                    'message' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * Handles the front-end get boat by ID request.
     */
    public static function frontGetBoatById(): void
    {
        self::checkPublicAjaxSecurity();

        // Security was verified immediately above.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $idRaw = isset($_REQUEST['id'])
            ? trim(sanitize_text_field((string) wp_unslash($_REQUEST['id'])))
            : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        if ($idRaw === '') {
            wp_send_json([
                'success' => false,
                'item'    => null,
                'error'   => [
                    'code'    => 'missing_id',
                    'message' => __('Missing required parameter: id.', 'maradigma'),
                ],
            ], 400);
        }

        try {
            $client = self::createApiClient();
            $response = $client->getServiceByIdOrSlug('boats', $idRaw);

            $data = is_array($response) ? self::extractBoatDataFromServiceResponse($response) : [];

            if (empty($data)) {
                wp_send_json([
                    'success' => false,
                    'item'    => null,
                    'error'   => [
                        'code'    => 'not_found',
                        'message' => __('Boat not found in API response.', 'maradigma'),
                    ],
                ], 404);
            }

            $id = (string)(
                $data['id_group_item']
                ?? $data['id_gi']
                ?? $data['id']
                ?? $idRaw
            );

            $text = self::formatBoatSelectLabel($data, $id);

            wp_send_json([
                'success' => true,
                'item'    => [
                    'id'   => $id,
                    'text' => $text,
                ],
            ]);
        } catch (\Throwable $e) {
            wp_send_json([
                'success' => false,
                'item'    => null,
                'error'   => [
                    'code'    => 'api_error',
                    'message' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * Handles the front-end search tags request.
     */
    public static function frontSearchTags(): void
    {
        self::checkPublicAjaxSecurity();

        try {
            $client   = self::createApiClient();
            $response = $client->getBoatTags();
            $q        = self::getSearchTerm();

            $items = [];
            $tags  = $response['tags'] ?? [];

            foreach ($tags as $tag) {
                $id   = isset($tag['id']) ? (int) $tag['id'] : 0;
                $name = isset($tag['name']) ? (string) $tag['name'] : '';

                if ($id <= 0 || $name === '') {
                    continue;
                }

                if ($q !== '' && stripos($name, $q) === false) {
                    continue;
                }

                $items[] = [
                    'id'   => $id,
                    'text' => $name,
                ];
            }

            wp_send_json([
                'success'    => true,
                'results'    => $items,
                'pagination' => ['more' => false],
            ]);
        } catch (\Throwable $e) {
            wp_send_json([
                'success' => false,
                'results' => [],
                'error'   => [
                    'code'    => 'api_error',
                    'message' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * Handles the front-end search base ports request.
     */
    public static function frontSearchBasePorts(): void
    {
        self::checkPublicAjaxSecurity();

        wp_send_json([
            'success'    => true,
            'results'    => [],
            'pagination' => ['more' => false],
        ]);
    }
}

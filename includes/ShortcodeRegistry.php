<?php

declare(strict_types=1);

namespace Maradigma;

use Maradigma\Support\BoatBookingAvailability;
use Maradigma\Support\Sanitizer;
use Maradigma\Support\Utils;

/**
 * Registers and renders the plugin's public shortcodes.
 */
final class ShortcodeRegistry
{

    /**
     * Canonical documentation for search params accepted by Maradigma Boats search.
     *
     * IMPORTANT:
     * - This is based on core API: filter_services() + search_group_services() + searchServices().
     * - Do not add attributes that are not supported by the API.
     *
     * @var array<int,array{
     *   attr:string,
     *   type:string,
     *   default:string,
     *   description:string,
     *   notes?:string
     * }>
     */
    public static array $DOC_SEARCH_BOATS_ATTRS = [

        // ─────────────────────────────────────────────
        // Required / routing
        // ─────────────────────────────────────────────
        [
            'attr' => 'id_group',
            'type' => 'string',
            'default' => 'boats',
            'description' => 'Qué catálogo quieres mostrar. Para el catálogo de barcos debe ser "boats".',
            'notes' => 'Normalmente no necesitas cambiarlo: usa id_group="boats".',
        ],

        // ─────────────────────────────────────────────
        // Pagination
        // ─────────────────────────────────────────────
        [
            'attr' => 'limit_services',
            'type' => 'int',
            'default' => '10',
            'description' => 'Cuántos barcos se muestran por página.',
            'notes' => 'Ejemplos: limit_services="6", limit_services="12", limit_services="24".',
        ],
        [
            'attr' => 'offset_services',
            'type' => 'int',
            'default' => '0',
            'description' => 'Desde qué posición empezar a mostrar resultados (paginación avanzada).',
            'notes' => 'Ejemplos: offset_services="0" (primera página), offset_services="10" (siguiente bloque si limit_services=10).',
        ],

        // ─────────────────────────────────────────────
        // Dates (availability)
        // ─────────────────────────────────────────────
        [
            'attr' => 'date_start',
            'type' => 'Y-m-d',
            'default' => '',
            'description' => 'Fecha de inicio para filtrar barcos por disponibilidad.',
            'notes' => 'Formato: YYYY-MM-DD. Ejemplo: date_start="2026-06-01". Úsalo junto con date_end para buscar por fechas.',
        ],
        [
            'attr' => 'date_end',
            'type' => 'Y-m-d',
            'default' => '',
            'description' => 'Fecha de fin para filtrar barcos por disponibilidad.',
            'notes' => 'Formato: YYYY-MM-DD. Ejemplo: date_end="2026-06-07". Si falta alguna fecha o el formato no es válido, el filtro por fechas puede no aplicarse.',
        ],

        // ─────────────────────────────────────────────
        // Sorting
        // ─────────────────────────────────────────────
        [
            'attr' => 'order_by',
            'type' => 'int',
            'default' => '0',
            'description' => 'Orden del listado (por número).',
            'notes' => 'Valores: 0=relevancia, 1=precio (menor a mayor), 2=precio (mayor a menor), 3=destacados primero, 4=novedades (más recientes primero), 5=eslora (mayor a menor), 6=eslora (menor a mayor).',
        ],
        [
            'attr' => 'custom_orderby_field',
            'type' => 'string',
            'default' => '',
            'description' => 'Ordena por un campo personalizado (solo si tu plantilla/instalación lo tiene configurado).',
            'notes' => 'Ejemplo: custom_orderby_field="boat_length". Si no estás seguro, no lo uses.',
        ],

        // ─────────────────────────────────────────────
        // Listing mode
        // ─────────────────────────────────────────────
        [
            'attr' => 'search_own_managment',
            'type' => 'bool',
            'default' => 'false',
            'description' => 'Muestra solo barcos gestionados por tu empresa (tu propio catálogo).',
            'notes' => 'Valores típicos: search_own_managment="1" o search_own_managment="true". Déjalo en false si quieres mostrar catálogo combinado (si tu web lo usa).',
        ],
        [
            'attr' => 'only_calendarization',
            'type' => 'bool',
            'default' => 'false',
            'description' => 'If true, return only services that are reservable online. If false, include also non-reservables (catalog).',
            'notes' => 'Default false to include the full catalog (including non-online-reservables).',
        ],
        [
            'attr' => 'last_minute_mode',
            'type' => 'bool',
            'default' => 'false',
            'description' => 'Activa el modo “last minute” para priorizar resultados de última hora.',
            'notes' => 'Valores típicos: last_minute_mode="1" o last_minute_mode="true". Útil para una página tipo “Ofertas de hoy/mañana”.',
        ],
        [
            'attr' => 'ignore_date_range',
            'type' => 'bool',
            'default' => 'false',
            'description' => 'Ignora el filtro por fechas aunque se envíen date_start/date_end.',
            'notes' => 'Valores típicos: ignore_date_range="1" o ignore_date_range="true". Úsalo si quieres mostrar catálogo completo sin comprobar disponibilidad por fechas.',
        ],

        // ─────────────────────────────────────────────
        // Status / IDs
        // ─────────────────────────────────────────────
        [
            'attr' => 'status_group_item',
            'type' => 'int[]',
            'default' => '',
            'description' => 'Filtra por estados del barco dentro del catálogo (solo para configuraciones avanzadas).',
            'notes' => 'Ejemplo: status_group_item="1,2". Si no sabes qué estados usa tu instalación, no lo uses.',
        ],
        [
            'attr' => 'ids_gi',
            'type' => 'int[]',
            'default' => '',
            'description' => 'Muestra solo barcos concretos por ID (lista).',
            'notes' => 'Ejemplo: ids_gi="12,45,78". Útil para crear páginas “selección manual” de barcos.',
        ],

        // ─────────────────────────────────────────────
        // Text search
        // ─────────────────────────────────────────────
        [
            'attr' => 'term',
            'type' => 'string',
            'default' => '',
            'description' => 'Búsqueda por texto (nombre, modelo, zona, etc. según tu catálogo).',
            'notes' => 'Ejemplo: term="ibiza" o term="sunseeker".',
        ],
        [
            'attr' => 'service_name',
            'type' => 'string',
            'default' => '',
            'description' => 'Alias de term (otra forma de indicar el texto de búsqueda).',
            'notes' => 'Ejemplo: service_name="ibiza". Recomendado usar term para mantener consistencia.',
        ],

        // ─────────────────────────────────────────────
        // Members / marketplace
        // ─────────────────────────────────────────────
        [
            'attr' => 'search_api_bch_gi',
            'type' => 'bool|int',
            'default' => 'false',
            'description' => 'Muestra solo barcos del catálogo “Maradigma Members”.',
            'notes' => 'Valores aceptados (equivalentes): 1 / "1" / true / "true" / "on". Para desactivarlo: 0 / "0" / false / "false".',
        ],
        [
            'attr' => 'is_owner',
            'type' => 'int',
            'default' => '',
            'description' => 'Filtra por propiedad del barco (si es tuyo o no).',
            'notes' => 'Valores: is_owner="1" (solo barcos propios) o is_owner="0" (solo barcos que no son propios). Si no lo indicas, se muestran ambos.',
        ],
        [
            'attr' => 'ins_book',
            'type' => 'int|bool|string',
            'default' => '',
            'description' => 'Muestra solo barcos con reserva online activa.',
            'notes' => 'Valores aceptados (equivalentes): 1 / "1" / true / "true" / "on". Cuando está activo, el visitante podrá iniciar una reserva desde la ficha del barco (por ejemplo ver disponibilidad y avanzar en el proceso de reserva). Para desactivarlo: 0 / "0" / false / "false" o no incluir el atributo.',
        ],
        [
            'attr' => 'featured',
            'type' => 'int|bool|string',
            'default' => '',
            'description' => 'Muestra solo barcos destacados.',
            'notes' => 'Valores aceptados (equivalentes): 1 / "1" / true / "true" / "on". Útil para secciones tipo “Recomendados”. Para desactivarlo: 0 / "0" / false / "false" o no incluir el atributo.',
        ],

        // ─────────────────────────────────────────────
        // Taxonomy / content type
        // ─────────────────────────────────────────────
        [
            'attr' => 'tags',
            'type' => 'int[]',
            'default' => '',
            'description' => 'Filtra por etiquetas (tags) del catálogo.',
            'notes' => 'Ejemplo: tags="3,7" (muestra barcos que tengan alguna de esas etiquetas).',
        ],
        [
            'attr' => 'id_group_content_type',
            'type' => 'int',
            'default' => '',
            'description' => 'Filtra por tipo de contenido (solo si tu catálogo lo usa).',
            'notes' => 'Ejemplo: id_group_content_type="2". Si no sabes qué valores existen, no lo uses.',
        ],
        [
            'attr' => 'id_group_multiple_content_type',
            'type' => 'int[]',
            'default' => '',
            'description' => 'Filtra por varios tipos de contenido (solo si tu catálogo lo usa).',
            'notes' => 'Ejemplo: id_group_multiple_content_type="2,5,9".',
        ],

        // ─────────────────────────────────────────────
        // Availability flags
        // ─────────────────────────────────────────────
        [
            'attr' => 'is_available_for_rent',
            'type' => 'bool|int|string',
            'default' => 'false',
            'description' => 'Muestra solo barcos disponibles para alquiler.',
            'notes' => 'Valores aceptados (equivalentes): 1 / "1" / true / "true" / "on". Úsalo para ocultar barcos que no estén en modo alquiler/publicación.',
        ],

        // ─────────────────────────────────────────────
        // Owners
        // ─────────────────────────────────────────────
        [
            'attr' => 'id_owner',
            'type' => 'int|int[]',
            'default' => '',
            'description' => 'Filtra por propietario/gestor del barco (owner).',
            'notes' => 'Ejemplos: id_owner="15" o id_owner="15,22". Útil si quieres crear una página por propietario/empresa.',
        ],

        // ─────────────────────────────────────────────
        // Price filters
        // ─────────────────────────────────────────────
        [
            'attr' => 'min_price',
            'type' => 'float',
            'default' => '',
            'description' => 'Precio mínimo.',
            'notes' => 'Ejemplo: min_price="500". Útil para páginas tipo “desde 500€”.',
        ],
        [
            'attr' => 'max_price',
            'type' => 'float',
            'default' => '',
            'description' => 'Precio máximo.',
            'notes' => 'Ejemplo: max_price="1200". Útil para páginas tipo “hasta 1200€”.',
        ],
        [
            'attr' => 'price-min',
            'type' => 'float',
            'default' => '',
            'description' => 'Alias de min_price.',
            'notes' => 'Ejemplo: price-min="500". Recomendado usar min_price para mantener consistencia.',
        ],
        [
            'attr' => 'price-max',
            'type' => 'float',
            'default' => '',
            'description' => 'Alias de max_price.',
            'notes' => 'Ejemplo: price-max="1200". Recomendado usar max_price para mantener consistencia.',
        ],

        // ─────────────────────────────────────────────
        // Boat-specific fields
        // ─────────────────────────────────────────────
        [
            'attr' => 'boat_id_builder',
            'type' => 'int|int[]',
            'default' => '',
            'description' => 'Filtra por fabricante/astillero (builder) del barco.',
            'notes' => 'Ejemplo: boat_id_builder="4" o boat_id_builder="4,8".',
        ],
        [
            'attr' => 'boat_model',
            'type' => 'string',
            'default' => '',
            'description' => 'Filtra por modelo del barco.',
            'notes' => 'Ejemplo: boat_model="Sunseeker".',
        ],
        [
            'attr' => 'boat_alias',
            'type' => 'string',
            'default' => '',
            'description' => 'Filtra por “alias” o nombre comercial del barco.',
            'notes' => 'Ejemplo: boat_alias="Aurelia".',
        ],
        [
            'attr' => 'boat_base_port',
            'type' => 'int',
            'default' => '',
            'description' => 'Filtra por puerto base (ID).',
            'notes' => 'Ejemplo: boat_base_port="12".',
        ],
        [
            'attr' => 'boat_capacity',
            'type' => 'int',
            'default' => '',
            'description' => 'Capacidad mínima de personas (pax).',
            'notes' => 'Ejemplo: boat_capacity="8" (mostrar barcos para 8 personas o más).',
        ],
        [
            'attr' => 'boat_cabins',
            'type' => 'int',
            'default' => '',
            'description' => 'Número mínimo de cabinas.',
            'notes' => 'Ejemplo: boat_cabins="3".',
        ],
        [
            'attr' => 'boat_beds',
            'type' => 'int',
            'default' => '',
            'description' => 'Número mínimo de camas.',
            'notes' => 'Ejemplo: boat_beds="6".',
        ],
        [
            'attr' => 'boat_bathrooms',
            'type' => 'int',
            'default' => '',
            'description' => 'Número mínimo de baños.',
            'notes' => 'Ejemplo: boat_bathrooms="2".',
        ],
        [
            'attr' => 'boat_skipper_option',
            'type' => 'int|int[]|csv',
            'default' => '',
            'description' => 'Filtra por tipo de opción de patrón (skipper).',
            'notes' => 'Ejemplos: boat_skipper_option="1" o boat_skipper_option="1,2". Útil para separar “con patrón” / “sin patrón” según tu catálogo.',
        ],
        [
            'attr' => 'boat_licence_required',
            'type' => 'int',
            'default' => '',
            'description' => 'Filtra por requisito de licencia.',
            'notes' => 'Ejemplo: boat_licence_required="1" (solo barcos que requieren licencia) o boat_licence_required="0" (barcos que no requieren licencia), según cómo esté configurado tu catálogo.',
        ],
        [
            'attr' => 'boat_length',
            'type' => 'number',
            'default' => '',
            'description' => 'Longitud mínima del barco (metros).',
            'notes' => 'Ejemplo: boat_length="12". Si quieres un rango (mín/máx), usa min_boat_length y max_boat_length.',
        ],
        [
            'attr' => 'boat_lenght',
            'type' => 'number',
            'default' => '',
            'description' => 'Alias legacy (typo) de boat_length.',
            'notes' => 'Ejemplo: boat_lenght="12". Recomendado usar boat_length.',
        ],
        [
            'attr' => 'min_boat_length',
            'type' => 'int',
            'default' => '',
            'description' => 'Longitud mínima del barco (metros).',
            'notes' => 'Ejemplo: min_boat_length="10". Úsalo junto con max_boat_length para filtrar por rango.',
        ],
        [
            'attr' => 'max_boat_length',
            'type' => 'int',
            'default' => '',
            'description' => 'Longitud máxima del barco (metros).',
            'notes' => 'Ejemplo: max_boat_length="15". Úsalo junto con min_boat_length para filtrar por rango.',
        ],
        [
            'attr' => 'boat_beam',
            'type' => 'number',
            'default' => '',
            'description' => 'Manga mínima del barco (metros).',
            'notes' => 'Ejemplo: boat_beam="4".',
        ]
    ];

    /**
     * Registers the component's WordPress hooks.
     */
    public static function init(): void
    {
        // Listado de barcos
        add_shortcode('maradigma_boats', [__CLASS__, 'renderBoatsListing']);
        add_shortcode('maradigma_search', [__CLASS__, 'renderSearchForm']);
        add_shortcode('maradigma_boat_card', [__CLASS__, 'renderBoatCard']);
        add_shortcode('maradigma_related_boats', [__CLASS__, 'renderRelatedBoats']);
        add_shortcode('maradigma_boats_related', [__CLASS__, 'renderRelatedBoats']);
        add_shortcode('maradigma_barcos_relacionados', [__CLASS__, 'renderRelatedBoats']);

        add_shortcode('maradigma_boat', [__CLASS__, 'renderSingleBoat']);

        add_shortcode('maradigma_boat_specs', [__CLASS__, 'renderBoatSpecs']);

        // Shortcodes de campo genéricos
        add_shortcode('maradigma_boat_field', [__CLASS__, 'renderBoatField']);

        // Azúcar sintáctico para casos típicos (pueden usar internamente renderBoatField)
        add_shortcode('maradigma_boat_name', [__CLASS__, 'renderBoatName']);
        add_shortcode('maradigma_boat_title', [__CLASS__, 'renderBoatTitle']);
        add_shortcode('maradigma_boat_pax', [__CLASS__, 'renderBoatPax']);
        add_shortcode('maradigma_boat_length', [__CLASS__, 'renderBoatLength']);
        add_shortcode('maradigma_boat_beam', [__CLASS__, 'renderBoatBeam']);
        add_shortcode('maradigma_boat_builder', [__CLASS__, 'renderBoatBuilder']);
        add_shortcode('maradigma_boat_base_port', [__CLASS__, 'renderBoatBasePort']);
        add_shortcode('maradigma_boat_main_image', [__CLASS__, 'renderBoatMainImage']);
        add_shortcode('maradigma_boat_gallery', [__CLASS__, 'renderBoatGallery']);
        add_shortcode('maradigma_boat_videos', [__CLASS__, 'renderBoatVideos']);
        add_shortcode('maradigma_boat_description', [__CLASS__, 'renderBoatDescription']);
        add_shortcode('maradigma_boat_equipments', [__CLASS__, 'renderBoatEquipments']);
        add_shortcode('maradigma_boat_included', [__CLASS__, 'renderBoatIncluded']);
        add_shortcode('maradigma_boat_not_included', [__CLASS__, 'renderBoatNotIncluded']);
        add_shortcode('maradigma_boat_additional_services', [__CLASS__, 'renderBoatAdditionalServices']);
        add_shortcode('maradigma_boat_prices', [__CLASS__, 'renderBoatPrices']);
        add_shortcode('maradigma_boat_price', [__CLASS__, 'renderBoatPrices']);
        add_shortcode('maradigma_boat_pdf_download', [__CLASS__, 'renderBoatPdfDownload']);
        add_shortcode('maradigma_boat_calendar', [__CLASS__, 'renderBoatCalendar']);

        // Booking boat shortcode
        add_shortcode('maradigma_boat_booking', [__CLASS__, 'renderBoatBooking']);
        add_shortcode('maradigma_booking_success', [__CLASS__, 'renderBookingSuccess']);
    }

    /* ============================================================
     * HELPERS COMUNES
     * ============================================================ */

    /**
     * Obtiene settings del plugin.
     *
     * @return array<string,mixed>
     */
    private static function getSettings(): array
    {
        return SettingsPage::getSettings();
    }

    /**
     * Devuelve idioma a usar con la API (prioridad: idioma actual multilang).
     * Retorna 'EN', 'ES', 'DE', etc.
     */
    private static function getLanguage(): string
    {
        // 1) Polylang/WPML (idioma actual)
        if (class_exists(\Maradigma\Support\MultilangAdapter::class)) {
            $cur = \Maradigma\Support\MultilangAdapter::getCurrentLanguage(); // 'es','en','de'...
            $cur = strtoupper(trim((string)$cur));
            if ($cur !== '') {
                return $cur;
            }
        }

        // 2) Runtime context fallback. This uses the multilingual default language
        // before falling back to WordPress locale, avoiding a wrong locale leak when
        // Polylang has no current language in REST/AJAX/public cached requests.
        if (class_exists(\Maradigma\Support\RuntimeContext::class)) {
            $cur = strtoupper(trim((string) \Maradigma\Support\RuntimeContext::detectCurrentLanguage()));
            if ($cur !== '') {
                $parts = preg_split('/[_-]/', $cur);
                $lang2 = strtoupper((string)($parts[0] ?? $cur));
                if ($lang2 !== '') {
                    return $lang2;
                }
            }
        }

        // 3) Settings default_language
        $settings = self::getSettings();
        $lang = strtoupper(trim((string)($settings['default_language'] ?? 'EN')));
        if ($lang !== '') {
            // si alguien guarda 'es_ES' o 'es-ES' por error, lo normalizamos a 2 letras
            $parts = preg_split('/[_-]/', $lang);
            $lang2 = strtoupper((string)($parts[0] ?? 'EN'));
            return $lang2 !== '' ? $lang2 : 'EN';
        }

        // 4) Fallback final: locale WP
        $locale = (string) get_locale();
        $parts  = preg_split('/[_-]/', $locale);
        $lang2  = strtoupper((string)($parts[0] ?? 'EN'));

        return $lang2 !== '' ? $lang2 : 'EN';
    }

    /**
     * Normalizes language slug.
     *
     * @param mixed $language Language or locale identifier.
     */
    private static function normalizeLanguageSlug($language): string
    {
        if (!\is_scalar($language)) {
            return '';
        }

        $language = \strtolower(\trim((string) $language));
        if ($language === '') {
            return '';
        }

        $parts = \preg_split('/[_-]/', $language);
        $slug = \strtolower(\trim((string) ($parts[0] ?? $language)));
        $slug = \preg_replace('/[^a-z0-9]/', '', $slug);

        return \is_string($slug) ? $slug : '';
    }

    /**
     * Resolve archive language explicitly first, then multilingual/runtime context.
     *
     * REST pagination cannot reliably infer Polylang/WPML context from the request
     * URL, so the frontend passes the current page language as md_lang/lang.
     *
     * @param array<string,mixed> $atts
     */
    private static function resolveArchiveCurrentLanguage(array $atts, string $defaultLang): string
    {
        foreach (['md_lang', 'current_lang', 'lang', 'language'] as $key) {
            $candidate = self::normalizeLanguageSlug($atts[$key] ?? '');
            if ($candidate !== '') {
                return $candidate;
            }
        }

        $currentLang = '';
        if (\function_exists('pll_current_language')) {
            $currentLang = (string) \pll_current_language('slug');
        } elseif (\has_filter('wpml_current_language')) {
            $maybe = \apply_filters('wpml_current_language', null);
            $currentLang = \is_string($maybe) ? $maybe : '';
        } elseif (\class_exists(\Maradigma\Support\MultilangAdapter::class)) {
            $currentLang = (string) \Maradigma\Support\MultilangAdapter::getCurrentLanguage();
        }

        $currentLang = self::normalizeLanguageSlug($currentLang);
        if ($currentLang !== '') {
            return $currentLang;
        }

        if (\class_exists(\Maradigma\Support\RuntimeContext::class)) {
            $currentLang = self::normalizeLanguageSlug(\Maradigma\Support\RuntimeContext::detectCurrentLanguage());
            if ($currentLang !== '') {
                return $currentLang;
            }
        }

        $defaultLang = self::normalizeLanguageSlug($defaultLang);

        return $defaultLang !== '' ? $defaultLang : 'en';
    }

    /**
     * Resolves the boat identifier from shortcode attributes or the current queried post context.
     *
     * Resolution priority:
     * 1. Explicit {@see $atts['slug']}, when provided and non-empty.
     * 2. Explicit {@see $atts['id']}, when provided and non-empty.
     * 3. Boat manually bound to the current queried post through
     *    {@see \Maradigma\MetaManager::getBoundBoatIdForPost()}.
     *
     * This method is used by single-boat related shortcodes so they can work in
     * both scenarios:
     * - Direct usage with an explicit identifier, for example:
     *   {@code [maradigma_boat id="123"]} or {@code [maradigma_boat slug="my-boat"]}.
     * - Context-aware usage inside a WordPress post/page already linked to a boat,
     *   where no explicit shortcode identifier is required.
     *
     * Important notes:
     * - If both {@see slug} and {@see id} are present, {@see slug} takes precedence.
     * - The contextual fallback only applies when a valid queried post exists.
     * - The post binding lookup is delegated to {@see \Maradigma\MetaManager}, which
     *   centralizes validation for supported post types and manual boat-page bindings.
     *
     * @param array<string,string> $atts Shortcode attributes that may contain
     *                                   {@see slug} and/or {@see id}.
     *
     * @return string|null The resolved boat identifier (slug or id) when available,
     *                     or {@see null} if no explicit or contextual identifier
     *                     could be determined.
     */
    private static function resolveIdentifier(array $atts): ?string
    {
        $slug = trim((string) ($atts['slug'] ?? ''));
        $id   = trim((string) ($atts['id'] ?? ''));

        if ($slug !== '') {
            return $slug;
        }

        if ($id !== '') {
            return $id;
        }

        $boundBoatId = self::resolveIdentifierFromPostContext();
        if ($boundBoatId !== null) {
            return $boundBoatId;
        }

        return null;
    }

    /**
     * Resolves identifier from post context.
     */
    private static function resolveIdentifierFromPostContext(): ?string
    {
        if (!class_exists(\Maradigma\MetaManager::class)) {
            return null;
        }

        $postIds = [];

        if (function_exists('get_queried_object_id')) {
            $postIds[] = (int) get_queried_object_id();
        }

        if (function_exists('get_the_ID')) {
            $postIds[] = (int) get_the_ID();
        }

        global $post;
        if ($post instanceof \WP_Post) {
            $postIds[] = (int) $post->ID;
        }

        $postIds = array_values(array_unique(array_filter($postIds, static fn(int $postId): bool => $postId > 0)));

        foreach ($postIds as $postId) {
            if (method_exists(\Maradigma\MetaManager::class, 'isPostProtectedFromBoatBinding')
                && \Maradigma\MetaManager::isPostProtectedFromBoatBinding($postId)
            ) {
                continue;
            }

            $boundBoatId = \Maradigma\MetaManager::getBoundBoatIdForPost($postId);
            if ($boundBoatId !== '') {
                return $boundBoatId;
            }

            foreach ([\Maradigma\MetaManager::META_PAGE_BOAT_ID, \Maradigma\MetaManager::META_CPT_BOAT_ID] as $metaKey) {
                $metaBoatId = trim((string) get_post_meta($postId, $metaKey, true));
                if ($metaBoatId !== '') {
                    return $metaBoatId;
                }
            }
        }

        return null;
    }

    /**
     * Carga los datos de un barco vía Cache.
     *
     * @param array<string,string>       $atts
     * @param array<int,string>          $requiredDataKeys
     * @param array<string,mixed>        $options
     * @return array<string,mixed>|null
     */
    /**
     * Resolve the API boat id from loaded boat data. Local Media Library
     * attachments are linked by API id, so a slug-based shortcode still needs
     * this normalized id before it can read cached images.
     *
     * @param array<string,mixed>  $boat
     * @param array<string,string> $atts
     */
    private static function resolveBoatIdFromBoatData(array $boat, array $atts = []): string
    {
        foreach (['id', 'id_gi', 'id_group_item', 'boat_id'] as $key) {
            $value = trim((string) ($boat[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        $id = trim((string) ($atts['id'] ?? ''));
        if ($id !== '') {
            return $id;
        }

        $contextId = self::resolveIdentifierFromPostContext();
        return $contextId !== null ? $contextId : '';
    }

    /**
     * Loads boat.
     */
    private static function loadBoat(
        array $atts,
        array $requiredDataKeys = [],
        array $options = []
    ): ?array {
        $identifier = self::resolveIdentifier($atts);
        if ($identifier === null) {
            return null;
        }

        $language = self::getLanguage();

        $cache  = new Cache();
        $result = $cache->getBoatDetails(
            (string) $identifier,
            $language,
            $options,
            $requiredDataKeys
        );

        $boat = is_array($result['data'] ?? null) ? $result['data'] : null;

        return $boat ?: null;
    }

    /* ============================================================
     * LISTADO DE BARCOS
     * ============================================================ */

    /**
     * Safe GET getter with a fixed prefix to avoid collisions with WP.
     *
     * @param string                    $key     UI key without "md_" prefix.
     * @param array<string,string>|null $uiQuery Optional explicit query map used by AJAX rendering.
     *
     * @return string
     */
    private static function getUiQuery(string $key, ?array $uiQuery = null): string
    {
        $param = 'md_' . $key;

        if (\is_array($uiQuery) && \array_key_exists($param, $uiQuery)) {
            $raw = (string) $uiQuery[$param];
            return \trim($raw);
        }

        // Public archive filters are intentionally shareable GET parameters.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!isset($_GET[$param])) {
            return '';
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $raw = \sanitize_text_field((string) \wp_unslash($_GET[$param]));
        return \trim($raw);
    }

    /**
     * Returns the canonical shortcode defaults for every supported boat search attribute.
     *
     * Keeping these defaults derived from the public search documentation prevents
     * WordPress shortcode normalization from discarding valid attributes supplied
     * by Elementor, Gutenberg, WPBakery, or a shortcode author.
     *
     * @return array<string,string>
     */
    public static function getBoatsSearchAttributeDefaults(): array
    {
        $defaults = [];

        foreach (self::$DOC_SEARCH_BOATS_ATTRS as $definition) {
            if (!\is_array($definition)) {
                continue;
            }

            $attribute = \trim((string) ($definition['attr'] ?? ''));
            if ($attribute === '') {
                continue;
            }

            $defaults[$attribute] = (string) ($definition['default'] ?? '');
        }

        return $defaults;
    }

    /**
     * Aligns undated BCH price calculations with the amount shown by the card.
     *
     * The dated search response already uses the exact range summary_total, so
     * this criterion must never be sent when a complete date range is present.
     *
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    private static function applyUndatedPriceOrderCriteria(array $filters, string $template): array
    {
        unset($filters['undated_price_order_criteria']);

        $hasSelectedDateRange = \trim((string) ($filters['date_start'] ?? '')) !== ''
            && \trim((string) ($filters['date_end'] ?? '')) !== '';

        if (
            !$hasSelectedDateRange
            && \strpos($template, '{{price_from_service_with_mandatory_additionals_total}}') !== false
        ) {
            $filters['undated_price_order_criteria'] = 'total_with_mandatory_additionals';
        }

        \ksort($filters);

        return $filters;
    }

    /**
     * In range mode the date_start UI control renders the full availability range.
     *
     * @param list<string> $fields
     * @return list<string>
     */
    private static function normalizeDateRangeUiFields(array $fields): array
    {
        $normalized = [];
        $hasDateRange = \in_array('date_start', $fields, true) || \in_array('date_end', $fields, true);

        foreach ($fields as $field) {
            $field = (string) $field;

            if ($field === 'date_start' || $field === 'date_end') {
                if ($hasDateRange && !\in_array('date_start', $normalized, true)) {
                    $normalized[] = 'date_start';
                }

                continue;
            }

            if (!\in_array($field, $normalized, true)) {
                $normalized[] = $field;
            }
        }

        return $normalized;
    }

    /**
     * Render the filter form (frontend).
     *
     * This renderer is shared by both:
     * - Standard SSR output.
     * - AJAX archive re-renders.
     *
     * It is aligned with the archive JS controller and exposes the required
     * data attributes so the frontend can intercept submit/pagination actions
     * and call the REST archive endpoint without reloading the page.
     *
     * @param list<string>              $fields
     * @param string                    $layout
     * @param string                    $submitMode
     * @param bool                      $showReset
     * @param bool                      $showMoreBtn
     * @param string                    $moreBtnText
     * @param string                    $drawerTitle
     * @param array<string,mixed>       $defaults
     * @param string                    $dateMode
     * @param list<string>              $drawerFields
     * @param list<string>              $rightFields
     * @param array<string,string>|null $uiQuery
     * @param string                    $archiveEndpoint
     * @param string                    $archiveTargetId
     *
     * @return string
     */
    private static function renderBoatsFiltersUi(
        array $fields,
        string $layout,
        string $submitMode,
        bool $showReset,
        bool $showMoreBtn = false,
        string $moreBtnText = 'More filters',
        string $drawerTitle = 'More filters',
        array $defaults = [],
        string $dateMode = 'range',
        array $drawerFields = [],
        array $rightFields = [],
        ?array $uiQuery = null,
        string $archiveEndpoint = '',
        string $archiveTargetId = ''
    ): string {
        $layout = ($layout === 'vertical') ? 'vertical' : 'horizontal';
        $auto   = ($submitMode === 'auto');

        $moreBtnText = \Maradigma\Support\MultilangAdapter::translateEditableString(
            \trim((string) $moreBtnText),
            'Maradigma Elementor Widgets',
            'boats_filters_more_button_text',
            'maradigma'
        );

        $drawerTitle = \Maradigma\Support\MultilangAdapter::translateEditableString(
            \trim((string) $drawerTitle),
            'Maradigma Elementor Widgets',
            'boats_filters_drawer_title',
            'maradigma'
        );

        if ($moreBtnText === '') {
            $moreBtnText = __('More filters', 'maradigma');
        }

        if ($drawerTitle === '') {
            $drawerTitle = __('More filters', 'maradigma');
        }

        $class = 'maradigma-boats-filters maradigma-boats-filters--' . $layout;

        $value = static function (string $k) use ($defaults, $uiQuery): string {
            $q = (string) self::getUiQuery($k, $uiQuery);

            if ($q !== '') {
                return \esc_attr($q);
            }

            if (\array_key_exists($k, $defaults) && $defaults[$k] !== null) {
                return \esc_attr((string) $defaults[$k]);
            }

            return '';
        };

        $normalizePrice = static function (array $arr): array {
            if (\in_array('min_price', $arr, true) && \in_array('max_price', $arr, true)) {
                $normalized = [];

                foreach ($arr as $f) {
                    if ($f === 'min_price') {
                        $normalized[] = 'price_range';
                        continue;
                    }

                    if ($f === 'max_price') {
                        continue;
                    }

                    $normalized[] = $f;
                }

                return $normalized;
            }

            return $arr;
        };

        $fields       = $normalizePrice($fields);
        $drawerFields = $normalizePrice($drawerFields);
        $rightFields  = $normalizePrice($rightFields);

        $csvToArray = static function (string $csv): array {
            $csv = \trim($csv);

            if ($csv === '') {
                return [];
            }

            $parts = \array_map('trim', \explode(',', $csv));

            return \array_values(
                \array_unique(
                    \array_filter($parts, static fn($v) => $v !== '')
                )
            );
        };

        $action = \remove_query_arg('md_page');

        $drawerId      = 'mdFiltersDrawer_' . \wp_rand(1000, 9999);
        $drawerLabelId = $drawerId . 'Label';

        $priceBounds = ['min' => 0, 'max' => 5000];

        if (isset($defaults['_price_bounds_min']) && \is_numeric($defaults['_price_bounds_min'])) {
            $priceBounds['min'] = (int) $defaults['_price_bounds_min'];
        }

        if (isset($defaults['_price_bounds_max']) && \is_numeric($defaults['_price_bounds_max'])) {
            $priceBounds['max'] = (int) $defaults['_price_bounds_max'];
        }

        if ($priceBounds['max'] <= $priceBounds['min']) {
            $dMin = (isset($defaults['min_price']) && \is_numeric($defaults['min_price'])) ? (int) $defaults['min_price'] : 0;
            $dMax = (isset($defaults['max_price']) && \is_numeric($defaults['max_price'])) ? (int) $defaults['max_price'] : 5000;

            $priceBounds['min'] = $dMin;
            $priceBounds['max'] = ($dMax > $dMin) ? $dMax : ($dMin + 1000);
        }

        \ob_start();

        $renderField = function (string $fieldKey, string $context = 'bar') use (
            $value,
            $dateMode,
            $csvToArray,
            $priceBounds,
            $defaults,
            $uiQuery
        ) {
            $fieldKey = \trim($fieldKey);
            $context  = ($context === 'drawer') ? 'drawer' : 'bar';

            if ($fieldKey === '') {
                return;
            }

            switch ($fieldKey) {
                case 'term':
?>
                    <div class="md-field maradigma-filter">
                        <label class="md-label"><?php \esc_html_e('Search', 'maradigma'); ?></label>
                        <input class="md-input" type="text" name="md_term" value="<?php echo \esc_attr($value('term')); ?>" placeholder="ibiza / sunseeker / ..." />
                    </div>
                    <?php
                    break;

                case 'boat_capacity':
                    $currentBoatCapacity = $value('boat_capacity');

                    if ($context === 'drawer') :
                    ?>
                        <div class="md-field maradigma-filter">
                            <label class="md-label"><?php \esc_html_e('Min pax', 'maradigma'); ?></label>
                            <input
                                class="md-input"
                                type="number"
                                min="1"
                                step="1"
                                name="md_boat_capacity"
                                value="<?php echo \esc_attr($currentBoatCapacity); ?>"
                                placeholder="<?php echo \esc_attr__('e.g. 8', 'maradigma'); ?>" />
                        </div>
                    <?php
                    else :
                    ?>
                        <div class="md-filter-dd md-field" data-md-dd="1">
                            <button type="button" class="md-filter-pill" data-md-dd-toggle="1" aria-expanded="false">
                                <?php \esc_html_e('Min pax', 'maradigma'); ?>
                                <span class="md-caret">▾</span>
                            </button>

                            <div class="md-filter-menu" data-md-dd-panel="1" role="dialog" aria-modal="false">
                                <div class="md-filter-menu__inner">
                                    <div class="md-filter-menu__title"><?php \esc_html_e('Minimum passengers', 'maradigma'); ?></div>

                                    <input
                                        class="md-input"
                                        type="number"
                                        min="1"
                                        step="1"
                                        name="md_boat_capacity"
                                        value="<?php echo \esc_attr($currentBoatCapacity); ?>"
                                        placeholder="<?php echo \esc_attr__('e.g. 8', 'maradigma'); ?>" />
                                </div>

                                <div class="md-filter-menu__footer">
                                    <button type="button" class="md-dd-clear" data-md-dd-clear="1"><?php \esc_html_e('Clear', 'maradigma'); ?></button>
                                    <button type="button" class="md-dd-apply" data-md-dd-apply="1"><?php \esc_html_e('Apply', 'maradigma'); ?></button>
                                </div>
                            </div>
                        </div>
                    <?php
                    endif;
                    break;

                case 'price_range':
                    $rangeMinRaw = (string) ($priceBounds['min'] ?? '');
                    $rangeMaxRaw = (string) ($priceBounds['max'] ?? '');

                    $rangeMin = \is_numeric($rangeMinRaw) ? (int) $rangeMinRaw : 0;
                    $rangeMax = \is_numeric($rangeMaxRaw) ? (int) $rangeMaxRaw : 5000;

                    if ($rangeMax <= $rangeMin) {
                        $rangeMax = $rangeMin + 1000;
                    }

                    $curMinRaw = $value('min_price');
                    $curMaxRaw = $value('max_price');

                    $curMin = \is_numeric($curMinRaw) ? (int) $curMinRaw : $rangeMin;
                    $curMax = \is_numeric($curMaxRaw) ? (int) $curMaxRaw : $rangeMax;

                    $curMin = \max($rangeMin, \min($curMin, $rangeMax));
                    $curMax = \max($rangeMin, \min($curMax, $rangeMax));

                    if ($curMax < $curMin) {
                        $curMax = $curMin;
                    }

                    $step = 10;

                    if ($context === 'drawer') :
                    ?>
                        <div class="md-field maradigma-filter md-field--price-range">
                            <label class="md-label"><?php \esc_html_e('Price per day', 'maradigma'); ?></label>

                            <div
                                class="maradigma-price-range"
                                data-md-price-range="1"
                                data-range-min="<?php echo \esc_attr((string) $rangeMin); ?>"
                                data-range-max="<?php echo \esc_attr((string) $rangeMax); ?>"
                                data-step="<?php echo \esc_attr((string) $step); ?>"
                                data-value-min="<?php echo \esc_attr((string) $curMin); ?>"
                                data-value-max="<?php echo \esc_attr((string) $curMax); ?>">
                                <div class="maradigma-price-range__slider"></div>

                                <div class="maradigma-price-range__values">
                                    <span class="maradigma-price-range__min"><?php echo \esc_html((string) $curMin); ?> €</span>
                                    <span class="maradigma-price-range__sep">—</span>
                                    <span class="maradigma-price-range__max"><?php echo \esc_html((string) $curMax); ?> €</span>
                                </div>

                                <input type="hidden" name="md_min_price" value="<?php echo \esc_attr((string) $curMin); ?>" />
                                <input type="hidden" name="md_max_price" value="<?php echo \esc_attr((string) $curMax); ?>" />
                            </div>
                        </div>
                    <?php
                    else :
                    ?>
                        <div class="md-filter-dd md-field" data-md-dd="1">
                            <button type="button" class="md-filter-pill" data-md-dd-toggle="1" aria-expanded="false">
                                <?php \esc_html_e('Price', 'maradigma'); ?> <span class="md-caret">▾</span>
                            </button>

                            <div class="md-filter-menu" data-md-dd-panel="1" role="dialog" aria-modal="false">
                                <div class="md-filter-menu__inner">
                                    <div class="md-filter-menu__title"><?php \esc_html_e('Price per day', 'maradigma'); ?></div>

                                    <div
                                        class="maradigma-price-range"
                                        data-md-price-range="1"
                                        data-range-min="<?php echo \esc_attr((string) $rangeMin); ?>"
                                        data-range-max="<?php echo \esc_attr((string) $rangeMax); ?>"
                                        data-step="<?php echo \esc_attr((string) $step); ?>"
                                        data-value-min="<?php echo \esc_attr((string) $curMin); ?>"
                                        data-value-max="<?php echo \esc_attr((string) $curMax); ?>">
                                        <div class="maradigma-price-range__slider"></div>

                                        <div class="maradigma-price-range__values">
                                            <span class="maradigma-price-range__min"><?php echo \esc_html((string) $curMin); ?> €</span>
                                            <span class="maradigma-price-range__sep">—</span>
                                            <span class="maradigma-price-range__max"><?php echo \esc_html((string) $curMax); ?> €</span>
                                        </div>

                                        <input type="hidden" name="md_min_price" value="<?php echo \esc_attr((string) $curMin); ?>" />
                                        <input type="hidden" name="md_max_price" value="<?php echo \esc_attr((string) $curMax); ?>" />
                                    </div>
                                </div>

                                <div class="md-filter-menu__footer">
                                    <button type="button" class="md-dd-clear" data-md-dd-clear="1"><?php \esc_html_e('Clear', 'maradigma'); ?></button>
                                    <button type="button" class="md-dd-apply" data-md-dd-apply="1"><?php \esc_html_e('Apply', 'maradigma'); ?></button>
                                </div>
                            </div>
                        </div>
                    <?php
                    endif;
                    break;

                case 'featured':
                    ?>
                    <label class="md-check">
                        <input type="checkbox" name="md_featured" value="1" <?php \checked(self::getUiQuery('featured', $uiQuery), '1'); ?> />
                        <span><?php \esc_html_e('Featured only', 'maradigma'); ?></span>
                    </label>
                <?php
                    break;

                case 'ins_book':
                ?>
                    <label class="md-check">
                        <input type="checkbox" name="md_ins_book" value="1" <?php \checked(self::getUiQuery('ins_book', $uiQuery), '1'); ?> />
                        <span><?php \esc_html_e('Instant booking', 'maradigma'); ?></span>
                    </label>
                    <?php
                    break;

                case 'boat_type_id':
                    $selectedType = (string) self::getUiQuery('boat_type_id', $uiQuery);

                    if ($context === 'drawer') :
                    ?>
                        <div class="md-field maradigma-filter">
                            <label class="md-label"><?php \esc_html_e('Boat type', 'maradigma'); ?></label>

                            <select
                                class="md-select"
                                name="md_boat_type_id"
                                data-md-select2="1"
                                data-maradigma-source="boat_types"
                                data-multiple="0">
                                <option value=""><?php \esc_html_e('Any', 'maradigma'); ?></option>
                                <?php if ($selectedType !== ''): ?>
                                    <option value="<?php echo \esc_attr($selectedType); ?>" selected><?php echo \esc_html('Type #' . $selectedType); ?></option>
                                <?php endif; ?>
                            </select>
                        </div>
                    <?php
                    else :
                    ?>
                        <div class="md-filter-dd md-field" data-md-dd="1">
                            <button type="button" class="md-filter-pill" data-md-dd-toggle="1" aria-expanded="false">
                                <?php \esc_html_e('Rental type', 'maradigma'); ?> <span class="md-caret">▾</span>
                            </button>

                            <div class="md-filter-menu" data-md-dd-panel="1" role="dialog" aria-modal="false">
                                <div class="md-filter-menu__inner">
                                    <div class="md-filter-menu__title"><?php \esc_html_e('Boat type', 'maradigma'); ?></div>

                                    <select
                                        class="md-select"
                                        name="md_boat_type_id"
                                        data-md-select2="1"
                                        data-maradigma-source="boat_types"
                                        data-multiple="0">
                                        <option value=""><?php \esc_html_e('Any', 'maradigma'); ?></option>
                                        <?php if ($selectedType !== ''): ?>
                                            <option value="<?php echo \esc_attr($selectedType); ?>" selected><?php echo \esc_html('Type #' . $selectedType); ?></option>
                                        <?php endif; ?>
                                    </select>
                                </div>

                                <div class="md-filter-menu__footer">
                                    <button type="button" class="md-dd-clear" data-md-dd-clear="1"><?php \esc_html_e('Clear', 'maradigma'); ?></button>
                                    <button type="button" class="md-dd-apply" data-md-dd-apply="1"><?php \esc_html_e('Apply', 'maradigma'); ?></button>
                                </div>
                            </div>
                        </div>
                    <?php
                    endif;
                    break;

                case 'builders':
                    $selectedBuilders = $csvToArray((string) self::getUiQuery('builders', $uiQuery));
                    $selectedBuilder  = isset($selectedBuilders[0]) ? (string) $selectedBuilders[0] : '';
                    $selectedBuilderLabel = ($selectedBuilder !== '')
                        ? self::resolveBoatBuilderDisplayName(
                            $selectedBuilder,
                            (string) ($defaults['builders_labels_json'] ?? '')
                        )
                        : '';
                    ?>
                    <?php
                    $buildersOptions = self::normalizeBuildersOptionsMode((string) ($defaults['builders_options'] ?? 'api'));
                    $availableBuilderOptions = \is_array($defaults['available_builder_options'] ?? null)
                        ? (array) $defaults['available_builder_options']
                        : [];
                    $useSearchResultBuilders = ($buildersOptions === 'search_result');
                    $selectedBuilderFound = false;
                    ?>
                    <div class="md-field maradigma-filter">
                        <label class="md-label"><?php \esc_html_e('Builder', 'maradigma'); ?></label>

                        <select
                            class="md-select"
                            name="md_builders"
                            data-md-select2="1"
                            <?php if ($useSearchResultBuilders): ?>data-md-select-ui="tom"<?php endif; ?>
                            data-placeholder="Builder"
                            <?php if (!$useSearchResultBuilders): ?>data-maradigma-source="builders"<?php endif; ?>
                            data-multiple="0">
                            <option value=""><?php if (!$useSearchResultBuilders) { \esc_html_e('Any', 'maradigma'); } ?></option>
                            <?php if ($useSearchResultBuilders): ?>
                                <?php foreach ($availableBuilderOptions as $builderOption): ?>
                                    <?php
                                    if (!\is_array($builderOption)) {
                                        continue;
                                    }
                                    $builderOptionId = \trim((string) ($builderOption['id'] ?? ''));
                                    $builderOptionName = \trim((string) ($builderOption['name'] ?? $builderOption['text'] ?? ''));
                                    if ($builderOptionId === '' || $builderOptionName === '') {
                                        continue;
                                    }
                                    $isSelectedBuilder = ($selectedBuilder !== '' && $builderOptionId === $selectedBuilder);
                                    if ($isSelectedBuilder) {
                                        $selectedBuilderFound = true;
                                    }
                                    ?>
                                    <option value="<?php echo \esc_attr($builderOptionId); ?>" <?php \selected($isSelectedBuilder); ?>>
                                        <?php echo \esc_html($builderOptionName); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <?php if ($selectedBuilder !== '' && (!$useSearchResultBuilders || !$selectedBuilderFound)): ?>
                                <option value="<?php echo \esc_attr($selectedBuilder); ?>" selected>
                                    <?php echo \esc_html($selectedBuilderLabel !== '' ? $selectedBuilderLabel : ('Builder #' . $selectedBuilder)); ?>
                                </option>
                            <?php endif; ?>
                        </select>
                    </div>
                <?php
                    break;

                case 'tags':
                    $selectedTags = $csvToArray((string) self::getUiQuery('tags', $uiQuery));
                ?>
                    <div class="md-field maradigma-filter">
                        <label class="md-label"><?php \esc_html_e('Tags', 'maradigma'); ?></label>

                        <input type="hidden" name="md_tags" value="<?php echo \esc_attr(\implode(',', $selectedTags)); ?>" data-md-csv-hidden="tags" />

                        <select
                            class="md-select"
                            multiple
                            data-md-csv-source="tags"
                            data-md-select2="1"
                            data-maradigma-source="tags"
                            data-multiple="1">
                            <?php foreach ($selectedTags as $id): ?>
                                <?php $id = \trim((string) $id); ?>
                                <?php if ($id === '') {
                                    continue;
                                } ?>
                                <option value="<?php echo \esc_attr($id); ?>" selected><?php echo \esc_html('ID #' . $id); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php
                    break;

                case 'ids_gi':
                    $selectedBoatId = \trim((string) self::getUiQuery('ids_gi', $uiQuery));
                ?>
                    <div class="md-field maradigma-filter">
                        <label class="md-label"><?php \esc_html_e('Specific boat', 'maradigma'); ?></label>

                        <select
                            class="md-select"
                            name="md_ids_gi"
                            data-md-select2="1"
                            data-maradigma-source="boats"
                            data-multiple="0">
                            <option value=""><?php \esc_html_e('Any', 'maradigma'); ?></option>
                            <?php if ($selectedBoatId !== ''): ?>
                                <option value="<?php echo \esc_attr($selectedBoatId); ?>" selected>
                                    <?php echo \esc_html('Boat #' . $selectedBoatId); ?>
                                </option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <?php
                    break;

                case 'date_start':
                    if ($dateMode === 'range') {
                        $ds = $value('date_start');
                        $de = $value('date_end');

                        $rangeVal = '';
                        if ($ds !== '' && $de !== '') {
                            $rangeVal = $ds . ' - ' . $de;
                        }
                    ?>
                        <div class="md-field md-field--date maradigma-filter">
                            <div class="md-date-range-control">
                                <span class="md-date-range-control__icon" aria-hidden="true">
                                    <svg class="md-date-range-control__svg" width="16" height="16" aria-hidden="true" focusable="false">
                                        <use href="#svg-calendar" xlink:href="#svg-calendar"></use>
                                    </svg>
                                </span>

                                <input
                                type="text"
                                class="md-input md-date-toggle maradigma-date-range"
                                data-md-date-range="1"
                                name="md_date_range"
                                value="<?php echo \esc_attr($rangeVal); ?>"
                                placeholder="<?php echo \esc_attr__('Check-in → Check-out', 'maradigma'); ?>"
                                    autocomplete="off" />

                                <button type="button" class="md-date-range-control__clear" data-md-date-range-clear="1" aria-label="<?php echo \esc_attr__('Clear', 'maradigma'); ?>">
                                    <svg class="md-date-range-control__clear-svg" width="10" height="10" aria-hidden="true" focusable="false">
                                        <use href="#svg-xmark" xlink:href="#svg-xmark"></use>
                                    </svg>
                                </button>
                            </div>

                            <input type="hidden" name="md_date_start" value="<?php echo \esc_attr($ds); ?>" />
                            <input type="hidden" name="md_date_end" value="<?php echo \esc_attr($de); ?>" />
                        </div>
                    <?php
                        break;
                    }
                    ?>
                    <div class="md-field maradigma-filter">
                        <label class="md-label"><?php \esc_html_e('Date start', 'maradigma'); ?></label>
                        <input class="md-input maradigma-date" type="text" name="md_date_start" value="<?php echo \esc_attr($value('date_start')); ?>" placeholder="YYYY-MM-DD" autocomplete="off" />
                    </div>
                <?php
                    break;

                case 'date_end':
                    if ($dateMode === 'range') {
                        break;
                    }
                ?>
                    <div class="md-field maradigma-filter">
                        <label class="md-label"><?php \esc_html_e('Date end', 'maradigma'); ?></label>
                        <input class="md-input maradigma-date" type="text" name="md_date_end" value="<?php echo \esc_attr($value('date_end')); ?>" placeholder="YYYY-MM-DD" autocomplete="off" />
                    </div>
        <?php
                    break;

                case 'order_by':
                    $selectedOrder = $value('order_by');
                    if (!\in_array($selectedOrder, ['0', '1', '2', '3', '4', '5', '6'], true)) {
                        $selectedOrder = '0';
                    }
                    ?>
                    <div class="md-sortby">
                        <span class="md-sortby__label"><?php \esc_html_e('Sort by', 'maradigma'); ?></span>
                        <select class="md-select" name="md_order_by" style="min-width:220px;">
                            <option value="0" <?php \selected($selectedOrder, '0'); ?>><?php \esc_html_e('Relevance', 'maradigma'); ?></option>
                            <option value="1" <?php \selected($selectedOrder, '1'); ?>><?php \esc_html_e('Price: low to high', 'maradigma'); ?></option>
                            <option value="2" <?php \selected($selectedOrder, '2'); ?>><?php \esc_html_e('Price: high to low', 'maradigma'); ?></option>
                            <option value="6" <?php \selected($selectedOrder, '6'); ?>><?php \esc_html_e('Length: low to high', 'maradigma'); ?></option>
                            <option value="5" <?php \selected($selectedOrder, '5'); ?>><?php \esc_html_e('Length: high to low', 'maradigma'); ?></option>
                            <option value="3" <?php \selected($selectedOrder, '3'); ?>><?php \esc_html_e('Featured first', 'maradigma'); ?></option>
                            <option value="4" <?php \selected($selectedOrder, '4'); ?>><?php \esc_html_e('Newest first', 'maradigma'); ?></option>
                        </select>
                    </div>
                    <?php
                    break;

                default:
                    break;
            }
        };
        ?>
        <form
            class="<?php echo \esc_attr($class); ?>"
            method="get"
            action="<?php echo \esc_url($action); ?>"
            <?php if ($auto) : ?>data-autosubmit="1"<?php endif; ?>
            <?php if ($archiveEndpoint !== ''): ?>
            data-md-boats-archive-url="<?php echo \esc_attr($archiveEndpoint); ?>"
            <?php endif; ?>
            <?php if ($archiveTargetId !== ''): ?>
            data-md-boats-archive-target="#<?php echo \esc_attr($archiveTargetId); ?>"
            <?php endif; ?>
            >
            <?php
            // Preserve unrelated public query parameters in this shareable GET form.
            // phpcs:disable WordPress.Security.NonceVerification.Recommended
            $publicQuery = \map_deep(\wp_unslash($_GET), 'sanitize_text_field');
            // phpcs:enable WordPress.Security.NonceVerification.Recommended
            foreach ($publicQuery as $k => $v) {
                $k = (string) $k;

                if ($k === 'md_page') {
                    continue;
                }

                if (\str_starts_with($k, 'md_')) {
                    continue;
                }

                if (\is_array($v)) {
                    continue;
                }

                echo '<input type="hidden" name="' . \esc_attr($k) . '" value="' . \esc_attr((string) $v) . '">';
            }

            echo '<input type="hidden" name="md_page" value="1">';
            ?>

            <div class="md-filterbar">
                <div class="md-filterbar__left">
                    <?php foreach ($fields as $fieldKey): ?>
                        <?php $renderField((string) $fieldKey, 'bar'); ?>
                    <?php endforeach; ?>

                    <?php if ($showMoreBtn): ?>
                        <button type="button" class="md-filterbar__more" data-md-drawer-open="1" aria-controls="<?php echo \esc_attr($drawerId); ?>">
                            <?php echo \esc_html($moreBtnText); ?>
                        </button>
                    <?php endif; ?>

                    <?php if ($showReset): ?>
                        <?php
                        $resetKeys = ['md_page', 'md_date_range'];

                        foreach ($fields as $f) {
                            $f = (string) $f;

                            if ($f === 'price_range') {
                                $resetKeys[] = 'md_min_price';
                                $resetKeys[] = 'md_max_price';
                                continue;
                            }

                            $resetKeys[] = 'md_' . $f;
                        }

                        foreach (\array_merge($drawerFields, $rightFields) as $f) {
                            $f = (string) $f;

                            if ($f === 'price_range') {
                                $resetKeys[] = 'md_min_price';
                                $resetKeys[] = 'md_max_price';
                                continue;
                            }

                            $resetKeys[] = 'md_' . $f;
                        }
                        $resetUrl = \remove_query_arg($resetKeys);
                        ?>
                        <a class="md-btn md-btn--ghost" href="<?php echo \esc_url($resetUrl); ?>">
                            <?php \esc_html_e('Reset', 'maradigma'); ?>
                        </a>
                    <?php endif; ?>

                    <?php if (!$auto): ?>
                        <button type="submit" class="md-btn">
                            <?php \esc_html_e('Apply', 'maradigma'); ?>
                        </button>
                    <?php endif; ?>
                </div>

                <?php if (!empty($rightFields)): ?>
                    <div class="md-filterbar__right">
                        <?php foreach ($rightFields as $fieldKey): ?>
                            <?php $renderField((string) $fieldKey, 'bar'); ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($showMoreBtn): ?>
                <div
                    class="md-drawer-overlay"
                    data-md-drawer-overlay="1"
                    aria-hidden="true"
                    style="display:none;">
                </div>

                <aside
                    class="md-drawer"
                    id="<?php echo \esc_attr($drawerId); ?>"
                    aria-labelledby="<?php echo \esc_attr($drawerLabelId); ?>"
                    aria-hidden="true"
                    style="display:none;">
                    <div class="md-drawer__header">
                        <h3 class="md-drawer__title" id="<?php echo \esc_attr($drawerLabelId); ?>">
                            <?php echo \esc_html($drawerTitle); ?>
                        </h3>
                        <button type="button" class="md-drawer__close" data-md-drawer-close="1" aria-label="<?php echo \esc_attr__('Close', 'maradigma'); ?>">×</button>
                    </div>

                    <div class="md-drawer__body">
                        <div class="md-drawer__grid">
                            <?php
                            $df = (\is_array($drawerFields) && !empty($drawerFields)) ? $drawerFields : [];
                            foreach ($df as $fieldKey) {
                                $renderField((string) $fieldKey, 'drawer');
                            }
                            ?>
                        </div>
                    </div>

                    <?php if (!$auto): ?>
                        <div class="md-drawer__footer">
                            <button type="submit" class="md-btn" style="width:100%;justify-content:center;">
                                <?php \esc_html_e('Apply filters', 'maradigma'); ?>
                            </button>
                        </div>
                    <?php endif; ?>
                </aside>
            <?php endif; ?>
        </form>
        <?php

        return (string) \ob_get_clean();
    }
    /**
     * Normalizes builders options mode.
     */
    private static function normalizeBuildersOptionsMode(string $mode): string
    {
        $mode = \strtolower(\trim($mode));

        return \in_array($mode, ['api', 'search_result'], true) ? $mode : 'api';
    }

    /**
     * @param mixed $value
     * @return list<int>
     */
    private static function normalizePositiveIdList($value): array
    {
        $rawValues = \is_array($value)
            ? $value
            : \preg_split('/\s*,\s*/', (string) $value);

        if (!\is_array($rawValues)) {
            return [];
        }

        $ids = [];
        foreach ($rawValues as $rawValue) {
            if (\is_array($rawValue)) {
                continue;
            }

            $rawValue = \trim((string) $rawValue);
            if ($rawValue === '' || !\is_numeric($rawValue)) {
                continue;
            }

            $id = (int) $rawValue;
            if ($id > 0) {
                $ids[$id] = true;
            }
        }

        return \array_keys($ids);
    }
    /**
     * @param list<int> $builderIds
     * @return list<array{id:string,name:string}>
     */
    private static function buildBoatBuilderOptionsFromIds(array $builderIds, string $labelsJson = ''): array
    {
        if (empty($builderIds)) {
            return [];
        }

        $builderNames = self::getBoatBuilderNameMap();
        $options = [];

        foreach ($builderIds as $builderId) {
            $id = \trim((string) $builderId);
            if ($id === '') {
                continue;
            }

            $name = self::resolveLabelFromJsonMap($id, $labelsJson);
            if ($name === '' && isset($builderNames[$id])) {
                $name = (string) $builderNames[$id];
            }
            if ($name === '') {
                $name = 'Builder #' . $id;
            }

            $options[] = [
                'id' => $id,
                'name' => $name,
            ];
        }

        return $options;
    }

    /**
     * Resolves boat builder display name.
     */
    private static function resolveBoatBuilderDisplayName(string $builderId, string $labelsJson = ''): string
    {
        $builderId = \trim($builderId);

        if ($builderId === '') {
            return '';
        }

        $label = self::resolveLabelFromJsonMap($builderId, $labelsJson);
        if ($label !== '') {
            return $label;
        }

        $builderNames = self::getBoatBuilderNameMap();

        return isset($builderNames[$builderId]) ? (string) $builderNames[$builderId] : '';
    }

    /**
     * @return array<string,string>
     */
    private static function getBoatBuilderNameMap(): array
    {
        static $builderNames = null;

        if (\is_array($builderNames)) {
            return $builderNames;
        }

        $builderNames = [];

        try {
            $cache  = new Cache();
            $result = $cache->getBoatBuilders();

            $builders = $result['data'] ?? [];
            if (isset($builders['data']) && \is_array($builders['data'])) {
                $builders = $builders['data'];
            }

            if (!\is_array($builders)) {
                return $builderNames;
            }

            foreach ($builders as $builder) {
                if (!\is_array($builder)) {
                    continue;
                }

                $id = \trim((string) ($builder['id'] ?? $builder['id_gi'] ?? $builder['id_group_item'] ?? ''));
                $name = \trim((string) ($builder['name'] ?? $builder['text'] ?? $builder['title'] ?? ''));

                if ($id === '' || $name === '') {
                    continue;
                }

                $builderNames[$id] = $name;
            }
        } catch (\Throwable $e) {
            return $builderNames;
        }

        return $builderNames;
    }

    /**
     * Resolves label from json map.
     */
    private static function resolveLabelFromJsonMap(string $id, string $labelsJson): string
    {
        $id = \trim($id);
        $labelsJson = \trim($labelsJson);

        if ($id === '' || $labelsJson === '') {
            return '';
        }

        $decoded = \json_decode($labelsJson, true);
        if (!\is_array($decoded)) {
            return '';
        }

        if (isset($decoded[$id]) && \is_scalar($decoded[$id])) {
            return \trim((string) $decoded[$id]);
        }

        foreach ($decoded as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $rowId = \trim((string) ($row['id'] ?? $row['value'] ?? ''));
            if ($rowId !== $id) {
                continue;
            }

            return \trim((string) ($row['text'] ?? $row['label'] ?? $row['name'] ?? ''));
        }

        return '';
    }

    /**
     * Build the full archive context for boats listing rendering.
     *
     * This method centralizes the SSR/AJAX logic so both the shortcode renderer
     * and the REST archive endpoint can reuse the exact same preparation flow.
     *
     * @param array<string,mixed>        $atts
     * @param array<string,string>|null  $uiQuery
     *
     * @return array<string,mixed>
     */
    public static function buildBoatsArchiveContext(array $atts = [], ?array $uiQuery = null): array
    {
        if (!\function_exists('shortcode_atts')) {
            return [];
        }

        $defaultRightFieldsSentinel = '__maradigma_default_right_fields__';

        $atts = \shortcode_atts(
            \array_replace(
                self::getBoatsSearchAttributeDefaults(),
                [
                // legacy aliases
                'q'        => '',
                'port'     => '',
                'people'   => '',

                'limit_services'  => '10',
                'offset_services' => '0',

                // routing/template
                'id_group' => 'boats',
                'card'     => '',
                'lang'     => '',
                'current_lang' => '',
                'md_lang'      => '',

                // image token
                'image_token' => '',

                // public filters
                'boat_type_id'         => '',
                'builders'             => '',
                'builders_labels_json' => '',
                'builders_options'     => 'api',

                // UI
                'show_filters'        => '0',
                'autosubmit_filters'  => '0',
                'allow_url_filters'   => '0',

                // UI fields config
                'filters_ui_fields'           => '',
                'filters_ui_fields_left'      => '',
                'filters_ui_fields_right'     => $defaultRightFieldsSentinel,
                'filters_ui_fields_offcanvas' => '',
                'filters_ui_layout'           => 'horizontal',
                'filters_ui_submit_mode'      => 'auto',
                'filters_ui_show_reset'       => '1',

                // offcanvas
                'show_more_filters_button'     => '0',
                'more_filters_button_text'     => '',
                'more_filters_offcanvas_title' => '',
                'date_picker_mode'             => 'range',
                ]
            ),
            $atts,
            'maradigma_boats'
        );

        $dateMode = ((string) ($atts['date_picker_mode'] ?? 'range') === 'separate') ? 'separate' : 'range';
        $buildersOptions = self::normalizeBuildersOptionsMode((string) ($atts['builders_options'] ?? 'api'));
        $atts['builders_options'] = $buildersOptions;

        $showFilters       = ((string) ($atts['show_filters'] ?? '0') === '1');
        $autoSubmitFilters = ((string) ($atts['autosubmit_filters'] ?? '0') === '1');
        $allowUrlFilters   = ((string) ($atts['allow_url_filters'] ?? '0') === '1');

        $uiLayout     = ((string) ($atts['filters_ui_layout'] ?? 'horizontal') === 'vertical') ? 'vertical' : 'horizontal';
        $uiSubmitMode = ((string) ($atts['filters_ui_submit_mode'] ?? 'auto') === 'button') ? 'button' : 'auto';
        $uiShowReset  = ((string) ($atts['filters_ui_show_reset'] ?? '1') === '1');

        $showMoreBtn = ((string) ($atts['show_more_filters_button'] ?? '0') === '1');

        $moreBtnText = \trim((string) ($atts['more_filters_button_text'] ?? ''));
        if ($moreBtnText === '') {
            $moreBtnText = \esc_html__('More filters', 'maradigma');
        }

        $offcanvasTitle = \trim((string) ($atts['more_filters_offcanvas_title'] ?? ''));
        if ($offcanvasTitle === '') {
            $offcanvasTitle = $moreBtnText;
        }

        $parseUiFieldsCsv = static function (string $csv): array {
            $csv = \trim($csv);

            if ($csv === '') {
                return [];
            }

            $parts = \array_map('trim', \explode(',', $csv));

            return \array_values(\array_filter($parts, static fn($value) => $value !== ''));
        };

        $uiFieldsCsv = \trim((string) ($atts['filters_ui_fields'] ?? ''));
        $uiFields = $parseUiFieldsCsv($uiFieldsCsv);

        if (empty($uiFields)) {
            $uiFields = ['term', 'boat_capacity', 'min_price', 'max_price', 'featured', 'ins_book', 'date_start', 'date_end'];
        }

        $allowedKeys = [
            'term',
            'boat_capacity',
            'featured',
            'ins_book',
            'order_by',
            'min_price',
            'max_price',
            'boat_type_id',
            'builders',
            'ids_gi',
            'date_start',
            'date_end',
            'tags',
        ];

        $uiFields = \array_values(\array_intersect($uiFields, $allowedKeys));
        if (empty($uiFields)) {
            $uiFields = ['term', 'boat_capacity'];
        }

        if ($dateMode === 'range') {
            $uiFields = self::normalizeDateRangeUiFields($uiFields);
        }

        $uiLeftCsv = \trim((string) ($atts['filters_ui_fields_left'] ?? ''));
        $uiLeftFields = \array_values(\array_intersect($parseUiFieldsCsv($uiLeftCsv), $allowedKeys));

        if ($dateMode === 'range') {
            $uiLeftFields = self::normalizeDateRangeUiFields($uiLeftFields);
        }

        foreach ($uiLeftFields as $leftField) {
            if (!\in_array($leftField, $uiFields, true)) {
                $uiFields[] = $leftField;
            }
        }

        $uiRightCsv = \trim((string) ($atts['filters_ui_fields_right'] ?? $defaultRightFieldsSentinel));
        $useDefaultRightFields = ($uiRightCsv === $defaultRightFieldsSentinel);

        if ($useDefaultRightFields) {
            $uiRightFields = (\in_array('order_by', $uiFields, true) && !\in_array('order_by', $uiLeftFields, true))
                ? ['order_by']
                : [];
            if (!empty($uiRightFields)) {
                $uiFields = \array_values(\array_filter(
                    $uiFields,
                    static fn($value) => !\in_array((string) $value, $uiRightFields, true)
                ));
            }
        } else {
            $uiRightFields = \array_values(\array_intersect($parseUiFieldsCsv($uiRightCsv), $allowedKeys));

            if ($dateMode === 'range') {
                $uiRightFields = self::normalizeDateRangeUiFields($uiRightFields);
            }

            if (!empty($uiLeftFields)) {
                $uiRightFields = \array_values(\array_filter(
                    $uiRightFields,
                    static fn($value) => !\in_array((string) $value, $uiLeftFields, true)
                ));
            }

            if (!empty($uiRightFields)) {
                $uiFields = \array_values(\array_filter(
                    $uiFields,
                    static fn($value) => !\in_array((string) $value, $uiRightFields, true)
                ));
            }
        }

        if (!empty($uiLeftFields)) {
            $uiRightFields = \array_values(\array_filter(
                $uiRightFields,
                static fn($value) => !\in_array((string) $value, $uiLeftFields, true)
            ));
        }

        $uiOffCsv = \trim((string) ($atts['filters_ui_fields_offcanvas'] ?? ''));
        $uiOffFields = $parseUiFieldsCsv($uiOffCsv);

        $uiOffFields = \array_values(\array_intersect($uiOffFields, $allowedKeys));
        if (empty($uiOffFields)) {
            $uiOffFields = ['boat_type_id', 'builders', 'ids_gi'];
        }

        if ($dateMode === 'range') {
            $uiOffFields = self::normalizeDateRangeUiFields($uiOffFields);
        }

        if ($allowUrlFilters) {
            // Public archive filters are intentionally shareable GET parameters.
            // phpcs:disable WordPress.Security.NonceVerification.Recommended
            $get = \map_deep(\wp_unslash($_GET), 'sanitize_text_field');
            // phpcs:enable WordPress.Security.NonceVerification.Recommended

            foreach ($allowedKeys as $key) {
                $param = 'md_' . $key;

                if (!isset($get[$param])) {
                    continue;
                }

                $value = $get[$param];

                if (\is_array($value)) {
                    continue;
                }

                $value = \trim((string) $value);

                $atts[$key] = ($value !== '') ? $value : '';
            }
        }

        if (\is_array($uiQuery)) {
            foreach ($allowedKeys as $key) {
                $param = 'md_' . $key;

                if (!\array_key_exists($param, $uiQuery)) {
                    continue;
                }

                $value = \trim((string) $uiQuery[$param]);
                $atts[$key] = ($value !== '') ? $value : '';
            }
        }

        if (!\is_array($uiQuery)) {
            $uiQuery = [];
        }

        if (
            !\array_key_exists('md_boat_type_id', $uiQuery)
            && \trim((string) ($atts['boat_type_id'] ?? '')) !== ''
        ) {
            $uiQuery['md_boat_type_id'] = \trim((string) $atts['boat_type_id']);
        }

        if (
            !\array_key_exists('md_builders', $uiQuery)
            && \trim((string) ($atts['builders'] ?? '')) !== ''
        ) {
            $uiQuery['md_builders'] = \trim((string) $atts['builders']);
        }

        $boundsMin = isset($atts['min_price']) ? \trim((string) $atts['min_price']) : '';
        $boundsMax = isset($atts['max_price']) ? \trim((string) $atts['max_price']) : '';

        $filters = Sanitizer::normalizeBoatsSearchAtts($atts, self::$DOC_SEARCH_BOATS_ATTRS);

        $rawPage = '1';

        if (\is_array($uiQuery) && isset($uiQuery['md_page'])) {
            $rawPage = (string) $uiQuery['md_page'];
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public archive pagination.
        } elseif (isset($_GET['md_page'])) {
            // Public archive pagination is an intentionally shareable GET parameter.
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $rawPage = (string) \absint(\wp_unslash($_GET['md_page']));
        }

        $page = (int) \preg_replace('/[^0-9]/', '', $rawPage);

        if ($page < 1) {
            $page = 1;
        }

        $perPage = isset($filters['limit_services']) ? (int) $filters['limit_services'] : 10;
        if ($perPage < 1) {
            $perPage = 10;
        }
        if ($perPage > 60) {
            $perPage = 60;
        }

        $baseOffset = isset($filters['offset_services']) ? (int) $filters['offset_services'] : 0;
        if ($baseOffset < 0) {
            $baseOffset = 0;
        }

        $filters['limit_services']  = $perPage;
        $filters['offset_services'] = $baseOffset + (($page - 1) * $perPage);

        $settings = SettingsPage::getSettings();

        /*
         * Resolve the selected card before requesting the archive. The BCH price
         * criterion must describe the amount rendered by that card, otherwise
         * price sorting/filtering and the visible amount can disagree.
         */
        $cardId = isset($atts['card']) ? \trim((string) $atts['card']) : '';
        if ($cardId === '') {
            $cardId = \Maradigma\BoatCardRepository::getDefaultCardId();
        }

        $card = \Maradigma\BoatCardRepository::getCard($cardId);
        if (!$card || empty($card['template'])) {
            $card = \Maradigma\BoatCardRepository::getCard(\Maradigma\BoatCardRepository::DEFAULT_CARD_ID);
        }

        $template = (string) ($card['template'] ?? '');
        if (\trim($template) === '') {
            $template = (string) (\Maradigma\BoatCardRepository::getCard(\Maradigma\BoatCardRepository::DEFAULT_CARD_ID)['template'] ?? '');
        }

        $filters = self::applyUndatedPriceOrderCriteria($filters, $template);

        $defaultLang = self::normalizeLanguageSlug((string) ($settings['default_language'] ?? 'en'));
        if ($defaultLang === '') {
            $defaultLang = 'en';
        }

        $currentLang = self::resolveArchiveCurrentLanguage($atts, $defaultLang);

        AssetsManager::enqueueBoatCardsAssets();

        if ($showFilters) {
            AssetsManager::enqueueArchiveFiltersAssets();

            $remoteSelectFields = ['boat_type_id', 'builders', 'ids_gi', 'tags'];
            $needsRemoteSelect = false;

            foreach (\array_merge($uiFields, $uiOffFields) as $fieldKey) {
                if (\in_array((string) $fieldKey, $remoteSelectFields, true)) {
                    $needsRemoteSelect = true;
                    break;
                }
            }

            if ($needsRemoteSelect) {
                AssetsManager::enqueueFrontendRemoteSelect2Assets();
            }
        }

        $apiClient = \Maradigma\ExternalApiClient::fromSettings($settings);
        $apiClient->setLanguage($currentLang);

        $cache  = new Cache($apiClient);
        $result = $cache->getBoatsList($filters);

        $boats = [];
        if (\is_array($result['data']['search_result'] ?? null)) {
            $boats = (array) $result['data']['search_result'];
        }

        $availableBuilderOptions = [];
        if ($buildersOptions === 'search_result') {
            $availableBuilderOptions = self::buildBoatBuilderOptionsFromIds(
                self::normalizePositiveIdList($result['data']['available_boat_id_builders'] ?? []),
                (string) ($atts['builders_labels_json'] ?? '')
            );
        }



        $totalResults = 0;
        if (isset($result['data']['total_results'])) {
            $totalResults = (int) $result['data']['total_results'];
        } else {
            $totalResults = \is_array($boats) ? \count($boats) : 0;
        }

        $totalPages = ($perPage > 0) ? (int) \ceil($totalResults / $perPage) : 1;
        if ($totalPages < 1) {
            $totalPages = 1;
        }

        $imageToken = \trim((string) ($atts['image_token'] ?? ''));

        $allowedImageTokens = ['image_main'];

        if (
            \class_exists(\Maradigma\BoatImagesSyncService::class)
            && \method_exists(\Maradigma\BoatImagesSyncService::class, 'getAllowedImageTokens')
        ) {
            $allowedImageTokens = (array) \Maradigma\BoatImagesSyncService::getAllowedImageTokens();
            if (!\in_array('image_main', $allowedImageTokens, true)) {
                $allowedImageTokens[] = 'image_main';
            }
        }

        if ($imageToken === '') {
            $imageToken = 'image_main';
        }

        if (!\in_array($imageToken, $allowedImageTokens, true)) {
            $imageToken = 'image_main';
        }

        $template = self::normalizeBoatCardTemplateImageToken($template, $imageToken);

        $boatPagesSyncEnabled = !empty($settings['enable_boat_pages_sync']);

        $baseSlug = SettingsPage::pickLocalizedValue(
            (string) ($settings['boats_base_slug'] ?? 'boats'),
            $currentLang,
            $defaultLang
        );

        $baseSlug = \trim((string) $baseSlug, '/');
        if ($baseSlug === '') {
            $baseSlug = 'boats';
        }

        $context = [
            'boats_base_slug'  => $baseSlug,
            'prefer_wp_images' => !empty($settings['store_boat_images_locally']),
            'current_lang'     => $currentLang,
            'date_start'       => \trim((string) ($filters['date_start'] ?? '')),
            'date_end'         => \trim((string) ($filters['date_end'] ?? '')),
        ];

        $boatIds = [];
        foreach ($boats as $boatRow) {
            if (!\is_array($boatRow)) {
                continue;
            }

            $id = \trim((string) ($boatRow['id'] ?? $boatRow['id_gi'] ?? $boatRow['id_group_item'] ?? ''));
            if ($id !== '') {
                $boatIds[$id] = true;
            }
        }

        $boatIds = \array_keys($boatIds);

        $linkedWpBoatMap = [];
        $permalinkByBoatId = [];

        if (!empty($boatIds)) {
            $linkedWpBoatMap = self::getLinkedWpBoatPostsMap($boatIds, $currentLang);

            foreach ($linkedWpBoatMap as $boatId => $row) {
                $url = isset($row['url']) && \is_string($row['url']) ? \trim((string) $row['url']) : '';
                if ($url !== '') {
                    $permalinkByBoatId[$boatId] = $url;
                }
            }

            if ($boatPagesSyncEnabled) {
                $q = new \WP_Query([
                    'post_type'      => \Maradigma\BoatPostType::POST_TYPE,
                    'post_status'    => 'any',
                    'fields'         => 'ids',
                    'posts_per_page' => -1,
                    'no_found_rows'  => true,
                    'lang'           => '',
                    'meta_query'     => [
                        [
                            'key'     => '_maradigma_boat_id',
                            'value'   => $boatIds,
                            'compare' => 'IN',
                        ],
                    ],
                ]);

                if (!empty($q->posts) && \is_array($q->posts)) {
                    $bucket = [];

                    foreach ($q->posts as $pid) {
                        $pid = (int) $pid;
                        if ($pid <= 0) {
                            continue;
                        }

                        $bid = \trim((string) \get_post_meta($pid, '_maradigma_boat_id', true));
                        if ($bid === '') {
                            continue;
                        }

                        $pl = '';
                        if (\function_exists('pll_get_post_language')) {
                            $pl = \strtolower(\trim((string) \pll_get_post_language($pid, 'slug')));
                            $pl = (string) (\preg_split('/[_-]/', $pl)[0] ?? $pl);
                            $pl = \strtolower(\trim($pl));
                        }

                        if (!isset($bucket[$bid])) {
                            $bucket[$bid] = [];
                        }

                        $bucket[$bid][$pl !== '' ? $pl : '_'] = $pid;
                    }

                    foreach ($bucket as $bid => $langsMap) {
                        $pickId = 0;

                        if (isset($langsMap[$currentLang])) {
                            $pickId = (int) $langsMap[$currentLang];
                        } else {
                            if (\function_exists('pll_get_post')) {
                                $any = (int) \reset($langsMap);
                                $translated = (int) \pll_get_post($any, $currentLang);
                                if ($translated > 0) {
                                    $pickId = $translated;
                                }
                            }

                            if ($pickId <= 0) {
                                $pickId = isset($langsMap['_']) ? (int) $langsMap['_'] : (int) \reset($langsMap);
                            }
                        }

                        if ($pickId > 0) {
                            $url = \get_permalink($pickId);
                            if (\is_string($url) && $url !== '' && empty($permalinkByBoatId[$bid])) {
                                $permalinkByBoatId[$bid] = $url;
                            }

                            if (empty($linkedWpBoatMap[$bid]['thumbnail_url']) && \has_post_thumbnail($pickId)) {
                                $thumbId = (int) \get_post_thumbnail_id($pickId);
                                if ($thumbId > 0) {
                                    $src = \wp_get_attachment_image_src($thumbId, 'full');
                                    if (\is_array($src) && !empty($src[0]) && \is_string($src[0])) {
                                        $linkedWpBoatMap[$bid] = [
                                            'post_id'       => $pickId,
                                            'url'           => \is_string($url) ? $url : '',
                                            'thumbnail_url' => (string) $src[0],
                                        ];
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        return [
            'atts'                    => $atts,
            'ui_query'                => $uiQuery,
            'date_mode'               => $dateMode,
            'show_filters'            => $showFilters,
            'autosubmit_filters'      => $autoSubmitFilters,
            'allow_url_filters'       => $allowUrlFilters,
            'ui_layout'               => $uiLayout,
            'ui_submit_mode'          => $uiSubmitMode,
            'ui_show_reset'           => $uiShowReset,
            'show_more_btn'           => $showMoreBtn,
            'more_btn_text'           => $moreBtnText,
            'offcanvas_title'         => $offcanvasTitle,
            'ui_left_fields'          => $uiLeftFields,
            'ui_fields'               => $uiFields,
            'ui_right_fields'         => $uiRightFields,
            'ui_off_fields'           => $uiOffFields,
            'bounds_min'              => $boundsMin,
            'bounds_max'              => $boundsMax,
            'filters'                 => $filters,
            'builders_options'        => $buildersOptions,
            'available_builder_options' => $availableBuilderOptions,
            'page'                    => $page,
            'per_page'                => $perPage,
            'base_offset'             => $baseOffset,
            'boats'                   => $boats,
            'total_results'           => $totalResults,
            'total_pages'             => $totalPages,
            'card_id'                 => $cardId,
            'template'                => $template,
            'image_token'             => $imageToken,
            'base_slug'               => $baseSlug,
            'context'                 => \array_merge($context, [
                'disable_api_image_fallback' => true,
            ]),
            'current_lang'            => $currentLang,
            'linked_wp_boat_map'      => $linkedWpBoatMap,
            'permalink_by_boat_id'    => $permalinkByBoatId,
            'boat_pages_sync_enabled' => $boatPagesSyncEnabled,
        ];
    }

    /**
     * Render only the archive results HTML.
     *
     * @param array<string,mixed> $archiveContext
     *
     * @return string
     */
    public static function renderBoatsArchiveResults(array $archiveContext): string
    {
        $boats                = (array) ($archiveContext['boats'] ?? []);
        $template             = (string) ($archiveContext['template'] ?? '');
        $context              = (array) ($archiveContext['context'] ?? []);
        $baseSlug             = (string) ($archiveContext['base_slug'] ?? 'boats');
        $permalinkByBoatId    = (array) ($archiveContext['permalink_by_boat_id'] ?? []);
        $linkedWpBoatMap      = (array) ($archiveContext['linked_wp_boat_map'] ?? []);
        $boatPagesSyncEnabled = !empty($archiveContext['boat_pages_sync_enabled']);
        $currentLang          = (string) ($archiveContext['current_lang'] ?? '');

        $engine = new \Maradigma\BoatCardEngine();

        \ob_start();

        if (!empty($boats)) : ?>
            <div class="maradigma-boats maradigma-boats-archive" data-maradigma="boats" data-md-boats-archive-results="1">
                <?php foreach ($boats as $boat) : ?>
                    <?php
                    if (!\is_array($boat)) {
                        continue;
                    }

                    $boatId = \trim((string) ($boat['id'] ?? $boat['id_gi'] ?? $boat['id_group_item'] ?? ''));

                    if (
                        $boatId !== ''
                        && isset($permalinkByBoatId[$boatId])
                        && \is_string($permalinkByBoatId[$boatId])
                        && $permalinkByBoatId[$boatId] !== ''
                    ) {
                        $boat['url'] = $permalinkByBoatId[$boatId];
                    } else {
                        $slug = \trim((string) ($boat['slug'] ?? $boat['service_slug'] ?? ''));
                        if ($slug !== '') {
                            $boat['url'] = \home_url('/' . $baseSlug . '/' . \sanitize_title($slug) . '/');
                        } else {
                            $boat['url'] = '#';
                        }
                    }

                    if (
                        $boatId !== ''
                        && isset($linkedWpBoatMap[$boatId]['thumbnail_url'])
                        && \is_string($linkedWpBoatMap[$boatId]['thumbnail_url'])
                        && \trim((string) $linkedWpBoatMap[$boatId]['thumbnail_url']) !== ''
                    ) {
                        $linkedThumbnailUrl = \trim((string) $linkedWpBoatMap[$boatId]['thumbnail_url']);

                        $boat['__linked_wp_post_thumbnail_url'] = $linkedThumbnailUrl;
                        $boat['image_main'] = $linkedThumbnailUrl;
                        $boat['image_token'] = $linkedThumbnailUrl;
                        $boat['thumbnail_url'] = $linkedThumbnailUrl;
                    }

                    if (
                        $boatId !== ''
                        && empty($boat['__linked_wp_post_thumbnail_url'])
                        && \class_exists(\Maradigma\Support\Debugger::class)
                    ) {
                        \Maradigma\Support\Debugger::log('boat-images-sync', 'archive_card_missing_linked_thumbnail', [
                            'boat_id'              => $boatId,
                            'service_name'         => (string) ($boat['service_name'] ?? ''),
                            'slug'                 => (string) ($boat['slug'] ?? $boat['service_slug'] ?? ''),
                            'current_lang'         => $currentLang,
                            'has_linked_map_row'   => isset($linkedWpBoatMap[$boatId]),
                            'linked_map_row'       => isset($linkedWpBoatMap[$boatId]) ? $linkedWpBoatMap[$boatId] : null,
                            'has_permalink_row'    => isset($permalinkByBoatId[$boatId]),
                            'resolved_card_url'    => (string) ($boat['url'] ?? ''),
                            'prefer_wp_images'     => !empty($context['prefer_wp_images']),
                            'has_payload_image'    => !empty($boat['image_main']) || !empty($boat['image_url']) || !empty($boat['main_image_url']) || !empty($boat['cover_url']) || !empty($boat['thumbnail_url']),
                        ]);
                    }

                    $html = $engine->render($template, $boat, $context);

                    $finalFallbackImage = self::resolveBoatCardFallbackImage($boat, $context);

                    if ($finalFallbackImage !== '') {
                        $html = (string) \preg_replace_callback(
                            '/<img\b[^>]*>/i',
                            static function (array $match) use ($finalFallbackImage): string {
                                $img = (string) ($match[0] ?? '');

                                if (!\preg_match('/\bclass=(["\'])(?:(?!\1).)*\bmaradigma-boat-card__img\b(?:(?!\1).)*\1/i', $img)) {
                                    return $img;
                                }

                                if (\preg_match('/\bsrc=(["\'])(.*?)\1/i', $img, $srcMatch)) {
                                    $currentSrc = \trim((string) ($srcMatch[2] ?? ''));

                                    if ($currentSrc !== '') {
                                        return $img;
                                    }

                                    return (string) \preg_replace(
                                        '/\bsrc=(["\'])(.*?)\1/i',
                                        'src=$1' . \esc_url($finalFallbackImage) . '$1',
                                        $img,
                                        1
                                    );
                                }

                                return (string) \preg_replace(
                                    '/<img\b/i',
                                    '<img src="' . \esc_url($finalFallbackImage) . '"',
                                    $img,
                                    1
                                );
                            },
                            $html
                        );
                    }

                    $allowed   = \Maradigma\BoatCardEngine::getAllowedHtml();
                    $cleanHtml = \wp_kses($html, $allowed);
                    $cleanHtml = self::repairBoatCardImageWrapStyles($cleanHtml, $finalFallbackImage);

                    if ($boatId !== '' && \class_exists(\Maradigma\Support\Debugger::class)) {
                        $extractCardImgSrc = static function (string $markup): string {
                            if (!\preg_match_all('/<img\b[^>]*>/i', $markup, $matches)) {
                                return '';
                            }

                            foreach ((array) ($matches[0] ?? []) as $imgTag) {
                                $imgTag = (string) $imgTag;

                                if (!\preg_match('/\bclass=(["\'])(?:(?!\1).)*\bmaradigma-boat-card__img\b(?:(?!\1).)*\1/i', $imgTag)) {
                                    continue;
                                }

                                if (\preg_match('/\bsrc=(["\'])(.*?)\1/i', $imgTag, $srcMatch)) {
                                    return \trim((string) ($srcMatch[2] ?? ''));
                                }

                                return '';
                            }

                            return '';
                        };

                        $rawImgSrc   = $extractCardImgSrc($html);
                        $cleanImgSrc = $extractCardImgSrc($cleanHtml);

                        if ($cleanImgSrc === '') {
                            \Maradigma\Support\Debugger::log('boat-images-sync', 'archive_card_empty_final_image_src', [
                                'card_id'                              => (string) ($card['id'] ?? ''),
                                'card_hash'                            => (string) ($card['hash'] ?? ''),
                                'boat_id'                              => $boatId,
                                'service_name'                         => (string) ($boat['service_name'] ?? ''),
                                'slug'                                 => (string) ($boat['slug'] ?? $boat['service_slug'] ?? ''),
                                'current_lang'                         => $currentLang,
                                'resolved_card_url'                    => (string) ($boat['url'] ?? ''),
                                'has_linked_map_row'                   => isset($linkedWpBoatMap[$boatId]),
                                'linked_map_row'                       => isset($linkedWpBoatMap[$boatId]) ? $linkedWpBoatMap[$boatId] : null,
                                'has_permalink_row'                    => isset($permalinkByBoatId[$boatId]),
                                'linked_thumbnail_in_payload'          => (string) ($boat['__linked_wp_post_thumbnail_url'] ?? ''),
                                'payload_image_main'                   => (string) ($boat['image_main'] ?? ''),
                                'payload_image_token'                  => (string) ($boat['image_token'] ?? ''),
                                'payload_thumbnail_url'                => (string) ($boat['thumbnail_url'] ?? ''),
                                'raw_img_src'                          => $rawImgSrc,
                                'clean_img_src'                        => $cleanImgSrc,
                                'raw_has_image_main_placeholder'       => \strpos($html, '{{image_main}}') !== false,
                                'raw_has_image_token_placeholder'      => \strpos($html, '{{image_token}}') !== false,
                                'template_has_image_main_placeholder'  => \strpos($template, '{{image_main}}') !== false,
                                'template_has_image_token_placeholder' => \strpos($template, '{{image_token}}') !== false,
                            ]);
                        }
                    }

                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Card markup was sanitized with BoatCardEngine::getAllowedHtml() immediately above.
                    echo $cleanHtml;
                    ?>
                <?php endforeach; ?>
            </div>
        <?php else : ?>
            <div data-md-boats-archive-results="1">
                <p><?php \esc_html_e('No boats found.', 'maradigma'); ?></p>
            </div>
        <?php endif;

        return (string) \ob_get_clean();
    }

    /**
     * Render only the archive pagination HTML.
     *
     * @param array<string,mixed> $archiveContext
     * @return string
     */
    public static function renderBoatsArchivePagination(array $archiveContext): string
    {
        $page            = (int) ($archiveContext['page'] ?? 1);
        $totalPages      = (int) ($archiveContext['total_pages'] ?? 1);
        $uiQuery         = (array) ($archiveContext['ui_query'] ?? []);
        $archiveBaseUrl  = \trim((string) ($archiveContext['archive_base_url'] ?? ''));

        \ob_start();

        echo '<div data-md-boats-archive-pagination="1">';

        if ($totalPages > 1 && \function_exists('paginate_links')) {
            if ($archiveBaseUrl === '') {
                $archiveBaseUrl = \remove_query_arg('md_page');
            }

            $queryArgs = [];

            foreach ($uiQuery as $key => $value) {
                $key = (string) $key;

                if ($key === 'md_page') {
                    continue;
                }

                if (\is_array($value)) {
                    continue;
                }

                $value = \trim((string) $value);

                if ($value === '') {
                    continue;
                }

                $queryArgs[$key] = $value;
            }

            $base = \add_query_arg(
                \array_merge($queryArgs, ['md_page' => '%#%']),
                $archiveBaseUrl
            );

            $links = \paginate_links([
                'base'      => $base,
                'format'    => '',
                'current'   => $page,
                'total'     => $totalPages,
                'type'      => 'list',
                'prev_text' => '«',
                'next_text' => '»',
            ]);

            if (\is_string($links) && $links !== '') {
                echo '<nav class="maradigma-pagination" aria-label="Boats pagination">' . \wp_kses_post($links) . '</nav>';
            }
        }

        echo '</div>';

        return (string) \ob_get_clean();
    }

    /**
     * Render boats listing shortcode.
     *
     * This renderer outputs the archive root container aligned with the frontend
     * AJAX archive controller:
     * - data-md-boats-archive-root="1"
     * - data-md-boats-archive-url="..."
     *
     * The same renderer works for classic SSR and progressive AJAX enhancement.
     *
     * @param array<string,mixed> $atts
     *
     * @return string
     */
    public static function renderBoatsListing(array $atts = []): string
    {
        if (!\function_exists('shortcode_atts')) {
            return '';
        }

        $archiveContext = self::buildBoatsArchiveContext($atts);

        if (empty($archiveContext)) {
            return '';
        }

        $attsUsed          = (array) ($archiveContext['atts'] ?? []);
        $filters           = (array) ($archiveContext['filters'] ?? []);
        $uiLeftFields      = (array) ($archiveContext['ui_left_fields'] ?? []);
        $uiFields          = (array) ($archiveContext['ui_fields'] ?? []);
        $uiRightFields     = (array) ($archiveContext['ui_right_fields'] ?? []);
        $uiOffFields       = (array) ($archiveContext['ui_off_fields'] ?? []);
        $showFilters       = !empty($archiveContext['show_filters']);
        $autoSubmitFilters = !empty($archiveContext['autosubmit_filters']);
        $uiLayout          = (string) ($archiveContext['ui_layout'] ?? 'horizontal');
        $uiShowReset       = !empty($archiveContext['ui_show_reset']);
        $showMoreBtn       = !empty($archiveContext['show_more_btn']);
        $moreBtnText       = (string) ($archiveContext['more_btn_text'] ?? '');
        $offcanvasTitle    = (string) ($archiveContext['offcanvas_title'] ?? '');
        $dateMode          = (string) ($archiveContext['date_mode'] ?? 'range');
        $currentLang       = (string) ($archiveContext['current_lang'] ?? '');
        $boundsMin         = (string) ($archiveContext['bounds_min'] ?? '');
        $boundsMax         = (string) ($archiveContext['bounds_max'] ?? '');
        $uiQuery           = (array) ($archiveContext['ui_query'] ?? null);

        $submitMode = $autoSubmitFilters
            ? 'auto'
            : (string) ($archiveContext['ui_submit_mode'] ?? 'auto');

        $archiveRootId   = 'md-boats-archive-' . \wp_rand(1000, 999999);
        $archiveEndpoint = (string) \rest_url('maradigma/v1/boats-archive');

        \ob_start();
        ?>
        <div
            id="<?php echo \esc_attr($archiveRootId); ?>"
            class="maradigma-boats-shortcode-list"
            data-md-boats-archive-root="1"
            data-md-boats-archive-url="<?php echo \esc_attr($archiveEndpoint); ?>"
            data-md-archive-id-group="<?php echo \esc_attr((string) ($attsUsed['id_group'] ?? 'boats')); ?>"
            data-md-archive-limit="<?php echo \esc_attr((string) ($attsUsed['limit_services'] ?? '10')); ?>"
            data-md-archive-offset="<?php echo \esc_attr((string) ($attsUsed['offset_services'] ?? '0')); ?>"
            data-md-archive-order-by="<?php echo \esc_attr((string) ($filters['order_by'] ?? $attsUsed['order_by'] ?? '0')); ?>"
            data-md-archive-card="<?php echo \esc_attr((string) ($attsUsed['card'] ?? '')); ?>"
            data-md-archive-image-token="<?php echo \esc_attr((string) ($attsUsed['image_token'] ?? '')); ?>"
            data-md-archive-date-mode="<?php echo \esc_attr($dateMode); ?>"
            data-md-archive-current-lang="<?php echo \esc_attr($currentLang); ?>"
            data-md-archive-builders-options="<?php echo \esc_attr((string) ($archiveContext['builders_options'] ?? 'api')); ?>"
            data-md-archive-filters-ui-fields="<?php echo \esc_attr(\implode(',', $uiFields)); ?>"
            data-md-archive-filters-ui-fields-left="<?php echo \esc_attr(\implode(',', $uiLeftFields)); ?>"
            data-md-archive-filters-ui-fields-right="<?php echo \esc_attr(\implode(',', $uiRightFields)); ?>"
            data-md-archive-filters-ui-fields-offcanvas="<?php echo \esc_attr(\implode(',', $uiOffFields)); ?>"
            data-md-archive-filters-ui-layout="<?php echo \esc_attr($uiLayout); ?>"
            data-md-archive-filters-ui-submit-mode="<?php echo \esc_attr($submitMode); ?>"
            data-md-archive-filters-ui-show-reset="<?php echo \esc_attr($uiShowReset ? '1' : '0'); ?>"
            data-md-archive-show-more-filters-button="<?php echo \esc_attr($showMoreBtn ? '1' : '0'); ?>"
            data-md-archive-more-filters-button-text="<?php echo \esc_attr($moreBtnText); ?>"
            data-md-archive-more-filters-offcanvas-title="<?php echo \esc_attr($offcanvasTitle); ?>"
            data-md-ajax-enabled="1">

            <?php if ($showFilters) : ?>
                <?php
                $uiDefaults = [
                    'date_start' => (string) ($filters['date_start'] ?? ''),
                    'date_end'   => (string) ($filters['date_end'] ?? ''),
                    'min_price'  => (string) ($filters['min_price'] ?? ''),
                    'max_price'  => (string) ($filters['max_price'] ?? ''),
                    'guests'     => (string) ($filters['guests'] ?? ''),
                    'location'   => (string) ($filters['location'] ?? ''),
                    'q'          => (string) ($filters['q'] ?? ''),
                    'order_by'   => (string) ($filters['order_by'] ?? $attsUsed['order_by'] ?? '0'),
                    'builders_labels_json' => (string) ($attsUsed['builders_labels_json'] ?? ''),
                    'builders_options' => (string) ($archiveContext['builders_options'] ?? 'api'),
                    'available_builder_options' => (array) ($archiveContext['available_builder_options'] ?? []),
                ];

                $uiDefaults['_price_bounds_min'] = $boundsMin;
                $uiDefaults['_price_bounds_max'] = $boundsMax;

                $filtersHtml = self::renderBoatsFiltersUi(
                    $uiFields,
                    $uiLayout,
                    $submitMode,
                    $uiShowReset,
                    $showMoreBtn,
                    $moreBtnText,
                    $offcanvasTitle,
                    $uiDefaults,
                    $dateMode,
                    $uiOffFields,
                    $uiRightFields,
                    $uiQuery,
                    $archiveEndpoint,
                    $archiveRootId
                );
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Filter renderer escapes every dynamic value and returns complete plugin markup.
                echo $filtersHtml;
                ?>
            <?php endif; ?>

            <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Archive renderer sanitizes card markup with its explicit allowlist. ?>
            <?php echo self::renderBoatsArchiveResults($archiveContext); ?>
            <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pagination renderer escapes URLs and filters paginate_links() markup. ?>
            <?php echo self::renderBoatsArchivePagination($archiveContext); ?>

        </div>
    <?php

        return (string) \ob_get_clean();
    }

    /**
     * Render one boat using the same editable card templates used by [maradigma_boats].
     *
     * Supported examples:
     * - [maradigma_boat_card id="2773"]
     * - [maradigma_boat_card id="2773" card="default"]
     * - [maradigma_boat_card slug="karnic-sl602-valkirie" image_token="image_maradigma_card_471x273"]
     *
     * @param array<string,mixed> $atts
     */
    public static function renderBoatCard(array $atts = []): string
    {
        if (!\function_exists('shortcode_atts')) {
            return '';
        }

        AssetsManager::enqueueBoatCardsAssets();

        $atts = \shortcode_atts(
            [
                'id'          => '',
                'slug'        => '',
                'card'        => '',
                'image_token' => '',
                'class'       => '',
            ],
            $atts,
            'maradigma_boat_card'
        );

        $boat = self::loadBoat($atts);
        if (!\is_array($boat) || $boat === []) {
            return '';
        }

        $cardId = \trim((string) ($atts['card'] ?? ''));
        if ($cardId === '') {
            $cardId = \Maradigma\BoatCardRepository::getDefaultCardId();
        }

        $card = \Maradigma\BoatCardRepository::getCard($cardId);
        if (!$card || empty($card['template'])) {
            $card = \Maradigma\BoatCardRepository::getCard(\Maradigma\BoatCardRepository::DEFAULT_CARD_ID);
        }

        $template = (string) ($card['template'] ?? '');
        if (\trim($template) === '') {
            return '';
        }

        $imageToken = self::normalizeBoatCardImageToken((string) ($atts['image_token'] ?? ''));
        $template = self::normalizeBoatCardTemplateImageToken($template, $imageToken);

        $settings = SettingsPage::getSettings();

        $currentLang = \class_exists(\Maradigma\Support\RuntimeContext::class)
            ? \Maradigma\Support\RuntimeContext::detectCurrentLanguage()
            : self::getLanguage();

        $currentLang = \strtolower(\trim((string) $currentLang));
        $currentLang = (string) (\preg_split('/[_-]/', $currentLang)[0] ?? $currentLang);
        if ($currentLang === '') {
            $currentLang = 'en';
        }

        $defaultLang = \strtolower(\trim((string) ($settings['default_language'] ?? 'en')));
        $defaultLang = (string) (\preg_split('/[_-]/', $defaultLang)[0] ?? $defaultLang);
        if ($defaultLang === '') {
            $defaultLang = 'en';
        }

        $baseSlug = SettingsPage::pickLocalizedValue(
            (string) ($settings['boats_base_slug'] ?? 'boats'),
            $currentLang,
            $defaultLang
        );

        $baseSlug = \trim((string) $baseSlug, '/');
        if ($baseSlug === '') {
            $baseSlug = 'boats';
        }

        $boatId = self::resolveBoatIdFromBoatData($boat, [
            'id'   => (string) ($atts['id'] ?? ''),
            'slug' => (string) ($atts['slug'] ?? ''),
        ]);

        $boatPagesSyncEnabled = !empty($settings['enable_boat_pages_sync']);

        if ($boatId !== '') {
            $linkedWpBoatMap = self::getLinkedWpBoatPostsMap([$boatId], $currentLang);
            if (!empty($linkedWpBoatMap[$boatId]['url']) && \is_string($linkedWpBoatMap[$boatId]['url'])) {
                $boat['url'] = \trim((string) $linkedWpBoatMap[$boatId]['url']);
            }

            if (
                !empty($linkedWpBoatMap[$boatId]['thumbnail_url'])
                && \is_string($linkedWpBoatMap[$boatId]['thumbnail_url'])
            ) {
                $boat['__linked_wp_post_thumbnail_url'] = \trim((string) $linkedWpBoatMap[$boatId]['thumbnail_url']);
            }
        }

        $context = [
            'boats_base_slug'  => $baseSlug,
            'prefer_wp_images' => !empty($settings['store_boat_images_locally']),
            'date_start'       => (string) ($filters['date_start'] ?? ''),
            'date_end'         => (string) ($filters['date_end'] ?? ''),
        ];

        if (
            \class_exists(\Maradigma\Support\RuntimeContext::class)
            && \method_exists(\Maradigma\Support\RuntimeContext::class, 'getBoatsBaseUrl')
        ) {
            $boatsBaseUrl = (string) \Maradigma\Support\RuntimeContext::getBoatsBaseUrl($currentLang);
            if ($boatsBaseUrl !== '') {
                $context['boats_base_url'] = $boatsBaseUrl;
            }
        }

        $engine = new \Maradigma\BoatCardEngine();
        $html = $engine->render($template, $boat, $context);
        $html = \wp_kses($html, \Maradigma\BoatCardEngine::getAllowedHtml());
        $html = self::repairBoatCardImageWrapStyles($html, self::resolveBoatCardFallbackImage($boat, $context));

        if ($html === '') {
            return '';
        }

        $extraClass = \trim((string) ($atts['class'] ?? ''));
        $classes = ['maradigma-boats', 'maradigma-boat-card-shortcode'];

        if ($extraClass !== '') {
            foreach (\preg_split('/\s+/', $extraClass) ?: [] as $className) {
                $className = \sanitize_html_class((string) $className);
                if ($className !== '') {
                    $classes[] = $className;
                }
            }
        }

        return '<div class="' . \esc_attr(\implode(' ', \array_unique($classes))) . '">' . $html . '</div>';
    }

    /**
     * Render related boats using the same editable card system as the archive.
     *
     * Examples:
     * - [maradigma_related_boats]
     * - [maradigma_related_boats count="4" priorities="price,pax"]
     * - [maradigma_related_boats id="2773" limit="5" priority="pax,price,base_port"]
     *
     * @param array<string,mixed> $atts
     */
    public static function renderRelatedBoats(array $atts = []): string
    {
        if (!\function_exists('shortcode_atts')) {
            return '';
        }

        $atts = \shortcode_atts(
            [
                'id'            => '',
                'slug'          => '',
                'count'         => '3',
                'limit'         => '',
                'priorities'    => 'price,pax',
                'priority'      => '',
                'card'          => '',
                'image_token'   => '',
                'title'         => '',
                'show_title'    => '0',
                'empty_message' => '',
                'class'         => '',
            ],
            $atts,
            'maradigma_related_boats'
        );

        $limitRaw = \trim((string) ($atts['limit'] ?? ''));
        $count = $limitRaw !== '' ? (int) $limitRaw : (int) ($atts['count'] ?? 3);
        $count = \max(2, \min(5, $count > 0 ? $count : 3));

        $currentBoat = self::loadBoat([
            'id'   => (string) ($atts['id'] ?? ''),
            'slug' => (string) ($atts['slug'] ?? ''),
        ]);

        if (!\is_array($currentBoat) || $currentBoat === []) {
            return '';
        }

        $currentBoatId = self::resolveBoatIdFromBoatData($currentBoat, [
            'id'   => (string) ($atts['id'] ?? ''),
            'slug' => (string) ($atts['slug'] ?? ''),
        ]);

        if ($currentBoatId === '') {
            return '';
        }

        $priorities = self::normalizeRelatedBoatsPriorities(
            (string) (($atts['priority'] ?? '') !== '' ? $atts['priority'] : ($atts['priorities'] ?? 'price,pax'))
        );

        $settings = SettingsPage::getSettings();
        $currentLang = \class_exists(\Maradigma\Support\RuntimeContext::class)
            ? \Maradigma\Support\RuntimeContext::detectCurrentLanguage()
            : self::getLanguage();

        $currentLang = \strtolower(\trim((string) $currentLang));
        $currentLang = (string) (\preg_split('/[_-]/', $currentLang)[0] ?? $currentLang);
        if ($currentLang === '') {
            $currentLang = 'en';
        }

        $defaultLang = \strtolower(\trim((string) ($settings['default_language'] ?? 'en')));
        $defaultLang = (string) (\preg_split('/[_-]/', $defaultLang)[0] ?? $defaultLang);
        if ($defaultLang === '') {
            $defaultLang = 'en';
        }

        $baseSlug = SettingsPage::pickLocalizedValue(
            (string) ($settings['boats_base_slug'] ?? 'boats'),
            $currentLang,
            $defaultLang
        );

        $baseSlug = \trim((string) $baseSlug, '/');
        if ($baseSlug === '') {
            $baseSlug = 'boats';
        }

        $candidates = self::loadRelatedBoatCandidates($currentBoatId, $currentLang, $count);
        if ($candidates === []) {
            return self::renderRelatedBoatsEmpty((string) ($atts['empty_message'] ?? ''));
        }

        $relatedBoats = self::pickRelatedBoats($currentBoat, $candidates, $currentBoatId, $priorities, $count);
        if ($relatedBoats === []) {
            return self::renderRelatedBoatsEmpty((string) ($atts['empty_message'] ?? ''));
        }

        $cardId = \trim((string) ($atts['card'] ?? ''));
        if ($cardId === '') {
            $cardId = \Maradigma\BoatCardRepository::getDefaultCardId();
        }

        $card = \Maradigma\BoatCardRepository::getCard($cardId);
        if (!$card || empty($card['template'])) {
            $card = \Maradigma\BoatCardRepository::getCard(\Maradigma\BoatCardRepository::DEFAULT_CARD_ID);
        }

        $template = (string) ($card['template'] ?? '');
        if (\trim($template) === '') {
            return '';
        }

        $imageToken = self::normalizeBoatCardImageToken((string) ($atts['image_token'] ?? ''));
        $template = self::normalizeBoatCardTemplateImageToken($template, $imageToken);

        $context = [
            'boats_base_slug'             => $baseSlug,
            'prefer_wp_images'            => !empty($settings['store_boat_images_locally']),
            'current_lang'                => $currentLang,
            'disable_api_image_fallback'  => true,
        ];

        if (
            \class_exists(\Maradigma\Support\RuntimeContext::class)
            && \method_exists(\Maradigma\Support\RuntimeContext::class, 'getBoatsBaseUrl')
        ) {
            $boatsBaseUrl = (string) \Maradigma\Support\RuntimeContext::getBoatsBaseUrl($currentLang);
            if ($boatsBaseUrl !== '') {
                $context['boats_base_url'] = $boatsBaseUrl;
            }
        }

        AssetsManager::enqueueBoatCardsAssets();

        $engine = new \Maradigma\BoatCardEngine();
        $classes = ['maradigma-related-boats'];

        $extraClass = \trim((string) ($atts['class'] ?? ''));
        if ($extraClass !== '') {
            foreach (\preg_split('/\s+/', $extraClass) ?: [] as $className) {
                $className = \sanitize_html_class((string) $className);
                if ($className !== '') {
                    $classes[] = $className;
                }
            }
        }

        $title = \trim((string) ($atts['title'] ?? ''));
        if ($title !== '') {
            $title = \Maradigma\Support\MultilangAdapter::translateEditableString(
                $title,
                'Maradigma Shortcodes',
                'related_boats_title',
                'maradigma'
            );
        }

        $showTitle = \in_array(\strtolower(\trim((string) ($atts['show_title'] ?? '0'))), ['1', 'true', 'yes', 'on'], true);

        \ob_start();
        ?>
        <section class="<?php echo \esc_attr(\implode(' ', \array_unique($classes))); ?>" data-maradigma-related-boats="1">
            <?php if ($showTitle && $title !== '') : ?>
                <h2 class="maradigma-related-boats__title"><?php echo \esc_html($title); ?></h2>
            <?php endif; ?>

            <div class="maradigma-boats maradigma-boats-related" data-maradigma="boats">
                <?php foreach ($relatedBoats as $boat) : ?>
                    <?php
                    if (!\is_array($boat)) {
                        continue;
                    }

                    $html = $engine->render($template, $boat, $context);
                    $html = \wp_kses($html, \Maradigma\BoatCardEngine::getAllowedHtml());
                    $html = self::repairBoatCardImageWrapStyles($html, self::resolveBoatCardFallbackImage($boat, $context));
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Card markup was sanitized with BoatCardEngine::getAllowedHtml() immediately above.
                    echo $html;
                    ?>
                <?php endforeach; ?>
            </div>
        </section>
        <?php

        return (string) \ob_get_clean();
    }

    /**
     * Renders related boats empty.
     */
    private static function renderRelatedBoatsEmpty(string $message): string
    {
        $message = \trim($message);
        if ($message === '') {
            return '';
        }

        return '<div class="maradigma-related-boats maradigma-related-boats--empty"><p>' . \esc_html($message) . '</p></div>';
    }

    /**
     * @return list<string>
     */
    private static function normalizeRelatedBoatsPriorities(string $value): array
    {
        $aliases = [
            'precio'      => 'price',
            'price'       => 'price',
            'preu'        => 'price',
            'passengers'  => 'pax',
            'pasajeros'   => 'pax',
            'personas'    => 'pax',
            'pax'         => 'pax',
            'capacity'    => 'pax',
            'capacidad'   => 'pax',
            'puerto'      => 'base_port',
            'port'        => 'base_port',
            'base_port'   => 'base_port',
            'type'        => 'type',
            'tipo'        => 'type',
            'builder'     => 'builder',
            'astillero'   => 'builder',
            'length'      => 'length',
            'eslora'      => 'length',
            'cabins'      => 'cabins',
            'cabinas'     => 'cabins',
            'year'        => 'year',
            'ano'         => 'year',
            'anio'        => 'year',
        ];

        $out = [];

        foreach (\preg_split('/[\s,;|]+/', \strtolower(\trim($value))) ?: [] as $part) {
            $part = \trim((string) $part);
            if ($part === '' || !isset($aliases[$part])) {
                continue;
            }

            $normalized = $aliases[$part];
            if (!\in_array($normalized, $out, true)) {
                $out[] = $normalized;
            }
        }

        foreach (['price', 'pax'] as $fallback) {
            if (!\in_array($fallback, $out, true)) {
                $out[] = $fallback;
            }
        }

        return $out;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private static function loadRelatedBoatCandidates(string $currentBoatId, string $currentLang, int $count): array
    {
        $candidates = self::loadRelatedBoatCandidatesFromWp($currentBoatId, $currentLang);

        if ($candidates !== []) {
            return $candidates;
        }

        $limit = \max(20, \min(60, $count * 12));

        $cache = new Cache();
        $result = $cache->getBoatsList([
            'id_group'             => 'boats',
            'limit_services'       => $limit,
            'offset_services'      => 0,
            'only_calendarization' => false,
        ]);

        $boats = [];
        if (\is_array($result['data']['search_result'] ?? null)) {
            $boats = (array) $result['data']['search_result'];
        }



        $boatIds = [];
        foreach ($boats as $boat) {
            if (!\is_array($boat)) {
                continue;
            }

            $id = self::resolveBoatIdFromBoatData($boat);
            if ($id !== '' && $id !== $currentBoatId) {
                $boatIds[] = $id;
            }
        }

        $linkedWpBoatMap = self::getLinkedWpBoatPostsMap(\array_values(\array_unique($boatIds)), $currentLang);
        $out = [];

        foreach ($boats as $boat) {
            if (!\is_array($boat)) {
                continue;
            }

            $id = self::resolveBoatIdFromBoatData($boat);
            if ($id === '' || $id === $currentBoatId) {
                continue;
            }

            if (!empty($linkedWpBoatMap[$id]['url']) && \is_string($linkedWpBoatMap[$id]['url'])) {
                $boat['url'] = \trim((string) $linkedWpBoatMap[$id]['url']);
            }

            if (!empty($linkedWpBoatMap[$id]['thumbnail_url']) && \is_string($linkedWpBoatMap[$id]['thumbnail_url'])) {
                $thumb = \trim((string) $linkedWpBoatMap[$id]['thumbnail_url']);
                $boat['__linked_wp_post_thumbnail_url'] = $thumb;
                $boat['image_main'] = $thumb;
                $boat['image_token'] = $thumb;
                $boat['thumbnail_url'] = $thumb;
            }

            $out[] = $boat;
        }

        return $out;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private static function loadRelatedBoatCandidatesFromWp(string $currentBoatId, string $currentLang): array
    {
        if (!\post_type_exists(\Maradigma\BoatPostType::POST_TYPE)) {
            return [];
        }

        $currentLang = \strtolower(\trim($currentLang));
        $currentLang = (string) (\preg_split('/[_-]/', $currentLang)[0] ?? $currentLang);

        $query = new \WP_Query([
            'post_type'              => \Maradigma\BoatPostType::POST_TYPE,
            'post_status'            => 'publish',
            'fields'                 => 'ids',
            'posts_per_page'         => -1,
            'no_found_rows'          => true,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => false,
            'lang'                   => '',
            'meta_query'             => [
                [
                    'key'     => '_maradigma_boat_id',
                    'value'   => $currentBoatId,
                    'compare' => '!=',
                ],
            ],
        ]);

        if (empty($query->posts) || !\is_array($query->posts)) {
            return [];
        }

        $bucket = [];

        foreach ($query->posts as $postId) {
            $postId = (int) $postId;
            if ($postId <= 0) {
                continue;
            }

            $payloadJson = (string) \get_post_meta($postId, '_maradigma_boat_payload', true);
            $payload = $payloadJson !== '' ? \json_decode($payloadJson, true) : null;

            if (!\is_array($payload)) {
                continue;
            }

            $boatId = self::resolveBoatIdFromBoatData($payload, [
                'id' => (string) \get_post_meta($postId, '_maradigma_boat_id', true),
            ]);

            if ($boatId === '' || $boatId === $currentBoatId) {
                continue;
            }

            $lang = '';
            if (\function_exists('pll_get_post_language')) {
                $lang = \strtolower(\trim((string) \pll_get_post_language($postId, 'slug')));
                $lang = (string) (\preg_split('/[_-]/', $lang)[0] ?? $lang);
            } elseif (\has_filter('wpml_element_language_code')) {
                $maybeLang = \apply_filters('wpml_element_language_code', null, [
                    'element_id'   => $postId,
                    'element_type' => 'post_' . \Maradigma\BoatPostType::POST_TYPE,
                ]);

                if (\is_string($maybeLang)) {
                    $lang = \strtolower(\trim($maybeLang));
                    $lang = (string) (\preg_split('/[_-]/', $lang)[0] ?? $lang);
                }
            }

            if (!isset($bucket[$boatId])) {
                $bucket[$boatId] = [];
            }

            $bucket[$boatId][$lang !== '' ? $lang : '_'] = [
                'post_id' => $postId,
                'payload' => $payload,
            ];
        }

        $out = [];

        foreach ($bucket as $boatId => $langsMap) {
            $picked = null;

            if ($currentLang !== '' && isset($langsMap[$currentLang])) {
                $picked = $langsMap[$currentLang];
            } elseif (isset($langsMap['_'])) {
                $picked = $langsMap['_'];
            } else {
                $picked = \reset($langsMap);
            }

            if (!\is_array($picked) || !isset($picked['post_id'], $picked['payload']) || !\is_array($picked['payload'])) {
                continue;
            }

            $postId = (int) $picked['post_id'];
            $payload = $picked['payload'];

            $url = \get_permalink($postId);
            if (\is_string($url) && $url !== '') {
                $payload['url'] = $url;
            }

            if (\has_post_thumbnail($postId)) {
                $thumbId = (int) \get_post_thumbnail_id($postId);
                $src = $thumbId > 0 ? \wp_get_attachment_image_src($thumbId, 'full') : false;
                if (\is_array($src) && !empty($src[0]) && \is_string($src[0])) {
                    $payload['__linked_wp_post_thumbnail_url'] = (string) $src[0];
                    $payload['image_main'] = (string) $src[0];
                    $payload['image_token'] = (string) $src[0];
                    $payload['thumbnail_url'] = (string) $src[0];
                }
            }

            $out[] = $payload;
        }

        return $out;
    }

    /**
     * @param list<array<string,mixed>> $candidates
     * @param list<string>              $priorities
     * @return list<array<string,mixed>>
     */
    private static function pickRelatedBoats(
        array $currentBoat,
        array $candidates,
        string $currentBoatId,
        array $priorities,
        int $count
    ): array {
        $scored = [];
        $index = 0;

        foreach ($candidates as $candidate) {
            if (!\is_array($candidate)) {
                continue;
            }

            $candidateId = self::resolveBoatIdFromBoatData($candidate);
            if ($candidateId === '' || $candidateId === $currentBoatId) {
                continue;
            }

            $scored[] = [
                'boat'  => $candidate,
                'score' => self::scoreRelatedBoat($currentBoat, $candidate, $priorities),
                'index' => $index,
            ];

            $index++;
        }

        \usort($scored, static function (array $a, array $b): int {
            $aScore = (float) ($a['score'] ?? 0);
            $bScore = (float) ($b['score'] ?? 0);

            if ($aScore === $bScore) {
                return ((int) ($a['index'] ?? 0)) <=> ((int) ($b['index'] ?? 0));
            }

            return $aScore <=> $bScore;
        });

        $out = [];
        foreach (\array_slice($scored, 0, $count) as $row) {
            if (\is_array($row['boat'] ?? null)) {
                $out[] = $row['boat'];
            }
        }

        return $out;
    }

    /**
     * Lower score means closer relationship.
     *
     * @param list<string> $priorities
     */
    private static function scoreRelatedBoat(array $currentBoat, array $candidate, array $priorities): float
    {
        $score = 0.0;
        $weight = \max(1, \count($priorities));

        foreach ($priorities as $priority) {
            $score += self::relatedBoatsPriorityDistance($priority, $currentBoat, $candidate) * $weight;
            $weight--;
        }

        return $score;
    }

    /**
     * Calculates the priority distance used to rank a related boat.
     */
    private static function relatedBoatsPriorityDistance(string $priority, array $currentBoat, array $candidate): float
    {
        switch ($priority) {
            case 'price':
                return self::relatedBoatsNumericDistance(
                    self::relatedBoatsPriceValue($currentBoat),
                    self::relatedBoatsPriceValue($candidate),
                    100.0
                );

            case 'pax':
                return self::relatedBoatsNumericDistance(
                    self::relatedBoatsNumericValue($currentBoat, ['boat_capacity', 'pax']),
                    self::relatedBoatsNumericValue($candidate, ['boat_capacity', 'pax']),
                    1.0
                );

            case 'length':
                return self::relatedBoatsNumericDistance(
                    self::relatedBoatsNumericValue($currentBoat, ['boat_length', 'length']),
                    self::relatedBoatsNumericValue($candidate, ['boat_length', 'length']),
                    1.0
                );

            case 'cabins':
                return self::relatedBoatsNumericDistance(
                    self::relatedBoatsNumericValue($currentBoat, ['boat_cabins', 'cabins']),
                    self::relatedBoatsNumericValue($candidate, ['boat_cabins', 'cabins']),
                    1.0
                );

            case 'year':
                return self::relatedBoatsNumericDistance(
                    self::relatedBoatsNumericValue($currentBoat, ['boat_year_construction', 'year']),
                    self::relatedBoatsNumericValue($candidate, ['boat_year_construction', 'year']),
                    1.0
                );

            case 'base_port':
                return self::relatedBoatsExactDistance($currentBoat, $candidate, ['boat_base_port', 'boat_base_port_name', 'port']);

            case 'type':
                return self::relatedBoatsExactDistance($currentBoat, $candidate, ['id_group_content_type', 'boat_type_id']);

            case 'builder':
                return self::relatedBoatsExactDistance($currentBoat, $candidate, ['boat_id_builder', 'boat_builder']);
        }

        return 1000.0;
    }

    /**
     * Extracts the comparable price used to rank a related boat.
     */
    private static function relatedBoatsPriceValue(array $boat): ?float
    {
        return self::relatedBoatsNumericValue($boat, [
            'price_from_total',
            'price_from',
            'base_price_total',
            'base_price',
            'base_week_price_total',
            'base_week_price',
            'base_hour_price_total',
            'base_hour_price',
        ]);
    }

    /**
     * @param list<string> $keys
     */
    private static function relatedBoatsNumericValue(array $boat, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (!isset($boat[$key])) {
                continue;
            }

            $value = $boat[$key];
            if (!\is_scalar($value) && !(\is_object($value) && \method_exists($value, '__toString'))) {
                continue;
            }

            $number = Utils::toFloatOrNull((string) $value);
            if ($number !== null) {
                return (float) $number;
            }
        }

        return null;
    }

    /**
     * Calculates the numeric distance between two related-boat values.
     */
    private static function relatedBoatsNumericDistance(?float $current, ?float $candidate, float $unit): float
    {
        if ($current === null || $candidate === null) {
            return 1000.0;
        }

        $unit = $unit > 0 ? $unit : 1.0;

        return \abs($current - $candidate) / $unit;
    }

    /**
     * @param list<string> $keys
     */
    private static function relatedBoatsExactDistance(array $currentBoat, array $candidate, array $keys): float
    {
        foreach ($keys as $key) {
            $currentValue = $currentBoat[$key] ?? '';
            $otherValue = $candidate[$key] ?? '';

            if (
                (!\is_scalar($currentValue) && !(\is_object($currentValue) && \method_exists($currentValue, '__toString'))) ||
                (!\is_scalar($otherValue) && !(\is_object($otherValue) && \method_exists($otherValue, '__toString')))
            ) {
                continue;
            }

            $current = \strtolower(\trim((string) $currentValue));
            $other = \strtolower(\trim((string) $otherValue));

            if ($current === '' || $other === '') {
                continue;
            }

            return $current === $other ? 0.0 : 1.0;
        }

        return 1000.0;
    }

    /**
     * Normalizes boat card image token.
     */
    private static function normalizeBoatCardImageToken(string $imageToken): string
    {
        $imageToken = \trim($imageToken);

        $allowedImageTokens = ['image_main'];

        if (
            \class_exists(\Maradigma\BoatImagesSyncService::class)
            && \method_exists(\Maradigma\BoatImagesSyncService::class, 'getAllowedImageTokens')
        ) {
            $allowedImageTokens = (array) \Maradigma\BoatImagesSyncService::getAllowedImageTokens();
            if (!\in_array('image_main', $allowedImageTokens, true)) {
                $allowedImageTokens[] = 'image_main';
            }
        }

        if ($imageToken === '' || !\in_array($imageToken, $allowedImageTokens, true)) {
            return 'image_main';
        }

        return $imageToken;
    }

    /**
     * Resolve the safest available image URL for custom card templates that render
     * the boat image as a background instead of a standard <img>.
     *
     * @param array<string,mixed> $boat
     * @param array<string,mixed> $context
     */
    private static function resolveBoatCardFallbackImage(array $boat, array $context = []): string
    {
        foreach (
            [
                '__linked_wp_post_thumbnail_url',
                'image_main',
                'image_token',
                'thumbnail_url',
                'image_url',
                'main_image_url',
                'cover_url',
            ] as $key
        ) {
            $url = \trim((string) ($boat[$key] ?? ''));
            if ($url !== '') {
                return $url;
            }
        }

        foreach (['images', 'service_images', 'gallery'] as $listKey) {
            $images = $boat[$listKey] ?? null;
            if (!\is_array($images) || $images === []) {
                continue;
            }

            $cover = null;
            foreach ($images as $image) {
                if (!\is_array($image)) {
                    continue;
                }

                $order = isset($image['number_order']) && \is_numeric($image['number_order'])
                    ? (int) $image['number_order']
                    : 0;

                if ($order === 1 || !empty($image['is_cover'])) {
                    $cover = $image;
                    break;
                }
            }

            if ($cover === null) {
                $first = \reset($images);
                if (!\is_array($first)) {
                    continue;
                }
                $cover = $first;
            }

            $url = self::extractBoatCardImageUrlFromPayloadRow($cover);
            if ($url !== '') {
                return $url;
            }
        }

        $boatId = self::resolveBoatIdFromBoatData($boat, []);
        if (
            $boatId !== ''
            && \class_exists(\Maradigma\BoatImagesSyncService::class)
            && \method_exists(\Maradigma\BoatImagesSyncService::class, 'getBoatCoverUrlsByBoatId')
            && \method_exists(\Maradigma\BoatImagesSyncService::class, 'pickMainUrlFromCoverUrls')
        ) {
            $cover = (array) \Maradigma\BoatImagesSyncService::getBoatCoverUrlsByBoatId($boatId);
            if ($cover !== []) {
                $url = \trim((string) \Maradigma\BoatImagesSyncService::pickMainUrlFromCoverUrls($cover));
                if ($url !== '') {
                    return $url;
                }
            }
        }

        return '';
    }

    /**
     * WordPress' inline CSS sanitizer can strip custom CSS properties containing
     * url(...), leaving custom background-image card templates with style=")".
     * Rebuild only the known image-wrap style using a sanitized URL.
     */
    private static function repairBoatCardImageWrapStyles(string $html, string $imageUrl): string
    {
        $imageUrl = \esc_url(\trim($imageUrl));
        if ($html === '' || $imageUrl === '') {
            return $html;
        }

        $style = \esc_attr(
            "--mrd-card-image: url('{$imageUrl}'); background-image: url('{$imageUrl}'); background-size: cover; background-position: center;"
        );

        return (string) \preg_replace_callback(
            '/<([a-z][a-z0-9:-]*)\b([^>]*\bclass\s*=\s*(["\'])(?:(?!\3).)*\bmaradigma-boat-card__image-wrap\b(?:(?!\3).)*\3[^>]*)>/i',
            static function (array $match) use ($style): string {
                $tag = (string) ($match[1] ?? 'div');
                $attrs = (string) ($match[2] ?? '');
                $attrs = (string) \preg_replace('/\s+style\s*=\s*(["\'])(?:(?!\1).)*\1/i', '', $attrs);

                return '<' . $tag . $attrs . ' style="' . $style . '">';
            },
            $html
        );
    }

    /** @param array<string,mixed> $image */
    private static function extractBoatCardImageUrlFromPayloadRow(array $image): string
    {
        $urlSizes = $image['url_sizes'] ?? $image['sizes'] ?? null;
        if (\is_array($urlSizes)) {
            foreach (['full', 'original', 'xxl', 'xl', 'large', 'medium'] as $key) {
                $url = \trim((string) ($urlSizes[$key] ?? ''));
                if ($url !== '') {
                    return $url;
                }
            }

            $numeric = [];
            foreach ($urlSizes as $key => $url) {
                if (!\is_numeric((string) $key) || !\is_string($url) || \trim($url) === '') {
                    continue;
                }

                $numeric[(int) $key] = \trim($url);
            }

            if ($numeric !== []) {
                \ksort($numeric);
                $last = \end($numeric);
                return \is_string($last) ? $last : '';
            }
        }

        foreach (['url_main_domain', 'url', 'src', 'image_url', 'thumbnail_url'] as $key) {
            $url = \trim((string) ($image[$key] ?? ''));
            if ($url !== '') {
                return $url;
            }
        }

        return '';
    }

    /**
     * Normalizes boat card template image token.
     */
    private static function normalizeBoatCardTemplateImageToken(string $template, string $imageToken): string
    {
        $imageToken = self::normalizeBoatCardImageToken($imageToken);

        return (string) \preg_replace_callback(
            '/<img\b[^>]*>/i',
            static function (array $match) use ($imageToken): string {
                $img = (string) ($match[0] ?? '');

                if (!\preg_match('/\bclass\s*=\s*(["\'])(?:(?!\1).)*\bmaradigma-boat-card__img\b(?:(?!\1).)*\1/i', $img)) {
                    return $img;
                }

                if (\preg_match('/\bsrc\s*=\s*(["\'])(?:(?!\1).)*\1/i', $img)) {
                    return (string) \preg_replace(
                        '/\bsrc\s*=\s*(["\'])(?:(?!\1).)*\1/i',
                        'src=$1{{' . $imageToken . '}}$1',
                        $img,
                        1
                    );
                }

                return (string) \preg_replace(
                    '/<img\b/i',
                    '<img src="{{' . $imageToken . '}}"',
                    $img,
                    1
                );
            },
            $template
        );
    }

    /**
     * Resolve manually linked WP posts for Maradigma boats.
     *
     * Priority:
     * - Current language post if available
     * - Translation in current language
     * - Any matching post as fallback
     *
     * Returned shape:
     * [
     *   '123' => [
     *     'post_id'        => 55,
     *     'url'            => 'https://example.com/custom-boat-page/',
     *     'thumbnail_url'  => 'https://example.com/uploads/....jpg',
     *   ]
     * ]
     *
     * @param array<int,string> $boatIds
     * @param string            $currentLang
     * @return array<string,array{post_id:int,url:string,thumbnail_url:string}>
     */
    private static function getLinkedWpBoatPostsMap(array $boatIds, string $currentLang): array
    {
        $boatIds = array_values(array_filter(array_map(
            static fn($value): string => trim((string) $value),
            $boatIds
        ), static fn(string $value): bool => $value !== ''));

        if ($boatIds === []) {
            return [];
        }

        $currentLang = strtolower(trim($currentLang));
        $currentLang = (string) (preg_split('/[_-]/', $currentLang)[0] ?? $currentLang);

        $query = new \WP_Query([
            'post_type'              => 'any',
            'post_status'            => 'publish',
            'fields'                 => 'ids',
            'posts_per_page'         => -1,
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'lang'                   => '',
            'meta_query'             => [
                'relation' => 'OR',
                [
                    'key'     => \Maradigma\MetaManager::META_PAGE_BOAT_ID,
                    'value'   => $boatIds,
                    'compare' => 'IN',
                ],
                [
                    'key'     => \Maradigma\MetaManager::META_CPT_BOAT_ID,
                    'value'   => $boatIds,
                    'compare' => 'IN',
                ],
            ],
        ]);

        if (empty($query->posts) || !is_array($query->posts)) {
            return [];
        }

        $bucket = [];

        foreach ($query->posts as $postId) {
            $postId = (int) $postId;
            if ($postId <= 0) {
                continue;
            }

            $postType = (string) get_post_type($postId);
            if ($postType === '') {
                continue;
            }

            $boatId = trim((string) get_post_meta($postId, \Maradigma\MetaManager::META_PAGE_BOAT_ID, true));
            if ($boatId === '') {
                $boatId = trim((string) get_post_meta($postId, \Maradigma\MetaManager::META_CPT_BOAT_ID, true));
            }

            if ($boatId === '') {
                continue;
            }

            $lang = '';

            if (function_exists('pll_get_post_language')) {
                $lang = strtolower(trim((string) pll_get_post_language($postId, 'slug')));
                $lang = (string) (preg_split('/[_-]/', $lang)[0] ?? $lang);
            } elseif (has_filter('wpml_element_language_code')) {
                $maybeLang = apply_filters('wpml_element_language_code', null, [
                    'element_id'   => $postId,
                    'element_type' => 'post_' . $postType,
                ]);

                if (is_string($maybeLang)) {
                    $lang = strtolower(trim($maybeLang));
                    $lang = (string) (preg_split('/[_-]/', $lang)[0] ?? $lang);
                }
            }

            if (!isset($bucket[$boatId])) {
                $bucket[$boatId] = [];
            }

            $bucket[$boatId][$lang !== '' ? $lang : '_'] = $postId;
        }

        $out = [];

        foreach ($bucket as $boatId => $langsMap) {
            $pickedPostId = 0;

            if ($currentLang !== '' && isset($langsMap[$currentLang])) {
                $pickedPostId = (int) $langsMap[$currentLang];
            } elseif (function_exists('pll_get_post')) {
                $anyPostId = (int) reset($langsMap);
                $translatedId = (int) pll_get_post($anyPostId, $currentLang);
                if ($translatedId > 0) {
                    $pickedPostId = $translatedId;
                }
            }

            if ($pickedPostId <= 0) {
                $pickedPostId = isset($langsMap['_']) ? (int) $langsMap['_'] : (int) reset($langsMap);
            }

            if ($pickedPostId <= 0) {
                continue;
            }

            $url = get_permalink($pickedPostId);
            if (!is_string($url) || $url === '') {
                continue;
            }

            $thumbnailUrl = self::getTranslatedPostThumbnailUrl($pickedPostId);

            $out[$boatId] = [
                'post_id'       => $pickedPostId,
                'url'           => $url,
                'thumbnail_url' => $thumbnailUrl,
            ];
        }

        return $out;
    }

    /**
     * Returns translated post thumbnail URL.
     */
    private static function getTranslatedPostThumbnailUrl(int $postId): string
    {
        $postIds = [$postId];

        if (\function_exists('pll_get_post_translations')) {
            $translations = \pll_get_post_translations($postId);
            if (\is_array($translations)) {
                foreach ($translations as $translatedId) {
                    $translatedId = (int) $translatedId;
                    if ($translatedId > 0) {
                        $postIds[] = $translatedId;
                    }
                }
            }
        }

        foreach (\array_values(\array_unique($postIds)) as $candidateId) {
            if (!\has_post_thumbnail($candidateId)) {
                continue;
            }

            $thumbId = (int) \get_post_thumbnail_id($candidateId);
            if ($thumbId <= 0) {
                continue;
            }

            $src = \wp_get_attachment_image_src($thumbId, 'full');
            if (\is_array($src) && !empty($src[0]) && \is_string($src[0])) {
                return (string) $src[0];
            }
        }

        return '';
    }

    /* ============================================================
     * FICHA DE BARCO → TEMPLATE SOBRESCRIBIBLE
     * ============================================================ */


    /**
     * Render a single boat details using a PHP template.
     *
     * Uso típico (retro-compatible):
     *   [maradigma_boat slug="alfastreet-marine-28-sanfil"]
     *   [maradigma_boat id="46"]
     *
     * El template se busca en:
     *   1) child-theme/ maradigma/single-boat.php
     *   2) parent-theme/ maradigma/single-boat.php
     *   3) plugin/ templates/single-boat.php (fallback)
     *
     * En el template tendrás disponibles:
     *   - $boat    → array con TODOS los campos del barco (tal cual API)
     *   - $atts    → atributos del shortcode
     *   - $settings→ ajustes del plugin
     *   - $language→ idioma usado en la API
     *
     * @param array<string,string> $atts
     * @return string
     */
    public static function renderSingleBoat(array $atts = []): string
    {
        if (!function_exists('shortcode_atts')) {
            return '';
        }

        $defaultAtts = [
            'slug'   => '',
            'id'     => '',
            'expand' => '',
            'fields' => '',
        ];

        foreach (\Maradigma\ExternalApiClient::getAllowedBoatDetailsScalarOptions() as $optionKey) {
            $defaultAtts[$optionKey] = '';
        }

        $atts = shortcode_atts(
            $defaultAtts,
            $atts,
            'maradigma_boat'
        );

        $boatDetailsOptions = \Maradigma\ExternalApiClient::normalizeBoatDetailsOptions($atts);

        $boat = self::loadBoat($atts, [], $boatDetailsOptions);
        if (!$boat) {
            return '<p>' . esc_html__('Boat not found.', 'maradigma') . '</p>';
        }

        $settings = self::getSettings();
        $language = self::getLanguage();

        $template = self::locateTemplate('single-boat.php');
        if ($template === null) {
            // Si no hay template, no rompemos: sacamos algo mínimo
            return esc_html((string) ($boat['boat_alias'] ?? $boat['reference'] ?? ''));
        }

        // Variables disponibles en el template
        /** @var array<string,mixed> $boat */
        /** @var array<string,string> $atts */
        /** @var array<string,mixed> $settings */
        /** @var string $language */

        ob_start();
        include $template;
        return (string) ob_get_clean();
    }

    /**
     * Localiza un template de Maradigma.
     */
    private static function locateTemplate(string $fileName): ?string
    {
        // Buscamos en el theme: /maradigma/{fileName}
        $themePath = 'maradigma/' . ltrim($fileName, '/');
        $located   = locate_template([$themePath]);

        if (!empty($located)) {
            return $located;
        }

        // Fallback: template dentro del propio plugin
        $pluginTemplate = plugin_dir_path(MARADIGMA_PLUGIN_FILE) . 'templates/' . $fileName;
        if (is_readable($pluginTemplate)) {
            return $pluginTemplate;
        }

        return null;
    }

    /**
     * Renders a configurable "Boat Specs" block as a shortcode.
     *
     * Shortcode: {@code [maradigma_boat_specs]}
     *
     * This shortcode prints a list of boat specifications (builder, length, beam, etc.) using the
     * cached boat details retrieved by {@see self::loadBoat()}. It supports:
     * - Multiple layouts ("two_cols" or "list").
     * - Optional labels (dt) and optional icons (SVG symbols from the Maradigma sprite).
     * - Custom per-field icon mapping through the {@code icons} attribute.
     * - Configuration via inline JSON (content between opening/closing shortcode tags).
     *
     * Basic usage:
     * - {@code [maradigma_boat_specs id="304"]}
     * - {@code [maradigma_boat_specs slug="sunseeker-52"]}
     *
     * Layout and UI:
     * - {@code [maradigma_boat_specs id="304" layout="list" show_labels="0" show_icons="1"]}
     *
     * Custom icon mapping:
     * - {@code [maradigma_boat_specs id="304" icons="boat_length:ruler,boat_base_port_name:map-marker"]}
     *   - Icon values may be passed as "ruler" or "svg-ruler" (both resolve to the same symbol id).
     *
     * Fields fallback (CSV):
     * - {@code [maradigma_boat_specs id="304" fields="boat_type,boat_builder,boat_length,boat_beam"]}
     * - Custom keys can be declared using {@code custom:my_key} in fields CSV:
     *   {@code fields="boat_length,custom:boat_color,boat_base_port_name"}
     *
     * Inline JSON configuration (content):
     * - {@code [maradigma_boat_specs id="304"]}
     *     {@code [{"field":"boat_length","label":"Length","format":"meters"}]}
     *   {@code [/maradigma_boat_specs]}
     *
     * JSON may be either:
     * - A list of items: {@code [{"field":"boat_length",...}, ...]}
     * - Or an object with {@code {"items":[...]}}.
     *
     * Each item supports:
     * - field (string): predefined key or "custom"
     * - custom_key (string): required when field="custom"
     * - label (string): label text (dt); if empty and labels enabled, a default label is used
     * - format (string): "text" | "int" | "meters" | "boolean"
     * - prefix (string): prefix concatenated to value
     * - suffix (string): suffix concatenated to value
     * - fallback (string): used when the extracted value is empty
     *
     * Notes:
     * - The renderer expects the Maradigma SVG sprite to be present in the DOM when icons are enabled.
     *   In your stack, this is typically ensured by your "maradigma-icons" script (sprite injection).
     *
     * @param array<string,mixed> $atts    Shortcode attributes.
     * @param string|null         $content Optional JSON configuration inside the shortcode body.
     *
     * @return string Rendered HTML for the boat specs block (or an empty-state message when boat/specs are missing).
     */
    public static function renderBoatSpecs(array $atts = [], ?string $content = null): string
    {
        if (!function_exists('shortcode_atts')) {
            return '';
        }

        $atts = shortcode_atts(
            [
                'id'   => '',
                'slug' => '',

                // UI
                'layout'      => 'two_cols', // two_cols | list
                'show_labels' => '1',
                'show_icons'  => '1',

                // Icons map: "field:icon,field2:icon2"
                // icon can be "ruler" OR "svg-ruler"
                'icons' => '',

                // Basic fields CSV fallback (if no JSON content is passed)
                'fields' => '', // e.g. "boat_type,boat_builder,boat_length"
            ],
            $atts,
            'maradigma_boat_specs'
        );

        $boat = self::loadBoat([
            'id'   => (string)$atts['id'],
            'slug' => (string)$atts['slug'],
        ]);

        if (!$boat || !is_array($boat)) {
            return '<div class="maradigma-boat-specs maradigma-boat-specs--empty">' . esc_html__('Boat not found.', 'maradigma') . '</div>';
        }

        // ─────────────────────────────────────────────
        // Build items
        // ─────────────────────────────────────────────
        $items = [];

        // 1) If content contains JSON array, use it.
        $rawContent = is_string($content) ? trim($content) : '';
        if ($rawContent !== '') {
            $decoded = json_decode($rawContent, true);
            if (is_array($decoded)) {
                // allow either list of items or {"items":[...]}
                if (isset($decoded['items']) && is_array($decoded['items'])) {
                    $items = $decoded['items'];
                } else {
                    $items = $decoded;
                }
            }
        }

        // 2) Fallback: fields CSV -> items with defaults
        if (!is_array($items) || $items === []) {
            $fieldsCsv = trim((string)$atts['fields']);
            if ($fieldsCsv !== '') {
                $fields = array_values(array_filter(array_map('trim', explode(',', $fieldsCsv)), static fn($v) => $v !== ''));
                foreach ($fields as $f) {
                    // support custom keys: custom:my_key
                    if (str_starts_with($f, 'custom:')) {
                        $customKey = trim(substr($f, 7));
                        if ($customKey === '') continue;
                        $items[] = [
                            'field'      => 'custom',
                            'custom_key' => $customKey,
                            'label'      => '',
                            'format'     => 'text',
                            'prefix'     => '',
                            'suffix'     => '',
                            'fallback'   => '',
                        ];
                        continue;
                    }

                    $items[] = [
                        'field'    => $f,
                        'label'    => '',
                        'format'   => ($f === 'boat_length' || $f === 'boat_beam') ? 'meters' : 'text',
                        'prefix'   => '',
                        'suffix'   => '',
                        'fallback' => '',
                    ];
                }
            } else {
                // same defaults as widget
                $items = [
                    ['field' => 'boat_type', 'label' => __('Type', 'maradigma'), 'format' => 'text'],
                    ['field' => 'boat_builder', 'label' => __('Builder', 'maradigma'), 'format' => 'text'],
                    ['field' => 'boat_model', 'label' => __('Model', 'maradigma'), 'format' => 'text'],
                    ['field' => 'boat_capacity', 'label' => __('Capacity', 'maradigma'), 'format' => 'int'],
                    ['field' => 'boat_cabins', 'label' => __('Cabins', 'maradigma'), 'format' => 'int'],
                    ['field' => 'boat_length', 'label' => __('Length', 'maradigma'), 'format' => 'meters'],
                    ['field' => 'boat_beam', 'label' => __('Beam', 'maradigma'), 'format' => 'meters'],
                    ['field' => 'boat_consumption', 'label' => __('Fuel consumption', 'maradigma'), 'format' => 'text', 'suffix' => ' L/H'],
                    ['field' => 'boat_base_port_name', 'label' => __('Base port', 'maradigma'), 'format' => 'text'],
                ];
            }
        }

        // ─────────────────────────────────────────────
        // Icons map parse
        // ─────────────────────────────────────────────
        $iconsMap = [];
        $iconsCsv = trim((string)$atts['icons']);

        if ($iconsCsv !== '') {
            $pairs = array_values(array_filter(array_map('trim', explode(',', $iconsCsv)), static fn($v) => $v !== ''));
            foreach ($pairs as $p) {
                // "boat_length:ruler"
                $parts = array_map('trim', explode(':', $p, 2));
                $k = (string)($parts[0] ?? '');
                $v = (string)($parts[1] ?? '');
                if ($k === '' || $v === '') continue;
                $iconsMap[$k] = $v;
            }
        }

        $layout     = in_array((string)$atts['layout'], ['two_cols', 'list'], true) ? (string)$atts['layout'] : 'two_cols';
        $showLabels = ((string)$atts['show_labels'] === '1');
        $showIcons  = ((string)$atts['show_icons'] === '1');

        // Render via shared renderer
        return \Maradigma\Support\BoatSpecsRenderer::render(
            (array)$boat,
            (array)$items,
            [
                'layout'      => $layout,
                'show_labels' => $showLabels,
                'show_icons'  => $showIcons,
                'icons_map'   => $iconsMap,
            ]
        );
    }

    /**
     * Render boat additional services shortcode.
     *
     * Examples:
     * [maradigma_boat_additional_services id="2410"]
     * [maradigma_boat_additional_services slug="pardo-yachts-38-test" title="Additional services"]
     * [maradigma_boat_additional_services layout="table" vat_mode="included" show_badges="1"]
     *
     * @param array<string,mixed> $atts
     * @return string
     */
    public static function renderBoatAdditionalServices(array $atts = []): string
    {
        if (!function_exists('shortcode_atts')) {
            return '';
        }

        $atts = shortcode_atts(
            [
                'id'                       => '',
                'slug'                     => '',
                'title'                    => (string) __('Additional services', 'maradigma'),
                'show_title'               => '1',
                'fallback'                 => '',
                'layout'                   => 'blocks',
                'show_headers'             => '1',
                'group_by_category'        => '1',
                'show_category_title'      => '1',
                'show_badges'              => '1',
                'show_badge_optional_type' => '1',
                'show_badge_price_type'    => '1',
                'show_badge_payment'       => '1',
                'badge_style'              => 'friendly',
                'show_description'         => '0',
                'show_quantity'            => '1',
                'price_display'            => 'total_html',
                'vat_mode'                 => 'included',
                'vat_position'             => 'below',
                'vat_text_included'        => (string) __('VAT included', 'maradigma'),
                'vat_text_excluded'        => (string) __('+ VAT', 'maradigma'),
                'currency_display'         => 'symbol',
                'decimals_mode'            => 'auto',
                'thousands_sep'            => '.',
                'decimal_sep'              => ',',
            ],
            $atts,
            'maradigma_boat_additional_services'
        );

        $boat = self::loadBoat(
            [
                'id'   => (string) $atts['id'],
                'slug' => (string) $atts['slug'],
            ],
            ['additionals'],
            ['expand' => ['service_additional_services']]
        );

        if (!$boat || !is_array($boat)) {
            return '';
        }

        $title = trim((string) $atts['title']);

        return \Maradigma\Support\BoatAdditionalServicesRenderer::render(
            $boat,
            [
                'language'                 => self::getLanguage(),
                'title'                    => $title,
                'show_title'               => $atts['show_title'],
                'fallback'                 => $atts['fallback'],
                'layout'                   => $atts['layout'],
                'show_headers'             => $atts['show_headers'],
                'group_by_category'        => $atts['group_by_category'],
                'show_category_title'      => $atts['show_category_title'],
                'show_badges'              => $atts['show_badges'],
                'show_badge_optional_type' => $atts['show_badge_optional_type'],
                'show_badge_price_type'    => $atts['show_badge_price_type'],
                'show_badge_payment'       => $atts['show_badge_payment'],
                'badge_style'              => $atts['badge_style'],
                'show_description'         => $atts['show_description'],
                'show_quantity'            => $atts['show_quantity'],
                'price_display'            => $atts['price_display'],
                'vat_mode'                 => $atts['vat_mode'],
                'vat_position'             => $atts['vat_position'],
                'vat_text_included'        => $atts['vat_text_included'],
                'vat_text_excluded'        => $atts['vat_text_excluded'],
                'currency_display'         => $atts['currency_display'],
                'decimals_mode'            => $atts['decimals_mode'],
                'thousands_sep'            => $atts['thousands_sep'],
                'decimal_sep'              => $atts['decimal_sep'],
            ]
        );
    }

    /**
     * Render boat prices shortcode.
     *
     * Examples:
     * [maradigma_boat_prices id="2410"]
     * [maradigma_boat_prices slug="pardo-yachts-38-test"]
     *
     * @param array<string,mixed> $atts
     * @return string
     */
    public static function renderBoatPrices(array $atts = []): string
    {
        if (!function_exists('shortcode_atts')) {
            return '';
        }

        $atts = shortcode_atts(
            [
                'id'         => '',
                'slug'       => '',
                'title'      => (string) __('Prices', 'maradigma'),
                'show_title' => '1',
                'fallback'   => (string) __('Prices not available.', 'maradigma'),
                'empty_text'  => '',
                'layout'            => 'cards',
                'range_mode'        => 'dates_short',
                'order_by'          => 'date_from_asc',
                'show_headers'      => '1',
                'row_gap'           => '10',
                'vat_mode'          => 'included',
                'vat_use_backend'   => '0',
                'vat_position'      => 'below',
                'vat_text_included' => (string) __('VAT included', 'maradigma'),
                'vat_text_excluded' => (string) __('+ VAT', 'maradigma'),
                'currency_display'  => 'symbol',
                'decimals_mode'     => 'auto',
                'thousands_sep'     => '.',
                'decimal_sep'       => ',',
            ],
            $atts,
            'maradigma_boat_prices'
        );

        $boat = self::loadBoat(
            [
                'id'   => (string) $atts['id'],
                'slug' => (string) $atts['slug'],
            ],
            ['prices'],
            ['expand' => ['service_prices']]
        );

        if (!$boat || !is_array($boat)) {
            return '';
        }

        $title = trim((string) $atts['title']);
        if ($title !== '') {
            $title = \Maradigma\Support\MultilangAdapter::translateEditableDefault(
                $title,
                'Prices',
                (string) __('Prices', 'maradigma'),
                'Maradigma Elementor Widgets',
                'boat_prices_shortcode_title'
            );
        }

        $fallback = trim((string) ($atts['empty_text'] !== '' ? $atts['empty_text'] : $atts['fallback']));
        if ($fallback !== '') {
            $fallback = \Maradigma\Support\MultilangAdapter::translateEditableDefault(
                $fallback,
                'Prices not available.',
                (string) __('Prices not available.', 'maradigma'),
                'Maradigma Elementor Widgets',
                'boat_prices_shortcode_empty_text'
            );
        }

        return \Maradigma\Support\BoatPricesRenderer::render(
            $boat,
            [
                'title'             => $title,
                'show_title'        => $atts['show_title'],
                'fallback'          => $fallback,
                'layout'            => $atts['layout'],
                'range_mode'        => $atts['range_mode'],
                'order_by'          => $atts['order_by'],
                'show_headers'      => $atts['show_headers'],
                'row_gap'           => $atts['row_gap'],
                'vat_mode'          => $atts['vat_mode'],
                'vat_use_backend'   => $atts['vat_use_backend'],
                'vat_position'      => $atts['vat_position'],
                'vat_text_included' => $atts['vat_text_included'],
                'vat_text_excluded' => $atts['vat_text_excluded'],
                'currency_display'  => $atts['currency_display'],
                'decimals_mode'     => $atts['decimals_mode'],
                'thousands_sep'     => $atts['thousands_sep'],
                'decimal_sep'       => $atts['decimal_sep'],
            ]
        );
    }

    /* ============================================================
     * SHORTCODE GENÉRICO DE CAMPO
     * ============================================================ */

    /**
     * Shortcode genérico de campo:
     *
     *   [maradigma_boat_field slug="alfastreet-marine-28-sanfil" field="boat_capacity"]
     *   [maradigma_boat_field id="46" field="boat_length" format="number" decimals="2"]
     *   [maradigma_boat_field slug="..." field="description_html" esc="false"]
     *
     * Atributos:
     *  - slug | id: identificador del barco (uno de los dos)
     *  - field: nombre exacto del índice del array devuelto por la API (ej: boat_capacity, boat_year_construction…)
     *  - esc: "true" (por defecto) applies esc_html; "false" allows wp_kses_post HTML
     *  - fallback: texto si el campo no existe o es null
     *  - format: "raw" (default), "number", "price"
     *  - decimals: para format="number" o "price"
     *
     * @param array<string,string> $atts
     * @return string
     */
    public static function renderBoatField(array $atts = []): string
    {
        if (!function_exists('shortcode_atts')) {
            return '';
        }

        $atts = shortcode_atts(
            [
                'slug'     => '',
                'id'       => '',
                'field'    => '',
                'esc'      => 'true',
                'fallback' => '',
                'format'   => 'raw',
                'decimals' => '0',
            ],
            $atts,
            'maradigma_boat_field'
        );

        $field = trim($atts['field']);
        if ($field === '') {
            return '';
        }

        $boat = self::loadBoat($atts);
        if (!$boat || !array_key_exists($field, $boat)) {
            return esc_html((string) $atts['fallback']);
        }

        $value = $boat[$field];

        // Formateo básico
        $format   = strtolower($atts['format']);
        $decimals = (int) $atts['decimals'];

        if ($format === 'number' && is_numeric($value)) {
            $value = number_format_i18n((float) $value, $decimals);
        } elseif ($format === 'price' && is_numeric($value)) {
            // price + currency si existe
            $currency = isset($boat['currency']) ? (string) $boat['currency'] : 'EUR';
            $formatted = number_format_i18n((float) $value, $decimals);
            $value     = sprintf('%s %s', $formatted, $currency);
        }

        $esc = strtolower($atts['esc']) !== 'false';

        if (is_scalar($value) || (is_object($value) && method_exists($value, '__toString'))) {
            $stringValue = (string) $value;
            return $esc ? esc_html($stringValue) : wp_kses_post($stringValue);
        }

        // Para arrays/objetos, devolvemos JSON (útil para debug) o fallback
        $json = wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return esc_html((string) $atts['fallback']);
        }
        return esc_html($json);
    }

    /* ============================================================
     * SHORTCODES AZÚCAR PARA CAMPOS TÍPICOS
     * ============================================================ */

    /**
     * [maradigma_boat_name slug="..."]
     */
    public static function renderBoatName(array $atts = []): string
    {
        $atts['field'] = $atts['field'] ?? 'boat_alias';
        return self::renderBoatField($atts);
    }

    /**
     * [maradigma_boat_title slug="..."]
     *
     * Returns the configurable display title used by single-boat builders.
     * Default format: builder + model + "alias".
     */
    public static function renderBoatTitle(array $atts = []): string
    {
        if (!function_exists('shortcode_atts')) {
            return '';
        }

        $atts = shortcode_atts(
            [
                'slug'                  => '',
                'id'                    => '',
                'fallback'              => '',
                'show_builder'          => '1',
                'show_model'            => '1',
                'show_alias'            => '1',
                'quote_alias'           => '1',
                'fallback_service_name' => '1',
                'separator'             => ' ',
            ],
            $atts,
            'maradigma_boat_title'
        );

        $boat = self::loadBoat($atts);
        if (!$boat || !is_array($boat)) {
            return esc_html((string) $atts['fallback']);
        }

        $isEnabled = static function ($value): bool {
            return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
        };

        $builder = trim((string) ($boat['boat_builder'] ?? $boat['builder'] ?? $boat['manufacturer'] ?? ''));
        $model   = trim((string) ($boat['boat_model'] ?? $boat['model'] ?? $boat['boat_modal'] ?? ''));
        $alias   = trim((string) ($boat['boat_alias'] ?? $boat['alias'] ?? ''));

        $parts = [];
        if ($isEnabled($atts['show_builder']) && $builder !== '') {
            $parts[] = $builder;
        }
        if ($isEnabled($atts['show_model']) && $model !== '') {
            $parts[] = $model;
        }
        if ($isEnabled($atts['show_alias']) && $alias !== '') {
            $parts[] = $isEnabled($atts['quote_alias']) ? '"' . $alias . '"' : $alias;
        }

        $separator = (string) $atts['separator'];
        if ($separator === '') {
            $separator = ' ';
        }

        $title = trim(implode($separator, $parts));

        if ($title === '' && $isEnabled($atts['fallback_service_name'])) {
            $title = trim((string) ($boat['service_name'] ?? $boat['name'] ?? $boat['title'] ?? ''));
        }

        if ($title === '') {
            $title = trim((string) $atts['fallback']);
        }

        return $title !== '' ? esc_html($title) : '';
    }

    /**
     * [maradigma_boat_pax slug="..."]
     */
    public static function renderBoatPax(array $atts = []): string
    {
        $atts['field']    = 'boat_capacity';
        $atts['format']   = $atts['format'] ?? 'number';
        $atts['decimals'] = $atts['decimals'] ?? '0';
        return self::renderBoatField($atts);
    }

    /**
     * [maradigma_boat_length slug="..." decimals="2"]
     */
    public static function renderBoatLength(array $atts = []): string
    {
        $atts['field']    = 'boat_length';
        $atts['format']   = $atts['format'] ?? 'number';
        $atts['decimals'] = $atts['decimals'] ?? '2';
        return self::renderBoatField($atts);
    }

    /**
     * [maradigma_boat_beam slug="..." decimals="2"]
     */
    public static function renderBoatBeam(array $atts = []): string
    {
        $atts['field']    = 'boat_beam';
        $atts['format']   = $atts['format'] ?? 'number';
        $atts['decimals'] = $atts['decimals'] ?? '2';
        return self::renderBoatField($atts);
    }

    /**
     * [maradigma_boat_builder slug="..."]
     */
    public static function renderBoatBuilder(array $atts = []): string
    {
        $atts['field'] = 'boat_builder';
        return self::renderBoatField($atts);
    }

    /**
     * [maradigma_boat_base_port slug="..."]
     */
    public static function renderBoatBasePort(array $atts = []): string
    {
        $atts['field']    = 'boat_base_port_name';
        $atts['fallback'] = $atts['fallback'] ?? '';
        return self::renderBoatField($atts);
    }

    /**
     * Renders the main (cover) image of a boat as an <img> tag.
     *
     * Shortcode:
     *   [maradigma_boat_main_image slug="..." size="1100" attr='class="img-fluid"']
     *
     * Behavior:
     * - If WP Media cache is enabled (SettingsPage::getSettings()['store_boat_images_locally'] = true) and
     *   prefer_wp_cache="1", this method tries to load the cached cover attachment deterministically:
     *     1) attachment with meta _maradigma_is_cover = 1
     *     2) else the attachment with the lowest _maradigma_image_order
     *     3) else the most recent attachment for the boat
     *
     * - If no cached attachment is found (or cache is disabled), it falls back to the API payload:
     *     - chooses the image with number_order = 1 (API contract)
     *     - else falls back to the first image in the array
     *     - picks the best URL using url_sizes (numeric or named keys), else url
     *
     * Notes:
     * - The method returns a complete <img> element (practical for themes/builders).
     * - The "attr" attribute is filtered against allowed <img> attributes.
     *
     * Supported attributes:
     * - slug (string)            Boat slug.
     * - id (string|int)          Boat id.
     * - size (string)            Desired size key. Can be numeric (e.g. "1100") or a named key
     *                            (e.g. "large", "full", "xl") depending on API payload.
     * - attr (string)            Allowed HTML attributes appended to <img>. Default: loading="lazy".
     * - prefer_wp_cache (0|1)    Whether to prefer the WP Media cached image when available. Default: 1.
     *
     * @param array<string,string> $atts Shortcode attributes.
     * @return string Rendered <img> tag or empty string when unavailable.
     */
    public static function renderBoatMainImage(array $atts = []): string
    {
        if (!function_exists('shortcode_atts')) {
            return '';
        }

        $atts = shortcode_atts(
            [
                'slug' => '',
                'id'   => '',
                'size' => '1100', // target width (numeric string) or named keys
                'attr' => 'loading="lazy"',
                'prefer_wp_cache' => '1', // NEW: use WP cache if enabled
            ],
            $atts,
            'maradigma_boat_main_image'
        );

        $boat = self::loadBoat($atts);
        if (!$boat || !is_array($boat)) {
            return '';
        }

        $boatId = trim((string)($boat['id'] ?? $atts['id'] ?? ''));
        $images = $boat['images'] ?? null;

        // 1) Prefer WP cached cover if enabled and available
        $preferWp = ((string)$atts['prefer_wp_cache'] === '1');
        if ($preferWp && $boatId !== '' && class_exists(\Maradigma\SettingsPage::class)) {
            $settings = \Maradigma\SettingsPage::getSettings();
            if (!empty($settings['store_boat_images_locally'])) {
                $attId = self::findCoverAttachmentIdByBoatId($boatId);
                if ($attId > 0) {
                    $size  = trim((string)$atts['size']);
                    $wpSize = 'full';

                    // Map numeric "size" to WP image sizes (best effort)
                    if (ctype_digit($size)) {
                        $w = (int)$size;
                        if ($w <= 150) {
                            $wpSize = 'thumbnail';
                        } elseif ($w <= 300) {
                            $wpSize = 'medium';
                        } elseif ($w <= 768) {
                            $wpSize = 'medium_large';
                        } else {
                            $wpSize = 'large';
                        }
                    }

                    $src = wp_get_attachment_image_src($attId, $wpSize);
                    if (is_array($src) && !empty($src[0])) {
                        $alt  = (string)($boat['boat_alias'] ?? $boat['service_name'] ?? $boat['reference'] ?? '');
                        $attr = trim((string)$atts['attr']);
                        $attr = $attr !== '' ? ' ' . $attr : '';

                        return self::sanitizeBoatMainImageHtml(
                            sprintf(
                                '<img src="%s" alt="%s"%s />',
                                esc_url((string)$src[0]),
                                esc_attr($alt),
                                $attr
                            )
                        );
                    }
                }
            }
        }

        // 2) Fallback to payload (API)
        if (!is_array($images) || empty($images)) {
            return '';
        }

        // Pick cover by number_order = 1; else fallback to first item.
        $cover = null;
        foreach ($images as $img) {
            if (!is_array($img)) {
                continue;
            }

            $ord = isset($img['number_order']) && is_numeric($img['number_order'])
                ? (int)$img['number_order']
                : 0;

            if ($ord === 1) {
                $cover = $img;
                break;
            }
        }

        if ($cover === null) {
            $cover = reset($images);
            if (!is_array($cover)) {
                return '';
            }
        }

        $sizeKey = trim((string)$atts['size']);
        $url     = '';

        // Prefer url_sizes (numeric or named)
        $urlSizes = $cover['url_sizes'] ?? $cover['sizes'] ?? null;

        if (is_array($urlSizes)) {
            // If numeric requested (e.g. "1100"), try exact first
            if ($sizeKey !== '' && isset($urlSizes[$sizeKey]) && is_string($urlSizes[$sizeKey])) {
                $url = (string)$urlSizes[$sizeKey];
            }

            // If still empty and numeric requested: choose closest >= target, else largest
            if ($url === '' && ctype_digit($sizeKey)) {
                $target  = (int)$sizeKey;
                $numeric = [];

                foreach ($urlSizes as $k => $v) {
                    if (!is_string($v) || $v === '') {
                        continue;
                    }
                    if (!is_numeric((string)$k)) {
                        continue;
                    }
                    $numeric[(int)$k] = $v;
                }

                if (!empty($numeric)) {
                    ksort($numeric); // asc
                    foreach ($numeric as $w => $u) {
                        if ($w >= $target) {
                            $url = (string)$u;
                            break;
                        }
                    }
                    if ($url === '') {
                        $url = (string)end($numeric);
                    }
                }
            }

            // Named fallback keys
            if ($url === '') {
                foreach (['full', 'original', 'xxl', 'xl', 'large', 'medium'] as $k) {
                    if (!empty($urlSizes[$k]) && is_string($urlSizes[$k])) {
                        $url = (string)$urlSizes[$k];
                        break;
                    }
                }
            }
        }

        // Fallback to raw url
        if ($url === '' && !empty($cover['url']) && is_string($cover['url'])) {
            $url = (string)$cover['url'];
        }

        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $alt  = (string)($boat['boat_alias'] ?? $boat['service_name'] ?? $boat['reference'] ?? '');
        $attr = trim((string)$atts['attr']);
        $attr = $attr !== '' ? ' ' . $attr : '';

        return self::sanitizeBoatMainImageHtml(
            sprintf(
                '<img src="%s" alt="%s"%s />',
                esc_url($url),
                esc_attr($alt),
                $attr
            )
        );
    }

    /**
     * Keep shortcode-provided image attributes within a strict HTML context.
     */
    private static function sanitizeBoatMainImageHtml(string $html): string
    {
        return wp_kses(
            $html,
            [
                'img' => [
                    'src'           => true,
                    'alt'           => true,
                    'class'         => true,
                    'id'            => true,
                    'title'         => true,
                    'width'         => true,
                    'height'        => true,
                    'srcset'        => true,
                    'sizes'         => true,
                    'loading'       => true,
                    'decoding'      => true,
                    'fetchpriority' => true,
                    'itemprop'      => true,
                    'data-*'        => true,
                    'aria-*'        => true,
                ],
            ]
        );
    }

    /**
     * Finds the best attachment id for the boat "cover" image deterministically.
     *
     * Selection order:
     * 1) Attachment with meta:
     *    - _maradigma_boat_id = $boatId
     *    - _maradigma_is_cover = 1
     * 2) If none, the attachment with the lowest numeric meta_value for _maradigma_image_order
     * 3) If none, the most recently created attachment for the boat
     *
     * This method is intentionally independent from CPT posts: attachments are linked to boats
     * only through postmeta (boat id + ordering fields).
     *
     * @param string $boatId Boat id as provided by the API.
     * @return int Attachment post ID or 0 if none.
     */
    private static function findCoverAttachmentIdByBoatId(string $boatId): int
    {
        $boatId = trim($boatId);
        if ($boatId === '') {
            return 0;
        }

        // 1) Cover first
        $q1 = new \WP_Query([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'fields'         => 'ids',
            'posts_per_page' => 1,
            'no_found_rows'  => true,
            'meta_query'     => [
                ['key' => '_maradigma_boat_id',  'value' => $boatId],
                ['key' => '_maradigma_is_cover', 'value' => '1'],
            ],
        ]);

        if (!empty($q1->posts[0])) {
            return (int)$q1->posts[0];
        }

        // 2) Else: lowest order using meta_value_num ordering
        $q2 = new \WP_Query([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'fields'         => 'ids',
            'posts_per_page' => 1,
            'no_found_rows'  => true,
            'meta_key'       => '_maradigma_image_order',
            'orderby'        => 'meta_value_num',
            'order'          => 'ASC',
            'meta_query'     => [
                ['key' => '_maradigma_boat_id', 'value' => $boatId],
                ['key' => '_maradigma_image_order', 'compare' => 'EXISTS'],
            ],
        ]);

        if (!empty($q2->posts[0])) {
            return (int)$q2->posts[0];
        }

        // 3) Fallback: any attachment for boat
        $q3 = new \WP_Query([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'fields'         => 'ids',
            'posts_per_page' => 1,
            'no_found_rows'  => true,
            'meta_query'     => [
                ['key' => '_maradigma_boat_id', 'value' => $boatId],
            ],
            'orderby' => ['date' => 'DESC'],
        ]);

        return !empty($q3->posts[0]) ? (int)$q3->posts[0] : 0;
    }

    /**
     * Renders a boat gallery (grid or slider) as a shortcode.
     *
     * Shortcode: {@code [maradigma_boat_gallery]}
     *
     * This shortcode outputs a gallery of boat images based on the cached boat details
     * returned by {@see self::loadBoat()}. It supports:
     * - Grid layout (2/3/4 columns).
     * - Slider layout (Swiper v11) with optional navigation, pagination and autoplay.
     * - Optional Elementor lightbox integration via {@code data-elementor-open-lightbox="yes"}.
     * - Index range (start/end) and max images limit.
     * - Size preference for API image sizes via {@code prefer_size_key}.
     * - Visual options (gap, radius) and stable aspect ratio via CSS variables.
     *
     * IMPORTANT (lightbox):
     * - If {@code enable_lightbox="1"}, the markup is compatible with Elementor Lightbox.
     * - Elementor Lightbox must be present on the frontend for it to work.
     *   If you need a theme-independent lightbox, we can add a Maradigma native lightbox.
     *
     * Usage examples:
     * - {@code [maradigma_boat_gallery id="304" layout="grid" columns="3"]}
     * - {@code [maradigma_boat_gallery slug="sunseeker-52" layout="slider" slider_navigation="1" slider_pagination="1"]}
     * - {@code [maradigma_boat_gallery id="304" start_index="1" end_index="8" max_images="8" gap="12" radius="12"]}
     *
     * Attributes:
     * - id (string|int)              Boat id.
     * - slug (string)                Boat slug.
     * - layout (grid|slider)          Default: grid.
     * - columns (2|3|4)               Default: 3 (grid only).
     * - enable_lightbox (0|1)         Default: 1.
     * - start_index (int, 1-based)    Default: 1.
     * - end_index (int, 0=last)       Default: 0.
     * - max_images (int)              Default: 12.
     * - prefer_size_key (string)      Default: large.
     * - prefer_wp_cache (0|1)         Default: 1. Uses local Media Library images first when enabled in settings.
     * - gap (int px)                  Default: 10.
     * - radius (int px)               Default: 10.
     *
     * Slider attributes (layout=slider):
     * - slider_autoplay (0|1)         Default: 0.
     * - slider_delay (ms)             Default: 3500.
     * - slider_loop (0|1)             Default: 1.
     * - slider_speed (ms)             Default: 400.
     * - slider_space_between (px)     Default: 10.
     * - slider_per_view_desktop (int) Default: 1.
     * - slider_per_view_tablet (int)  Default: 1.
     * - slider_per_view_mobile (int)  Default: 1.
     * - slider_navigation (0|1)       Default: 1.
     * - slider_pagination (0|1)       Default: 1.
     *
     * @param array<string,mixed> $atts Shortcode attributes.
     * @return string HTML markup for the boat gallery (empty string when no images are available).
     */
    public static function renderBoatGallery(array $atts = []): string
    {
        if (!function_exists('shortcode_atts')) {
            return '';
        }

        $atts = shortcode_atts(
            [
                'id'   => '',
                'slug' => '',

                'layout'          => 'grid',
                'columns'         => '3',
                'enable_lightbox' => '1',

                'start_index' => '1',
                'end_index'   => '0',
                'max_images'  => '12',

                'prefer_size_key' => 'large',
                'prefer_wp_cache' => '1',

                'gap'    => '10',
                'radius' => '10',

                // Slider options
                'slider_autoplay'         => '0',
                'slider_delay'            => '3500',
                'slider_loop'             => '1',
                'slider_speed'            => '400',
                'slider_space_between'    => '10',
                'slider_per_view_desktop' => '1',
                'slider_per_view_tablet'  => '1',
                'slider_per_view_mobile'  => '1',
                'slider_navigation'       => '1',
                'slider_pagination'       => '1',
            ],
            $atts,
            'maradigma_boat_gallery'
        );

        $boat = self::loadBoat([
            'id'   => (string) $atts['id'],
            'slug' => (string) $atts['slug'],
        ]);

        if (!$boat || !is_array($boat)) {
            return '';
        }

        $preferKey = trim((string) $atts['prefer_size_key']);
        $boatId = self::resolveBoatIdFromBoatData($boat, [
            'id'   => (string) $atts['id'],
            'slug' => (string) $atts['slug'],
        ]);

        $images = null;
        $settings = SettingsPage::getSettings();
        $preferWpCache = ((string) $atts['prefer_wp_cache'] === '1');

        if (
            $preferWpCache
            && !empty($settings['store_boat_images_locally'])
            && $boatId !== ''
            && class_exists(\Maradigma\BoatImagesSyncService::class)
        ) {
            $attachmentIds = \Maradigma\BoatImagesSyncService::getOrderedAttachmentIdsByBoatId($boatId, 200);
            $wpImages = [];

            foreach ($attachmentIds as $attachmentId) {
                $attachmentId = (int) $attachmentId;
                if ($attachmentId <= 0) {
                    continue;
                }

                $thumb = '';
                if ($preferKey !== '') {
                    $thumb = (string) \wp_get_attachment_image_url($attachmentId, $preferKey);
                }

                if ($thumb === '') {
                    foreach (['maradigma_card_960x540', 'maradigma_card_640x360', 'large', 'medium_large', 'full'] as $sizeName) {
                        $thumb = (string) \wp_get_attachment_image_url($attachmentId, $sizeName);
                        if ($thumb !== '') {
                            break;
                        }
                    }
                }

                $full = (string) \wp_get_attachment_image_url($attachmentId, 'full');
                if ($full === '') {
                    $full = $thumb;
                }

                if ($thumb === '') {
                    continue;
                }

                $wpImages[] = [
                    'attachment_id' => $attachmentId,
                    'url'           => $thumb,
                    'url_full'      => $full,
                ];
            }

            if ($wpImages !== []) {
                $images = $wpImages;
            }
        }

        if ($images === null) {
            $images = $boat['images'] ?? $boat['service_images'] ?? $boat['gallery'] ?? null;
        }

        if (!is_array($images) || empty($images)) {
            $main = isset($boat['main_image']) ? trim((string) $boat['main_image']) : '';
            if ($main !== '') {
                $images = [['url' => $main]];
            } else {
                return '';
            }
        }

        $available = count($images);

        $layout = in_array((string) $atts['layout'], ['grid', 'slider'], true)
            ? (string) $atts['layout']
            : 'grid';

        $cols = (int) $atts['columns'];
        if (!in_array($cols, [2, 3, 4], true)) {
            $cols = 3;
        }

        $enableLightbox = ((string) $atts['enable_lightbox'] === '1');

        $startIndex = (int) $atts['start_index'];
        $endIndex   = (int) $atts['end_index'];
        $maxImages  = (int) $atts['max_images'];

        if ($startIndex < 1) {
            $startIndex = 1;
        }
        if ($endIndex < 0) {
            $endIndex = 0;
        }
        if ($maxImages < 1) {
            $maxImages = 1;
        }

        if ($endIndex === 0) {
            $endIndex = $available;
        }

        if ($endIndex < $startIndex) {
            $tmp = $startIndex;
            $startIndex = $endIndex;
            $endIndex = $tmp;
        }

        if ($startIndex > $available) {
            return '';
        }

        if ($endIndex > $available) {
            $endIndex = $available;
        }

        $offset = $startIndex - 1;
        $length = ($endIndex - $startIndex) + 1;

        $images = array_slice($images, $offset, $length);
        if (count($images) > $maxImages) {
            $images = array_slice($images, 0, $maxImages);
        }

        $gap       = (int) $atts['gap'];
        $radius    = (int) $atts['radius'];

        $alt = trim((string) ($boat['service_name'] ?? $boat['name'] ?? $boat['boat_alias'] ?? 'Boat image'));
        if ($alt === '') {
            $alt = 'Boat image';
        }

        $identifier = self::resolveIdentifier([
            'id'   => (string) $atts['id'],
            'slug' => (string) $atts['slug'],
        ]) ?? wp_rand(1000, 999999);

        $slideshow = 'maradigma-boat-' . preg_replace('/[^a-zA-Z0-9_-]+/', '-', (string) $identifier);

        $pickFull = static function (array $img): string {
            if (!empty($img['url_full']) && is_string($img['url_full'])) {
                return trim((string) $img['url_full']);
            }

            if (!empty($img['url_sizes']) && is_array($img['url_sizes'])) {
                foreach (['original', 'full', 'xxl', 'xl', 'large', 'medium'] as $key) {
                    if (!empty($img['url_sizes'][$key]) && is_string($img['url_sizes'][$key])) {
                        return trim((string) $img['url_sizes'][$key]);
                    }
                }
            }

            if (!empty($img['sizes']) && is_array($img['sizes'])) {
                foreach (['original', 'full', 'xxl', 'xl', 'large', 'medium'] as $key) {
                    if (!empty($img['sizes'][$key]) && is_string($img['sizes'][$key])) {
                        return trim((string) $img['sizes'][$key]);
                    }
                }
            }

            return trim((string) ($img['url'] ?? ''));
        };

        $pickThumb = static function (array $img, string $preferKey): string {
            if ($preferKey !== '') {
                if (!empty($img['url_sizes']) && is_array($img['url_sizes']) && !empty($img['url_sizes'][$preferKey])) {
                    return trim((string) $img['url_sizes'][$preferKey]);
                }

                if (!empty($img['sizes']) && is_array($img['sizes']) && !empty($img['sizes'][$preferKey])) {
                    return trim((string) $img['sizes'][$preferKey]);
                }
            }

            return trim((string) ($img['url'] ?? ''));
        };

        if ($layout === 'slider') {
            AssetsManager::enqueueSwiperAssets();
        }

        $style = sprintf(
            '--md-gallery-gap:%dpx;--md-gallery-radius:%dpx;--md-gallery-cols:%d;',
            $gap,
            $radius,
            $cols
        );

        if ($layout === 'grid') {
            ob_start();

            echo '<div class="maradigma-boat-gallery maradigma-boat-gallery--grid" style="' . esc_attr($style) . '">';

            foreach ($images as $img) {
                if (!is_array($img)) {
                    continue;
                }

                $thumb = $pickThumb($img, $preferKey);
                if ($thumb === '') {
                    continue;
                }

                $full = $pickFull($img);
                if ($full === '') {
                    $full = $thumb;
                }

                echo '<figure class="maradigma-boat-gallery__item">';

                if ($enableLightbox && $full !== '') {
                    echo '<a href="' . esc_url($full) . '" data-elementor-open-lightbox="yes" data-elementor-lightbox-slideshow="' . esc_attr($slideshow) . '">';
                }

                echo '<img class="maradigma-boat-gallery__img" src="' . esc_url($thumb) . '" alt="' . esc_attr($alt) . '" loading="lazy" />';

                if ($enableLightbox && $full !== '') {
                    echo '</a>';
                }

                echo '</figure>';
            }

            echo '</div>';

            return (string) ob_get_clean();
        }

        $autoplay   = ((string) $atts['slider_autoplay'] === '1');
        $delay      = (int) $atts['slider_delay'];
        $loop       = ((string) $atts['slider_loop'] === '1');
        $speed      = (int) $atts['slider_speed'];
        $space      = (int) $atts['slider_space_between'];
        $perDesktop = (int) $atts['slider_per_view_desktop'];
        $perTablet  = (int) $atts['slider_per_view_tablet'];
        $perMobile  = (int) $atts['slider_per_view_mobile'];

        if ($delay < 500) {
            $delay = 500;
        }
        if ($speed < 100) {
            $speed = 100;
        }
        if ($perDesktop < 1) {
            $perDesktop = 1;
        }
        if ($perTablet < 1) {
            $perTablet = 1;
        }
        if ($perMobile < 1) {
            $perMobile = 1;
        }

        $showNav  = ((string) $atts['slider_navigation'] === '1');
        $showDots = ((string) $atts['slider_pagination'] === '1');

        $settings = [
            'autoplay'        => $autoplay,
            'delay'           => $delay,
            'loop'            => $loop,
            'speed'           => $speed,
            'spaceBetween'    => $space,
            'perViewDesktop'  => $perDesktop,
            'perViewTablet'   => $perTablet,
            'perViewMobile'   => $perMobile,
            'navigation'      => $showNav,
            'pagination'      => $showDots,
            'radius'          => $radius,
            'enableLightbox'  => $enableLightbox,
            'slideshow'       => $slideshow,
        ];

        $json = wp_json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        ob_start();

        echo '<div class="maradigma-boat-gallery maradigma-boat-gallery--slider" style="' . esc_attr($style) . '" data-maradigma-gallery="' . esc_attr((string) $json) . '">';
        echo '  <div class="swiper maradigma-swiper">';
        echo '    <div class="swiper-wrapper">';

        foreach ($images as $img) {
            if (!is_array($img)) {
                continue;
            }

            $thumb = $pickThumb($img, $preferKey);
            if ($thumb === '') {
                continue;
            }

            $full = $pickFull($img);
            if ($full === '') {
                $full = $thumb;
            }

            echo '<div class="swiper-slide">';

            if ($enableLightbox && $full !== '') {
                echo '<a href="' . esc_url($full) . '" data-elementor-open-lightbox="yes" data-elementor-lightbox-slideshow="' . esc_attr($slideshow) . '">';
            }

            echo '<img class="maradigma-boat-gallery__img" src="' . esc_url($thumb) . '" alt="' . esc_attr($alt) . '" loading="lazy" />';

            if ($enableLightbox && $full !== '') {
                echo '</a>';
            }

            echo '</div>';
        }

        echo '    </div>';

        if ($showDots) {
            echo '<div class="swiper-pagination"></div>';
        }

        if ($showNav) {
            echo '<div class="swiper-button-prev"></div>';
            echo '<div class="swiper-button-next"></div>';
        }

        echo '  </div>';
        echo '</div>';

        return (string) ob_get_clean();
    }

    /**
     * Render boat videos shortcode.
     *
     * Examples:
     * [maradigma_boat_videos id="732"]
     * [maradigma_boat_videos slug="sunseeker-predator-108-dominator" layout="grid" columns="2"]
     * [maradigma_boat_videos slug="..." mode="embed"]
     *
     * @param array<string,mixed> $atts
     * @return string
     */
    public static function renderBoatVideos(array $atts = []): string
    {
        if (!function_exists('shortcode_atts')) {
            return '';
        }

        $atts = shortcode_atts(
            [
                'id'          => '',
                'slug'        => '',
                'layout'      => 'grid',   // grid | list
                'mode'        => 'embed',  // embed | thumbnail
                'columns'     => '2',
                'max_videos'  => '12',
                'show_title'  => '0',
                'class'       => '',
            ],
            $atts,
            'maradigma_boat_videos'
        );

        $identifier = self::resolveIdentifier($atts);
        if ($identifier === null) {
            return '<p>' . esc_html__('Missing boat id/slug.', 'maradigma') . '</p>';
        }

        $language = self::getLanguage();

        $cache  = new Cache();
        $result = $cache->getServiceVideos('boats', (string) $identifier, $language);

        $videos = is_array($result['data'] ?? null) ? $result['data'] : [];
        if ($videos === []) {
            return '';
        }

        $layout = in_array((string) $atts['layout'], ['grid', 'list'], true) ? (string) $atts['layout'] : 'grid';
        $mode   = in_array((string) $atts['mode'], ['embed', 'thumbnail'], true) ? (string) $atts['mode'] : 'embed';

        $columns = (int) $atts['columns'];
        if (!in_array($columns, [1, 2, 3, 4], true)) {
            $columns = 2;
        }

        $maxVideos = (int) $atts['max_videos'];
        if ($maxVideos < 1) {
            $maxVideos = 12;
        }

        $videos = array_slice($videos, 0, $maxVideos);

        $wrapperClass = trim('maradigma-boat-videos maradigma-boat-videos--' . $layout . ' ' . (string) $atts['class']);

        ob_start();
    ?>
        <div class="<?php echo esc_attr($wrapperClass); ?>" style="--md-videos-cols:<?php echo esc_attr((string) $columns); ?>;">
            <?php foreach ($videos as $video): ?>
                <?php
                if (!is_array($video)) {
                    continue;
                }

                $provider     = (string) ($video['provider'] ?? '');
                $embedUrl     = trim((string) ($video['embed_url'] ?? ''));
                $thumbUrl     = trim((string) ($video['thumbnail_url'] ?? ''));
                $videoUrl     = trim((string) ($video['url'] ?? ''));
                $videoId      = trim((string) ($video['video_id'] ?? ''));
                $translations = is_array($video['translations'] ?? null) ? $video['translations'] : [];
                $title        = '';

                if (isset($translations[$language]) && is_string($translations[$language])) {
                    $title = trim($translations[$language]);
                }

                if ($title === '' && isset($translations['EN']) && is_string($translations['EN'])) {
                    $title = trim($translations['EN']);
                }

                if ($title === '') {
                    $title = ucfirst($provider) . ' video';
                }

                if ($mode === 'embed' && $embedUrl !== ''):
                ?>
                    <div class="maradigma-boat-videos__item">
                        <div class="maradigma-boat-videos__embed">
                            <iframe
                                src="<?php echo esc_url($embedUrl); ?>"
                                title="<?php echo esc_attr($title); ?>"
                                loading="lazy"
                                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                                allowfullscreen>
                            </iframe>
                        </div>

                        <?php if ((string) $atts['show_title'] === '1' && $title !== ''): ?>
                            <div class="maradigma-boat-videos__title"><?php echo esc_html($title); ?></div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="maradigma-boat-videos__item">
                        <a
                            href="<?php echo esc_url($videoUrl !== '' ? $videoUrl : $embedUrl); ?>"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="maradigma-boat-videos__thumb-link">
                            <?php if ($thumbUrl !== ''): ?>
                                <img
                                    src="<?php echo esc_url($thumbUrl); ?>"
                                    alt="<?php echo esc_attr($title); ?>"
                                    class="maradigma-boat-videos__thumb"
                                    loading="lazy" />
                            <?php else: ?>
                                <div class="maradigma-boat-videos__thumb-placeholder">
                                    <?php echo esc_html($videoId !== '' ? $videoId : $title); ?>
                                </div>
                            <?php endif; ?>
                        </a>

                        <?php if ((string) $atts['show_title'] === '1' && $title !== ''): ?>
                            <div class="maradigma-boat-videos__title"><?php echo esc_html($title); ?></div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    <?php

        return (string) ob_get_clean();
    }

    /**
     * Render boat description shortcode.
     *
     * Examples:
     * [maradigma_boat_description id="2410"]
     * [maradigma_boat_description slug="pardo-yachts-38-test" type="short"]
     * [maradigma_boat_description id="2410" allow_html="1" max_words="80"]
     *
     * Supported attributes:
     * - id (string|int)
     * - slug (string)
     * - type (auto|large|long|short)
     * - esc (true|false)               Legacy compatibility. False allows wp_kses_post HTML.
     * - allow_html (1|0|yes|no)        If enabled, output is sanitized with wp_kses_post.
     * - max_words (int)                0 = unlimited
     * - fallback (string)
     *
     * Notes:
     * - "large" is accepted as alias for "long".
     * - If max_words > 0, content is trimmed from plain text.
     *
     * @param array<string,mixed> $atts
     * @return string
     */
    public static function renderBoatDescription(array $atts = []): string
    {
        if (!function_exists('shortcode_atts')) {
            return '';
        }

        $atts = shortcode_atts(
            [
                'slug'       => '',
                'id'         => '',
                'esc'        => 'true',
                'allow_html' => '',
                'max_words'  => '0',
                'fallback'   => '',
                'type'       => 'auto',
            ],
            $atts,
            'maradigma_boat_description'
        );

        $boat = self::loadBoat(
            [
                'id'   => (string) $atts['id'],
                'slug' => (string) $atts['slug'],
            ],
            ['descriptions'],
            ['expand' => ['service_descriptions']]
        );

        if (!$boat || !is_array($boat)) {
            return esc_html((string) ($atts['fallback'] ?? ''));
        }

        $type = strtolower(trim((string) ($atts['type'] ?? 'auto')));
        if ($type === 'large') {
            $type = 'long';
        }
        if (!in_array($type, ['auto', 'long', 'short'], true)) {
            $type = 'auto';
        }

        $desc = self::resolveBoatDescriptionFromBoat($boat, self::getLanguage(), $type);

        if ($desc === '') {
            return esc_html((string) ($atts['fallback'] ?? ''));
        }

        $maxWords = (int) $atts['max_words'];
        if ($maxWords > 0) {
            $desc = wp_trim_words(wp_strip_all_tags($desc), $maxWords, '…');
            return '<p>' . esc_html($desc) . '</p>';
        }

        $allowHtml = filter_var($atts['allow_html'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($allowHtml === true) {
            return wp_kses_post($desc);
        }

        $esc = strtolower((string) $atts['esc']) !== 'false';

        if ($esc) {
            return esc_html($desc);
        }

        return wp_kses_post($desc);
    }

    /**
     * Extracts a unique translated string list from one or more possible boat payload keys.
     *
     * This helper is used by list-style shortcodes such as:
     * - included items
     * - not included items
     * - equipments
     *
     * It searches the first non-empty array node found in $candidateKeys and converts
     * each item into a displayable label using:
     * - translations[$lang]
     * - first non-empty translation fallback
     * - first non-empty scalar among $labelKeys
     *
     * @param array<string,mixed> $boat
     * @param array<int,string>   $candidateKeys
     * @param array<int,string>   $labelKeys
     * @param string              $language
     * @param bool                $sort
     * @return array<int,string>
     */
    private static function extractTranslatedStringListFromBoatNode(
        array $boat,
        array $candidateKeys,
        array $labelKeys,
        string $language,
        bool $sort = true
    ): array {
        $node = null;

        foreach ($candidateKeys as $key) {
            if (isset($boat[$key]) && is_array($boat[$key]) && $boat[$key] !== []) {
                $node = $boat[$key];
                break;
            }
        }

        if (!is_array($node) || $node === []) {
            return [];
        }

        $lang = strtoupper(trim($language));
        if ($lang === '') {
            $lang = 'EN';
        }

        $out = [];

        foreach ($node as $item) {
            if (is_string($item)) {
                $value = trim($item);
                if ($value !== '') {
                    $out[] = $value;
                }
                continue;
            }

            if (!is_array($item)) {
                continue;
            }

            $label = '';

            if (isset($item['translations']) && is_array($item['translations'])) {
                if (
                    isset($item['translations'][$lang]) &&
                    is_string($item['translations'][$lang]) &&
                    trim($item['translations'][$lang]) !== ''
                ) {
                    $label = trim((string) $item['translations'][$lang]);
                } else {
                    foreach ($item['translations'] as $translationValue) {
                        if (is_string($translationValue) && trim($translationValue) !== '') {
                            $label = trim((string) $translationValue);
                            break;
                        }
                    }
                }
            }

            if ($label === '') {
                foreach ($labelKeys as $labelKey) {
                    if (isset($item[$labelKey]) && is_string($item[$labelKey]) && trim($item[$labelKey]) !== '') {
                        $label = trim((string) $item[$labelKey]);
                        break;
                    }
                }
            }

            if ($label !== '') {
                $out[] = $label;
            }
        }

        $out = array_values(array_unique(array_filter(
            $out,
            static fn($value): bool => is_string($value) && trim($value) !== ''
        )));

        if ($sort && $out !== []) {
            sort($out, SORT_NATURAL | SORT_FLAG_CASE);
        }

        return $out;
    }

    /**
     * Render boat equipments shortcode.
     *
     * Examples:
     * [maradigma_boat_equipments id="2410"]
     * [maradigma_boat_equipments slug="pardo-yachts-38-test" show_title="1" title="Equipments"]
     * [maradigma_boat_equipments id="2410" show_icon="1" icon_text="✓"]
     *
     * Supported attributes:
     * - id (string|int)
     * - slug (string)
     * - title (string)
     * - show_title (1|0|yes|no)
     * - show_icon (1|0|yes|no)
     * - icon_text (string)
     * - sort (1|0|yes|no)              Sort unique items alphabetically. Default: 1
     * - fallback (string)              Output if no equipments are available
     *
     * @param array<string,mixed> $atts
     * @return string
     */
    public static function renderBoatEquipments(array $atts = []): string
    {
        if (!function_exists('shortcode_atts')) {
            return '';
        }

        $atts = shortcode_atts(
            [
                'id'         => '',
                'slug'       => '',
                'title'      => 'Equipments',
                'show_title' => '1',
                'show_icon'  => '0',
                'icon_text'  => '✓',
                'sort'       => '1',
                'fallback'   => '',
            ],
            $atts,
            'maradigma_boat_equipments'
        );

        $boat = self::loadBoat(
            [
                'id'   => (string) $atts['id'],
                'slug' => (string) $atts['slug'],
            ],
            ['equipments'],
            ['expand' => ['service_equipments']]
        );

        if (!$boat || !is_array($boat)) {
            return '';
        }

        $node = $boat['equipments'] ?? null;
        if (!is_array($node) || $node === []) {
            $fallback = trim((string) $atts['fallback']);
            if ($fallback === '') {
                $fallback = (string) __('No equipments available.', 'maradigma');
            }

            return '<div class="maradigma-boat-equipments maradigma-boat-equipments--empty">' . esc_html($fallback) . '</div>';
        }

        $lang = self::getLanguage();
        $lang = strtoupper(trim($lang));

        $items = [];

        foreach ($node as $item) {
            if (is_string($item)) {
                $s = trim($item);
                if ($s !== '') {
                    $items[] = $s;
                }
                continue;
            }

            if (!is_array($item)) {
                continue;
            }

            $label = '';

            if (isset($item['translations']) && is_array($item['translations'])) {
                if (isset($item['translations'][$lang]) && is_string($item['translations'][$lang]) && trim($item['translations'][$lang]) !== '') {
                    $label = trim((string) $item['translations'][$lang]);
                } else {
                    foreach ($item['translations'] as $translationValue) {
                        if (is_string($translationValue) && trim($translationValue) !== '') {
                            $label = trim($translationValue);
                            break;
                        }
                    }
                }
            }

            if ($label === '') {
                foreach (['name', 'title', 'label', 'equipment_name', 'name_not_translate'] as $key) {
                    if (isset($item[$key]) && is_string($item[$key]) && trim($item[$key]) !== '') {
                        $label = trim((string) $item[$key]);
                        break;
                    }
                }
            }

            if ($label !== '') {
                $items[] = $label;
            }
        }

        $items = array_values(array_unique(array_filter($items, static fn($value) => is_string($value) && trim($value) !== '')));

        $sort = filter_var($atts['sort'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $sort = $sort ?? true;

        if ($sort && $items !== []) {
            sort($items, SORT_NATURAL | SORT_FLAG_CASE);
        }

        if ($items === []) {
            $fallback = trim((string) $atts['fallback']);
            if ($fallback === '') {
                $fallback = (string) __('No equipments available.', 'maradigma');
            }

            return '<div class="maradigma-boat-equipments maradigma-boat-equipments--empty">' . esc_html($fallback) . '</div>';
        }

        $showTitle = filter_var($atts['show_title'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $showTitle = $showTitle ?? true;

        $showIcon = filter_var($atts['show_icon'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $showIcon = $showIcon ?? false;

        $title = trim((string) $atts['title']);
        if ($title !== '') {
            $title = \Maradigma\Support\MultilangAdapter::translateEditableDefault(
                $title,
                'Equipments',
                (string) __('Equipments', 'maradigma'),
                'Maradigma Elementor Widgets',
                'boat_equipments_shortcode_title'
            );
        }

        $iconText = trim((string) $atts['icon_text']);
        if ($iconText === '') {
            $iconText = '✓';
        }

        ob_start();

        echo '<div class="maradigma-boat-equipments">';

        if ($showTitle && $title !== '') {
            echo '<h3 class="maradigma-boat-equipments__title">' . esc_html($title) . '</h3>';
        }

        echo '<ul class="maradigma-boat-equipments__list">';
        foreach ($items as $label) {
            echo '<li class="maradigma-boat-equipments__item">';

            if ($showIcon) {
                echo '<span class="maradigma-boat-equipments__icon" aria-hidden="true">' . esc_html($iconText) . '</span>';
            }

            echo '<span class="maradigma-boat-equipments__label">' . esc_html($label) . '</span>';
            echo '</li>';
        }
        echo '</ul>';

        echo '</div>';

        return (string) ob_get_clean();
    }

    /**
     * Render boat included items shortcode.
     *
     * Examples:
     * [maradigma_boat_included id="2410"]
     * [maradigma_boat_included slug="pardo-yachts-38-test" title="Included"]
     * [maradigma_boat_included id="2410" show_tick_icon="1" tick_text="✓"]
     *
     * Supported attributes:
     * - id (string|int)
     * - slug (string)
     * - title (string)
     * - show_title (1|0|yes|no)
     * - show_tick_icon (1|0|yes|no)
     * - tick_text (string)
     * - sort (1|0|yes|no)
     * - fallback (string)
     *
     * @param array<string,mixed> $atts
     * @return string
     */
    public static function renderBoatIncluded(array $atts = []): string
    {
        if (!function_exists('shortcode_atts')) {
            return '';
        }

        $atts = shortcode_atts(
            [
                'id'             => '',
                'slug'           => '',
                'title'          => 'Included',
                'show_title'     => '1',
                'show_tick_icon' => '1',
                'tick_text'      => '✓',
                'sort'           => '1',
                'fallback'       => '',
            ],
            $atts,
            'maradigma_boat_included'
        );

        $boat = self::loadBoat(
            [
                'id'   => (string) $atts['id'],
                'slug' => (string) $atts['slug'],
            ],
            [],
            ['expand' => ['service_included_items']]
        );

        if (!$boat || !is_array($boat)) {
            return '';
        }

        $sort = filter_var($atts['sort'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $sort = $sort ?? true;

        $items = self::extractTranslatedStringListFromBoatNode(
            $boat,
            ['included', 'included_items'],
            ['name', 'title', 'label', 'item_name', 'service_name'],
            self::getLanguage(),
            $sort
        );

        if ($items === []) {
            $fallback = trim((string) $atts['fallback']);
            if ($fallback === '') {
                $fallback = (string) __('No included items.', 'maradigma');
            }

            return '<div class="maradigma-boat-included maradigma-boat-included--empty">' . esc_html($fallback) . '</div>';
        }

        $showTitle = filter_var($atts['show_title'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $showTitle = $showTitle ?? true;

        $showTick = filter_var($atts['show_tick_icon'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $showTick = $showTick ?? true;

        $title = trim((string) $atts['title']);
        if ($title !== '') {
            $title = \Maradigma\Support\MultilangAdapter::translateEditableDefault(
                $title,
                'Included',
                (string) __('Included', 'maradigma'),
                'Maradigma Elementor Widgets',
                'boat_included_shortcode_title'
            );
        }

        $tickText = trim((string) $atts['tick_text']);
        if ($tickText === '') {
            $tickText = '✓';
        }

        ob_start();

        echo '<div class="maradigma-boat-included">';

        if ($showTitle && $title !== '') {
            echo '<h3 class="maradigma-boat-included__title">' . esc_html($title) . '</h3>';
        }

        echo '<ul class="maradigma-boat-included__list">';
        foreach ($items as $label) {
            echo '<li class="maradigma-boat-included__item">';

            if ($showTick) {
                echo '<span class="maradigma-boat-included__tick" aria-hidden="true">' . esc_html($tickText) . '</span>';
            }

            echo '<span class="maradigma-boat-included__label">' . esc_html($label) . '</span>';
            echo '</li>';
        }
        echo '</ul>';

        echo '</div>';

        return (string) ob_get_clean();
    }

    /**
     * Render boat not included items shortcode.
     *
     * Examples:
     * [maradigma_boat_not_included id="2410"]
     * [maradigma_boat_not_included slug="pardo-yachts-38-test" title="Not included"]
     * [maradigma_boat_not_included id="2410" show_cross_icon="1" cross_text="✕"]
     *
     * Supported attributes:
     * - id (string|int)
     * - slug (string)
     * - title (string)
     * - show_title (1|0|yes|no)
     * - show_cross_icon (1|0|yes|no)
     * - cross_text (string)
     * - sort (1|0|yes|no)
     * - fallback (string)
     *
     * @param array<string,mixed> $atts
     * @return string
     */
    public static function renderBoatNotIncluded(array $atts = []): string
    {
        if (!function_exists('shortcode_atts')) {
            return '';
        }

        $atts = shortcode_atts(
            [
                'id'              => '',
                'slug'            => '',
                'title'           => 'Not included',
                'show_title'      => '1',
                'show_cross_icon' => '1',
                'cross_text'      => '✕',
                'sort'            => '1',
                'fallback'        => '',
            ],
            $atts,
            'maradigma_boat_not_included'
        );

        $boat = self::loadBoat(
            [
                'id'   => (string) $atts['id'],
                'slug' => (string) $atts['slug'],
            ],
            [],
            ['expand' => ['service_not_included_items']]
        );

        if (!$boat || !is_array($boat)) {
            return '';
        }

        $sort = filter_var($atts['sort'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $sort = $sort ?? true;

        $items = self::extractTranslatedStringListFromBoatNode(
            $boat,
            ['not_included', 'not_included_items'],
            ['name', 'title', 'label', 'item_name', 'service_name'],
            self::getLanguage(),
            $sort
        );

        if ($items === []) {
            $fallback = trim((string) $atts['fallback']);
            if ($fallback === '') {
                $fallback = (string) __('No "not included" items.', 'maradigma');
            }

            return '<div class="maradigma-boat-not-included maradigma-boat-not-included--empty">' . esc_html($fallback) . '</div>';
        }

        $showTitle = filter_var($atts['show_title'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $showTitle = $showTitle ?? true;

        $showCross = filter_var($atts['show_cross_icon'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $showCross = $showCross ?? true;

        $title = trim((string) $atts['title']);
        if ($title !== '') {
            $title = \Maradigma\Support\MultilangAdapter::translateEditableDefault(
                $title,
                'Not included',
                (string) __('Not included', 'maradigma'),
                'Maradigma Elementor Widgets',
                'boat_not_included_shortcode_title'
            );
        }

        $crossText = trim((string) $atts['cross_text']);
        if ($crossText === '') {
            $crossText = '✕';
        }

        ob_start();

        echo '<div class="maradigma-boat-not-included">';

        if ($showTitle && $title !== '') {
            echo '<h3 class="maradigma-boat-not-included__title">' . esc_html($title) . '</h3>';
        }

        echo '<ul class="maradigma-boat-not-included__list">';
        foreach ($items as $label) {
            echo '<li class="maradigma-boat-not-included__item">';

            if ($showCross) {
                echo '<span class="maradigma-boat-not-included__cross" aria-hidden="true">' . esc_html($crossText) . '</span>';
            }

            echo '<span class="maradigma-boat-not-included__label">' . esc_html($label) . '</span>';
            echo '</li>';
        }
        echo '</ul>';

        echo '</div>';

        return (string) ob_get_clean();
    }

    /**
     * Render boat PDF download shortcode.
     *
     * Examples:
     * [maradigma_boat_pdf_download id="2410"]
     * [maradigma_boat_pdf_download slug="pardo-yachts-38-test" button_text="Download PDF"]
     * [maradigma_boat_pdf_download id="2410" open_in_new_tab="1" force_download="0"]
     *
     * Supported attributes:
     * - id (string|int)
     * - slug (string)
     * - button_text (string)
     * - open_in_new_tab (1|0|yes|no)
     * - force_download (1|0|yes|no)
     * - fallback (string)
     *
     * Notes:
     * - This shortcode requests the real API expand `service_pdf`.
     * - The expected payload key is `url_pdf`.
     *
     * @param array<string,mixed> $atts
     * @return string
     */
    public static function renderBoatPdfDownload(array $atts = []): string
    {
        if (!function_exists('shortcode_atts')) {
            return '';
        }

        $atts = shortcode_atts(
            [
                'id'              => '',
                'slug'            => '',
                'button_text'     => 'Download PDF',
                'open_in_new_tab' => '1',
                'force_download'  => '0',
                'fallback'        => '',
            ],
            $atts,
            'maradigma_boat_pdf_download'
        );

        $boat = self::loadBoat(
            [
                'id'   => (string) $atts['id'],
                'slug' => (string) $atts['slug'],
            ],
            [],
            ['expand' => ['service_pdf']]
        );

        if (!$boat || !is_array($boat)) {
            return '';
        }

        $pdfUrl = '';
        if (isset($boat['url_pdf']) && is_string($boat['url_pdf'])) {
            $pdfUrl = trim((string) $boat['url_pdf']);
        }

        if ($pdfUrl === '') {
            $fallback = trim((string) $atts['fallback']);
            if ($fallback === '') {
                return '';
            }

            return '<div class="maradigma-boat-pdf-download maradigma-boat-pdf-download--empty">' . esc_html($fallback) . '</div>';
        }

        $buttonText = trim((string) $atts['button_text']);
        if ($buttonText === '') {
            $buttonText = (string) __('Download PDF', 'maradigma');
        }

        $buttonText = \Maradigma\Support\MultilangAdapter::translateEditableDefault(
            $buttonText,
            'Download PDF',
            (string) __('Download PDF', 'maradigma'),
            'Maradigma Elementor Widgets',
            'boat_pdf_download_shortcode_button_text'
        );

        $openInNewTab = filter_var($atts['open_in_new_tab'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $openInNewTab = $openInNewTab ?? true;

        $forceDownload = filter_var($atts['force_download'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $forceDownload = $forceDownload ?? false;

        $attrs = [];
        $attrs[] = 'class="maradigma-boat-pdf-download__btn"';
        $attrs[] = 'href="' . esc_url($pdfUrl) . '"';

        if ($openInNewTab) {
            $attrs[] = 'target="_blank"';
            $attrs[] = 'rel="noopener noreferrer"';
        }

        if ($forceDownload) {
            $attrs[] = 'download';
        }

        ob_start();

        echo '<div class="maradigma-boat-pdf-download">';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attributes are assembled locally from escaped URL and fixed literals.
        echo '<a ' . implode(' ', $attrs) . '>';
        echo esc_html($buttonText);
        echo '</a>';
        echo '</div>';

        return (string) ob_get_clean();
    }

    /**
     * Renders a boat availability calendar as a frontend shortcode.
     *
     * Shortcode examples:
     *   - [maradigma_boat_calendar id="732"]
     *   - [maradigma_boat_calendar slug="sunseeker-predator-108-dominator" months="12"]
     *   - [maradigma_boat_calendar slug="..." color_available="#d4edda" color_booked="#ffc0bd" color_option="#ffe8a1"]
     *
     * This shortcode outputs a lightweight calendar component (month selector + grid) and delegates
     * data loading to the plugin REST endpoint:
     *   GET /wp-json/maradigma/v1/calendar?boat={id|slug}&months=...&start_month=...&include_booking_status=...
     *
     * The REST proxy exists to avoid exposing External API credentials (X-API-KEY / X-SIGNATURE) to the browser.
     *
     * Availability states:
     * - Available: default state for dates not present in unavailability ranges.
     * - Booked:   any "booking", "unavailability" or "ical" range (unless "Option" applies).
     * - Option:   booking ranges whose status matches one of the `option_statuses` codes.
     *
     * Localization:
     * - Month names and weekday labels are rendered client-side via Intl.DateTimeFormat using the current WP locale.
     * - Legend labels are provided by WP translations via __().
     *
     * Color customization:
     * - Colors are applied via CSS custom properties scoped to the root element:
     *   --mdcal-available, --mdcal-booked, --mdcal-option, --mdcal-muted
     *
     * Supported attributes:
     * - id (string)                    Boat/service numeric identifier.
     * - slug (string)                  Boat/service slug identifier.
     * - months (string|int)            Number of months in selector. Clamped to [1..24]. Default 12.
     * - start_month (string)           "current" (default) or "next".
     * - show_legend (string|int|bool)  1/0 whether to render legend. Default 1.
     * - color_available (string)       CSS color. Default "#d4edda".
     * - color_booked (string)          CSS color. Default "#ffc0bd".
     * - color_option (string)          CSS color. Default "#ffe8a1".
     * - option_statuses (string)       CSV list of booking status codes treated as "Option". Default "4".
     * - include_booking_status (string|int|bool) 1/0. If 0, booking status is not requested (privacy). Default 1.
     *
     * Output:
     * - Enqueues the calendar CSS/JS only when the shortcode is rendered.
     * - Returns the HTML markup for one calendar instance (multiple instances per page supported).
     *
     * @param array<string,mixed> $atts Shortcode attributes.
     * @return string Rendered calendar HTML (or a small error message if missing id/slug).
     */
    public static function renderBoatCalendar(array $atts = []): string
    {
        if (!function_exists('shortcode_atts')) {
            return '';
        }

        $atts = shortcode_atts(
            [
                'id'   => '',
                'slug' => '',

                // UI
                'months'      => '12',
                'start_month' => 'current',
                'show_legend' => '1',

                // Colors (CSS vars)
                'color_available' => '#d4edda',
                'color_booked'    => '#ffc0bd',
                'color_option'    => '#ffe8a1',

                // Option logic
                'option_statuses' => '4',

                // Privacy
                'include_booking_status' => '1',
            ],
            $atts,
            'maradigma_boat_calendar'
        );

        $identifier = self::resolveIdentifier($atts);
        if ($identifier === null) {
            return '<p>' . esc_html__('Missing boat id/slug.', 'maradigma') . '</p>';
        }

        $boat = self::loadBoat($atts);
        if (!is_array($boat) || !BoatBookingAvailability::canRenderCalendar($boat)) {
            return '';
        }

        AssetsManager::enqueueBoatCalendarAssets();

        $locale = function_exists('determine_locale')
            ? (string) determine_locale()
            : (string) get_locale();

        $months = (int) $atts['months'];
        if ($months < 1) {
            $months = 12;
        }
        if ($months > 24) {
            $months = 24;
        }

        $startMonth = ((string) $atts['start_month'] === 'next') ? 'next' : 'current';

        $optionStatuses = array_values(
            array_unique(
                array_filter(
                    array_map('trim', explode(',', (string) $atts['option_statuses'])),
                    static fn($value): bool => $value !== '' && ctype_digit($value)
                )
            )
        );

        $uid = 'mdcal_' . wp_rand(1000, 9999) . '_' . preg_replace('/[^a-zA-Z0-9_-]+/', '_', (string) $identifier);

        $restUrlCalendar = function_exists('rest_url')
            ? (string) rest_url('maradigma/v1/calendar')
            : '/wp-json/maradigma/v1/calendar';

        $payload = [
            'uid'  => $uid,
            'boat' => (string) $identifier,
            'rest' => [
                'url' => esc_url_raw($restUrlCalendar),
            ],
            'locale' => $locale,
            'months' => $months,
            'startMonth' => $startMonth,
            'includeBookingStatus' => ((string) $atts['include_booking_status'] === '1') ? 1 : 0,
            'optionStatuses' => array_map('intval', $optionStatuses),
            'colors' => [
                'available' => (string) $atts['color_available'],
                'booked'    => (string) $atts['color_booked'],
                'option'    => (string) $atts['color_option'],
            ],
            'i18n' => [
                'available' => __('Available', 'maradigma'),
                'booked'    => __('Booked', 'maradigma'),
                'option'    => __('Option', 'maradigma'),
                'prev'      => __('Previous', 'maradigma'),
                'next'      => __('Next', 'maradigma'),
                'loading'   => __('Loading…', 'maradigma'),
                'failed'    => __('Calendar not available.', 'maradigma'),
            ],
        ];

        $showLegend = ((string) $atts['show_legend'] === '1');

        ob_start();
    ?>
        <div
            id="<?php echo esc_attr($uid); ?>"
            class="md-boat-calendar"
            style="
            --mdcal-available: <?php echo esc_attr((string) $atts['color_available']); ?>;
            --mdcal-booked: <?php echo esc_attr((string) $atts['color_booked']); ?>;
            --mdcal-option: <?php echo esc_attr((string) $atts['color_option']); ?>;
        "
            data-mdcal="<?php echo esc_attr((string) wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?>">
            <div class="mdcal__controller">
                <button
                    type="button"
                    class="mdcal__btn"
                    data-mdcal-prev
                    aria-label="<?php echo esc_attr($payload['i18n']['prev']); ?>">
                    <svg class="mdcal__icon" aria-hidden="true" focusable="false" width="16" height="16">
                        <use href="#svg-chevron-left" xlink:href="#svg-chevron-left"></use>
                    </svg>
                </button>

                <select
                    class="mdcal__select"
                    data-mdcal-select
                    aria-label="<?php echo esc_attr__('Select month', 'maradigma'); ?>"></select>

                <button
                    type="button"
                    class="mdcal__btn"
                    data-mdcal-next
                    aria-label="<?php echo esc_attr($payload['i18n']['next']); ?>">
                    <svg class="mdcal__icon" aria-hidden="true" focusable="false" width="16" height="16">
                        <use href="#svg-chevron-right" xlink:href="#svg-chevron-right"></use>
                    </svg>
                </button>
            </div>

            <div class="mdcal__tablewrap" data-mdcal-table>
                <div class="mdcal__loading" data-mdcal-loading><?php echo esc_html($payload['i18n']['loading']); ?></div>
            </div>

            <?php if ($showLegend): ?>
                <div class="mdcal__legend" aria-label="<?php echo esc_attr__('Legend', 'maradigma'); ?>">
                    <span class="mdcal__legend-item"><i class="mdcal__dot mdcal__dot--available"></i> <?php echo esc_html($payload['i18n']['available']); ?></span>
                    <span class="mdcal__legend-item"><i class="mdcal__dot mdcal__dot--booked"></i> <?php echo esc_html($payload['i18n']['booked']); ?></span>
                    <span class="mdcal__legend-item"><i class="mdcal__dot mdcal__dot--option"></i> <?php echo esc_html($payload['i18n']['option']); ?></span>
                </div>
            <?php endif; ?>
        </div>
    <?php

        return (string) ob_get_clean();
    }

    /**
     * Resolves boat description from boat.
     */
    public static function resolveBoatDescriptionFromBoat(array $boat, string $language, string $mode = 'auto'): string
    {
        $mode = strtolower(trim($mode));
        if (!in_array($mode, ['auto', 'long', 'short'], true)) {
            $mode = 'auto';
        }

        // managed?
        $isManaged = false;
        if (array_key_exists('has_managed_service', $boat)) {
            $b = filter_var($boat['has_managed_service'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            $isManaged = ($b === null) ? ((int)$boat['has_managed_service'] === 1) : $b;
        }

        // pick node
        $node = $isManaged ? ($boat['descriptions'] ?? null) : ($boat['descriptions_tenant'] ?? null);
        if (!is_array($node) || $node === []) {
            $alt  = !$isManaged ? ($boat['descriptions'] ?? null) : ($boat['descriptions_tenant'] ?? null);
            $node = is_array($alt) ? $alt : null;
        }
        if (!is_array($node) || $node === []) {
            return '';
        }

        // normalize language to 'EN' / 'ES' / 'DE' ...
        $langKey = strtoupper(trim($language));
        if (str_contains($langKey, '_') || str_contains($langKey, '-')) {
            $parts   = preg_split('/[_-]/', $langKey);
            $langKey = strtoupper((string)($parts[0] ?? 'EN'));
        }
        if ($langKey === '') $langKey = 'EN';

        // helper: choose long/short based on $mode
        $pick = static function (array $row) use ($mode): string {
            $long  = (isset($row['long_description']) && is_string($row['long_description'])) ? trim($row['long_description']) : '';
            $short = (isset($row['short_description']) && is_string($row['short_description'])) ? trim($row['short_description']) : '';

            if ($mode === 'long')  return $long;
            if ($mode === 'short') return $short;

            // auto: long first, then short
            return $long !== '' ? $long : $short;
        };

        $fallback = ''; // first usable (any lang)
        foreach ($node as $row) {
            if (!is_array($row)) continue;

            $candidate = $pick($row);
            if ($fallback === '' && $candidate !== '') {
                $fallback = $candidate;
            }

            $rowLangRaw = isset($row['code_language']) ? (string)$row['code_language'] : '';
            $rowLang    = strtoupper(trim($rowLangRaw));
            if ($rowLang !== '') {
                if (str_contains($rowLang, '_') || str_contains($rowLang, '-')) {
                    $p = preg_split('/[_-]/', $rowLang);
                    $rowLang = strtoupper((string)($p[0] ?? $rowLang));
                }
            }

            if ($rowLang !== '' && $rowLang === $langKey) {
                $txt = $pick($row);

                // Si el modo elegido no existe en este idioma, en auto ya cae a short.
                // Pero en long/short hacemos fallback "inteligente" al otro si está vacío:
                if ($txt === '') {
                    $long  = (isset($row['long_description']) && is_string($row['long_description'])) ? trim($row['long_description']) : '';
                    $short = (isset($row['short_description']) && is_string($row['short_description'])) ? trim($row['short_description']) : '';
                    if ($mode === 'long' && $short !== '') return $short;
                    if ($mode === 'short' && $long !== '') return $long;
                }

                if ($txt !== '') return $txt;
            }
        }

        return $fallback;
    }
    /**
     * Renders search form.
     */
    public static function renderSearchForm(array $atts = []): string
    {
        $atts = shortcode_atts(
            [
                'target_url'       => '',
                'show_term'        => '1',
                'show_dates'       => '1',
                'show_passengers'  => '1',
                'button_text'      => __('Search', 'maradigma'),
                'placeholder'      => __('Search boats', 'maradigma'),
            ],
            $atts,
            'maradigma_search'
        );

        $truthy = static function ($value): bool {
            if (is_bool($value)) {
                return $value;
            }

            return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
        };

        $targetUrl = trim((string) $atts['target_url']);
        $requestUri = isset($_SERVER['REQUEST_URI'])
            ? sanitize_url((string) wp_unslash($_SERVER['REQUEST_URI']))
            : '/';
        $action = $targetUrl !== ''
            ? $targetUrl
            : home_url(add_query_arg([], $requestUri));

        $showTerm       = $truthy($atts['show_term']);
        $showDates      = $truthy($atts['show_dates']);
        $showPassengers = $truthy($atts['show_passengers']);
        $buttonText     = trim((string) $atts['button_text']);
        $placeholder    = trim((string) $atts['placeholder']);

        // Public search values are read-only and intentionally preserved in shareable URLs.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $dateStart = isset($_GET['date_start'])
            ? sanitize_text_field((string) wp_unslash($_GET['date_start']))
            : '';
        $dateEnd = isset($_GET['date_end'])
            ? sanitize_text_field((string) wp_unslash($_GET['date_end']))
            : '';
        $passengers = isset($_GET['passengers']) ? absint(wp_unslash($_GET['passengers'])) : 0;
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        if ($buttonText === '') {
            $buttonText = (string) __('Search', 'maradigma');
        }

        if ($placeholder === '') {
            $placeholder = (string) __('Search boats', 'maradigma');
        }

        ob_start();
        ?>
        <form class="maradigma-search-form" action="<?php echo esc_url($action); ?>" method="get">
            <div class="maradigma-search-form__fields">
                <?php if ($showTerm): ?>
                    <div class="maradigma-search-form__field maradigma-search-form__field--term">
                        <label class="screen-reader-text" for="maradigma-search-term">
                            <?php echo esc_html__('Search boats', 'maradigma'); ?>
                        </label>
                        <input
                            id="maradigma-search-term"
                            type="search"
                            name="s"
                            value="<?php echo esc_attr(get_search_query()); ?>"
                            placeholder="<?php echo esc_attr($placeholder); ?>"
                        />
                    </div>
                <?php endif; ?>

                <?php if ($showDates): ?>
                    <div class="maradigma-search-form__field maradigma-search-form__field--date">
                        <label class="screen-reader-text" for="maradigma-search-date-start">
                            <?php echo esc_html__('Start date', 'maradigma'); ?>
                        </label>
                        <input
                            id="maradigma-search-date-start"
                            type="date"
                            name="date_start"
                            value="<?php echo esc_attr($dateStart); ?>"
                        />
                    </div>
                    <div class="maradigma-search-form__field maradigma-search-form__field--date">
                        <label class="screen-reader-text" for="maradigma-search-date-end">
                            <?php echo esc_html__('End date', 'maradigma'); ?>
                        </label>
                        <input
                            id="maradigma-search-date-end"
                            type="date"
                            name="date_end"
                            value="<?php echo esc_attr($dateEnd); ?>"
                        />
                    </div>
                <?php endif; ?>

                <?php if ($showPassengers): ?>
                    <div class="maradigma-search-form__field maradigma-search-form__field--passengers">
                        <label class="screen-reader-text" for="maradigma-search-passengers">
                            <?php echo esc_html__('Passengers', 'maradigma'); ?>
                        </label>
                        <input
                            id="maradigma-search-passengers"
                            type="number"
                            min="1"
                            name="passengers"
                            value="<?php echo $passengers > 0 ? esc_attr((string) $passengers) : ''; ?>"
                            placeholder="<?php echo esc_attr__('Passengers', 'maradigma'); ?>"
                        />
                    </div>
                <?php endif; ?>

                <button type="submit" class="maradigma-search-form__submit">
                    <?php echo esc_html($buttonText); ?>
                </button>
            </div>
        </form>
        <?php

        return (string) ob_get_clean();
    }
    /**
     * Renders boats listing from config.
     */
    public static function renderBoatsListingFromConfig(array $config = []): string
    {
        // Por ahora, simplemente traduce "config" a los atts que ya tenías,
        // o amplía la lógica para soportar más filtros.
        // Ejemplo básico: si en el futuro añades filtros por tipos, IDs, etc.

        $atts = [];

        if (!empty($config['mode'])) {
            $atts['mode'] = $config['mode'];
        }

        if (!empty($config['types']) && is_array($config['types'])) {
            // Podrías convertirlo a un CSV para el shortcode interno
            $atts['types'] = implode(',', $config['types']);
        }

        if (!empty($config['custom_ids'])) {
            $atts['ids'] = $config['custom_ids'];
        }

        // Llamas a tu listado actual. Más adelante puedes evolucionar este
        // método para usar directamente el ExternalApiClient con filtros
        // más avanzados.
        return self::renderBoatsListing($atts);
    }

    /**
     * Renders the boat booking call-to-action (CTA) markup for the shortcode
     * {@code [maradigma_boat_booking]}.
     *
     * This renderer outputs:
     * - A "Book now" button (label configurable via shortcode attributes).
     * - A booking modal (markup included in the returned HTML).
     * - Inline configuration through data attributes consumed by the frontend booking modal.
     *
     * Supported shortcode usage:
     * - {@code [maradigma_boat_booking id="304"]}
     * - {@code [maradigma_boat_booking slug="alfastreet-marine-28-sanfil"]}
     * - {@code [maradigma_boat_booking id="304" calendar_display="inline"]}
     * - {@code [maradigma_boat_booking id="304" calendar_selection_mode="single"]}
     * - {@code [maradigma_boat_booking id="304" show_schedule_text="0"]}
     * - {@code [maradigma_boat_booking id="304" show_promo_code="0"]}
     * - {@code [maradigma_boat_booking id="304" show_children_included="0"]}
     * - {@code [maradigma_boat_booking id="304" free_additional_label="included"]}
     *
     * Supported calendar configuration:
     * - {@code calendar_display}: popup|inline
     * - {@code calendar_months}: 1|2
     * - {@code calendar_selection_mode}: range|single
     *
     * Additional UI configuration:
     * - {@code show_schedule_text}: 1|0
     * - {@code show_promo_code}: 1|0
     * - {@code show_children_included}: 1|0
     * - {@code free_additional_label}: free|included
     * - {@code buttons_position}: footer|inline
     *
     * Rules:
     * - If {@code calendar_display="inline"}, the number of visible months is forcibly set to 1.
     *
     * @param array<string,mixed> $atts Shortcode attributes.
     *
     * @return string Rendered HTML for the booking CTA (button + modal + config attributes).
     */
    public static function renderBoatBooking(array $atts = []): string {
        if (!function_exists('shortcode_atts')) {
            return '';
        }

        $atts = shortcode_atts(
            [
                'id'                      => '',
                'slug'                    => '',
                'button_text'             => 'Book now',
                'redirect_url_success'    => '',
                'calendar_display'        => 'inline',
                'calendar_months'         => '1',
                'calendar_selection_mode' => 'range',
                'show_schedule_text'      => '1',
                'show_promo_code'         => '1',
                'show_children_included'  => '1',
                'free_additional_label'   => 'free',
                'buttons_position'        => 'inline',
            ],
            $atts,
            'maradigma_boat_booking'
        );

        $identifier = self::resolveIdentifier($atts);
        if ($identifier === null) {
            return '<p>' . esc_html__('Missing boat id/slug.', 'maradigma') . '</p>';
        }

        $boat = self::loadBoat($atts);
        if (!is_array($boat) || !BoatBookingAvailability::canRenderBooking($boat)) {
            return '';
        }

        AssetsManager::enqueueBookingModalAssets();

        $calendarDisplay = strtolower(trim((string) $atts['calendar_display']));
        if (!in_array($calendarDisplay, ['popup', 'inline'], true)) {
            $calendarDisplay = 'inline';
        }

        $calendarMonths = (int) $atts['calendar_months'];
        if (!in_array($calendarMonths, [1, 2], true)) {
            $calendarMonths = 1;
        }

        $calendarSelectionMode = strtolower(trim((string) $atts['calendar_selection_mode']));
        if (!in_array($calendarSelectionMode, ['range', 'single'], true)) {
            $calendarSelectionMode = 'range';
        }

        if ($calendarDisplay === 'inline') {
            $calendarMonths = 1;
        }

        $showScheduleText = filter_var($atts['show_schedule_text'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $showPromoCode = filter_var($atts['show_promo_code'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $showChildrenIncluded = filter_var($atts['show_children_included'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        $showScheduleText = $showScheduleText ?? true;
        $showPromoCode = $showPromoCode ?? true;
        $showChildrenIncluded = $showChildrenIncluded ?? true;

        $freeAdditionalLabel = strtolower(trim((string) $atts['free_additional_label']));
        if (!in_array($freeAdditionalLabel, ['free', 'included'], true)) {
            $freeAdditionalLabel = 'free';
        }

        $buttonsPosition = strtolower(trim((string) $atts['buttons_position']));
        if (!in_array($buttonsPosition, ['footer', 'inline'], true)) {
            $buttonsPosition = 'inline';
        }

        $quoteEndpoint   = esc_url_raw(rest_url('maradigma/v1/quote'));
        $bookingEndpoint = esc_url_raw(rest_url('maradigma/v1/booking'));

        $uid     = 'md_' . wp_rand(1000, 9999) . '_' . (string) $identifier;
        $modalId = $uid . '_modal';

        $btnText = trim((string) $atts['button_text']);
        if ($btnText === '') {
            $btnText = (string) esc_html__('Book now', 'maradigma');
        } else {
            $btnText = \Maradigma\Support\MultilangAdapter::translateEditableDefault(
                $btnText,
                'Book now',
                (string) __('Book now', 'maradigma'),
                'Maradigma Elementor Widgets',
                'boat_book_now_button_text'
            );
        }

        $redirectUrlSuccess = '';
        if (!empty($atts['redirect_url_success'])) {
            $redirectUrlSuccess = esc_url_raw((string) $atts['redirect_url_success']);
        }

        $multilangContext = \Maradigma\Support\MultilangAdapter::getCurrentContext();

        $langSlug = (string) ($multilangContext['lang'] ?? '');
        $locale   = (string) ($multilangContext['locale'] ?? '');

        $isElementorContext   = \Maradigma\Support\RuntimeContext::isElementorEditorContext();
        $isInteractiveBooking = !$isElementorContext;

        ob_start();
        ?>
        <div class="md-booking"
            id="<?php echo esc_attr($uid); ?>"
            data-md-ready="<?php echo $isInteractiveBooking ? '0' : '1'; ?>"
            data-md-preview-mode="<?php echo $isElementorContext ? '1' : '0'; ?>"
            data-boat-id="<?php echo esc_attr((string) $identifier); ?>"
            data-lang="<?php echo esc_attr(strtolower($langSlug)); ?>"
            data-locale="<?php echo esc_attr(str_replace('_', '-', $locale)); ?>"
            data-expand="service_prices,service_additional_services,service_unavailability_dates"
            data-quote-endpoint="<?php echo esc_attr((string) $quoteEndpoint); ?>"
            data-booking-endpoint="<?php echo esc_attr((string) $bookingEndpoint); ?>"
            data-redirect-url-success="<?php echo esc_attr($redirectUrlSuccess); ?>"
            data-calendar-display="<?php echo esc_attr($calendarDisplay); ?>"
            data-calendar-months="<?php echo esc_attr((string) $calendarMonths); ?>"
            data-calendar-selection-mode="<?php echo esc_attr($calendarSelectionMode); ?>"
            data-show-schedule-text="<?php echo $showScheduleText ? '1' : '0'; ?>"
            data-show-promo-code="<?php echo $showPromoCode ? '1' : '0'; ?>"
            data-show-children-included="<?php echo $showChildrenIncluded ? '1' : '0'; ?>"
            data-free-additional-label="<?php echo esc_attr($freeAdditionalLabel); ?>"
            data-buttons-position="<?php echo esc_attr($buttonsPosition); ?>">

            <button type="button"
                class="md-btn md-btn--primary<?php echo $isInteractiveBooking ? ' is-loading' : ''; ?>"
                data-md-open
                data-md-open-ready="<?php echo $isInteractiveBooking ? '0' : '1'; ?>"
                <?php if ($isInteractiveBooking): ?>
                aria-busy="true"
                aria-disabled="true"
                disabled
                <?php endif; ?>
                aria-controls="<?php echo esc_attr($modalId); ?>">
                <?php echo esc_html($btnText); ?>
            </button>

            <div id="<?php echo esc_attr($modalId); ?>"
                class="md-modal"
                data-md-backdrop-close="false"
                data-md-modal
                aria-hidden="true"
                role="dialog"
                aria-modal="true">

                <div class="md-modal__overlay"></div>

                <div class="md-modal__panel" role="document">
                    <button type="button"
                        class="md-modal__close"
                        aria-label="<?php echo esc_attr__('Close', 'maradigma'); ?>"
                        data-md-close>×</button>

                    <div class="md-modal__header">
                        <h3 class="md-modal__title"><?php echo esc_html__('Booking details', 'maradigma'); ?></h3>

                        <div class="md-steps" data-md-steps>
                            <div class="md-step" data-md-step-pill="1">
                                <span class="md-step__badge">1</span>
                                <span class="md-step__label"><?php echo esc_html__('Reservation', 'maradigma'); ?></span>
                            </div>
                            <div class="md-step" data-md-step-pill="2">
                                <span class="md-step__badge">2</span>
                                <span class="md-step__label"><?php echo esc_html__('Identification', 'maradigma'); ?></span>
                            </div>
                            <div class="md-step" data-md-step-pill="3">
                                <span class="md-step__badge">3</span>
                                <span class="md-step__label"><?php echo esc_html__('Payment', 'maradigma'); ?></span>
                            </div>
                            <div class="md-step" data-md-step-pill="4">
                                <span class="md-step__badge">4</span>
                                <span class="md-step__label"><?php echo esc_html__('Finish', 'maradigma'); ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="md-modal__body">
                        <div class="md-alert md-alert--info" data-md-info></div>
                        <div class="md-alert md-alert--error" data-md-error></div>
                        <div class="md-step-title" data-md-step-title></div>
                        <div data-md-step-container></div>

                        <?php if ($buttonsPosition === 'inline'): ?>
                            <?php self::renderBookingModalActions(true); ?>
                        <?php endif; ?>
                    </div>

                    <?php if ($buttonsPosition === 'footer'): ?>
                        <?php self::renderBookingModalActions(false); ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    /**
     * Renders the booking modal navigation controls.
     *
     * Inline controls are placed inside the scrollable modal body. Footer controls
     * remain outside that body and therefore stay visible while the body scrolls.
     *
     * @param bool $inline Whether the controls are rendered inside the modal body.
     */
    private static function renderBookingModalActions(bool $inline): void
    {
        $className = 'md-modal__footer';
        if ($inline) {
            $className .= ' md-modal__footer--inline';
        }
        ?>
        <div class="<?php echo esc_attr($className); ?>" data-md-modal-actions>
            <button type="button" class="md-btn md-btn--ghost md-footer__back md-d-none" data-md-back>
                <?php echo esc_html__('Back', 'maradigma'); ?>
            </button>

            <button type="button" class="md-btn md-btn--primary" data-md-next>
                <?php echo esc_html__('Continue', 'maradigma'); ?>
            </button>
        </div>
        <?php
    }

    /**
     * Renders the booking success block from the payment return URL.
     *
     * This shortcode is intended to be used on a dedicated success page after
     * the online payment flow returns the customer back to the website.
     *
     * Expected query params:
     * - bchShopCart={uuid}
     * - payment=success|error (optional)
     *
     * Data source:
     * - Validates the shop cart through the external API.
     * - Reuses the same payload semantics already used by the frontend booking flow:
     *   - shop_cart
     *   - booking
     *   - payment
     *   - customer
     *   - booking_restore
     *   - cart_summary
     *   - cart_additional_services
     *
     * Shortcode example:
     * [maradigma_booking_success]
     *
     * @param array<string,mixed> $atts Shortcode attributes.
     * @return string
     */
    public static function renderBookingSuccess(array $atts = []): string
    {
        if (!function_exists('shortcode_atts')) {
            return '';
        }

        $atts = shortcode_atts(
            [
                'show_if_not_paid' => '0',
                'empty_message'    => '',
                'error_message'    => '',
            ],
            $atts,
            'maradigma_booking_success'
        );

        $uuidShopCart = '';
        // Payment return parameters identify a read-only booking result.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['bchShopCart'])) {
            $uuidShopCart = trim(sanitize_text_field((string) wp_unslash($_GET['bchShopCart'])));
        }

        $paymentStatus = '';
        if (isset($_GET['payment'])) {
            $paymentStatus = strtolower(trim(sanitize_key((string) wp_unslash($_GET['payment']))));
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        if ($uuidShopCart === '') {
            $emptyMessage = trim((string) $atts['empty_message']);
            if ($emptyMessage === '') {
                $emptyMessage = (string) __('Booking information not found.', 'maradigma');
            }

            return '<div class="md-booking-success md-booking-success--empty"><p>' . esc_html($emptyMessage) . '</p></div>';
        }

        try {
            $settings = self::getSettings();
            $client   = ExternalApiClient::fromSettings($settings);
            $client->setLanguage(self::getLanguage());

            $response = $client->validateShopCart($uuidShopCart);

            $payload = [];
            if (
                isset($response['status']) &&
                $response['status'] === 'success' &&
                isset($response['data']) &&
                is_array($response['data'])
            ) {
                $payload = $response['data'];
            } elseif (
                isset($response['success']) &&
                $response['success'] === true &&
                isset($response['data']) &&
                is_array($response['data'])
            ) {
                $payload = $response['data'];
            } elseif (isset($response['result']) && is_array($response['result'])) {
                $payload = $response['result'];
            } elseif (is_array($response)) {
                $payload = $response;
            }

            $isPaid = false;

            if (
                (isset($payload['paid']) && (bool) $payload['paid'] === true) ||
                (isset($payload['is_paid']) && (bool) $payload['is_paid'] === true) ||
                (
                    isset($payload['payment']) &&
                    is_array($payload['payment']) &&
                    !empty($payload['payment']['paid'])
                ) ||
                (
                    isset($payload['payment']) &&
                    is_array($payload['payment']) &&
                    !empty($payload['payment']['reference'])
                )
            ) {
                $isPaid = true;
            }

            if (!$isPaid && $paymentStatus === 'success') {
                $isPaid = true;
            }

            if (!$isPaid && (string) $atts['show_if_not_paid'] !== '1') {
                $errorMessage = trim((string) $atts['error_message']);
                if ($errorMessage === '') {
                    $errorMessage = (string) __('We could not confirm the payment for this booking.', 'maradigma');
                }

                return '<div class="md-booking-success md-booking-success--error"><p>' . esc_html($errorMessage) . '</p></div>';
            }

            $shopCart = [];
            if (isset($payload['shop_cart']) && is_array($payload['shop_cart'])) {
                $shopCart = $payload['shop_cart'];
            } else {
                $shopCart = $payload;
            }

            $booking = [];
            if (isset($payload['booking']) && is_array($payload['booking'])) {
                $booking = $payload['booking'];
            }

            $payment = [];
            if (isset($payload['payment']) && is_array($payload['payment'])) {
                $payment = $payload['payment'];
            }

            $customer = [];
            if (isset($payload['customer']) && is_array($payload['customer'])) {
                $customer = $payload['customer'];
            }

            $bookingRestore = [];
            if (isset($payload['booking_restore']) && is_array($payload['booking_restore'])) {
                $bookingRestore = $payload['booking_restore'];
            }

            $cartSummary = [];
            if (isset($payload['cart_summary']) && is_array($payload['cart_summary'])) {
                $cartSummary = $payload['cart_summary'];
            } elseif (isset($shopCart['cart_summary']) && is_array($shopCart['cart_summary'])) {
                $cartSummary = $shopCart['cart_summary'];
            }

            $bookingReference = '';
            if (!empty($booking['reference'])) {
                $bookingReference = (string) $booking['reference'];
            } elseif ($uuidShopCart !== '') {
                $bookingReference = $uuidShopCart;
            } else {
                $bookingReference = '—';
            }

            $bookingService = '';
            if (!empty($booking['service'])) {
                $bookingService = (string) $booking['service'];
            } elseif (!empty($shopCart['service'])) {
                $bookingService = (string) $shopCart['service'];
            } elseif (!empty($shopCart['service_name'])) {
                $bookingService = (string) $shopCart['service_name'];
            } else {
                $bookingService = '—';
            }

            $bookingLocation = '';
            if (!empty($booking['location'])) {
                $bookingLocation = (string) $booking['location'];
            } elseif (!empty($shopCart['location'])) {
                $bookingLocation = (string) $shopCart['location'];
            } elseif (!empty($shopCart['boat_base_port_name'])) {
                $bookingLocation = (string) $shopCart['boat_base_port_name'];
            } elseif (!empty($shopCart['base_port_name'])) {
                $bookingLocation = (string) $shopCart['base_port_name'];
            } else {
                $bookingLocation = '—';
            }

            $bookingDate = '';
            if (!empty($booking['date'])) {
                $bookingDate = (string) $booking['date'];
            } else {
                $dateStart = '';
                $dateEnd   = '';

                if (!empty($bookingRestore['date_start'])) {
                    $dateStart = (string) $bookingRestore['date_start'];
                } elseif (!empty($shopCart['date_start'])) {
                    $dateStart = (string) $shopCart['date_start'];
                }

                if (!empty($bookingRestore['date_end'])) {
                    $dateEnd = (string) $bookingRestore['date_end'];
                } elseif (!empty($shopCart['date_end'])) {
                    $dateEnd = (string) $shopCart['date_end'];
                }

                if ($dateStart !== '' && $dateEnd !== '') {
                    try {
                        $locale = get_locale();
                        if (!is_string($locale) || $locale === '') {
                            $locale = 'en_GB';
                        }
                        $locale = str_replace('_', '-', $locale);

                        $dateStartObj = new \DateTimeImmutable($dateStart);
                        $dateEndObj   = new \DateTimeImmutable($dateEnd);

                        $fmt = new \IntlDateFormatter(
                            $locale,
                            \IntlDateFormatter::LONG,
                            \IntlDateFormatter::NONE
                        );

                        $dateStartFormatted = $fmt->format($dateStartObj);
                        $dateEndFormatted   = $fmt->format($dateEndObj);

                        if ($dateStart === $dateEnd) {
                            $bookingDate = is_string($dateStartFormatted) && $dateStartFormatted !== ''
                                ? $dateStartFormatted
                                : $dateStart;
                        } else {
                            $bookingDate = sprintf(
                                /* translators: 1: start date, 2: end date */
                                __('From %1$s to %2$s', 'maradigma'),
                                is_string($dateStartFormatted) && $dateStartFormatted !== '' ? $dateStartFormatted : $dateStart,
                                is_string($dateEndFormatted) && $dateEndFormatted !== '' ? $dateEndFormatted : $dateEnd
                            );
                        }
                    } catch (\Throwable $e) {
                        $bookingDate = ($dateStart === $dateEnd) ? $dateStart : ($dateStart . ' → ' . $dateEnd);
                    }
                } elseif ($dateStart !== '') {
                    $bookingDate = $dateStart;
                } else {
                    $bookingDate = '—';
                }
            }

            $bookingCustomer = '';
            if (!empty($booking['customer'])) {
                $bookingCustomer = (string) $booking['customer'];
            } else {
                $firstName = '';
                $lastName  = '';

                if (!empty($customer['first_name'])) {
                    $firstName = (string) $customer['first_name'];
                } elseif (!empty($shopCart['first_name'])) {
                    $firstName = (string) $shopCart['first_name'];
                }

                if (!empty($customer['last_name'])) {
                    $lastName = (string) $customer['last_name'];
                } elseif (!empty($shopCart['last_name'])) {
                    $lastName = (string) $shopCart['last_name'];
                }

                $bookingCustomer = trim($firstName . ' ' . $lastName);
                if ($bookingCustomer === '') {
                    $bookingCustomer = '—';
                }
            }

            $bookingUrl = '';
            if (!empty($booking['url'])) {
                $bookingUrl = (string) $booking['url'];
            }

            $paymentReference = '';
            if (!empty($payment['reference'])) {
                $paymentReference = (string) $payment['reference'];
            } else {
                $paymentReference = '—';
            }

            $amountPaid = '';
            if (!empty($payment['total_price'])) {
                $amountPaid = (string) $payment['total_price'];
            } elseif (
                isset($cartSummary['prepayment']) &&
                is_array($cartSummary['prepayment']) &&
                isset($cartSummary['prepayment']['due_now']) &&
                is_array($cartSummary['prepayment']['due_now']) &&
                isset($cartSummary['prepayment']['due_now']['formatted']) &&
                is_array($cartSummary['prepayment']['due_now']['formatted']) &&
                !empty($cartSummary['prepayment']['due_now']['formatted']['total'])
            ) {
                $amountPaid = (string) $cartSummary['prepayment']['due_now']['formatted']['total'];
            } elseif (
                isset($cartSummary['amounts']) &&
                is_array($cartSummary['amounts']) &&
                isset($cartSummary['amounts']['grand_total']) &&
                is_array($cartSummary['amounts']['grand_total']) &&
                isset($cartSummary['amounts']['grand_total']['formatted']) &&
                is_array($cartSummary['amounts']['grand_total']['formatted']) &&
                !empty($cartSummary['amounts']['grand_total']['formatted']['total'])
            ) {
                $amountPaid = (string) $cartSummary['amounts']['grand_total']['formatted']['total'];
            } else {
                $amountPaid = '—';
            }

            $email = '';
            if (!empty($customer['email'])) {
                $email = (string) $customer['email'];
            } elseif (!empty($shopCart['email'])) {
                $email = (string) $shopCart['email'];
            } else {
                $email = '—';
            }

            $phone = '';
            if (!empty($customer['phone'])) {
                $phone = (string) $customer['phone'];
            } elseif (!empty($shopCart['phone'])) {
                $phone = (string) $shopCart['phone'];
            } else {
                $phone = '—';
            }

            ob_start();
        ?>
            <div class="md-booking-success md-booking-success--success">
                <div class="md-booking-success__hero">
                    <div class="md-booking-success__icon">✓</div>
                    <div class="md-booking-success__hero-content">
                        <h2 class="md-booking-success__title">
                            <?php echo esc_html__('Reservation confirmed', 'maradigma'); ?>
                        </h2>
                        <p class="md-booking-success__text">
                            <?php echo esc_html__('Your reservation is now confirmed. Below you can review your booking and payment details.', 'maradigma'); ?>
                        </p>
                    </div>
                </div>

                <div class="md-booking-success__card">
                    <div class="md-booking-success__section">
                        <h3 class="md-booking-success__section-title">
                            <?php echo esc_html__('Booking summary', 'maradigma'); ?>
                        </h3>

                        <div class="md-booking-success__row">
                            <div class="md-booking-success__label"><?php echo esc_html__('Booking reference', 'maradigma'); ?></div>
                            <div class="md-booking-success__value"><?php echo esc_html($bookingReference); ?></div>
                        </div>

                        <div class="md-booking-success__row">
                            <div class="md-booking-success__label"><?php echo esc_html__('Service', 'maradigma'); ?></div>
                            <div class="md-booking-success__value"><?php echo esc_html($bookingService); ?></div>
                        </div>

                        <div class="md-booking-success__row">
                            <div class="md-booking-success__label"><?php echo esc_html__('Location', 'maradigma'); ?></div>
                            <div class="md-booking-success__value"><?php echo esc_html($bookingLocation); ?></div>
                        </div>

                        <div class="md-booking-success__row">
                            <div class="md-booking-success__label"><?php echo esc_html__('Date', 'maradigma'); ?></div>
                            <div class="md-booking-success__value"><?php echo esc_html($bookingDate); ?></div>
                        </div>

                        <div class="md-booking-success__row">
                            <div class="md-booking-success__label"><?php echo esc_html__('Customer', 'maradigma'); ?></div>
                            <div class="md-booking-success__value"><?php echo esc_html($bookingCustomer); ?></div>
                        </div>
                    </div>

                    <div class="md-booking-success__section">
                        <h3 class="md-booking-success__section-title">
                            <?php echo esc_html__('Payment summary', 'maradigma'); ?>
                        </h3>

                        <div class="md-booking-success__row">
                            <div class="md-booking-success__label"><?php echo esc_html__('Payment reference', 'maradigma'); ?></div>
                            <div class="md-booking-success__value"><?php echo esc_html($paymentReference); ?></div>
                        </div>

                        <div class="md-booking-success__row">
                            <div class="md-booking-success__label"><?php echo esc_html__('Amount paid', 'maradigma'); ?></div>
                            <div class="md-booking-success__value"><?php echo esc_html($amountPaid); ?></div>
                        </div>
                    </div>

                    <div class="md-booking-success__section">
                        <h3 class="md-booking-success__section-title">
                            <?php echo esc_html__('Contact details', 'maradigma'); ?>
                        </h3>

                        <div class="md-booking-success__row">
                            <div class="md-booking-success__label"><?php echo esc_html__('Email', 'maradigma'); ?></div>
                            <div class="md-booking-success__value"><?php echo esc_html($email); ?></div>
                        </div>

                        <div class="md-booking-success__row">
                            <div class="md-booking-success__label"><?php echo esc_html__('Phone', 'maradigma'); ?></div>
                            <div class="md-booking-success__value"><?php echo esc_html($phone); ?></div>
                        </div>

                        <?php if ($bookingUrl !== ''): ?>
                            <div class="md-booking-success__row">
                                <div class="md-booking-success__label"><?php echo esc_html__('Booking details', 'maradigma'); ?></div>
                                <div class="md-booking-success__value">
                                    <a href="<?php echo esc_url($bookingUrl); ?>" target="_blank" rel="noopener noreferrer">
                                        <?php echo esc_html__('View details', 'maradigma'); ?>
                                    </a>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
<?php

            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            $errorMessage = trim((string) $atts['error_message']);
            if ($errorMessage === '') {
                $errorMessage = (string) __('An error occurred while loading the booking information.', 'maradigma');
            }

            return '<div class="md-booking-success md-booking-success--error"><p>' . esc_html($errorMessage) . '</p></div>';
        }
    }
}

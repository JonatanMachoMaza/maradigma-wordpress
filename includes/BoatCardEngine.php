<?php

declare(strict_types=1);

namespace Maradigma;

use Maradigma\Support\BoatBookingAvailability;
use Maradigma\Support\Utils;
use Maradigma\Support\MultilangAdapter;

/**
 * Boat cards template engine.
 *
 * IMPORTANT:
 * - All image logic is delegated to BoatImagesSyncService (WP Media cache).
 * - We NEVER use API url_sizes for rendering.
 * - We NEVER fallback to remote (app.maradigma.com) URLs (by design).
 *
 * Image tokens (NO backward compatibility):
 * - {{image_main}} -> deterministic "best" cached size
 * - {{image_<sizeName>}} -> exact WP generated size, e.g. {{image_maradigma_card_471x273}}
 */
final class BoatCardEngine
{
    /** @var list<array<string,mixed>> */
    private static array $moneyFormatStack = [];

    /**
     * Static tokens only.
     * Dynamic image tokens are resolved at runtime:
     * - {{image_main}}
     * - {{image_<sizeName>}}
     */
    private const TOKENS = [
        // Core
        'id',
        'name',
        'url',
        'slug',
        'reference',

        // Pricing / offer meta
        'currency',

        // Identity / model
        'service_name',
        'boat_alias',
        'boat_model',
        'boat_builder',
        'boat_plate_number',
        'boat_license',
        'id_group',
        'id_group_content_type',

        // Media (NEW, only)
        'image_main',
        'image_token',

        // Legacy
        'carousel_html',

        // Location
        'port',
        'destination',
        'destination_slug',
        'boat_base_port',
        'boat_base_port_name',
        'maps_latitude',
        'maps_longitude',

        // Capacity / layout
        'pax',
        'boat_capacity',
        'boat_capacity_crew',
        'boat_capacity_pernocta',
        'cabins',
        'boat_cabins',
        'beds',
        'boat_beds',
        'bathrooms',
        'boat_bathrooms',

        // Dimensions
        'length',
        'boat_length',
        'beam',
        'boat_beam',

        // Years
        'year',
        'boat_year_construction',
        'boat_year_refit',

        // Crew / skipper
        'mandatory_skipper',
        'is_bareboat',
        'skipper_label',
        'text_skipper',
        'boat_skipper_option',
        'boat_crew_skippers',
        'boat_crew_aircrew',
        'boat_crew_hostesses',
        'id_default_skipper',

        // Deposits / licence
        'boat_licence_required',
        'boat_security_deposit',
        'boat_deposit_without_captain',
        'boat_deposit_with_captain',
        'has_deposit_without_skipper',
        'has_deposit_with_skipper',

        // Engines / consumption
        'boat_engines',
        'boat_consumption',
        'boat_consumption_euro_hour',
        'boat_fuel_included_option',
        'boat_min_speed',
        'boat_max_speed',

        // Booking/payment meta
        'checkin_schedule',
        'checkout_schedule',
        'ins_book',
        'ownership_is_tenant_member',
        'booking_has_rent_online',
        'prepaid_percent',
        'payment_methods',
        'collab_commission',

        // Pricing
        'price_from',
        'price_from_vat_percent',
        'price_from_vat_price',
        'price_from_total',
        'price_from_service_with_mandatory_additionals_base',
        'price_from_service_with_mandatory_additionals_vat',
        'price_from_service_with_mandatory_additionals_total',

        'base_price',
        'base_price_vat_percent',
        'base_price_vat_price',
        'base_price_total',

        'base_week_price',
        'base_week_price_vat_percent',
        'base_week_price_vat_price',
        'base_week_price_total',

        'base_hour_price',
        'base_hour_price_vat_percent',
        'base_hour_price_vat_price',
        'base_hour_price_total',

        // Flags
        'featured',
        'status',
        'is_owner',
        'booking_can_render',
        'has_managed_service',
        'sort_order',

        // Derived HTML helpers
        'badge_featured_html',
        'badge_instant_booking_html',

        // Debug / i18n
        'wp_locale',
        'current_language',
        'default_language',
        'multilang_provider',
    ];

    /**
     * Renders the component output.
     */
    public function render(string $templateHtml, array $boat, array $context = []): string
    {
        $locale = self::localeForLanguage(
            (string) ($context['current_lang'] ?? ''),
            \function_exists('determine_locale') ? (string) \determine_locale() : (string) \get_locale()
        );

        $switchedLocale = false;
        if (
            $locale !== ''
            && \function_exists('switch_to_locale')
            && \function_exists('restore_previous_locale')
            && $locale !== (\function_exists('determine_locale') ? (string) \determine_locale() : (string) \get_locale())
        ) {
            $switchedLocale = \switch_to_locale($locale);
        }

        try {
            // i18n placeholders (gettext)
            $templateHtml = $this->replaceI18nPlaceholders($templateHtml);

            $tokenValues = $this->buildTokenValues($boat, $context);
            $templateHtml = $this->normalizeLegacySlugHref($templateHtml);
            $templateHtml = $this->replaceConditionalBlocks($templateHtml, $tokenValues, $context);
            $html = $this->replaceTokens($templateHtml, $tokenValues);

            return self::sanitizeHtml($html);
        } finally {
            if ($switchedLocale) {
                \restore_previous_locale();
            }
        }
    }

    /**
     * Normalizes legacy slug href.
     */
    private function normalizeLegacySlugHref(string $template): string
    {
        return (string) preg_replace(
            '/\bhref=(["\'])\{\{slug\}\}\1/i',
            'href=$1{{url}}$1',
            $template
        );
    }

    /**
     * Translatable placeholders:
     * - [[t  text="..."]]  -> translated and escaped HTML text
     * - [[ta text="..."]]  -> translated and escaped attribute text
     *
     * Soporta comillas simples o dobles.
     */
    private function replaceI18nPlaceholders(string $template): string
    {
        $template = html_entity_decode($template, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $template = (string) preg_replace_callback(
            '/\[\[t\s+text=(["\'])(.*?)\1\s*\]\]/s',
            static function (array $m): string {
                $text = (string) $m[2];
                $text = wp_unslash($text);
                return esc_html(self::translateTemplateText($text));
            },
            $template
        );

        $template = (string) preg_replace_callback(
            '/\[\[ta\s+text=(["\'])(.*?)\1\s*\]\]/s',
            static function (array $m): string {
                $text = (string) $m[2];
                $text = wp_unslash($text);
                return esc_attr(self::translateTemplateText($text));
            },
            $template
        );

        return $template;
    }

    /**
     * Translate built-in labels with literal gettext strings.
     *
     * Custom labels remain available to WPML/Polylang through the editable
     * string adapter without passing variables to WordPress gettext APIs.
     */
    private static function translateTemplateText(string $text): string
    {
        switch ($text) {
            case 'Boat details':
                return __('Boat details', 'maradigma');
            case 'pers.':
                return __('pers.', 'maradigma');
            case 'cabins':
                return __('cabins', 'maradigma');
            case 'from':
                return __('from', 'maradigma');
            case 'From':
                return __('From', 'maradigma');
            case 'day':
                return __('day', 'maradigma');
            case 'Charter':
                return __('Charter', 'maradigma');
            case 'Book now':
                return __('Book now', 'maradigma');
            default:
                return MultilangAdapter::translateEditableString(
                    $text,
                    'Maradigma boat cards',
                    'boat_card_template_' . md5($text)
                );
        }
    }

    /**
     * Normalizes language code.
     */
    private static function normalizeLanguageCode(string $language): string
    {
        $language = strtolower(trim($language));
        if ($language === '') {
            return '';
        }

        $parts = preg_split('/[_-]/', $language);
        $language = strtolower(trim((string) ($parts[0] ?? $language)));

        return preg_replace('/[^a-z0-9]/', '', $language) ?: '';
    }

    /**
     * Resolves the WordPress locale for a language code.
     */
    private static function localeForLanguage(string $language, string $fallbackLocale): string
    {
        $language = self::normalizeLanguageCode($language);

        $locales = [
            'en' => 'en_GB',
            'es' => 'es_ES',
            'ca' => 'ca',
            'de' => 'de_DE',
            'fr' => 'fr_FR',
            'it' => 'it_IT',
        ];

        return $locales[$language] ?? $fallbackLocale;
    }

    /**
     * Normalizes a value to its boolean representation.
     *
     * @param mixed $value Value to process.
     */
    private static function truthy($value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        if (\is_int($value) || \is_float($value)) {
            return (int) $value === 1;
        }

        if (\is_string($value)) {
            return \in_array(\strtolower(\trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    /** @return list<string> */
    public static function listTokens(): array
    {
        // Static tokens only. Dynamic image tokens are runtime-based.
        return self::TOKENS;
    }

    /** @return array<string,string> */
    public static function getPlaceholdersLabels(): array
    {
        return [
            '{{id}}'        => __('Boat ID', 'maradigma'),
            '{{name}}'      => __('Boat name', 'maradigma'),
            '{{url}}'       => __('Boat URL', 'maradigma'),
            '{{slug}}'      => __('Slug', 'maradigma'),
            '{{reference}}' => __('Reference', 'maradigma'),

            '{{currency}}'  => __('Currency (ISO)', 'maradigma'),

            '{{service_name}}'          => __('Service name', 'maradigma'),
            '{{boat_alias}}'            => __('Alias', 'maradigma'),
            '{{boat_model}}'            => __('Model', 'maradigma'),
            '{{boat_builder}}'          => __('Builder', 'maradigma'),
            '{{boat_plate_number}}'     => __('Boat plate number', 'maradigma'),
            '{{boat_license}}'          => __('Boat license', 'maradigma'),
            '{{id_group}}'              => __('Group ID', 'maradigma'),
            '{{id_group_content_type}}' => __('Group content type ID', 'maradigma'),

            // Images (NEW)
            '{{image_main}}'       => __('Main image (cached, deterministic)', 'maradigma'),
            '{{image_token}}'      => __('Main image alias', 'maradigma'),
            '{{image_<sizeName>}}' => __('Exact image size token. Example: {{image_maradigma_card_471x273}}', 'maradigma'),

            '{{carousel_html}}'   => __('Carousel HTML (legacy)', 'maradigma'),

            '{{port}}'                => __('Port', 'maradigma'),
            '{{destination}}'         => __('Destination', 'maradigma'),
            '{{destination_slug}}'    => __('Destination slug', 'maradigma'),
            '{{boat_base_port}}'      => __('Base port ID', 'maradigma'),
            '{{boat_base_port_name}}' => __('Base port name', 'maradigma'),
            '{{maps_latitude}}'       => __('Latitude', 'maradigma'),
            '{{maps_longitude}}'      => __('Longitude', 'maradigma'),

            '{{pax}}'                    => __('Capacity (pax)', 'maradigma'),
            '{{boat_capacity}}'          => __('Boat capacity', 'maradigma'),
            '{{boat_capacity_crew}}'     => __('Crew capacity', 'maradigma'),
            '{{boat_capacity_pernocta}}' => __('Overnight capacity', 'maradigma'),
            '{{cabins}}'                 => __('Cabins', 'maradigma'),
            '{{boat_cabins}}'            => __('Cabins (raw)', 'maradigma'),
            '{{beds}}'                   => __('Beds', 'maradigma'),
            '{{boat_beds}}'              => __('Beds (raw)', 'maradigma'),
            '{{bathrooms}}'              => __('Bathrooms', 'maradigma'),
            '{{boat_bathrooms}}'         => __('Bathrooms (raw)', 'maradigma'),

            '{{length}}'      => __('Length (m)', 'maradigma'),
            '{{boat_length}}' => __('Length (raw)', 'maradigma'),
            '{{beam}}'        => __('Beam (m)', 'maradigma'),
            '{{boat_beam}}'   => __('Beam (raw)', 'maradigma'),

            '{{year}}'                   => __('Year (construction)', 'maradigma'),
            '{{boat_year_construction}}' => __('Construction year (raw)', 'maradigma'),
            '{{boat_year_refit}}'        => __('Year refit', 'maradigma'),

            '{{mandatory_skipper}}'   => __('Mandatory skipper (bool)', 'maradigma'),
            '{{is_bareboat}}'         => __('Bareboat (bool)', 'maradigma'),
            '{{skipper_label}}'       => __('Skipper label', 'maradigma'),
            '{{text_skipper}}'        => __('Skipper text (raw)', 'maradigma'),
            '{{boat_skipper_option}}' => __('Skipper option (raw)', 'maradigma'),

            '{{price_from}}'             => __('Price from', 'maradigma'),
            '{{price_from_vat_percent}}' => __('Price from VAT percent', 'maradigma'),
            '{{price_from_vat_price}}'   => __('Price from VAT amount', 'maradigma'),
            '{{price_from_total}}'       => __('Price from total (VAT included)', 'maradigma'),
            '{{price_from_service_with_mandatory_additionals_base}}'  => __('Price from with mandatory additionals base', 'maradigma'),
            '{{price_from_service_with_mandatory_additionals_vat}}'   => __('Price from with mandatory additionals VAT', 'maradigma'),
            '{{price_from_service_with_mandatory_additionals_total}}' => __('Price from with mandatory additionals total', 'maradigma'),

            '{{base_price}}'             => __('Base price', 'maradigma'),
            '{{base_price_vat_percent}}' => __('Base price VAT percent', 'maradigma'),
            '{{base_price_vat_price}}'   => __('Base price VAT amount', 'maradigma'),
            '{{base_price_total}}'       => __('Base price total (VAT included)', 'maradigma'),

            '{{base_week_price}}'             => __('Base week price', 'maradigma'),
            '{{base_week_price_vat_percent}}' => __('Base week price VAT percent', 'maradigma'),
            '{{base_week_price_vat_price}}'   => __('Base week price VAT amount', 'maradigma'),
            '{{base_week_price_total}}'       => __('Base week price total (VAT included)', 'maradigma'),

            '{{base_hour_price}}'             => __('Base hour price', 'maradigma'),
            '{{base_hour_price_vat_percent}}' => __('Base hour price VAT percent', 'maradigma'),
            '{{base_hour_price_vat_price}}'   => __('Base hour price VAT amount', 'maradigma'),
            '{{base_hour_price_total}}'       => __('Base hour price total (VAT included)', 'maradigma'),

            '{{featured}}'            => __('Featured (bool)', 'maradigma'),
            '{{status}}'              => __('Status', 'maradigma'),
            '{{ownership_is_tenant_member}}' => __('Tenant member flag', 'maradigma'),
            '{{booking_has_rent_online}}'    => __('Rent online flag', 'maradigma'),
            '{{booking_can_render}}'         => __('Can render booking flag', 'maradigma'),
            '{{badge_featured_html}}' => __('Featured badge HTML (auto)', 'maradigma'),
            '{{badge_instant_booking_html}}' => __('Instant booking badge HTML (auto)', 'maradigma'),
            '{{wp_locale}}'           => __('WordPress locale', 'maradigma'),
            '{{current_language}}'    => __('Current language', 'maradigma'),
            '{{default_language}}'    => __('Default language', 'maradigma'),
            '{{multilang_provider}}'  => __('Multilang provider', 'maradigma'),
        ];
    }

    /** @return array<string,string> */
    private function buildTokenValues(array $boat, array $context): array
    {
        $id        = $this->resolveBoatId($boat);
        $slug = (string) ($boat['slug'] ?? '');
        if ($slug === '') {
            $slug = (string) ($boat['service_slug'] ?? '');
        }
        if ($slug !== '') {
            $slug = sanitize_title((string) $slug);
        }
        $reference = (string) ($boat['reference'] ?? '');

        $name = (string) ($boat['service_name'] ?? '');
        if ($name === '') {
            $builder = (string) ($boat['boat_builder'] ?? '');
            $model   = (string) ($boat['boat_model'] ?? '');
            $alias   = (string) ($boat['boat_alias'] ?? '');
            $name = trim($builder . ' ' . $model . ' ' . $alias);
        }
        if ($name === '') {
            $name = (string) ($boat['boat_alias'] ?? ($boat['name'] ?? ''));
        }

        $preferUrl = '';
        if (!empty($boat['url']) && is_string($boat['url'])) {
            $preferUrl = trim((string) $boat['url']);
        }

        $url      = $this->buildBoatUrl($slug, $id, $boat, $context, $preferUrl);
        $currency = self::guessCurrencyFromPayload($boat);

        // Images: exact, WP-only
        $forcedImageMain = '';
        if (!empty($boat['__linked_wp_post_thumbnail_url']) && is_string($boat['__linked_wp_post_thumbnail_url'])) {
            $forcedImageMain = trim((string) $boat['__linked_wp_post_thumbnail_url']);
        }

        // Images: linked WP thumbnail, API payload, then local media cache fallback.
        $media = $this->buildMediaTokens($id, $context, $forcedImageMain);
        if (trim((string) ($media['image_main'] ?? '')) === '') {
            $payloadImage = self::extractMainImageFromPayload($boat);
            if ($payloadImage !== '') {
                $media['image_main'] = $payloadImage;
            }
        }
        if (trim((string) ($media['image_main'] ?? '')) === '') {
            $media = $this->buildMediaTokensFromWpCache($id, $context, true);
        }
        if (
            trim((string) ($media['image_main'] ?? '')) === ''
            && empty($context['disable_api_image_fallback'])
        ) {
            $media['image_main'] = $this->buildImageFromServiceImagesCache($id, $context);
        }

        $carouselHtml = '';

        // Friendly fields
        $pax  = (string) ($boat['boat_capacity'] ?? ($boat['pax'] ?? ''));
        $len  = (string) ($boat['boat_length'] ?? ($boat['length'] ?? ''));
        $beam = (string) ($boat['boat_beam'] ?? '');
        $port = (string) ($boat['boat_base_port_name'] ?? ($boat['port'] ?? ''));
        $destination = BoatUrlResolver::getDestinationName($boat);
        $destinationSlug = BoatUrlResolver::getDestinationSlug($boat);

        $cabins    = (string) ($boat['boat_cabins'] ?? '');
        $beds      = (string) ($boat['boat_beds'] ?? '');
        $bathrooms = (string) ($boat['boat_bathrooms'] ?? '');
        $year      = (string) ($boat['boat_year_construction'] ?? '');

        $mandatorySkipper = !empty($boat['mandatory_skipper']) ? '1' : '0';
        $isBareboat       = !empty($boat['is_bareboat']) ? '1' : '0';

        $skipperLabel = trim((string) ($boat['text_skipper'] ?? ''));

        if ($skipperLabel === '') {
            $skipperLabel = ($mandatorySkipper === '1')
                ? __('Mandatory skipper', 'maradigma')
                : __('Optional skipper', 'maradigma');
        } else {
            $skipperLabel = match ($skipperLabel) {
                'Mandatory skipper' => __('Mandatory skipper', 'maradigma'),
                'Optional skipper'  => __('Optional skipper', 'maradigma'),
                default             => $skipperLabel,
            };
        }

        $hasSelectedDateRange = self::hasSelectedDateRange($context) || self::hasBoatFoundDateRangePrice($boat);

        $basePrice = (string) ($boat['base_price_total'] ?? $boat['base_price'] ?? $boat['price_from_total'] ?? $boat['price_from'] ?? '');
        $priceFrom = (string) ($boat['price_from_total'] ?? $boat['price_from'] ?? $boat['base_price_total'] ?? $boat['base_price'] ?? '');
        $weekPrice = (string) ($boat['base_week_price_total'] ?? $boat['base_week_price'] ?? '');
        $hourPrice = (string) ($boat['base_hour_price_total'] ?? $boat['base_hour_price'] ?? '');
        $mandatoryAdditionalsPrice = [];

        if (
            isset($boat['from_price']) &&
            \is_array($boat['from_price']) &&
            isset($boat['from_price']['service_with_mandatory_additionals']) &&
            \is_array($boat['from_price']['service_with_mandatory_additionals'])
        ) {
            $mandatoryAdditionalsPrice = $boat['from_price']['service_with_mandatory_additionals'];
        }

        $mandatoryAdditionalsBase = self::pickPriceDisplayValue($mandatoryAdditionalsPrice, 'base');
        $mandatoryAdditionalsVat = self::pickPriceDisplayValue($mandatoryAdditionalsPrice, 'vat');
        $mandatoryAdditionalsTotal = self::pickPriceDisplayValue($mandatoryAdditionalsPrice, 'total');

        self::maybeLogMandatoryAdditionalsPriceDebug($boat, $mandatoryAdditionalsPrice, [
            'base' => $mandatoryAdditionalsBase,
            'vat' => $mandatoryAdditionalsVat,
            'total' => $mandatoryAdditionalsTotal,
        ]);

        if ($hasSelectedDateRange) {
            $selectedPriceDisplay = self::extractSelectedDateRangePriceDisplay($boat);
            $selectedPriceBase = self::extractSelectedDateRangePriceBase($boat);
            $selectedPriceTotal = self::extractSelectedDateRangePriceTotal($boat);
            $selectedPriceVat = self::extractSelectedDateRangePriceVat($boat);
            $selectedPriceVatPercent = self::extractSelectedDateRangePriceVatPercent($boat);

            if ($selectedPriceDisplay !== '') {
                $priceFrom = $selectedPriceDisplay;
            } elseif ($selectedPriceTotal !== '') {
                $priceFrom = $selectedPriceTotal;
            }

            if ($selectedPriceTotal !== '') {
                $basePrice = $selectedPriceTotal;
            }

            if ($selectedPriceBase !== '') {
                $mandatoryAdditionalsBase = $selectedPriceBase;
            }

            if ($selectedPriceVat !== '') {
                $mandatoryAdditionalsVat = $selectedPriceVat;
            }

            if ($selectedPriceDisplay !== '') {
                $mandatoryAdditionalsTotal = $selectedPriceDisplay;
            } elseif ($selectedPriceTotal !== '') {
                $mandatoryAdditionalsTotal = $selectedPriceTotal;
            }
        }

        $isFeatured = !empty($boat['featured']);
        $badgeFeaturedHtml = $isFeatured
            ? '<span class="maradigma-boat-card__badge maradigma-boat-card__badge--featured">' . esc_html__('Featured', 'maradigma') . '</span>'
            : '';
        $badgeInstantBookingHtml = BoatBookingAvailability::canRenderBooking($boat)
            ? '<span class="maradigma-boat-card__badge maradigma-boat-card__badge--instant-booking">' . esc_html__('Instant booking', 'maradigma') . '</span>'
            : '';

        // Defaults
        $out = [];
        foreach (self::TOKENS as $t) {
            $out[$t] = '';
        }

        // Core
        $out['id']        = $id;
        $out['name']      = $name;
        $out['url']       = $url;
        $out['slug']      = $slug;
        $out['reference'] = $reference;

        // Offer meta
        $out['currency'] = $currency;

        // Identity / model
        $out['service_name']          = (string) ($boat['service_name'] ?? '');
        $out['boat_alias']            = (string) ($boat['boat_alias'] ?? '');
        $out['boat_model']            = (string) ($boat['boat_model'] ?? '');
        $out['boat_builder']          = (string) ($boat['boat_builder'] ?? '');
        $out['boat_plate_number']     = (string) ($boat['boat_plate_number'] ?? '');
        $out['boat_license']          = (string) ($boat['boat_license'] ?? '');
        $out['id_group']              = (string) ($boat['id_group'] ?? '');
        $out['id_group_content_type'] = (string) ($boat['id_group_content_type'] ?? '');

        // Media
        $out['image_main'] = (string) ($media['image_main'] ?? '');
        $out['image_token'] = (string) ($media['image_main'] ?? '');

        // Also inject ALL dynamic image_<sizeName> tokens (exact)
        foreach ($media as $k => $v) {
            if ($k === 'image_main') {
                continue;
            }

            if (str_starts_with($k, 'image_')) {
                $out[$k] = (string) $v;
            }
        }

        $out['carousel_html'] = $carouselHtml;

        // Location
        $out['port']                = $port;
        $out['destination']         = $destination;
        $out['destination_slug']    = $destinationSlug;
        $out['boat_base_port']      = (string) ($boat['boat_base_port'] ?? '');
        $out['boat_base_port_name'] = (string) ($boat['boat_base_port_name'] ?? '');
        $out['maps_latitude']       = (string) ($boat['maps_latitude'] ?? '');
        $out['maps_longitude']      = (string) ($boat['maps_longitude'] ?? '');

        // Capacity / layout
        $out['pax']                    = $pax;
        $out['boat_capacity']          = (string) ($boat['boat_capacity'] ?? '');
        $out['boat_capacity_crew']     = (string) ($boat['boat_capacity_crew'] ?? '');
        $out['boat_capacity_pernocta'] = (string) ($boat['boat_capacity_pernocta'] ?? '');
        $out['cabins']                 = $cabins;
        $out['boat_cabins']            = (string) ($boat['boat_cabins'] ?? '');
        $out['beds']                   = $beds;
        $out['boat_beds']              = (string) ($boat['boat_beds'] ?? '');
        $out['bathrooms']              = $bathrooms;
        $out['boat_bathrooms']         = (string) ($boat['boat_bathrooms'] ?? '');

        // Dimensions
        $out['length']      = $len;
        $out['boat_length'] = (string) ($boat['boat_length'] ?? '');
        $out['beam']        = $beam;
        $out['boat_beam']   = (string) ($boat['boat_beam'] ?? '');

        // Years
        $out['year']                   = $year;
        $out['boat_year_construction'] = (string) ($boat['boat_year_construction'] ?? '');
        $out['boat_year_refit']        = (string) ($boat['boat_year_refit'] ?? '');

        // Crew / skipper
        $out['mandatory_skipper']   = $mandatorySkipper;
        $out['is_bareboat']         = $isBareboat;
        $out['skipper_label']       = $skipperLabel;
        $out['text_skipper']        = $skipperLabel;
        $out['boat_skipper_option'] = (string) ($boat['boat_skipper_option'] ?? '');
        $out['boat_crew_skippers']  = (string) ($boat['boat_crew_skippers'] ?? '');
        $out['boat_crew_aircrew']   = (string) ($boat['boat_crew_aircrew'] ?? '');
        $out['boat_crew_hostesses'] = (string) ($boat['boat_crew_hostesses'] ?? '');
        $out['id_default_skipper']  = (string) ($boat['id_default_skipper'] ?? '');

        // Deposits / licence
        $out['boat_licence_required']        = (string) ($boat['boat_licence_required'] ?? '');
        $out['boat_security_deposit']        = (string) ($boat['boat_security_deposit'] ?? '');
        $out['boat_deposit_without_captain'] = (string) ($boat['boat_deposit_without_captain'] ?? '');
        $out['boat_deposit_with_captain']    = (string) ($boat['boat_deposit_with_captain'] ?? '');
        $out['has_deposit_without_skipper']  = !empty($boat['has_deposit_without_skipper']) ? '1' : '0';
        $out['has_deposit_with_skipper']     = !empty($boat['has_deposit_with_skipper']) ? '1' : '0';

        // Engines / consumption
        $out['boat_engines']               = (string) ($boat['boat_engines'] ?? '');
        $out['boat_consumption']           = (string) ($boat['boat_consumption'] ?? '');
        $out['boat_consumption_euro_hour'] = (string) ($boat['boat_consumption_euro_hour'] ?? '');
        $out['boat_fuel_included_option']  = (string) ($boat['boat_fuel_included_option'] ?? '');
        $out['boat_min_speed']             = (string) ($boat['boat_min_speed'] ?? '');
        $out['boat_max_speed']             = (string) ($boat['boat_max_speed'] ?? '');

        // Booking/payment meta
        $out['checkin_schedule']  = (string) ($boat['checkin_schedule'] ?? '');
        $out['checkout_schedule'] = (string) ($boat['checkout_schedule'] ?? '');
        $out['ins_book']          = (string) ($boat['ins_book'] ?? '');
        $out['ownership_is_tenant_member'] = self::truthy($boat['is_tenant_member'] ?? ($boat['ownership']['is_tenant_member'] ?? null)) ? '1' : '0';
        $out['booking_has_rent_online']    = self::truthy($boat['booking']['has_rent_online'] ?? null) ? '1' : '0';
        $out['prepaid_percent']   = (string) ($boat['prepaid_percent'] ?? '');
        $out['payment_methods']   = (string) ($boat['payment_methods'] ?? '');
        $out['collab_commission'] = (string) ($boat['collab_commission'] ?? '');

        // Pricing
        $out['price_from']             = $priceFrom;
        $out['price_from_vat_percent'] = $hasSelectedDateRange
            ? ($selectedPriceVatPercent !== '' ? $selectedPriceVatPercent : (string) ($boat['price_from_vat_percent'] ?? ''))
            : (string) ($boat['price_from_vat_percent'] ?? '');
        $out['price_from_vat_price']   = $hasSelectedDateRange
            ? ($selectedPriceVat !== '' ? $selectedPriceVat : (string) ($boat['price_from_vat_price'] ?? ''))
            : (string) ($boat['price_from_vat_price'] ?? '');
        $out['price_from_total']       = $hasSelectedDateRange
            ? ($selectedPriceTotal !== '' ? $selectedPriceTotal : (string) ($boat['price_from_total'] ?? ''))
            : (string) ($boat['price_from_total'] ?? '');
        $out['price_from_service_with_mandatory_additionals_base'] = $mandatoryAdditionalsBase;
        $out['price_from_service_with_mandatory_additionals_vat'] = $mandatoryAdditionalsVat;
        $out['price_from_service_with_mandatory_additionals_total'] = $mandatoryAdditionalsTotal;

        $out['base_price']             = $basePrice;
        $out['base_price_vat_percent'] = $hasSelectedDateRange
            ? ($selectedPriceVatPercent !== '' ? $selectedPriceVatPercent : (string) ($boat['base_price_vat_percent'] ?? ''))
            : (string) ($boat['base_price_vat_percent'] ?? '');
        $out['base_price_vat_price']   = $hasSelectedDateRange
            ? ($selectedPriceVat !== '' ? $selectedPriceVat : (string) ($boat['base_price_vat_price'] ?? ''))
            : (string) ($boat['base_price_vat_price'] ?? '');
        $out['base_price_total']       = $hasSelectedDateRange
            ? ($selectedPriceTotal !== '' ? $selectedPriceTotal : (string) ($boat['base_price_total'] ?? ''))
            : (string) ($boat['base_price_total'] ?? '');

        $out['base_week_price']             = $weekPrice;
        $out['base_week_price_vat_percent'] = (string) ($boat['base_week_price_vat_percent'] ?? '');
        $out['base_week_price_vat_price']   = (string) ($boat['base_week_price_vat_price'] ?? '');
        $out['base_week_price_total']       = (string) ($boat['base_week_price_total'] ?? '');

        $out['base_hour_price']             = $hourPrice;
        $out['base_hour_price_vat_percent'] = (string) ($boat['base_hour_price_vat_percent'] ?? '');
        $out['base_hour_price_vat_price']   = (string) ($boat['base_hour_price_vat_price'] ?? '');
        $out['base_hour_price_total']       = (string) ($boat['base_hour_price_total'] ?? '');

        // Flags
        $out['featured']            = $isFeatured ? '1' : '0';
        $out['status']              = (string) ($boat['status'] ?? '');
        $out['is_owner']            = self::truthy($boat['is_owner'] ?? null) ? '1' : '0';
        $out['booking_can_render']  = BoatBookingAvailability::canRenderBooking($boat) ? '1' : '0';
        $out['has_managed_service'] = !empty($boat['has_managed_service']) ? '1' : '0';
        $out['sort_order']          = (string) ($boat['sort_order'] ?? '');

        // Derived HTML helpers
        $out['badge_featured_html'] = $badgeFeaturedHtml;
        $out['badge_instant_booking_html'] = $badgeInstantBookingHtml;

        $wpLocale = function_exists('determine_locale')
            ? (string) determine_locale()
            : (string) get_locale();

        $currentLanguage = strtolower(trim((string) ($context['current_lang'] ?? '')));
        $currentLanguage = (string) (preg_split('/[_-]/', $currentLanguage)[0] ?? $currentLanguage);

        if ($currentLanguage === '') {
            $currentLanguage = MultilangAdapter::getCurrentLanguage();
        }

        if ($currentLanguage === '' && class_exists(\Maradigma\Support\RuntimeContext::class)) {
            $currentLanguage = \Maradigma\Support\RuntimeContext::detectCurrentLanguage();
        }

        $currentLanguage = strtolower(trim((string) $currentLanguage));
        $currentLanguage = (string) (preg_split('/[_-]/', $currentLanguage)[0] ?? $currentLanguage);
        $wpLocale = self::localeForLanguage($currentLanguage, $wpLocale);

        $defaultLanguage   = MultilangAdapter::getDefaultLanguage();
        $multilangProvider = MultilangAdapter::detectProvider();

        $out['wp_locale']          = $wpLocale;
        $out['current_language']   = $currentLanguage;
        $out['default_language']   = $defaultLanguage;
        $out['multilang_provider'] = $multilangProvider;
        $out['has_selected_date_range'] = $hasSelectedDateRange ? '1' : '0';

        // Money formatting
        self::applyMoneyFormatToTokenValues($out, $boat, $context);

        // Escape
        foreach ($out as $k => $v) {
            if ($k === 'carousel_html' || $k === 'badge_featured_html' || $k === 'badge_instant_booking_html') {
                $out[$k] = (string) $v;
                continue;
            }

            if ($k === 'url' || $k === 'image_main' || str_starts_with($k, 'image_')) {
                $out[$k] = esc_url((string) $v);
                continue;
            }

            $out[$k] = esc_html((string) $v);
        }

        return $out;
    }

    /**
     * Resolves boat ID.
     */
    private function resolveBoatId(array $boat): string
    {
        foreach (['id', 'id_gi', 'id_group_item'] as $k) {
            if (!empty($boat[$k])) {
                $v = trim((string) $boat[$k]);
                if ($v !== '') {
                    return $v;
                }
            }
        }

        return '';
    }

    /**
     * Builds media tokens from WordPress cache.
     */
    private function buildMediaTokensFromWpCache(string $boatId, array $context, bool $force = false): array
    {
        $boatId = trim($boatId);
        if ($boatId === '') {
            return ['image_main' => ''];
        }

        if (!$force && !self::shouldPreferWpImages($context)) {
            return ['image_main' => ''];
        }

        $cover = BoatImagesSyncService::getBoatCoverUrlsByBoatId($boatId);
        if ($cover === []) {
            return ['image_main' => ''];
        }

        $out = ['image_main' => BoatImagesSyncService::pickMainUrlFromCoverUrls($cover)];

        // Tokens exactos dinámicos: image_<sizeName>
        foreach ($cover as $sizeName => $url) {
            if (!is_string($sizeName) || !is_string($url) || $url === '') {
                continue;
            }

            $token = 'image_' . $this->sanitizeTokenSuffix($sizeName);
            if ($token === 'image_') {
                continue;
            }

            $out[$token] = $url;
        }

        return $out;
    }

    /**
     * Builds media tokens.
     */
    private function buildMediaTokens(string $boatId, array $context, string $forcedImageMain = ''): array
    {
        $forcedImageMain = trim($forcedImageMain);
        if ($forcedImageMain !== '') {
            $out = [
                'image_main' => $forcedImageMain,
            ];

            $requestedImageToken = trim((string) ($context['image_token'] ?? ''));
            if ($requestedImageToken !== '' && str_starts_with($requestedImageToken, 'image_')) {
                $out[$requestedImageToken] = $forcedImageMain;
            }

            if (
                \class_exists(\Maradigma\BoatImagesSyncService::class)
                && \method_exists(\Maradigma\BoatImagesSyncService::class, 'getAllowedImageTokens')
            ) {
                foreach ((array) \Maradigma\BoatImagesSyncService::getAllowedImageTokens() as $token) {
                    $token = trim((string) $token);

                    if ($token !== '' && str_starts_with($token, 'image_')) {
                        $out[$token] = $forcedImageMain;
                    }
                }
            }

            return $out;
        }

        $boatId = trim($boatId);
        if ($boatId === '') {
            return ['image_main' => ''];
        }

        if (!self::shouldPreferWpImages($context)) {
            return ['image_main' => ''];
        }

        $cover = BoatImagesSyncService::getBoatCoverUrlsByBoatId($boatId);
        if ($cover === []) {
            return ['image_main' => ''];
        }

        $out = ['image_main' => BoatImagesSyncService::pickMainUrlFromCoverUrls($cover)];

        foreach ($cover as $sizeName => $url) {
            if (!is_string($sizeName) || !is_string($url) || $url === '') {
                continue;
            }

            $token = 'image_' . $this->sanitizeTokenSuffix($sizeName);
            if ($token === 'image_') {
                continue;
            }

            $out[$token] = $url;
        }

        return $out;
    }

    /**
     * Sanitizes token suffix.
     */
    private function sanitizeTokenSuffix(string $raw): string
    {
        $raw = strtolower(trim($raw));
        if ($raw === '') {
            return '';
        }

        $raw = preg_replace('/[^a-z0-9_]+/', '_', $raw) ?: '';
        return trim($raw, '_');
    }

    /**
     * Builds image from service images cache.
     */
    private function buildImageFromServiceImagesCache(string $boatId, array $context): string
    {
        $boatId = trim($boatId);
        if ($boatId === '' || !class_exists(\Maradigma\Cache::class)) {
            return '';
        }

        $lang = strtolower(trim((string) ($context['current_lang'] ?? '')));
        $lang = (string) (preg_split('/[_-]/', $lang)[0] ?? $lang);
        if ($lang === '') {
            $lang = class_exists(\Maradigma\Support\RuntimeContext::class)
                ? (string) \Maradigma\Support\RuntimeContext::detectCurrentLanguage()
                : 'en';
        }

        $language = strtoupper(trim($lang));
        if ($language === '') {
            $language = 'EN';
        }

        try {
            $cache = new \Maradigma\Cache();
            $result = $cache->getServiceImages('boats', $boatId, $language);
        } catch (\Throwable $e) {
            return '';
        }

        $data = $result['data'] ?? null;
        if (!is_array($data) || $data === []) {
            return '';
        }

        $url = self::extractMainImageFromPayload(['images' => $data]);
        if ($url !== '') {
            return $url;
        }

        return self::extractMainImageFromPayload($data);
    }

    /** @param array<string,mixed> $boat */
    private static function extractMainImageFromPayload(array $boat): string
    {
        foreach (['image_main', 'image_url', 'main_image_url', 'cover_url', 'thumbnail_url'] as $key) {
            $url = trim((string) ($boat[$key] ?? ''));
            if ($url !== '') {
                return $url;
            }
        }

        foreach (['images', 'service_images', 'gallery'] as $listKey) {
            $images = $boat[$listKey] ?? null;
            if (!is_array($images) || $images === []) {
                continue;
            }

            $cover = null;
            foreach ($images as $image) {
                if (!is_array($image)) {
                    continue;
                }

                $order = isset($image['number_order']) && is_numeric($image['number_order'])
                    ? (int) $image['number_order']
                    : 0;

                if ($order === 1 || !empty($image['is_cover'])) {
                    $cover = $image;
                    break;
                }
            }

            if ($cover === null) {
                $first = reset($images);
                if (!is_array($first)) {
                    continue;
                }

                $cover = $first;
            }

            $url = self::extractImageUrlFromPayloadRow($cover);
            if ($url !== '') {
                return $url;
            }
        }

        return '';
    }

    /** @param array<string,mixed> $image */
    private static function extractImageUrlFromPayloadRow(array $image): string
    {
        $urlSizes = $image['url_sizes'] ?? $image['sizes'] ?? null;
        if (is_array($urlSizes)) {
            foreach (['full', 'original', 'xxl', 'xl', 'large', 'medium'] as $key) {
                $url = trim((string) ($urlSizes[$key] ?? ''));
                if ($url !== '') {
                    return $url;
                }
            }

            $numeric = [];
            foreach ($urlSizes as $key => $url) {
                if (!is_numeric((string) $key) || !is_string($url) || trim($url) === '') {
                    continue;
                }

                $numeric[(int) $key] = trim($url);
            }

            if ($numeric !== []) {
                ksort($numeric);
                $last = end($numeric);
                return is_string($last) ? $last : '';
            }
        }

        foreach (['url_main_domain', 'url', 'src', 'image_url', 'thumbnail_url'] as $key) {
            $url = trim((string) ($image[$key] ?? ''));
            if ($url !== '') {
                return $url;
            }
        }

        return '';
    }

    /**
     * Supported syntax:
     * - [[if has_selected_date_range]]...[[/if]]
     * - [[if has_selected_date_range]]...[[else]]...[[/if]]
     * - [[unless has_selected_date_range]]...[[/unless]]
     * - [[unless has_selected_date_range]]...[[else]]...[[/unless]]
     *
     * Conditions can use:
     * - Built-in flags, e.g. has_selected_date_range
     * - Token names, e.g. featured, price_from, boat_model
     * - Context keys, e.g. date_start, date_end
     */
    private function replaceConditionalBlocks(string $template, array $values, array $context): string
    {
        $tagPattern = '/\[\[(if|unless)\s+([a-z0-9_]+)\s*\]\]|\[\[(else)\s*\]\]|\[\[\/(if|unless)\s*\]\]/i';

        while (\preg_match($tagPattern, $template, $firstMatch, \PREG_OFFSET_CAPTURE)) {
            $openType  = isset($firstMatch[1][0]) ? \strtolower((string) $firstMatch[1][0]) : '';
            $condition = isset($firstMatch[2][0]) ? \strtolower((string) $firstMatch[2][0]) : '';
            $openTag   = (string) ($firstMatch[0][0] ?? '');
            $openPos   = (int) ($firstMatch[0][1] ?? -1);

            if ($openType === '' || $condition === '' || $openPos < 0) {
                break;
            }

            $searchOffset = $openPos + \strlen($openTag);
            $depth = 1;
            $elsePos = null;
            $elseLen = 0;
            $closePos = null;
            $closeLen = 0;
            $closeType = '';

            while (\preg_match($tagPattern, $template, $nextMatch, \PREG_OFFSET_CAPTURE, $searchOffset)) {
                $fullTag = (string) ($nextMatch[0][0] ?? '');
                $tagPos  = (int) ($nextMatch[0][1] ?? -1);
                $nestedOpenType = isset($nextMatch[1][0]) ? \strtolower((string) $nextMatch[1][0]) : '';
                $isElse = isset($nextMatch[3][0]) ? \strtolower((string) $nextMatch[3][0]) : '';
                $endType = isset($nextMatch[4][0]) ? \strtolower((string) $nextMatch[4][0]) : '';

                if ($tagPos < 0 || $fullTag === '') {
                    break 2;
                }

                if ($nestedOpenType !== '') {
                    $depth++;
                } elseif ($isElse !== '') {
                    if ($depth === 1 && $elsePos === null) {
                        $elsePos = $tagPos;
                        $elseLen = \strlen($fullTag);
                    }
                } elseif ($endType !== '') {
                    $depth--;

                    if ($depth === 0) {
                        $closePos = $tagPos;
                        $closeLen = \strlen($fullTag);
                        $closeType = $endType;
                        break;
                    }
                }

                $searchOffset = $tagPos + \strlen($fullTag);
            }

            if ($closePos === null || $closeType !== $openType) {
                break;
            }

            $contentStart = $openPos + \strlen($openTag);
            $truthyBlockEnd = $elsePos ?? $closePos;
            $truthyBlock = \substr($template, $contentStart, $truthyBlockEnd - $contentStart);

            $falsyBlock = '';
            if ($elsePos !== null) {
                $falsyStart = $elsePos + $elseLen;
                $falsyBlock = \substr($template, $falsyStart, $closePos - $falsyStart);
            }

            $conditionResult = $this->evaluateTemplateCondition($condition, $values, $context);
            if ($openType === 'unless') {
                $conditionResult = !$conditionResult;
            }

            $replacement = $conditionResult ? $truthyBlock : $falsyBlock;
            $replacement = $this->replaceConditionalBlocks($replacement, $values, $context);

            $template = \substr($template, 0, $openPos)
                . $replacement
                . \substr($template, $closePos + $closeLen);
        }

        return $template;
    }

    /**
     * Evaluates a conditional expression used by a boat card template.
     */
    private function evaluateTemplateCondition(string $condition, array $values, array $context): bool
    {
        $condition = \strtolower(\trim($condition));
        if ($condition === '') {
            return false;
        }

        if ($condition === 'has_selected_date_range') {
            return self::hasSelectedDateRange($context) || self::isTruthyTemplateValue($values['has_selected_date_range'] ?? '');
        }

        if (\array_key_exists($condition, $values)) {
            return self::isTruthyTemplateValue($values[$condition]);
        }

        if (\array_key_exists($condition, $context)) {
            return self::isTruthyTemplateValue($context[$condition]);
        }

        return false;
    }

    /** @param mixed $value */
    private static function isTruthyTemplateValue($value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        if (\is_int($value) || \is_float($value)) {
            return (float) $value !== 0.0;
        }

        $value = \strtolower(\trim((string) $value));

        if ($value === '' || $value === '0' || $value === 'false' || $value === 'no' || $value === 'off') {
            return false;
        }

        return true;
    }

    /**
     * Determines whether selected date range.
     */
    private static function hasSelectedDateRange(array $context): bool
    {
        $dateStart = trim((string) ($context['date_start'] ?? ''));
        $dateEnd   = trim((string) ($context['date_end'] ?? ''));

        return $dateStart !== '' && $dateEnd !== '';
    }

    /** @param array<string,mixed> $format */
    public static function pushMoneyFormat(array $format): void
    {
        self::$moneyFormatStack[] = $format;
    }

    /**
     * Restores the previous monetary formatting context.
     */
    public static function popMoneyFormat(): void
    {
        \array_pop(self::$moneyFormatStack);
    }

    /** @param array<string,mixed> $price */
    private static function pickPriceDisplayValue(array $price, string $key): string
    {
        $formatted = null;

        if (isset($price['pformat']) && \is_array($price['pformat'])) {
            $formatted = $price['pformat'][$key] ?? null;
        }

        if (\is_scalar($formatted)) {
            $value = \trim((string) $formatted);
            if ($value !== '') {
                return $value;
            }
        }

        $raw = $price[$key] ?? null;

        return \is_scalar($raw) ? \trim((string) $raw) : '';
    }

    /**
     * @param array<string,mixed> $boat
     * @param array<string,mixed> $mandatoryAdditionalsPrice
     * @param array<string,string> $resolved
     */
    private static function maybeLogMandatoryAdditionalsPriceDebug(array $boat, array $mandatoryAdditionalsPrice, array $resolved): void
    {
        if (!self::isPriceDebugEnabled() || !\class_exists(\Maradigma\Support\Debugger::class)) {
            return;
        }

        $fromPrice = isset($boat['from_price']) && \is_array($boat['from_price'])
            ? $boat['from_price']
            : [];

        $pformat = isset($mandatoryAdditionalsPrice['pformat']) && \is_array($mandatoryAdditionalsPrice['pformat'])
            ? $mandatoryAdditionalsPrice['pformat']
            : [];

        \Maradigma\Support\Debugger::log('external-api', 'boat_card_mandatory_additionals_price', [
            'boat_id' => (string) ($boat['id'] ?? $boat['id_gi'] ?? $boat['id_group_item'] ?? ''),
            'service_name' => (string) ($boat['service_name'] ?? $boat['name'] ?? ''),
            'has_from_price' => $fromPrice !== [],
            'from_price_keys' => \array_keys($fromPrice),
            'has_service_with_mandatory_additionals' => $mandatoryAdditionalsPrice !== [],
            'service_with_mandatory_additionals_keys' => \array_keys($mandatoryAdditionalsPrice),
            'pformat_keys' => \array_keys($pformat),
            'raw' => [
                'base' => $mandatoryAdditionalsPrice['base'] ?? null,
                'vat' => $mandatoryAdditionalsPrice['vat'] ?? null,
                'total' => $mandatoryAdditionalsPrice['total'] ?? null,
            ],
            'pformat' => [
                'base' => $pformat['base'] ?? null,
                'vat' => $pformat['vat'] ?? null,
                'total' => $pformat['total'] ?? null,
            ],
            'resolved_tokens' => $resolved,
        ]);
    }

    /**
     * Determines whether server-side price diagnostics are enabled.
     */
    private static function isPriceDebugEnabled(): bool
    {
        if (!\defined('MARADIGMA_PLUGIN_DEBUG')) {
            return false;
        }

        return (bool) \constant('MARADIGMA_PLUGIN_DEBUG');
    }

    /** @param array<string,mixed> $boat */
    private static function hasBoatFoundDateRangePrice(array $boat): bool
    {
        if (!isset($boat['summary']) || !\is_array($boat['summary'])) {
            return false;
        }

        return !empty($boat['summary']['found_price_daterange']);
    }

    /** @param array<string,mixed> $boat */
    private static function extractSelectedDateRangePriceDisplay(array $boat): string
    {
        $summary = self::extractSelectedDateRangeSummary($boat);
        if ($summary === []) {
            return '';
        }

        $candidates = [
            $summary['pformat']['summary_total'] ?? null,
            $summary['summary_total'] ?? null,
            $summary['pformat']['summary_total_gi'] ?? null,
            $summary['summary_total_gi'] ?? null,
            $summary['pformat']['total'] ?? null,
            $summary['total'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /** @param array<string,mixed> $boat */
    private static function extractSelectedDateRangePriceBase(array $boat): string
    {
        $summary = self::extractSelectedDateRangeSummary($boat);
        if ($summary === []) {
            return '';
        }

        foreach (['summary_price', 'summary_subtotal', 'subtotal', 'base', 'summary_price_gi', 'summary_subtotal_gi'] as $key) {
            $value = trim((string) ($summary[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /** @param array<string,mixed> $boat */
    private static function extractSelectedDateRangePriceTotal(array $boat): string
    {
        $summary = self::extractSelectedDateRangeSummary($boat);
        if ($summary === []) {
            return '';
        }

        foreach (['summary_total', 'summary_total_gi', 'total'] as $key) {
            $value = trim((string) ($summary[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /** @param array<string,mixed> $boat */
    private static function extractSelectedDateRangePriceVat(array $boat): string
    {
        $summary = self::extractSelectedDateRangeSummary($boat);
        if ($summary === []) {
            return '';
        }

        foreach (['summary_vat', 'vat', 'summary_vat_gi'] as $key) {
            $value = trim((string) ($summary[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /** @param array<string,mixed> $boat */
    private static function extractSelectedDateRangePriceVatPercent(array $boat): string
    {
        $summary = self::extractSelectedDateRangeSummary($boat);
        if ($summary === []) {
            return '';
        }

        foreach (['summary_vat_percent_gi', 'summary_vat_percent', 'vat_percent'] as $key) {
            $value = trim((string) ($summary[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<string,mixed> $boat
     * @return array<string,mixed>
     */
    private static function extractSelectedDateRangeSummary(array $boat): array
    {
        if (!isset($boat['summary']) || !\is_array($boat['summary'])) {
            return [];
        }

        $summary = $boat['summary'];
        $found = !empty($summary['found_price_daterange']);

        return $found ? $summary : [];
    }

    /**
     * Builds boat URL.
     */
    private function buildBoatUrl(string $slug, string $id, array $boat, array $context, string $preferUrl = ''): string
    {
        $preferUrl = trim((string) $preferUrl);
        if ($preferUrl !== '') {
            $safe = esc_url($preferUrl);
            return ($safe !== '') ? $safe : $preferUrl;
        }

        $configuredBase = (string) ($context['boats_base_slug'] ?? 'boats');
        if (BoatUrlResolver::hasDynamicPlaceholder($configuredBase)) {
            $language = (string) ($context['current_lang'] ?? '');
            if ($language === '') {
                $language = Support\RuntimeContext::detectCurrentLanguage();
            }

            $resolvedUrl = BoatUrlResolver::buildBoatUrl(
                $boat,
                $language,
                $slug !== '' ? $slug : $id
            );

            if ($resolvedUrl !== '') {
                return $resolvedUrl;
            }
        }

        $baseUrl = (string) ($context['boats_base_url'] ?? '');
        if ($baseUrl !== '') {
            $base = $baseUrl;
        } else {
            $baseSlug = (string) ($context['boats_base_slug'] ?? 'boats');
            $base = home_url('/' . trim($baseSlug, '/') . '/');
        }

        $slug = trim((string) $slug);
        if ($slug !== '') {
            $slugSan = sanitize_title($slug);
            if ($slugSan !== '') {
                return rtrim($base, '/') . '/' . $slugSan . '/';
            }
        }

        $id = trim((string) $id);
        if ($id !== '') {
            return rtrim($base, '/') . '/' . rawurlencode($id) . '/';
        }

        return rtrim($base, '/') . '/';
    }

    /**
     * Replace static tokens + dynamic image_* tokens.
     *
     * @param array<string,string> $values
     */
    private function replaceTokens(string $template, array $values): string
    {
        // 1) Replace declared tokens
        foreach (self::TOKENS as $token) {
            $needle = '{{' . $token . '}}';
            $template = str_replace($needle, $values[$token] ?? '', $template);
        }

        // 2) Replace dynamic image_* tokens EXACTLY if present in $values
        // Example: {{image_maradigma_card_471x273}}
        $template = (string) preg_replace_callback(
            '/\{\{(image_[a-z0-9_]+)\}\}/i',
            static function (array $m) use ($values): string {
                $k = (string) $m[1];
                return $values[$k] ?? '';
            },
            $template
        );

        // 3) Strip any leftover unknown tokens
        return preg_replace('/\{\{[a-z0-9\-_]+\}\}/i', '', $template) ?: $template;
    }

    /** @return array<string, array<string, bool|array<int,string>>> */
    public static function getAllowedHtml(): array
    {
        $allowedHtml = [
            'article' => [
                'class' => true,
                'id' => true,
                'style' => true,
                'title' => true,
                'role' => true,
                'data-*' => true,
                'aria-*' => true,
                'itemscope' => true,
                'itemtype' => true,
                'itemprop' => true,
            ],
            'section' => [
                'class' => true,
                'id' => true,
                'style' => true,
                'title' => true,
                'role' => true,
                'data-*' => true,
                'aria-*' => true,
                'itemscope' => true,
                'itemtype' => true,
                'itemprop' => true,
            ],
            'header' => [
                'class' => true,
                'id' => true,
                'style' => true,
                'title' => true,
                'role' => true,
                'data-*' => true,
                'aria-*' => true,
            ],
            'footer' => [
                'class' => true,
                'id' => true,
                'style' => true,
                'title' => true,
                'role' => true,
                'data-*' => true,
                'aria-*' => true,
            ],
            'main' => [
                'class' => true,
                'id' => true,
                'style' => true,
                'title' => true,
                'role' => true,
                'data-*' => true,
                'aria-*' => true,
            ],
            'nav' => [
                'class' => true,
                'id' => true,
                'style' => true,
                'title' => true,
                'role' => true,
                'data-*' => true,
                'aria-*' => true,
            ],
            'aside' => [
                'class' => true,
                'id' => true,
                'style' => true,
                'title' => true,
                'role' => true,
                'data-*' => true,
                'aria-*' => true,
            ],
            'div' => [
                'class' => true,
                'id' => true,
                'style' => true,
                'title' => true,
                'role' => true,
                'data-*' => true,
                'aria-*' => true,
                'itemscope' => true,
                'itemtype' => true,
                'itemprop' => true,
            ],
            'span' => [
                'class' => true,
                'id' => true,
                'style' => true,
                'title' => true,
                'role' => true,
                'data-*' => true,
                'aria-*' => true,
                'itemprop' => true,
            ],
            'a' => [
                'href' => true,
                'class' => true,
                'id' => true,
                'style' => true,
                'target' => true,
                'rel' => true,
                'title' => true,
                'role' => true,
                'data-*' => true,
                'aria-*' => true,
                'itemprop' => true,
            ],
            'p' => [
                'class' => true,
                'id' => true,
                'style' => true,
                'title' => true,
                'role' => true,
                'data-*' => true,
                'aria-*' => true,
                'itemprop' => true,
            ],
            'h1' => [
                'class' => true,
                'id' => true,
                'style' => true,
                'title' => true,
                'role' => true,
                'aria-level' => true,
                'data-*' => true,
                'aria-*' => true,
                'itemprop' => true,
            ],
            'h2' => [
                'class' => true,
                'id' => true,
                'style' => true,
                'title' => true,
                'role' => true,
                'aria-level' => true,
                'data-*' => true,
                'aria-*' => true,
                'itemprop' => true,
            ],
            'h3' => [
                'class' => true,
                'id' => true,
                'style' => true,
                'title' => true,
                'role' => true,
                'aria-level' => true,
                'data-*' => true,
                'aria-*' => true,
                'itemprop' => true,
            ],
            'h4' => [
                'class' => true,
                'id' => true,
                'style' => true,
                'title' => true,
                'role' => true,
                'aria-level' => true,
                'data-*' => true,
                'aria-*' => true,
                'itemprop' => true,
            ],
            'h5' => [
                'class' => true,
                'id' => true,
                'style' => true,
                'title' => true,
                'role' => true,
                'aria-level' => true,
                'data-*' => true,
                'aria-*' => true,
                'itemprop' => true,
            ],
            'h6' => [
                'class' => true,
                'id' => true,
                'style' => true,
                'title' => true,
                'role' => true,
                'aria-level' => true,
                'data-*' => true,
                'aria-*' => true,
                'itemprop' => true,
            ],
            'img' => [
                'src' => true,
                'srcset' => true,
                'sizes' => true,
                'alt' => true,
                'class' => true,
                'id' => true,
                'style' => true,
                'loading' => true,
                'width' => true,
                'height' => true,
                'decoding' => true,
                'title' => true,
                'itemprop' => true,
            ],
            'picture' => [
                'class' => true,
                'id' => true,
                'style' => true,
                'title' => true,
                'role' => true,
                'data-*' => true,
                'aria-*' => true,
            ],
            'source' => [
                'srcset' => true,
                'src' => true,
                'sizes' => true,
                'media' => true,
                'type' => true,
            ],
            'iframe' => [
                'src' => true,
                'title' => true,
                'class' => true,
                'id' => true,
                'style' => true,
                'width' => true,
                'height' => true,
                'loading' => true,
                'allow' => true,
                'allowfullscreen' => true,
                'referrerpolicy' => true,
                'frameborder' => true,
                'data-*' => true,
                'aria-*' => true,
            ],
            'video' => [
                'src' => true,
                'poster' => true,
                'preload' => true,
                'class' => true,
                'id' => true,
                'style' => true,
                'width' => true,
                'height' => true,
                'controls' => true,
                'autoplay' => true,
                'loop' => true,
                'muted' => true,
                'playsinline' => true,
                'data-*' => true,
                'aria-*' => true,
            ],
            'track' => [
                'src' => true,
                'kind' => true,
                'srclang' => true,
                'label' => true,
                'default' => true,
            ],
            'ul' => [
                'class' => true,
                'id' => true,
                'style' => true,
                'title' => true,
                'role' => true,
                'data-*' => true,
                'aria-*' => true,
            ],
            'ol' => [
                'class' => true,
                'id' => true,
                'style' => true,
                'title' => true,
                'role' => true,
                'data-*' => true,
                'aria-*' => true,
            ],
            'li' => [
                'class' => true,
                'id' => true,
                'style' => true,
                'title' => true,
                'role' => true,
                'data-*' => true,
                'aria-*' => true,
            ],
            'dl' => [
                'class' => true,
                'id' => true,
                'style' => true,
                'title' => true,
                'role' => true,
                'data-*' => true,
                'aria-*' => true,
            ],
            'dt' => [
                'class' => true,
                'id' => true,
                'style' => true,
                'title' => true,
                'role' => true,
                'data-*' => true,
                'aria-*' => true,
            ],
            'dd' => [
                'class' => true,
                'id' => true,
                'style' => true,
                'title' => true,
                'role' => true,
                'data-*' => true,
                'aria-*' => true,
            ],
            'figure' => [
                'class' => true,
                'id' => true,
                'style' => true,
                'title' => true,
                'role' => true,
                'data-*' => true,
                'aria-*' => true,
                'itemscope' => true,
                'itemtype' => true,
                'itemprop' => true,
            ],
            'figcaption' => [
                'class' => true,
                'id' => true,
                'style' => true,
                'title' => true,
                'role' => true,
                'data-*' => true,
                'aria-*' => true,
                'itemprop' => true,
            ],
            'meta' => [
                'name' => true,
                'content' => true,
                'itemprop' => true,
                'property' => true,
                'http-equiv' => true,
                'charset' => true,
            ],
            'link' => [
                'rel' => true,
                'href' => true,
                'title' => true,
                'type' => true,
                'media' => true,
                'sizes' => true,
                'itemprop' => true,
            ],
            'time' => [
                'class' => true,
                'id' => true,
                'style' => true,
                'title' => true,
                'role' => true,
                'datetime' => true,
                'data-*' => true,
                'aria-*' => true,
                'itemprop' => true,
            ],
            'form' => [
                'action' => true,
                'method' => true,
                'enctype' => true,
                'accept-charset' => true,
                'autocomplete' => true,
                'class' => true,
                'id' => true,
                'role' => true,
                'data-*' => true,
                'aria-*' => true,
                'novalidate' => true,
            ],
            'label' => [
                'for' => true,
                'class' => true,
                'id' => true,
                'style' => true,
                'title' => true,
                'data-*' => true,
                'aria-*' => true,
            ],
            'input' => [
                'type' => true,
                'name' => true,
                'value' => true,
                'class' => true,
                'id' => true,
                'placeholder' => true,
                'autocomplete' => true,
                'accept' => true,
                'checked' => true,
                'disabled' => true,
                'readonly' => true,
                'required' => true,
                'multiple' => true,
                'min' => true,
                'max' => true,
                'step' => true,
                'pattern' => true,
                'inputmode' => true,
                'tabindex' => true,
                'role' => true,
                'data-*' => true,
                'aria-*' => true,
            ],
            'select' => [
                'name' => true,
                'class' => true,
                'id' => true,
                'size' => true,
                'multiple' => true,
                'disabled' => true,
                'required' => true,
                'tabindex' => true,
                'role' => true,
                'data-*' => true,
                'aria-*' => true,
            ],
            'option' => [
                'value' => true,
                'label' => true,
                'selected' => true,
                'disabled' => true,
                'data-*' => true,
            ],
            'textarea' => [
                'name' => true,
                'class' => true,
                'id' => true,
                'rows' => true,
                'cols' => true,
                'maxlength' => true,
                'placeholder' => true,
                'autocomplete' => true,
                'disabled' => true,
                'readonly' => true,
                'required' => true,
                'data-*' => true,
                'aria-*' => true,
            ],
            'button' => [
                'type' => true,
                'class' => true,
                'id' => true,
                'style' => true,
                'title' => true,
                'role' => true,
                'data-*' => true,
                'aria-*' => true,
                'disabled' => true,
            ],
            'strong' => [
                'class' => true,
                'id' => true,
                'style' => true,
                'title' => true,
            ],
            'em' => [
                'class' => true,
                'id' => true,
                'style' => true,
                'title' => true,
            ],
            'small' => [
                'class' => true,
                'id' => true,
                'style' => true,
                'title' => true,
            ],
            'br' => [],
            'hr' => [
                'class' => true,
                'id' => true,
                'style' => true,
                'title' => true,
            ],

            'svg' => [
                'class' => true,
                'id' => true,
                'width' => true,
                'height' => true,
                'viewbox' => true,
                'xmlns' => true,
                'xmlns:xlink' => true,
                'aria-hidden' => true,
                'role' => true,
                'focusable' => true,
                'fill' => true,
                'stroke' => true,
                'style' => true,
            ],
            'use' => [
                'href' => true,
                'xlink:href' => true,
            ],
            'symbol' => [
                'id' => true,
                'viewbox' => true,
            ],
            'path' => [
                'd' => true,
                'fill' => true,
                'stroke' => true,
                'stroke-width' => true,
                'stroke-linecap' => true,
                'stroke-linejoin' => true,
                'opacity' => true,
                'transform' => true,
            ],
            'circle' => [
                'cx' => true,
                'cy' => true,
                'r' => true,
                'fill' => true,
                'stroke' => true,
                'stroke-width' => true,
                'opacity' => true,
                'transform' => true,
            ],
            'rect' => [
                'x' => true,
                'y' => true,
                'width' => true,
                'height' => true,
                'rx' => true,
                'ry' => true,
                'fill' => true,
                'stroke' => true,
                'stroke-width' => true,
                'opacity' => true,
                'transform' => true,
            ],
            'g' => [
                'transform' => true,
                'fill' => true,
                'stroke' => true,
                'opacity' => true,
            ],
            'title' => [],
            'desc'  => [],
        ];

        return \array_replace_recursive(\wp_kses_allowed_html('post'), $allowedHtml);
    }

    /**
     * Sanitize an editable boat-card template before persistence.
     */
    public static function sanitizeTemplate(string $html): string
    {
        return self::sanitizeHtml($html);
    }

    /**
     * Sanitizes HTML.
     */
    private static function sanitizeHtml(string $html): string
    {
        return wp_kses($html, self::getAllowedHtml());
    }

    /** @param array<string,mixed> $context */
    private static function shouldPreferWpImages(array $context): bool
    {
        if (array_key_exists('prefer_wp_images', $context)) {
            return !empty($context['prefer_wp_images']);
        }

        $settings = SettingsPage::getSettings();
        return !empty($settings['store_boat_images_locally']);
    }

    /** @param array<string,mixed> $context */
    private static function moneyFormatContext(array $context): array
    {
        if (!empty($context['money_format']) && is_array($context['money_format'])) {
            return (array) $context['money_format'];
        }

        if (self::$moneyFormatStack !== []) {
            $last = \end(self::$moneyFormatStack);
            return \is_array($last) ? $last : [];
        }

        return [];
    }

    /**
     * @param array<string,string> $out
     * @param array<string,mixed>  $payload
     * @param array<string,mixed>  $context
     */
    private static function applyMoneyFormatToTokenValues(array &$out, array $payload, array $context): void
    {
        $currency = self::guessCurrencyFromPayload($payload);
        $fmt      = self::moneyFormatContext($context);

        $keys = [
            'price_from',
            'price_from_vat_price',
            'price_from_total',
            'price_from_service_with_mandatory_additionals_base',
            'price_from_service_with_mandatory_additionals_vat',
            'price_from_service_with_mandatory_additionals_total',

            'base_price',
            'base_price_vat_price',
            'base_price_total',

            'base_week_price',
            'base_week_price_vat_price',
            'base_week_price_total',

            'base_hour_price',
            'base_hour_price_vat_price',
            'base_hour_price_total',

            'boat_security_deposit',
            'boat_deposit_without_captain',
            'boat_deposit_with_captain',
        ];

        foreach ($keys as $k) {
            if (!array_key_exists($k, $out)) {
                continue;
            }

            $raw = trim((string) $out[$k]);
            if ($raw === '') {
                continue;
            }

            $n = Utils::toFloatOrNull($raw);
            if ($n === null) {
                continue;
            }

            $out[$k] = Utils::formatMoneyAdvanced((float) $n, $currency, $fmt);
        }
    }

    /** @param array<string,mixed> $payload */
    private static function guessCurrencyFromPayload(array $payload): string
    {
        if (!empty($payload['currency']) && is_string($payload['currency'])) {
            $c = strtoupper(trim((string) $payload['currency']));
            return $c !== '' ? $c : 'EUR';
        }

        foreach (['currency_code', 'currency_iso'] as $k) {
            if (!empty($payload[$k]) && is_string($payload[$k])) {
                $c = strtoupper(trim((string) $payload[$k]));
                if ($c !== '') {
                    return $c;
                }
            }
        }

        return 'EUR';
    }
}

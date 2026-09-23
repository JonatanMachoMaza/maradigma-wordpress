<?php

declare(strict_types=1);

namespace Maradigma\Support;

use Maradigma\ExternalApiClient;

/**
 * Limits the boat details that the public REST proxy returns to browsers.
 *
 * The external API answers the site's signed requests with internal fields
 * (owner record, accounting, commissions, the private imported iCal feed).
 * Server-side rendering may use them; anonymous visitors must only receive
 * customer-facing data.
 */
final class PublicBoatPayload
{
    /**
     * Expand options a visitor may request.
     */
    private const EXPAND = [
        'service_additional_services',
        'service_property_amenities',
        'service_descriptions',
        'service_destination',
        'service_destinations',
        'service_equipments',
        'service_group_category',
        'service_images',
        'service_included_items',
        'service_not_included_items',
        'service_pdf',
        'service_prices',
        'service_price_rates',
        'service_price_time_slots',
        'service_public_urls',
        'service_unavailability_dates',
        'service_real_unavailable_dates',
    ];

    /**
     * Top-level payload keys a visitor may receive.
     */
    private const FIELDS = [
        'id',
        'reference',
        'slug',
        'service_name',
        'id_group',
        'id_group_content_type',
        'is_owner',
        'featured',
        'boat_model',
        'boat_alias',
        'boat_builder',
        'boat_id_builder',
        'boat_year_construction',
        'boat_year_refit',
        'boat_length',
        'boat_beam',
        'boat_capacity',
        'boat_capacity_crew',
        'boat_capacity_pernocta',
        'boat_crew_skippers',
        'boat_crew_aircrew',
        'boat_crew_hostesses',
        'boat_skipper_option',
        'boat_deposit_without_captain',
        'boat_deposit_with_captain',
        'boat_licence_required',
        'boat_security_deposit',
        'boat_cabins',
        'boat_beds',
        'boat_bathrooms',
        'boat_engines',
        'boat_fuel_included_option',
        'boat_consumption',
        'boat_consumption_euro_hour',
        'boat_min_speed',
        'boat_max_speed',
        'boat_base_port',
        'boat_base_port_name',
        'maps_latitude',
        'maps_longitude',
        'checkin_schedule',
        'checkout_schedule',
        'cancellation_type',
        'ins_book',
        'prepaid_percent',
        'main_image',
        'base_price',
        'base_price_cents',
        'base_price_pformat',
        'base_price_vat_percent',
        'base_price_vat_price',
        'base_price_total',
        'from_price',
        'base_week_price',
        'base_week_price_cents',
        'base_price_week_pformat',
        'base_week_price_vat_percent',
        'base_week_price_vat_price',
        'base_week_price_total',
        'base_hour_price',
        'base_hour_price_cents',
        'base_price_hour_pformat',
        'base_hour_price_vat_percent',
        'base_hour_price_vat_price',
        'base_hour_price_total',
        'mandatory_skipper',
        'is_bareboat',
        'has_deposit_without_skipper',
        'has_deposit_with_skipper',
        'text_skipper',
        'has_managed_service',
        'is_tenant_member',
        'url_pdf',
        'url_ical',
        'descriptions_tenant',
        'summary',
        'images',
        'ownership',
        'booking',
        // Keys filled by the public expand options above.
        'additional_services',
        'amenities',
        'descriptions',
        'destination',
        'destinations',
        'equipments',
        'gc_type',
        'arr_gc_type',
        'included',
        'not_included',
        'prices',
        'price_rates',
        'price_time_slots',
        'website_urls',
        'unavailability_dates',
        'unavailability_dates_and_timeslots',
        'real_dates_not_available',
    ];

    /**
     * Returns the requested expand options that visitors may use, sorted so
     * equivalent requests share one cache entry.
     *
     * @return list<string>
     */
    public static function expandOptions(mixed $raw): array
    {
        $expand = \array_values(\array_intersect(
            ExternalApiClient::normalizeBoatDetailsExpandOptions($raw),
            self::EXPAND
        ));
        \sort($expand);

        return $expand;
    }

    /**
     * Keeps only the customer-facing keys of a boat details payload.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function project(array $data): array
    {
        $fields = self::FIELDS;
        if (\function_exists('apply_filters')) {
            $filtered = \apply_filters('maradigma_public_boat_fields', $fields);
            if (\is_array($filtered)) {
                $fields = \array_values(\array_filter($filtered, 'is_string'));
            }
        }

        return \array_intersect_key($data, \array_flip($fields));
    }

    /**
     * Reduces a visitor-supplied language to the two-letter code the API uses.
     */
    public static function language(mixed $raw, string $fallback = 'EN'): string
    {
        $letters = (string) \preg_replace('/[^A-Za-z]/', '', \is_scalar($raw) ? (string) $raw : '');
        $code = \strtoupper(\substr($letters, 0, 2));

        return \strlen($code) === 2 ? $code : $fallback;
    }
}

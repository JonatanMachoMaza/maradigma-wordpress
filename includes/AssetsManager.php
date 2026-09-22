<?php

declare(strict_types=1);

namespace Maradigma;

if (!defined('ABSPATH')) {
    exit;
}

use Maradigma\Support\MultilangAdapter;
use Maradigma\Support\PublicBookingGuard;

/**
 * Manages assets registration and runtime behavior.
 */
final class AssetsManager
{
    public const ASSETS_ADMIN_JS     = 'assets/js/admin';
    public const ASSETS_ADMIN_CSS    = 'assets/css/admin';
    public const ASSETS_EDITOR_JS    = 'assets/js/editor';
    public const ASSETS_FRONTEND_JS  = 'assets/js/frontend';
    public const ASSETS_FRONTEND_CSS = 'assets/css/frontend';
    public const ASSETS_SHARED_JS    = 'assets/js/shared';
    public const ASSETS_SHARED_CSS   = 'assets/css/shared';

    public const ASSETS_DIST_JS      = 'assets/dist/js';
    public const ASSETS_DIST_CSS     = 'assets/dist/css';

    /**
     * Registers the component's WordPress hooks.
     */
    public static function init(): void
    {
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueueFrontend']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueueAdmin']);
        add_action('elementor/editor/after_enqueue_scripts', [__CLASS__, 'enqueueElementorEditorAssets']);
        add_action('elementor/preview/enqueue_styles', [__CLASS__, 'enqueueElementorPreviewStyles']);
        add_action('elementor/preview/enqueue_scripts', [__CLASS__, 'enqueueElementorPreviewScripts']);
    }

    // ─────────────────────────────────────────────
    // FRONTEND BASE
    // ─────────────────────────────────────────────

    /**
     * Enqueues frontend assets.
     */
    public static function enqueueFrontend(): void
    {
        $ver = self::getVersion();

        self::enqueueFrontendCssBundle($ver);
        self::enqueueFrontendCoreScripts($ver);

        self::registerFlatpickrOnce($ver);
        self::registerIntlTelInputOnce();
        self::registerSwiperOnce();
        self::registerBoatCalendarOnce($ver);
        self::registerNoUiSliderOnce($ver);
        self::registerJqueryUiDatepickerStyleOnce($ver);
        self::registerSelect2Once();
    }

    /**
     * @return array{paymentIntroText:string}
     */
    private static function getBookingFrontendConfig(): array
    {
        return [
            'paymentIntroText' => SettingsPage::getBookingPaymentIntroText(),
        ];
    }

    /**
     * Enqueues frontend core scripts assets.
     */
    private static function enqueueFrontendCoreScripts(string $ver): void
    {
        $iconsHandle = 'maradigma-icons';

        wp_enqueue_script(
            $iconsHandle,
            trailingslashit(MARADIGMA_PLUGIN_URL) . self::getJsBasePath() . '/shared/maradigma-icons' . self::getJsSuffix() . '.js',
            [],
            $ver,
            true
        );

        $svgs = require trailingslashit(MARADIGMA_PLUGIN_DIR) . 'includes/Support/FrontendSvgs.php';

        wp_localize_script(
            $iconsHandle,
            'MaradigmaConfig',
            [
                'nonce'           => wp_create_nonce('wp_rest'),
                'bookingNonce'    => PublicBookingGuard::createNonce(),
                'svgs'            => $svgs,
                'intlTelUtilsUrl' => self::getIntlTelInputUtilsUrl(),
                'wpJsonBase'      => esc_url_raw(rest_url()),
                'ajaxUrl'         => esc_url_raw(admin_url('admin-ajax.php')),
                'restUrlQuote'    => esc_url_raw(rest_url('maradigma/v1/quote')),
                'restUrlBooking'  => esc_url_raw(rest_url('maradigma/v1/booking')),
                'restUrlBookingNonce' => esc_url_raw(rest_url('maradigma/v1/booking/security-token')),
                'booking'         => self::getBookingFrontendConfig(),
            ]
        );

        wp_enqueue_script(
            'maradigma-events',
            trailingslashit(MARADIGMA_PLUGIN_URL) . self::getJsBasePath() . '/frontend/maradigma-events' . self::getJsSuffix() . '.js',
            [],
            $ver,
            true
        );

        wp_enqueue_script(
            'maradigma-api-client',
            trailingslashit(MARADIGMA_PLUGIN_URL) . self::getJsBasePath() . '/frontend/maradigma-api-client' . self::getJsSuffix() . '.js',
            ['maradigma-events'],
            $ver,
            true
        );

        wp_enqueue_script(
            'maradigma-boat-ui',
            trailingslashit(MARADIGMA_PLUGIN_URL) . self::getJsBasePath() . '/frontend/maradigma-boat-ui' . self::getJsSuffix() . '.js',
            ['maradigma-events', 'maradigma-api-client'],
            $ver,
            true
        );

        wp_enqueue_script(
            'maradigma-boats',
            trailingslashit(MARADIGMA_PLUGIN_URL) . self::getJsBasePath() . '/frontend/maradigma' . self::getJsSuffix() . '.js',
            [$iconsHandle, 'maradigma-events', 'maradigma-api-client', 'maradigma-boat-ui'],
            $ver,
            true
        );
    }

    /**
     * Kept for compatibility with existing shortcode calls.
     * CSS now comes from the frontend bundle.
     */
    public static function enqueueBoatCardsAssets(): void
    {
        // CSS handled globally by frontend bundle.
    }

    /**
     * Enqueues the booking modal assets.
     */
    public static function enqueueBookingModalAssets(): void
    {
        $ver = self::getVersion();

        self::enqueueFrontendCoreScripts($ver);
        self::enqueueFlatpickr($ver);
        self::enqueueIntlTelInput();

        wp_enqueue_script(
            'maradigma-booking-modal',
            trailingslashit(MARADIGMA_PLUGIN_URL) . self::getJsBasePath() . '/frontend/maradigma-booking-modal' . self::getJsSuffix() . '.js',
            [
                'maradigma-flatpickr',
                'maradigma-iti',
                'maradigma-events',
                'maradigma-api-client',
                'maradigma-boat-ui',
            ],
            $ver,
            true
        );

        self::addBookingModalI18n('maradigma-booking-modal');
    }

    /**
     * Enqueues the boat archive filter assets.
     */
    public static function enqueueArchiveFiltersAssets(): void
    {
        $ver = self::getVersion();

        wp_enqueue_script('jquery');
        self::enqueueFlatpickr($ver);
        self::enqueueNoUiSlider($ver);

        wp_enqueue_script(
            'maradigma-archive-filters',
            trailingslashit(MARADIGMA_PLUGIN_URL) . self::getJsBasePath() . '/frontend/archive-filters' . self::getJsSuffix() . '.js',
            ['jquery', 'maradigma-flatpickr', 'nouislider'],
            $ver,
            true
        );
    }

    /**
     * Enqueues the frontend remote Select2 assets.
     */
    public static function enqueueFrontendRemoteSelect2Assets(): void
    {
        self::enqueueSharedRemoteSelect2('frontend', self::getVersion());
    }

    /**
     * Enqueues the admin remote Select2 assets.
     */
    public static function enqueueAdminRemoteSelect2Assets(): void
    {
        self::enqueueSharedRemoteSelect2('admin', self::getVersion());
    }

    /**
     * Enqueues Swiper assets.
     */
    public static function enqueueSwiperAssets(): void
    {
        self::enqueueSwiper();
    }

    /**
     * Registers boat gallery script.
     */
    public static function registerBoatGalleryScript(): void
    {
        self::registerSwiperOnce();
    }

    /**
     * Enqueues the boat calendar assets.
     */
    public static function enqueueBoatCalendarAssets(): void
    {
        $ver = self::getVersion();

        self::registerBoatCalendarOnce($ver);

        if (!wp_script_is('maradigma-events', 'enqueued')) {
            wp_enqueue_script(
                'maradigma-events',
                trailingslashit(MARADIGMA_PLUGIN_URL) . self::getJsBasePath() . '/frontend/maradigma-events' . self::getJsSuffix() . '.js',
                [],
                $ver,
                true
            );
        }

        wp_enqueue_script('maradigma-boat-calendar');
    }

    /**
     * Registers boat calendar script.
     */
    public static function registerBoatCalendarScript(): void
    {
        self::registerBoatCalendarOnce(self::getVersion());
    }

    // ─────────────────────────────────────────────
    // INTL-TEL-INPUT
    // ─────────────────────────────────────────────

    /**
     * Registers the international telephone input assets once.
     */
    private static function registerIntlTelInputOnce(): void
    {
        static $done = false;

        if ($done) {
            return;
        }

        $done = true;

        $ver  = '26.3.1';
        $base = trailingslashit(MARADIGMA_PLUGIN_URL) . 'vendors/intl-tel-input/';

        wp_register_style(
            'maradigma-iti-css',
            $base . 'css/intlTelInput.min.css',
            [],
            $ver
        );

        wp_register_script(
            'maradigma-iti',
            $base . 'js/intlTelInput.min.js',
            [],
            $ver,
            true
        );
    }

    /**
     * Enqueues the international telephone input assets.
     */
    private static function enqueueIntlTelInput(): void
    {
        self::registerIntlTelInputOnce();
        wp_enqueue_style('maradigma-iti-css');
        wp_enqueue_script('maradigma-iti');
    }

    /**
     * Returns the URL of the international telephone input utilities script.
     */
    private static function getIntlTelInputUtilsUrl(): string
    {
        $base = trailingslashit(MARADIGMA_PLUGIN_URL) . 'vendors/intl-tel-input/';
        return $base . 'js/utils.js';
    }

    // ─────────────────────────────────────────────
    // SELECT2
    // ─────────────────────────────────────────────

    /**
     * Registers select2 once.
     */
    private static function registerSelect2Once(): void
    {
        static $done = false;

        if ($done) {
            return;
        }

        $done = true;

        $select2Js  = trailingslashit(MARADIGMA_PLUGIN_URL) . 'vendors/select2/select2.full.min.js';
        $select2Css = trailingslashit(MARADIGMA_PLUGIN_URL) . 'vendors/select2/select2.min.css';

        wp_register_script('maradigma-select2', $select2Js, ['jquery'], '4.1.0', true);
        wp_register_style('maradigma-select2', $select2Css, [], '4.1.0');
    }

    /**
     * Enqueues select2 assets.
     */
    private static function enqueueSelect2(): void
    {
        self::registerSelect2Once();
        wp_enqueue_script('maradigma-select2');
        wp_enqueue_style('maradigma-select2');
        self::addSelect2FrontendOverrides();
    }

    /**
     * Adds select2 frontend overrides.
     */
    private static function addSelect2FrontendOverrides(): void
    {
        wp_add_inline_style(
            'maradigma-select2',
            '.select2-container--default .select2-search--dropdown{padding:0;background:#fff}.select2-container--default .select2-search--dropdown .select2-search__field{display:block;width:100%;min-height:42px;border:0;border-bottom:1px solid rgba(0,0,0,.12);border-radius:0;padding:10px 12px;outline:none;box-sizing:border-box;background:#fff}.select2-container--default .select2-search--dropdown .select2-search__field:focus{border-color:rgba(0,0,0,.12);box-shadow:none}.select2-dropdown{box-sizing:border-box}'
        );
    }

    /**
     * Registers the jQuery UI datepicker stylesheet once.
     */
    private static function registerJqueryUiDatepickerStyleOnce(string $ver): void
    {
        if (!wp_style_is('jquery-ui-css', 'registered')) {
            wp_register_style(
                'jquery-ui-css',
                trailingslashit(MARADIGMA_PLUGIN_URL) . self::ASSETS_DIST_CSS . '/vendor/jquery-ui-datepicker.min.css',
                [],
                $ver
            );
        }
    }

    /**
     * Enqueues jquery UI datepicker style assets.
     */
    private static function enqueueJqueryUiDatepickerStyle(string $ver): void
    {
        self::registerJqueryUiDatepickerStyleOnce($ver);
        wp_enqueue_style('jquery-ui-css');
    }

    /**
     * Registers the noUiSlider assets once.
     */
    private static function registerNoUiSliderOnce(string $ver): void
    {
        if (!wp_style_is('nouislider', 'registered')) {
            wp_register_style(
                'nouislider',
                trailingslashit(MARADIGMA_PLUGIN_URL) . self::ASSETS_DIST_CSS . '/vendor/nouislider.min.css',
                [],
                $ver
            );
        }

        if (!wp_script_is('nouislider', 'registered')) {
            wp_register_script(
                'nouislider',
                trailingslashit(MARADIGMA_PLUGIN_URL) . self::ASSETS_DIST_JS . '/vendor/nouislider.min.js',
                [],
                $ver,
                true
            );
        }
    }

    /**
     * Enqueues the noUiSlider assets.
     */
    private static function enqueueNoUiSlider(string $ver): void
    {
        self::registerNoUiSliderOnce($ver);
        wp_enqueue_style('nouislider');
        wp_enqueue_script('nouislider');
    }

    // ─────────────────────────────────────────────
    // FLATPICKR
    // ─────────────────────────────────────────────

    /**
     * Registers flatpickr once.
     */
    private static function registerFlatpickrOnce(string $ver): void
    {
        static $done = false;

        if ($done) {
            return;
        }

        $done = true;

        wp_register_style(
            'maradigma-flatpickr-css',
            trailingslashit(MARADIGMA_PLUGIN_URL) . self::ASSETS_DIST_CSS . '/vendor/flatpickr.min.css',
            [],
            $ver
        );

        wp_register_script(
            'maradigma-flatpickr',
            trailingslashit(MARADIGMA_PLUGIN_URL) . self::ASSETS_DIST_JS . '/vendor/flatpickr.min.js',
            [],
            $ver,
            true
        );
    }

    /**
     * Detects the Flatpickr language code for the current locale.
     */
    private static function detectFlatpickrLangCode(): string
    {
        if (class_exists(\Maradigma\Support\MultilangAdapter::class)) {
            $slug = \Maradigma\Support\MultilangAdapter::getCurrentLanguage();
            $slug = strtolower(trim((string) $slug));

            if ($slug !== '') {
                $slug = preg_split('/[_-]/', $slug)[0] ?? $slug;
                return $slug ?: 'en';
            }
        }

        $locale = strtolower(trim((string) determine_locale()));
        if ($locale !== '') {
            $base = preg_split('/[_-]/', $locale)[0] ?? '';
            if ($base !== '') {
                return $base;
            }
        }

        return 'en';
    }

    /**
     * Maps a language code to its Flatpickr locale file.
     */
    private static function mapFlatpickrLocaleFile(string $lang): string
    {
        $lang = strtolower(trim($lang));

        $map = [
            'en' => 'default',
        ];

        return $map[$lang] ?? $lang;
    }

    /**
     * Enqueues flatpickr assets.
     */
    private static function enqueueFlatpickr(string $ver): void
    {
        self::registerFlatpickrOnce($ver);

        wp_enqueue_style('maradigma-flatpickr-css');
        wp_enqueue_script('maradigma-flatpickr');
    }

    // ─────────────────────────────────────────────
    // SWIPER
    // ─────────────────────────────────────────────

    /**
     * Registers swiper once.
     */
    private static function registerSwiperOnce(): void
    {
        static $done = false;

        if ($done) {
            return;
        }

        $done = true;

        $ver = self::getVersion();

        wp_register_style(
            'maradigma-swiper-css',
            trailingslashit(MARADIGMA_PLUGIN_URL) . self::ASSETS_DIST_CSS . '/vendor/swiper.min.css',
            [],
            $ver
        );

        wp_register_script(
            'maradigma-swiper',
            trailingslashit(MARADIGMA_PLUGIN_URL) . self::ASSETS_DIST_JS . '/vendor/swiper.min.js',
            [],
            $ver,
            true
        );

        wp_register_script(
            'maradigma-boat-gallery',
            trailingslashit(MARADIGMA_PLUGIN_URL) . self::getJsBasePath() . '/shared/maradigma-boat-gallery' . self::getJsSuffix() . '.js',
            ['jquery', 'maradigma-swiper'],
            $ver,
            true
        );
    }

    /**
     * Enqueues swiper assets.
     */
    private static function enqueueSwiper(): void
    {
        self::registerSwiperOnce();
        wp_enqueue_style('maradigma-swiper-css');
        wp_enqueue_script('maradigma-swiper');
        wp_enqueue_script('maradigma-boat-gallery');
    }

    // ─────────────────────────────────────────────
    // BOAT CALENDAR
    // ─────────────────────────────────────────────

    /**
     * Registers boat calendar once.
     */
    private static function registerBoatCalendarOnce(string $ver): void
    {
        static $done = false;

        if ($done) {
            return;
        }

        $done = true;

        $jsHandle = 'maradigma-boat-calendar';
        $jsSrc    = trailingslashit(MARADIGMA_PLUGIN_URL) . self::getJsBasePath() . '/shared/maradigma-boat-calendar' . self::getJsSuffix() . '.js';

        if (!wp_script_is('maradigma-events', 'registered')) {
            wp_register_script(
                'maradigma-events',
                trailingslashit(MARADIGMA_PLUGIN_URL) . self::getJsBasePath() . '/frontend/maradigma-events' . self::getJsSuffix() . '.js',
                [],
                $ver,
                true
            );
        }

        if (!wp_script_is($jsHandle, 'registered')) {
            wp_register_script($jsHandle, $jsSrc, ['maradigma-events'], $ver, true);
        }
    }

    // ─────────────────────────────────────────────
    // I18N BOOKING MODAL
    // ─────────────────────────────────────────────

    /**
     * Adds booking modal i18n.
     */
    private static function addBookingModalI18n(string $handle): void {
        $ctx = MultilangAdapter::getCurrentContext();

        $payload = [
            'provider' => $ctx['provider'],
            'lang' => $ctx['lang'],
            'locale' => $ctx['locale'],
            'labels' => [
                'dateStart'                          => __('Date start', 'maradigma'),
                'dateEnd'                            => __('Date end', 'maradigma'),
                'dateRange'                          => __('Dates', 'maradigma'),
                'fullDayCharter'                     => __('Full day charter', 'maradigma'),
                'fullDayCharterHelp'                 => __('Select full day if you want the boat for the entire day. Otherwise, choose a half-day schedule.', 'maradigma'),
                'selectTimeslot'                     => __('Select a schedule', 'maradigma'),
                'selectTimeslotHelp'                 => __('Choose your preferred half-day schedule for this day charter.', 'maradigma'),
                'people'                             => __('People', 'maradigma'),
                'firstName'                          => __('First name', 'maradigma'),
                'lastName'                           => __('Last name', 'maradigma'),
                'email'                              => __('Email', 'maradigma'),
                'emailRequired'                      => __('Email *', 'maradigma'),
                'phone'                              => __('Phone', 'maradigma'),
                'country'                            => __('Country', 'maradigma'),
                'paymentMethod'                      => __('Payment method', 'maradigma'),
                'card'                               => __('Card', 'maradigma'),
                'acceptTerms'                        => __('I accept terms and conditions', 'maradigma'),

                'total'                              => __('Total', 'maradigma'),
                'extras'                             => __('Extras', 'maradigma'),
                'toBePaidOnline'                     => __('To be paid online', 'maradigma'),
                'toBePaidOnSpot'                     => __('To be paid on the spot', 'maradigma'),
                'totalBookingExtras'                 => __('Total booking + extras', 'maradigma'),
                'prepaymentPercent'                  => __('Prepayment', 'maradigma'),

                'securityDeposit'                    => __('Amount of the security deposit', 'maradigma'),
                'fuelNotIncluded'                    => __('Fuel not included', 'maradigma'),
                'mandatory'                          => __('Mandatory', 'maradigma'),
                'included'                           => __('Included', 'maradigma'),

                'childrenIncluded'                   => __('Including children onboard', 'maradigma'),
                'chooseChildren'                     => __('Choose the number of children', 'maradigma'),
                'hireSkipperTitle'                   => __('Would you like a professional skipper on board?', 'maradigma'),
                'hireSkipperHelp'                    => __("Recommended for a stress-free trip, especially if you're not fully familiar with the area or docking.", 'maradigma'),
                'optionalMessage'                    => __('Optional message', 'maradigma'),
                'optionalMessageHelp'                => __('You can specify your project (schedule, program, particular needs)', 'maradigma'),

                'promoCodeTitle'                     => __('Add a promotional code', 'maradigma'),
                'promoCodePlaceholder'               => __('Enter coupon', 'maradigma'),
                'selectPaymentMethod'                => __('Select a payment method', 'maradigma'),

                'priceDetails'                       => __('Price details', 'maradigma'),
                'rentalPrice'                        => __('Rental price', 'maradigma'),
                'serviceFee'                         => __('Service fee', 'maradigma'),
                'appliedToAmountYouWillPayNow'       => __('applied to the amount you will pay now', 'maradigma'),
                'taxableAmount'                      => __('Taxable amount', 'maradigma'),
                'vatIncluded'                        => __('VAT included', 'maradigma'),
                'vat'                                => __('VAT', 'maradigma'),
                'payNow'                             => __('Pay now', 'maradigma'),
                'payAtPort'                          => __('Pay at the port', 'maradigma'),

                'termsAcceptanceFull'                => __('By selecting the following button, you unconditionally accept the Terms of Use and Rental Terms. You also agree to pay the total amount of the reservation.', 'maradigma'),
                'readTerms'                          => __('Read terms', 'maradigma'),

                'bookingConfirmedTitle'              => __('Reservation confirmed', 'maradigma'),
                'bookingConfirmedText'               => __('Your payment has been confirmed and your booking has been created successfully.', 'maradigma'),
                'bookingSummary'                     => __('Booking summary', 'maradigma'),
                'bookingReference'                   => __('Booking reference', 'maradigma'),
                'service'                            => __('Service', 'maradigma'),
                'location'                           => __('Location', 'maradigma'),
                'date'                               => __('Date', 'maradigma'),
                'customer'                           => __('Customer', 'maradigma'),
                'paymentSummary'                     => __('Payment summary', 'maradigma'),
                'paymentReference'                   => __('Payment reference', 'maradigma'),
                'amountPaid'                         => __('Amount paid', 'maradigma'),
                'contactDetails'                     => __('Contact details', 'maradigma'),
                'bookingDetails'                     => __('Booking details', 'maradigma'),

                'readyToContinueTitle'               => __('Ready to continue', 'maradigma'),
                'readyToContinueText'                => __('Click Continue to create the booking and go to payment.', 'maradigma'),
                'termsPrefix'                        => __('I have read and agree to the', 'maradigma'),
                'termsLinkText'                      => __('reservation terms and conditions', 'maradigma'),
                'passengerSingular'                  => __('passenger', 'maradigma'),
                'passengerPlural'                    => __('passengers', 'maradigma'),
                'dateRangeBetween'                   => __('From {start} to {end}', 'maradigma'),
                'managementFee'                      => __('Management fee', 'maradigma'),
                'onlinePaymentCommissionInfo'        => __('Applied to the online booking payment', 'maradigma'),
                'totalChargedOnline'                 => __('Total charged online', 'maradigma'),
                'onlinePaymentIncludesManagementFee' => __('This price already includes a management fee of %price%', 'maradigma'),
            ],
            'steps' => [
                'step1' => __('Specify the reservation', 'maradigma'),
                'step2' => __('Your profile information', 'maradigma'),
                'step3' => __('Payment method', 'maradigma'),
                'step4' => __('Reservation confirmed!', 'maradigma'),
            ],
            'ui' => [
                'calculating'         => __('Calculating…', 'maradigma'),
                'loadingTerms'        => __('Loading terms…', 'maradigma'),
                'quoteFailed'         => __('Quote failed.', 'maradigma'),
                'notCalculated'       => __('Not calculated', 'maradigma'),
                'boatNotSelected'     => __('Boat not selected.', 'maradigma'),
                'selectDates'         => __('Select dates', 'maradigma'),
                'messagePlaceholder'  => __('Write your message (optional)…', 'maradigma'),
                'continue'            => __('Continue', 'maradigma'),
                'continueToPayment'   => __('Continue to payment', 'maradigma'),
                'back'                => __('Back', 'maradigma'),
                'cancel'              => __('Cancel', 'maradigma'),
                'redirecting'         => __('Redirecting…', 'maradigma'),
                'totalPrefix'         => __('Total:', 'maradigma'),
                'quoteReceived'       => __('Quote received.', 'maradigma'),
                'bookingOkNoUrl'      => __('Booking created, but no payment URL was returned.', 'maradigma'),
                'bookingOkNoRedirect' => __('Booking OK (no redirect URL).', 'maradigma'),
                'learnMore'           => __('Learn more', 'maradigma'),
                'fuelLearnMore'       => __('Fuel cost may be charged before or after boarding.', 'maradigma'),
                'fuelIncluded'        => __('Fuel included', 'maradigma'),
                'fuelIncludedInfo'    => __('Fuel is already included in the rental price, so you won’t need to pay it separately.', 'maradigma'),
                'yesRecommended'      => __('Yes (recommended)', 'maradigma'),
                'no'                  => __('No', 'maradigma'),
                'apply'               => __('Apply', 'maradigma'),
                'selectCountry'       => __('Select country', 'maradigma'),
                'selectOneTimeslot'   => __('Select a schedule…', 'maradigma'),
                'occupied'            => __('Occupied', 'maradigma'),
                'paymentOk'           => __('Payment completed successfully.', 'maradigma'),
                'paymentCancelled'    => __('Payment was cancelled or failed. You can try again.', 'maradigma'),
                'paymentPending'      => __('Payment not completed yet. If you already paid, refresh in a moment.', 'maradigma'),
                'viewBookingDetails'  => __('View booking details', 'maradigma'),
                'viewDetails'         => __('View details', 'maradigma'),
                'close'               => __('Close', 'maradigma'),
                'free'                => __('Free', 'maradigma'),
                'payNowHelp'          => __('This is the amount you will pay online now.', 'maradigma'),
                'payAtPortHelp'       => __('This is the amount to be paid at the port on the charter day.', 'maradigma'),
                'viewPaymentBreakdown' => __('View payment breakdown', 'maradigma'),
                'viewPendingBreakdown' => __('View pending breakdown', 'maradigma'),
                'subtotalToPayNow'     => __('Subtotal to pay now', 'maradigma'),
                'totalToPayNow'        => __('Total to pay now', 'maradigma'),
                'totalPending'         => __('Total pending', 'maradigma'),
                'confirmAndPay' => __('Confirm and pay', 'maradigma'),
                'reloadPage'           => __('Reload page', 'maradigma'),
            ],
            'errors'   => [
                'emailRequired'          => __('Email is required.', 'maradigma'),
                'selectDates'            => __('Please select dates.', 'maradigma'),
                'mustAcceptTerms'        => __('You must accept terms and conditions.', 'maradigma'),
                'quoteEndpointMissing'   => __('Quote endpoint not configured.', 'maradigma'),
                'bookingEndpointMissing' => __('Booking endpoint not configured.', 'maradigma'),
                'quoteError'             => __('Quote error', 'maradigma'),
                'priceOnBookingError'    => __('Price calculation error', 'maradigma'),
                'bookingError'           => __('Booking error', 'maradigma'),
                'temporaryUnavailable'   => __('We cannot process your request right now. Please try again later.', 'maradigma'),
                'invalidPhone'           => __('Invalid phone number', 'maradigma'),
                'selectTimeslot'         => __('Please select a schedule.', 'maradigma'),
                'selectedTimeslotUnavailable' => __('The selected schedule is no longer available. We refreshed the available schedules.', 'maradigma'),
                'fullDayNotAllowed'      => __('Full day is not available for this date.', 'maradigma'),
                'shopCartNotFound'       => __('Shop cart not found.', 'maradigma'),
                'bookingSessionExpired'  => __('The session has expired.', 'maradigma'),
            ],
        ];

        wp_add_inline_script(
            $handle,
            'window.MaradigmaI18n = ' . wp_json_encode($payload) . ';',
            'before'
        );
    }

    // ─────────────────────────────────────────────
    // ADMIN
    // ─────────────────────────────────────────────

    /**
     * Enqueues admin assets.
     */
    public static function enqueueAdmin(string $hookSuffix): void
    {
        if (self::isMaradigmaSettingsPage()) {
            self::enqueueSettingsAssets($hookSuffix);
            return;
        }

        if (self::isBlockEditorScreen()) {
            return;
        }

        self::enqueuePageEditorAssets();
    }

    /**
     * Determines whether maradigma settings page.
     */
    private static function isMaradigmaSettingsPage(): bool
    {
        // Read-only admin routing value used only to decide which assets to enqueue.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = isset($_GET['page']) ? sanitize_key((string) wp_unslash($_GET['page'])) : '';
        return $page === 'maradigma-settings';
    }

    /**
     * Determines whether block editor screen.
     */
    private static function isBlockEditorScreen(): bool
    {
        if (!function_exists('get_current_screen')) {
            return false;
        }

        $screen = get_current_screen();

        return $screen && method_exists($screen, 'is_block_editor') && $screen->is_block_editor();
    }

    /**
     * Enqueues assets for the Maradigma settings screen.
     */
    private static function enqueueSettingsAssets(string $hookSuffix): void
    {
        $ver = self::getVersion();

        self::enqueueAdminCssBundle($ver);

        // Read-only admin tab used only to decide which assets to enqueue.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $tab = isset($_GET['tab']) ? sanitize_key((string) wp_unslash($_GET['tab'])) : 'settings';

        self::enqueueAdminSvgSpriteShared($ver);

        if ($tab === 'seo') {
            $seoHandle = 'maradigma-settings-seo';

            wp_enqueue_script(
                $seoHandle,
                trailingslashit(MARADIGMA_PLUGIN_URL) . self::getJsBasePath() . '/admin/settings-seo' . self::getJsSuffix() . '.js',
                ['jquery'],
                $ver,
                true
            );

            $labels = \Maradigma\BoatCardEngine::getPlaceholdersLabels();

            $tokensForJs = [];
            foreach (\Maradigma\BoatCardEngine::listTokens() as $token) {
                $id = '{{' . $token . '}}';

                $tokensForJs[] = [
                    'id'    => $id,
                    'label' => (string) ($labels[$id] ?? $token),
                ];
            }

            wp_localize_script(
                $seoHandle,
                'MaradigmaSeoTokens',
                [
                    'tokens' => $tokensForJs,
                    'i18n'   => [
                        'insert'    => __('Insertar', 'maradigma'),
                        'close'     => __('Cerrar', 'maradigma'),
                        'selectOne' => __('Select a variable…', 'maradigma'),
                    ],
                ]
            );

            return;
        }

        if ($tab === 'settings') {
            $boatsHandle = 'maradigma-settings-boats-sync';

            wp_enqueue_script(
                $boatsHandle,
                trailingslashit(MARADIGMA_PLUGIN_URL) . self::getJsBasePath() . '/admin/settings-boats-sync' . self::getJsSuffix() . '.js',
                [],
                $ver,
                true
            );

            wp_localize_script(
                $boatsHandle,
                'MaradigmaBoatSyncAdmin',
                [
                    'ajaxUrl'      => admin_url('admin-ajax.php'),
                    'nonce'        => wp_create_nonce('maradigma_boat_sync_admin'),
                    'statusAction' => 'maradigma_boat_sync_status',
                    'pumpAction'   => 'maradigma_boat_sync_pump',
                    'stopAction'   => 'maradigma_boat_sync_stop',
                    'i18n'         => [
                        'status' => [
                            'running' => __('running', 'maradigma'),
                            'done'    => __('done', 'maradigma'),
                            'stopped' => __('stopped', 'maradigma'),
                            'error'   => __('error', 'maradigma'),
                            'idle'    => __('idle', 'maradigma'),
                        ],
                    ],
                ]
            );

            $imagesHandle = 'maradigma-settings-images-sync';

            wp_enqueue_script(
                $imagesHandle,
                trailingslashit(MARADIGMA_PLUGIN_URL) . self::getJsBasePath() . '/admin/settings-images-sync' . self::getJsSuffix() . '.js',
                [],
                $ver,
                true
            );

            wp_localize_script(
                $imagesHandle,
                'MaradigmaImageSyncAdmin',
                [
                    'ajaxUrl'      => admin_url('admin-ajax.php'),
                    'nonce'        => wp_create_nonce('maradigma_images_sync_admin'),
                    'statusAction' => 'maradigma_boat_images_sync_status',
                    'pumpAction'   => 'maradigma_boat_images_sync_pump',
                    'i18n'         => [
                        /* translators: 1: imported image count, 2: reused image count, 3: failed image count. */
                        'runCounts' => __('imported %1$d | reused %2$d | failed %3$d', 'maradigma'),
                        'status'    => [
                            'running' => __('running', 'maradigma'),
                            'done'    => __('done', 'maradigma'),
                            'stopped' => __('stopped', 'maradigma'),
                            'error'   => __('error', 'maradigma'),
                            'idle'    => __('idle', 'maradigma'),
                        ],
                        'worker'    => [
                            'processing'                     => __('processing', 'maradigma'),
                            'waiting for current worker'     => __('waiting for current worker', 'maradigma'),
                            'waiting for wp-cron'            => __('waiting for WP-Cron', 'maradigma'),
                            'running, waiting for next kick' => __('running, waiting for next kick', 'maradigma'),
                            'running'                        => __('running', 'maradigma'),
                            'done'                           => __('done', 'maradigma'),
                            'stopped'                        => __('stopped', 'maradigma'),
                            'error'                          => __('error', 'maradigma'),
                            'idle'                           => __('idle', 'maradigma'),
                        ],
                        'async'     => [
                            'ok'            => __('ok', 'maradigma'),
                            'failed'        => __('failed', 'maradigma'),
                            'not attempted' => __('not attempted', 'maradigma'),
                        ],
                    ],
                ]
            );

            $templateHandle = 'maradigma-settings-template-sync';

            wp_enqueue_script(
                $templateHandle,
                trailingslashit(MARADIGMA_PLUGIN_URL) . self::getJsBasePath() . '/admin/settings-template-sync' . self::getJsSuffix() . '.js',
                [],
                $ver,
                true
            );

            wp_localize_script(
                $templateHandle,
                'MaradigmaTemplateSyncAdmin',
                [
                    'ajaxUrl'      => admin_url('admin-ajax.php'),
                    'nonce'        => wp_create_nonce('maradigma_template_sync_admin'),
                    'statusAction' => 'maradigma_template_sync_status',
                    'pumpAction'   => 'maradigma_template_sync_pump',
                    'i18n'         => [
                        'status' => [
                            'running' => __('running', 'maradigma'),
                            'done'    => __('done', 'maradigma'),
                            'stopped' => __('stopped', 'maradigma'),
                            'error'   => __('error', 'maradigma'),
                            'idle'    => __('idle', 'maradigma'),
                        ],
                    ],
                ]
            );

            return;
        }

        if ($tab !== 'cards') {
            return;
        }

        $codeEditorSettings = wp_enqueue_code_editor([
            'type' => 'text/html',
        ]);

        if ($codeEditorSettings !== false) {
            wp_enqueue_script('code-editor');
            wp_enqueue_style('code-editor');
        }

        self::enqueueSelect2();

        $cardsHandle = 'maradigma-boats-cards-admin';

        wp_enqueue_script(
            $cardsHandle,
            trailingslashit(MARADIGMA_PLUGIN_URL) . self::getJsBasePath() . '/admin/boats-cards' . self::getJsSuffix() . '.js',
            array_values(array_filter([
                'jquery',
                'maradigma-select2',
                $codeEditorSettings !== false ? 'code-editor' : null,
                'maradigma-icons-admin',
            ])),
            $ver,
            true
        );

        $labels = \Maradigma\BoatCardEngine::getPlaceholdersLabels();
        foreach (\Maradigma\BoatCardEngine::listTokens() as $token) {
            $id = '{{' . $token . '}}';

            if (!isset($labels[$id])) {
                $labels[$id] = $token;
            }
        }

        uksort($labels, static function (string $a, string $b) use ($labels): int {
            $aHuman = $labels[$a] !== trim($a, '{}');
            $bHuman = $labels[$b] !== trim($b, '{}');

            if ($aHuman !== $bHuman) {
                return $aHuman ? -1 : 1;
            }

            return strcasecmp($a, $b);
        });

        $tokensForJs = [];
        foreach ($labels as $id => $label) {
            $tokensForJs[] = [
                'id'    => (string) $id,
                'label' => (string) $label,
            ];
        }

        wp_localize_script(
            $cardsHandle,
            'MaradigmaCardsAdmin',
            [
                'hasCodeEditor'      => $codeEditorSettings !== false ? '1' : '0',
                'codeEditorSettings' => $codeEditorSettings !== false ? $codeEditorSettings : null,
                'frontendCssUrl'     => trailingslashit(MARADIGMA_PLUGIN_URL) . self::getCssBasePath() . '/frontend' . self::getCssSuffix() . '.css',
                'frontendCssUrls'    => [
                    trailingslashit(MARADIGMA_PLUGIN_URL) . self::getCssBasePath() . '/frontend' . self::getCssSuffix() . '.css',
                ],
                'tokens'             => $tokensForJs,
                'previewSample'      => self::getBoatCardPreviewSample(),
            ]
        );
    }

    /**
     * Enqueues admin svg sprite shared assets.
     */
    private static function enqueueAdminSvgSpriteShared(string $ver): void
    {
        $iconsHandle = 'maradigma-icons-admin';

        wp_enqueue_script(
            $iconsHandle,
            trailingslashit(MARADIGMA_PLUGIN_URL) . self::getJsBasePath() . '/shared/maradigma-icons' . self::getJsSuffix() . '.js',
            [],
            $ver,
            true
        );

        $svgs = require trailingslashit(MARADIGMA_PLUGIN_DIR) . 'includes/Support/FrontendSvgs.php';

        wp_localize_script(
            $iconsHandle,
            'MaradigmaConfig',
            [
                'nonce' => wp_create_nonce('wp_rest'),
                'svgs'  => $svgs,
            ]
        );
    }

    /**
     * Enqueues shared remote select2 assets.
     */
    private static function enqueueSharedRemoteSelect2(string $context, string $ver): void
    {
        self::enqueueSelect2();

        $sharedHandle = 'maradigma-remote-select2';

        wp_enqueue_script(
            $sharedHandle,
            trailingslashit(MARADIGMA_PLUGIN_URL) . self::getJsBasePath() . '/shared/remote-select2' . self::getJsSuffix() . '.js',
            ['jquery', 'maradigma-select2'],
            $ver,
            true
        );

        $isEditorContext = in_array($context, ['admin', 'elementor_editor'], true);

        if ($isEditorContext) {
            $searchActions = [
                'boatTypesAction' => 'maradigma_admin_search_boat_types',
                'tagsAction'      => 'maradigma_admin_search_tags',
                'buildersAction'  => 'maradigma_admin_search_builders',
                'builderByIdAction' => 'maradigma_admin_get_builder_by_id',
                'boatsAction'     => 'maradigma_admin_search_boats',
                'basePortsAction' => 'maradigma_admin_search_base_ports',
                'boatByIdAction'  => 'maradigma_admin_get_boat_by_id',
            ];

            $nonceAction = 'maradigma_admin';
        } else {
            $searchActions = [
                'boatTypesAction' => 'maradigma_front_search_boat_types',
                'tagsAction'      => 'maradigma_front_search_tags',
                'buildersAction'  => 'maradigma_front_search_builders',
                'boatsAction'     => 'maradigma_front_search_boats',
                'basePortsAction' => 'maradigma_front_search_base_ports',
                'boatByIdAction'  => 'maradigma_front_get_boat_by_id',
            ];

            $nonceAction = 'maradigma_remote_select2';
        }

        wp_add_inline_script(
            $sharedHandle,
            'window.MaradigmaRemoteSelect2 = ' . wp_json_encode([
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce($nonceAction),
                'search'  => $searchActions,
                'i18n'    => [
                    'placeholderSingle' => __('Select an option', 'maradigma'),
                    'placeholderMulti'  => __('Select one or more options', 'maradigma'),
                    'searching'         => __('Searching…', 'maradigma'),
                    'noResults'         => __('No results found', 'maradigma'),
                    'errorLoading'      => __('Error loading results', 'maradigma'),
                    'inputTooShort'     => __('Type at least 2 characters', 'maradigma'),
                    'boatTypes'         => __('Select boat type', 'maradigma'),
                    'tags'              => __('Select tags', 'maradigma'),
                    'builders'          => $isEditorContext ? __('Select builders', 'maradigma') : __('Select brand', 'maradigma'),
                    'boats'             => __('Search a boat', 'maradigma'),
                    'basePorts'         => __('Select base port', 'maradigma'),
                ],
                'context' => $context,
            ]) . ';',
            'before'
        );
    }

    /**
     * Enqueues the boat page editor assets.
     */
    private static function enqueuePageEditorAssets(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen) {
            return;
        }

        $postType = (string) ($screen->post_type ?? '');
        $taxonomy = (string) ($screen->taxonomy ?? '');
        $base     = (string) ($screen->base ?? '');

        $enabledBindingPostTypes = \Maradigma\SettingsPage::getEnabledBoatBindingPostTypes();

        $isSupportedPostScreen = in_array($postType, $enabledBindingPostTypes, true)
            || $postType === BoatPostType::POST_TYPE;
        $isSupportedTaxScreen  = in_array($base, ['edit-tags', 'term'], true)
            && $taxonomy !== ''
            && \Maradigma\MetaManager::isSupportedBoatTaxonomy($taxonomy);

        if (!$isSupportedPostScreen && !$isSupportedTaxScreen) {
            return;
        }

        self::enqueueSelect2();

        $handle = 'maradigma-admin-page-boats';
        $ver    = self::getVersion();

        wp_enqueue_script(
            $handle,
            trailingslashit(MARADIGMA_PLUGIN_URL) . self::getJsBasePath() . '/admin/admin-page-boats' . self::getJsSuffix() . '.js',
            ['jquery', 'maradigma-select2'],
            $ver,
            true
        );

        wp_localize_script(
            $handle,
            'MaradigmaBoatsAdmin',
            [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('maradigma_admin'),
                'search'  => [
                    'boatTypesAction' => 'maradigma_admin_search_boat_types',
                    'tagsAction'      => 'maradigma_admin_search_tags',
                    'buildersAction'  => 'maradigma_admin_search_builders',
                    'builderByIdAction' => 'maradigma_admin_get_builder_by_id',
                    'boatsAction'     => 'maradigma_admin_search_boats',
                    'boatByIdAction'  => 'maradigma_admin_get_boat_by_id',
                ],
                'i18n'    => [
                    'placeholderSingle'      => __('Select an option', 'maradigma'),
                    'placeholderMulti'       => __('Select one or more options', 'maradigma'),
                    'searching'              => __('Searching…', 'maradigma'),
                    'noResults'              => __('No results found', 'maradigma'),
                    'errorLoading'           => __('Error loading results', 'maradigma'),
                    'i18nByTypeType'         => __('Select boat type', 'maradigma'),
                    'i18nMultiTypes'         => __('Select boat types', 'maradigma'),
                    'i18nCustomServiceTypes' => __('Select service types', 'maradigma'),
                    'i18nCustomTags'         => __('Select tags', 'maradigma'),
                    'i18nCustomBuilders'     => __('Select builders', 'maradigma'),
                    'i18nCustomBoats'        => __('Select boats', 'maradigma'),
                    'i18nSelectBoat'         => __('Search a boat', 'maradigma'),
                    'inputTooShort'          => __('Type at least 2 characters', 'maradigma'),
                ],
            ]
        );
    }

    // ─────────────────────────────────────────────
    // ELEMENTOR
    // ─────────────────────────────────────────────

    /**
     * Enqueues the block editor boat-binding assets.
     */
    public static function enqueueBlockEditorBoatBindingAssets(): void
    {
        // Gutenberg uses native WordPress controls configured by GutenbergIntegration.
    }

    /**
     * Enqueues the Elementor editor assets.
     */
    public static function enqueueElementorEditorAssets(): void
    {
        $ver = self::getVersion();

        self::enqueueSelect2();
        self::enqueueAdminCssBundle($ver);

        $handle = 'maradigma-elementor-widget';

        wp_enqueue_script(
            $handle,
            trailingslashit(MARADIGMA_PLUGIN_URL) . self::getJsBasePath() . '/admin/elementor-widget' . self::getJsSuffix() . '.js',
            ['jquery', 'maradigma-select2'],
            $ver,
            true
        );

        wp_enqueue_script(
            'maradigma-elementor-guards',
            trailingslashit(MARADIGMA_PLUGIN_URL) . self::getJsBasePath() . '/admin/elementor-guards' . self::getJsSuffix() . '.js',
            ['jquery', $handle],
            $ver,
            true
        );

        wp_localize_script(
            $handle,
            'MaradigmaElementor',
            [
                'ajaxUrl'           => admin_url('admin-ajax.php'),
                'nonce'             => wp_create_nonce('maradigma_elementor'),
                'pageSize'          => 20,
                'search'            => [
                    'boatsAction'        => 'maradigma_elementor_search_boats',
                    'boatTypesAction'    => 'maradigma_elementor_search_boat_types',
                    'buildersAction'     => 'maradigma_elementor_search_builders',
                    'destinationsAction' => 'maradigma_elementor_search_destinations',
                ],
                'imagesCountAction' => 'maradigma_get_boat_images_count',
                'i18n'              => [
                    'placeholderSingle'      => __('Select an option', 'maradigma'),
                    'placeholderMulti'       => __('Select one or more options', 'maradigma'),
                    'searching'              => __('Searching…', 'maradigma'),
                    'noResults'              => __('No results found', 'maradigma'),
                    'errorLoading'           => __('Error loading results', 'maradigma'),
                    'boatsPlaceholder'       => __('Search boats...', 'maradigma'),
                    'typesPlaceholder'       => __('Select boat type', 'maradigma'),
                    'buildersPlaceholder'    => __('Search builders...', 'maradigma'),
                    'destinationsPlaceholder' => __('Search destinations...', 'maradigma'),
                    'imagesAvailablePrefix'  => __('Images available: ', 'maradigma'),
                    'imagesAvailableLoading' => __('Images available: …', 'maradigma'),
                    'imagesAvailableUnknown' => __('Images available: —', 'maradigma'),
                ],
            ]
        );

        $panelHandle = 'maradigma-elementor-panel';

        wp_enqueue_script(
            $panelHandle,
            trailingslashit(MARADIGMA_PLUGIN_URL) . self::getJsBasePath() . '/admin/elementor-maradigma-panel' . self::getJsSuffix() . '.js',
            ['jquery', 'wp-api-fetch', 'elementor-editor'],
            $ver,
            true
        );

        $postId = 0;

        // Elementor editor identifiers are read-only routing values.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $requestedPostId = isset($_GET['post'])
            ? sanitize_text_field((string) wp_unslash($_GET['post']))
            : '';
        $requestedFallbackPostId = isset($_GET['post_id'])
            ? sanitize_text_field((string) wp_unslash($_GET['post_id']))
            : '';

        if ($requestedPostId !== '' && is_numeric($requestedPostId)) {
            $postId = absint($requestedPostId);
        }

        if ($postId <= 0 && $requestedFallbackPostId !== '' && is_numeric($requestedFallbackPostId)) {
            $postId = absint($requestedFallbackPostId);
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        if ($postId <= 0) {
            global $post;
            if ($post instanceof \WP_Post) {
                $postId = (int) $post->ID;
            }
        }

        $panelCfg = [
            'post'     => [
                'id'   => $postId,
                'type' => $postId > 0 ? (string) get_post_type($postId) : '',
            ],
            'metaKeys' => [
                'useCustomLayout' => \Maradigma\MetaManager::META_CPT_ELEMENTOR_CUSTOM_LAYOUT,
                'boatId'          => \Maradigma\MetaManager::META_CPT_BOAT_ID,
            ],
            'i18n'     => [
                'tabTitle'        => __('Maradigma', 'maradigma'),
                'boatSelectLabel' => __('Maradigma boat (searchable)', 'maradigma'),
                'boatSelectHelp'  => __('Select one of your active/public boats from Maradigma. Start typing to search.', 'maradigma'),
                'useCustomLayout' => __('Use custom Elementor layout', 'maradigma'),
                'useCustomHelp'   => __('When enabled, sync will never overwrite this boat’s Elementor layout. Boat data, images and SEO will still be synced.', 'maradigma'),
                'readOnlyNotice'  => __('Context not available. Panel is in read-only mode.', 'maradigma'),
            ],
        ];

        wp_add_inline_script(
            $panelHandle,
            'window.MaradigmaElementorPanel = ' . wp_json_encode($panelCfg) . ';',
            'before'
        );

        self::registerSwiperOnce();
    }

    /**
     * Enqueues Elementor preview styles assets.
     */
    public static function enqueueElementorPreviewStyles(): void
    {
        $ver = self::getVersion();

        self::enqueueFrontendCssBundle($ver);
        self::enqueueSelect2();

        self::registerSwiperOnce();
        wp_enqueue_style('maradigma-swiper-css');
        self::enqueueJqueryUiDatepickerStyle($ver);
        self::enqueueNoUiSlider($ver);
    }

    /**
     * Enqueues Elementor preview scripts assets.
     */
    public static function enqueueElementorPreviewScripts(): void
    {
        $ver = self::getVersion();

        self::enqueueSharedRemoteSelect2('elementor_preview', $ver);
        self::enqueueFlatpickr($ver);
        self::enqueueIntlTelInput();
        self::enqueueSwiper();

        $iconsHandle = 'maradigma-icons';

        wp_enqueue_script(
            $iconsHandle,
            trailingslashit(MARADIGMA_PLUGIN_URL) . self::getJsBasePath() . '/shared/maradigma-icons' . self::getJsSuffix() . '.js',
            [],
            $ver,
            true
        );

        $svgs = require trailingslashit(MARADIGMA_PLUGIN_DIR) . 'includes/Support/FrontendSvgs.php';

        wp_localize_script(
            $iconsHandle,
            'MaradigmaConfig',
            [
                'nonce'           => wp_create_nonce('wp_rest'),
                'bookingNonce'    => PublicBookingGuard::createNonce(),
                'svgs'            => $svgs,
                'intlTelUtilsUrl' => self::getIntlTelInputUtilsUrl(),
                'wpJsonBase'      => esc_url_raw(rest_url()),
                'restUrlBookingNonce' => esc_url_raw(rest_url('maradigma/v1/booking/security-token')),
                'booking'         => self::getBookingFrontendConfig(),
            ]
        );

        wp_enqueue_script(
            'maradigma-events',
            trailingslashit(MARADIGMA_PLUGIN_URL) . self::getJsBasePath() . '/frontend/maradigma-events' . self::getJsSuffix() . '.js',
            [],
            $ver,
            true
        );

        wp_enqueue_script(
            'maradigma-api-client',
            trailingslashit(MARADIGMA_PLUGIN_URL) . self::getJsBasePath() . '/frontend/maradigma-api-client' . self::getJsSuffix() . '.js',
            ['maradigma-events'],
            $ver,
            true
        );

        wp_enqueue_script(
            'maradigma-boat-ui',
            trailingslashit(MARADIGMA_PLUGIN_URL) . self::getJsBasePath() . '/frontend/maradigma-boat-ui' . self::getJsSuffix() . '.js',
            ['maradigma-events', 'maradigma-api-client'],
            $ver,
            true
        );

        wp_enqueue_script(
            'maradigma-boats',
            trailingslashit(MARADIGMA_PLUGIN_URL) . self::getJsBasePath() . '/frontend/maradigma' . self::getJsSuffix() . '.js',
            [$iconsHandle, 'maradigma-events', 'maradigma-api-client', 'maradigma-boat-ui', 'maradigma-flatpickr'],
            $ver,
            true
        );

        wp_enqueue_script(
            'maradigma-booking-modal',
            trailingslashit(MARADIGMA_PLUGIN_URL) . self::getJsBasePath() . '/frontend/maradigma-booking-modal' . self::getJsSuffix() . '.js',
            ['maradigma-flatpickr', 'maradigma-iti', 'maradigma-events', 'maradigma-api-client', 'maradigma-boat-ui'],
            $ver,
            true
        );

        self::addBookingModalI18n('maradigma-booking-modal');

        self::registerBoatCalendarOnce($ver);
        wp_enqueue_script('maradigma-boat-calendar');

        wp_enqueue_script('jquery');
        self::enqueueFlatpickr($ver);
        self::enqueueNoUiSlider($ver);

        wp_enqueue_script(
            'maradigma-archive-filters',
            trailingslashit(MARADIGMA_PLUGIN_URL) . self::getJsBasePath() . '/frontend/archive-filters' . self::getJsSuffix() . '.js',
            ['jquery', 'maradigma-flatpickr', 'nouislider'],
            $ver,
            true
        );
    }

    /**
     * Enqueues the frontend/shared assets needed to preview Maradigma blocks inside
     * the Gutenberg editor canvas.
     *
     * This mirrors the Elementor preview asset stack without loading Elementor-specific
     * assets. It is called from enqueue_block_assets while the current admin screen is
     * the block editor, so styles/scripts are available inside Gutenberg's canvas.
     */
    public static function enqueueGutenbergPreviewAssets(): void
    {
        $ver = self::getVersion();

        self::enqueueFrontendCssBundle($ver);

        self::registerSwiperOnce();
        wp_enqueue_style('maradigma-swiper-css');
    }

    // ─────────────────────────────────────────────
    // PREVIEW SAMPLE
    // ─────────────────────────────────────────────

    /**
     * @return array<string,string>
     */
    private static function getBoatCardPreviewSample(): array
    {
        $svgPlaceholder = 'data:image/svg+xml;utf8,' . rawurlencode(
            '<svg xmlns="http://www.w3.org/2000/svg" width="800" height="500">'
            . '<rect width="100%" height="100%" fill="#f1f1f1"/>'
            . '<text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" font-family="Arial, sans-serif" font-size="32" fill="#666">Boat image</text>'
            . '</svg>'
        );

        return [
            '{{id}}'                     => '123',
            '{{name}}'                   => 'Sunseeker 52',
            '{{url}}'                    => '#',
            '{{slug}}'                   => 'sunseeker-52',
            '{{reference}}'              => 'IBZ-SS52',
            '{{service_name}}'           => 'Sunseeker 52',
            '{{boat_alias}}'             => 'Sunseeker',
            '{{boat_model}}'             => '52',
            '{{boat_builder}}'           => 'Sunseeker',
            '{{image_url}}'              => $svgPlaceholder,
            '{{image_url_600x400}}'      => $svgPlaceholder,
            '{{carousel_html}}'          => '<div class="maradigma-boat-card__carousel"><img src="' . esc_url($svgPlaceholder) . '" alt="Boat image" loading="lazy"></div>',
            '{{pax}}'                    => '10',
            '{{boat_capacity}}'          => '10',
            '{{length}}'                 => '16',
            '{{boat_length}}'            => '16',
            '{{beam}}'                   => '4.6',
            '{{boat_beam}}'              => '4.6',
            '{{port}}'                   => 'Ibiza',
            '{{boat_base_port_name}}'    => 'Ibiza',
            '{{boat_base_port}}'         => '2',
            '{{maps_latitude}}'          => '38.9067',
            '{{maps_longitude}}'         => '1.4206',
            '{{cabins}}'                 => '2',
            '{{beds}}'                   => '4',
            '{{bathrooms}}'              => '1',
            '{{year}}'                   => '2018',
            '{{boat_year_construction}}' => '2018',
            '{{boat_year_refit}}'        => '2023',
            '{{mandatory_skipper}}'      => '0',
            '{{is_bareboat}}'            => '0',
            '{{skipper_label}}'          => 'Optional skipper',
            '{{text_skipper}}'           => 'Optional skipper',
            '{{price_from}}'             => '650€',
            '{{price_from_service_with_mandatory_additionals_base}}'  => '700 EUR',
            '{{price_from_service_with_mandatory_additionals_vat}}'   => '147 EUR',
            '{{price_from_service_with_mandatory_additionals_total}}' => '847 EUR',
            '{{base_price}}'             => '650',
            '{{base_week_price}}'        => '3900',
            '{{featured}}'               => '1',
            '{{badge_featured_html}}'     => '<span class="maradigma-boat-card__badge maradigma-boat-card__badge--featured">Featured</span>',
            '{{badge_instant_booking_html}}' => '<span class="maradigma-boat-card__badge maradigma-boat-card__badge--instant-booking">Instant booking</span>',
            '{{status}}'                 => 'active',
        ];
    }

    // ─────────────────────────────────────────────
    // BOOTSTRAP
    // ─────────────────────────────────────────────

    /**
     * Registers bootstrap once.
     */
    private static function registerBootstrapOnce(): void
    {
        // Bootstrap is intentionally not bundled/enqueued. The plugin uses its own UI.
    }

    // ─────────────────────────────────────────────
    // CSS BUNDLES
    // ─────────────────────────────────────────────

    /**
     * Enqueues frontend CSS bundle assets.
     */
    private static function enqueueFrontendCssBundle(string $ver): void
    {
        wp_enqueue_style(
            'maradigma-frontend',
            trailingslashit(MARADIGMA_PLUGIN_URL) . self::getCssBasePath() . '/frontend' . self::getCssSuffix() . '.css',
            [],
            $ver
        );
    }

    /**
     * Enqueues the compiled administrative CSS bundle.
     */
    private static function enqueueAdminCssBundle(string $ver): void
    {
        wp_enqueue_style(
            'maradigma-admin',
            trailingslashit(MARADIGMA_PLUGIN_URL) . self::getCssBasePath() . '/admin' . self::getCssSuffix() . '.css',
            [],
            $ver
        );
    }

    // ─────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────

    /**
     * Determines whether compiled CSS assets should be used.
     */
    private static function useDistCss(): bool
    {
        if (defined('MARADIGMA_ASSETS_MIN_CSS')) {
            return (bool) MARADIGMA_ASSETS_MIN_CSS;
        }

        return true;
    }

    /**
     * Determines whether compiled JavaScript assets should be used.
     */
    private static function useDistJs(): bool
    {
        if (defined('MARADIGMA_ASSETS_MIN_JS')) {
            return (bool) MARADIGMA_ASSETS_MIN_JS;
        }

        return true;
    }

    /**
     * Returns CSS base path.
     */
    private static function getCssBasePath(): string
    {
        return self::useDistCss() ? self::ASSETS_DIST_CSS : 'assets/css';
    }

    /**
     * Returns js base path.
     */
    private static function getJsBasePath(): string
    {
        return self::useDistJs() ? self::ASSETS_DIST_JS : 'assets/js';
    }

    /**
     * Returns CSS suffix.
     */
    private static function getCssSuffix(): string
    {
        return self::useDistCss() ? '.min' : '';
    }

    /**
     * Returns js suffix.
     */
    private static function getJsSuffix(): string
    {
        return self::useDistJs() ? '.min' : '';
    }

    /**
     * Returns version.
     */
    private static function getVersion(): string
    {
        return defined('MARADIGMA_PLUGIN_VERSION') ? (string) MARADIGMA_PLUGIN_VERSION : '0.1.0';
    }
}

<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}
?>
<article class="maradigma-boat-card"
         data-boat-id="{{id}}"
         data-wp-locale="{{wp_locale}}"
         data-current-language="{{current_language}}"
         data-default-language="{{default_language}}"
         data-multilang-provider="{{multilang_provider}}"
         data-booking-can-render="{{booking_can_render}}"
         data-booking-is-owner="{{is_owner}}"
         data-booking-ins-book="{{ins_book}}"
         data-booking-ownership-is-tenant-member="{{ownership_is_tenant_member}}"
         data-booking-has-rent-online="{{booking_has_rent_online}}"
         itemscope
         itemtype="https://schema.org/Product">

    <a class="maradigma-boat-card__link"
       href="{{url}}"
       title="{{name}}"
       itemprop="url">

        <!-- Media -->
        <div class="maradigma-boat-card__media">
            <!--{{carousel_html}}-->
            <img class="maradigma-boat-card__img"
                 src="{{image_token}}"
                 alt="{{name}}"
                 loading="lazy"
                 decoding="async"
                 itemprop="image">

            <div class="maradigma-boat-card__badges">
                {{badge_featured_html}}
                {{badge_instant_booking_html}}
            </div>
        </div>

        <!-- Body -->
        <div class="maradigma-boat-card__body">

            <div class="maradigma-boat-card__top">
                <div class="maradigma-boat-card__header">
                    <p class="maradigma-boat-card__title"
                       role="heading"
                       aria-level="3"
                       itemprop="name">
                        {{service_name}}
                    </p>

                    <p class="maradigma-boat-card__subtitle">
                        <svg class="maradigma-boat-chip__icon"
                             aria-hidden="true"
                             focusable="false"
                             width="14"
                             height="14">
                            <use href="#svg-map-marker" xlink:href="#svg-map-marker"></use>
                        </svg>
                        <span class="maradigma-truncate">{{port}}</span>
                    </p>
                </div>
            </div>

            <!-- Bullets -->
            <div class="maradigma-boat-card__bullets" aria-label="[[ta text='Boat details']]">

                [[if year]]
                <div class="maradigma-bullet">
                    <svg class="maradigma-boat-chip__icon"
                         aria-hidden="true"
                         focusable="false"
                         width="14"
                         height="14">
                        <use href="#svg-users" xlink:href="#svg-users"></use>
                    </svg>
                    <span>{{pax}} [[t text="pers."]]</span>
                </div>

                <div class="maradigma-bullet">
                    <svg class="maradigma-boat-chip__icon"
                         aria-hidden="true"
                         focusable="false"
                         width="14"
                         height="14">
                        <use href="#svg-cabin" xlink:href="#svg-cabin"></use>
                    </svg>
                    <span>{{boat_cabins}} [[t text="cabins"]]</span>
                </div>

                <div class="maradigma-bullet" title="{{text_skipper}}">
                    <svg class="maradigma-boat-chip__icon"
                         aria-hidden="true"
                         focusable="false"
                         width="14"
                         height="14">
                        <use href="#svg-skipper" xlink:href="#svg-skipper"></use>
                    </svg>
                    <span class="maradigma-truncate">{{text_skipper}}</span>
                </div>

                <div class="maradigma-bullet">
                    <svg class="maradigma-boat-chip__icon"
                         aria-hidden="true"
                         focusable="false"
                         width="14"
                         height="14">
                        <use href="#svg-clock-rotate-left" xlink:href="#svg-clock-rotate-left"></use>
                    </svg>
                    <span>{{year}}</span>
                </div>
                [[/if]]

                <div class="maradigma-bullet">
                    <svg class="maradigma-boat-chip__icon"
                         aria-hidden="true"
                         focusable="false"
                         width="14"
                         height="14">
                        <use href="#svg-ruler" xlink:href="#svg-ruler"></use>
                    </svg>
                    <span>{{boat_length}} m</span>
                </div>

            </div>

            <!-- Offer (SEO) + Footer -->
            <div class="maradigma-boat-card__footer"
                 itemprop="offers"
                 itemscope
                 itemtype="https://schema.org/Offer">

                <meta itemprop="priceCurrency" content="{{currency}}" />
                <link itemprop="availability" href="https://schema.org/InStock" />

                <div class="maradigma-boat-card__price">
                    [[unless has_selected_date_range]]
                    <span class="maradigma-boat-card__price-label">[[t text="from"]]</span>
                    [[/unless]]
                    <span class="maradigma-boat-card__price-value">
                        <span itemprop="price">{{price_from}}</span>
                    </span>
                </div>

                <span class="maradigma-boat-card__cta">[[t text="Book now"]]</span>
            </div>

        </div>
    </a>
</article>

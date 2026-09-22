# Maradigma WordPress Plugin — Shortcodes (Developer Documentation)

This document is intended for **webmasters, theme developers, and agencies** integrating the **Maradigma — Boats & Bookings** plugin into a WordPress website.

It explains:

- Boat listing shortcode (catalog/search) with **filters** and **pagination**
- Single boat shortcode rendered through an **overridable PHP template**
- Generic and “sugar” shortcodes for printing specific boat fields
- Booking CTA shortcode (modal + REST calls)
- Allowed `md_*` URL parameters used by the filters UI

> The shortcodes call the Maradigma External API through the plugin’s `Cache` layer (transients) where applicable.

---

## Table of contents

1. [Available shortcodes](#1-available-shortcodes)  
2. [`[maradigma_boats]` — Boats listing](#2-maradigma_boats--boats-listing)  
   2.1. [Listing UI attributes (filters)](#21-listing-ui-attributes-filters)  
   2.2. [Search/API attributes](#22-searchapi-attributes)  
   2.2.1. [Standard boat type IDs](#221-standard-boat-type-ids)  
   2.3. [Pagination](#23-pagination)  
   2.4. [URL filters (`md_*`)](#24-url-filters-md_)  
   2.5. [Examples](#25-examples)  
   2.6. [`[maradigma_boat_card]` - Single boat card](#26-maradigma_boat_card---single-boat-card)
   2.7. [`[maradigma_related_boats]` - Related boat cards](#27-maradigma_related_boats---related-boat-cards)
3. [`[maradigma_boat]` — Single boat page](#3-maradigma_boat--single-boat-page)  
   3.1. [API detail options](#31-api-detail-options)
   3.2. [Overriding the template](#32-overriding-the-template)
   3.3. [Examples](#33-examples)
4. [`[maradigma_boat_field]` — Generic field shortcode](#4-maradigma_boat_field--generic-field-shortcode)  
5. “Sugar” shortcodes (common fields)](#5-sugar-shortcodes-common-fields)  
6. [`[maradigma_boat_booking]` — Booking CTA + modal](#6-maradigma_boat_booking--booking-cta--modal)  
7. [Notes & troubleshooting](#7-notes--troubleshooting)  

---

## 1. Available shortcodes

| Shortcode | Purpose |
|---|---|
| `[maradigma_boats]` | Boats catalog/listing (supports filters UI + pagination) |
| `[maradigma_boat_card]` | Render one boat using the editable Boat Card template system |
| `[maradigma_related_boats]` | Render related boats using the editable Boat Card template system |
| `[maradigma_boat]` | Single boat rendering via PHP template |
| `[maradigma_boat_field]` | Print any boat field returned by the API (raw/number/price) |
| `[maradigma_boat_prices]` | Render seasonal/base prices with cards/table layout and VAT display options |
| `[maradigma_boat_title]` | Prints the configurable full boat title: builder + model + alias by default |
| `[maradigma_boat_name]` | Convenience: prints boat name/alias |
| `[maradigma_boat_pax]` | Convenience: prints minimum pax/capacity |
| `[maradigma_boat_length]` | Convenience: prints boat length |
| `[maradigma_boat_beam]` | Convenience: prints boat beam |
| `[maradigma_boat_builder]` | Convenience: prints builder/shipyard |
| `[maradigma_boat_base_port]` | Convenience: prints base port name (if available in API payload) |
| `[maradigma_boat_main_image]` | Outputs the main `<img>` tag from boat images |
| `[maradigma_boat_description]` | Prints boat HTML description (prefers `description_html`) |
| `[maradigma_boat_booking]` | Booking CTA: button + modal + REST calls |

---

## 2. `[maradigma_boats]` — Boats listing

Renders a list of boats returned by the Maradigma API and optionally displays a filter form.  
The output is a grid of cards rendered by the selected **Boat Card template**.

### Basic usage

```text
[maradigma_boats]
```

### 2.1. Listing UI attributes (filters)

These attributes control **frontend UI** (filters form) and do not necessarily change API behavior unless the relevant filter values are provided.

| Attribute | Type | Default | Description |
|---|---:|---:|---|
| `show_filters` | `0/1` | `0` | Show the filters UI above the listing |
| `autosubmit_filters` | `0/1` | `0` | Auto-submit on change (client-side) |
| `allow_url_filters` | `0/1` | `0` | Allow `md_*` query parameters to override defaults |
| `filters_ui_fields` | CSV | *(plugin default)* | Which filter fields appear in the main row (allowlist enforced) |
| `filters_ui_fields_left` | CSV | *(empty)* | Fields that must stay in the left/main row. Useful to keep `order_by` before the apply button without losing the default right-side behavior elsewhere. |
| `filters_ui_fields_right` | CSV | auto | Which filter fields appear aligned to the right of the filter bar. If omitted, `order_by` keeps the legacy right-side position automatically. Set it to an empty string to keep all fields in the main row order. |
| `filters_ui_fields_offcanvas` | CSV | *(plugin default)* | Which filter fields appear inside the “More filters” offcanvas |
| `filters_ui_layout` | `horizontal/vertical` | `horizontal` | Layout class for the filters UI |
| `filters_ui_submit_mode` | `auto/button` | `auto` | If `button`, renders “Apply filters” button |
| `filters_ui_show_reset` | `0/1` | `1` | Render “Reset” button |
| `show_more_filters_button` | `0/1` | `0` | Shows an offcanvas “More filters” button |
| `more_filters_button_text` | string | `"More filters"` | Button label |
| `more_filters_offcanvas_title` | string | *(same as button)* | Offcanvas title |
| `date_picker_mode` | `range/separate` | `range` | `range` uses one input (`md_date_range`) + hidden start/end |

**Allowed UI field keys** (CSV values for `filters_ui_fields*`):

- `term`
- `boat_capacity`
- `featured`
- `ins_book`
- `order_by`
- `min_price`
- `max_price`  
  *(If both are present, the UI renders a single `price_range` control internally.)*
- `boat_type_id`
- `builders` *(multi-select, stored as CSV)*
- `tags` *(multi-select, stored as CSV)*
- `ids_gi`
- `date_start`
- `date_end`
- `boat_cabins` *(minimum cabins, `- / +` selector; `0` means no filter → `md_boat_cabins`)*
- `boat_bathrooms` *(minimum bathrooms, `- / +` selector; `0` means no filter → `md_boat_bathrooms`)*
- `boat_length` *(double-handle length slider in meters; the range comes from the shortest and longest boat of the listing → `md_min_boat_length` and `md_max_boat_length`, sent only when the slider is not on its full range)*
- `boat_skipper_option` *(“With skipper” / “Without skipper” checkboxes → `md_boat_skipper_option`. “With skipper” sends `0,2`, “Without skipper” sends `1,2`; boats with an optional skipper match both choices. Ticking both or none sends no filter.)*

`boat_capacity` is also rendered as a `- / +` selector inside the offcanvas (`0` means no filter).

> Any field not in the allowlist is ignored.

By default, `order_by` is rendered on the right side when included in `filters_ui_fields`.
You do not need to set `filters_ui_fields_right="order_by"` for the default layout.

To place `order_by` before the apply button, include it in `filters_ui_fields` and force it into the left/main row:

```text
[maradigma_boats show_filters="1" filters_ui_submit_mode="button" filters_ui_fields="term,builders,min_price,order_by,max_price" filters_ui_fields_left="order_by"]
```

### 2.2. Search/API attributes

These are passed to the API layer (after sanitization/normalization). Use them to set default filters from the shortcode itself.

> The plugin normalizes and validates attributes using `Sanitizer::normalizeBoatsSearchAtts()` and the canonical list in `ShortcodeRegistry::$DOC_SEARCH_BOATS_ATTRS`.

Below is a practical subset most commonly used by webmasters:

| Attribute | Type | Example | Notes |
|---|---:|---|---|
| `id_group` | string | `boats` | Always use `boats` for the boats catalog |
| `limit_services` | int | `12` | Results per page |
| `offset_services` | int | `0` | Base offset (advanced) |
| `term` | string | `ibiza` | Text search |
| `order_by` | int | `1` | `0=relevance, 1=price asc, 2=price desc, 6=length asc, 5=length desc, 3=featured first, 4=newest first` |
| `date_start` / `date_end` | `Y-m-d` | `2026-06-01` | Availability range |
| `ignore_date_range` | bool | `true` | Ignore date filters even if provided |
| `min_price` / `max_price` | float | `500` / `1500` | Price range |
| `featured` | bool/int | `1` | Featured boats only |
| `ins_book` | bool/int | `1` | Online-bookable boats only |
| `tags` | CSV int | `3,7` | Tag filter |
| `ids_gi` | CSV int | `304,305` | Force a specific manual selection |
| `boat_capacity` | int | `8` | Minimum pax |
| `boat_type_id` | int | `2` | Boat type |
| `destination` | int | `1704` | Destination ID: boats whose base port is in that destination (island, locality, region…). Same as `departure_location="destination:1704"` |
| `departure_location` | token | `destination:1704` / `port:12` | Destination or single base port, as the Maradigma API expects it |
| `builders` | CSV int | `10,12` | Builder ids (multi) |
| `builders_options` | `api/search_result` | `search_result` | Builder filter source. Use `api` for the global builders list. Use `search_result` to show only builders returned by the current API search response (`available_boat_id_builders`) |

You may also use the API’s broader set of boat-specific fields (builder, model, base port, cabins, licence flags, min/max length, etc.) as documented in the code.

### 2.2.1. Standard boat type IDs

Use these values with `boat_type_id`:

| ID | Boat type |
|---:|---|
| `41` | Catamaran |
| `3` | Motorboats |
| `5` | RIBs |
| `4` | Sailboats |
| `1` | Super yachts |
| `29` | Water toys |
| `2` | Yachts |

Examples:

```text
[maradigma_boats boat_type_id="3"]
[maradigma_boats show_filters="1" boat_type_id="41" filters_ui_fields="boat_type_id,boat_capacity,min_price,max_price"]
```

### 2.2.2. Boat type and destination

`destination` limits the listing to the boats whose base port belongs to a destination, and combines with `boat_type_id`, for example one page per boat type in each destination. Destination IDs come from the boats' `destinations` in the Maradigma API; the Elementor **Boats Archive** widget and the Gutenberg block list them in a searchable **Destination** field (**Listing defaults**), with the number of boats in each. Like the boat type, the destination stays applied when visitors filter or paginate the listing.

```text
[maradigma_boats boat_type_id="3" destination="1704"]
[maradigma_boats departure_location="port:12"]
```

### 2.3. Pagination

Pagination is controlled by the URL parameter:

- `?md_page=1` (first page)
- `?md_page=2` (second page)

The plugin computes:

- `limit_services` = per-page
- `offset_services` = base offset + (`md_page` - 1) × per-page

### 2.4. URL filters (`md_*`)

When `allow_url_filters="1"` is enabled, the shortcode reads filter values from the current URL using a **safe prefix**: `md_`.

Examples:

- `?md_term=sunseeker`
- `?md_min_price=500&md_max_price=1500`
- `?md_featured=1`
- `?md_ins_book=1`
- `?md_date_start=2026-06-01&md_date_end=2026-06-07`
- `?md_page=2`

The UI form also uses `md_*` names so the listing and filters stay in sync.

### 2.5. Examples

**1) Simple catalog (no filters UI):**
```text
[maradigma_boats limit_services="12" order_by="0"]
```

**2) Listing with filters UI + autosubmit + URL overrides:**
```text
[maradigma_boats show_filters="1" autosubmit_filters="1" allow_url_filters="1"]
```

**3) Fixed “Featured boats” section:**
```text
[maradigma_boats featured="1" limit_services="6" order_by="3"]
```

**4) “Last minute” landing page (date ignored, but you can still show filters):**
```text
[maradigma_boats last_minute_mode="1" ignore_date_range="1" show_filters="1"]
```

**5) Manual curated selection by IDs:**
```text
[maradigma_boats ids_gi="304,305,306" limit_services="12"]
```

**6) Use a specific card template:**
```text
[maradigma_boats card="compact" limit_services="12"]
```

**7) Builder filter limited to the current search result:**
```text
[maradigma_boats show_filters="1" filters_ui_fields="term,date_start,date_end,builders,min_price,max_price" builders_options="search_result"]
```

### 2.6. `[maradigma_boat_card]` - Single boat card

Renders one boat using the same editable Boat Card templates used by `[maradigma_boats]`.

```text
[maradigma_boat_card id="2773"]
[maradigma_boat_card id="2773" card="default"]
[maradigma_boat_card slug="karnic-sl602-valkirie" image_token="image_maradigma_card_471x273"]
```

Supported attributes:

| Attribute | Type | Default | Notes |
|---|---:|---:|---|
| `id` | string/int | empty | Maradigma boat ID |
| `slug` | string | empty | Boat slug; takes priority when provided |
| `card` | string | plugin default | Boat Card template ID |
| `image_token` | string | `image_main` | Cached image token, e.g. `image_maradigma_card_471x273` |
| `class` | string | empty | Extra wrapper CSS class |

### 2.7. `[maradigma_related_boats]` - Related boat cards

Renders a small set of related boats using the same editable Boat Card templates used by `[maradigma_boats]`.

It is designed to be used inside a boat page. If `id` or `slug` are not provided, the shortcode detects the current boat from the current WordPress post/page binding.

Aliases:

```text
[maradigma_related_boats]
[maradigma_boats_related]
[maradigma_barcos_relacionados]
```

Basic usage:

```text
[maradigma_related_boats]
```

Examples:

```text
[maradigma_related_boats count="4"]
[maradigma_related_boats limit="5" priorities="price,pax"]
[maradigma_related_boats id="2773" priority="pax,price,base_port"]
[maradigma_related_boats card="compact" image_token="image_maradigma_card_471x273"]
```

Supported attributes:

| Attribute | Type | Default | Notes |
|---|---:|---:|---|
| `id` | string/int | empty | Optional Maradigma boat ID. If empty, the shortcode uses the current boat context |
| `slug` | string | empty | Optional boat slug. Takes priority over `id` when provided |
| `count` | int | `3` | Number of related boats to show. Minimum `2`, maximum `5` |
| `limit` | int | empty | Alias/override for `count`. Also clamped between `2` and `5` |
| `priorities` | CSV | `price,pax` | Relationship priority order |
| `priority` | CSV | empty | Alias for `priorities`; when present it takes priority |
| `card` | string | plugin default | Boat Card template ID |
| `image_token` | string | `image_main` | Cached image token, e.g. `image_maradigma_card_471x273` |
| `title` | string | empty | Optional section title |
| `show_title` | `0/1` | `0` | Show the title when `title` is not empty |
| `empty_message` | string | empty | Optional message when no related boats are found |
| `class` | string | empty | Extra wrapper CSS class |

Supported relationship priorities:

| Priority | Meaning |
|---|---|
| `price` | Closest price. This is the first default priority |
| `pax` | Closest passenger capacity. This is the second default priority |
| `base_port` | Same base port when possible |
| `type` | Same boat/content type when possible |
| `builder` | Same builder/shipyard when possible |
| `length` | Closest boat length |
| `cabins` | Closest cabin count |
| `year` | Closest construction year |

Accepted aliases include Spanish labels such as `precio`, `pasajeros`, `personas`, `capacidad`, `puerto`, `tipo`, `astillero`, `eslora`, `cabinas`, `ano`, and `anio`.

Behavior notes:

- The current boat is always excluded from the results.
- The shortcode first tries local synced boat posts and their stored `_maradigma_boat_payload`.
- If no local candidates exist, it falls back to the API boats list through the plugin cache layer.
- In multilingual sites, synced boat pages are de-duplicated by Maradigma boat ID and the current language is preferred.

---

## 3. `[maradigma_boat]` — Single boat page

Renders a single boat using a PHP template (overridable from the theme).

### Usage

```text
[maradigma_boat slug="alfastreet-marine-28-sanfil"]
```

or:

```text
[maradigma_boat id="46"]
```

If the boat cannot be found, the shortcode returns a small “Boat not found.” message.

### 3.1. API detail options

The shortcode can request extra service detail data from the External API by passing safe API options. Unsupported `expand` values and unsupported scalar flags are ignored before the request is built.

Common use case: request the full images payload instead of the cover-only/default detail response.

```text
[maradigma_boat id="304" expand="service_images" only_load_cover_image="0"]
```

Multiple expands are accepted as CSV:

```text
[maradigma_boat id="304" expand="service_images,service_prices,service_descriptions" only_load_cover_image="0"]
```

Supported `expand` values:

- `service_accounting`
- `service_additional_services`
- `service_property_amenities`
- `service_admin_tools`
- `service_payment_methods`
- `service_descriptions`
- `service_destination`
- `service_equipments`
- `service_group_category`
- `service_images`
- `service_ical`
- `service_pdf`
- `service_owner`
- `service_included_items`
- `service_not_included_items`
- `service_prices`
- `service_price_rates`
- `service_price_time_slots`
- `service_public_urls`
- `service_unavailability_dates`
- `service_real_unavailable_dates`

Supported scalar API flags:

- `accounting`
- `additionals`
- `additionals_build_html`
- `amenities`
- `admin_tools`
- `build_method_payment`
- `descriptions`
- `equipments`
- `gc_type`
- `gc_type_cache`
- `images`
- `ical`
- `only_load_cover_image`
- `url_images_main_domain`
- `owner`
- `included`
- `not_included`
- `prices`
- `price_rates`
- `price_time_slots`
- `website_urls`

### 3.2. Overriding the template

The plugin searches for `single-boat.php` in the following order:

1. `wp-content/themes/<child-theme>/maradigma/single-boat.php`
2. `wp-content/themes/<parent-theme>/maradigma/single-boat.php`
3. `wp-content/plugins/maradigma/templates/single-boat.php` *(fallback)*

Inside the template, these variables are available:

- `$boat` (array) — full boat payload from the API
- `$atts` (array) — shortcode attributes
- `$settings` (array) — plugin settings
- `$language` (string) — language sent to the API

### 3.3. Examples

**Use inside a custom page template:**
```php
<?php echo do_shortcode('[maradigma_boat slug="my-boat-slug"]'); ?>
```

---

## 4. `[maradigma_boat_field]` — Generic field shortcode

Prints a single field from the boat payload. Useful for Elementor widgets, Gutenberg blocks, or text modules that support shortcodes.

### Usage

```text
[maradigma_boat_field slug="alfastreet-marine-28-sanfil" field="boat_capacity"]
```

or numeric formatting:

```text
[maradigma_boat_field id="46" field="boat_length" format="number" decimals="2"]
```

For prices:

```text
[maradigma_boat_field id="46" field="price_from" format="price" decimals="0"]
```

### Attributes

| Attribute | Default | Description |
|---|---|---|
| `slug` / `id` | `""` | One of them is required |
| `field` | `""` | Exact key from the API payload |
| `esc` | `true` | If `false`, outputs raw HTML (use only for trusted fields like `description_html`) |
| `fallback` | `""` | Output if field is missing/null |
| `format` | `raw` | `raw`, `number`, `price` |
| `decimals` | `0` | Used for `number` / `price` |

---

## 5. Sugar shortcodes (common fields)

These are convenience wrappers around `[maradigma_boat_field]`.

| Shortcode | Equivalent | Notes |
|---|---|---|
| `[maradigma_boat_title]` | `boat_builder + boat_model + "boat_alias"` | Configurable full boat title |
| `[maradigma_boat_name]` | `field="boat_alias"` | Boat display name |
| `[maradigma_boat_pax]` | `field="boat_capacity"` | Defaults to number formatting |
| `[maradigma_boat_length]` | `field="boat_length"` | Defaults `decimals="2"` |
| `[maradigma_boat_beam]` | `field="boat_beam"` | Defaults `decimals="2"` |
| `[maradigma_boat_builder]` | `field="boat_builder"` | Builder/shipyard name |
| `[maradigma_boat_base_port]` | `field="base_port_name"` | Requires API to provide this field |
| `[maradigma_boat_description]` | `field="description_html"` | Outputs HTML by default (`esc="false"`) |

### `[maradigma_boat_title]`

Builds a complete boat title from configurable parts. Default output is:

```text
boat_builder + boat_model + "boat_alias"
```

Example:

```text
[maradigma_boat_title id="304"]
```

Attributes:

| Attribute | Default | Description |
|---|---|---|
| `show_builder` | `1` | Include `boat_builder` |
| `show_model` | `1` | Include `boat_model` |
| `show_alias` | `1` | Include `boat_alias` |
| `quote_alias` | `1` | Wrap alias in double quotes |
| `fallback_service_name` | `1` | If all selected parts are empty, use `service_name` / `name` / `title` |
| `separator` | space | Separator between visible parts |
| `fallback` | `""` | Output if no title can be built |

### `[maradigma_boat_main_image]`

Outputs an `<img>` tag using the first image in the API payload.

```text
[maradigma_boat_main_image slug="..." size="1100" attr='class="img-fluid" loading="lazy"']
```

Attributes:

- `size` — key in `images[0].sizes` (falls back to `images[0].url`)
- `attr` — raw HTML attributes appended to `<img ...>`

---

## `[maradigma_boat_prices]` - Boat prices

Renders seasonal prices from `service_prices`. If no seasonal ranges exist, it falls back to base, week, and hour prices when available.

```text
[maradigma_boat_prices id="304"]
[maradigma_boat_prices slug="sunseeker-52" layout="table" show_headers="1"]
```

Attributes:

| Attribute | Default | Description |
|---|---|---|
| `slug` / `id` | `""` | Boat identifier. Context fallback is supported inside bound boat pages |
| `title` | `Prices` | Section title |
| `show_title` | `1` | Show/hide title |
| `fallback` / `empty_text` | `Prices not available.` | Empty state text |
| `layout` | `cards` | `cards` or `table`. Legacy `blocks` maps to `cards` |
| `range_mode` | `dates_short` | `dates_short`, `dates_long`, or `month` |
| `order_by` | `date_from_asc` | `date_from_asc`, `date_from_desc`, `price_asc`, `price_desc` |
| `show_headers` | `1` | Table headers, only relevant for `layout="table"` |
| `row_gap` | `10` | Card/table row gap in px |
| `vat_mode` | `included` | `included`, `excluded`, `hidden_base`, `hidden_total` |
| `vat_position` | `below` | `below`, `inline`, `tooltip` |
| `vat_text_included` | `VAT included` | Text shown for included VAT |
| `vat_text_excluded` | `+ VAT` | Text shown for excluded VAT |
| `currency_display` | `symbol` | `symbol` or `iso` |
| `decimals_mode` | `auto` | `auto`, `0`, or `2` |
| `thousands_sep` | `.` | Thousands separator |
| `decimal_sep` | `,` | Decimal separator |

Alias: `[maradigma_boat_price]`.

---
## 6. `[maradigma_boat_booking]` — Booking CTA + modal

Renders a “Book now” button that opens a booking modal. The modal UI is rendered by the shortcode and is driven by frontend JS.

### Usage

```text
[maradigma_boat_booking id="304" button_text="Book now"]
```

or:

```text
[maradigma_boat_booking slug="alfastreet-marine-28-sanfil"]
```

By default, the navigation buttons appear after the current step inside the
scrollable modal body. To keep them persistently visible in the modal footer:

```text
[maradigma_boat_booking id="304" buttons_position="footer"]
```

### Attributes

| Attribute | Default | Description |
|---|---|---|
| `id` / `slug` | `""` | Boat identifier. Context fallback is supported inside bound boat pages. |
| `button_text` | `Book now` | Label of the button that opens the modal. |
| `redirect_url_success` | `""` | Optional URL used after a successful booking/payment. |
| `calendar_display` | `inline` | `inline` or `popup`. |
| `calendar_months` | `1` | `1` or `2`. Inline calendars always use one month. |
| `calendar_selection_mode` | `range` | `range` or `single`. |
| `show_schedule_text` | `1` | Show the boat schedule text. |
| `show_promo_code` | `1` | Show the promotional code field. |
| `show_children_included` | `1` | Show the children-on-board option. |
| `free_additional_label` | `free` | `free` or `included`. |
| `buttons_position` | `inline` | `inline` places the actions after the current step inside the scrollable modal body; `footer` keeps them persistently visible in the modal footer. |

### How it works (high level)

- The shortcode outputs:
  - A button
  - The modal markup
  - Data attributes including:
    - Boat identifier
    - REST nonce (`X-WP-Nonce`)
    - REST endpoints for quote and booking

- The frontend script calls plugin REST endpoints (under `/wp-json/maradigma/v1`):
  - `POST /quote` — calculate a quote
  - `POST /booking` — create booking and obtain a payment URL (implementation dependent)

> The exact REST payload contract is defined by the plugin’s REST controllers.  
> If you customize endpoints, keep the HTML `data-*` attributes in sync.

### Notes

- The markup uses **Bootstrap-like modal patterns**, but the current implementation outputs its own `.md-modal` markup. Ensure the plugin’s booking assets are enqueued (CSS/JS).
- Multiple booking shortcodes on the same page are supported (unique DOM ids are generated).

---

## 7. Notes & troubleshooting

### Caching

- The plugin uses a `Cache` class (transients) for boats list and boat details.
- If content looks outdated, use the plugin admin action **“Clear plugin cache”**.

### Common issues

- **Empty listing**: verify API keys in plugin settings and confirm connection status.
- **Filters do nothing**: ensure `show_filters="1"` and optionally `allow_url_filters="1"`.
- **Dates not applied**: confirm `YYYY-MM-DD` format and ensure `ignore_date_range` is not enabled.
- **Template override not picked up**: confirm the file path is exactly `maradigma/single-boat.php` in the active theme (child theme takes priority).

---

*Maradigma — Boats & Bookings*  
Developer documentation for shortcodes.

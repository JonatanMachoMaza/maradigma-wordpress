/**
 * Pure helpers for the boats archive filters.
 *
 * They never touch the DOM or jQuery, so they can be unit tested with node:test.
 */

// Archive UI configuration is sent with every AJAX request but must never reach the address bar.
export const ARCHIVE_UI_CONFIG_PARAMS = [
  'filters_ui_fields',
  'filters_ui_fields_left',
  'filters_ui_fields_right',
  'filters_ui_fields_offcanvas',
  'filters_ui_layout',
  'filters_ui_submit_mode',
  'filters_ui_show_reset',
  'show_more_filters_button',
  'more_filters_button_text',
  'more_filters_offcanvas_title'
];

// Request-only parameters that do not belong in the address bar either.
const ARCHIVE_REQUEST_ONLY_PARAMS = [
  'archive_base_url',
  'archive_scope',
  'md_lang',
  'id_group',
  'limit_services',
  'offset_services',
  'card',
  'image_token',
  'date_picker_mode',
  'builders_options'
];

export function isArchiveUiConfigParam(paramName) {
  return ARCHIVE_UI_CONFIG_PARAMS.indexOf(String(paramName || '').trim()) !== -1;
}

/**
 * Builds the address-bar URL for a filter request: the current URL without the
 * previous md_* filters (and any leaked UI configuration), plus the visitor's
 * non-empty filters from the serialized request.
 */
export function buildBrowserUrl(currentHref, serializedData) {
  var urlObject = new URL(currentHref);

  Array.from(urlObject.searchParams.keys()).forEach(function (key) {
    if (String(key || '').indexOf('md_') === 0 || isArchiveUiConfigParam(key)) {
      urlObject.searchParams.delete(key);
    }
  });

  Object.keys(serializedData || {}).forEach(function (key) {
    if (ARCHIVE_REQUEST_ONLY_PARAMS.indexOf(key) !== -1 || isArchiveUiConfigParam(key)) {
      return;
    }

    var value = serializedData[key];

    if (Array.isArray(value)) {
      value.forEach(function (itemValue) {
        if (String(itemValue || '').trim() !== '') {
          urlObject.searchParams.append(key, String(itemValue));
        }
      });
      return;
    }

    if (String(value || '').trim() !== '') {
      urlObject.searchParams.set(key, String(value));
    }
  });

  return urlObject.toString();
}

/**
 * Moves a "- value +" selector by one step, keeping it between min and max.
 */
export function stepStepperValue(currentValue, delta, minValue, maxValue) {
  var current = parseInt(currentValue, 10);
  var min = parseInt(minValue, 10);
  var max = parseInt(maxValue, 10);

  if (isNaN(current)) current = 0;
  if (isNaN(min)) min = 0;
  if (isNaN(max)) max = current;

  return Math.min(Math.max(current + delta, min), max);
}

/**
 * Maps the "with / without skipper" checkboxes to the boat_skipper_option value.
 * Ticking both choices (or none) means no filter.
 */
export function buildSkipperOptionValue(withChecked, withoutChecked, codesWith, codesWithout) {
  if (!!withChecked === !!withoutChecked) {
    return '';
  }

  return String((withChecked ? codesWith : codesWithout) || '').trim();
}

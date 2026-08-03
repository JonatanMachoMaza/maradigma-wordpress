/* global jQuery */
import TomSelect from 'tom-select/dist/js/tom-select.complete.js';

(function ($) {
  'use strict';


  // ------------------------------------------------------------
  // DATE HELPERS
  // ------------------------------------------------------------

  function parseYmd(valueToParse) {
    valueToParse = String(valueToParse || '').trim();
    if (!/^\d{4}-\d{2}-\d{2}$/.test(valueToParse)) return null;

    var parts = valueToParse.split('-');
    var year = parseInt(parts[0], 10);
    var month = parseInt(parts[1], 10) - 1;
    var day = parseInt(parts[2], 10);

    var parsedDate = new Date(year, month, day);
    if (
      parsedDate.getFullYear() !== year ||
      parsedDate.getMonth() !== month ||
      parsedDate.getDate() !== day
    ) {
      return null;
    }

    parsedDate.setHours(0, 0, 0, 0);
    return parsedDate;
  }

  function parseDmy(valueToParse) {
    valueToParse = String(valueToParse || '').trim();
    if (!/^\d{2}-\d{2}-\d{4}$/.test(valueToParse)) return null;

    var parts = valueToParse.split('-');
    var day = parseInt(parts[0], 10);
    var month = parseInt(parts[1], 10) - 1;
    var year = parseInt(parts[2], 10);

    var parsedDate = new Date(year, month, day);
    if (
      parsedDate.getFullYear() !== year ||
      parsedDate.getMonth() !== month ||
      parsedDate.getDate() !== day
    ) {
      return null;
    }

    parsedDate.setHours(0, 0, 0, 0);
    return parsedDate;
  }

  function fmtYmd(dateObject) {
    if (!dateObject) return '';

    var year = dateObject.getFullYear();
    var month = String(dateObject.getMonth() + 1).padStart(2, '0');
    var day = String(dateObject.getDate()).padStart(2, '0');

    return year + '-' + month + '-' + day;
  }

  function fmtDmy(dateObject) {
    if (!dateObject) return '';

    var day = String(dateObject.getDate()).padStart(2, '0');
    var month = String(dateObject.getMonth() + 1).padStart(2, '0');
    var year = dateObject.getFullYear();

    return day + '-' + month + '-' + year;
  }

  function toInt(valueToNormalize, fallbackValue) {
    var parsedInteger = parseInt(valueToNormalize, 10);
    return isNaN(parsedInteger) ? fallbackValue : parsedInteger;
  }

  function toArray(valueToNormalize) {
    if (Array.isArray(valueToNormalize)) {
      return valueToNormalize;
    }

    if (valueToNormalize === null || typeof valueToNormalize === 'undefined') {
      return [];
    }

    return [valueToNormalize];
  }

  function uniqueNonEmptyStringArray(inputValues) {
    var seenMap = {};
    var normalizedValues = [];

    toArray(inputValues).forEach(function (rawValue) {
      var normalizedValue = String(rawValue || '').trim();
      if (!normalizedValue) {
        return;
      }
      if (seenMap[normalizedValue]) {
        return;
      }
      seenMap[normalizedValue] = true;
      normalizedValues.push(normalizedValue);
    });

    return normalizedValues;
  }

  function isPromiseLike(valueToCheck) {
    return !!valueToCheck && typeof valueToCheck.then === 'function';
  }

  // ------------------------------------------------------------
  // FORM SANITIZE BEFORE SUBMIT
  // ------------------------------------------------------------

  function markFieldNameForTemporaryRemoval($fieldElement) {
    if (
      !$fieldElement ||
      !$fieldElement.length ||
      $fieldElement.prop('disabled')
    ) {
      return;
    }

    var currentName = String($fieldElement.attr('name') || '').trim();
    if (!currentName) {
      return;
    }

    if (!$fieldElement.attr('data-md-original-name')) {
      $fieldElement.attr('data-md-original-name', currentName);
    }

    $fieldElement.removeAttr('name');
    $fieldElement.attr('data-md-temp-disabled-name', '1');
  }

  function restoreTemporarilyRemovedNames($formElement) {
    if (!$formElement || !$formElement.length) {
      return;
    }

    $formElement.find('[data-md-temp-disabled-name="1"]').each(function () {
      var $fieldElement = $(this);
      var originalName = String($fieldElement.attr('data-md-original-name') || '').trim();

      if (originalName) {
        $fieldElement.attr('name', originalName);
      }

      $fieldElement.removeAttr('data-md-temp-disabled-name');
    });
  }

  function isEffectivelyEmptyValue(valueToCheck) {
    return String(valueToCheck || '').trim() === '';
  }

  function shouldSkipFieldForSubmit($fieldElement) {
    if (!$fieldElement || !$fieldElement.length) {
      return false;
    }

    if ($fieldElement.prop('disabled')) {
      return false;
    }

    var fieldName = String($fieldElement.attr('name') || '').trim();
    if (!fieldName) {
      return false;
    }

    if (fieldName === 'md_page') {
      return false;
    }

    if ($fieldElement.is('input[type="checkbox"], input[type="radio"]')) {
      return !$fieldElement.is(':checked');
    }

    if ($fieldElement.is('select')) {
      var selectValue = $fieldElement.val();

      if (Array.isArray(selectValue)) {
        return selectValue.filter(function (itemValue) {
          return !isEffectivelyEmptyValue(itemValue);
        }).length === 0;
      }

      return isEffectivelyEmptyValue(selectValue);
    }

    return isEffectivelyEmptyValue($fieldElement.val());
  }

  function sanitizePriceRangeFieldsForSubmit($formElement) {
    if (!$formElement || !$formElement.length) {
      return;
    }

    $formElement.find('[data-md-price-range]').each(function () {
      var $rangeWrapper = $(this);
      var rangeMin = toInt($rangeWrapper.attr('data-range-min'), 0);
      var rangeMax = toInt($rangeWrapper.attr('data-range-max'), 5000);

      var $minInput = $rangeWrapper.find('input[name="md_min_price"], input[data-md-original-name="md_min_price"]').first();
      var $maxInput = $rangeWrapper.find('input[name="md_max_price"], input[data-md-original-name="md_max_price"]').first();

      if (!$minInput.length || !$maxInput.length) {
        return;
      }

      var currentMin = toInt($minInput.val(), rangeMin);
      var currentMax = toInt($maxInput.val(), rangeMax);

      var isDefaultFullRange = (currentMin === rangeMin && currentMax === rangeMax);

      if (isDefaultFullRange) {
        markFieldNameForTemporaryRemoval($minInput);
        markFieldNameForTemporaryRemoval($maxInput);
      }
    });
  }

  function sanitizeEmptyFieldsForSubmit($formElement) {
    if (!$formElement || !$formElement.length) {
      return;
    }

    $formElement.find('input, select, textarea').each(function () {
      var $fieldElement = $(this);

      if (shouldSkipFieldForSubmit($fieldElement)) {
        markFieldNameForTemporaryRemoval($fieldElement);
      }
    });
  }

  function sanitizeFormBeforeSubmit($formElement) {
    if (!$formElement || !$formElement.length) {
      return;
    }

    restoreTemporarilyRemovedNames($formElement);
    sanitizePriceRangeFieldsForSubmit($formElement);
    sanitizeEmptyFieldsForSubmit($formElement);
  }

  // ------------------------------------------------------------
  // AJAX ARCHIVE HELPERS
  // ------------------------------------------------------------

  function getArchiveRootFromForm($formElement) {
    if (!$formElement || !$formElement.length) {
      return $();
    }

    var targetSelector = String($formElement.attr('data-md-boats-archive-target') || '').trim();
    if (targetSelector) {
      try {
        var $targetElement = $(targetSelector).first();
        if ($targetElement.length) {
          return $targetElement;
        }
      } catch (targetSelectorError) {}
    }

    var $closestRoot = $formElement.closest('[data-md-boats-archive-root="1"]');
    if ($closestRoot.length) {
      return $closestRoot.first();
    }

    var $shortcodeRoot = $formElement.closest('.maradigma-boats-shortcode-list');
    if ($shortcodeRoot.length) {
      return $shortcodeRoot.first();
    }

    return $();
  }

  function getArchiveEndpointUrl($formElement, $archiveRoot) {
    var endpointUrl = '';

    if ($formElement && $formElement.length) {
      endpointUrl = String($formElement.attr('data-md-boats-archive-url') || '').trim();
    }

    if (!endpointUrl && $archiveRoot && $archiveRoot.length) {
      endpointUrl = String($archiveRoot.attr('data-md-boats-archive-url') || '').trim();
    }

    return endpointUrl;
  }

  function isAjaxArchiveEnabled($formElement) {
    var $archiveRoot = getArchiveRootFromForm($formElement);
    var endpointUrl = getArchiveEndpointUrl($formElement, $archiveRoot);

    return endpointUrl !== '';
  }

  function isArchiveLoading($archiveRoot) {
    if (!$archiveRoot || !$archiveRoot.length) {
      return false;
    }

    return $archiveRoot.attr('data-md-loading') === '1';
  }

function ensureSkeletonStylesInjected() {
  if (document.getElementById('md-archive-skeleton-styles')) {
    return;
  }

  var styleElement = document.createElement('style');
  styleElement.id = 'md-archive-skeleton-styles';
  styleElement.textContent =
    '[data-md-boats-archive-root="1"], .maradigma-boats-shortcode-list{position:relative}' +

    '.md-loading-blocker{' +
      'position:absolute;' +
      'inset:0;' +
      'z-index:9999;' +
      'background:rgba(255,255,255,.10);' +
      'backdrop-filter:saturate(120%) blur(1px);' +
      'cursor:progress;' +
      'display:none;' +
    '}' +

    '.md-is-disabled-link{' +
      'pointer-events:none !important;' +
      'cursor:not-allowed !important;' +
    '}' +

    '.md-is-disabled-control{' +
      'cursor:not-allowed !important;' +
    '}' +

    '[data-md-boats-archive-root="1"].is-loading [data-md-boats-archive-results="1"]{' +
      'position:relative;' +
    '}' +

    '[data-md-boats-archive-root="1"].is-loading .maradigma-boat-card,' +
    '.maradigma-boats-shortcode-list.is-loading .maradigma-boat-card{' +
      'position:relative;' +
      'overflow:hidden;' +
      'pointer-events:none !important;' +
    '}' +

    '[data-md-boats-archive-root="1"].is-loading .maradigma-boat-card::after,' +
    '.maradigma-boats-shortcode-list.is-loading .maradigma-boat-card::after{' +
      'content:"";' +
      'position:absolute;' +
      'inset:0;' +
      'border-radius:inherit;' +
      'background:linear-gradient(90deg, rgba(255,255,255,0) 0%, rgba(255,255,255,.45) 50%, rgba(255,255,255,0) 100%);' +
      'transform:translateX(-100%);' +
      'animation:mdSkeletonShimmer 1.2s ease-in-out infinite;' +
      'z-index:3;' +
    '}' +

    '[data-md-boats-archive-root="1"].is-loading .maradigma-boat-card__img,' +
    '.maradigma-boats-shortcode-list.is-loading .maradigma-boat-card__img{' +
      'filter:grayscale(1) brightness(1.05);' +
      'opacity:.35;' +
    '}' +

    '[data-md-boats-archive-root="1"].is-loading .maradigma-boat-card__title,' +
    '[data-md-boats-archive-root="1"].is-loading .maradigma-boat-card__subtitle,' +
    '[data-md-boats-archive-root="1"].is-loading .maradigma-bullet span,' +
    '[data-md-boats-archive-root="1"].is-loading .maradigma-boat-card__price,' +
    '[data-md-boats-archive-root="1"].is-loading .maradigma-boat-card__cta,' +
    '.maradigma-boats-shortcode-list.is-loading .maradigma-boat-card__title,' +
    '.maradigma-boats-shortcode-list.is-loading .maradigma-boat-card__subtitle,' +
    '.maradigma-boats-shortcode-list.is-loading .maradigma-bullet span,' +
    '.maradigma-boats-shortcode-list.is-loading .maradigma-boat-card__price,' +
    '.maradigma-boats-shortcode-list.is-loading .maradigma-boat-card__cta{' +
      'opacity:.18 !important;' +
    '}' +

    '[data-md-boats-archive-root="1"].is-loading .maradigma-boat-card__media,' +
    '[data-md-boats-archive-root="1"].is-loading .maradigma-boat-card__body,' +
    '.maradigma-boats-shortcode-list.is-loading .maradigma-boat-card__media,' +
    '.maradigma-boats-shortcode-list.is-loading .maradigma-boat-card__body{' +
      'position:relative;' +
      'z-index:1;' +
    '}' +

    '[data-md-boats-archive-root="1"].is-loading .maradigma-pagination a,' +
    '[data-md-boats-archive-root="1"].is-loading .maradigma-pagination .page-numbers,' +
    '.maradigma-boats-shortcode-list.is-loading .maradigma-pagination a,' +
    '.maradigma-boats-shortcode-list.is-loading .maradigma-pagination .page-numbers{' +
      'pointer-events:none !important;' +
      'opacity:.5;' +
      'cursor:not-allowed !important;' +
    '}' +

    '[data-md-boats-archive-root="1"].is-loading form.maradigma-boats-filters,' +
    '.maradigma-boats-shortcode-list.is-loading form.maradigma-boats-filters{' +
      'pointer-events:none !important;' +
      'opacity:.85;' +
    '}' +

    '@keyframes mdSkeletonShimmer{' +
      '100%{transform:translateX(100%)}' +
    '}';

  document.head.appendChild(styleElement);
}

  function ensureArchiveRootPositionContext($archiveRoot) {
    if (!$archiveRoot || !$archiveRoot.length) {
      return;
    }

    var currentPosition = window.getComputedStyle($archiveRoot[0]).position;
    if (currentPosition === 'static') {
      $archiveRoot.attr('data-md-added-position-relative', '1');
      $archiveRoot.css('position', 'relative');
    }
  }

  function ensureLoadingBlocker($archiveRoot) {
    if (!$archiveRoot || !$archiveRoot.length) {
      return $();
    }

    ensureSkeletonStylesInjected();
    ensureArchiveRootPositionContext($archiveRoot);

    var $blocker = $archiveRoot.children('[data-md-loading-blocker="1"]').first();

    if (!$blocker.length) {
      $blocker = $('<div class="md-loading-blocker" data-md-loading-blocker="1" aria-hidden="true"></div>');
      $blocker.hide();
      $archiveRoot.append($blocker);
    }

    return $blocker;
  }

  function setLinksDisabled($scope, isDisabled) {
    if (!$scope || !$scope.length) {
      return;
    }

    $scope.find('a').each(function () {
      var $link = $(this);

      if (isDisabled) {
        if (!$link.attr('data-md-disabled-href')) {
          $link.attr('data-md-disabled-href', String($link.attr('href') || ''));
        }

        if (!$link.attr('data-md-disabled-tabindex') && $link.attr('tabindex') !== undefined) {
          $link.attr('data-md-disabled-tabindex', String($link.attr('tabindex')));
        }

        $link.removeAttr('href');
        $link.attr('aria-disabled', 'true');
        $link.attr('tabindex', '-1');
        $link.addClass('md-is-disabled-link');
        return;
      }

      if ($link.attr('data-md-disabled-href') !== undefined) {
        var originalHref = String($link.attr('data-md-disabled-href') || '').trim();
        if (originalHref !== '') {
          $link.attr('href', originalHref);
        } else {
          $link.removeAttr('href');
        }
        $link.removeAttr('data-md-disabled-href');
      }

      if ($link.attr('data-md-disabled-tabindex') !== undefined) {
        $link.attr('tabindex', $link.attr('data-md-disabled-tabindex'));
        $link.removeAttr('data-md-disabled-tabindex');
      } else {
        $link.removeAttr('tabindex');
      }

      $link.removeAttr('aria-disabled');
      $link.removeClass('md-is-disabled-link');
    });
  }

  function setFormControlsDisabled($scope, isDisabled) {
    if (!$scope || !$scope.length) {
      return;
    }

    $scope.find('button, input, select, textarea').each(function () {
      var $element = $(this);

      if (isDisabled) {
        if ($element.prop('disabled')) {
          $element.attr('data-md-disabled-original', '1');
        } else {
          $element.attr('data-md-disabled-original', '0');
          $element.prop('disabled', true);
        }

        $element.addClass('md-is-disabled-control');
        return;
      }

      var originalDisabledState = String($element.attr('data-md-disabled-original') || '');

      if (originalDisabledState === '0') {
        $element.prop('disabled', false);
      }

      $element.removeAttr('data-md-disabled-original');
      $element.removeClass('md-is-disabled-control');
    });
  }

  function setInteractiveElementsDisabled($archiveRoot, isDisabled) {
    if (!$archiveRoot || !$archiveRoot.length) {
      return;
    }

    var $formElement = $archiveRoot.find('form.maradigma-boats-filters').first();
    var $paginationElement = findArchivePaginationElement($archiveRoot);

    if ($formElement.length) {
      setFormControlsDisabled($formElement, isDisabled);
      setLinksDisabled($formElement, isDisabled);
    }

    if ($paginationElement.length) {
      setLinksDisabled($paginationElement, isDisabled);
      setFormControlsDisabled($paginationElement, isDisabled);
    }
  }

  function renderArchiveLoadingSkeletons($archiveRoot) {
    if (!$archiveRoot || !$archiveRoot.length) {
      return;
    }

    ensureSkeletonStylesInjected();

    var $resultsElement = findArchiveResultsElement($archiveRoot);
    if (!$resultsElement.length) {
      return;
    }

    if ($resultsElement.attr('data-md-loading-skeleton-ready') === '1') {
      return;
    }

    $resultsElement.attr('data-md-loading-skeleton-ready', '1');
  }

  function setArchiveLoadingState($archiveRoot, isLoading) {
    if (!$archiveRoot || !$archiveRoot.length) {
      return;
    }

    var $blocker = ensureLoadingBlocker($archiveRoot);

    if (isLoading) {
      $archiveRoot.attr('data-md-loading', '1');
      $archiveRoot.attr('aria-busy', 'true');
      $archiveRoot.addClass('is-loading');

      setInteractiveElementsDisabled($archiveRoot, true);
      renderArchiveLoadingSkeletons($archiveRoot);

      if ($blocker.length) {
        $blocker.show();
      }

      return;
    }

    $archiveRoot.removeAttr('data-md-loading');
    $archiveRoot.removeAttr('aria-busy');
    $archiveRoot.removeClass('is-loading');

    setInteractiveElementsDisabled($archiveRoot, false);

    if ($blocker.length) {
      $blocker.hide();
    }
  }

  function findArchiveResultsElement($archiveRoot) {
    if (!$archiveRoot || !$archiveRoot.length) {
      return $();
    }

    return $archiveRoot.find('[data-md-boats-archive-results="1"]').first();
  }

  function findArchivePaginationElement($archiveRoot) {
    if (!$archiveRoot || !$archiveRoot.length) {
      return $();
    }

    return $archiveRoot.find('[data-md-boats-archive-pagination="1"]').first();
  }

  function findArchiveCountElement($archiveRoot) {
    if (!$archiveRoot || !$archiveRoot.length) {
      return $();
    }

    return $archiveRoot.find('[data-md-boats-archive-count="1"]').first();
  }

  function findArchiveEmptyElement($archiveRoot) {
    if (!$archiveRoot || !$archiveRoot.length) {
      return $();
    }

    return $archiveRoot.find('[data-md-boats-archive-empty="1"]').first();
  }

  function buildQueryStringFromObject(dataObject) {
    var searchParams = new URLSearchParams();

    Object.keys(dataObject || {}).forEach(function (key) {
      var value = dataObject[key];

      if (Array.isArray(value)) {
        value.forEach(function (itemValue) {
          searchParams.append(key, String(itemValue || ''));
        });
        return;
      }

      searchParams.append(key, String(value || ''));
    });

    return searchParams.toString();
  }

  function buildArchiveBaseUrl() {
    var urlObject = new URL(window.location.href);

    return urlObject.pathname;
  }

  function isArchiveAjaxParamAllowed(paramName) {
    paramName = String(paramName || '').trim();

    if (paramName.indexOf('md_') === 0) {
      return true;
    }

    return (
      paramName === 'id_group' ||
      paramName === 'limit_services' ||
      paramName === 'offset_services' ||
      paramName === 'card' ||
      paramName === 'image_token' ||
      paramName === 'date_picker_mode' ||
      paramName === 'builders_options' ||
      paramName === 'filters_ui_fields' ||
      paramName === 'filters_ui_fields_left' ||
      paramName === 'filters_ui_fields_right' ||
      paramName === 'filters_ui_fields_offcanvas' ||
      paramName === 'filters_ui_layout' ||
      paramName === 'filters_ui_submit_mode' ||
      paramName === 'filters_ui_show_reset' ||
      paramName === 'show_more_filters_button' ||
      paramName === 'more_filters_button_text' ||
      paramName === 'more_filters_offcanvas_title' ||
      paramName === 'archive_base_url'
    );
  }

  function updateBrowserUrlFromFormData(serializedData) {
    var urlObject = new URL(window.location.href);

    Array.from(urlObject.searchParams.keys()).forEach(function (key) {
      if (String(key || '').indexOf('md_') === 0) {
        urlObject.searchParams.delete(key);
      }
    });

    Object.keys(serializedData || {}).forEach(function (key) {
      var value = serializedData[key];

      if (key === 'archive_base_url' || key === 'md_lang') {
        return;
      }

      if (
        key === 'id_group' ||
        key === 'limit_services' ||
        key === 'offset_services' ||
        key === 'card' ||
        key === 'image_token' ||
        key === 'date_picker_mode' ||
        key === 'builders_options'
      ) {
        return;
      }

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

    window.history.replaceState({}, '', urlObject.toString());
  }

  function normalizeArchiveResponse(rawResponse) {
    if (!rawResponse || typeof rawResponse !== 'object') {
      return null;
    }

    if (
      rawResponse.success === true &&
      rawResponse.data &&
      typeof rawResponse.data === 'object'
    ) {
      return rawResponse.data;
    }

    if (
      rawResponse.success === true &&
      (
        Object.prototype.hasOwnProperty.call(rawResponse, 'results_html') ||
        Object.prototype.hasOwnProperty.call(rawResponse, 'pagination_html') ||
        Object.prototype.hasOwnProperty.call(rawResponse, 'total_results')
      )
    ) {
      return rawResponse;
    }

    if (rawResponse.data && typeof rawResponse.data === 'object') {
      return rawResponse.data;
    }

    return rawResponse;
  }

  function getResponseValue(responseObject, possibleKeys) {
    var foundValue = null;

    possibleKeys.some(function (keyName) {
      if (Object.prototype.hasOwnProperty.call(responseObject, keyName)) {
        foundValue = responseObject[keyName];
        return true;
      }
      return false;
    });

    return foundValue;
  }

  function buildSerializedFormData($formElement) {
    restoreTemporarilyRemovedNames($formElement);
    sanitizeFormBeforeSubmit($formElement);

    var serializedArray = $formElement.serializeArray();
    var serializedData = {};

    serializedArray.forEach(function (itemObject) {
      var key = String(itemObject.name || '').trim();
      var value = itemObject.value;

      if (!key) {
        return;
      }

      if (!isArchiveAjaxParamAllowed(key)) {
        return;
      }

      if (Object.prototype.hasOwnProperty.call(serializedData, key)) {
        if (!Array.isArray(serializedData[key])) {
          serializedData[key] = [serializedData[key]];
        }

        serializedData[key].push(value);
        return;
      }

      serializedData[key] = value;
    });

    var $archiveRoot = getArchiveRootFromForm($formElement);

    if ($archiveRoot.length) {
      var limitValue = String($archiveRoot.attr('data-md-archive-limit') || '').trim();
      var orderByValue = String($archiveRoot.attr('data-md-archive-order-by') || '').trim();
      var cardValue = String($archiveRoot.attr('data-md-archive-card') || '').trim();
      var currentLangValue = String($archiveRoot.attr('data-md-archive-current-lang') || '').trim();
      var buildersOptionsValue = String($archiveRoot.attr('data-md-archive-builders-options') || '').trim();
      var filtersUiFieldsValue = String($archiveRoot.attr('data-md-archive-filters-ui-fields') || '').trim();
      var filtersUiFieldsLeftValue = String($archiveRoot.attr('data-md-archive-filters-ui-fields-left') || '').trim();
      var filtersUiFieldsRightValue = String($archiveRoot.attr('data-md-archive-filters-ui-fields-right') || '').trim();
      var filtersUiFieldsOffcanvasValue = String($archiveRoot.attr('data-md-archive-filters-ui-fields-offcanvas') || '').trim();
      var filtersUiLayoutValue = String($archiveRoot.attr('data-md-archive-filters-ui-layout') || '').trim();
      var filtersUiSubmitModeValue = String($archiveRoot.attr('data-md-archive-filters-ui-submit-mode') || '').trim();
      var filtersUiShowResetValue = String($archiveRoot.attr('data-md-archive-filters-ui-show-reset') || '').trim();
      var showMoreFiltersButtonValue = String($archiveRoot.attr('data-md-archive-show-more-filters-button') || '').trim();
      var moreFiltersButtonTextValue = String($archiveRoot.attr('data-md-archive-more-filters-button-text') || '').trim();
      var moreFiltersOffcanvasTitleValue = String($archiveRoot.attr('data-md-archive-more-filters-offcanvas-title') || '').trim();

      if (limitValue !== '') {
        serializedData.limit_services = limitValue;
      }

      if (
        orderByValue !== '' &&
        !Object.prototype.hasOwnProperty.call(serializedData, 'md_order_by')
      ) {
        serializedData.md_order_by = orderByValue;
      }

      if (cardValue !== '') {
        serializedData.card = cardValue;
      }

      if (currentLangValue !== '') {
        serializedData.md_lang = currentLangValue;
      }

      if (buildersOptionsValue !== '') {
        serializedData.builders_options = buildersOptionsValue;
      }

      serializedData.filters_ui_fields = filtersUiFieldsValue;
      serializedData.filters_ui_fields_left = filtersUiFieldsLeftValue;
      serializedData.filters_ui_fields_right = filtersUiFieldsRightValue;
      serializedData.filters_ui_fields_offcanvas = filtersUiFieldsOffcanvasValue;

      if (filtersUiLayoutValue !== '') {
        serializedData.filters_ui_layout = filtersUiLayoutValue;
      }

      if (filtersUiSubmitModeValue !== '') {
        serializedData.filters_ui_submit_mode = filtersUiSubmitModeValue;
      }

      if (filtersUiShowResetValue !== '') {
        serializedData.filters_ui_show_reset = filtersUiShowResetValue;
      }

      if (showMoreFiltersButtonValue !== '') {
        serializedData.show_more_filters_button = showMoreFiltersButtonValue;
      }

      if (moreFiltersButtonTextValue !== '') {
        serializedData.more_filters_button_text = moreFiltersButtonTextValue;
      }

      if (moreFiltersOffcanvasTitleValue !== '') {
        serializedData.more_filters_offcanvas_title = moreFiltersOffcanvasTitleValue;
      }
    }

    serializedData.archive_base_url = buildArchiveBaseUrl();

    restoreTemporarilyRemovedNames($formElement);

    return serializedData;
  }

  function updateArchiveMarkup($archiveRoot, normalizedResponse) {
    if (!$archiveRoot || !$archiveRoot.length || !normalizedResponse) {
      return;
    }

    var $resultsElement = findArchiveResultsElement($archiveRoot);
    var $paginationElement = findArchivePaginationElement($archiveRoot);
    var $countElement = findArchiveCountElement($archiveRoot);
    var $emptyElement = findArchiveEmptyElement($archiveRoot);

    var resultsHtml = getResponseValue(normalizedResponse, [
      'results_html',
      'html',
      'cards_html',
      'archive_html'
    ]);

    var paginationHtml = getResponseValue(normalizedResponse, [
      'pagination_html',
      'pagination'
    ]);

    var totalResults = getResponseValue(normalizedResponse, [
      'total_results',
      'found_posts',
      'count_group_items',
      'count'
    ]);

    if ($resultsElement.length && typeof resultsHtml === 'string') {
      $resultsElement.replaceWith(resultsHtml);
    }

    if ($paginationElement.length && typeof paginationHtml === 'string') {
      $paginationElement.replaceWith(paginationHtml);
    } else if (!$paginationElement.length && typeof paginationHtml === 'string') {
      var $updatedResultsElement = $archiveRoot.find('[data-md-boats-archive-results="1"]').first();
      if ($updatedResultsElement.length) {
        $updatedResultsElement.after(paginationHtml);
      } else {
        $archiveRoot.append(paginationHtml);
      }
    }

    if ($countElement.length && totalResults !== null && typeof totalResults !== 'undefined') {
      $countElement.text(String(totalResults));
    }

    if ($emptyElement.length && totalResults !== null && typeof totalResults !== 'undefined') {
      if (toInt(totalResults, 0) > 0) {
        $emptyElement.hide();
      } else {
        $emptyElement.show();
      }
    } else if (totalResults !== null && typeof totalResults !== 'undefined') {
      $archiveRoot.toggleClass('is-empty', toInt(totalResults, 0) <= 0);
    }
  }

  function requestArchiveUpdate($formElement, optionsObject) {
    optionsObject = optionsObject || {};

    if (!$formElement || !$formElement.length) {
      return null;
    }

    var $archiveRoot = getArchiveRootFromForm($formElement);
    var endpointUrl = getArchiveEndpointUrl($formElement, $archiveRoot);

    if (!endpointUrl) {
      return null;
    }

    if (isArchiveLoading($archiveRoot)) {
      return null;
    }

    var serializedData = buildSerializedFormData($formElement);
    var requestMethod = String($formElement.attr('data-md-boats-archive-method') || 'GET').trim().toUpperCase();
    var abortController = window.AbortController ? new window.AbortController() : null;

    if ($archiveRoot.length) {
      var previousAbortController = $archiveRoot.data('mdArchiveAbortController');
      if (previousAbortController && typeof previousAbortController.abort === 'function') {
        try {
          previousAbortController.abort();
        } catch (abortError) {}
      }

      if (abortController) {
        $archiveRoot.data('mdArchiveAbortController', abortController);
      }
    }

    updateBrowserUrlFromFormData(serializedData);
    setArchiveLoadingState($archiveRoot, true);

    if ($archiveRoot.length) {
      $archiveRoot.trigger('md:archive:beforeRequest', [serializedData]);
    }

    var fetchUrl = endpointUrl;
    var fetchOptions = {
      method: requestMethod,
      credentials: 'same-origin',
      headers: {
        'X-Requested-With': 'XMLHttpRequest'
      }
    };

    if (abortController) {
      fetchOptions.signal = abortController.signal;
    }

    if (requestMethod === 'POST') {
      fetchOptions.headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
      fetchOptions.body = buildQueryStringFromObject(serializedData);
    } else {
      var queryString = buildQueryStringFromObject(serializedData);
      if (queryString) {
        fetchUrl += (fetchUrl.indexOf('?') >= 0 ? '&' : '?') + queryString;
      }
    }

    return window.fetch(fetchUrl, fetchOptions)
      .then(function (responseObject) {
        if (!responseObject.ok) {
          throw new Error('HTTP ' + responseObject.status);
        }

        return responseObject.json();
      })
      .then(function (rawResponse) {
        var normalizedResponse = normalizeArchiveResponse(rawResponse);

        if (!normalizedResponse) {
          throw new Error('Invalid archive response');
        }

        updateArchiveMarkup($archiveRoot, normalizedResponse);
        boot($archiveRoot[0]);

        if ($archiveRoot.length) {
          $archiveRoot.trigger('md:archive:afterRender', [normalizedResponse, serializedData]);
        }

        if (optionsObject.scrollToResults !== false && $archiveRoot.length) {
          var archiveTop = $archiveRoot.offset();
          if (archiveTop && typeof archiveTop.top !== 'undefined') {
            window.scrollTo({
              top: Math.max(archiveTop.top - 40, 0),
              behavior: 'smooth'
            });
          }
        }

        return normalizedResponse;
      })
      .catch(function (requestError) {
        if (requestError && requestError.name === 'AbortError') {
          return null;
        }

        if ($archiveRoot.length) {
          $archiveRoot.trigger('md:archive:error', [requestError]);
        }

        if (optionsObject && optionsObject.fallbackToNative === true) {
          if ($formElement[0] && typeof $formElement[0].submit === 'function') {
            $formElement[0].submit();
          }
        }

        throw requestError;
      })
      .finally(function () {
        setArchiveLoadingState($archiveRoot, false);

        if ($archiveRoot.length) {
          $archiveRoot.removeData('mdArchiveAbortController');
        }
      });
  }

  function appendSyntheticArchiveField($formElement, key, value) {
    if (!$formElement || !$formElement.length) {
      return;
    }

    var normalizedKey = String(key || '').trim();
    if (!normalizedKey) {
      return;
    }

    $('<input type="hidden" />')
      .attr('name', normalizedKey)
      .val(String(value || ''))
      .appendTo($formElement);
  }

  function buildArchiveRequestDataFromUrl(hrefValue, $archiveRoot) {
    var serializedData = {};
    var currentUrl;
    var targetUrl;

    try {
      currentUrl = new URL(window.location.href);
      targetUrl = new URL(String(hrefValue || ''), window.location.href);
    } catch (urlError) {
      return serializedData;
    }

    function assignParamValue(key, value) {
      if (!isArchiveAjaxParamAllowed(key)) {
        return;
      }

      if (Object.prototype.hasOwnProperty.call(serializedData, key)) {
        if (!Array.isArray(serializedData[key])) {
          serializedData[key] = [serializedData[key]];
        }

        serializedData[key].push(value);
        return;
      }

      serializedData[key] = value;
    }

    currentUrl.searchParams.forEach(function (value, key) {
      assignParamValue(key, value);
    });

    targetUrl.searchParams.forEach(function (value, key) {
      if (!isArchiveAjaxParamAllowed(key)) {
        return;
      }

      if (Object.prototype.hasOwnProperty.call(serializedData, key)) {
        delete serializedData[key];
      }

      assignParamValue(key, value);
    });

    if ($archiveRoot && $archiveRoot.length) {
      var idGroupValue = String($archiveRoot.attr('data-md-archive-id-group') || '').trim();
      var limitValue = String($archiveRoot.attr('data-md-archive-limit') || '').trim();
      var offsetValue = String($archiveRoot.attr('data-md-archive-offset') || '').trim();
      var orderByValue = String($archiveRoot.attr('data-md-archive-order-by') || '').trim();
      var cardValue = String($archiveRoot.attr('data-md-archive-card') || '').trim();
      var imageTokenValue = String($archiveRoot.attr('data-md-archive-image-token') || '').trim();
      var dateModeValue = String($archiveRoot.attr('data-md-archive-date-mode') || '').trim();
      var currentLangValue = String($archiveRoot.attr('data-md-archive-current-lang') || '').trim();
      var buildersOptionsValue = String($archiveRoot.attr('data-md-archive-builders-options') || '').trim();
      var filtersUiFieldsValue = String($archiveRoot.attr('data-md-archive-filters-ui-fields') || '').trim();
      var filtersUiFieldsLeftValue = String($archiveRoot.attr('data-md-archive-filters-ui-fields-left') || '').trim();
      var filtersUiFieldsRightValue = String($archiveRoot.attr('data-md-archive-filters-ui-fields-right') || '').trim();
      var filtersUiFieldsOffcanvasValue = String($archiveRoot.attr('data-md-archive-filters-ui-fields-offcanvas') || '').trim();
      var filtersUiLayoutValue = String($archiveRoot.attr('data-md-archive-filters-ui-layout') || '').trim();
      var filtersUiSubmitModeValue = String($archiveRoot.attr('data-md-archive-filters-ui-submit-mode') || '').trim();
      var filtersUiShowResetValue = String($archiveRoot.attr('data-md-archive-filters-ui-show-reset') || '').trim();
      var showMoreFiltersButtonValue = String($archiveRoot.attr('data-md-archive-show-more-filters-button') || '').trim();
      var moreFiltersButtonTextValue = String($archiveRoot.attr('data-md-archive-more-filters-button-text') || '').trim();
      var moreFiltersOffcanvasTitleValue = String($archiveRoot.attr('data-md-archive-more-filters-offcanvas-title') || '').trim();

      if (idGroupValue !== '' && !Object.prototype.hasOwnProperty.call(serializedData, 'id_group')) {
        serializedData.id_group = idGroupValue;
      }

      if (limitValue !== '' && !Object.prototype.hasOwnProperty.call(serializedData, 'limit_services')) {
        serializedData.limit_services = limitValue;
      }

      if (offsetValue !== '' && !Object.prototype.hasOwnProperty.call(serializedData, 'offset_services')) {
        serializedData.offset_services = offsetValue;
      }

      if (orderByValue !== '' && !Object.prototype.hasOwnProperty.call(serializedData, 'md_order_by')) {
        serializedData.md_order_by = orderByValue;
      }

      if (cardValue !== '' && !Object.prototype.hasOwnProperty.call(serializedData, 'card')) {
        serializedData.card = cardValue;
      }

      if (imageTokenValue !== '' && !Object.prototype.hasOwnProperty.call(serializedData, 'image_token')) {
        serializedData.image_token = imageTokenValue;
      }

      if (dateModeValue !== '' && !Object.prototype.hasOwnProperty.call(serializedData, 'date_picker_mode')) {
        serializedData.date_picker_mode = dateModeValue;
      }

      if (currentLangValue !== '' && !Object.prototype.hasOwnProperty.call(serializedData, 'md_lang')) {
        serializedData.md_lang = currentLangValue;
      }

      if (buildersOptionsValue !== '' && !Object.prototype.hasOwnProperty.call(serializedData, 'builders_options')) {
        serializedData.builders_options = buildersOptionsValue;
      }

      if (!Object.prototype.hasOwnProperty.call(serializedData, 'filters_ui_fields')) {
        serializedData.filters_ui_fields = filtersUiFieldsValue;
      }

      if (!Object.prototype.hasOwnProperty.call(serializedData, 'filters_ui_fields_left')) {
        serializedData.filters_ui_fields_left = filtersUiFieldsLeftValue;
      }

      if (!Object.prototype.hasOwnProperty.call(serializedData, 'filters_ui_fields_right')) {
        serializedData.filters_ui_fields_right = filtersUiFieldsRightValue;
      }

      if (!Object.prototype.hasOwnProperty.call(serializedData, 'filters_ui_fields_offcanvas')) {
        serializedData.filters_ui_fields_offcanvas = filtersUiFieldsOffcanvasValue;
      }

      if (filtersUiLayoutValue !== '' && !Object.prototype.hasOwnProperty.call(serializedData, 'filters_ui_layout')) {
        serializedData.filters_ui_layout = filtersUiLayoutValue;
      }

      if (filtersUiSubmitModeValue !== '' && !Object.prototype.hasOwnProperty.call(serializedData, 'filters_ui_submit_mode')) {
        serializedData.filters_ui_submit_mode = filtersUiSubmitModeValue;
      }

      if (filtersUiShowResetValue !== '' && !Object.prototype.hasOwnProperty.call(serializedData, 'filters_ui_show_reset')) {
        serializedData.filters_ui_show_reset = filtersUiShowResetValue;
      }

      if (showMoreFiltersButtonValue !== '' && !Object.prototype.hasOwnProperty.call(serializedData, 'show_more_filters_button')) {
        serializedData.show_more_filters_button = showMoreFiltersButtonValue;
      }

      if (moreFiltersButtonTextValue !== '' && !Object.prototype.hasOwnProperty.call(serializedData, 'more_filters_button_text')) {
        serializedData.more_filters_button_text = moreFiltersButtonTextValue;
      }

      if (moreFiltersOffcanvasTitleValue !== '' && !Object.prototype.hasOwnProperty.call(serializedData, 'more_filters_offcanvas_title')) {
        serializedData.more_filters_offcanvas_title = moreFiltersOffcanvasTitleValue;
      }
    }

    if (!Object.prototype.hasOwnProperty.call(serializedData, 'md_page')) {
      serializedData.md_page = String(readPageNumberFromUrl(hrefValue));
    }

    serializedData.archive_base_url = buildArchiveBaseUrl();

    return serializedData;
  }

  function createSyntheticArchiveForm($archiveRoot, serializedData) {
    if (!$archiveRoot || !$archiveRoot.length) {
      return $();
    }

    var rootId = String($archiveRoot.attr('id') || '').trim();
    if (!rootId) {
      return $();
    }

    var endpointUrl = String($archiveRoot.attr('data-md-boats-archive-url') || '').trim();
    var $formElement = $('<form class="maradigma-boats-filters maradigma-boats-filters--synthetic" method="get" style="display:none;"></form>');

    $formElement.attr('data-md-boats-archive-target', '#' + rootId);

    if (endpointUrl !== '') {
      $formElement.attr('data-md-boats-archive-url', endpointUrl);
    }

    Object.keys(serializedData || {}).forEach(function (key) {
      var value = serializedData[key];

      if (Array.isArray(value)) {
        value.forEach(function (itemValue) {
          appendSyntheticArchiveField($formElement, key, itemValue);
        });
        return;
      }

      appendSyntheticArchiveField($formElement, key, value);
    });

    $archiveRoot.append($formElement);

    return $formElement;
  }

  // ------------------------------------------------------------
  // AUTOSUBMIT
  // ------------------------------------------------------------

  function submitFiltersForm($formElement, optionsObject) {
    optionsObject = optionsObject || {};

    if (!$formElement || !$formElement.length) return null;

    var $archiveRoot = getArchiveRootFromForm($formElement);
    if (isArchiveLoading($archiveRoot)) {
      return null;
    }

    var resetPageToFirst = optionsObject.resetPage !== false;

    var $pageInput = $formElement.find('input[name="md_page"], input[data-md-original-name="md_page"]').first();
    if ($pageInput.length && resetPageToFirst) {
      if (!$pageInput.attr('name')) {
        $pageInput.attr('name', 'md_page');
      }
      $pageInput.val('1');
    }

    sanitizeFormBeforeSubmit($formElement);

    try {
      $formElement.trigger('md:beforeSubmit');
    } catch (submitEventError) {}

    if (isAjaxArchiveEnabled($formElement)) {
      return requestArchiveUpdate($formElement, {
        scrollToResults: optionsObject.scrollToResults !== false,
        fallbackToNative: false
      });
    }

    if ($formElement[0] && typeof $formElement[0].submit === 'function') {
      $formElement[0].submit();
    }

    return null;
  }

  function isSelect2InternalField($element) {
    if (!$element || !$element.length) {
      return false;
    }

    return (
      $element.hasClass('select2-search__field') ||
      $element.closest('.select2-container').length > 0 ||
      $element.closest('.select2-dropdown').length > 0
    );
  }

  function initAutosubmitForms(contextRoot) {
    $(contextRoot).find('form.maradigma-boats-filters[data-autosubmit="1"]').each(function () {
      var $formElement = $(this);

      if ($formElement.data('mdAutosubmitReady')) {
        return;
      }

      $formElement.data('mdAutosubmitReady', 1);

      var debounceTimer = null;

      function scheduleSubmit(delayInMs) {
        if (debounceTimer) {
          clearTimeout(debounceTimer);
        }

        debounceTimer = setTimeout(function () {
          var maybePromise = submitFiltersForm($formElement, {
            resetPage: true,
            scrollToResults: false
          });

          if (isPromiseLike(maybePromise)) {
            maybePromise.catch(function () {});
          }
        }, delayInMs || 250);
      }

      $formElement.on('change.mdAutosubmit', 'select, input[type="checkbox"], input[type="radio"]', function (eventObject) {
        var $targetElement = $(eventObject.target);

        if ($targetElement.is('[name="md_page"]')) return;
        if (isSelect2InternalField($targetElement)) return;

        scheduleSubmit(0);
      });

      $formElement.on('input.mdAutosubmit', 'input[type="text"], input[type="number"], input[type="search"], textarea, input:not([type])', function (eventObject) {
        var $targetElement = $(eventObject.target);

        if ($targetElement.is('[name="md_page"]')) return;
        if ($targetElement.is('[name="md_date_range"]')) return;
        if (isSelect2InternalField($targetElement)) return;

        scheduleSubmit(400);
      });

      $formElement.on('keydown.mdAutosubmit', 'input[type="text"], input[type="search"], textarea, input:not([type])', function (eventObject) {
        var $targetElement = $(eventObject.target);

        if (isSelect2InternalField($targetElement)) return;

        if (eventObject.key === 'Enter') {
          eventObject.preventDefault();

          var maybePromise = submitFiltersForm($formElement, {
            resetPage: true,
            scrollToResults: false
          });

          if (isPromiseLike(maybePromise)) {
            maybePromise.catch(function () {});
          }
        }
      });

      $formElement.on('submit.mdSanitizeBeforeNativeSubmit', function (submitEventObject) {
        if (isArchiveLoading(getArchiveRootFromForm($formElement))) {
          submitEventObject.preventDefault();
          return;
        }

        sanitizeFormBeforeSubmit($formElement);

        if (isAjaxArchiveEnabled($formElement)) {
          submitEventObject.preventDefault();

          var maybePromise = requestArchiveUpdate($formElement, {
            scrollToResults: true,
            fallbackToNative: false
          });

          if (isPromiseLike(maybePromise)) {
            maybePromise.catch(function () {});
          }
        }
      });
    });
  }

  // ------------------------------------------------------------
  // LOCAL SELECT CONTROLS
  // IMPORTANT:
  // - Remote Select2 fields are handled by remote-select2.js
  // - Tom Select is used only where explicitly requested.
  // ------------------------------------------------------------

  function initTomSelectFields(contextRoot) {
    if (typeof TomSelect !== 'function') return;

    $(contextRoot).find('select[data-md-select-ui="tom"]').each(function () {
      var selectElement = this;
      var $selectElement = $(selectElement);
      var isMultipleSelection = String($selectElement.attr('data-multiple') || '0') === '1';

      if (selectElement.tomselect || $selectElement.data('mdTomSelectReady')) {
        return;
      }

      $selectElement.data('mdTomSelectReady', 1);

      try {
        new TomSelect(selectElement, {
          allowEmptyOption: false,
          closeAfterSelect: !isMultipleSelection,
          create: false,
          hideSelected: false,
          maxItems: isMultipleSelection ? null : 1,
          plugins: isMultipleSelection ? ['remove_button', 'dropdown_input'] : ['clear_button', 'dropdown_input'],
          placeholder: $selectElement.attr('data-placeholder') || getSelect2Placeholder($selectElement),
          searchField: ['text'],
          sortField: [
            { field: '$order' },
            { field: '$score' }
          ],
          onChange: function () {
            $selectElement.trigger('change');
          }
        });
      } catch (initializationError) {
        $selectElement.removeData('mdTomSelectReady');
      }
    });
  }

  function getSelect2Placeholder($selectElement) {
    var sourceName = String($selectElement.attr('data-maradigma-source') || '').trim();
    var isMultipleSelection = String($selectElement.attr('data-multiple') || '0') === '1';

    if (sourceName === 'boat_types') return 'Select boat type';
    if (sourceName === 'builders') return 'Select builder';
    if (sourceName === 'boats') return 'Select boat';

    return isMultipleSelection ? 'Select options' : 'Select option';
  }

  function buildSelect2Config($selectElement) {
    var isMultipleSelection = String($selectElement.attr('data-multiple') || '0') === '1';
    var $drawerElement = $selectElement.closest('.md-drawer');

    var select2Config = {
      width: '100%',
      multiple: isMultipleSelection,
      placeholder: getSelect2Placeholder($selectElement),
      closeOnSelect: !isMultipleSelection,
      minimumResultsForSearch: 0
    };

    if (!isMultipleSelection) {
      var hasEmptyOption = $selectElement.find('option[value=""]').length > 0;
      select2Config.allowClear = hasEmptyOption;
    }

    if ($drawerElement.length) {
      select2Config.dropdownParent = $drawerElement;
    }

    return select2Config;
  }

  function initSelect2Fields(contextRoot) {
    if (!$.fn.select2) return;

    $(contextRoot).find('select[data-md-select2="1"]:not([data-maradigma-source]):not([data-md-select-ui="tom"])').each(function () {
      var $selectElement = $(this);
      var select2Config = buildSelect2Config($selectElement);

      if ($selectElement.data('mdSelect2Ready')) {
        return;
      }

      $selectElement.data('mdSelect2Ready', 1);

      try {
        if ($selectElement.hasClass('select2-hidden-accessible')) {
          $selectElement.select2('destroy');
        }
      } catch (destroyError) {
      }

      try {
        $selectElement.select2(select2Config);
      } catch (initializationError) {
      }
    });
  }

  function rebindSelect2ForDrawer($drawerElement) {
    if (!$drawerElement || !$drawerElement.length) {
      return;
    }

    if ($.fn.select2) {
      $drawerElement.find('select[data-md-select2="1"]:not([data-maradigma-source]):not([data-md-select-ui="tom"])').each(function () {
        var $selectElement = $(this);

        try {
          if ($selectElement.hasClass('select2-hidden-accessible')) {
            $selectElement.select2('destroy');
          }
        } catch (destroyError) {}

        $selectElement.removeData('mdSelect2Ready');

        try {
          $selectElement.select2(buildSelect2Config($selectElement));
          $selectElement.data('mdSelect2Ready', 1);
        } catch (initializationError) {}
      });
    }

    if (
      window.MaradigmaRemoteSelect2Shared &&
      typeof window.MaradigmaRemoteSelect2Shared.initAll === 'function'
    ) {
      window.MaradigmaRemoteSelect2Shared.initAll($drawerElement[0]);
    }
  }

  // ------------------------------------------------------------
  // CSV HIDDEN SYNC
  // ------------------------------------------------------------

  function initCsvHiddenSync(contextRoot) {
    $(contextRoot).find('select[data-md-csv-source]').each(function () {
      var $selectElement = $(this);

      if ($selectElement.data('mdCsvSyncReady')) {
        return;
      }

      $selectElement.data('mdCsvSyncReady', 1);

      function syncCsvHiddenField() {
        var sourceKey = String($selectElement.attr('data-md-csv-source') || '').trim();
        if (!sourceKey) {
          return;
        }

        var $formElement = $selectElement.closest('form');
        if (!$formElement.length) {
          return;
        }

        var $hiddenInput = $formElement.find('input[type="hidden"][data-md-csv-hidden="' + sourceKey + '"]').first();
        if (!$hiddenInput.length) {
          return;
        }

        var selectedValues = uniqueNonEmptyStringArray($selectElement.val());
        $hiddenInput.val(selectedValues.join(','));
      }

      $selectElement.on('change.mdCsvSync', syncCsvHiddenField);
      syncCsvHiddenField();
    });
  }

  // ------------------------------------------------------------
  // FILTER BAR DROPDOWNS
  // ------------------------------------------------------------

  function closeAllFilterDropdowns(exceptPanelElement) {
    $('.md-filterbar [data-md-dd-panel="1"]').each(function () {
      if (exceptPanelElement && this === exceptPanelElement) {
        return;
      }

      $(this).removeClass('is-open');
    });

    $('.md-filterbar [data-md-dd-toggle="1"]').attr('aria-expanded', 'false');
  }

  function clearDropdownPanelFields($panelElement) {
    if (!$panelElement || !$panelElement.length) {
      return;
    }

    $panelElement.find('input, select').each(function () {
      var fieldElement = this;
      var $fieldElement = $(fieldElement);

      if (fieldElement.type === 'hidden') {
        return;
      }

      if (fieldElement.type === 'checkbox' || fieldElement.type === 'radio') {
        fieldElement.checked = false;
        return;
      }

      $fieldElement.val('');

      if ($fieldElement.is('select')) {
        $fieldElement.trigger('change');
      }
    });

    $panelElement.trigger('md:dropdown:clear');
  }

  // ------------------------------------------------------------
  // DRAWER
  // ------------------------------------------------------------

  function getDrawerOverlayForElement($drawerElement) {
    if (!$drawerElement || !$drawerElement.length) {
      return $();
    }

    var $formElement = $drawerElement.closest('form');
    if (!$formElement.length) {
      return $();
    }

    return $formElement.find('[data-md-drawer-overlay="1"]').first();
  }

  function openFiltersDrawer($drawerElement) {
    if (!$drawerElement || !$drawerElement.length) {
      return;
    }

    var $root = $drawerElement.closest('[data-md-boats-archive-root="1"], .maradigma-boats-shortcode-list').first();
    if (isArchiveLoading($root)) {
      return;
    }

    var $overlayElement = getDrawerOverlayForElement($drawerElement);

    $drawerElement.css('display', '');
    $drawerElement.addClass('is-open');
    $drawerElement.attr('aria-hidden', 'false');

    if ($overlayElement.length) {
      $overlayElement.css('display', '');
      $overlayElement.addClass('is-open');
      $overlayElement.attr('aria-hidden', 'false');
    }
  }

  function closeFiltersDrawer($drawerElement) {
    if (!$drawerElement || !$drawerElement.length) {
      return;
    }

    var $overlayElement = getDrawerOverlayForElement($drawerElement);

    $drawerElement.removeClass('is-open');
    $drawerElement.attr('aria-hidden', 'true');

    if ($overlayElement.length) {
      $overlayElement.removeClass('is-open');
      $overlayElement.attr('aria-hidden', 'true');
    }
  }

  function closeAllFilterDrawers() {
    $('.md-drawer.is-open').each(function () {
      closeFiltersDrawer($(this));
    });
  }

  function syncInitialDrawerState(contextRoot) {
    $(contextRoot).find('.md-drawer').each(function () {
      var $drawerElement = $(this);
      var $overlayElement = getDrawerOverlayForElement($drawerElement);

      $drawerElement.css('display', '');
      $drawerElement.removeClass('is-open');
      $drawerElement.attr('aria-hidden', 'true');

      if ($overlayElement.length) {
        $overlayElement.css('display', '');
        $overlayElement.removeClass('is-open');
        $overlayElement.attr('aria-hidden', 'true');
      }
    });
  }

  // ------------------------------------------------------------
  // UX: force open datepicker even if input already has focus
  // ------------------------------------------------------------

  function bindForceOpen($inputElement) {
    if ($inputElement.data('mdForceOpen')) return;
    $inputElement.data('mdForceOpen', 1);

    $inputElement.on('mousedown.mdForceOpen', function () {
      var $root = $inputElement.closest('[data-md-boats-archive-root="1"], .maradigma-boats-shortcode-list').first();
      if (isArchiveLoading($root)) {
        return;
      }

      var inputDomElement = this;
      setTimeout(function () {
        try {
          if (inputDomElement._flatpickr) {
            inputDomElement._flatpickr.open();
          } else if ($.fn.datepicker) {
            $(inputDomElement).datepicker('show');
          }
        } catch (showError) {}
      }, 0);
    });
  }

  function getFlatpickrLocale() {
    if (!window.flatpickr || !window.flatpickr.l10ns) {
      return null;
    }

    var rawLocale = String(
      document.documentElement.getAttribute('lang') ||
      (window.navigator && window.navigator.language) ||
      'en'
    ).trim().replace('_', '-').toLowerCase();

    var langCode = rawLocale.split('-')[0] || 'en';
    var localeMap = {
      ca: 'cat',
      en: 'default'
    };
    var localeKey = localeMap[langCode] || langCode;

    return window.flatpickr.l10ns[localeKey] || window.flatpickr.l10ns[langCode] || null;
  }

  function buildFlatpickrConfig(extraConfig) {
    return $.extend({
      dateFormat: 'd-m-Y',
      allowInput: true,
      disableMobile: true,
      minDate: 'today',
      locale: getFlatpickrLocale() || undefined
    }, extraConfig || {});
  }

  function getDocumentLocale() {
    var rawLocale = String(
      document.documentElement.getAttribute('lang') ||
      (window.navigator && window.navigator.language) ||
      'en-GB'
    ).trim().replace('_', '-');

    return rawLocale || 'en-GB';
  }

  function getShortMonthName(dateObject) {
    try {
      return new Intl.DateTimeFormat(getDocumentLocale(), { month: 'short' }).format(dateObject).replace(/\.$/, '');
    } catch (formatError) {
      return String(dateObject.getMonth() + 1).padStart(2, '0');
    }
  }

  function formatHumanSingleDate(dateObject) {
    if (!dateObject) return '';

    try {
      return new Intl.DateTimeFormat(getDocumentLocale(), {
        day: 'numeric',
        month: 'short',
        year: 'numeric'
      }).format(dateObject).replace(/\.$/, '');
    } catch (formatError) {
      return fmtDmy(dateObject);
    }
  }

  function formatHumanDateRange(startDate, endDate) {
    if (startDate && !endDate) {
      return formatHumanSingleDate(startDate);
    }

    if (!startDate || !endDate) {
      return '';
    }

    if (startDate.getFullYear() === endDate.getFullYear() && startDate.getMonth() === endDate.getMonth() && startDate.getDate() === endDate.getDate()) {
      return formatHumanSingleDate(startDate);
    }

    var sameMonth = startDate.getFullYear() === endDate.getFullYear() && startDate.getMonth() === endDate.getMonth();
    if (sameMonth) {
      return startDate.getDate() + ' - ' + endDate.getDate() + ' ' + getShortMonthName(endDate) + ' ' + endDate.getFullYear();
    }

    var sameYear = startDate.getFullYear() === endDate.getFullYear();
    if (sameYear) {
      return startDate.getDate() + ' ' + getShortMonthName(startDate) + ' - ' + endDate.getDate() + ' ' + getShortMonthName(endDate) + ' ' + endDate.getFullYear();
    }

    return formatHumanSingleDate(startDate) + ' - ' + formatHumanSingleDate(endDate);
  }

  function autosubmitIfEnabled($elementOrForm) {
    var $formElement = $elementOrForm.is('form') ? $elementOrForm : $elementOrForm.closest('form');
    if ($formElement.length && $formElement.is('[data-autosubmit="1"]')) {
      var maybePromise = submitFiltersForm($formElement, {
        resetPage: true,
        scrollToResults: false
      });

      if (isPromiseLike(maybePromise)) {
        maybePromise.catch(function () {});
      }
    }
  }

  // ------------------------------------------------------------
  // SEPARATE DATE PICKERS (2 inputs)
  // ------------------------------------------------------------

  function ensureSeparateHidden($inputElement) {
    var originalName = $inputElement.data('mdOrigName') || $inputElement.attr('data-md-visible-name') || $inputElement.attr('name') || '';
    originalName = String(originalName || '').trim();

    if (originalName !== 'md_date_start' && originalName !== 'md_date_end') {
      return null;
    }

    if (!$inputElement.data('mdOrigName')) {
      $inputElement.data('mdOrigName', originalName);
      $inputElement.attr('data-md-visible-name', originalName);
    }

    if ($inputElement.attr('name') === originalName) {
      $inputElement.attr('name', originalName + '_display');
    }

    var $hiddenInput = $inputElement.siblings('input[type="hidden"][data-md-hidden-for="' + originalName + '"]');
    if (!$hiddenInput.length) {
      $hiddenInput = $('<input type="hidden">')
        .attr('name', originalName)
        .attr('data-md-hidden-for', originalName);

      $inputElement.after($hiddenInput);
    }

    return {
      origName: originalName,
      $hidden: $hiddenInput
    };
  }

  function syncSeparateHiddenFromVisible($inputElement, inputMeta) {
    if (!inputMeta) return;

    var rawValue = String($inputElement.val() || '').trim();
    var parsedDate = parseDmy(rawValue);

    if (!parsedDate) {
      var ymdDate = parseYmd(rawValue);
      if (ymdDate) {
        $inputElement.val(fmtDmy(ymdDate));
        parsedDate = ymdDate;
      }
    }

    inputMeta.$hidden.val(parsedDate ? fmtYmd(parsedDate) : '');
  }

  function initDatePickers(contextRoot) {
    if (!window.flatpickr) return;

    $(contextRoot).find('input.maradigma-date').each(function () {
      var $inputElement = $(this);

      if ($inputElement.data('mdDatepicker')) {
        return;
      }

      $inputElement.data('mdDatepicker', 1);

      var inputMeta = ensureSeparateHidden($inputElement);

      if (inputMeta) {
        syncSeparateHiddenFromVisible($inputElement, inputMeta);
      }

      try {
        var initialDate = parseYmd(inputMeta && inputMeta.$hidden.val()) || parseDmy($inputElement.val());

        window.flatpickr(this, buildFlatpickrConfig({
          defaultDate: initialDate || null,
          onChange: function () {
            if (!inputMeta) {
              inputMeta = ensureSeparateHidden($inputElement);
            }

            if (inputMeta) {
              syncSeparateHiddenFromVisible($inputElement, inputMeta);
            }

            autosubmitIfEnabled($inputElement);
          }
        }));

        $inputElement.on('change.mdDate', function () {
          if (!inputMeta) {
            inputMeta = ensureSeparateHidden($inputElement);
          }

          if (inputMeta) {
            syncSeparateHiddenFromVisible($inputElement, inputMeta);
          }
        });

        bindForceOpen($inputElement);
      } catch (datepickerError) {}
    });
  }

  // ------------------------------------------------------------
  // RANGE DATE PICKER (1 input)
  // ------------------------------------------------------------

  function initRangeDatePickers(contextRoot) {
    if (!window.flatpickr) return;

    $(contextRoot).find('input.maradigma-date-range[data-md-date-range="1"]').each(function () {
      var $rangeInput = $(this);

      if ($rangeInput.data('mdRangeReady')) {
        return;
      }

      $rangeInput.data('mdRangeReady', 1);

      var $formElement = $rangeInput.closest('form');
      var $dateControl = $rangeInput.closest('.md-date-range-control');
      var $clearButton = $dateControl.find('[data-md-date-range-clear="1"]').first();
      var $startHidden = $formElement.find('input[name="md_date_start"], input[data-md-original-name="md_date_start"]').first();
      var $endHidden = $formElement.find('input[name="md_date_end"], input[data-md-original-name="md_date_end"]').first();

      if (!$startHidden.length) {
        $startHidden = $('<input type="hidden" name="md_date_start">').appendTo($formElement);
      }

      if (!$endHidden.length) {
        $endHidden = $('<input type="hidden" name="md_date_end">').appendTo($formElement);
      }

      function getStart() {
        return parseYmd($startHidden.val());
      }

      function getEnd() {
        return parseYmd($endHidden.val());
      }

      function setStart(dateObject) {
        $startHidden.val(dateObject ? fmtYmd(dateObject) : '');
      }

      function setEnd(dateObject) {
        $endHidden.val(dateObject ? fmtYmd(dateObject) : '');
      }

      function setDisplay(startDate, endDate) {
        $rangeInput.val(formatHumanDateRange(startDate, endDate));
        updateClearState();
      }

      function updateClearState() {
        var hasValue = String($startHidden.val() || '').trim() !== '' || String($endHidden.val() || '').trim() !== '' || String($rangeInput.val() || '').trim() !== '';
        $dateControl.toggleClass('has-value', hasValue);
      }

      setDisplay(getStart(), getEnd());

      $clearButton.off('click.mdDateRangeClear').on('click.mdDateRangeClear', function (eventObject) {
        eventObject.preventDefault();

        setStart(null);
        setEnd(null);

        try {
          if ($rangeInput[0] && $rangeInput[0]._flatpickr) {
            $rangeInput[0]._flatpickr.clear();
          }
        } catch (clearError) {}

        setDisplay(null, null);
        autosubmitIfEnabled($rangeInput);
      });

      try {
        window.flatpickr(this, buildFlatpickrConfig({
          mode: 'range',
          closeOnSelect: false,
          defaultDate: [getStart(), getEnd()].filter(Boolean),
          onReady: function () {
            setDisplay(getStart(), getEnd());
          },
          onValueUpdate: function (selectedDates) {
            var startDate = selectedDates[0] || null;
            var endDate = selectedDates.length > 1 ? (selectedDates[1] || null) : null;

            if (!selectedDates.length) {
              startDate = getStart();
              endDate = getEnd();
            }

            setTimeout(function () {
              setDisplay(startDate, endDate);
            }, 0);
          },
          onChange: function (selectedDates) {
            var startDate = selectedDates[0] || null;
            var endDate = selectedDates[1] || null;

            setStart(startDate);
            setEnd(endDate);
            setDisplay(startDate, endDate);

            if (startDate && endDate) {
              setTimeout(function () {
                try {
                  if ($rangeInput[0] && $rangeInput[0]._flatpickr) {
                    $rangeInput[0]._flatpickr.close();
                  }
                } catch (closeError) {}
              }, 0);

              autosubmitIfEnabled($rangeInput);
            }
          },
          onClose: function (selectedDates) {
            if (!selectedDates.length) {
              setStart(null);
              setEnd(null);
              setDisplay(null, null);
              autosubmitIfEnabled($rangeInput);
            }
          }
        }));

        $rangeInput.on('change.mdRange', function () {
          var rawValue = String($rangeInput.val() || '').trim();

          if (!rawValue) {
            setStart(null);
            setEnd(null);
            return;
          }

          var parts = rawValue.split(' - ').map(function (partValue) {
            return String(partValue || '').trim();
          });

          var firstDate = parts[0] ? (parseDmy(parts[0]) || parseYmd(parts[0])) : null;
          var secondDate = parts[1] ? (parseDmy(parts[1]) || parseYmd(parts[1])) : null;

          if (firstDate && !secondDate) {
            setStart(firstDate);
            setEnd(null);
            setDisplay(firstDate, null);
            $rangeInput.data('mdNeedSecondPick', 1);
            return;
          }

          if (firstDate && secondDate) {
            if (secondDate < firstDate) {
              var swapDate = firstDate;
              firstDate = secondDate;
              secondDate = swapDate;
            }

            setStart(firstDate);
            setEnd(secondDate);
            setDisplay(firstDate, secondDate);
            $rangeInput.removeData('mdNeedSecondPick');
            autosubmitIfEnabled($rangeInput);
          }
        });

        bindForceOpen($rangeInput);
      } catch (rangePickerError) {}
    });
  }

  // ------------------------------------------------------------
  // PRICE SLIDER
  // ------------------------------------------------------------

  function initPriceRangeSliders(contextRoot) {
    if (!window.noUiSlider || typeof window.noUiSlider.create !== 'function') return;

    $(contextRoot).find('[data-md-price-range]').each(function () {
      var $rangeWrapper = $(this);

      if ($rangeWrapper.data('mdSlider')) {
        return;
      }

      $rangeWrapper.data('mdSlider', 1);

      var $sliderElement = $rangeWrapper.find('.maradigma-price-range__slider');
      var $minOutput = $rangeWrapper.find('.maradigma-price-range__min');
      var $maxOutput = $rangeWrapper.find('.maradigma-price-range__max');
      var $minInput = $rangeWrapper.find('input[name="md_min_price"], input[data-md-original-name="md_min_price"]').first();
      var $maxInput = $rangeWrapper.find('input[name="md_max_price"], input[data-md-original-name="md_max_price"]').first();

      var minimumValue = toInt($rangeWrapper.data('rangeMin'), 0);
      var maximumValue = toInt($rangeWrapper.data('rangeMax'), 5000);
      var stepValue = toInt($rangeWrapper.data('step'), 10);
      var currentMinValue = toInt($rangeWrapper.data('valueMin'), minimumValue);
      var currentMaxValue = toInt($rangeWrapper.data('valueMax'), maximumValue);

      if (currentMinValue < minimumValue) currentMinValue = minimumValue;
      if (currentMaxValue > maximumValue) currentMaxValue = maximumValue;
      if (currentMinValue > currentMaxValue) currentMinValue = currentMaxValue;

      function renderValues(minRenderedValue, maxRenderedValue) {
        $minOutput.text(minRenderedValue + ' €');
        $maxOutput.text(maxRenderedValue + ' €');
        $minInput.val(minRenderedValue);
        $maxInput.val(maxRenderedValue);
      }

      renderValues(currentMinValue, currentMaxValue);

      try {
        if ($sliderElement[0] && $sliderElement[0].noUiSlider) {
          $sliderElement[0].noUiSlider.destroy();
        }
      } catch (destroySliderError) {}

      try {
        window.noUiSlider.create($sliderElement[0], {
          start: [currentMinValue, currentMaxValue],
          connect: true,
          range: {
            min: minimumValue,
            max: maximumValue
          },
          step: stepValue,
          format: {
            to: function (valueToFormat) {
              return Math.round(valueToFormat);
            },
            from: function (valueToParse) {
              return parseInt(valueToParse, 10);
            }
          }
        });

        $sliderElement[0].noUiSlider.on('update', function (valuesArray) {
          var minUpdatedValue = parseInt(valuesArray[0], 10);
          var maxUpdatedValue = parseInt(valuesArray[1], 10);

          if (!isFinite(minUpdatedValue)) minUpdatedValue = currentMinValue;
          if (!isFinite(maxUpdatedValue)) maxUpdatedValue = currentMaxValue;

          renderValues(minUpdatedValue, maxUpdatedValue);
        });

        $sliderElement[0].noUiSlider.on('set', function () {
          var $formElement = $rangeWrapper.closest('form');
          if ($formElement.length && $formElement.is('[data-autosubmit="1"]')) {
            var maybePromise = submitFiltersForm($formElement, {
              resetPage: true,
              scrollToResults: false
            });

            if (isPromiseLike(maybePromise)) {
              maybePromise.catch(function () {});
            }
          }
        });
      } catch (sliderInitializationError) {}
    });
  }

  // ------------------------------------------------------------
  // PAGINATION AJAX
  // ------------------------------------------------------------

  function readPageNumberFromUrl(urlToParse) {
    if (!urlToParse) {
      return 1;
    }

    try {
      var parsedUrl = new URL(urlToParse, window.location.origin);
      var mdPageValue = parsedUrl.searchParams.get('md_page');
      var parsedPage = toInt(mdPageValue, 1);
      return parsedPage > 0 ? parsedPage : 1;
    } catch (urlParseError) {
      return 1;
    }
  }

  function initArchivePagination(contextRoot) {
    $(contextRoot).find('.maradigma-pagination a').each(function () {
      var $linkElement = $(this);

      if ($linkElement.data('mdPaginationReady')) {
        return;
      }

      $linkElement.data('mdPaginationReady', 1);

      $linkElement.on('click.mdArchivePagination', function (eventObject) {
        var $clickedLink = $(this);
        var hrefValue = String($clickedLink.attr('href') || '').trim();

        if (!hrefValue) {
          eventObject.preventDefault();
          return;
        }

        var $archiveRoot = $clickedLink.closest('[data-md-boats-archive-root="1"], .maradigma-boats-shortcode-list').first();
        if (!$archiveRoot.length) {
          eventObject.preventDefault();
          return;
        }

        if (isArchiveLoading($archiveRoot)) {
          eventObject.preventDefault();
          eventObject.stopImmediatePropagation();
          return;
        }

        eventObject.preventDefault();
        eventObject.stopImmediatePropagation();

        var targetPage = readPageNumberFromUrl(hrefValue);
        var $formElement = $archiveRoot.find('form.maradigma-boats-filters').first();
        var syntheticFormUsed = false;

        if (!$formElement.length) {
          $formElement = createSyntheticArchiveForm(
            $archiveRoot,
            buildArchiveRequestDataFromUrl(hrefValue, $archiveRoot)
          );
          syntheticFormUsed = $formElement.length > 0;
        }

        if (!$formElement.length) {
          return;
        }

        if (!isAjaxArchiveEnabled($formElement)) {
          if (syntheticFormUsed) {
            $formElement.remove();
          }
          window.location.href = hrefValue;
          return;
        }

        if (!syntheticFormUsed) {
          var $pageInput = $formElement.find('input[name="md_page"], input[data-md-original-name="md_page"]').first();

          if ($pageInput.length) {
            if (!$pageInput.attr('name')) {
              $pageInput.attr('name', 'md_page');
            }
            $pageInput.val(String(targetPage));
          }
        }

        var maybePromise = submitFiltersForm($formElement, {
          resetPage: false,
          scrollToResults: true
        });

        if (syntheticFormUsed) {
          if (isPromiseLike(maybePromise)) {
            maybePromise.finally(function () {
              $formElement.remove();
            });
          } else {
            $formElement.remove();
          }
        }

        if (isPromiseLike(maybePromise)) {
          maybePromise.catch(function () {});
        }
      });
    });
  }

  // ------------------------------------------------------------
  // BOOT
  // ------------------------------------------------------------

  function boot(contextRoot) {
    syncInitialDrawerState(contextRoot);
    initAutosubmitForms(contextRoot);
    initCsvHiddenSync(contextRoot);
    initTomSelectFields(contextRoot);
    initSelect2Fields(contextRoot);
    initDatePickers(contextRoot);
    initRangeDatePickers(contextRoot);
    initPriceRangeSliders(contextRoot);
    initArchivePagination(contextRoot);
  }

  $(function () {
    boot(document);

    $(window).on('elementor/frontend/init', function () {
      boot(document);
    });

    $(document).on('click', '[data-md-loading-blocker="1"]', function (eventObject) {
      eventObject.preventDefault();
      eventObject.stopImmediatePropagation();
    });

    $(document).on('click', function (eventObject) {
      var $target = $(eventObject.target);
      var $archiveRoot = $target.closest('[data-md-boats-archive-root="1"], .maradigma-boats-shortcode-list').first();

      if ($archiveRoot.length && isArchiveLoading($archiveRoot)) {
        eventObject.preventDefault();
        eventObject.stopImmediatePropagation();
        return;
      }

      var $toggleElement = $target.closest('.md-filterbar [data-md-dd-toggle="1"]');
      var $panelElement = $target.closest('.md-filterbar [data-md-dd-panel="1"]');

      if (!$toggleElement.length && !$panelElement.length) {
        closeAllFilterDropdowns(null);
        return;
      }

      if ($toggleElement.length) {
        var $wrapperElement = $toggleElement.closest('[data-md-dd="1"]');
        if (!$wrapperElement.length) return;

        var $targetPanel = $wrapperElement.find('[data-md-dd-panel="1"]').first();
        if (!$targetPanel.length) return;

        var isOpen = $targetPanel.hasClass('is-open');

        closeAllFilterDropdowns($targetPanel[0]);

        if (!isOpen) {
          $targetPanel.addClass('is-open');
          $toggleElement.attr('aria-expanded', 'true');
        } else {
          $targetPanel.removeClass('is-open');
          $toggleElement.attr('aria-expanded', 'false');
        }
      }
    });

    $(document).on('click', '.md-filterbar [data-md-dd-clear="1"], .md-filterbar [data-md-dd-apply="1"]', function (eventObject) {
      var $clickedButton = $(eventObject.target).closest('[data-md-dd-clear="1"], [data-md-dd-apply="1"]');
      var $panelElement = $clickedButton.closest('.md-filterbar [data-md-dd-panel="1"]');

      if (!$panelElement.length) {
        return;
      }

      var $archiveRoot = $clickedButton.closest('[data-md-boats-archive-root="1"], .maradigma-boats-shortcode-list').first();
      if ($archiveRoot.length && isArchiveLoading($archiveRoot)) {
        eventObject.preventDefault();
        eventObject.stopImmediatePropagation();
        return;
      }

      if ($clickedButton.is('[data-md-dd-clear="1"]')) {
        clearDropdownPanelFields($panelElement);
      }

      closeAllFilterDropdowns(null);

      var $formElement = $panelElement.closest('form');
      if ($formElement.length && $formElement.is('[data-autosubmit="1"]')) {
        var maybePromise = submitFiltersForm($formElement, {
          resetPage: true,
          scrollToResults: false
        });

        if (isPromiseLike(maybePromise)) {
          maybePromise.catch(function () {});
        }
      }
    });

    $(document).on('click', '[data-md-drawer-open="1"]', function (eventObject) {
      var $trigger = $(this);
      var $archiveRoot = $trigger.closest('[data-md-boats-archive-root="1"], .maradigma-boats-shortcode-list').first();

      if ($archiveRoot.length && isArchiveLoading($archiveRoot)) {
        eventObject.preventDefault();
        eventObject.stopImmediatePropagation();
        return;
      }

      var targetDrawerId = String($trigger.attr('aria-controls') || '').trim();
      if (!targetDrawerId) return;

      var $drawerElement = $('#' + targetDrawerId);
      if (!$drawerElement.length) return;

      openFiltersDrawer($drawerElement);

      setTimeout(function () {
        initTomSelectFields($drawerElement);
        rebindSelect2ForDrawer($drawerElement);
      }, 30);
    });

    $(document).on('click', '[data-md-drawer-close="1"]', function (eventObject) {
      var $drawerElement = $(this).closest('.md-drawer');
      if (!$drawerElement.length) return;

      var $archiveRoot = $drawerElement.closest('[data-md-boats-archive-root="1"], .maradigma-boats-shortcode-list').first();
      if ($archiveRoot.length && isArchiveLoading($archiveRoot)) {
        eventObject.preventDefault();
        eventObject.stopImmediatePropagation();
        return;
      }

      closeFiltersDrawer($drawerElement);
    });

    $(document).on('click', '[data-md-drawer-overlay="1"]', function (eventObject) {
      var $formElement = $(this).closest('form');
      var $archiveRoot = $formElement.closest('[data-md-boats-archive-root="1"], .maradigma-boats-shortcode-list').first();

      if ($archiveRoot.length && isArchiveLoading($archiveRoot)) {
        eventObject.preventDefault();
        eventObject.stopImmediatePropagation();
        return;
      }

      var $drawerElement = $formElement.find('.md-drawer').first();

      if ($drawerElement.length) {
        closeFiltersDrawer($drawerElement);
      }
    });

    $(document).on('keydown', function (eventObject) {
      var $target = $(eventObject.target);
      var $archiveRoot = $target.closest('[data-md-boats-archive-root="1"], .maradigma-boats-shortcode-list').first();

      if ($archiveRoot.length && isArchiveLoading($archiveRoot)) {
        if (eventObject.key !== 'Tab') {
          eventObject.preventDefault();
          eventObject.stopImmediatePropagation();
        }
        return;
      }

      if (eventObject.key !== 'Escape') {
        return;
      }

      closeAllFilterDropdowns(null);
      closeAllFilterDrawers();
    });

    $(document).on('submit', 'form.maradigma-boats-filters', function (submitEventObject) {
      var $formElement = $(this);
      var $archiveRoot = getArchiveRootFromForm($formElement);

      if (isArchiveLoading($archiveRoot)) {
        submitEventObject.preventDefault();
        submitEventObject.stopImmediatePropagation();
        return;
      }

      sanitizeFormBeforeSubmit($formElement);

      if (isAjaxArchiveEnabled($formElement)) {
        submitEventObject.preventDefault();

        var maybePromise = requestArchiveUpdate($formElement, {
          scrollToResults: true,
          fallbackToNative: false
        });

        if (isPromiseLike(maybePromise)) {
          maybePromise.catch(function () {});
        }
      }
    });

    window.addEventListener('popstate', function () {
      $('[data-md-boats-archive-root="1"], .maradigma-boats-shortcode-list').each(function () {
        var $archiveRoot = $(this);
        var $formElement = $archiveRoot.find('form.maradigma-boats-filters').first();

        if (!$formElement.length || !isAjaxArchiveEnabled($formElement)) {
          return;
        }

        if (isArchiveLoading($archiveRoot)) {
          return;
        }

        var maybePromise = requestArchiveUpdate($formElement, {
          scrollToResults: false,
          fallbackToNative: false
        });

        if (isPromiseLike(maybePromise)) {
          maybePromise.catch(function () {});
        }
      });
    });
  });
})(jQuery);


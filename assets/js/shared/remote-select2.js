'use strict';


/**
 * Maradigma Shared Select2 (admin + frontend)
 *
 * Requires:
 * - jQuery
 * - Select2
 *
 * Reads config from:
 *   window.MaradigmaRemoteSelect2 = {
 *     ajaxUrl: "...",
 *     nonce: "...",
 *     search: {
 *       boatTypesAction: "maradigma_admin_search_boat_types",
 *       tagsAction: "maradigma_admin_search_tags",
 *       buildersAction: "maradigma_admin_search_builders",
 *       boatsAction: "maradigma_admin_search_boats",
 *       basePortsAction: "maradigma_admin_search_base_ports",
 *       boatByIdAction: "maradigma_admin_get_boat_by_id"
 *     },
 *     i18n: {
 *       placeholderSingle: "Select an option",
 *       placeholderMulti: "Select options",
 *       searching: "Searching…",
 *       noResults: "No results found",
 *       errorLoading: "Error loading results",
 *       inputTooShort: "Type at least 2 characters",
 *       boatTypes: "Select boat type",
 *       tags: "Select tags",
 *       builders: "Select builders",
 *       boats: "Search a boat",
 *       basePorts: "Select base port"
 *     }
 *   }
 *
 * HTML:
 *   <select class="maradigma-remote-select"
 *           data-maradigma-source="boats"
 *           data-multiple="0"></select>
 */
window.MaradigmaRemoteSelect2Shared = window.MaradigmaRemoteSelect2Shared || (function () {
  var api = {};

  function getConfig() {
    return window.MaradigmaRemoteSelect2 || {};
  }

  function toBool(valueToNormalize) {
    return (
      valueToNormalize === true ||
      valueToNormalize === 'true' ||
      valueToNormalize === 1 ||
      valueToNormalize === '1'
    );
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

  function buildLanguage(minimumLengthForSearch, i18nConfig) {
    return {
      inputTooShort: function () {
        if (minimumLengthForSearch <= 0) {
          return '';
        }
        return i18nConfig.inputTooShort || 'Type at least 2 characters';
      },
      searching: function () {
        return i18nConfig.searching || 'Searching…';
      },
      noResults: function () {
        return i18nConfig.noResults || 'No results found';
      },
      errorLoading: function () {
        return i18nConfig.errorLoading || 'Error loading results';
      }
    };
  }

  function getSearchActionMap(searchConfig) {
    return {
      boat_types: searchConfig.boatTypesAction || 'maradigma_admin_search_boat_types',
      tags: searchConfig.tagsAction || 'maradigma_admin_search_tags',
      builders: searchConfig.buildersAction || 'maradigma_admin_search_builders',
      boats: searchConfig.boatsAction || 'maradigma_admin_search_boats',
      base_ports: searchConfig.basePortsAction || 'maradigma_admin_search_base_ports',
      destinations: searchConfig.destinationsAction || 'maradigma_admin_search_destinations'
    };
  }

  function getPlaceholderMap(i18nConfig) {
    return {
      boat_types: i18nConfig.boatTypes || i18nConfig.placeholderSingle || 'Select boat type',
      tags: i18nConfig.tags || i18nConfig.placeholderMulti || 'Select tags',
      builders: i18nConfig.builders || i18nConfig.placeholderMulti || 'Select builders',
      boats: i18nConfig.boats || i18nConfig.placeholderSingle || 'Search a boat',
      base_ports: i18nConfig.basePorts || i18nConfig.placeholderSingle || 'Select base port',
      destinations: i18nConfig.destinations || i18nConfig.placeholderSingle || 'Select destination'
    };
  }

  function getFallbackLabelForSource(sourceName, itemId) {
    switch (sourceName) {
      case 'boats':
        return 'Boat #' + itemId;
      case 'builders':
        return 'Builder #' + itemId;
      case 'boat_types':
        return 'Type #' + itemId;
      case 'tags':
        return 'Tag #' + itemId;
      case 'base_ports':
        return 'Base port #' + itemId;
      case 'destinations':
        return '#' + itemId;
      default:
        return '#' + itemId;
    }
  }

  function escapeForRegExp(value) {
    return String(value).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  }

  function optionLooksLikeFallbackLabel(sourceName, optionText, optionValue) {
    var normalizedText = String(optionText || '').trim();
    var normalizedValue = escapeForRegExp(String(optionValue || '').trim());

    if (!normalizedText || !normalizedValue) {
      return false;
    }

    switch (sourceName) {
      case 'boats':
        return new RegExp('^Boat\\s*#\\s*' + normalizedValue + '$', 'i').test(normalizedText);

      case 'builders':
        return new RegExp('^Builder\\s*#\\s*' + normalizedValue + '$', 'i').test(normalizedText);

      case 'boat_types':
        return new RegExp('^Type\\s*#\\s*' + normalizedValue + '$', 'i').test(normalizedText);

      case 'tags':
        return new RegExp('^Tag\\s*#\\s*' + normalizedValue + '$', 'i').test(normalizedText);

      case 'base_ports':
        return new RegExp('^Base\\s*port\\s*#\\s*' + normalizedValue + '$', 'i').test(normalizedText);

      default:
        return /^#\s*\S+$/i.test(normalizedText);
    }
  }

  function extractCurrentSelectedValues($selectElement, isMultipleSelection) {
    if (!$selectElement || !$selectElement.length) {
      return [];
    }

    var rawValue = $selectElement.val();

    if (isMultipleSelection) {
      return uniqueNonEmptyStringArray(rawValue);
    }

    var singleValue = String(rawValue || '').trim();
    return singleValue ? [singleValue] : [];
  }

  function normalizeAjaxResults(resultsArray) {
    if (!Array.isArray(resultsArray)) {
      return [];
    }

    return resultsArray
      .filter(function (itemObject) {
        return itemObject && typeof itemObject === 'object';
      })
      .map(function (itemObject) {
        return {
          id: String(itemObject.id || '').trim(),
          text: String(itemObject.text || '').trim()
        };
      })
      .filter(function (itemObject) {
        return itemObject.id !== '' && itemObject.text !== '';
      });
  }

  function rebuildSelectedOptionNodes($selectElement) {
    if (!$selectElement || !$selectElement.length) {
      return;
    }

    $selectElement.find('option:selected').each(function () {
      var optionValue = String(this.value || '').trim();
      var optionText = String(window.jQuery(this).text() || '').trim();

      if (!optionValue || !optionText) {
        return;
      }

      var replacementOption = new Option(optionText, optionValue, true, true);
      window.jQuery(this).replaceWith(replacementOption);
    });
  }

  /**
   * Hydrate missing labels for already-selected values.
   *
   * Supported:
   * - single selects
   * - multiple selects
   * - boats/builders/boat_types/tags/base_ports
   *
   * Strategy:
   * - if source supports a by-id action, hydrate each selected id individually
   * - for the rest, search remotely with empty/neutral query and map by id from returned results
   */
  function hydrateSelectedOptionIfNeeded($selectElement, sourceName, configObject, isMultipleSelection) {
    if (!$selectElement || !$selectElement.length) {
      return;
    }

    var currentValues = extractCurrentSelectedValues($selectElement, isMultipleSelection);
    if (!currentValues.length) {
      return;
    }

    var missingValueIds = [];

    currentValues.forEach(function (selectedValue) {
      var $matchingOption = $selectElement.find('option').filter(function () {
        return String(this.value || '').trim() === selectedValue;
      }).first();

      if (!$matchingOption.length) {
        missingValueIds.push(selectedValue);
        return;
      }

      var optionText = String($matchingOption.text() || '').trim();
      if (!optionText || optionLooksLikeFallbackLabel(sourceName, optionText, selectedValue)) {
        missingValueIds.push(selectedValue);
      }
    });

    missingValueIds = uniqueNonEmptyStringArray(missingValueIds);

    if (!missingValueIds.length) {
      return;
    }

    var ajaxUrl = String(configObject.ajaxUrl || '').trim();
    var nonce = String(configObject.nonce || '').trim();
    var searchConfig = configObject.search || {};
    var actionMap = getSearchActionMap(searchConfig);

    if (!ajaxUrl || !nonce) {
      return;
    }

    function appendOrReplaceOption(itemId, itemText) {
      var normalizedId = String(itemId || '').trim();
      var normalizedText = String(itemText || '').trim();

      if (!normalizedId || !normalizedText) {
        return;
      }

      var $existingOption = $selectElement.find('option').filter(function () {
        return String(this.value || '').trim() === normalizedId;
      }).first();

      if ($existingOption.length) {
        $existingOption.text(normalizedText);
        return;
      }

      var shouldSelect = currentValues.indexOf(normalizedId) !== -1;
      var hydratedOption = new Option(normalizedText, normalizedId, shouldSelect, shouldSelect);
      $selectElement.append(hydratedOption);
    }

    function finalizeHydration() {
      if (isMultipleSelection) {
        $selectElement.val(currentValues).trigger('change').trigger('change.select2');
      } else if (currentValues.length) {
        $selectElement.val(currentValues[0]).trigger('change').trigger('change.select2');
      }
    }

    var hydrateByIdAction = '';
    if (sourceName === 'boats' && searchConfig.boatByIdAction) {
      hydrateByIdAction = searchConfig.boatByIdAction;
    } else if (sourceName === 'builders' && searchConfig.builderByIdAction) {
      hydrateByIdAction = searchConfig.builderByIdAction;
    }

    if (hydrateByIdAction) {
      var remainingRequests = missingValueIds.length;

      missingValueIds.forEach(function (missingId) {
        window.jQuery.ajax({
          url: ajaxUrl,
          method: 'GET',
          dataType: 'json',
          data: {
            action: hydrateByIdAction,
            nonce: nonce,
            id: missingId
          }
        }).done(function (ajaxResponse) {
          if (ajaxResponse && ajaxResponse.success === true && ajaxResponse.item && ajaxResponse.item.id) {
            appendOrReplaceOption(
              ajaxResponse.item.id,
              ajaxResponse.item.text || getFallbackLabelForSource(sourceName, ajaxResponse.item.id)
            );
          } else {
            appendOrReplaceOption(missingId, getFallbackLabelForSource(sourceName, missingId));
          }
        }).fail(function () {
          appendOrReplaceOption(missingId, getFallbackLabelForSource(sourceName, missingId));
        }).always(function () {
          remainingRequests -= 1;
          if (remainingRequests <= 0) {
            finalizeHydration();
          }
        });
      });

      return;
    }

    var searchActionName = actionMap[sourceName];
    if (!searchActionName) {
      return;
    }

    window.jQuery.ajax({
      url: ajaxUrl,
      method: 'GET',
      dataType: 'json',
      data: {
        action: searchActionName,
        nonce: nonce,
        lang: String(configObject.lang || ''),
        q: '',
        page: 1
      }
    }).done(function (ajaxResponse) {
      var resultMap = {};
      var normalizedResults = normalizeAjaxResults(ajaxResponse && ajaxResponse.results);

      normalizedResults.forEach(function (itemObject) {
        resultMap[itemObject.id] = itemObject.text;
      });

      missingValueIds.forEach(function (missingId) {
        appendOrReplaceOption(
          missingId,
          resultMap[missingId] || getFallbackLabelForSource(sourceName, missingId)
        );
      });

      finalizeHydration();
    }).fail(function () {
      missingValueIds.forEach(function (missingId) {
        appendOrReplaceOption(missingId, getFallbackLabelForSource(sourceName, missingId));
      });

      finalizeHydration();
    });
  }

  function initAjaxSelect2ForElement($selectElement) {
    if (!$selectElement || !$selectElement.length) {
      return;
    }

    if (typeof window.jQuery === 'undefined') {
      console.warn('Maradigma: jQuery not available for Select2.');
      return;
    }

    if (typeof window.jQuery.fn.select2 !== 'function') {
      console.warn('Maradigma: Select2 is not available.');
      return;
    }

    var sourceName = String($selectElement.data('maradigma-source') || '').trim();
    if (!sourceName) {
      return;
    }

    if ($selectElement.hasClass('select2-hidden-accessible')) {
      try {
        $selectElement.select2('destroy');
      } catch (destroyError) {}
    }

    rebuildSelectedOptionNodes($selectElement);

    var configObject = getConfig();
    var ajaxUrl = String(configObject.ajaxUrl || '').trim();
    var nonce = String(configObject.nonce || '').trim();
    var searchConfig = configObject.search || {};
    var i18nConfig = configObject.i18n || {};

    if (!ajaxUrl) {
      console.warn('Maradigma: missing ajaxUrl in window.MaradigmaRemoteSelect2');
      return;
    }

    var isStaticSelect = false;

    var isMultipleSelection = toBool($selectElement.data('multiple'));
    var $drawerContainer = $selectElement.closest('.md-drawer');

    var actionMap = getSearchActionMap(searchConfig);
    var placeholderMap = getPlaceholderMap(i18nConfig);

    var ajaxActionName = sourceName ? actionMap[sourceName] : '';
    if (sourceName && !ajaxActionName) {
      console.warn('Maradigma: no AJAX action for source:', sourceName, $selectElement);
      return;
    }

    var placeholderText = (sourceName ? placeholderMap[sourceName] : '') || (
      isMultipleSelection
        ? (i18nConfig.placeholderMulti || 'Select options')
        : (i18nConfig.placeholderSingle || 'Select an option')
    );

    var minimumInputLength = (sourceName === 'boats') ? 2 : 0;

    if (!isStaticSelect) {
      hydrateSelectedOptionIfNeeded($selectElement, sourceName, configObject, isMultipleSelection);
    }

    var select2Configuration = {
      width: '100%',
      placeholder: placeholderText,
      multiple: isMultipleSelection,
      language: buildLanguage(minimumInputLength, i18nConfig),
      minimumInputLength: minimumInputLength
    };

    if (!isStaticSelect) {
      select2Configuration.ajax = {
        url: ajaxUrl,
        dataType: 'json',
        delay: 250,
        data: function (paramsObject) {
          return {
            action: ajaxActionName,
            nonce: nonce,
            lang: String(configObject.lang || ''),
            q: paramsObject.term || '',
            page: paramsObject.page || 1
          };
        },
        processResults: function (ajaxResponse, paramsObject) {
          paramsObject.page = paramsObject.page || 1;

          if (!ajaxResponse || ajaxResponse.success !== true) {
            return {
              results: [],
              pagination: {
                more: false
              }
            };
          }

          var normalizedResults = normalizeAjaxResults(ajaxResponse.results);
          var hasMorePages = false;

          if (
            ajaxResponse.pagination &&
            typeof ajaxResponse.pagination.more !== 'undefined'
          ) {
            hasMorePages = !!ajaxResponse.pagination.more;
          }

          return {
            results: normalizedResults,
            pagination: {
              more: hasMorePages
            }
          };
        },
        cache: true
      };
    }

    if (isStaticSelect) {
      select2Configuration.minimumResultsForSearch = Infinity;
    }

    if (!isMultipleSelection) {
      select2Configuration.allowClear = true;
    }

    if ($drawerContainer.length) {
      select2Configuration.dropdownParent = $drawerContainer;
    }

    try {
      $selectElement.select2(select2Configuration);
    } catch (initializationError) {
      console.error(
        'Maradigma: failed to initialize Select2 for source "' + sourceName + '".',
        initializationError,
        $selectElement
      );
    }
  }

  function initAll(rootElement) {
    var $ = window.jQuery;
    if (!$) {
      return;
    }

    var $root = rootElement ? $(rootElement) : $(document);
    var remoteSelector = 'select.maradigma-remote-select, select[data-md-select2="1"][data-maradigma-source], select.maradigma-el-remote-select';
    var $remoteCandidates = $root.find(remoteSelector);

    $remoteCandidates.each(function () {
      initAjaxSelect2ForElement($(this));
    });
  }

  api.initAll = initAll;

  api.initOne = function (singleElement) {
    initAjaxSelect2ForElement(window.jQuery(singleElement));
  };

  return api;
})();

/**
 * Global init helper for dynamically injected content
 * (Elementor panel, AJAX fragments, etc.)
 */
window.MaradigmaRemoteSelect2Init = function (rootElement) {
  if (
    window.MaradigmaRemoteSelect2Shared &&
    typeof window.MaradigmaRemoteSelect2Shared.initAll === 'function'
  ) {
    window.MaradigmaRemoteSelect2Shared.initAll(rootElement);
  }
};

document.addEventListener('DOMContentLoaded', function () {
  if (
    window.MaradigmaRemoteSelect2Shared &&
    typeof window.MaradigmaRemoteSelect2Shared.initAll === 'function'
  ) {
    window.MaradigmaRemoteSelect2Shared.initAll();
  }
});

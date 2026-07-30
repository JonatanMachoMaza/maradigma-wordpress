// assets/js/admin/elementor-widget.js
(function ($) {
  'use strict';

  var cfg     = window.MaradigmaElementor || {};
  var ajaxUrl = cfg.ajaxUrl || '';
  var nonce   = cfg.nonce || '';
  var search  = cfg.search || {};
  var i18n    = cfg.i18n || {};

  var delegatedEventsBound = false;
  var panelInitBound = false;

  function postAjax(action, payload, onDone) {
    if (!ajaxUrl) {
      if (onDone) onDone(null);
      return;
    }

    var data = $.extend({}, payload || {}, {
      action: action,
      nonce: nonce
    });

    $.ajax({
      url: ajaxUrl,
      method: 'POST',
      dataType: 'json',
      data: data
    }).done(function (resp) {
      if (onDone) onDone(resp);
    }).fail(function () {
      if (onDone) onDone(null);
    });
  }

  function ensureImagesInfoNode($control) {
    var $hint = $control.find('.maradigma-el-images-info');
    if ($hint.length) {
      return $hint;
    }

    $hint = $('<div class="maradigma-el-images-info" style="margin-top:6px;font-size:12px;opacity:.8;"></div>');
    $control.find('.elementor-control-content').append($hint);

    return $hint;
  }

  function getRepeaterRows($root) {
    return $root.find('.elementor-control-items .elementor-repeater-fields');
  }

  function getFieldSelectFromRow($row) {
    return $row.find('select[data-setting="field"]').first();
  }

  function getLabelInputFromRow($row) {
    return $row.find('input[data-setting="label"]').first();
  }

  function getRowTitleButton($row) {
    return $row.find('.elementor-repeater-row-item-title').first();
  }

  function getSelectedFieldLabelText($select) {
    if (!$select || !$select.length) {
      return '';
    }

    var value = String($select.val() || '').trim();
    if (!value) {
      return i18n.selectField || 'Select a field';
    }

    if (value === 'custom') {
      return i18n.customField || 'Custom key';
    }

    var $option = $select.find('option:selected').first();
    if (!$option.length) {
      return value;
    }

    return String($option.text() || '').trim();
  }

  function updateRepeaterRowTitle($row) {
    if (!$row || !$row.length) {
      return;
    }

    var $titleButton = getRowTitleButton($row);
    if (!$titleButton.length) {
      return;
    }

    var $labelInput  = getLabelInputFromRow($row);
    var $fieldSelect = getFieldSelectFromRow($row);

    var label = String(($labelInput.val() || '')).trim();
    if (label !== '') {
      $titleButton.text(label);
      return;
    }

    $titleButton.text(getSelectedFieldLabelText($fieldSelect));
  }

  function autoFillRepeaterLabelFromRow($row) {
    if (!$row || !$row.length) {
      return;
    }

    var $fieldSelect = getFieldSelectFromRow($row);
    var $labelInput  = getLabelInputFromRow($row);

    if (!$fieldSelect.length || !$labelInput.length) {
      updateRepeaterRowTitle($row);
      return;
    }

    var currentLabel = String($labelInput.val() || '').trim();
    var fieldValue   = String($fieldSelect.val() || '').trim();

    if (currentLabel === '' && fieldValue !== '' && fieldValue !== 'custom') {
      var selectedLabel = getSelectedFieldLabelText($fieldSelect);
      if (selectedLabel !== '') {
        $labelInput.val(selectedLabel).trigger('input');
      }
    }

    updateRepeaterRowTitle($row);
  }

  function collectUsedFieldValues($root) {
    var used = [];

    getRepeaterRows($root).each(function () {
      var $row = $(this);
      var $select = getFieldSelectFromRow($row);

      if (!$select.length) {
        return;
      }

      var value = String($select.val() || '').trim();
      if (!value || value === 'custom') {
        return;
      }

      used.push(value);
    });

    return used;
  }

  function refreshFieldOptionsAvailability($root) {
    if (!$root || !$root.length) {
      return;
    }

    getRepeaterRows($root).each(function () {
      var $row = $(this);
      var $select = getFieldSelectFromRow($row);

      if (!$select.length) {
        return;
      }

      var currentValue = String($select.val() || '').trim();
      var used = collectUsedFieldValues($root);

      $select.find('option').each(function () {
        var $option = $(this);
        var value = String($option.attr('value') || '').trim();

        if (value === '' || value === 'custom') {
          $option.prop('disabled', false).show();
          return;
        }

        if (value === currentValue) {
          $option.prop('disabled', false).show();
          return;
        }

        if (used.indexOf(value) !== -1) {
          $option.prop('disabled', true).hide();
          return;
        }

        $option.prop('disabled', false).show();
      });
    });
  }

  function syncAllRepeaterUi($root) {
    if (!$root || !$root.length) {
      return;
    }

    getRepeaterRows($root).each(function () {
      updateRepeaterRowTitle($(this));
    });

    refreshFieldOptionsAvailability($root);
  }

  function initAjaxSelect2ForElement($el) {
    if (!$el.length) {
      return;
    }

    if ($el.hasClass('select2-hidden-accessible') || $el.data('select2')) {
      return;
    }

    var $panel = $el.closest('#elementor-panel');

    var source     = ($el.data('maradigma-source') || '').toString().trim();
    var multiple   = String($el.data('multiple')) === '1';
    var settingKey = ($el.data('setting-key') || '').toString().trim();

    if (!settingKey) {
      console.warn('[Maradigma] Missing data-setting-key for select', $el);
      return;
    }

    var $control = $el.closest('.elementor-control');
    var $stack   = $control.closest('.elementor-controls-stack');
    var $hidden  = $stack.find('input[data-setting="' + settingKey + '"]').first();

    if (!$hidden.length) {
      console.warn('[Maradigma] Hidden input not found for', settingKey, $el);
      return;
    }

    var actionMap = {
      boat_types: search.boatTypesAction || 'maradigma_elementor_search_boat_types',
      boats:      search.boatsAction     || 'maradigma_elementor_search_boats',
      builders:   search.buildersAction  || 'maradigma_elementor_search_builders'
    };

    var placeholderMap = {
      boat_types: i18n.typesPlaceholder    || i18n.placeholderSingle || 'Select boat type',
      boats:      i18n.boatsPlaceholder    || i18n.placeholderMulti  || 'Search boats...',
      builders:   i18n.buildersPlaceholder || i18n.placeholderMulti  || 'Search builders...'
    };

    var action = actionMap[source];
    var placeholder = placeholderMap[source] || i18n.placeholderSingle || 'Select an option';

    if (!action) {
      console.warn('[Maradigma] No AJAX action for source:', source, $el);
      return;
    }

    var existing = ($hidden.val() || '').toString().trim();
    var existingIds = [];

    if (multiple) {
      existingIds = existing
        ? existing.split(',').map(function (s) { return s.trim(); }).filter(Boolean)
        : [];
    } else {
      existingIds = existing ? [existing] : [];
    }

    $el.select2({
      width: '100%',
      dropdownParent: $panel.length ? $panel : $(document.body),
      placeholder: placeholder,
      allowClear: true,
      multiple: multiple,
      minimumInputLength: 0,
      language: {
        inputTooShort: function () { return ''; },
        searching: function () { return i18n.searching || 'Searching…'; },
        noResults: function () { return i18n.noResults || 'No results found'; },
        errorLoading: function () { return i18n.errorLoading || 'Error loading results'; }
      },
      ajax: {
        url: ajaxUrl,
        dataType: 'json',
        delay: 250,
        data: function (params) {
          return {
            action: action,
            nonce: nonce,
            q: params.term || '',
            page: params.page || 1,
            page_size: cfg.pageSize || 20
          };
        },
        processResults: function (data, params) {
          params.page = params.page || 1;

          if (!data || !data.results) {
            return {
              results: [],
              pagination: { more: false }
            };
          }

          return data;
        },
        cache: true
      }
    });

    if (existingIds.length) {
      existingIds.forEach(function (id) {
        var text = '#' + id;
        var opt = new Option(text, id, true, true);
        $el.append(opt);
      });

      $el.trigger('change');
      $hidden.trigger('input');
    }

    $el.on('change', function () {
      var val = $el.val();

      if (multiple) {
        var ids = (val || []).map(String);
        $hidden.val(ids.join(',')).trigger('input');
      } else {
        $hidden.val(val ? String(val) : '').trigger('input');
      }
    });

    if (source === 'boats') {
      var $hint = ensureImagesInfoNode($control);

      function renderLoading() {
        $hint.text(i18n.imagesAvailableLoading || 'Images available: …');
      }

      function renderUnknown() {
        $hint.text(i18n.imagesAvailableUnknown || 'Images available: —');
      }

      function renderCount(n) {
        $hint.text((i18n.imagesAvailablePrefix || 'Images available: ') + String(n));
      }

      function fetchCountForBoatId(boatId) {
        var countAction = cfg.imagesCountAction || 'maradigma_get_boat_images_count';
        boatId = (boatId || '').toString().trim();

        if (!countAction || !boatId) {
          renderUnknown();
          return;
        }

        renderLoading();

        postAjax(countAction, { boat_id: boatId }, function (resp) {
          try {
            if (resp && resp.success && resp.data && typeof resp.data.count !== 'undefined' && resp.data.count !== null) {
              renderCount(resp.data.count);
              return;
            }
          } catch (e) {}

          renderUnknown();
        });
      }

      if (existingIds.length === 1) {
        fetchCountForBoatId(existingIds[0]);
      } else {
        renderUnknown();
      }

      $el.on('change', function () {
        var v = $el.val();
        var id = '';

        if (Array.isArray(v)) {
          id = v.length ? String(v[0]) : '';
        } else {
          id = v ? String(v) : '';
        }

        fetchCountForBoatId(id);
      });
    }
  }

  function initRemoteSelectsInControls() {
    var $controls = $('#elementor-controls');
    if (!$controls.length) {
      return;
    }

    if (typeof $.fn.select2 !== 'function') {
      return;
    }

    $controls.find('.maradigma-el-remote-select').each(function () {
      initAjaxSelect2ForElement($(this));
    });
  }

  function bindDelegatedRepeaterEvents() {
    if (delegatedEventsBound) {
      return;
    }

    delegatedEventsBound = true;

    var $doc = $(document);

    $doc.on('change.maradigmaRepeater', '#elementor-controls select[data-setting="field"]', function () {
      var $row = $(this).closest('.elementor-repeater-fields');
      var $controls = $('#elementor-controls');

      if (!$row.length) {
        return;
      }

      autoFillRepeaterLabelFromRow($row);
      syncAllRepeaterUi($controls);
    });

    $doc.on('input.maradigmaRepeater change.maradigmaRepeater keyup.maradigmaRepeater', '#elementor-controls input[data-setting="label"]', function () {
      var $row = $(this).closest('.elementor-repeater-fields');

      if (!$row.length) {
        return;
      }

      updateRepeaterRowTitle($row);
    });

    $doc.on('click.maradigmaRepeater', '#elementor-controls .elementor-repeater-add', function () {
      window.setTimeout(function () {
        var $controls = $('#elementor-controls');
        var $rows = getRepeaterRows($controls);

        if (!$rows.length) {
          return;
        }

        var $lastRow = $rows.last();
        updateRepeaterRowTitle($lastRow);
        syncAllRepeaterUi($controls);
      }, 80);
    });

    $doc.on('click.maradigmaRepeater', '#elementor-controls .elementor-repeater-tool-remove, #elementor-controls .elementor-repeater-tool-duplicate', function () {
      window.setTimeout(function () {
        var $controls = $('#elementor-controls');
        syncAllRepeaterUi($controls);
      }, 80);
    });
  }

  function initPanelUi() {
    var $controls = $('#elementor-controls');
    if (!$controls.length) {
      return;
    }

    initRemoteSelectsInControls();
    syncAllRepeaterUi($controls);
    bindDelegatedRepeaterEvents();
  }

  function attachElementorHook() {
    if (!window.elementor || !window.elementor.hooks) {
      return false;
    }

    if (panelInitBound) {
      return true;
    }

    panelInitBound = true;

    elementor.hooks.addAction('panel/open_editor/widget', function () {
      window.setTimeout(function () {
        initPanelUi();
      }, 50);
    });

    elementor.hooks.addAction('panel/open_editor/section', function () {
      window.setTimeout(function () {
        initPanelUi();
      }, 50);
    });

    return true;
  }

  var t = setInterval(function () {
    if (attachElementorHook()) {
      clearInterval(t);
    }
  }, 200);

})(jQuery);
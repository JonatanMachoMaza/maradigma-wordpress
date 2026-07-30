// assets/js/elementor-guards.js

(function ($) {
  'use strict';

  // Ajusta esto si cambias el nombre del widget o del control
  var WIDGET_NAME = 'maradigma_boats_archive';
  var CONTROL_NAME = 'filters_ui_fields_repeater';

  function getPanelRoot() {
    return $('#elementor-panel');
  }

  function isOurWidgetSelected() {
    // En el panel, el widget seleccionado suele estar en el breadcrumb / header.
    // Esta comprobación es "best effort".
    var $panel = getPanelRoot();
    if (!$panel.length) return false;

    // Elementor pone el nombre del widget en data-widget_type en algunos nodos.
    // Alternativamente, nos basta con buscar el control repeater (si está visible, es nuestro widget).
    return $panel.find('.elementor-control-' + CONTROL_NAME).length > 0;
  }

  function collectSelectedValues($panel) {
    var values = [];
    $panel.find('.elementor-control-' + CONTROL_NAME + ' select[data-setting="field"]').each(function () {
      var v = String($(this).val() || '').trim();
      if (v) values.push(v);
    });
    return values;
  }

  function refreshDisabledOptions() {
    var $panel = getPanelRoot();
    if (!$panel.length) return;
    if (!isOurWidgetSelected()) return;

    var $selects = $panel.find('.elementor-control-' + CONTROL_NAME + ' select[data-setting="field"]');
    if (!$selects.length) return;

    // 1) contamos ocurrencias
    var counts = {};
    $selects.each(function () {
      var v = String($(this).val() || '').trim();
      if (!v) return;
      counts[v] = (counts[v] || 0) + 1;
    });

    // 2) deshabilitamos opciones ya usadas en OTROS selects
    $selects.each(function () {
      var $sel = $(this);
      var current = String($sel.val() || '').trim();

      $sel.find('option').each(function () {
        var $opt = $(this);
        var key = String($opt.attr('value') || '').trim();
        if (!key) return;

        // Si está usado y NO es el valor actual, lo deshabilitamos
        if (counts[key] && key !== current) {
          $opt.prop('disabled', true);
        } else {
          $opt.prop('disabled', false);
        }
      });

      // 3) Si el actual está duplicado (>1), lo marcamos visualmente
      var isDup = current && counts[current] > 1;
      $sel.toggleClass('maradigma-is-duplicate', !!isDup);
    });

    // (opcional) pequeño aviso arriba del control si hay duplicados
    var hasDup = Object.keys(counts).some(function (k) { return counts[k] > 1; });

    var $ctrl = $panel.find('.elementor-control-' + CONTROL_NAME);
    $ctrl.find('.maradigma-dup-warning').remove();

    if (hasDup) {
      $ctrl.find('.elementor-control-title').first().after(
        '<div class="maradigma-dup-warning" style="margin-top:6px;color:#b42318;font-size:12px;">' +
        'There are duplicated fields. Each field should be used only once.' +
        '</div>'
      );
    }
  }

  // Disparadores típicos del editor
  function bindGuards() {
    var $panel = getPanelRoot();

    // Cuando cambia un select de field
    $panel.on('change', '.elementor-control-' + CONTROL_NAME + ' select[data-setting="field"]', function () {
      refreshDisabledOptions();
    });

    // Cuando se abre/cambia panel o se añade/elimina row del repeater
    // (Elementor dispara muchos eventos; nosotros hacemos refresh “best effort”)
    $(document).on('click', '.elementor-repeater-add, .elementor-repeater-tool-remove, .elementor-repeater-tool-duplicate', function () {
      setTimeout(refreshDisabledOptions, 50);
    });

    // Tick inicial
    setTimeout(refreshDisabledOptions, 200);

    // Y refresco cada vez que el panel re-renderiza
    // (simple polling ligero para asegurar)
    setInterval(function () {
      refreshDisabledOptions();
    }, 800);
  }

  // Cuando Elementor editor está listo
  $(window).on('elementor:init', function () {
    bindGuards();
  });

})(jQuery);

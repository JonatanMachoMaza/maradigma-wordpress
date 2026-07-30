// assets/js/admin/settings-seo.js
(function ($) {
  'use strict';

  var $modal = null;
  var $select = null;
  var $btnInsert = null;
  var $btnClose = null;

  var currentTargetSelector = null;

  function ensureModal() {
    if ($modal && $modal.length) return true;

    $modal = $('#maradigma-token-modal');
    if (!$modal.length) return false;

    $select = $('#maradigma-seo-token-select');
    $btnInsert = $('#maradigma-token-modal-insert');
    $btnClose = $('#maradigma-token-modal-close');

    return true;
  }

  function openModal(targetSelector) {
    if (!ensureModal()) {
      console.warn('Maradigma SEO: token modal not found in DOM.');
      return;
    }

    currentTargetSelector = targetSelector || null;

    // reset select
    if ($select && $select.length) {
      $select.val('');
    }

    $modal.show();

    // focus select for quick use
    setTimeout(function () {
      if ($select && $select.length) {
        $select.trigger('focus');
      }
    }, 50);
  }

  function closeModal() {
    if (!ensureModal()) return;

    $modal.hide();
    currentTargetSelector = null;
  }

  function insertToken() {
    if (!ensureModal()) return;
    if (!$select || !$select.length) return;

    var token = $select.val();
    if (!token) return;

    var $target = currentTargetSelector ? $(currentTargetSelector) : $();
    if (!$target.length) {
      console.warn('Maradigma SEO: target input not found:', currentTargetSelector);
      return;
    }

    var el = $target.get(0);

    // Insert at cursor (input/textarea)
    var value = $target.val() || '';
    var start = 0;
    var end = 0;

    if (typeof el.selectionStart === 'number' && typeof el.selectionEnd === 'number') {
      start = el.selectionStart;
      end = el.selectionEnd;
    } else {
      // fallback: append
      start = value.length;
      end = value.length;
    }

    var newValue = value.substring(0, start) + token + value.substring(end);
    $target.val(newValue);

    // Move cursor after inserted token
    if (typeof el.selectionStart === 'number' && typeof el.selectionEnd === 'number') {
      var cursor = start + String(token).length;
      el.selectionStart = el.selectionEnd = cursor;
    }

    // Trigger change for WP
    $target.trigger('input').trigger('change');

    // Keep modal open (as your UI says)
    $select.val('');
    $select.trigger('focus');
  }

  function bindEvents() {
    // Open modal button
    $(document).on('click', '[data-maradigma-open-token-picker]', function (e) {
      e.preventDefault();

      var target = $(this).attr('data-target') || $(this).data('target') || '';
      if (!target) {
        console.warn('Maradigma SEO: missing data-target on token picker button');
        return;
      }

      openModal(target);
    });

    // Insert button inside modal
    $(document).on('click', '#maradigma-token-modal-insert', function (e) {
      e.preventDefault();
      insertToken();
    });

    // Close button
    $(document).on('click', '#maradigma-token-modal-close', function (e) {
      e.preventDefault();
      closeModal();
    });

    // Click outside (overlay) closes
    $(document).on('click', '#maradigma-token-modal', function (e) {
      // only if user clicks overlay itself, not modal inner content
      if (e.target === this) {
        closeModal();
      }
    });

    // ESC closes
    $(document).on('keydown', function (e) {
      if (e.key === 'Escape') {
        if ($modal && $modal.is(':visible')) {
          closeModal();
        }
      }
    });

    // Enter inserts (when select focused)
    $(document).on('keydown', '#maradigma-seo-token-select', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        insertToken();
      }
    });
  }

  $(document).ready(function () {
    // Only run if modal exists (SEO tab)
    if (!$('#maradigma-token-modal').length) {
      return;
    }

    bindEvents();
  });
})(jQuery);

// assets/js/admin-page-boats.js
(function ($) {
    'use strict';

    var maradigmaBoatsAdmin = window.MaradigmaBoatsAdmin || {};
    var ajaxUrl = maradigmaBoatsAdmin.ajaxUrl || '';
    var nonce   = maradigmaBoatsAdmin.nonce || '';
    var search  = maradigmaBoatsAdmin.search || {};
    var i18n    = maradigmaBoatsAdmin.i18n || {};

    /**
     * Normalize boolean-like values from data-* attributes.
     *
     * @param {*} v
     * @return {boolean}
     */
    function toBool(v) {
        return (v === true || v === 'true' || v === 1 || v === '1');
    }

    /**
     * Build Select2 language map.
     *
     * @param {number} minLen
     * @return {Object}
     */
    function buildLanguage(minLen) {
        return {
            inputTooShort: function () {
                if (minLen <= 0) return '';
                // If you want dynamic message with minLen, you can adjust here.
                return i18n.inputTooShort || 'Type at least 2 characters';
            },
            searching: function () { return i18n.searching || 'Searching…'; },
            noResults: function () { return i18n.noResults || 'No results found'; },
            errorLoading: function () { return i18n.errorLoading || 'Error loading results'; }
        };
    }

    /**
     * Try to "hydrate" the selected option label (when editing an existing post),
     * so we don't show the fallback "Boat #123" text.
     *
     * Requires a backend AJAX action that returns:
     * { success:true, item:{ id: "891", text:"Sunseeker · 52 · OTAZU" } }
     *
     * @param {jQuery} $el
     * @param {string} source
     */
    function hydrateSelectedOptionIfNeeded($el, source) {
        if (!$el || !$el.length) return;

        // Only for boats (we care about the "Boat #ID" fallback)
        if (source !== 'boats') return;

        var currentVal = $el.val();
        if (!currentVal) return;

        var $selectedOpt = $el.find('option:selected');
        if (!$selectedOpt.length) return;

        var currentText = String($selectedOpt.text() || '').trim();
        var looksLikeFallback = /^Boat\s*#\s*\d+$/i.test(currentText);

        if (!looksLikeFallback) {
            return;
        }

        var getByIdAction = search.boatByIdAction || 'maradigma_admin_get_boat_by_id';

        $.ajax({
            url: ajaxUrl,
            method: 'GET',
            dataType: 'json',
            data: {
                action: getByIdAction,
                nonce: nonce,
                id: currentVal
            }
        }).done(function (data) {
            if (!data || data.success !== true || !data.item || !data.item.id) {
                return;
            }

            // Replace the option with hydrated label
            var opt = new Option(String(data.item.text || ('Boat #' + data.item.id)), String(data.item.id), true, true);
            $el.empty().append(opt).val(String(data.item.id)).trigger('change').trigger('change.select2');
        }).fail(function () {
            // silent
        });
    }

    /**
     * Initialize Select2 + AJAX for a specific element.
     *
     * @param {jQuery} $el
     */
    function initAjaxSelect2ForElement($el) {
        if (!$el || !$el.length) {
            return;
        }

        if (typeof $.fn.select2 !== 'function') {
            console.warn('Maradigma: Select2 is not available in admin.');
            return;
        }

        // Even if another initializer already mounted Select2, still hydrate
        // the saved label so the edit screen does not stay on "Boat #ID".
        if ($el.hasClass('select2-hidden-accessible')) {
            hydrateSelectedOptionIfNeeded($el, String($el.data('maradigma-source') || '').trim());
            return;
        }

        var source = String($el.data('maradigma-source') || '').trim(); // boat_types | tags | builders | boats
        if (!source) {
            console.warn('Maradigma: missing data-maradigma-source on select:', $el);
            return;
        }

        var multiple = toBool($el.data('multiple'));

        var actionMap = {
            boat_types: search.boatTypesAction || 'maradigma_admin_search_boat_types',
            tags:       search.tagsAction      || 'maradigma_admin_search_tags',
            builders:   search.buildersAction  || 'maradigma_admin_search_builders',
            boats:      search.boatsAction     || 'maradigma_admin_search_boats',

            // ✅ NEW: fetch single boat by ID to hydrate label on edit screens
            boats_by_id: search.boatByIdAction || 'maradigma_admin_get_boat_by_id'
        };

        var placeholderMap = {
            boat_types: i18n.i18nByTypeType     || i18n.placeholderSingle || 'Select boat type',
            tags:       i18n.i18nCustomTags     || i18n.placeholderMulti  || 'Select tags',
            builders:   i18n.i18nCustomBuilders || i18n.placeholderMulti  || 'Select builders',
            boats:      i18n.i18nSelectBoat     || i18n.placeholderSingle || 'Search a boat'
        };

        var action = actionMap[source];
        if (!action) {
            console.warn('Maradigma: no AJAX action for source:', source, $el);
            return;
        }

        var placeholder = placeholderMap[source] || i18n.placeholderSingle || 'Select an option';

        // Better UX/performance: for "boats" require at least 2 chars (avoid huge listings)
        var minLen = (source === 'boats') ? 2 : 0;

        // ✅ If this select has a preselected value (edit screen), hydrate its label
        // Must run BEFORE select2 init so it shows the correct label immediately.
        hydrateSelectedOptionIfNeeded($el, source);

        $el.select2({
            width: '100%',
            placeholder: placeholder,
            allowClear: true,
            multiple: multiple,
            language: buildLanguage(minLen),
            ajax: {
                url: ajaxUrl,
                dataType: 'json',
                delay: 250,
                data: function (params) {
                    return {
                        action: action,
                        nonce: nonce,
                        q: params.term || '',
                        page: params.page || 1
                    };
                },
                processResults: function (data, params) {
                    params.page = params.page || 1;

                    if (!data || data.success !== true) {
                        return { results: [], pagination: { more: false } };
                    }

                    var results = data.results || [];
                    var more = false;

                    if (data.pagination && typeof data.pagination.more !== 'undefined') {
                        more = !!data.pagination.more;
                    }

                    return {
                        results: results,
                        pagination: { more: more }
                    };
                },
                cache: true
            },
            minimumInputLength: minLen
        });

        window.setTimeout(function () {
            hydrateSelectedOptionIfNeeded($el, source);
        }, 150);
    }

    /**
     * Page binding metabox:
     * - checkbox: maradigma_is_boat_page
     * - select:   maradigma_page_boat_id
     */
    function initBoatPageBindingMetaBox() {
        var $toggle = $('#maradigma_is_boat_page');
        var $wrap   = $('.maradigma-boat-page-binding');
        var $select = $('#maradigma_page_boat_id');

        if (!$toggle.length || !$wrap.length || !$select.length) {
            return;
        }

        // Toggle show/hide first, so this still works even if Select2 fails.
        syncBoatPageBindingVisibility();

        // Init select2 after the basic UI behavior is already wired.
        try {
            initAjaxSelect2ForElement($select);
        } catch (select2Error) {
            console.warn('Maradigma: boat page binding Select2 failed to initialize.', select2Error);
        }
    }

    function syncBoatPageBindingVisibility() {
        var $toggle = $('#maradigma_is_boat_page');
        var $wrap   = $('.maradigma-boat-page-binding');
        var $select = $('#maradigma_page_boat_id');

        if (!$toggle.length || !$wrap.length) {
            return;
        }

        if ($toggle.is(':checked')) {
            $wrap.show();
        } else {
            $wrap.hide();
            if ($select.length) {
                $select.val(null).trigger('change');
            }
        }
    }

    /**
     * Boat CPT binding metabox:
     * - select: maradigma_cpt_boat_id
     */
    function initBoatCptBindingMetaBox() {
        var $select = $('#maradigma_cpt_boat_id');
        if (!$select.length) {
            return;
        }
        initAjaxSelect2ForElement($select);
    }

    /**
     * LEGACY listing modes UI (safe to remove once BoatsAdminPage is removed).
     */
    function initListingModeUIIfExists() {
        var $modeRadios = $('input[name="maradigma_boats_page[mode]"]');
        if (!$modeRadios.length) {
            return;
        }

        function updateModeUI() {
            var mode = $modeRadios.filter(':checked').val() || '';

            $('#maradigma_boats_section_by_type').hide();
            $('#maradigma_boats_section_multiple_types').hide();
            $('#maradigma_boats_section_custom').hide();

            if (mode === 'by_type') {
                $('#maradigma_boats_section_by_type').show();
            } else if (mode === 'multiple_types') {
                $('#maradigma_boats_section_multiple_types').show();
            } else if (mode === 'custom_list') {
                $('#maradigma_boats_section_custom').show();
            }
        }

        updateModeUI();
        $modeRadios.on('change', updateModeUI);
    }

    $(document).ready(function () {
        $(document).on('change', '#maradigma_is_boat_page', syncBoatPageBindingVisibility);

        // 1) Page binding (checkbox + select)
        initBoatPageBindingMetaBox();

        // 2) Boat CPT binding (select only)
        initBoatCptBindingMetaBox();

        // 3) Legacy listing UI (optional)
        initListingModeUIIfExists();

        // 4) Initialize any other remote selects present (metaboxes, etc.)
        $('.maradigma-remote-select').each(function () {
            initAjaxSelect2ForElement($(this));
        });
    });

})(jQuery);

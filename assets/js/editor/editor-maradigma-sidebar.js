// assets/js/editor-maradigma-sidebar.js
(function (wp) {
    if (!wp || !wp.plugins || !wp.editPost || !wp.data || !wp.element || !wp.components) {
        return;
    }

    const { registerPlugin } = wp.plugins;
    const { PluginSidebar, PluginSidebarMoreMenuItem } = wp.editPost;

    const {
        PanelBody,
        SelectControl,
        TextControl,
        ToggleControl,
        BaseControl,
        Spinner,
        Button,
        Notice,
        Flex,
        FlexItem,
        __experimentalComboboxControl: ComboboxControl, // WP 6.9 suele tenerlo
    } = wp.components;

    const { Fragment, createElement: el, useEffect, useMemo, useRef, useState } = wp.element;
    const { __ } = wp.i18n;
    const { withSelect, withDispatch } = wp.data;

    /**
     * CONFIG
     * - Este meta DEBE existir y estar expuesto a REST con register_post_meta.
     * - Debe coincidir con tu self::META_KEY en PHP.
     */
    const META_KEY = '_maradigma_boats_page';

    /**
     * AJAX CONFIG (lo ideal es que lo pases desde PHP con wp_localize_script)
     * MaradigmaBoatsAdmin = {
     *   ajaxUrl, nonce,
     *   search: { boatTypesAction, tagsAction, buildersAction, boatsAction },
     *   i18n: { ... }
     * }
     */
    const AdminCfg = (typeof window.MaradigmaBoatsAdmin === 'object' && window.MaradigmaBoatsAdmin)
        ? window.MaradigmaBoatsAdmin
        : null;

    // Helpers
    function isObj(v) {
        return v !== null && typeof v === 'object' && !Array.isArray(v);
    }

    function toInt(v) {
        const n = parseInt(String(v || ''), 10);
        return Number.isFinite(n) && n > 0 ? n : 0;
    }

    function uniqInts(arr) {
        const out = [];
        const seen = new Set();
        (Array.isArray(arr) ? arr : []).forEach((v) => {
            const n = toInt(v);
            if (n > 0 && !seen.has(n)) {
                seen.add(n);
                out.push(n);
            }
        });
        return out;
    }

    function safeCfg(cfg) {
        const base = isObj(cfg) ? cfg : {};
        const custom = isObj(base.custom_listing) ? base.custom_listing : {};

        return {
            mode: typeof base.mode === 'string' ? base.mode : '',
            by_type_gc_type: toInt(base.by_type_gc_type),

            multiple_types_gc_type: uniqInts(base.multiple_types_gc_type),

            custom_listing: {
                id_gc_type: uniqInts(custom.id_gc_type),
                tags: uniqInts(custom.tags),
                boat_builders: uniqInts(custom.boat_builders),
                boat_ids: uniqInts(custom.boat_ids),

                bareboat: !!custom.bareboat,
                discount: !!custom.discount,
                ins_book: !!custom.ins_book,
                featured: !!custom.featured,
                last_minute: !!custom.last_minute,
            },
        };
    }

    function deepMerge(a, b) {
        // Merge simple (objetos + arrays de ints)
        const out = { ...(isObj(a) ? a : {}) };
        Object.keys(isObj(b) ? b : {}).forEach((k) => {
            const av = out[k];
            const bv = b[k];
            if (isObj(av) && isObj(bv)) out[k] = deepMerge(av, bv);
            else out[k] = bv;
        });
        return out;
    }

    /**
     * Remote search via admin-ajax.
     * Usa tus acciones:
     * - maradigma_admin_search_boat_types
     * - maradigma_admin_search_tags
     * - maradigma_admin_search_builders
     * - maradigma_admin_search_boats
     *
     * Espera respuesta tipo Select2:
     * { success:true, results:[{id,text}], pagination:{more:boolean} }
     */
    async function ajaxSearch(action, q, page) {
        if (!AdminCfg || !AdminCfg.ajaxUrl || !action) {
            return { ok: false, results: [], more: false, error: 'Missing AdminCfg' };
        }

        const url = AdminCfg.ajaxUrl;
        const body = new URLSearchParams();
        body.set('action', action);
        body.set('q', q || '');
        body.set('page', String(page || 1));

        if (AdminCfg.nonce) body.set('nonce', AdminCfg.nonce);

        const res = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString(),
        });

        // Si te devuelve HTML (login), aquí lo detectarás
        const text = await res.text();

        let json = null;
        try {
            json = JSON.parse(text);
        } catch (e) {
            return { ok: false, results: [], more: false, error: 'Non-JSON response (maybe HTML).', raw: text };
        }

        if (!json || json.success !== true) {
            const msg = (json && json.error && json.error.message) ? json.error.message : 'API error';
            return { ok: false, results: [], more: false, error: msg, raw: json };
        }

        const results = Array.isArray(json.results) ? json.results : [];
        const more = !!(json.pagination && json.pagination.more);

        return { ok: true, results, more, raw: json };
    }

    /**
     * UI: Selector múltiple con búsqueda remota (simple)
     * - muestra seleccionados como "chips" (Botones)
     * - input de búsqueda + lista de resultados
     */
    function RemoteMultiPicker(props) {
        const {
            label,
            help,
            valueIds,
            onChangeIds,
            sourceKey, // 'boat_types' | 'tags' | 'builders' | 'boats'
            placeholder,
        } = props;

        const [query, setQuery] = useState('');
        const [page, setPage] = useState(1);
        const [loading, setLoading] = useState(false);
        const [items, setItems] = useState([]);
        const [more, setMore] = useState(false);
        const [error, setError] = useState('');

        const action = useMemo(() => {
            if (!AdminCfg || !AdminCfg.search) return '';
            if (sourceKey === 'boat_types') return AdminCfg.search.boatTypesAction;
            if (sourceKey === 'tags') return AdminCfg.search.tagsAction;
            if (sourceKey === 'builders') return AdminCfg.search.buildersAction;
            if (sourceKey === 'boats') return AdminCfg.search.boatsAction;
            return '';
        }, [sourceKey]);

        const selectedIds = Array.isArray(valueIds) ? valueIds : [];

        const load = async (reset) => {
            setError('');
            setLoading(true);
            const nextPage = reset ? 1 : page;

            const res = await ajaxSearch(action, query, nextPage);

            if (!res.ok) {
                setLoading(false);
                setItems([]);
                setMore(false);
                setError(res.error || 'Error');
                return;
            }

            const mapped = res.results.map((r) => ({
                id: toInt(r.id),
                text: String(r.text || ''),
            })).filter((r) => r.id > 0 && r.text);

            setLoading(false);

            if (reset) {
                setItems(mapped);
                setPage(1);
            } else {
                setItems((prev) => prev.concat(mapped));
            }
            setMore(res.more);
        };

        // Carga inicial o cuando cambia query
        useEffect(() => {
            // si no hay config, no intentes
            if (!AdminCfg) return;
            load(true);
            // eslint-disable-next-line react-hooks/exhaustive-deps
        }, [query, action]);

        const addId = (id) => {
            const n = toInt(id);
            if (n <= 0) return;
            const next = uniqInts(selectedIds.concat([n]));
            onChangeIds(next);
        };

        const removeId = (id) => {
            const n = toInt(id);
            const next = selectedIds.filter((x) => x !== n);
            onChangeIds(next);
        };

        return el(
            BaseControl,
            { label: label, help: help },
            el(Flex, { gap: 2, style: { marginBottom: '8px' } },
                el(FlexItem, { style: { flexGrow: 1 } },
                    el(TextControl, {
                        label: '',
                        value: query,
                        placeholder: placeholder || __('Search…', 'maradigma'),
                        onChange: (v) => setQuery(v || ''),
                    })
                ),
                el(FlexItem, null, loading ? el(Spinner, null) : null)
            ),

            error ? el(Notice, { status: 'error', isDismissible: true, onRemove: () => setError('') }, error) : null,

            // Resultados
            el('div', { style: { border: '1px solid #e5e7eb', borderRadius: '6px', padding: '8px', maxHeight: '180px', overflow: 'auto' } },
                items.length === 0 && !loading
                    ? el('div', { style: { color: '#6b7280', fontSize: '12px' } }, __('No results.', 'maradigma'))
                    : items.map((it) => {
                        const isSelected = selectedIds.includes(it.id);
                        return el(
                            'div',
                            { key: String(it.id), style: { display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '4px 0' } },
                            el('span', null, it.text),
                            el(Button, {
                                isSecondary: true,
                                onClick: () => addId(it.id),
                                disabled: isSelected,
                            }, isSelected ? __('Added', 'maradigma') : __('Add', 'maradigma'))
                        );
                    }),
                more
                    ? el(Button, { isSecondary: true, onClick: () => { setPage((p) => p + 1); load(false); }, style: { marginTop: '8px' } }, __('Load more', 'maradigma'))
                    : null
            ),

            // Seleccionados
            el('div', { style: { marginTop: '10px' } },
                el('div', { style: { fontSize: '12px', color: '#6b7280', marginBottom: '6px' } }, __('Selected:', 'maradigma')),
                selectedIds.length === 0
                    ? el('div', { style: { fontSize: '12px', color: '#9ca3af' } }, __('None', 'maradigma'))
                    : el('div', { style: { display: 'flex', flexWrap: 'wrap', gap: '6px' } },
                        selectedIds.map((id) =>
                            el(Button, {
                                key: String(id),
                                isSmall: true,
                                isSecondary: true,
                                onClick: () => removeId(id),
                            }, `#${id} ×`)
                        )
                    )
            )
        );
    }

    /**
     * MAIN PANEL UI
     */
    const MaradigmaMetaBase = (props) => {
        const cfg = safeCfg(props.cfg);

        const setCfg = (partial) => {
            const next = safeCfg(deepMerge(cfg, partial));
            props.setCfg(next);
        };

        const mode = cfg.mode;

        // UI text fallback
        const i18n = (AdminCfg && AdminCfg.i18n) ? AdminCfg.i18n : {};

        // If no AdminCfg, warn
        const missingCfgNotice = !AdminCfg
            ? el(
                Notice,
                { status: 'warning', isDismissible: false },
                __('MaradigmaBoatsAdmin config is missing. Enqueue/admin localize is not loaded.', 'maradigma')
            )
            : null;

        return el(
            Fragment,
            null,

            missingCfgNotice,

            el(
                PanelBody,
                { title: __('Maradigma - Boats settings', 'maradigma'), initialOpen: true },
                el(SelectControl, {
                    label: __('Boats page mode', 'maradigma'),
                    value: mode,
                    options: [
                        { label: __('No special listing', 'maradigma'), value: '' },
                        { label: __('Listing', 'maradigma'), value: 'listing' },
                        { label: __('By type (single type)', 'maradigma'), value: 'by_type' },
                        { label: __('Multiple types', 'maradigma'), value: 'multiple_types' },
                        { label: __('Custom list', 'maradigma'), value: 'custom_list' },
                    ],
                    onChange: (v) => setCfg({ mode: v || '' }),
                    __next40pxDefaultSize: true,
                    __nextHasNoMarginBottom: true,
                }),
                el('p', null, __('These settings control how this page will show boats using Maradigma.', 'maradigma'))
            ),

            // By type
            mode === 'by_type'
                ? el(
                    PanelBody,
                    { title: __('By type', 'maradigma'), initialOpen: true },
                    AdminCfg
                        ? el(RemoteMultiPicker, {
                            label: i18n.i18nByTypeType || __('Boat type', 'maradigma'),
                            help: __('Select exactly one type.', 'maradigma'),
                            valueIds: cfg.by_type_gc_type > 0 ? [cfg.by_type_gc_type] : [],
                            onChangeIds: (ids) => setCfg({ by_type_gc_type: (ids && ids[0]) ? toInt(ids[0]) : 0 }),
                            sourceKey: 'boat_types',
                            placeholder: i18n.searching || __('Search…', 'maradigma'),
                        })
                        : el(TextControl, {
                            label: __('Boat type id', 'maradigma'),
                            value: cfg.by_type_gc_type ? String(cfg.by_type_gc_type) : '',
                            onChange: (v) => setCfg({ by_type_gc_type: toInt(v) }),
                        })
                )
                : null,

            // Multiple types
            mode === 'multiple_types'
                ? el(
                    PanelBody,
                    { title: __('Multiple types', 'maradigma'), initialOpen: true },
                    AdminCfg
                        ? el(RemoteMultiPicker, {
                            label: i18n.i18nMultiTypes || __('Boat types', 'maradigma'),
                            help: __('Select one or more types.', 'maradigma'),
                            valueIds: cfg.multiple_types_gc_type,
                            onChangeIds: (ids) => setCfg({ multiple_types_gc_type: uniqInts(ids) }),
                            sourceKey: 'boat_types',
                            placeholder: i18n.searching || __('Search…', 'maradigma'),
                        })
                        : null
                )
                : null,

            // Custom listing
            mode === 'custom_list'
                ? el(
                    PanelBody,
                    { title: __('Custom listing', 'maradigma'), initialOpen: true },

                    AdminCfg
                        ? el(RemoteMultiPicker, {
                            label: i18n.i18nCustomServiceTypes || __('Service types', 'maradigma'),
                            help: __('Service/boat types to include.', 'maradigma'),
                            valueIds: cfg.custom_listing.id_gc_type,
                            onChangeIds: (ids) => setCfg({ custom_listing: { ...cfg.custom_listing, id_gc_type: uniqInts(ids) } }),
                            sourceKey: 'boat_types',
                        })
                        : null,

                    AdminCfg
                        ? el(RemoteMultiPicker, {
                            label: i18n.i18nCustomTags || __('Tags', 'maradigma'),
                            help: __('Filter by tags.', 'maradigma'),
                            valueIds: cfg.custom_listing.tags,
                            onChangeIds: (ids) => setCfg({ custom_listing: { ...cfg.custom_listing, tags: uniqInts(ids) } }),
                            sourceKey: 'tags',
                        })
                        : null,

                    AdminCfg
                        ? el(RemoteMultiPicker, {
                            label: i18n.i18nCustomBuilders || __('Builders', 'maradigma'),
                            help: __('Filter by builders.', 'maradigma'),
                            valueIds: cfg.custom_listing.boat_builders,
                            onChangeIds: (ids) => setCfg({ custom_listing: { ...cfg.custom_listing, boat_builders: uniqInts(ids) } }),
                            sourceKey: 'builders',
                        })
                        : null,

                    AdminCfg
                        ? el(RemoteMultiPicker, {
                            label: i18n.i18nCustomBoats || __('Specific boats', 'maradigma'),
                            help: __('Pick specific boats (searchable).', 'maradigma'),
                            valueIds: cfg.custom_listing.boat_ids,
                            onChangeIds: (ids) => setCfg({ custom_listing: { ...cfg.custom_listing, boat_ids: uniqInts(ids) } }),
                            sourceKey: 'boats',
                        })
                        : null,

                    el('hr', null),

                    el(ToggleControl, {
                        label: __('Bareboat', 'maradigma'),
                        checked: !!cfg.custom_listing.bareboat,
                        onChange: (v) => setCfg({ custom_listing: { ...cfg.custom_listing, bareboat: !!v } }),
                    }),
                    el(ToggleControl, {
                        label: __('Discount', 'maradigma'),
                        checked: !!cfg.custom_listing.discount,
                        onChange: (v) => setCfg({ custom_listing: { ...cfg.custom_listing, discount: !!v } }),
                    }),
                    el(ToggleControl, {
                        label: __('Instant booking', 'maradigma'),
                        checked: !!cfg.custom_listing.ins_book,
                        onChange: (v) => setCfg({ custom_listing: { ...cfg.custom_listing, ins_book: !!v } }),
                    }),
                    el(ToggleControl, {
                        label: __('Featured', 'maradigma'),
                        checked: !!cfg.custom_listing.featured,
                        onChange: (v) => setCfg({ custom_listing: { ...cfg.custom_listing, featured: !!v } }),
                    }),
                    el(ToggleControl, {
                        label: __('Last minute', 'maradigma'),
                        checked: !!cfg.custom_listing.last_minute,
                        onChange: (v) => setCfg({ custom_listing: { ...cfg.custom_listing, last_minute: !!v } }),
                    })
                )
                : null
        );
    };

    // HOC connect: leer y escribir meta completo
    const MaradigmaMetaPanel = withSelect((select) => {
        const meta = select('core/editor').getEditedPostAttribute('meta') || {};
        return {
            cfg: meta[META_KEY] || {},
        };
    })(
        withDispatch((dispatch) => ({
            setCfg(nextCfg) {
                dispatch('core/editor').editPost({
                    meta: {
                        [META_KEY]: nextCfg,
                    },
                });
            },
        }))(MaradigmaMetaBase)
    );

    const MaradigmaSidebar = () =>
        el(
            Fragment,
            null,
            el(
                PluginSidebarMoreMenuItem,
                { target: 'maradigma-sidebar' },
                __('Maradigma', 'maradigma')
            ),
            el(
                PluginSidebar,
                { name: 'maradigma-sidebar', title: __('Maradigma', 'maradigma') },
                el(MaradigmaMetaPanel, null)
            )
        );

    registerPlugin('maradigma-sidebar', {
        icon: 'admin-site-alt3',
        render: MaradigmaSidebar,
    });
})(window.wp);

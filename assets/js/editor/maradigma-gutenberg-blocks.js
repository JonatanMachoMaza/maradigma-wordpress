(function (wp) {
    'use strict';

    if (!wp || !wp.blocks || !wp.element || !wp.blockEditor || !wp.components) {
        return;
    }

    var registerBlockType = wp.blocks.registerBlockType;
    var el = wp.element.createElement;
    var Fragment = wp.element.Fragment;
    var useEffect = wp.element.useEffect;
    var useRef = wp.element.useRef;
    var useState = wp.element.useState;
    var __ = wp.i18n && wp.i18n.__ ? wp.i18n.__ : function (value) { return value; };
    var registerPlugin = wp.plugins && wp.plugins.registerPlugin ? wp.plugins.registerPlugin : null;
    var PluginDocumentSettingPanel = wp.editPost && wp.editPost.PluginDocumentSettingPanel ? wp.editPost.PluginDocumentSettingPanel : null;
    var useSelect = wp.data && wp.data.useSelect ? wp.data.useSelect : null;
    var useDispatch = wp.data && wp.data.useDispatch ? wp.data.useDispatch : null;
    var InspectorControls = wp.blockEditor.InspectorControls;
    var PanelBody = wp.components.PanelBody;
    var TextControl = wp.components.TextControl;
    var TextareaControl = wp.components.TextareaControl;
    var ToggleControl = wp.components.ToggleControl;
    var SelectControl = wp.components.SelectControl;
    var FormTokenField = wp.components.FormTokenField;
    var ComboboxControl = wp.components.ComboboxControl;
    var Button = wp.components.Button;
    var BaseControl = wp.components.BaseControl;
    var ServerSideRender = wp.serverSideRender || null;
    var useBlockProps = wp.blockEditor.useBlockProps;

    if (ServerSideRender && ServerSideRender.default) {
        ServerSideRender = ServerSideRender.default;
    }

    var CATEGORY = 'maradigma';
    var gutenbergConfig = window.MaradigmaGutenbergBlocks || {};

    var commonSupports = {
        html: false,
        color: {
            text: true,
            background: true,
            heading: true,
            enableContrastChecker: true
        },
        typography: {
            fontSize: true,
            lineHeight: true
        },
        spacing: {
            margin: true,
            padding: true,
            blockGap: true
        },
        border: {
            color: true,
            radius: true,
            style: true,
            width: true
        },
        shadow: true
    };

    function asString(value) {
        if (value === undefined || value === null) {
            return '';
        }
        return String(value);
    }

    function isTruthy(value) {
        return value === true || value === 1 || value === '1' || value === 'true';
    }

    function parseCsv(value) {
        return asString(value)
            .split(',')
            .map(function (item) { return item.trim(); })
            .filter(function (item, index, items) {
                return item !== '' && items.indexOf(item) === index;
            });
    }

    function toCsv(items) {
        return (items || []).filter(function (item) {
            return !!item;
        }).join(',');
    }

    function parseJsonMap(value) {
        if (!value) {
            return {};
        }

        try {
            var parsed = JSON.parse(String(value));
            if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
                return parsed;
            }
        } catch (error) {}

        return {};
    }

    function normalizeAjaxResults(results) {
        if (!Array.isArray(results)) {
            return [];
        }

        return results
            .filter(function (item) {
                return item && typeof item === 'object';
            })
            .map(function (item) {
                return {
                    id: asString(item.id).trim(),
                    text: asString(item.text).trim()
                };
            })
            .filter(function (item) {
                return item.id !== '' && item.text !== '';
            });
    }

    function getRemoteSelectConfig() {
        return window.MaradigmaRemoteSelect2 || {};
    }

    function getRemoteSelectActionName(source) {
        var search = getRemoteSelectConfig().search || {};

        switch (source) {
            case 'boat_types':
                return search.boatTypesAction || '';
            case 'builders':
                return search.buildersAction || '';
            case 'boats':
                return search.boatsAction || '';
            default:
                return '';
        }
    }

    function fetchRemoteOptions(source, query) {
        var config = getRemoteSelectConfig();
        var ajaxUrl = asString(config.ajaxUrl).trim();
        var nonce = asString(config.nonce).trim();
        var action = getRemoteSelectActionName(source);
        var normalizedQuery = asString(query).trim();

        if (!ajaxUrl || !nonce || !action || !window.fetch) {
            return Promise.resolve([]);
        }

        if (source === 'boats' && normalizedQuery.length < 2) {
            return Promise.resolve([]);
        }

        var params = new URLSearchParams();
        params.set('action', action);
        params.set('nonce', nonce);
        params.set('q', normalizedQuery);
        params.set('page', '1');

        return window.fetch(ajaxUrl + '?' + params.toString(), {
            credentials: 'same-origin'
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (payload) {
                if (!payload || payload.success !== true) {
                    return [];
                }

                return normalizeAjaxResults(payload.results);
            })
            .catch(function () {
                return [];
            });
    }

    function fetchBoatLabelById(id) {
        var config = getRemoteSelectConfig();
        var ajaxUrl = asString(config.ajaxUrl).trim();
        var nonce = asString(config.nonce).trim();
        var search = config.search || {};
        var action = asString(search.boatByIdAction).trim();

        if (!ajaxUrl || !nonce || !action || !window.fetch) {
            return Promise.resolve('');
        }

        var params = new URLSearchParams();
        params.set('action', action);
        params.set('nonce', nonce);
        params.set('id', asString(id).trim());

        return window.fetch(ajaxUrl + '?' + params.toString(), {
            credentials: 'same-origin'
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (payload) {
                if (
                    payload &&
                    payload.success === true &&
                    payload.item &&
                    asString(payload.item.text).trim() !== ''
                ) {
                    return asString(payload.item.text).trim();
                }

                return '';
            })
            .catch(function () {
                return '';
            });
    }

    function setEditedMetaValue(editPost, meta, key, value) {
        var nextMeta = {};

        Object.keys(meta || {}).forEach(function (metaKey) {
            nextMeta[metaKey] = meta[metaKey];
        });

        nextMeta[key] = value;
        editPost({ meta: nextMeta });
    }

    function setEditedMetaValues(editPost, meta, values) {
        var nextMeta = {};

        Object.keys(meta || {}).forEach(function (metaKey) {
            nextMeta[metaKey] = meta[metaKey];
        });

        Object.keys(values || {}).forEach(function (metaKey) {
            nextMeta[metaKey] = values[metaKey];
        });

        editPost({ meta: nextMeta });
    }

    function getBoatBindingConfigForPostType(bindingConfig, currentPostType) {
        var candidates = Array.isArray(bindingConfig.bindings)
            ? bindingConfig.bindings
            : [bindingConfig];
        var normalizedCurrentPostType = asString(currentPostType).trim();
        var found = null;

        candidates.some(function (candidate) {
            var postType = asString(candidate && candidate.postType).trim();
            var postTypes = Array.isArray(candidate && candidate.postTypes)
                ? candidate.postTypes
                : [];

            if (postType !== '' && postType === normalizedCurrentPostType) {
                found = candidate;
                return true;
            }

            if (postTypes.map(asString).indexOf(normalizedCurrentPostType) !== -1) {
                found = candidate;
                return true;
            }

            return false;
        });

        return found || {};
    }

    function normalizeComboboxOptions(items, selectedId, selectedLabel) {
        var options = [];
        var seen = {};
        var normalizedSelectedId = asString(selectedId).trim();

        if (normalizedSelectedId !== '') {
            options.push({
                label: asString(selectedLabel).trim() || ('Boat #' + normalizedSelectedId),
                value: normalizedSelectedId
            });
            seen[normalizedSelectedId] = true;
        }

        (items || []).forEach(function (item) {
            var value = asString(item.id).trim();
            var label = asString(item.text).trim();

            if (!value || !label || seen[value]) {
                return;
            }

            options.push({
                label: label,
                value: value
            });
            seen[value] = true;
        });

        return options;
    }

    function MaradigmaBoatBindingPanel() {
        var boatBindingConfig = gutenbergConfig.boatBinding || {};
        var searchState = useState('');
        var searchValue = searchState[0];
        var setSearchValue = searchState[1];
        var optionsState = useState([]);
        var remoteOptions = optionsState[0];
        var setRemoteOptions = optionsState[1];
        var labelState = useState('');
        var selectedBoatLabel = labelState[0];
        var setSelectedBoatLabel = labelState[1];
        var loadingState = useState(false);
        var isLoading = loadingState[0];
        var setIsLoading = loadingState[1];
        var editorState = useSelect(function (select) {
            var editor = select('core/editor');

            if (!editor) {
                return {
                    currentPostType: '',
                    meta: {}
                };
            }

            return {
                currentPostType: asString(editor.getCurrentPostType ? editor.getCurrentPostType() : ''),
                meta: editor.getEditedPostAttribute ? (editor.getEditedPostAttribute('meta') || {}) : {}
            };
        }, []);
        var editPost = useDispatch('core/editor').editPost;
        var bindingConfig = getBoatBindingConfigForPostType(boatBindingConfig, editorState.currentPostType);
        var boatIdMetaKey = asString(bindingConfig.boatIdMetaKey).trim();
        var isBoatPageMetaKey = asString(bindingConfig.isBoatPageMetaKey).trim();
        var customLayoutMetaKey = asString(bindingConfig.customLayoutMetaKey).trim();
        var meta = editorState.meta || {};
        var boatId = asString(meta[boatIdMetaKey]).trim();
        var isBoatPage = isBoatPageMetaKey === '' || isTruthy(meta[isBoatPageMetaKey]);
        var isCustomLayout = isTruthy(meta[customLayoutMetaKey]);

        useEffect(function () {
            var cancelled = false;

            if (boatId === '') {
                setSelectedBoatLabel('');
                return undefined;
            }

            fetchBoatLabelById(boatId).then(function (label) {
                if (!cancelled) {
                    setSelectedBoatLabel(label || ('Boat #' + boatId));
                }
            });

            return function () {
                cancelled = true;
            };
        }, [boatId]);

        useEffect(function () {
            var cancelled = false;
            var normalizedSearch = asString(searchValue).trim();

            if (normalizedSearch.length < 2) {
                setRemoteOptions([]);
                setIsLoading(false);
                return undefined;
            }

            setIsLoading(true);
            fetchRemoteOptions('boats', normalizedSearch).then(function (items) {
                if (!cancelled) {
                    setRemoteOptions(items);
                    setIsLoading(false);
                }
            });

            return function () {
                cancelled = true;
            };
        }, [searchValue]);

        if (!boatIdMetaKey) {
            return null;
        }

        return el(
            PluginDocumentSettingPanel,
            {
                name: 'maradigma-boat-binding',
                title: __('Maradigma', 'maradigma'),
                className: 'maradigma-gutenberg-boat-binding-panel'
            },
            isBoatPageMetaKey !== '' && el(ToggleControl, {
                label: __('This page is a boat detail page', 'maradigma'),
                checked: isBoatPage,
                onChange: function (checked) {
                    var values = {};
                    values[isBoatPageMetaKey] = !!checked;

                    if (!checked) {
                        values[boatIdMetaKey] = '';
                        setSelectedBoatLabel('');
                        setSearchValue('');
                        setRemoteOptions([]);
                    }

                    setEditedMetaValues(editPost, meta, values);
                }
            }),
            isBoatPage && el(ComboboxControl, {
                label: __('Maradigma boat', 'maradigma'),
                value: boatId,
                options: normalizeComboboxOptions(remoteOptions, boatId, selectedBoatLabel),
                onFilterValueChange: setSearchValue,
                onChange: function (nextValue) {
                    setEditedMetaValue(editPost, meta, boatIdMetaKey, asString(nextValue).trim());
                },
                help: isLoading
                    ? __('Searching boats...', 'maradigma')
                    : __('Type at least 2 characters to search.', 'maradigma')
            }),
            isBoatPage && boatId !== '' && el(
                Button,
                {
                    className: 'maradigma-gutenberg-boat-binding-panel__clear',
                    variant: 'secondary',
                    isSmall: true,
                    onClick: function () {
                        setEditedMetaValue(editPost, meta, boatIdMetaKey, '');
                        setSelectedBoatLabel('');
                        setSearchValue('');
                        setRemoteOptions([]);
                    }
                },
                __('Clear boat', 'maradigma')
            ),
            customLayoutMetaKey !== '' && el(ToggleControl, {
                label: __('Use custom Elementor layout', 'maradigma'),
                checked: isCustomLayout,
                onChange: function (checked) {
                    setEditedMetaValue(editPost, meta, customLayoutMetaKey, !!checked);
                },
                help: __('When enabled, sync will not overwrite this boat Elementor layout.', 'maradigma')
            })
        );
    }

    function registerBoatBindingPanel() {
        if (!registerPlugin || !PluginDocumentSettingPanel || !useSelect || !useDispatch || !ComboboxControl) {
            return;
        }

        registerPlugin('maradigma-boat-binding', {
            render: MaradigmaBoatBindingPanel,
            icon: 'admin-site-alt3'
        });
    }

    function moveArrayItem(items, fromIndex, toIndex) {
        var next = (items || []).slice();

        if (
            fromIndex < 0 ||
            fromIndex >= next.length ||
            toIndex < 0 ||
            toIndex >= next.length ||
            fromIndex === toIndex
        ) {
            return next;
        }

        var moved = next.splice(fromIndex, 1)[0];
        next.splice(toIndex, 0, moved);

        return next;
    }

    function isControlVisible(control, attributes) {
        if (!control) {
            return true;
        }

        if (typeof control.when === 'function') {
            return !!control.when(attributes || {});
        }

        return true;
    }

    var archiveFieldOptions = [
        { key: 'term', label: __('Search term', 'maradigma') },
        { key: 'boat_capacity', label: __('Min pax', 'maradigma') },
        { key: 'order_by', label: __('Order by', 'maradigma') },
        { key: 'featured', label: __('Featured', 'maradigma') },
        { key: 'ins_book', label: __('Instant booking', 'maradigma') },
        { key: 'min_price', label: __('Min price', 'maradigma') },
        { key: 'max_price', label: __('Max price', 'maradigma') },
        { key: 'boat_type_id', label: __('Boat type', 'maradigma') },
        { key: 'builders', label: __('Builders', 'maradigma') },
        { key: 'ids_gi', label: __('Specific boats', 'maradigma') },
        { key: 'date_start', label: __('Availability dates', 'maradigma') },
        { key: 'date_end', label: __('Date end (separate mode only)', 'maradigma') }
    ];

    function renderArchiveFieldsOrderControl(control, props) {
        var attributes = props.attributes || {};
        var setAttributes = props.setAttributes;
        var key = control.key;
        var label = control.label || key;
        var help = control.help || undefined;
        var allowed = control.availableFields || archiveFieldOptions;
        var defaultItems = parseCsv(control.defaultValue || '');
        var currentItems = parseCsv(attributes[key]);
        var activeItems = (currentItems.length ? currentItems : defaultItems).filter(function (item, index, items) {
            var exists = allowed.some(function (option) {
                return option.key === item;
            });

            return exists && items.indexOf(item) === index;
        });

        var inactiveItems = allowed.filter(function (option) {
            return activeItems.indexOf(option.key) === -1;
        });

        function updateItems(nextItems) {
            var update = {};
            update[key] = toCsv(nextItems);
            setAttributes(update);
        }

        return el(
            BaseControl,
            {
                key: key,
                label: label,
                help: help
            },
            el(
                'div',
                { className: 'maradigma-gutenberg-archive-fields-order' },
                activeItems.length > 0 ? activeItems.map(function (itemKey, index) {
                    var option = allowed.find(function (candidate) {
                        return candidate.key === itemKey;
                    });
                    var itemLabel = option ? option.label : itemKey;

                    return el(
                        'div',
                        {
                            key: itemKey,
                            className: 'maradigma-gutenberg-archive-fields-order__row',
                            style: {
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'space-between',
                                gap: '8px',
                                marginBottom: '8px',
                                padding: '8px 10px',
                                border: '1px solid #dcdcde',
                                borderRadius: '6px',
                                background: '#fff'
                            }
                        },
                        el(
                            'span',
                            { style: { fontWeight: '600' } },
                            itemLabel
                        ),
                        el(
                            'div',
                            { style: { display: 'flex', gap: '6px' } },
                            el(Button, {
                                isSmall: true,
                                variant: 'secondary',
                                disabled: index === 0,
                                onClick: function () {
                                    updateItems(moveArrayItem(activeItems, index, index - 1));
                                }
                            }, __('Up', 'maradigma')),
                            el(Button, {
                                isSmall: true,
                                variant: 'secondary',
                                disabled: index === activeItems.length - 1,
                                onClick: function () {
                                    updateItems(moveArrayItem(activeItems, index, index + 1));
                                }
                            }, __('Down', 'maradigma')),
                            el(Button, {
                                isSmall: true,
                                isDestructive: true,
                                variant: 'secondary',
                                onClick: function () {
                                    updateItems(activeItems.filter(function (candidate) {
                                        return candidate !== itemKey;
                                    }));
                                }
                            }, __('Remove', 'maradigma'))
                        )
                    );
                }) : el(
                    'p',
                    { style: { margin: '0 0 8px' } },
                    __('No fields selected yet.', 'maradigma')
                ),
                inactiveItems.length > 0 && el(
                    'div',
                    { style: { marginTop: '10px' } },
                    el(
                        'p',
                        { style: { margin: '0 0 8px', fontWeight: '600' } },
                        __('Add field', 'maradigma')
                    ),
                    el(
                        'div',
                        { style: { display: 'flex', flexWrap: 'wrap', gap: '6px' } },
                        inactiveItems.map(function (option) {
                            return el(Button, {
                                key: option.key,
                                isSmall: true,
                                variant: 'secondary',
                                onClick: function () {
                                    updateItems(activeItems.concat(option.key));
                                }
                            }, option.label);
                        })
                    )
                )
            )
        );
    }

    function RemoteTokenFieldControl(params) {
        var control = params.control;
        var attributes = params.attributes || {};
        var setAttributes = params.setAttributes;
        var key = control.key;
        var label = control.label || key;
        var help = control.help || undefined;
        var source = control.source || '';
        var labelsKey = control.labelsKey || '';
        var rawValue = attributes[key];
        var selectedIds = parseCsv(rawValue);
        var storedMap = parseJsonMap(attributes[labelsKey]);
        var initialLabels = selectedIds.map(function (id) {
            return asString(storedMap[id]).trim();
        }).filter(function (value) {
            return value !== '';
        });
        var suggestionsState = useState([]);
        var suggestions = suggestionsState[0];
        var setSuggestions = suggestionsState[1];
        var valueState = useState(initialLabels);
        var valueTokens = valueState[0];
        var setValueTokens = valueState[1];
        var idByLabelRef = useRef({});

        useEffect(function () {
            var nextMap = {};
            var nextTokens = [];

            selectedIds.forEach(function (id) {
                var labelValue = asString(storedMap[id]).trim();
                if (!labelValue) {
                    return;
                }

                nextMap[labelValue] = id;
                nextTokens.push(labelValue);
            });

            idByLabelRef.current = nextMap;
            setValueTokens(nextTokens);
        }, [asString(rawValue), asString(attributes[labelsKey])]);

        useEffect(function () {
            if (source !== 'boats') {
                return undefined;
            }

            var missingIds = selectedIds.filter(function (id) {
                return asString(storedMap[id]).trim() === '';
            });

            if (!missingIds.length) {
                return undefined;
            }

            Promise.all(missingIds.map(function (id) {
                return fetchBoatLabelById(id).then(function (text) {
                    return { id: id, text: text };
                });
            })).then(function (items) {
                var nextMap = parseJsonMap(attributes[labelsKey]);
                var changed = false;

                items.forEach(function (item) {
                    if (asString(item.text).trim() === '') {
                        return;
                    }

                    nextMap[item.id] = item.text;
                    changed = true;
                });

                if (changed) {
                    var update = {};
                    update[labelsKey] = JSON.stringify(nextMap);
                    setAttributes(update);
                }
            });

            return undefined;
        }, [source, asString(rawValue), asString(attributes[labelsKey])]);

        useEffect(function () {
            if (source === 'boats') {
                return undefined;
            }

            fetchRemoteOptions(source, '').then(function (items) {
                mergeOptionsIntoLookup(items);
                setSuggestions(items.map(function (item) {
                    return item.text;
                }));
            });
        }, [source]);

        function mergeOptionsIntoLookup(options) {
            var nextLookup = {};

            Object.keys(idByLabelRef.current || {}).forEach(function (labelValue) {
                nextLookup[labelValue] = idByLabelRef.current[labelValue];
            });

            (options || []).forEach(function (option) {
                nextLookup[option.text] = option.id;
            });

            idByLabelRef.current = nextLookup;
        }

        function updateFromTokens(nextTokens) {
            var trimmedTokens = (nextTokens || []).map(function (token) {
                return asString(token).trim();
            }).filter(function (token, index, items) {
                return token !== '' && items.indexOf(token) === index;
            });

            var nextIds = [];
            var nextMap = {};

            trimmedTokens.forEach(function (token) {
                var id = asString(idByLabelRef.current[token]).trim();
                if (!id) {
                    return;
                }

                nextIds.push(id);
                nextMap[id] = token;
            });

            setValueTokens(trimmedTokens);

            var update = {};
            update[key] = toCsv(nextIds);
            update[labelsKey] = JSON.stringify(nextMap);
            setAttributes(update);
        }

        return el(
            'div',
            {
                key: key,
                className: 'maradigma-gutenberg-native-control maradigma-gutenberg-native-control--tokens'
            },
            FormTokenField ? el(FormTokenField, {
                label: label,
                help: help,
                value: valueTokens,
                suggestions: suggestions,
                __experimentalExpandOnFocus: true,
                onInputChange: function (inputValue) {
                    fetchRemoteOptions(source, inputValue).then(function (items) {
                        mergeOptionsIntoLookup(items);
                        setSuggestions(items.map(function (item) {
                            return item.text;
                        }));
                    });
                },
                placeholder: control.placeholder || __('Search and select…', 'maradigma'),
                onChange: updateFromTokens
            }) : el(TextControl, {
                label: label,
                help: help || __('Enter comma-separated values.', 'maradigma'),
                value: valueTokens.join(', '),
                onChange: function (nextValue) {
                    updateFromTokens(
                        asString(nextValue)
                            .split(',')
                            .map(function (item) { return item.trim(); })
                            .filter(function (item) { return item !== ''; })
                    );
                }
            })
        );
    }

    function RemoteComboboxControl(params) {
        var control = params.control;
        var attributes = params.attributes || {};
        var setAttributes = params.setAttributes;
        var key = control.key;
        var label = control.label || key;
        var help = control.help || undefined;
        var source = control.source || '';
        var labelKey = control.labelKey || '';
        var selectedId = asString(attributes[key]).trim();
        var selectedLabel = asString(attributes[labelKey]).trim();
        var optionsState = useState([]);
        var options = optionsState[0];
        var setOptions = optionsState[1];
        var inputState = useState(selectedLabel);
        var inputValue = inputState[0];
        var setInputValue = inputState[1];

        useEffect(function () {
            setInputValue(selectedLabel);
        }, [selectedLabel]);

        useEffect(function () {
            fetchRemoteOptions(source, '').then(function (items) {
                setOptions(items);
            });
        }, [source]);

        var comboboxOptions = options.map(function (item) {
            return {
                value: item.id,
                label: item.text
            };
        });

        if (selectedId !== '' && selectedLabel !== '' && !comboboxOptions.some(function (item) { return item.value === selectedId; })) {
            comboboxOptions.unshift({
                value: selectedId,
                label: selectedLabel
            });
        }

        return el(
            'div',
            {
                key: key,
                className: 'maradigma-gutenberg-native-control maradigma-gutenberg-native-control--combo'
            },
            ComboboxControl ? el(ComboboxControl, {
                label: label,
                help: help,
                value: selectedId,
                options: comboboxOptions,
                onFilterValueChange: function (nextInputValue) {
                    setInputValue(nextInputValue);
                    fetchRemoteOptions(source, nextInputValue).then(function (items) {
                        setOptions(items);
                    });
                },
                onChange: function (nextValue) {
                    var normalizedValue = asString(nextValue).trim();
                    var match = comboboxOptions.find(function (item) {
                        return item.value === normalizedValue;
                    });
                    var update = {};
                    update[key] = normalizedValue;
                    update[labelKey] = match ? match.label : '';
                    setAttributes(update);
                },
                __next40pxDefaultSize: true
            }) : el(SelectControl, {
                label: label,
                help: help,
                value: selectedId,
                options: [{ label: __('Select an option', 'maradigma'), value: '' }].concat(comboboxOptions),
                onChange: function (nextValue) {
                    var normalizedValue = asString(nextValue).trim();
                    var match = comboboxOptions.find(function (item) {
                        return item.value === normalizedValue;
                    });
                    var update = {};
                    update[key] = normalizedValue;
                    update[labelKey] = match ? match.label : '';
                    setAttributes(update);
                }
            })
        );
    }

    function renderControl(control, props) {
        var attributes = props.attributes || {};
        var setAttributes = props.setAttributes;
        var key = control.key;
        var label = control.label || key;
        var help = control.help || undefined;
        var value = attributes[key];

        if (!isControlVisible(control, attributes)) {
            return null;
        }

        if (control.type === 'archive-fields-order') {
            return renderArchiveFieldsOrderControl(control, props);
        }

        if (control.type === 'remote-token-field') {
            return el(RemoteTokenFieldControl, {
                key: key,
                control: control,
                attributes: attributes,
                setAttributes: setAttributes
            });
        }

        if (control.type === 'remote-combobox') {
            return el(RemoteComboboxControl, {
                key: key,
                control: control,
                attributes: attributes,
                setAttributes: setAttributes
            });
        }

        if (control.type === 'toggle') {
            return el(ToggleControl, {
                key: key,
                label: label,
                help: help,
                checked: !!value,
                onChange: function (checked) {
                    var update = {};
                    update[key] = !!checked;
                    setAttributes(update);
                }
            });
        }

        if (control.type === 'select') {
            return el(SelectControl, {
                key: key,
                label: label,
                help: help,
                value: asString(value),
                options: control.options || [],
                onChange: function (selected) {
                    var update = {};
                    update[key] = selected;
                    setAttributes(update);
                }
            });
        }

        if (control.type === 'textarea') {
            return el(TextareaControl, {
                key: key,
                label: label,
                help: help,
                value: asString(value),
                rows: control.rows || 4,
                onChange: function (nextValue) {
                    var update = {};
                    update[key] = nextValue;
                    setAttributes(update);
                }
            });
        }

        return el(TextControl, {
            key: key,
            label: label,
            help: help,
            type: control.inputType || 'text',
            value: asString(value),
            onChange: function (nextValue) {
                var update = {};
                update[key] = nextValue;
                setAttributes(update);
            }
        });
    }

    function renderInspectorPanels(definition, props) {
        var groups = definition.controlGroups || [];

        return el(
            InspectorControls,
            {},
            groups.map(function (group, index) {
                return el(
                    PanelBody,
                    {
                        key: group.title || index,
                        title: group.title || __('Settings', 'maradigma'),
                        initialOpen: index === 0
                    },
                    (group.controls || []).map(function (control) {
                        return renderControl(control, props);
                    })
                );
            })
        );
    }

    function renderCalendarStaticPreview(props) {
        var attributes = props.attributes || {};
        var available = asString(attributes.color_available || '#d4edda') || '#d4edda';
        var booked = asString(attributes.color_booked || '#ffc0bd') || '#ffc0bd';
        var option = asString(attributes.color_option || '#ffe8a1') || '#ffe8a1';
        var showLegend = asString(attributes.show_legend || '1') !== '0';
        var now = new Date();
        var monthLabel;

        try {
            monthLabel = new Intl.DateTimeFormat(undefined, {
                month: 'long',
                year: 'numeric'
            }).format(new Date(now.getFullYear(), now.getMonth(), 1));
        } catch (e) {
            monthLabel = __('Calendar preview', 'maradigma');
        }

        var weekdays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        var states = {
            3: 'booked',
            4: 'booked',
            10: 'option',
            11: 'option',
            17: 'booked',
            24: 'option'
        };
        var days = [];

        for (var i = 1; i <= 28; i++) {
            days.push(i);
        }

        return el(
            'div',
            {
                className: 'md-boat-calendar maradigma-gutenberg-calendar-preview',
                style: {
                    '--mdcal-available': available,
                    '--mdcal-booked': booked,
                    '--mdcal-option': option
                }
            },
            el(
                'div',
                { className: 'mdcal__controller' },
                el('button', { type: 'button', className: 'mdcal__btn', disabled: true }, '<'),
                el('div', { className: 'mdcal__select', style: { display: 'flex', alignItems: 'center' } }, monthLabel),
                el('button', { type: 'button', className: 'mdcal__btn', disabled: true }, '>')
            ),
            el(
                'div',
                { className: 'mdcal__tablewrap' },
                el(
                    'table',
                    { className: 'mdcal__table' },
                    el(
                        'thead',
                        {},
                        el(
                            'tr',
                            {},
                            weekdays.map(function (day) {
                                return el('th', { key: day }, day);
                            })
                        )
                    ),
                    el(
                        'tbody',
                        {},
                        [0, 1, 2, 3].map(function (row) {
                            return el(
                                'tr',
                                { key: row },
                                days.slice(row * 7, row * 7 + 7).map(function (day) {
                                    var state = states[day] || 'available';
                                    var className = 'mdcal__day';

                                    if (state === 'booked') {
                                        className += ' mdcal__day--booked';
                                    } else if (state === 'option') {
                                        className += ' mdcal__day--option';
                                    }

                                    return el('td', { key: day, className: className }, day);
                                })
                            );
                        })
                    )
                )
            ),
            showLegend && el(
                'div',
                { className: 'mdcal__legend' },
                el('span', { className: 'mdcal__legend-item' }, el('i', { className: 'mdcal__dot mdcal__dot--available' }), ' ', __('Available', 'maradigma')),
                el('span', { className: 'mdcal__legend-item' }, el('i', { className: 'mdcal__dot mdcal__dot--booked' }), ' ', __('Booked', 'maradigma')),
                el('span', { className: 'mdcal__legend-item' }, el('i', { className: 'mdcal__dot mdcal__dot--option' }), ' ', __('Option', 'maradigma'))
            ),
            el(
                'p',
                { className: 'maradigma-gutenberg-placeholder', style: { marginTop: '8px' } },
                __('Editor preview. The live calendar loads availability on the frontend.', 'maradigma')
            )
        );
    }

    function renderBoatCardPreview() {
        var boats = [
            { title: 'Pardo Yachts 38 TEST', port: 'Marina Ibiza', price: '1.200 EUR', meta: ['12 pers.', '1 cabin', '11.60 m'], featured: true },
            { title: 'Quicksilver Test DEMO', port: 'Marina Badalona', price: '900 EUR', meta: ['7 pers.', '0 cabins', '10.00 m'], featured: false },
            { title: 'Karnic SL602 Valkirie', port: 'Marina Ibiza', price: '650 EUR', meta: ['6 pers.', '1 cabin', '6.02 m'], featured: true }
        ];

        return el(
            'div',
            { className: 'maradigma-gutenberg-archive-preview' },
            el(
                'div',
                { className: 'maradigma-gutenberg-archive-preview__filters' },
                [__('Dates', 'maradigma'), __('Capacity', 'maradigma'), __('Boat type', 'maradigma'), __('Price', 'maradigma')].map(function (label) {
                    return el('span', { key: label }, label);
                })
            ),
            el(
                'div',
                { className: 'maradigma-boats maradigma-boats-archive maradigma-gutenberg-preview-cards', style: { '--mrd-results-columns': '3' } },
                boats.map(function (boat, index) {
                    return el(
                        'article',
                        { key: index, className: 'maradigma-boat-card' },
                        el(
                            'div',
                            { className: 'maradigma-boat-card__link' },
                            el(
                                'div',
                                { className: 'maradigma-boat-card__media maradigma-gutenberg-preview-media' },
                                boat.featured && el(
                                    'div',
                                    { className: 'maradigma-boat-card__badges' },
                                    el('span', { className: 'maradigma-boat-card__badge maradigma-boat-card__badge--featured' }, __('Featured', 'maradigma'))
                                )
                            ),
                            el(
                                'div',
                                { className: 'maradigma-boat-card__body' },
                                el(
                                    'div',
                                    { className: 'maradigma-boat-card__top' },
                                    el(
                                        'div',
                                        { className: 'maradigma-boat-card__header' },
                                        el('p', { className: 'maradigma-boat-card__title' }, boat.title),
                                        el('p', { className: 'maradigma-boat-card__subtitle' }, boat.port)
                                    )
                                ),
                                el(
                                    'div',
                                    { className: 'maradigma-boat-card__bullets' },
                                    boat.meta.map(function (item) {
                                        return el('div', { key: item, className: 'maradigma-bullet' }, el('span', {}, item));
                                    })
                                ),
                                el(
                                    'div',
                                    { className: 'maradigma-boat-card__footer' },
                                    el(
                                        'div',
                                        { className: 'maradigma-boat-card__price' },
                                        el('span', { className: 'maradigma-boat-card__price-label' }, __('from', 'maradigma')),
                                        el('span', { className: 'maradigma-boat-card__price-value' }, boat.price)
                                    ),
                                    el('span', { className: 'maradigma-boat-card__cta' }, __('Book now', 'maradigma'))
                                )
                            )
                        )
                    );
                })
            )
        );
    }

    function renderGalleryPreview() {
        return el(
            'div',
            { className: 'maradigma-boat-gallery maradigma-boat-gallery--grid maradigma-gutenberg-preview-gallery', style: { '--md-gallery-gap': '10px', '--md-gallery-radius': '10px', '--md-gallery-cols': '3' } },
            [1, 2, 3, 4, 5, 6].map(function (item) {
                return el(
                    'figure',
                    { key: item, className: 'maradigma-boat-gallery__item' },
                    el('div', { className: 'maradigma-boat-gallery__img maradigma-gutenberg-preview-media' })
                );
            })
        );
    }

    function renderSvgUse(icon, className) {
        return el(
            'svg',
            { className: className || '', width: 16, height: 16, focusable: false, 'aria-hidden': true },
            el('use', { href: '#svg-' + icon, xlinkHref: '#svg-' + icon })
        );
    }

    function renderBookingPreview() {
        return el(
            'div',
            {
                className: 'md-booking',
                'data-md-preview-mode': '1',
                'data-calendar-display': 'popup',
                'data-calendar-months': '2',
                'data-calendar-selection-mode': 'range'
            },
            el(
                'button',
                {
                    type: 'button',
                    className: 'md-btn md-btn--primary',
                    onClick: function (event) {
                        event.preventDefault();
                    }
                },
                __('Book now', 'maradigma')
            ),
            el(
                'div',
                {
                    className: 'md-modal',
                    'aria-hidden': 'true',
                    role: 'dialog',
                    'aria-modal': 'true',
                    style: { display: 'none' }
                },
                el('div', { className: 'md-modal__overlay' }),
                el(
                    'div',
                    { className: 'md-modal__panel', role: 'document' },
                    el('button', { type: 'button', className: 'md-modal__close', 'aria-label': __('Close', 'maradigma') }, 'x'),
                    el(
                        'div',
                        { className: 'md-modal__header' },
                        el('h3', { className: 'md-modal__title' }, __('Booking details', 'maradigma'))
                    )
                )
            )
        );
    }

    function renderPdfPreview() {
        return el(
            'div',
            { className: 'maradigma-boat-pdf-download' },
            el(
                'a',
                {
                    className: 'maradigma-boat-pdf-download__btn',
                    href: '#',
                    onClick: function (event) {
                        event.preventDefault();
                    }
                },
                __('Download PDF', 'maradigma')
            )
        );
    }

    function renderTextBlockPreview(title, lines) {
        return el(
            'div',
            { className: 'maradigma-gutenberg-static-preview' },
            title && el('h3', { style: { marginTop: 0 } }, title),
            (lines || []).map(function (line, index) {
                return el('p', { key: index, style: { margin: index === 0 ? '0 0 6px' : '6px 0 0' } }, line);
            })
        );
    }

    function renderListBlockPreview(title, items, negative) {
        return el(
            'div',
            { className: 'maradigma-gutenberg-static-preview' },
            title && el('h3', { style: { marginTop: 0 } }, title),
            el(
                'ul',
                { style: { margin: 0, paddingLeft: '18px', display: 'grid', gap: '6px' } },
                (items || []).map(function (item, index) {
                    return el('li', { key: index }, (negative ? 'x ' : '✓ ') + item);
                })
            )
        );
    }

    function renderSpecsPreview() {
        var specs = [
            ['users', __('Capacity', 'maradigma'), '10'],
            ['ruler', __('Length', 'maradigma'), '6.82 m'],
            ['ruler', __('Beam', 'maradigma'), '2.55 m'],
            ['users', __('Builder', 'maradigma'), 'Bayliner'],
            ['map-marker', __('Base port', 'maradigma'), 'Marina Ibiza']
        ];

        return el(
            'div',
            { className: 'maradigma-boat-specs maradigma-boat-specs--two_cols maradigma-boat-specs--with-icons' },
            el(
                'dl',
                { className: 'maradigma-boat-specs__dl' },
                specs.map(function (row) {
                    return el(
                        'div',
                        { key: row[1], className: 'maradigma-boat-specs__row' },
                        el(
                            'span',
                            { className: 'maradigma-boat-specs__icon', 'aria-hidden': true },
                            renderSvgUse(row[0], 'maradigma-boat-specs__svg')
                        ),
                        el(
                            'div',
                            { className: 'maradigma-boat-specs__content' },
                            el('dt', { className: 'maradigma-boat-specs__dt' }, row[1]),
                            el('dd', { className: 'maradigma-boat-specs__dd' }, row[2])
                        )
                    );
                })
            )
        );
    }

    function renderPricePreview() {
        return el(
            'div',
            { className: 'maradigma-gutenberg-static-preview maradigma-boat-prices maradigma-boat-prices--cards' },
            el('h3', { className: 'maradigma-boat-prices__title' }, __('Prices', 'maradigma')),
            el(
                'div',
                { className: 'maradigma-boat-prices__cards' },
                el(
                    'div',
                    { className: 'maradigma-boat-prices__card' },
                    el('div', { className: 'maradigma-boat-prices__season' }, '01 Jan - 01 Dec'),
                    el(
                        'div',
                        { className: 'maradigma-boat-prices__price' },
                        el('span', { className: 'maradigma-boat-prices__amount' }, '1.200 EUR'),
                        el('br'),
                        el('small', { className: 'maradigma-boat-prices__vat' }, 'VAT included')
                    )
                )
            )
        );
    }

    function renderAdditionalServicesPreview() {
        return el(
            'div',
            { className: 'maradigma-gutenberg-static-preview maradigma-boat-additionals' },
            el('h3', { className: 'maradigma-boat-additionals__title' }, __('Additional services', 'maradigma')),
            ['Hotel', 'Welcome pack', 'Skipper'].map(function (name, index) {
                return el(
                    'div',
                    { key: name, className: 'maradigma-boat-additionals__item' },
                    el(
                        'div',
                        { className: 'maradigma-boat-additionals__row' },
                        el(
                            'div',
                            { className: 'maradigma-boat-additionals__left' },
                            el('span', { className: 'maradigma-boat-additionals__name' }, name),
                            el(
                                'div',
                                { className: 'maradigma-boat-additionals__badges' },
                                el('span', { className: 'maradigma-boat-additionals__badge' }, index === 2 ? __('Mandatory', 'maradigma') : __('Optional', 'maradigma')),
                                el('span', { className: 'maradigma-boat-additionals__badge' }, __('Per booking', 'maradigma'))
                            )
                        ),
                        el(
                            'div',
                            { className: 'maradigma-boat-additionals__right' },
                            el('strong', {}, index === 2 ? '217,80 EUR' : index === 1 ? '42,35 EUR' : '60,50 EUR')
                        )
                    )
                );
            })
        );
    }

    function renderStaticPreview(definition, props) {
        var slug = definition.slug || '';
        var label = definition.title || __('Maradigma block', 'maradigma');

        if (slug === 'boat-calendar') {
            return renderCalendarStaticPreview(props);
        }

        if (slug === 'boats-archive') {
            return renderBoatCardPreview();
        }

        if (slug === 'boat-gallery') {
            return renderGalleryPreview();
        }

        if (slug === 'boat-booking') {
            return renderBookingPreview();
        }

        if (slug === 'boat-title') {
            return renderTextBlockPreview('Pardo Yachts 38 "TEST"', []);
        }

        if (slug === 'boat-description' || slug === 'boat-single') {
            return renderTextBlockPreview(__('Boat description', 'maradigma'), [
                __('Comfortable charter boat with equipment, services and booking information.', 'maradigma')
            ]);
        }

        if (slug === 'boat-specs') {
            return renderSpecsPreview();
        }

        if (slug === 'boat-price') {
            return renderPricePreview();
        }

        if (slug === 'boat-equipments') {
            return renderListBlockPreview(__('Equipment', 'maradigma'), ['GPS', 'Bimini', 'Deck shower', 'USB socket'], false);
        }

        if (slug === 'boat-included') {
            return renderListBlockPreview(__('Included', 'maradigma'), ['Final cleaning', 'Insurance', 'Paddle board'], false);
        }

        if (slug === 'boat-not-included') {
            return renderListBlockPreview(__('Not included', 'maradigma'), ['Fuel consumption', 'Skipper overnight', 'Towels'], true);
        }

        if (slug === 'boat-additional-services') {
            return renderAdditionalServicesPreview();
        }

        if (slug === 'boat-pdf-download') {
            return renderPdfPreview();
        }

        if (slug === 'boat-main-image') {
            return el('div', {
                className: 'maradigma-gutenberg-static-preview',
                style: { minHeight: '220px', background: '#e2e8f0', borderRadius: '10px' }
            });
        }

        if (slug === 'boat-videos') {
            return renderTextBlockPreview(__('Videos', 'maradigma'), [
                __('Boat video preview', 'maradigma')
            ]);
        }

        if (slug === 'search') {
            return renderTextBlockPreview(__('Boat search', 'maradigma'), [
                __('Search form preview', 'maradigma')
            ]);
        }

        if (slug === 'boat-field') {
            return renderTextBlockPreview(__('Boat field', 'maradigma'), [
                __('Dynamic field value', 'maradigma')
            ]);
        }

        return renderTextBlockPreview(label, [
            __('Static editor preview. The real block renders on the frontend.', 'maradigma')
        ]);
    }

    function renderPreview(definition, props) {
        if (definition.staticPreview !== false) {
            return el(
                'div',
                { className: 'maradigma-gutenberg-preview maradigma-gutenberg-preview--' + definition.slug },
                renderStaticPreview(definition, props)
            );
        }

        if (!ServerSideRender) {
            return el(
                'div',
                { className: 'maradigma-gutenberg-placeholder' },
                __('Preview not available. The block will render on the frontend.', 'maradigma')
            );
        }

        return el(
            'div',
            { className: 'maradigma-gutenberg-preview maradigma-gutenberg-preview--' + definition.slug },
            el(ServerSideRender, {
                block: definition.name,
                attributes: props.attributes,
                EmptyResponsePlaceholder: function () {
                    return el(
                        'div',
                        { className: 'maradigma-gutenberg-placeholder' },
                        __('No preview available yet.', 'maradigma')
                    );
                },
                ErrorResponsePlaceholder: function () {
                    return el(
                        'div',
                        { className: 'maradigma-gutenberg-placeholder maradigma-gutenberg-placeholder--error' },
                        __('The Maradigma preview could not be loaded.', 'maradigma')
                    );
                }
            })
        );
    }

    function createEdit(definition) {
        return function (props) {
            var blockProps = useBlockProps ? useBlockProps() : {};

            return el(
                'div',
                blockProps,
                props.isSelected ? renderInspectorPanels(definition, props) : null,
                renderPreview(definition, props)
            );
        };
    }

    var commonIdentifier = [
        { key: 'id', label: __('Boat ID', 'maradigma'), help: __('Leave empty to use the boat linked to the current post/page.', 'maradigma') },
        { key: 'slug', label: __('Boat slug', 'maradigma'), help: __('Optional. Slug has priority over Boat ID.', 'maradigma') }
    ];

    var boolOptions = [
        { label: __('No', 'maradigma'), value: '0' },
        { label: __('Yes', 'maradigma'), value: '1' }
    ];

    var boatCardOptions = Array.isArray(gutenbergConfig.boatCardOptions)
        ? gutenbergConfig.boatCardOptions
        : [{ label: __('Default (plugin setting)', 'maradigma'), value: '' }];

    var blocks = [
        {
            name: 'maradigma/boats-archive',
            slug: 'boats-archive',
            title: __('Maradigma Boats Archive', 'maradigma'),
            icon: 'grid-view',
            description: __('Server-rendered boat listing using the Maradigma archive shortcode.', 'maradigma'),
            attributes: {
                id_group: { type: 'string', default: 'boats' },
                limit_services: { type: 'string', default: '12' },
                offset_services: { type: 'string', default: '0' },
                show_filters: { type: 'string', default: '0' },
                autosubmit_filters: { type: 'string', default: '1' },
                allow_url_filters: { type: 'string', default: '1' },
                order_by: { type: 'string', default: '0' },
                card: { type: 'string', default: '' },
                image_token: { type: 'string', default: 'image_main' },
                filters_ui_fields: { type: 'string', default: 'date_start,boat_capacity,min_price,max_price' },
                filters_ui_fields_offcanvas: { type: 'string', default: 'boat_type_id,builders,ids_gi' },
                filters_ui_layout: { type: 'string', default: 'horizontal' },
                filters_ui_submit_mode: { type: 'string', default: 'auto' },
                filters_ui_show_reset: { type: 'string', default: '1' },
                show_more_filters_button: { type: 'string', default: '1' },
                more_filters_button_text: { type: 'string', default: '', role: 'content' },
                more_filters_offcanvas_title: { type: 'string', default: '', role: 'content' },
                date_picker_mode: { type: 'string', default: 'range' },
                term: { type: 'string', default: '' },
                ids_gi: { type: 'string', default: '' },
                featured: { type: 'string', default: '' },
                ins_book: { type: 'string', default: '' },
                min_price: { type: 'string', default: '' },
                max_price: { type: 'string', default: '' },
                boat_capacity: { type: 'string', default: '' },
                boat_type_id: { type: 'string', default: '' },
                boat_type_id_label: { type: 'string', default: '' },
                builders: { type: 'string', default: '' },
                builders_labels_json: { type: 'string', default: '' },
                ids_gi_labels_json: { type: 'string', default: '' },
                gc_type: { type: 'string', default: '' },
                date_start: { type: 'string', default: '' },
                date_end: { type: 'string', default: '' },
                search_own_managment: { type: 'string', default: '' },
                only_calendarization: { type: 'string', default: '' },
                ignore_date_range: { type: 'string', default: '' }
            },
            controlGroups: [
                {
                    title: __('Listing', 'maradigma'),
                    controls: [
                        { key: 'limit_services', label: __('Items per page', 'maradigma'), inputType: 'number' },
                        { key: 'offset_services', label: __('Offset', 'maradigma'), inputType: 'number' },
                        { key: 'order_by', label: __('Order by', 'maradigma'), type: 'select', options: [
                            { label: __('Relevance', 'maradigma'), value: '0' },
                            { label: __('Price: low to high', 'maradigma'), value: '1' },
                            { label: __('Price: high to low', 'maradigma'), value: '2' },
                            { label: __('Length: low to high', 'maradigma'), value: '6' },
                            { label: __('Length: high to low', 'maradigma'), value: '5' },
                            { label: __('Featured first', 'maradigma'), value: '3' },
                            { label: __('Newest first', 'maradigma'), value: '4' }
                        ] },
                        { key: 'card', label: __('Card template', 'maradigma'), type: 'select', options: boatCardOptions },
                        { key: 'image_token', label: __('Card image token', 'maradigma') }
                    ]
                },
                {
                    title: __('Filters UI', 'maradigma'),
                    controls: [
                        { key: 'show_filters', label: __('Show filters', 'maradigma'), type: 'select', options: boolOptions },
                        { key: 'autosubmit_filters', label: __('Autosubmit filters', 'maradigma'), type: 'select', options: boolOptions },
                        { key: 'allow_url_filters', label: __('Allow URL filters', 'maradigma'), type: 'select', options: boolOptions },
                        {
                            key: 'filters_ui_layout',
                            label: __('Layout', 'maradigma'),
                            type: 'select',
                            options: [
                                { label: __('Horizontal', 'maradigma'), value: 'horizontal' },
                                { label: __('Vertical', 'maradigma'), value: 'vertical' }
                            ],
                            when: function (attributes) {
                                return asString(attributes.show_filters) === '1';
                            }
                        },
                        {
                            key: 'filters_ui_submit_mode',
                            label: __('Submit mode', 'maradigma'),
                            type: 'select',
                            options: [
                                { label: __('Auto', 'maradigma'), value: 'auto' },
                                { label: __('Button', 'maradigma'), value: 'button' }
                            ],
                            when: function (attributes) {
                                return asString(attributes.show_filters) === '1';
                            }
                        },
                        {
                            key: 'filters_ui_show_reset',
                            label: __('Show reset link', 'maradigma'),
                            type: 'select',
                            options: boolOptions,
                            when: function (attributes) {
                                return asString(attributes.show_filters) === '1';
                            }
                        },
                        {
                            key: 'date_picker_mode',
                            label: __('Date picker mode', 'maradigma'),
                            type: 'select',
                            options: [
                                { label: __('Range', 'maradigma'), value: 'range' },
                                { label: __('Separate fields', 'maradigma'), value: 'separate' }
                            ],
                            when: function (attributes) {
                                return asString(attributes.show_filters) === '1';
                            }
                        },
                        {
                            key: 'show_more_filters_button',
                            label: __('Show More filters button', 'maradigma'),
                            type: 'select',
                            options: boolOptions,
                            when: function (attributes) {
                                return asString(attributes.show_filters) === '1';
                            }
                        },
                        {
                            key: 'more_filters_button_text',
                            label: __('More filters button text', 'maradigma'),
                            when: function (attributes) {
                                return asString(attributes.show_filters) === '1' && asString(attributes.show_more_filters_button) === '1';
                            }
                        },
                        {
                            key: 'more_filters_offcanvas_title',
                            label: __('Offcanvas title', 'maradigma'),
                            when: function (attributes) {
                                return asString(attributes.show_filters) === '1' && asString(attributes.show_more_filters_button) === '1';
                            }
                        },
                        {
                            key: 'filters_ui_fields',
                            label: __('Fields order', 'maradigma'),
                            type: 'archive-fields-order',
                            availableFields: archiveFieldOptions,
                            defaultValue: 'date_start,boat_capacity,min_price,max_price',
                            help: __('Use the buttons to match the frontend filters order.', 'maradigma'),
                            when: function (attributes) {
                                return asString(attributes.show_filters) === '1';
                            }
                        },
                        {
                            key: 'filters_ui_fields_offcanvas',
                            label: __('Offcanvas fields order', 'maradigma'),
                            type: 'archive-fields-order',
                            availableFields: archiveFieldOptions,
                            defaultValue: 'boat_type_id,builders,ids_gi',
                            help: __('These fields appear inside the More filters drawer.', 'maradigma'),
                            when: function (attributes) {
                                return asString(attributes.show_filters) === '1' && asString(attributes.show_more_filters_button) === '1';
                            }
                        }
                    ]
                },
                {
                    title: __('Listing defaults', 'maradigma'),
                    controls: [
                        { key: 'term', label: __('Search term', 'maradigma') },
                        { key: 'ids_gi', label: __('Specific boats', 'maradigma'), type: 'remote-token-field', source: 'boats', labelsKey: 'ids_gi_labels_json', placeholder: __('Search boats…', 'maradigma') },
                        { key: 'featured', label: __('Featured only', 'maradigma'), type: 'select', options: [{ label: __('Default', 'maradigma'), value: '' }].concat(boolOptions) },
                        { key: 'ins_book', label: __('Instant booking only', 'maradigma'), type: 'select', options: [{ label: __('Default', 'maradigma'), value: '' }].concat(boolOptions) },
                        { key: 'min_price', label: __('Min price', 'maradigma'), inputType: 'number' },
                        { key: 'max_price', label: __('Max price', 'maradigma'), inputType: 'number' },
                        { key: 'boat_capacity', label: __('Min pax', 'maradigma'), inputType: 'number' },
                        { key: 'boat_type_id', label: __('Boat type', 'maradigma'), type: 'remote-combobox', source: 'boat_types', labelKey: 'boat_type_id_label' },
                        { key: 'builders', label: __('Builders', 'maradigma'), type: 'remote-token-field', source: 'builders', labelsKey: 'builders_labels_json', placeholder: __('Search builders…', 'maradigma') },
                        { key: 'date_start', label: __('Start date', 'maradigma'), inputType: 'date' },
                        { key: 'date_end', label: __('End date', 'maradigma'), inputType: 'date' }
                    ]
                }
            ]
        },
        {
            name: 'maradigma/search',
            slug: 'search',
            title: __('Maradigma Search Form', 'maradigma'),
            icon: 'search',
            description: __('Search form for sending visitors to a boat listing page.', 'maradigma'),
            attributes: {
                target_url: { type: 'string', default: '' },
                show_term: { type: 'boolean', default: true },
                show_capacity: { type: 'boolean', default: true },
                show_price: { type: 'boolean', default: true },
                show_dates: { type: 'boolean', default: false }
            },
            controlGroups: [
                {
                    title: __('Form', 'maradigma'),
                    controls: [
                        { key: 'target_url', label: __('Target URL', 'maradigma'), help: __('If empty, the form submits to the current page.', 'maradigma') },
                        { key: 'show_term', label: __('Show search field', 'maradigma'), type: 'toggle' },
                        { key: 'show_capacity', label: __('Show pax field', 'maradigma'), type: 'toggle' },
                        { key: 'show_price', label: __('Show price fields', 'maradigma'), type: 'toggle' },
                        { key: 'show_dates', label: __('Show date fields', 'maradigma'), type: 'toggle' }
                    ]
                }
            ]
        },
        {
            name: 'maradigma/boat-single',
            slug: 'boat-single',
            title: __('Maradigma Boat Single', 'maradigma'),
            icon: 'admin-site-alt3',
            description: __('Full boat template rendered by the Maradigma single shortcode/template.', 'maradigma'),
            attributes: { id: { type: 'string', default: '' }, slug: { type: 'string', default: '' } },
            controlGroups: [{ title: __('Boat', 'maradigma'), controls: commonIdentifier }]
        },
        {
            name: 'maradigma/boat-title',
            slug: 'boat-title',
            title: __('Maradigma Boat Title', 'maradigma'),
            icon: 'heading',
            description: __('Boat title/name.', 'maradigma'),
            attributes: {
                id: { type: 'string', default: '' },
                slug: { type: 'string', default: '' },
                tag: { type: 'string', default: 'h1' },
                show_builder: { type: 'string', default: '1' },
                show_model: { type: 'string', default: '1' },
                show_alias: { type: 'string', default: '1' },
                quote_alias: { type: 'string', default: '1' },
                fallback_service_name: { type: 'string', default: '1' },
                separator: { type: 'string', default: ' ' },
                fallback: { type: 'string', default: '', role: 'content' }
            },
            controlGroups: [
                { title: __('Boat', 'maradigma'), controls: commonIdentifier },
                { title: __('Display', 'maradigma'), controls: [
                    { key: 'tag', label: __('HTML tag', 'maradigma'), type: 'select', options: ['h1','h2','h3','h4','h5','h6','div','span'].map(function (tag) { return { label: tag, value: tag }; }) },
                    { key: 'show_builder', label: __('Show builder', 'maradigma'), type: 'select', options: boolOptions },
                    { key: 'show_model', label: __('Show model', 'maradigma'), type: 'select', options: boolOptions },
                    { key: 'show_alias', label: __('Show alias', 'maradigma'), type: 'select', options: boolOptions },
                    { key: 'quote_alias', label: __('Quote alias', 'maradigma'), type: 'select', options: boolOptions },
                    { key: 'fallback_service_name', label: __('Fallback to service name', 'maradigma'), type: 'select', options: boolOptions },
                    { key: 'separator', label: __('Separator', 'maradigma') },
                    { key: 'fallback', label: __('Fallback', 'maradigma') }
                ] }
            ]
        },
        {
            name: 'maradigma/boat-field',
            slug: 'boat-field',
            title: __('Maradigma Boat Field', 'maradigma'),
            icon: 'editor-code',
            description: __('Generic boat field renderer.', 'maradigma'),
            attributes: {
                id: { type: 'string', default: '' },
                slug: { type: 'string', default: '' },
                field: { type: 'string', default: '' },
                fallback: { type: 'string', default: '', role: 'content' },
                format: { type: 'string', default: 'raw' },
                decimals: { type: 'string', default: '0' },
                esc: { type: 'string', default: 'true' }
            },
            controlGroups: [
                { title: __('Boat', 'maradigma'), controls: commonIdentifier },
                { title: __('Field', 'maradigma'), controls: [
                    { key: 'field', label: __('Field key', 'maradigma') },
                    { key: 'format', label: __('Format', 'maradigma'), type: 'select', options: [
                        { label: 'raw', value: 'raw' },
                        { label: 'number', value: 'number' },
                        { label: 'price', value: 'price' }
                    ] },
                    { key: 'decimals', label: __('Decimals', 'maradigma'), inputType: 'number' },
                    { key: 'fallback', label: __('Fallback', 'maradigma') }
                ] }
            ]
        },
        {
            name: 'maradigma/boat-pax',
            slug: 'boat-pax',
            title: __('Maradigma Boat Pax', 'maradigma'),
            icon: 'groups',
            description: __('Boat capacity.', 'maradigma'),
            attributes: { id: { type: 'string', default: '' }, slug: { type: 'string', default: '' }, fallback: { type: 'string', default: '', role: 'content' } },
            controlGroups: [{ title: __('Boat', 'maradigma'), controls: commonIdentifier }]
        },
        {
            name: 'maradigma/boat-length',
            slug: 'boat-length',
            title: __('Maradigma Boat Length', 'maradigma'),
            icon: 'leftright',
            description: __('Boat length.', 'maradigma'),
            attributes: { id: { type: 'string', default: '' }, slug: { type: 'string', default: '' }, fallback: { type: 'string', default: '', role: 'content' } },
            controlGroups: [{ title: __('Boat', 'maradigma'), controls: commonIdentifier }]
        },
        {
            name: 'maradigma/boat-beam',
            slug: 'boat-beam',
            title: __('Maradigma Boat Beam', 'maradigma'),
            icon: 'leftright',
            description: __('Boat beam.', 'maradigma'),
            attributes: { id: { type: 'string', default: '' }, slug: { type: 'string', default: '' }, fallback: { type: 'string', default: '', role: 'content' } },
            controlGroups: [{ title: __('Boat', 'maradigma'), controls: commonIdentifier }]
        },
        {
            name: 'maradigma/boat-builder',
            slug: 'boat-builder',
            title: __('Maradigma Boat Builder', 'maradigma'),
            icon: 'admin-tools',
            description: __('Boat builder.', 'maradigma'),
            attributes: { id: { type: 'string', default: '' }, slug: { type: 'string', default: '' }, fallback: { type: 'string', default: '', role: 'content' } },
            controlGroups: [{ title: __('Boat', 'maradigma'), controls: commonIdentifier }]
        },
        {
            name: 'maradigma/boat-base-port',
            slug: 'boat-base-port',
            title: __('Maradigma Boat Base Port', 'maradigma'),
            icon: 'location',
            description: __('Boat base port.', 'maradigma'),
            attributes: { id: { type: 'string', default: '' }, slug: { type: 'string', default: '' }, fallback: { type: 'string', default: '', role: 'content' } },
            controlGroups: [{ title: __('Boat', 'maradigma'), controls: commonIdentifier }]
        },
        {
            name: 'maradigma/boat-main-image',
            slug: 'boat-main-image',
            title: __('Maradigma Boat Main Image', 'maradigma'),
            icon: 'format-image',
            description: __('Boat cover image.', 'maradigma'),
            attributes: {
                id: { type: 'string', default: '' },
                slug: { type: 'string', default: '' },
                size: { type: 'string', default: '1100' },
                attr: { type: 'string', default: 'loading="lazy"' },
                prefer_wp_cache: { type: 'string', default: '1' }
            },
            controlGroups: [
                { title: __('Boat', 'maradigma'), controls: commonIdentifier },
                { title: __('Image', 'maradigma'), controls: [
                    { key: 'size', label: __('Size', 'maradigma') },
                    { key: 'attr', label: __('HTML attributes', 'maradigma') },
                    { key: 'prefer_wp_cache', label: __('Prefer WP cached image', 'maradigma'), type: 'select', options: boolOptions }
                ] }
            ]
        },
        {
            name: 'maradigma/boat-gallery',
            slug: 'boat-gallery',
            title: __('Maradigma Boat Gallery', 'maradigma'),
            icon: 'format-gallery',
            description: __('Boat gallery.', 'maradigma'),
            attributes: {
                id: { type: 'string', default: '' },
                slug: { type: 'string', default: '' },
                layout: { type: 'string', default: 'grid' },
                columns: { type: 'string', default: '3' },
                enable_lightbox: { type: 'string', default: '1' },
                max_images: { type: 'string', default: '12' },
                gap: { type: 'string', default: '10' },
                radius: { type: 'string', default: '10' },
                slider_autoplay: { type: 'string', default: '0' },
                slider_navigation: { type: 'string', default: '1' },
                slider_pagination: { type: 'string', default: '1' }
            },
            controlGroups: [
                { title: __('Boat', 'maradigma'), controls: commonIdentifier },
                { title: __('Gallery', 'maradigma'), controls: [
                    { key: 'layout', label: __('Layout', 'maradigma'), type: 'select', options: [
                        { label: 'grid', value: 'grid' },
                        { label: 'slider', value: 'slider' }
                    ] },
                    { key: 'columns', label: __('Columns', 'maradigma'), inputType: 'number' },
                    { key: 'max_images', label: __('Max images', 'maradigma'), inputType: 'number' },
                    { key: 'enable_lightbox', label: __('Lightbox', 'maradigma'), type: 'select', options: boolOptions },
                    { key: 'gap', label: __('Gap', 'maradigma'), inputType: 'number' },
                    { key: 'radius', label: __('Radius', 'maradigma'), inputType: 'number' },
                    { key: 'slider_autoplay', label: __('Slider autoplay', 'maradigma'), type: 'select', options: boolOptions },
                    { key: 'slider_navigation', label: __('Slider navigation', 'maradigma'), type: 'select', options: boolOptions },
                    { key: 'slider_pagination', label: __('Slider pagination', 'maradigma'), type: 'select', options: boolOptions }
                ] }
            ]
        },
        {
            name: 'maradigma/boat-videos',
            slug: 'boat-videos',
            title: __('Maradigma Boat Videos', 'maradigma'),
            icon: 'video-alt3',
            description: __('Boat videos.', 'maradigma'),
            attributes: {
                id: { type: 'string', default: '' },
                slug: { type: 'string', default: '' },
                layout: { type: 'string', default: 'grid' },
                mode: { type: 'string', default: 'embed' },
                columns: { type: 'string', default: '2' },
                max_videos: { type: 'string', default: '12' },
                show_title: { type: 'string', default: '0' },
                class: { type: 'string', default: '' }
            },
            controlGroups: [
                { title: __('Boat', 'maradigma'), controls: commonIdentifier },
                { title: __('Videos', 'maradigma'), controls: [
                    { key: 'layout', label: __('Layout', 'maradigma'), type: 'select', options: [{label:'grid',value:'grid'},{label:'list',value:'list'}] },
                    { key: 'mode', label: __('Mode', 'maradigma'), type: 'select', options: [{label:'embed',value:'embed'},{label:'thumbnail',value:'thumbnail'}] },
                    { key: 'columns', label: __('Columns', 'maradigma'), inputType: 'number' },
                    { key: 'max_videos', label: __('Max videos', 'maradigma'), inputType: 'number' },
                    { key: 'show_title', label: __('Show title', 'maradigma'), type: 'select', options: boolOptions },
                    { key: 'class', label: __('Extra CSS class', 'maradigma') }
                ] }
            ]
        },
        {
            name: 'maradigma/boat-specs',
            slug: 'boat-specs',
            title: __('Maradigma Boat Specs', 'maradigma'),
            icon: 'list-view',
            description: __('Configurable boat specs list.', 'maradigma'),
            attributes: {
                id: { type: 'string', default: '' },
                slug: { type: 'string', default: '' },
                layout: { type: 'string', default: 'two_cols' },
                show_labels: { type: 'string', default: '1' },
                show_icons: { type: 'string', default: '1' },
                icons: { type: 'string', default: '' },
                fields: { type: 'string', default: 'boat_capacity,boat_length,boat_beam,boat_builder,boat_base_port_name' },
                items_json: { type: 'string', default: '' }
            },
            controlGroups: [
                { title: __('Boat', 'maradigma'), controls: commonIdentifier },
                { title: __('Specs', 'maradigma'), controls: [
                    { key: 'layout', label: __('Layout', 'maradigma'), type: 'select', options: [{label:'two_cols',value:'two_cols'},{label:'list',value:'list'}] },
                    { key: 'fields', label: __('Fields CSV', 'maradigma') },
                    { key: 'items_json', label: __('Advanced items JSON', 'maradigma'), type: 'textarea', rows: 6 },
                    { key: 'show_labels', label: __('Show labels', 'maradigma'), type: 'select', options: boolOptions },
                    { key: 'show_icons', label: __('Show icons', 'maradigma'), type: 'select', options: boolOptions },
                    { key: 'icons', label: __('Icons map', 'maradigma') }
                ] }
            ]
        },
        {
            name: 'maradigma/boat-description',
            slug: 'boat-description',
            title: __('Maradigma Boat Description', 'maradigma'),
            icon: 'text-page',
            description: __('Boat description.', 'maradigma'),
            attributes: {
                id: { type: 'string', default: '' },
                slug: { type: 'string', default: '' },
                type: { type: 'string', default: 'auto' },
                allow_html: { type: 'string', default: '1' },
                max_words: { type: 'string', default: '0' },
                fallback: { type: 'string', default: '', role: 'content' }
            },
            controlGroups: [
                { title: __('Boat', 'maradigma'), controls: commonIdentifier },
                { title: __('Description', 'maradigma'), controls: [
                    { key: 'type', label: __('Description type', 'maradigma'), type: 'select', options: [{label:'auto',value:'auto'},{label:'long',value:'long'},{label:'short',value:'short'}] },
                    { key: 'allow_html', label: __('Allow HTML', 'maradigma'), type: 'select', options: boolOptions },
                    { key: 'max_words', label: __('Max words', 'maradigma'), inputType: 'number' },
                    { key: 'fallback', label: __('Fallback', 'maradigma') }
                ] }
            ]
        },
        {
            name: 'maradigma/boat-price',
            slug: 'boat-price',
            title: __('Maradigma Boat Prices', 'maradigma'),
            icon: 'money-alt',
            description: __('Boat season prices.', 'maradigma'),
            attributes: {
                id: { type: 'string', default: '' },
                slug: { type: 'string', default: '' },
                title: { type: 'string', default: 'Prices', role: 'content' },
                show_title: { type: 'boolean', default: true },
                layout: { type: 'string', default: 'cards' },
                range_mode: { type: 'string', default: 'dates_short' },
                order_by: { type: 'string', default: 'date_from_asc' },
                fallback: { type: 'string', default: 'Prices not available.', role: 'content' },
                show_headers: { type: 'boolean', default: true },
                row_gap: { type: 'string', default: '10' },
                vat_mode: { type: 'string', default: 'included' },
                vat_position: { type: 'string', default: 'below' },
                vat_text_included: { type: 'string', default: 'VAT included', role: 'content' },
                vat_text_excluded: { type: 'string', default: '+ VAT', role: 'content' },
                currency_display: { type: 'string', default: 'symbol' },
                decimals_mode: { type: 'string', default: 'auto' },
                thousands_sep: { type: 'string', default: '.' },
                decimal_sep: { type: 'string', default: ',' }
            },
            controlGroups: [
                { title: __('Boat', 'maradigma'), controls: commonIdentifier },
                { title: __('Prices', 'maradigma'), controls: [
                    { key: 'title', label: __('Title', 'maradigma') },
                    { key: 'show_title', label: __('Show title', 'maradigma'), type: 'toggle' },
                    { key: 'layout', label: __('Layout', 'maradigma'), type: 'select', options: [{label:'cards',value:'cards'},{label:'table',value:'table'}] },
                    { key: 'range_mode', label: __('Range label', 'maradigma'), type: 'select', options: [{label:'dates_short',value:'dates_short'},{label:'dates_long',value:'dates_long'},{label:'month',value:'month'}] },
                    { key: 'order_by', label: __('Order by', 'maradigma'), type: 'select', options: [
                        { label:'date_from_asc', value:'date_from_asc' },
                        { label:'date_from_desc', value:'date_from_desc' },
                        { label:'price_asc', value:'price_asc' },
                        { label:'price_desc', value:'price_desc' }
                    ] },
                    { key: 'show_headers', label: __('Show table headers', 'maradigma'), type: 'toggle' },
                    { key: 'row_gap', label: __('Row gap', 'maradigma'), inputType: 'number' },
                    { key: 'fallback', label: __('Fallback', 'maradigma') }
                ] },
                { title: __('Price and VAT', 'maradigma'), controls: [
                    { key: 'vat_mode', label: __('VAT mode', 'maradigma'), type: 'select', options: [
                        { label: __('VAT included', 'maradigma'), value: 'included' },
                        { label: __('+ VAT', 'maradigma'), value: 'excluded' },
                        { label: __('Hidden, total price', 'maradigma'), value: 'hidden_total' },
                        { label: __('Hidden, base price', 'maradigma'), value: 'hidden_base' }
                    ] },
                    { key: 'vat_position', label: __('VAT position', 'maradigma'), type: 'select', options: [
                        { label: __('Below', 'maradigma'), value: 'below' },
                        { label: __('Inline', 'maradigma'), value: 'inline' },
                        { label: __('Tooltip', 'maradigma'), value: 'tooltip' }
                    ] },
                    { key: 'vat_text_included', label: __('VAT included text', 'maradigma') },
                    { key: 'vat_text_excluded', label: __('VAT excluded text', 'maradigma') },
                    { key: 'currency_display', label: __('Currency display', 'maradigma'), type: 'select', options: [
                        { label: __('Symbol', 'maradigma'), value: 'symbol' },
                        { label: __('ISO', 'maradigma'), value: 'iso' }
                    ] },
                    { key: 'decimals_mode', label: __('Decimals', 'maradigma'), type: 'select', options: [
                        { label: __('Auto', 'maradigma'), value: 'auto' },
                        { label: '0', value: '0' },
                        { label: '2', value: '2' }
                    ] },
                    { key: 'thousands_sep', label: __('Thousands separator', 'maradigma') },
                    { key: 'decimal_sep', label: __('Decimal separator', 'maradigma') }
                ] }
            ]
        },
        {
            name: 'maradigma/boat-equipments',
            slug: 'boat-equipments',
            title: __('Maradigma Boat Equipments', 'maradigma'),
            icon: 'yes-alt',
            description: __('Boat equipments.', 'maradigma'),
            attributes: {
                id: { type: 'string', default: '' },
                slug: { type: 'string', default: '' },
                title: { type: 'string', default: 'Equipments', role: 'content' },
                show_title: { type: 'string', default: '1' },
                show_icon: { type: 'string', default: '0' },
                icon_text: { type: 'string', default: '✓', role: 'content' },
                sort: { type: 'string', default: '1' },
                fallback: { type: 'string', default: '', role: 'content' }
            },
            controlGroups: [
                { title: __('Boat', 'maradigma'), controls: commonIdentifier },
                { title: __('Equipments', 'maradigma'), controls: [
                    { key: 'title', label: __('Title', 'maradigma') },
                    { key: 'show_title', label: __('Show title', 'maradigma'), type: 'select', options: boolOptions },
                    { key: 'show_icon', label: __('Show icon', 'maradigma'), type: 'select', options: boolOptions },
                    { key: 'icon_text', label: __('Icon text', 'maradigma') },
                    { key: 'sort', label: __('Sort', 'maradigma'), type: 'select', options: boolOptions },
                    { key: 'fallback', label: __('Fallback', 'maradigma') }
                ] }
            ]
        },
        {
            name: 'maradigma/boat-included',
            slug: 'boat-included',
            title: __('Maradigma Boat Included', 'maradigma'),
            icon: 'yes',
            description: __('Included items.', 'maradigma'),
            attributes: {
                id: { type: 'string', default: '' },
                slug: { type: 'string', default: '' },
                title: { type: 'string', default: 'Included', role: 'content' },
                show_title: { type: 'string', default: '1' },
                show_tick_icon: { type: 'string', default: '1' },
                tick_text: { type: 'string', default: '✓', role: 'content' },
                sort: { type: 'string', default: '1' },
                fallback: { type: 'string', default: '', role: 'content' }
            },
            controlGroups: [
                { title: __('Boat', 'maradigma'), controls: commonIdentifier },
                { title: __('Included', 'maradigma'), controls: [
                    { key: 'title', label: __('Title', 'maradigma') },
                    { key: 'show_title', label: __('Show title', 'maradigma'), type: 'select', options: boolOptions },
                    { key: 'show_tick_icon', label: __('Show tick icon', 'maradigma'), type: 'select', options: boolOptions },
                    { key: 'tick_text', label: __('Tick text', 'maradigma') },
                    { key: 'sort', label: __('Sort', 'maradigma'), type: 'select', options: boolOptions },
                    { key: 'fallback', label: __('Fallback', 'maradigma') }
                ] }
            ]
        },
        {
            name: 'maradigma/boat-not-included',
            slug: 'boat-not-included',
            title: __('Maradigma Boat Not Included', 'maradigma'),
            icon: 'no-alt',
            description: __('Not included items.', 'maradigma'),
            attributes: {
                id: { type: 'string', default: '' },
                slug: { type: 'string', default: '' },
                title: { type: 'string', default: 'Not included', role: 'content' },
                show_title: { type: 'string', default: '1' },
                show_cross_icon: { type: 'string', default: '1' },
                cross_text: { type: 'string', default: '✕', role: 'content' },
                sort: { type: 'string', default: '1' },
                fallback: { type: 'string', default: '', role: 'content' }
            },
            controlGroups: [
                { title: __('Boat', 'maradigma'), controls: commonIdentifier },
                { title: __('Not included', 'maradigma'), controls: [
                    { key: 'title', label: __('Title', 'maradigma') },
                    { key: 'show_title', label: __('Show title', 'maradigma'), type: 'select', options: boolOptions },
                    { key: 'show_cross_icon', label: __('Show cross icon', 'maradigma'), type: 'select', options: boolOptions },
                    { key: 'cross_text', label: __('Cross text', 'maradigma') },
                    { key: 'sort', label: __('Sort', 'maradigma'), type: 'select', options: boolOptions },
                    { key: 'fallback', label: __('Fallback', 'maradigma') }
                ] }
            ]
        },
        {
            name: 'maradigma/boat-additional-services',
            slug: 'boat-additional-services',
            title: __('Maradigma Boat Additional Services', 'maradigma'),
            icon: 'plus-alt2',
            description: __('Additional services.', 'maradigma'),
            attributes: {
                id: { type: 'string', default: '' },
                slug: { type: 'string', default: '' },
                title: { type: 'string', default: 'Additional services', role: 'content' },
                show_title: { type: 'boolean', default: true },
                fallback: { type: 'string', default: '', role: 'content' },
                layout: { type: 'string', default: 'blocks' },
                show_headers: { type: 'boolean', default: true },
                group_by_category: { type: 'boolean', default: true },
                show_category_title: { type: 'boolean', default: true },
                show_badges: { type: 'boolean', default: true },
                show_badge_optional_type: { type: 'boolean', default: true },
                show_badge_price_type: { type: 'boolean', default: true },
                show_badge_payment: { type: 'boolean', default: true },
                badge_style: { type: 'string', default: 'friendly' },
                show_description: { type: 'boolean', default: false },
                show_quantity: { type: 'boolean', default: true },
                price_display: { type: 'string', default: 'total_html' },
                vat_mode: { type: 'string', default: 'included' },
                vat_position: { type: 'string', default: 'below' },
                vat_text_included: { type: 'string', default: 'VAT included', role: 'content' },
                vat_text_excluded: { type: 'string', default: '+ VAT', role: 'content' },
                currency_display: { type: 'string', default: 'symbol' },
                decimals_mode: { type: 'string', default: 'auto' },
                thousands_sep: { type: 'string', default: '.' },
                decimal_sep: { type: 'string', default: ',' }
            },
            supports: {
                html: false,
                color: {
                    text: true,
                    background: true,
                    heading: true,
                    enableContrastChecker: true
                },
                typography: {
                    fontSize: true,
                    lineHeight: true
                },
                spacing: {
                    margin: true,
                    padding: true,
                    blockGap: true
                },
                border: {
                    color: true,
                    radius: true,
                    style: true,
                    width: true
                },
                shadow: true
            },
            controlGroups: [
                { title: __('Boat', 'maradigma'), controls: commonIdentifier },
                { title: __('Additional services', 'maradigma'), controls: [
                    { key: 'title', label: __('Title', 'maradigma') },
                    { key: 'show_title', label: __('Show title', 'maradigma'), type: 'toggle' },
                    { key: 'layout', label: __('Layout', 'maradigma'), type: 'select', options: [
                        { label: __('Blocks', 'maradigma'), value: 'blocks' },
                        { label: __('Table', 'maradigma'), value: 'table' }
                    ] },
                    { key: 'show_headers', label: __('Show table headers', 'maradigma'), type: 'toggle' },
                    { key: 'group_by_category', label: __('Group by category', 'maradigma'), type: 'toggle' },
                    { key: 'show_category_title', label: __('Show category title', 'maradigma'), type: 'toggle' },
                    { key: 'show_description', label: __('Show description', 'maradigma'), type: 'toggle' },
                    { key: 'show_quantity', label: __('Show quantity', 'maradigma'), type: 'toggle' },
                    { key: 'fallback', label: __('Fallback', 'maradigma') }
                ] },
                { title: __('Badges', 'maradigma'), controls: [
                    { key: 'show_badges', label: __('Show badges', 'maradigma'), type: 'toggle' },
                    { key: 'show_badge_optional_type', label: __('Required / Optional / Free', 'maradigma'), type: 'toggle' },
                    { key: 'show_badge_price_type', label: __('Per booking / Per day', 'maradigma'), type: 'toggle' },
                    { key: 'show_badge_payment', label: __('Payment', 'maradigma'), type: 'toggle' },
                    { key: 'badge_style', label: __('Badge style', 'maradigma'), type: 'select', options: [
                        { label: __('Friendly', 'maradigma'), value: 'friendly' },
                        { label: __('Raw', 'maradigma'), value: 'raw' }
                    ] }
                ] },
                { title: __('Price and VAT', 'maradigma'), controls: [
                    { key: 'price_display', label: __('Price source', 'maradigma'), type: 'select', options: [
                        { label: __('Prefer total formatted', 'maradigma'), value: 'total_html' },
                        { label: __('Total', 'maradigma'), value: 'total' },
                        { label: __('Base', 'maradigma'), value: 'base' },
                        { label: __('VAT', 'maradigma'), value: 'vat' },
                        { label: __('Raw total', 'maradigma'), value: 'raw_total' },
                        { label: __('Raw base', 'maradigma'), value: 'raw_base' }
                    ] },
                    { key: 'vat_mode', label: __('VAT mode', 'maradigma'), type: 'select', options: [
                        { label: __('VAT included', 'maradigma'), value: 'included' },
                        { label: __('+ VAT', 'maradigma'), value: 'excluded' },
                        { label: __('Hidden, total price', 'maradigma'), value: 'hidden_total' },
                        { label: __('Hidden, base price', 'maradigma'), value: 'hidden_base' }
                    ] },
                    { key: 'vat_position', label: __('VAT position', 'maradigma'), type: 'select', options: [
                        { label: __('Below', 'maradigma'), value: 'below' },
                        { label: __('Inline', 'maradigma'), value: 'inline' },
                        { label: __('Tooltip', 'maradigma'), value: 'tooltip' }
                    ] },
                    { key: 'vat_text_included', label: __('VAT included text', 'maradigma') },
                    { key: 'vat_text_excluded', label: __('VAT excluded text', 'maradigma') },
                    { key: 'currency_display', label: __('Currency display', 'maradigma'), type: 'select', options: [
                        { label: __('Symbol', 'maradigma'), value: 'symbol' },
                        { label: __('ISO', 'maradigma'), value: 'iso' }
                    ] },
                    { key: 'decimals_mode', label: __('Decimals', 'maradigma'), type: 'select', options: [
                        { label: __('Auto', 'maradigma'), value: 'auto' },
                        { label: '0', value: '0' },
                        { label: '2', value: '2' }
                    ] },
                    { key: 'thousands_sep', label: __('Thousands separator', 'maradigma') },
                    { key: 'decimal_sep', label: __('Decimal separator', 'maradigma') }
                ] }
            ]
        },
        {
            name: 'maradigma/boat-pdf-download',
            slug: 'boat-pdf-download',
            title: __('Maradigma Boat PDF Download', 'maradigma'),
            icon: 'pdf',
            description: __('PDF download button.', 'maradigma'),
            attributes: {
                id: { type: 'string', default: '' },
                slug: { type: 'string', default: '' },
                button_text: { type: 'string', default: 'Download PDF', role: 'content' },
                open_in_new_tab: { type: 'string', default: '1' },
                force_download: { type: 'string', default: '0' },
                fallback: { type: 'string', default: '', role: 'content' }
            },
            controlGroups: [
                { title: __('Boat', 'maradigma'), controls: commonIdentifier },
                { title: __('Button', 'maradigma'), controls: [
                    { key: 'button_text', label: __('Button text', 'maradigma') },
                    { key: 'open_in_new_tab', label: __('Open in new tab', 'maradigma'), type: 'select', options: boolOptions },
                    { key: 'force_download', label: __('Force download', 'maradigma'), type: 'select', options: boolOptions },
                    { key: 'fallback', label: __('Fallback', 'maradigma') }
                ] }
            ]
        },
        {
            name: 'maradigma/boat-calendar',
            slug: 'boat-calendar',
            title: __('Maradigma Boat Calendar', 'maradigma'),
            icon: 'calendar-alt',
            description: __('Availability calendar.', 'maradigma'),
            attributes: {
                id: { type: 'string', default: '' },
                slug: { type: 'string', default: '' },
                months: { type: 'string', default: '12' },
                start_month: { type: 'string', default: 'current' },
                show_legend: { type: 'string', default: '1' },
                color_available: { type: 'string', default: '#d4edda' },
                color_booked: { type: 'string', default: '#ffc0bd' },
                color_option: { type: 'string', default: '#ffe8a1' },
                option_statuses: { type: 'string', default: '4' },
                include_booking_status: { type: 'string', default: '1' }
            },
            controlGroups: [
                { title: __('Boat', 'maradigma'), controls: commonIdentifier },
                { title: __('Calendar', 'maradigma'), controls: [
                    { key: 'months', label: __('Months', 'maradigma'), inputType: 'number' },
                    { key: 'start_month', label: __('Start month', 'maradigma'), type: 'select', options: [{label:'current',value:'current'},{label:'next',value:'next'}] },
                    { key: 'show_legend', label: __('Show legend', 'maradigma'), type: 'select', options: boolOptions },
                    { key: 'color_available', label: __('Available color', 'maradigma') },
                    { key: 'color_booked', label: __('Booked color', 'maradigma') },
                    { key: 'color_option', label: __('Option color', 'maradigma') },
                    { key: 'option_statuses', label: __('Option statuses CSV', 'maradigma') },
                    { key: 'include_booking_status', label: __('Include booking status', 'maradigma'), type: 'select', options: boolOptions }
                ] }
            ]
        },
        {
            name: 'maradigma/boat-booking',
            slug: 'boat-booking',
            title: __('Maradigma Boat Booking Button', 'maradigma'),
            icon: 'tickets-alt',
            description: __('Booking button and modal.', 'maradigma'),
            attributes: {
                id: { type: 'string', default: '' },
                slug: { type: 'string', default: '' },
                button_text: { type: 'string', default: 'Book now', role: 'content' },
                redirect_url_success: { type: 'string', default: '' },
                calendar_display: { type: 'string', default: 'inline' },
                calendar_months: { type: 'string', default: '1' },
                calendar_selection_mode: { type: 'string', default: 'range' },
                show_schedule_text: { type: 'string', default: '1' },
                show_promo_code: { type: 'string', default: '1' },
                show_children_included: { type: 'string', default: '1' },
                free_additional_label: { type: 'string', default: 'free', role: 'content' },
                buttons_position: { type: 'string', default: 'inline' }
            },
            controlGroups: [
                { title: __('Boat', 'maradigma'), controls: commonIdentifier },
                { title: __('Booking', 'maradigma'), controls: [
                    { key: 'button_text', label: __('Button text', 'maradigma') },
                    { key: 'redirect_url_success', label: __('Success URL', 'maradigma') },
                    { key: 'calendar_display', label: __('Calendar display', 'maradigma'), type: 'select', options: [{label:'popup',value:'popup'},{label:'inline',value:'inline'}] },
                    { key: 'calendar_months', label: __('Calendar months', 'maradigma'), type: 'select', options: [{label:'1',value:'1'},{label:'2',value:'2'}] },
                    { key: 'calendar_selection_mode', label: __('Selection mode', 'maradigma'), type: 'select', options: [{label:'range',value:'range'},{label:'single',value:'single'}] },
                    { key: 'show_schedule_text', label: __('Show schedule text', 'maradigma'), type: 'select', options: boolOptions },
                    { key: 'show_promo_code', label: __('Show promo code', 'maradigma'), type: 'select', options: boolOptions },
                    { key: 'show_children_included', label: __('Show children included', 'maradigma'), type: 'select', options: boolOptions },
                    { key: 'free_additional_label', label: __('Free additional label', 'maradigma'), type: 'select', options: [{label:'free',value:'free'},{label:'included',value:'included'}] },
                    { key: 'buttons_position', label: __('Navigation buttons position', 'maradigma'), type: 'select', options: [{label:__('Modal footer', 'maradigma'),value:'footer'},{label:__('Inline with modal content', 'maradigma'),value:'inline'}] }
                ] }
            ]
        }
    ];

    registerBoatBindingPanel();

    blocks.forEach(function (definition) {
        var metadata = gutenbergConfig.blockMetadata && gutenbergConfig.blockMetadata[definition.name]
            ? gutenbergConfig.blockMetadata[definition.name]
            : {};

        registerBlockType(definition.name, {
            apiVersion: metadata.apiVersion || 3,
            title: metadata.title || definition.title,
            category: metadata.category || CATEGORY,
            icon: metadata.icon || definition.icon || 'admin-site-alt3',
            description: metadata.description || definition.description || '',
            attributes: metadata.attributes || definition.attributes || {},
            supports: metadata.supports || definition.supports || commonSupports,
            edit: createEdit(definition),
            save: function () {
                return null;
            }
        });
    });
})(window.wp);

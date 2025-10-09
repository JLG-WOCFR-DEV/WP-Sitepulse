(function (wp) {
    if (!wp || !wp.blocks) {
        return;
    }

    var __ = wp.i18n.__;
    var sprintf = wp.i18n.sprintf;
    var useMemo = wp.element.useMemo;
    var useRef = wp.element.useRef;
    var Fragment = wp.element.Fragment;
    var RawHTML = wp.element.RawHTML;
    var registerBlockType = wp.blocks.registerBlockType;
    var InspectorControls = wp.blockEditor.InspectorControls || wp.editor.InspectorControls;
    var useBlockProps = wp.blockEditor.useBlockProps || wp.editor.useBlockProps;
    var PanelBody = wp.components.PanelBody;
    var ToggleControl = wp.components.ToggleControl;
    var Notice = wp.components.Notice;
    var Button = wp.components.Button;

    var BLOCK_NAME = 'sitepulse/dashboard-preview';
    var MODULE_KEYS = ['speed', 'uptime', 'database', 'logs'];
    var summaryIdCounter = 0;

    function getConfig() {
        var config = window.SitePulseDashboardPreviewData || {};

        if (!config.modules) {
            config.modules = {};
        }

        if (!config.strings) {
            config.strings = {};
        }

        if (!config.settings) {
            config.settings = {};
        }

        if (!config.preview) {
            config.preview = {};
        }

        if (!Array.isArray(config.preview.cards)) {
            config.preview.cards = [];
        }

        if (!config.preview.statusLabels) {
            config.preview.statusLabels = {};
        }

        if (!config.preview.strings) {
            config.preview.strings = {};
        }

        return config;
    }

    function generateSummaryId() {
        summaryIdCounter += 1;
        return 'sitepulse-preview-summary-' + summaryIdCounter;
    }

    function sanitizeClass(value, fallback) {
        var result = typeof value === 'string' ? value : '';
        result = result.replace(/[^A-Za-z0-9_-]/g, '-');

        if (!result) {
            return fallback || '';
        }

        return result;
    }

    function normalizeNumber(value) {
        if (typeof value !== 'number' || !isFinite(value)) {
            return '';
        }

        var roundedInteger = Math.round(value);
        var roundedTwoDecimals = Math.round(value * 100) / 100;

        if (Math.abs(roundedTwoDecimals - roundedInteger) < 0.0005) {
            return roundedInteger.toLocaleString();
        }

        return roundedTwoDecimals.toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });
    }

    function ChartArea(props) {
        var chart = props.chart;
        var canvasId = props.canvasId;
        var strings = props.strings || {};

        var summary = useMemo(function () {
            if (!chart || typeof chart !== 'object') {
                return { id: '', element: null };
            }

            var labels = Array.isArray(chart.labels) ? chart.labels : [];
            var datasets = Array.isArray(chart.datasets) ? chart.datasets : [];

            if (!labels.length || !datasets.length) {
                return { id: '', element: null };
            }

            var items = [];

            labels.forEach(function (label, index) {
                var values = [];

                datasets.forEach(function (dataset) {
                    if (!dataset || !Array.isArray(dataset.data)) {
                        return;
                    }

                    var dataValue = dataset.data[index];

                    if (typeof dataValue === 'number' && isFinite(dataValue)) {
                        values.push(normalizeNumber(dataValue));
                    } else if (typeof dataValue === 'string') {
                        values.push(dataValue);
                    }
                });

                if (!values.length) {
                    return;
                }

                items.push(
                    wp.element.createElement(
                        'li',
                        { key: index },
                        wp.element.createElement(
                            'span',
                            { className: 'sitepulse-preview-list__label' },
                            String(label)
                        ),
                        wp.element.createElement(
                            'span',
                            { className: 'sitepulse-preview-list__value' },
                            values.join(', ')
                        )
                    )
                );
            });

            if (!items.length) {
                return { id: '', element: null };
            }

            var summaryId = generateSummaryId();

            return {
                id: summaryId,
                element: wp.element.createElement(
                    'div',
                    { id: summaryId, className: 'sitepulse-preview-summary' },
                    wp.element.createElement('ul', { className: 'sitepulse-preview-list' }, items)
                ),
            };
        }, [chart]);

        if (!chart || chart.empty || !Array.isArray(chart.datasets) || chart.datasets.length === 0) {
            return wp.element.createElement(
                'div',
                { className: 'sitepulse-chart-container' },
                wp.element.createElement(
                    'div',
                    { className: 'sitepulse-chart-placeholder' },
                    strings.noData || __('Pas encore de mesures disponibles pour ce graphique.', 'sitepulse')
                ),
                summary.element
            );
        }

        var scriptId = canvasId + '-data';
        var json = '';

        try {
            json = JSON.stringify(chart);
        } catch (error) {
            json = '';
        }

        if (!json) {
            json = '{}';
        }

        var canvasProps = {
            id: canvasId,
            role: 'img',
            'data-sitepulse-chart': '#' + scriptId,
            'data-sitepulse-chart-source': scriptId,
            'data-sitepulse-chart-format': 'application/json',
        };

        if (summary.id) {
            canvasProps['aria-describedby'] = summary.id;
        } else {
            canvasProps['aria-label'] = strings.chartAriaLabel || __('Aperçu du graphique des données SitePulse.', 'sitepulse');
        }

        return wp.element.createElement(
            'div',
            { className: 'sitepulse-chart-container' },
            wp.element.createElement(
                'canvas',
                canvasProps,
                wp.element.createElement(
                    'span',
                    { 'aria-hidden': 'true' },
                    strings.canvasFallback || __('Votre navigateur ne prend pas en charge les graphiques. Consultez le résumé textuel ci-dessous.', 'sitepulse')
                ),
                wp.element.createElement(
                    'span',
                    { className: 'screen-reader-text' },
                    strings.canvasSrFallback || __('Les données du graphique sont détaillées dans le résumé textuel qui suit.', 'sitepulse')
                )
            ),
            wp.element.createElement('script', {
                type: 'application/json',
                id: scriptId,
                className: 'sitepulse-chart-data',
                dangerouslySetInnerHTML: { __html: json },
            }),
            summary.element
        );
    }

    function PreviewCard(props) {
        var card = props.card || {};
        var statusLabels = props.statusLabels || {};
        var chartStrings = props.chartStrings || {};
        var canvasId = props.canvasId;

        var classes = Array.isArray(card.classes) ? card.classes.join(' ') : '';

        if (!classes) {
            classes = 'sitepulse-card';
        }

        var statusKey = typeof card.status === 'string' ? card.status : '';
        var normalizedStatusKey = sanitizeClass(statusKey, 'status-warn');
        var defaultStatus = {
            label: __('Indisponible', 'sitepulse'),
            sr: __('Statut non disponible', 'sitepulse'),
            icon: 'ℹ️',
        };
        var statusMeta = statusLabels[statusKey] || statusLabels[normalizedStatusKey] || statusLabels['status-warn'] || defaultStatus;
        var badgeClass = normalizedStatusKey || 'status-warn';

        var metricChildren = [
            wp.element.createElement(
                'span',
                {
                    key: 'badge',
                    className: 'status-badge ' + badgeClass,
                    'aria-hidden': 'true',
                },
                wp.element.createElement('span', { className: 'status-icon' }, statusMeta.icon || ''),
                wp.element.createElement('span', { className: 'status-text' }, statusMeta.label || '')
            ),
            wp.element.createElement('span', { key: 'sr', className: 'screen-reader-text' }, statusMeta.sr || '')
        ];

        if (card.metricHtml) {
            metricChildren.push(
                wp.element.createElement(
                    RawHTML,
                    { key: 'metric' },
                    card.metricHtml
                )
            );
        }

        if (card.afterMetricHtml) {
            metricChildren.push(
                wp.element.createElement(
                    RawHTML,
                    { key: 'after-metric' },
                    card.afterMetricHtml
                )
            );
        }

        return wp.element.createElement(
            'section',
            { className: classes },
            wp.element.createElement(
                'div',
                { className: 'sitepulse-card-header' },
                wp.element.createElement('h3', null, card.title || '')
            ),
            wp.element.createElement('p', { className: 'sitepulse-card-subtitle' }, card.subtitle || ''),
            wp.element.createElement(ChartArea, { chart: card.chart, canvasId: canvasId, strings: chartStrings }),
            wp.element.createElement('p', { className: 'sitepulse-metric' }, metricChildren),
            wp.element.createElement('p', { className: 'description' }, card.description || '')
        );
    }

    registerBlockType(BLOCK_NAME, {
        edit: function (props) {
            props = props || {};
            var attributes = props.attributes || {};
            var setAttributes = props.setAttributes || function () {};
            var config = getConfig();
            var modules = config.modules;
            var moduleSettingsUrl = config.settings.moduleSettingsUrl;
            var moduleActivationUrl = config.settings.moduleActivationUrl || moduleSettingsUrl;
            var moduleSettingsHelp = config.strings.moduleSettingsHelp;
            var preview = config.preview || {};
            var previewCards = Array.isArray(preview.cards) ? preview.cards : [];
            var statusLabels = preview.statusLabels || {};
            var previewStrings = preview.strings || {};

            var inactiveModules = useMemo(function () {
                return MODULE_KEYS.filter(function (key) {
                    return modules[key] && modules[key].enabled === false;
                });
            }, [modules]);

            var inactiveNotice = useMemo(function () {
                if (!inactiveModules.length) {
                    return null;
                }

                var labels = inactiveModules.map(function (key) {
                    return modules[key].label || key;
                });

                var template = config.strings.inactiveNotice || __('Les modules suivants sont désactivés : %s', 'sitepulse');

                return sprintf(template, labels.join(', '));
            }, [inactiveModules, modules]);

            var allowedLayouts = ['grid', 'list'];
            var layoutVariant = typeof attributes.layoutVariant === 'string' ? attributes.layoutVariant : 'grid';
            if (allowedLayouts.indexOf(layoutVariant) === -1) {
                layoutVariant = 'grid';
            }
            var layoutVariantSlug = sanitizeClass(layoutVariant, 'grid');

            var allowedDensities = ['compact', 'comfortable', 'spacious'];
            var gridDensity = typeof attributes.gridDensity === 'string' ? attributes.gridDensity : 'comfortable';
            if (allowedDensities.indexOf(gridDensity) === -1) {
                gridDensity = 'comfortable';
            }
            var gridDensitySlug = sanitizeClass(gridDensity, 'comfortable');

            var columns = parseInt(attributes.columns, 10);
            if (isNaN(columns)) {
                columns = 2;
            }
            if (columns < 1) {
                columns = 1;
            } else if (columns > 4) {
                columns = 4;
            }

            var showHeader = attributes.hasOwnProperty('showHeader') ? !!attributes.showHeader : true;

            var activeCards = useMemo(function () {
                return previewCards.filter(function (card) {
                    if (!card || typeof card !== 'object') {
                        return false;
                    }

                    var moduleKey = card.moduleKey || '';

                    if (!moduleKey) {
                        return false;
                    }

                    var attributeKey = 'show' + moduleKey.charAt(0).toUpperCase() + moduleKey.slice(1);
                    var attributeEnabled = attributes.hasOwnProperty(attributeKey) ? !!attributes[attributeKey] : true;
                    var moduleState = modules[moduleKey];
                    var moduleEnabled = moduleState ? moduleState.enabled !== false : true;

                    return attributeEnabled && moduleEnabled;
                });
            }, [previewCards, attributes, modules]);

            var hasCards = activeCards.length > 0;

            var wrapperClasses = [
                'sitepulse-dashboard-preview',
                'sitepulse-dashboard-preview--layout-' + layoutVariantSlug,
                'sitepulse-dashboard-preview--density-' + gridDensitySlug,
                'sitepulse-dashboard-preview--columns-' + columns,
            ];

            wrapperClasses.push(showHeader ? 'sitepulse-dashboard-preview--has-header' : 'sitepulse-dashboard-preview--no-header');

            if (!hasCards) {
                wrapperClasses.push('sitepulse-dashboard-preview--is-empty');
            }

            var gridClasses = [
                'sitepulse-grid',
                'sitepulse-grid--cols-' + columns,
                'sitepulse-grid--density-' + gridDensitySlug,
                'sitepulse-grid--variant-' + layoutVariantSlug,
            ];

            var gridClassName = gridClasses.map(function (value) {
                return sanitizeClass(value, value);
            }).join(' ');

            var blockProps = useBlockProps({ className: wrapperClasses.join(' ') });

            var uniquePrefixRef = useRef(null);

            if (uniquePrefixRef.current === null) {
                uniquePrefixRef.current = 'sitepulse-preview-' + Math.random().toString(36).slice(2);
            }

            var uniquePrefix = sanitizeClass(uniquePrefixRef.current, 'sitepulse-preview');

            var chartStrings = {
                noData: previewStrings.noData,
                canvasFallback: previewStrings.canvasFallback,
                canvasSrFallback: previewStrings.canvasSrFallback,
                chartAriaLabel: previewStrings.chartAriaLabel,
            };

            var children = [];

            if (inactiveNotice) {
                children.push(
                    wp.element.createElement(
                        Notice,
                        { status: 'warning', isDismissible: false, className: 'sitepulse-dashboard-preview-block__notice', key: 'notice' },
                        wp.element.createElement('p', null, inactiveNotice),
                        config.strings.inactiveNoticeHelp ? wp.element.createElement('p', null, config.strings.inactiveNoticeHelp) : null,
                        moduleSettingsHelp ? wp.element.createElement('p', null, moduleSettingsHelp) : null,
                        moduleSettingsUrl || moduleActivationUrl
                            ? wp.element.createElement(
                                  'div',
                                  { className: 'sitepulse-dashboard-preview-block__notice-actions' },
                                  moduleSettingsUrl
                                      ? wp.element.createElement(
                                            Button,
                                            {
                                                key: 'primary',
                                                href: moduleSettingsUrl,
                                                variant: 'secondary',
                                                isSecondary: true,
                                                target: '_self',
                                            },
                                            config.strings.inactiveNoticeCta || __('Gérer les modules', 'sitepulse')
                                        )
                                      : null,
                                  moduleActivationUrl && moduleActivationUrl !== moduleSettingsUrl && config.strings.inactiveNoticeSecondaryCta
                                      ? wp.element.createElement(
                                            Button,
                                            {
                                                key: 'secondary',
                                                href: moduleActivationUrl,
                                                variant: 'link',
                                                isLink: true,
                                                target: '_self',
                                            },
                                            config.strings.inactiveNoticeSecondaryCta
                                        )
                                      : null
                              )
                            : null
                    )
                );
            }

            if (!hasCards) {
                children.push(
                    wp.element.createElement(
                        'div',
                        { className: 'sitepulse-dashboard-preview__empty', key: 'empty' },
                        previewStrings.emptyState || __('Aucune carte à afficher pour le moment. Activez les modules souhaités ou collectez davantage de données.', 'sitepulse')
                    )
                );
            } else {
                if (showHeader) {
                    children.push(
                        wp.element.createElement(
                            'div',
                            { className: 'sitepulse-dashboard-preview__header', key: 'header' },
                            wp.element.createElement('h3', null, previewStrings.headerTitle || __('Aperçu SitePulse', 'sitepulse')),
                            wp.element.createElement('p', null, previewStrings.headerSubtitle || __('Dernières mesures agrégées par vos modules actifs.', 'sitepulse'))
                        )
                    );
                }

                var cards = activeCards.map(function (card) {
                    var moduleKey = card.moduleKey || '';
                    var chartSuffix = card.chartSuffix || moduleKey || 'chart';
                    var chartSlug = sanitizeClass(chartSuffix, 'chart');
                    var canvasId = uniquePrefix + '-' + chartSlug + '-chart';

                    return wp.element.createElement(PreviewCard, {
                        key: moduleKey || canvasId,
                        card: card,
                        statusLabels: statusLabels,
                        chartStrings: chartStrings,
                        canvasId: canvasId,
                    });
                });

                children.push(
                    wp.element.createElement(
                        'div',
                        { className: gridClassName, key: 'grid' },
                        cards
                    )
                );
            }

            return wp.element.createElement(
                Fragment,
                null,
                wp.element.createElement(
                    InspectorControls,
                    null,
                    wp.element.createElement(
                        PanelBody,
                        { title: __('Affichage des modules', 'sitepulse'), initialOpen: true },
                        MODULE_KEYS.map(function (key) {
                            var attributeKey = 'show' + key.charAt(0).toUpperCase() + key.slice(1);
                            var isEnabled = attributes.hasOwnProperty(attributeKey) ? attributes[attributeKey] : true;
                            var label = (modules[key] && modules[key].controlLabel) || (modules[key] && modules[key].label) || key;
                            var moduleDisabled = modules[key] && modules[key].enabled === false;
                            var toggleHelp = null;

                            if (moduleDisabled) {
                                var helpIntro = config.strings.moduleDisabledHelp || __('Ce module est actuellement désactivé. Activez-le depuis les réglages de SitePulse.', 'sitepulse');
                                var helpMore = config.strings.moduleDisabledHelpMore || __('Activez ce module pour l’afficher dans le bloc Aperçu du tableau de bord.', 'sitepulse');
                                var helpText = [helpIntro, helpMore].filter(Boolean).join(' ');
                                var helpCtaLabel = config.strings.moduleDisabledHelpCta || __('Accéder aux réglages des modules', 'sitepulse');
                                var helpCta = moduleSettingsUrl
                                    ? wp.element.createElement(
                                          Button,
                                          {
                                              href: moduleSettingsUrl,
                                              variant: 'link',
                                              isLink: true,
                                              target: '_self',
                                          },
                                          helpCtaLabel
                                      )
                                    : null;

                                toggleHelp = helpCta ? wp.element.createElement(Fragment, null, helpText, ' ', helpCta) : helpText;
                            }

                            return wp.element.createElement(ToggleControl, {
                                key: key,
                                label: label,
                                checked: !!isEnabled,
                                onChange: function (value) {
                                    var next = {};
                                    next[attributeKey] = !!value;
                                    setAttributes(next);
                                },
                                disabled: moduleDisabled,
                                help: toggleHelp,
                                __nextHasNoMarginBottom: true,
                            });
                        })
                    ),
                    wp.element.createElement(
                        PanelBody,
                        { title: __('Aide', 'sitepulse'), initialOpen: false },
                        wp.element.createElement(
                            'p',
                            null,
                            __('Ce bloc affiche un aperçu des principales métriques suivies par SitePulse.', 'sitepulse')
                        ),
                        wp.element.createElement(
                            'p',
                            null,
                            __('Les données proviennent des derniers relevés enregistrés. Utilisez les options ci-dessus pour masquer les cartes non pertinentes.', 'sitepulse')
                        )
                    )
                ),
                wp.element.createElement('div', blockProps, children)
            );
        },
        save: function () {
            return null;
        },
    });
})(window.wp);

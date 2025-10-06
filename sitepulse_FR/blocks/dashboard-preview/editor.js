(function (wp) {
    if (!wp || !wp.blocks) {
        return;
    }

    var __ = wp.i18n.__;
    var sprintf = wp.i18n.sprintf;
    var useMemo = wp.element.useMemo;
    var Fragment = wp.element.Fragment;
    var registerBlockType = wp.blocks.registerBlockType;
    var InspectorControls = wp.blockEditor.InspectorControls || wp.editor.InspectorControls;
    var useBlockProps = wp.blockEditor.useBlockProps || wp.editor.useBlockProps;
    var PanelBody = wp.components.PanelBody;
    var ToggleControl = wp.components.ToggleControl;
    var Notice = wp.components.Notice;
    var Button = wp.components.Button;
    var ServerSideRender = wp.serverSideRender;

    var BLOCK_NAME = 'sitepulse/dashboard-preview';
    var MODULE_KEYS = ['speed', 'uptime', 'database', 'logs'];

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
        return config;
    }

    registerBlockType(BLOCK_NAME, {
        edit: function (props) {
            props = props || {};
            var attributes = props.attributes || {};
            var setAttributes = props.setAttributes || function () {};
            var blockProps = useBlockProps();
            var config = getConfig();
            var modules = config.modules;
            var moduleSettingsUrl = config.settings.moduleSettingsUrl;
            var moduleActivationUrl = config.settings.moduleActivationUrl || moduleSettingsUrl;
            var moduleSettingsHelp = config.strings.moduleSettingsHelp;
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
                                              target: '_self'
                                          },
                                          helpCtaLabel
                                      )
                                    : null;

                                toggleHelp = helpCta
                                    ? wp.element.createElement(Fragment, null, helpText, ' ', helpCta)
                                    : helpText;
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
                                __nextHasNoMarginBottom: true
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
                wp.element.createElement(
                    'div',
                    blockProps,
                    inactiveNotice &&
                        wp.element.createElement(
                            Notice,
                            { status: 'warning', isDismissible: false, className: 'sitepulse-dashboard-preview-block__notice' },
                            wp.element.createElement('p', null, inactiveNotice),
                            config.strings.inactiveNoticeHelp
                                ? wp.element.createElement('p', null, config.strings.inactiveNoticeHelp)
                                : null,
                            moduleSettingsHelp ? wp.element.createElement('p', null, moduleSettingsHelp) : null,
                            (moduleSettingsUrl || moduleActivationUrl)
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
                                                    target: '_self'
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
                                                    target: '_self'
                                                },
                                                config.strings.inactiveNoticeSecondaryCta
                                            )
                                          : null
                                  )
                                : null
                        ),
                    wp.element.createElement(ServerSideRender, {
                        block: BLOCK_NAME,
                        attributes: attributes
                    })
                )
            );
        },
        save: function () {
            return null;
        }
    });
})(window.wp);

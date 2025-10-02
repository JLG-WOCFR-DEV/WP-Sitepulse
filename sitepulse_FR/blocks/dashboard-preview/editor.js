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

            var inactiveNotice = useMemo(function () {
                var inactive = MODULE_KEYS.filter(function (key) {
                    return modules[key] && modules[key].enabled === false;
                });

                if (!inactive.length) {
                    return null;
                }

                var labels = inactive.map(function (key) {
                    return modules[key].label || key;
                });

                var template = config.strings.inactiveNotice || __('Les modules suivants sont désactivés : %s', 'sitepulse');

                return sprintf(template, labels.join(', '));
            }, [modules]);

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

                            return wp.element.createElement(ToggleControl, {
                                key: key,
                                label: label,
                                checked: !!isEnabled,
                                onChange: function (value) {
                                    var next = {};
                                    next[attributeKey] = !!value;
                                    setAttributes(next);
                                },
                                disabled: modules[key] && modules[key].enabled === false
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
                            inactiveNotice
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

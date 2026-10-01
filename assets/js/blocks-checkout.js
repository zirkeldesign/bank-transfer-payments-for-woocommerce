/* global wc, wp */
(function () {
    'use strict';

    var registry = window.wc && window.wc.wcBlocksRegistry;
    var settingsApi = window.wc && window.wc.wcSettings;
    var element = window.wp && window.wp.element;

    if (!registry || !settingsApi || !element) {
        return;
    }

    var data = settingsApi.getSetting('stripe_bank_transfer_data', {});
    var createElement = element.createElement;

    function Content() {
        return createElement(
            'div',
            { className: 'btpw-blocks-content' },
            data.description ? createElement('p', null, data.description) : null,
            data.notice ? createElement('p', null, data.notice) : null
        );
    }

    registry.registerPaymentMethod({
        name: 'stripe_bank_transfer',
        label: data.title || 'Bank Transfer',
        ariaLabel: data.title || 'Bank Transfer',
        content: createElement(Content, null),
        edit: createElement(Content, null),
        canMakePayment: function () {
            return true;
        },
        supports: {
            features: data.supports || ['products']
        }
    });
})();

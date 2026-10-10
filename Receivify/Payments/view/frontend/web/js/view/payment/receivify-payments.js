/**
 * Receivify for Magento 2 — register the method renderer.
 */
define([
    'uiComponent',
    'Magento_Checkout/js/model/payment/renderer-list'
], function (Component, rendererList) {
    'use strict';

    rendererList.push({
        type: 'receivify_payments',
        component: 'Receivify_Payments/js/view/payment/method-renderer/receivify-method'
    });

    return Component.extend({});
});

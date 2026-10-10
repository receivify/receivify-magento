/**
 * Receivify for Magento 2 — the checkout method renderer.
 *
 * Magento places the order, then we send the browser to
 * /receivify/checkout/start, which creates the Receivify invoice server-side and
 * redirects on to the hosted checkout. The API key never reaches the browser.
 */
define([
    'Magento_Checkout/js/view/payment/default',
    'mage/url'
], function (Component, url) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'Receivify_Payments/payment/receivify'
        },

        /** The text under the radio button, from ConfigProvider. */
        getInstructions: function () {
            return window.checkoutConfig.payment[this.getCode()]
                ? window.checkoutConfig.payment[this.getCode()].instructions
                : '';
        },

        getCode: function () {
            return 'receivify_payments';
        },

        /** Hand off to the server-side controller once the order exists. */
        afterPlaceOrder: function () {
            var config = window.checkoutConfig.payment[this.getCode()],
                target = (config && config.redirectUrl) || url.build('receivify/checkout/start');

            window.location.replace(target);
        }
    });
});

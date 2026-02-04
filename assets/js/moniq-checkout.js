/**
 * Moniq Payment Gateway - WooCommerce Blocks Integration
 */
const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
const { getSetting } = window.wc.wcSettings;
const { __ } = window.wp.i18n;
const { decodeEntities } = window.wp.htmlEntities;

const settings = getSetting( 'moniq_gateway_data', {} );

const Content = () => {
    return wp.element.createElement( 'div', null, decodeEntities( settings.description || '' ) );
};

const Label = () => {
    const { createElement } = wp.element;

    return createElement(
        'span',
        { className: 'wc-block-components-payment-method-label' },
        createElement(
            'span',
            { className: 'wc-block-components-payment-method-label__text' },
            decodeEntities( settings.title || __( 'Moniq', 'moniq-gateway' ) )
        ),
        settings.icon && createElement(
            'img',
            {
                src: settings.icon,
                alt: decodeEntities( settings.title || __( 'Moniq', 'moniq-gateway' ) ),
                className: 'wc-block-components-payment-method-label__icon',
                loading: 'lazy'
            }
        )
    );
};

const Moniq = {
    name: 'moniq_gateway',
    label: wp.element.createElement( Label ),
    content: wp.element.createElement( Content ),
    edit: wp.element.createElement( Content ),
    canMakePayment: () => true,
    ariaLabel: decodeEntities( settings.title || __( 'Moniq payment method', 'moniq-gateway' ) ),
    supports: {
        features: settings.supports || [],
    },
};

registerPaymentMethod( Moniq );

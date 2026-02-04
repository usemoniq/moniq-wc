<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Moniq Payment Gateway Blocks Integration.
 *
 * @since 2.0.0
 */
final class WC_Moniq_Blocks_Integration extends AbstractPaymentMethodType {

    private $gateway;
    protected $name = 'moniq_gateway';

    public function initialize() {
        $this->settings = get_option( 'woocommerce_moniq_gateway_settings', array() );
        $gateways = WC()->payment_gateways->get_available_payment_gateways();
        if ( isset( $gateways[ $this->name ] ) ) {
            $this->gateway = $gateways[ $this->name ];
        }
    }

    public function is_active() {
        return ! empty( $this->gateway ) && $this->gateway->is_available();
    }

    public function get_payment_method_script_handles() {
        $script_handle = 'moniq-blocks-integration';
        $script_url = plugin_dir_url( dirname( __FILE__ ) ) . 'assets/js/moniq-checkout.js';

        wp_register_script(
            $script_handle,
            $script_url,
            array(
                'wc-blocks-registry',
                'wc-settings',
                'wp-element',
                'wp-html-entities',
                'wp-i18n',
            ),
            defined( 'MONIQ_GATEWAY_VERSION' ) ? MONIQ_GATEWAY_VERSION : '2.0.0',
            true
        );

        if ( $this->gateway ) {
            wp_localize_script(
                $script_handle,
                'moniq_gateway_data',
                apply_filters( 'wc_moniq_gateway_script_data', array(
                    'title'       => $this->gateway->get_title(),
                    'description' => $this->gateway->get_description(),
                    'icon'        => $this->gateway->icon,
                    'supports'    => array_keys( $this->gateway->supports ),
                    'gateway_id'  => $this->gateway->id,
                ), $this->gateway )
            );
        }

        if ( function_exists( 'wp_set_script_translations' ) ) {
            wp_set_script_translations(
                $script_handle,
                'moniq-gateway',
                plugin_dir_path( dirname( __FILE__ ) ) . 'languages'
            );
        }

        return array( $script_handle );
    }

    public function get_payment_method_data() {
        if ( ! $this->gateway ) {
            return array();
        }

        return array(
            'title'       => $this->gateway->get_title(),
            'description' => $this->gateway->get_description(),
            'icon'        => $this->gateway->icon,
            'supports'    => $this->gateway->supports,
        );
    }
}

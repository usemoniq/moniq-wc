<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Moniq Payment Gateway.
 *
 * @class       WC_Moniq_Gateway
 * @extends     WC_Payment_Gateway
 * @version     2.0.0
 */
class WC_Moniq_Gateway extends WC_Payment_Gateway {

    public $api_handler;
    public $logger;
    public $public_key;
    public $api_secret;
    public $debug;
    private $webhook_secret;

    public function __construct() {
        $this->id                 = 'moniq_gateway';
        $this->icon               = apply_filters( 'woocommerce_moniq_gateway_icon', plugin_dir_url( __FILE__ ) . 'assets/images/icon.png' );
        $this->has_fields         = false;
        $this->method_title       = __( 'Moniq', 'moniq-gateway' );
        $this->method_description = __( 'Accept payments through Moniq. Customers are redirected to complete their purchase.', 'moniq-gateway' );

        $this->supports = array( 'products' );

        $this->init_form_fields();
        $this->init_settings();

        $this->title          = $this->get_option( 'title' );
        $this->description    = $this->get_option( 'description' );
        $this->enabled        = $this->get_option( 'enabled' );
        $this->public_key     = $this->get_option( 'public_key' );
        $this->api_secret     = $this->get_option( 'api_secret' );
        $this->webhook_secret = $this->get_option( 'webhook_secret' );
        $this->debug          = 'yes' === $this->get_option( 'debug', 'no' );

        if ( class_exists( 'WC_Moniq_Logger' ) ) {
            $this->logger = new WC_Moniq_Logger( $this->debug );
        }
        if ( class_exists( 'WC_Moniq_API' ) ) {
            $this->api_handler = new WC_Moniq_API( $this );
        }

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page_content' ) );
        add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );
        add_action( 'woocommerce_api_wc_moniq_gateway', array( $this, 'handle_webhook' ) );
        add_action( 'woocommerce_checkout_create_order', array( $this, 'save_order_metadata_on_checkout' ), 10, 2 );
        add_action( 'woocommerce_blocks_loaded', array( $this, 'woocommerce_gateway_moniq_woocommerce_block_support' ) );
    }

    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'   => __( 'Enable/Disable', 'moniq-gateway' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable Moniq Payment Gateway', 'moniq-gateway' ),
                'default' => 'yes',
            ),
            'title' => array(
                'title'       => __( 'Title', 'moniq-gateway' ),
                'type'        => 'text',
                'description' => __( 'Title displayed during checkout.', 'moniq-gateway' ),
                'default'     => __( 'Moniq', 'moniq-gateway' ),
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __( 'Description', 'moniq-gateway' ),
                'type'        => 'textarea',
                'description' => __( 'Description displayed during checkout.', 'moniq-gateway' ),
                'default'     => __( 'Pay securely using Moniq. You will be redirected to complete your purchase.', 'moniq-gateway' ),
            ),
            'api_details' => array(
                'title'       => __( 'API Credentials', 'moniq-gateway' ),
                'type'        => 'title',
                'description' => sprintf(
                    __( 'Enter your Moniq API credentials from your %sMoniq Dashboard%s.', 'moniq-gateway' ),
                    '<a href="https://dashboard.everydaymoney.app" target="_blank">',
                    '</a>'
                ) . '<br><button type="button" class="button" id="moniq-test-connection">' . __( 'Test API Connection', 'moniq-gateway' ) . '</button><div id="moniq-test-connection-message" style="margin-top:10px;"></div>',
            ),
            'public_key' => array(
                'title'       => __( 'Public Key', 'moniq-gateway' ),
                'type'        => 'text',
                'description' => __( 'Your API Public Key.', 'moniq-gateway' ),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'api_secret' => array(
                'title'       => __( 'API Secret', 'moniq-gateway' ),
                'type'        => 'password',
                'description' => __( 'Your API Secret Key.', 'moniq-gateway' ),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'webhook_secret' => array(
                'title'       => __( 'Webhook Secret', 'moniq-gateway' ),
                'type'        => 'password',
                'description' => sprintf(
                    __( 'Webhook endpoint secret for signature verification. Your webhook URL: %s', 'moniq-gateway' ),
                    '<code>' . WC()->api_request_url( 'wc_moniq_gateway' ) . '</code>'
                ),
                'default'     => '',
            ),
            'advanced' => array(
                'title' => __( 'Advanced Options', 'moniq-gateway' ),
                'type'  => 'title',
            ),
            'order_status_on_success' => array(
                'title'       => __( 'Order Status on Success', 'moniq-gateway' ),
                'type'        => 'select',
                'description' => __( 'Order status after successful payment.', 'moniq-gateway' ),
                'default'     => 'processing',
                'desc_tip'    => true,
                'options'     => array(
                    'processing' => __( 'Processing', 'moniq-gateway' ),
                    'completed'  => __( 'Completed', 'moniq-gateway' ),
                ),
            ),
            'debug' => array(
                'title'       => __( 'Debug Log', 'moniq-gateway' ),
                'type'        => 'checkbox',
                'label'       => __( 'Enable logging', 'moniq-gateway' ),
                'default'     => 'no',
                'description' => sprintf(
                    __( 'Log Moniq events. Logs are in %s', 'moniq-gateway' ),
                    '<code>WooCommerce > Status > Logs</code>'
                ),
            ),
        );
    }

    public function is_available() {
        if ( ! parent::is_available() ) {
            return false;
        }

        if ( empty( $this->public_key ) || empty( $this->api_secret ) ) {
            if ( isset( $this->logger ) ) {
                $this->logger->log( 'Gateway not available: credentials not set.', 'warning' );
            }
            return false;
        }

        return true;
    }

    public function payment_fields() {
        if ( $this->description ) {
            echo wpautop( wp_kses_post( $this->description ) );
        }
    }

    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wc_add_notice( __( 'Order not found.', 'moniq-gateway' ), 'error' );
            return array( 'result' => 'failure' );
        }

        $this->logger->log( 'Processing payment for order #' . $order->get_order_number(), 'info' );

        try {
            $charge_data = $this->prepare_charge_data( $order );
            $response = $this->api_handler->create_charge( $charge_data );

            if ( is_wp_error( $response ) ) {
                $this->logger->log( 'API Error: ' . $response->get_error_message(), 'error' );
                wc_add_notice( sprintf( __( 'Payment error: %s', 'moniq-gateway' ), esc_html( $response->get_error_message() ) ), 'error' );
                return array( 'result' => 'failure' );
            }

            $checkout_url   = isset( $response['checkoutURL'] ) ? $response['checkoutURL'] : null;
            $charge_data    = isset( $response['order']['charges'][0] ) ? $response['order']['charges'][0] : null;
            $transaction_id = isset( $charge_data['transactionRef'] ) ? $charge_data['transactionRef'] : null;
            $api_order_id   = isset( $response['order']['id'] ) ? $response['order']['id'] : null;

            if ( empty( $checkout_url ) || empty( $transaction_id ) ) {
                $this->logger->log( 'Invalid API response structure', 'error' );
                wc_add_notice( __( 'Payment error: Invalid response from payment provider.', 'moniq-gateway' ), 'error' );
                return array( 'result' => 'failure' );
            }

            $this->save_transaction_to_db( $order, $response );

            $order->update_status( 'pending', __( 'Awaiting payment confirmation from Moniq.', 'moniq-gateway' ) );
            $order->update_meta_data( '_moniq_transaction_id', sanitize_text_field( $transaction_id ) );
            $order->update_meta_data( '_moniq_order_id', sanitize_text_field( $api_order_id ) );
            $order->save();

            wc_reduce_stock_levels( $order_id );
            WC()->cart->empty_cart();

            $this->logger->log( 'Redirecting to: ' . $checkout_url, 'info' );

            return array(
                'result'   => 'success',
                'redirect' => esc_url_raw( $checkout_url ),
            );

        } catch ( Exception $e ) {
            $this->logger->log( 'Exception: ' . $e->getMessage(), 'error' );
            wc_add_notice( __( 'Payment error: An unexpected error occurred.', 'moniq-gateway' ), 'error' );
            return array( 'result' => 'failure' );
        }
    }

    private function prepare_charge_data( $order ) {
        $order_lines = array();

        foreach ( $order->get_items() as $item_id => $item ) {
            $product = $item->get_product();
            $qty = (int) $item->get_quantity();
            $unit_price = $qty > 0 ? $order->get_line_subtotal( $item, false, false ) / $qty : 0;

            $order_lines[] = array(
                'itemName' => substr( $item->get_name(), 0, 255 ),
                'quantity' => $qty,
                'amount'   => round( (float) $unit_price, wc_get_price_decimals() ),
            );
        }

        if ( $order->get_shipping_total() > 0 ) {
            $order_lines[] = array(
                'itemName' => substr( sprintf( __( 'Shipping: %s', 'moniq-gateway' ), $order->get_shipping_method() ), 0, 255 ),
                'quantity' => 1,
                'amount'   => round( (float) $order->get_shipping_total(), wc_get_price_decimals() ),
            );
        }

        foreach ( $order->get_fees() as $fee ) {
            $order_lines[] = array(
                'itemName' => substr( $fee->get_name(), 0, 255 ),
                'quantity' => 1,
                'amount'   => round( (float) $fee->get_total(), wc_get_price_decimals() ),
            );
        }

        if ( $order->get_total_tax() > 0 && ! wc_prices_include_tax() ) {
            $order_lines[] = array(
                'itemName' => __( 'Tax', 'moniq-gateway' ),
                'quantity' => 1,
                'amount'   => round( (float) $order->get_total_tax(), wc_get_price_decimals() ),
            );
        }

        $customer_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
        if ( empty( $customer_name ) ) {
            $customer_name = $order->get_billing_email() ?: 'Guest';
        }

        $charge_data = array(
            'currency'       => $order->get_currency(),
            'email'          => $order->get_billing_email(),
            'phone'          => $order->get_billing_phone() ?: '',
            'customerName'   => $customer_name,
            'narration'      => sprintf( __( 'Order #%s from %s', 'moniq-gateway' ), $order->get_order_number(), get_bloginfo( 'name' ) ),
            'transactionRef' => 'WC-' . $order->get_order_number() . '-' . time(),
            'referenceKey'   => $order->get_order_key(),
            'redirectUrl'    => $this->get_return_url( $order ),
            'webhookUrl'     => WC()->api_request_url( 'wc_moniq_gateway' ),
            'orderLines'     => $order_lines,
            'metadata'       => array(
                'order_id'     => (string) $order->get_id(),
                'order_number' => (string) $order->get_order_number(),
                'store_url'    => get_site_url(),
                'store_name'   => get_bloginfo( 'name' ),
            ),
        );

        // Add customer address if available
        if ( $order->get_billing_address_1() ) {
            $charge_data['customerAddress'] = array(
                'line1'      => $order->get_billing_address_1() ?: '',
                'line2'      => $order->get_billing_address_2() ?: '',
                'city'       => $order->get_billing_city() ?: '',
                'state'      => $order->get_billing_state() ?: '',
                'postalCode' => $order->get_billing_postcode() ?: '',
                'country'    => $order->get_billing_country() ?: '',
            );
        }

        return apply_filters( 'wc_moniq_charge_data', $charge_data, $order );
    }

    private function save_transaction_to_db( $order, $response ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'moniq_transactions';

        $transaction_id = isset( $response['order']['charges'][0]['transactionRef'] ) ? $response['order']['charges'][0]['transactionRef'] : '';

        $wpdb->insert(
            $table_name,
            array(
                'order_id'        => $order->get_id(),
                'transaction_id'  => sanitize_text_field( $transaction_id ),
                'transaction_ref' => sanitize_text_field( $transaction_id ),
                'status'          => 'pending',
                'amount'          => $order->get_total(),
                'currency'        => $order->get_currency(),
                'webhook_data'    => wp_json_encode( $response ),
                'created_at'      => current_time( 'mysql', true ),
                'updated_at'      => current_time( 'mysql', true ),
            ),
            array( '%d', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s' )
        );

        if ( $wpdb->last_error ) {
            $this->logger->log( 'DB Insert Error: ' . $wpdb->last_error, 'error' );
        }
    }

    public function handle_webhook() {
        if ( ! isset( $this->logger ) ) {
            return;
        }

        $this->logger->log( 'Webhook received', 'info' );
        $payload = file_get_contents( 'php://input' );

        if ( ! $this->verify_webhook_signature( $payload, getallheaders() ) ) {
            $this->logger->log( 'Invalid webhook signature', 'error' );
            status_header( 401 );
            exit( 'Invalid signature' );
        }

        $data = json_decode( $payload, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            $this->logger->log( 'Invalid JSON in webhook', 'error' );
            status_header( 400 );
            exit( 'Invalid JSON' );
        }

        $this->logger->log( 'Webhook data: ' . print_r( $data, true ), 'debug' );

        try {
            $this->process_webhook( $data );
            status_header( 200 );
            exit( 'OK' );
        } catch ( Exception $e ) {
            $this->logger->log( 'Webhook error: ' . $e->getMessage(), 'error' );
            status_header( 500 );
            exit( 'Processing error' );
        }
    }

    private function process_webhook( $data ) {
        $order = $this->get_order_from_webhook( $data );
        if ( ! $order ) {
            $this->logger->log( 'Order not found for webhook', 'warning' );
            return;
        }

        if ( $order->is_paid() || $order->has_status( array( 'completed', 'processing', 'failed', 'cancelled' ) ) ) {
            $this->logger->log( 'Order #' . $order->get_order_number() . ' already in final state', 'info' );
            return;
        }

        $verified_data = $this->verify_with_api( $order );
        if ( ! $verified_data ) {
            $order->add_order_note( __( 'Moniq webhook received but API verification failed.', 'moniq-gateway' ) );
            return;
        }

        $charge = isset( $verified_data['charges'][0] ) ? $verified_data['charges'][0] : null;
        $status = isset( $charge['status'] ) ? strtolower( $charge['status'] ) : 'unknown';
        $successful_statuses = array( 'completed', 'paid', 'successful', 'succeeded' );

        $this->update_transaction_in_db( $order->get_id(), $status, $verified_data );

        if ( in_array( $status, $successful_statuses ) ) {
            $transaction_id = $charge['transactionRef'];
            $order->payment_complete( $transaction_id );
            $order->add_order_note( sprintf( __( 'Moniq payment completed. Transaction: %s', 'moniq-gateway' ), $transaction_id ) );

            $status_on_success = $this->get_option( 'order_status_on_success', 'processing' );
            $order->update_status( $status_on_success, __( 'Payment confirmed by Moniq.', 'moniq-gateway' ) );
            $this->logger->log( 'Payment completed for order #' . $order->get_order_number(), 'info' );

        } elseif ( $status === 'failed' ) {
            $reason = isset( $charge['statusReason'] ) ? sanitize_text_field( $charge['statusReason'] ) : __( 'Unknown', 'moniq-gateway' );
            $order->update_status( 'failed', sprintf( __( 'Moniq payment failed: %s', 'moniq-gateway' ), $reason ) );
            $this->logger->log( 'Payment failed for order #' . $order->get_order_number(), 'info' );
        }
    }

    private function get_order_from_webhook( $data ) {
        $api_order_id = isset( $data['orderId'] ) ? sanitize_text_field( $data['orderId'] ) : null;
        $transaction_ref = isset( $data['transactionRef'] ) ? sanitize_text_field( $data['transactionRef'] ) : null;
        $transaction_id = isset( $data['transactionId'] ) ? sanitize_text_field( $data['transactionId'] ) : null;

        $query_args = array(
            'limit'      => 1,
            'meta_query' => array( 'relation' => 'OR' ),
        );

        if ( $api_order_id ) {
            $query_args['meta_query'][] = array( 'key' => '_moniq_order_id', 'value' => $api_order_id );
        }
        if ( $transaction_ref ) {
            $query_args['meta_query'][] = array( 'key' => '_moniq_transaction_id', 'value' => $transaction_ref );
        }
        if ( $transaction_id ) {
            $query_args['meta_query'][] = array( 'key' => '_moniq_transaction_id', 'value' => $transaction_id );
        }

        if ( count( $query_args['meta_query'] ) <= 1 ) {
            return false;
        }

        $orders = wc_get_orders( $query_args );
        return ! empty( $orders ) ? $orders[0] : false;
    }

    private function verify_with_api( $order ) {
        $api_order_id = $order->get_meta( '_moniq_order_id' );

        if ( empty( $api_order_id ) ) {
            $this->logger->log( 'No API order ID found for order #' . $order->get_order_number(), 'error' );
            return false;
        }

        $verified_data = $this->api_handler->verify_order( $api_order_id );

        if ( is_wp_error( $verified_data ) ) {
            $this->logger->log( 'API verification failed: ' . $verified_data->get_error_message(), 'error' );
            return false;
        }

        // Verify amount
        $order_total = (float) $order->get_total();
        $verified_amount = isset( $verified_data['amount'] ) ? (float) $verified_data['amount'] : 0.0;

        if ( abs( $order_total - $verified_amount ) > 0.01 ) {
            $this->logger->log( 'Amount mismatch: WC=' . $order_total . ', API=' . $verified_amount, 'error' );
            return false;
        }

        return $verified_data;
    }

    private function verify_webhook_signature( $payload, $headers ) {
        if ( empty( $this->webhook_secret ) ) {
            $this->logger->log( 'Webhook secret not configured - skipping verification', 'warning' );
            return true;
        }

        $signature_keys = array( 'X-Moniq-Signature', 'X-Everydaymoney-Signature', 'HTTP_X_MONIQ_SIGNATURE', 'HTTP_X_EVERYDAYMONEY_SIGNATURE' );
        $signature_header = '';

        foreach ( $signature_keys as $key ) {
            $normalized = str_replace( '-', '_', strtoupper( $key ) );
            if ( isset( $headers[ $key ] ) ) {
                $signature_header = $headers[ $key ];
                break;
            }
            if ( isset( $_SERVER[ 'HTTP_' . $normalized ] ) ) {
                $signature_header = $_SERVER[ 'HTTP_' . $normalized ];
                break;
            }
        }

        if ( empty( $signature_header ) ) {
            $this->logger->log( 'Missing signature header', 'error' );
            return false;
        }

        $elements = array();
        parse_str( str_replace( ',', '&', $signature_header ), $elements );

        $timestamp = isset( $elements['t'] ) ? $elements['t'] : null;
        $signature = isset( $elements['v1'] ) ? $elements['v1'] : null;

        if ( empty( $timestamp ) || empty( $signature ) ) {
            $this->logger->log( 'Malformed signature header', 'error' );
            return false;
        }

        if ( abs( time() - intval( $timestamp ) ) > 300 ) {
            $this->logger->log( 'Webhook timestamp expired', 'error' );
            return false;
        }

        $expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, $this->webhook_secret );

        return hash_equals( $expected, $signature );
    }

    private function update_transaction_in_db( $order_id, $status, $data ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'moniq_transactions';

        $wpdb->update(
            $table_name,
            array(
                'status'       => sanitize_text_field( $status ),
                'webhook_data' => wp_json_encode( $data ),
                'updated_at'   => current_time( 'mysql', true ),
            ),
            array( 'order_id' => $order_id )
        );
    }

    public function thankyou_page_content() {
        global $wp;
        if ( empty( $wp->query_vars['order-received'] ) ) {
            return;
        }

        $order = wc_get_order( absint( $wp->query_vars['order-received'] ) );

        if ( ! $order || $order->get_payment_method() !== $this->id ) {
            return;
        }

        if ( $order->has_status( array( 'pending', 'on-hold' ) ) ) {
            echo '<p>' . esc_html__( 'Your payment is being processed. You will receive confirmation shortly.', 'moniq-gateway' ) . '</p>';
        } elseif ( $order->has_status( 'failed' ) ) {
            echo '<p class="woocommerce-error">' . esc_html__( 'Payment could not be processed. Please try again.', 'moniq-gateway' ) . '</p>';
        } elseif ( $order->has_status( array( 'processing', 'completed' ) ) ) {
            echo '<p class="woocommerce-thankyou-order-received">' . esc_html__( 'Thank you. Your payment has been received.', 'moniq-gateway' ) . '</p>';
        }
    }

    public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
        if ( ! $sent_to_admin && $this->id === $order->get_payment_method() && $order->has_status( array( 'pending', 'on-hold' ) ) ) {
            $text = esc_html__( 'Your payment is being processed. You will be notified when confirmed.', 'moniq-gateway' );
            echo $plain_text ? $text . PHP_EOL : '<p>' . $text . '</p>';
        }
    }

    public function save_order_metadata_on_checkout( $order, $data ) {
        if ( $order->get_payment_method() === $this->id ) {
            $order->update_meta_data( '_moniq_checkout_initiated_at', current_time( 'timestamp', true ) );
        }
    }

    public function admin_options() {
        ?>
        <h2><?php echo esc_html( $this->get_method_title() ); ?></h2>
        <?php echo wp_kses_post( wpautop( $this->get_method_description() ) ); ?>
        <table class="form-table">
            <?php $this->generate_settings_html(); ?>
        </table>
        <?php
    }
}

<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Moniq API Handler.
 *
 * @class       WC_Moniq_API
 * @version     2.0.0
 */
class WC_Moniq_API {

    private $gateway;
    private $api_base_url;
    private $logger;

    public function __construct( $gateway ) {
        $this->gateway      = $gateway;
        $this->logger       = new WC_Moniq_Logger( $this->gateway->debug );
        $this->api_base_url = rtrim( MONIQ_GATEWAY_API_URL, '/' );
    }

    /**
     * Verify an order by fetching from API.
     *
     * @param string $api_order_id The order ID from API.
     * @return array|WP_Error
     */
    public function verify_order( $api_order_id ) {
        $this->logger->log( 'Verifying order: ' . $api_order_id, 'info' );

        if ( empty( $api_order_id ) ) {
            return new WP_Error( 'missing_order_id', __( 'API Order ID is required.', 'moniq-gateway' ) );
        }

        return $this->make_request( '/business/order/' . sanitize_text_field( $api_order_id ), array(), 'GET' );
    }

    /**
     * Create a charge.
     *
     * @param array $charge_data Charge data.
     * @return array|WP_Error
     */
    public function create_charge( $charge_data ) {
        $this->logger->log( 'Creating charge', 'info' );

        $required = array( 'currency', 'email', 'orderLines' );
        foreach ( $required as $field ) {
            if ( empty( $charge_data[ $field ] ) ) {
                return new WP_Error( 'missing_field', sprintf( __( 'Missing required field: %s', 'moniq-gateway' ), $field ) );
            }
        }

        return $this->make_request( '/payment/checkout/api-charge-order', $charge_data, 'POST' );
    }

    /**
     * Test API connection.
     *
     * @return bool|WP_Error
     */
    public function test_connection() {
        $this->logger->log( 'Testing API connection', 'info' );

        // Clear cached token
        $transient_key = 'moniq_token_' . md5( $this->gateway->public_key );
        delete_transient( $transient_key );

        $jwt = $this->get_jwt_token();

        if ( $jwt && ! is_wp_error( $jwt ) ) {
            $this->logger->log( 'Connection test successful', 'info' );
            return true;
        } elseif ( is_wp_error( $jwt ) ) {
            return $jwt;
        }

        return new WP_Error( 'test_failed', __( 'Failed to obtain JWT token.', 'moniq-gateway' ) );
    }

    /**
     * Get JWT token with caching.
     *
     * @return string|false
     */
    private function get_jwt_token() {
        $transient_key = 'moniq_token_' . md5( $this->gateway->public_key );
        $cached_token = get_transient( $transient_key );

        if ( false !== $cached_token ) {
            $this->logger->log( 'Using cached JWT token', 'debug' );
            return $cached_token;
        }

        if ( empty( $this->gateway->public_key ) || empty( $this->gateway->api_secret ) ) {
            $this->logger->log( 'Missing API credentials', 'error' );
            return false;
        }

        $auth_string = base64_encode( $this->gateway->public_key . ':' . $this->gateway->api_secret );

        $response = wp_remote_post(
            $this->api_base_url . '/auth/business/token',
            array(
                'method'  => 'POST',
                'headers' => array(
                    'Content-Type'  => 'application/json',
                    'X-Api-Key'     => $this->gateway->public_key,
                    'Authorization' => 'Basic ' . $auth_string,
                ),
                'timeout' => 45,
            )
        );

        if ( is_wp_error( $response ) ) {
            $this->logger->log( 'JWT request error: ' . $response->get_error_message(), 'error' );
            return false;
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $body      = wp_remote_retrieve_body( $response );
        $parsed    = json_decode( $body, true );

        if ( ( $http_code === 200 || $http_code === 201 ) &&
             isset( $parsed['isError'] ) && false === $parsed['isError'] &&
             isset( $parsed['result'] ) && is_string( $parsed['result'] ) ) {

            $jwt = $parsed['result'];
            set_transient( $transient_key, $jwt, 3400 ); // Cache for ~56 minutes
            $this->logger->log( 'JWT token obtained and cached', 'info' );
            return $jwt;
        }

        $this->logger->log( 'Failed to obtain JWT. HTTP: ' . $http_code . ' Body: ' . $body, 'error' );
        return false;
    }

    /**
     * Make API request.
     *
     * @param string $endpoint API endpoint.
     * @param array  $data     Request data.
     * @param string $method   HTTP method.
     * @return array|WP_Error
     */
    private function make_request( $endpoint, $data = array(), $method = 'POST' ) {
        $jwt = $this->get_jwt_token();
        if ( ! $jwt ) {
            return new WP_Error( 'auth_failed', __( 'Authentication failed.', 'moniq-gateway' ) );
        }

        $url = $this->api_base_url . $endpoint;
        $args = array(
            'method'  => strtoupper( $method ),
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $jwt,
            ),
            'timeout' => 45,
        );

        if ( ! empty( $data ) && in_array( $method, array( 'POST', 'PUT' ) ) ) {
            $args['body'] = wp_json_encode( $data );
        } elseif ( ! empty( $data ) && $method === 'GET' ) {
            $url = add_query_arg( $data, $url );
        }

        $this->logger->log( 'API Request: ' . $method . ' ' . $url, 'info' );
        if ( isset( $args['body'] ) ) {
            $this->logger->log( 'Request body: ' . $args['body'], 'debug' );
        }

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            $this->logger->log( 'API Error: ' . $response->get_error_message(), 'error' );
            return $response;
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $body      = wp_remote_retrieve_body( $response );
        $parsed    = json_decode( $body, true );

        $this->logger->log( 'API Response: HTTP ' . $http_code, 'info' );
        $this->logger->log( 'Response body: ' . $body, 'debug' );

        if ( ( $http_code >= 200 && $http_code < 300 ) &&
             isset( $parsed['isError'] ) && false === $parsed['isError'] &&
             isset( $parsed['result'] ) ) {
            return $parsed['result'];
        }

        $error_message = $this->extract_error( $parsed );
        $this->logger->log( 'API Error: ' . $error_message, 'error' );

        return new WP_Error( 'api_error', esc_html( $error_message ), array( 'status' => $http_code ) );
    }

    /**
     * Extract error message from response.
     *
     * @param array $parsed Parsed response.
     * @return string
     */
    private function extract_error( $parsed ) {
        if ( isset( $parsed['result']['message'] ) ) {
            return is_array( $parsed['result']['message'] ) ? implode( ', ', $parsed['result']['message'] ) : $parsed['result']['message'];
        }
        if ( isset( $parsed['message'] ) ) {
            return is_array( $parsed['message'] ) ? implode( ', ', $parsed['message'] ) : $parsed['message'];
        }
        if ( isset( $parsed['error'] ) ) {
            return is_array( $parsed['error'] ) ? implode( ', ', $parsed['error'] ) : $parsed['error'];
        }
        return __( 'An unknown API error occurred.', 'moniq-gateway' );
    }
}

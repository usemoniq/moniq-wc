<?php
/**
 * Plugin Name:       Moniq Payment Gateway
 * Plugin URI:        https://moniq.app/integrations
 * Description:       Accept payments through Moniq. Customers are redirected to a secure checkout page to complete their purchase.
 * Version:           2.0.0
 * Author:            Moniq
 * Author URI:        https://moniq.app
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * WC requires at least: 3.5
 * WC tested up to:   9.0
 * Text Domain:       moniq-gateway
 * Domain Path:       /languages
 * Requires Plugins:  woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'MONIQ_GATEWAY_PLUGIN_FILE', __FILE__ );
define( 'MONIQ_GATEWAY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MONIQ_GATEWAY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MONIQ_GATEWAY_VERSION', '2.0.0' );
define( 'MONIQ_GATEWAY_API_URL', 'https://em-api-prod.everydaymoney.app' );

/**
 * Main initialization function.
 */
function moniq_gateway_init() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', 'moniq_gateway_missing_woocommerce_notice' );
        return;
    }

    add_filter( 'woocommerce_payment_gateways', 'moniq_add_gateway_class' );
    add_filter( 'plugin_action_links_' . plugin_basename( MONIQ_GATEWAY_PLUGIN_FILE ), 'moniq_gateway_add_settings_link' );
    add_action( 'admin_enqueue_scripts', 'moniq_gateway_enqueue_admin_assets' );
    load_plugin_textdomain( 'moniq-gateway', false, dirname( plugin_basename( MONIQ_GATEWAY_PLUGIN_FILE ) ) . '/languages/' );

    // Declare HPOS compatibility
    add_action( 'before_woocommerce_init', function() {
        if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', MONIQ_GATEWAY_PLUGIN_FILE, true );
        }
    } );

    // Register blocks integration
    if ( class_exists( 'Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry' ) ) {
        add_action(
            'woocommerce_blocks_payment_method_type_registration',
            function( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
                $integration_file = MONIQ_GATEWAY_PLUGIN_DIR . 'includes/class-wc-moniq-blocks-integration.php';
                if ( file_exists( $integration_file ) ) {
                    require_once $integration_file;
                    $payment_method_registry->register( new WC_Moniq_Blocks_Integration() );
                }
            }
        );
    }

    add_action( 'wp_ajax_moniq_test_connection', 'moniq_ajax_test_connection' );
}
add_action( 'plugins_loaded', 'moniq_gateway_init' );

/**
 * Add gateway class to WooCommerce.
 */
function moniq_add_gateway_class( $gateways ) {
    $includes_dir = MONIQ_GATEWAY_PLUGIN_DIR . 'includes/';

    if ( ! is_dir( $includes_dir ) ) {
        return $gateways;
    }

    $files = array(
        $includes_dir . 'class-wc-moniq-logger.php',
        $includes_dir . 'class-wc-moniq-api.php',
        MONIQ_GATEWAY_PLUGIN_DIR . 'class-wc-moniq-gateway.php',
    );

    foreach ( $files as $file ) {
        if ( file_exists( $file ) ) {
            require_once $file;
        }
    }

    if ( class_exists( 'WC_Moniq_Gateway' ) ) {
        $gateways[] = 'WC_Moniq_Gateway';
    }

    return $gateways;
}

/**
 * Plugin activation - create transactions table.
 */
function moniq_gateway_activate() {
    global $wpdb;
    $table_name      = $wpdb->prefix . 'moniq_transactions';
    $charset_collate = $wpdb->get_charset_collate();

    if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) !== $table_name ) {
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            order_id bigint(20) UNSIGNED NOT NULL,
            transaction_id varchar(255) DEFAULT '' NOT NULL,
            transaction_ref varchar(255) DEFAULT '' NOT NULL,
            status varchar(50) DEFAULT 'pending' NOT NULL,
            amount decimal(19,4) NOT NULL,
            currency varchar(10) NOT NULL,
            webhook_data longtext DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY order_id (order_id),
            UNIQUE KEY unique_transaction_id (transaction_id(191))
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }
}
register_activation_hook( MONIQ_GATEWAY_PLUGIN_FILE, 'moniq_gateway_activate' );

/**
 * Admin notice if WooCommerce is not active.
 */
function moniq_gateway_missing_woocommerce_notice() {
    ?>
    <div class="error">
        <p><?php esc_html_e( 'Moniq Payment Gateway requires WooCommerce to be installed and activated.', 'moniq-gateway' ); ?></p>
    </div>
    <?php
}

/**
 * Add settings link on plugins page.
 */
function moniq_gateway_add_settings_link( $links ) {
    $settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=moniq_gateway' ) ) . '">' . esc_html__( 'Settings', 'moniq-gateway' ) . '</a>';
    array_unshift( $links, $settings_link );
    return $links;
}

/**
 * Enqueue admin scripts.
 */
function moniq_gateway_enqueue_admin_assets( $hook_suffix ) {
    if ( 'woocommerce_page_wc-settings' !== $hook_suffix ) {
        return;
    }

    $current_section = isset( $_GET['section'] ) ? sanitize_text_field( wp_unslash( $_GET['section'] ) ) : '';
    if ( 'moniq_gateway' !== $current_section ) {
        return;
    }

    wp_enqueue_script(
        'moniq-gateway-admin',
        MONIQ_GATEWAY_PLUGIN_URL . 'assets/js/moniq-admin.js',
        array( 'jquery' ),
        MONIQ_GATEWAY_VERSION,
        true
    );

    wp_localize_script(
        'moniq-gateway-admin',
        'moniq_admin_params',
        array(
            'ajax_url'        => admin_url( 'admin-ajax.php' ),
            'nonce'           => wp_create_nonce( 'moniq_test_connection_nonce' ),
            'testing_message' => __( 'Testing connection...', 'moniq-gateway' ),
            'success_message' => __( 'Connection successful!', 'moniq-gateway' ),
            'failure_message' => __( 'Connection failed: ', 'moniq-gateway' ),
        )
    );
}

/**
 * AJAX handler for testing API connection.
 */
function moniq_ajax_test_connection() {
    check_ajax_referer( 'moniq_test_connection_nonce', 'nonce' );

    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'moniq-gateway' ) ), 403 );
        return;
    }

    $payment_gateways = WC()->payment_gateways();
    if ( ! $payment_gateways ) {
        wp_send_json_error( array( 'message' => __( 'Could not load payment gateways.', 'moniq-gateway' ) ), 500 );
        return;
    }

    $gateways = $payment_gateways->payment_gateways();
    if ( ! isset( $gateways['moniq_gateway'] ) ) {
        wp_send_json_error( array( 'message' => __( 'Gateway not found. Please save your settings first.', 'moniq-gateway' ) ), 500 );
        return;
    }

    $gateway_instance = $gateways['moniq_gateway'];

    if ( ! isset( $gateway_instance->api_handler ) || ! method_exists( $gateway_instance->api_handler, 'test_connection' ) ) {
        wp_send_json_error( array( 'message' => __( 'API Handler not initialized.', 'moniq-gateway' ) ), 500 );
        return;
    }

    $result = $gateway_instance->api_handler->test_connection();

    if ( true === $result ) {
        wp_send_json_success( array( 'message' => __( 'Connection successful!', 'moniq-gateway' ) ) );
    } elseif ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
    } else {
        wp_send_json_error( array( 'message' => __( 'Connection test failed.', 'moniq-gateway' ) ), 500 );
    }
}

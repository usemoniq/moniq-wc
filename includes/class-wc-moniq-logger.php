<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Moniq Logger.
 *
 * @class       WC_Moniq_Logger
 * @version     2.0.0
 */
class WC_Moniq_Logger {

    private static $wc_logger;
    private $debug_enabled = false;

    public function __construct( $debug_enabled = false ) {
        $this->debug_enabled = (bool) $debug_enabled;
    }

    private static function get_wc_logger() {
        if ( null === self::$wc_logger ) {
            self::$wc_logger = wc_get_logger();
        }
        return self::$wc_logger;
    }

    /**
     * Log a message.
     *
     * @param string $message Message to log.
     * @param string $level   Log level.
     */
    public function log( $message, $level = 'info' ) {
        if ( ! $this->debug_enabled && in_array( $level, array( 'debug', 'info' ) ) ) {
            return;
        }

        $logger = self::get_wc_logger();
        $context = array( 'source' => 'moniq-gateway' );
        $message = is_scalar( $message ) ? $message : print_r( $message, true );

        switch ( $level ) {
            case 'emergency':
                $logger->emergency( $message, $context );
                break;
            case 'alert':
                $logger->alert( $message, $context );
                break;
            case 'critical':
                $logger->critical( $message, $context );
                break;
            case 'error':
                $logger->error( $message, $context );
                break;
            case 'warning':
                $logger->warning( $message, $context );
                break;
            case 'notice':
                $logger->notice( $message, $context );
                break;
            case 'info':
                $logger->info( $message, $context );
                break;
            case 'debug':
            default:
                if ( $this->debug_enabled ) {
                    $logger->debug( $message, $context );
                }
                break;
        }
    }
}

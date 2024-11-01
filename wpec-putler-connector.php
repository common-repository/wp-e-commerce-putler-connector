<?php
/*
 * Plugin Name: WP-e-Commerce Putler Connector
 * Plugin URI: http://putler.com/connector/wpec/
 * Description: Track WPeC transactions data with Putler. Insightful reporting that grows your business.
 * Version: 2.1
 * Author: putler, storeapps
 * Author URI: http://putler.com/
 * License: GPL 3.0
*/

add_action( 'plugins_loaded', 'wpsc_putler_connector_pre_init' );

function wpsc_putler_connector_pre_init () {

	// Simple check for Wpec being active...
	if ( class_exists('WP_eCommerce') ) {

		// Init admin menu for settings etc if we are in admin
		if ( is_admin() ) {
			wpsc_putler_connector_init();
		} 

                // If configuration not done, can't track anything...
		if ( null != get_option('putler_connector_settings', null) ) {
                        // On these events, send order data to Putler
                        if ( is_admin() ) {
                            add_action( 'wpsc_purchase_log_save', 'wpsc_putler_connector_post_order' );
                        } else {
                            add_action( 'wpsc_submit_checkout', 'wpsc_putler_connector_post_order' );   
                        }
                        
//                                         
                }
                
	}
}

function wpsc_putler_connector_init() {
	
	include_once 'classes/class.putler-connector.php';
	$GLOBALS['putler_connector'] = Putler_Connector::getInstance();

        include_once 'classes/class.putler-connector-wpec.php';
        if ( !isset( $GLOBALS['wpec_putler_connector'] ) ) {
            $GLOBALS['wpec_putler_connector'] = new WPeC_Putler_Connector();
	}
}

function wpsc_putler_connector_post_order( $purchlog_data ) {
    
        if( is_object($purchlog_data) ){
                $purchase_id = $purchlog_data->get( 'id' );
        } else if( isset( $purchlog_data['purchase_log_id'] ) && is_array( $purchlog_data ) ){
                $purchase_id = $purchlog_data['purchase_log_id'] ;
        }
        wpsc_putler_connector_init();
        if (method_exists($GLOBALS['putler_connector'], 'post_order') ) {
                $GLOBALS['putler_connector']->post_order( $purchase_id );	
        }
}


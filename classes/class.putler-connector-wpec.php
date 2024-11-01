<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

if ( ! class_exists( 'WPeC_Putler_Connector' ) ) {
    
    class WPeC_Putler_Connector {
        
        private $name = 'wpsc';

        public function __construct() {
            add_filter('putler_connector_get_order_count', array( &$this, 'get_order_count') );
            add_filter('putler_connector_get_orders', array( &$this, 'get_orders') );
        }

        public function get_order_count( $count )  {
            global $wpdb;
            $order_count = 0;
            
            $query_to_fetch_order_count = "SELECT COUNT(*)
                                            FROM {$wpdb->prefix}wpsc_purchase_logs AS wpsc_purchase_logs";
            
            $order_count_result = $wpdb->get_col( $query_to_fetch_order_count );
            
            if( !empty( $order_count_result ) ) {
                    $order_count = $order_count_result[0];
            }
            
            return $count + $order_count;
        }
        
        public function get_orders( $params )  {
            global $wpdb;
            
            $order_status = array(
                              '1' => 'Pending',
                              '2' => 'Pending',
                              '3' => 'Completed',
                              '4' => 'Completed',
                              '5' => 'Completed',
                              '6' => 'Pending') ;
            
            $query_currency = "SELECT code FROM {$wpdb->prefix}wpsc_currency_list WHERE id ='" . esc_attr( get_option( 'currency_type' ) ) . "'";
            $result_currency = $wpdb->get_col( $query_currency );
            $currency = $result_currency[0];
            
            //Code to get the last order sent
            $cond = '';

            if ( empty($params['order_id']) ) {
                $start_limit = (isset($params[ $this->name ]['start_limit'])) ? $params[ $this->name ]['start_limit'] : 0;
                $batch_limit = (isset($params['limit'])) ? $params['limit'] : 50;    
            } else {
                $start_limit = 0;
                $batch_limit = 1;
                $cond = 'WHERE wppl.id IN(' .intval($params['order_id']). ')'; 
            }
            
            // Query to get order ids
            $fetch_order_details = "SELECT wppl.id,
                                        wppl.totalprice AS amount,
                                        wppl.processed AS order_status,
                                        wppl.user_ID AS customer_id, 
                                        wppl.date AS unixdate,
                                        wppl.discount_value AS discount,
                                        wppl.wpec_taxes_total AS total_tax,
                                        wppl.base_shipping AS total_shipping,
                                        wppl.notes AS order_notes
                                FROM {$wpdb->prefix}wpsc_purchase_logs AS wppl 
                                $cond
                                GROUP BY wppl.id ";
            
            $results_order_details = $wpdb->get_results( $fetch_order_details, 'ARRAY_A' );
            $results_order_ids_count = $wpdb->num_rows;
            
            if ( $results_order_ids_count > 0 ) {
                
                $order_ids = array(); 
                 
                foreach ( $results_order_details as $results_order_detail ) {
                    $order_ids[] = $results_order_detail['id'];                    
                }
                
                // Query to fetch cart items 
                
                $fetch_cart_items = " SELECT wtcc.prodid AS product_ids,
                                            wtcc.name AS cart_item_name,
                                            wtcc.quantity AS cart_quantity,
                                            wtcc.purchaseid AS purchase_id,
                                            wtcc.price AS cart_price
                                        FROM {$wpdb->prefix}wpsc_cart_contents AS wtcc
                                        WHERE wtcc.purchaseid IN (". implode(",",$order_ids) .") ";
                                        
                $results_fetch_cart_items = $wpdb->get_results( $fetch_cart_items, 'ARRAY_A' );
                $results_cart_items_count = $wpdb->num_rows;
                
                $cart_items = array();
                
                if( $results_cart_items_count > 0 ){
                 
                    foreach( $results_fetch_cart_items as $cart_item ){
                        $order_id = $cart_item['purchase_id'];
                        $product_id = $cart_item['product_ids'];
                        
                        if( !isset( $cart_items[$order_id] ) ){
                            $cart_items[$order_id] = array();
                            $cart_items[$order_id]['tot_qty'] = 0;
                            $cart_items[$order_id]['cart_items'] = array();
                        }
                        
                        if ( !isset($cart_items[$order_id]['cart_items'][$product_id] ) ) {
                            $cart_items[$order_id]['cart_items'][$product_id] = array();
                            $cart_items[$order_id]['tot_qty']++;
                            $cart_items[$order_id]['cart_items'][$product_id]['product_name'] = $cart_item['cart_item_name'];
                            $cart_items[$order_id]['cart_items'][$product_id]['quantity'] = $cart_item['cart_quantity'];
                            $cart_items[$order_id]['cart_items'][$product_id]['price'] = $cart_item['cart_price'];
                        } 
                        
                    }
                    
                }//  check whether count of cart_item is greater than 0
                
                // Query to fetch user info
                
                $fetch_user_info = " SELECT wsfd.log_id,wsfd.value,wcf.unique_name
                                        FROM {$wpdb->prefix}wpsc_submited_form_data AS wsfd
                                        JOIN {$wpdb->prefix}wpsc_checkout_forms AS wcf
                                        ON ( wsfd.form_id = wcf.id ) 
                                        WHERE wcf.unique_name LIKE 'billing%'
                                        AND wsfd.log_id IN (". implode(",",$order_ids) .") ";
                
                $results_fetch_user_info = $wpdb->get_results( $fetch_user_info, 'ARRAY_A' );
                $results_fetch_user_info_count = $wpdb->num_rows;
                
                $user_info = array();
                
                if( $results_fetch_user_info_count > 0 ) {
                    
                    foreach( $results_fetch_user_info as $user_detail ){
                        
                        $order_id = $user_detail['log_id'];
                        $order_billing_key = $user_detail['unique_name'];
                        $order_billing_value = $user_detail['value'];
                        
                        if( !isset( $user_info[$order_id] ) ){
                            $user_info[$order_id] = array();
                        }
                        
                        $user_info[$order_id][$order_billing_key] = $order_billing_value;
                        
                    }
                    
                }
                
                //Code for Data Mapping as per Putler
                foreach( $results_order_details as $order_detail ){
                    
                    $response = array();
                    $order_id = $order_detail['id'];
                    $status_id = $order_detail['order_status'];
                    // $dateInGMT = gmdate('Y-m-d', $order_detail['unixdate'] );
                    $dateInGMT = gmdate('m/d/Y', $order_detail['unixdate'] );
                    $timeInGMT = gmdate('H:i:s', $order_detail['unixdate'] );
                    
                    $response ['Date'] = $dateInGMT;
                    $response ['Time'] = $timeInGMT;
                    $response ['Time_Zone'] = 'GMT';
                    
                    $response ['Source'] = $this->name;
                    $response ['Name'] = $user_info[$order_id]['billingfirstname'] . ' ' . $user_info[$order_id]['billinglastname'];
                    // $response ['Type'] = ( $status == "refunded") ? 'Refund' : 'Shopping Cart Payment Received';
                    $response ['Type'] = 'Shopping Cart Payment Received';
                    
                    $response ['Status'] = ucfirst( $order_status[$status_id] );

                    $response ['Currency'] = $currency;

                    $response ['Gross'] = $order_detail['amount'];
                    $response ['Fee'] = 0.00;
                    $response ['Net'] = $order_detail['amount'];

                    $response ['From_Email_Address'] = $user_info[$order_id]['billingemail'] ;
                    $response ['To_Email_Address'] = '';
                    $response ['Transaction_ID'] = $order_id ;
                    $response ['Counterparty_Status'] = '';
                    $response ['Address_Status'] = '';
                    $response ['Item_Title'] = 'Shopping Cart';
                    $response ['Item_ID'] = 0; // Set to 0 for main Order Transaction row
                    $response ['Shipping_and_Handling_Amount'] = ( isset( $order_detail[$order_id]['total_shipping'] ) ) ? round ( $order_detail[$order_id]['total_shipping'], 2 ) : 0.00;
                    $response ['Insurance_Amount'] = '';
                    $response ['Discount'] = isset( $order_detail[$order_id]['discount'] ) ? round ( $order_detail[$order_id]['discount'], 2 ) : 0.00;
                            
                    $response ['Sales_Tax'] = isset( $order_detail[$order_id]['total_tax'] ) ? round ( $order_detail[$order_id]['total_tax'], 2 ) : 0.00;

                    $response ['Option_1_Name'] = '';
                    $response ['Option_1_Value'] = '';
                    $response ['Option_2_Name'] = '';
                    $response ['Option_2_Value'] = '';
                            
                    $response ['Auction_Site'] = '';
                    $response ['Buyer_ID'] = '';
                    $response ['Item_URL'] = '';
                    $response ['Closing_Date'] = '';
                    $response ['Escrow_ID'] = '';
                    $response ['Invoice_ID'] = '';
                    $response ['Reference_Txn_ID'] = '';
                    $response ['Invoice_Number'] = '';
                    $response ['Custom_Number'] = '';
                    $response ['Quantity'] = $cart_items[$order_id]['tot_qty']; 
                    $response ['Receipt_ID'] = '';

                    $response ['Balance'] = '';
                    $response ['Note'] = $order_detail['order_notes'] ;
                    $response ['Address_Line_1'] = ( isset( $user_info[$order_id]['billingaddress'] ) ) ? $user_info[$order_id]['billingaddress'] : '';
                    $response ['Address_Line_2'] = '';
                    $response ['Town_City'] = isset( $user_info[$order_id]['billingcity'] ) ? $user_info[$order_id]['billingcity'] : '' ;
                    $response ['State_Province'] = $user_info[$order_id]['billingstate'];
                    $response ['Zip_Postal_Code'] = isset( $user_info[$order_id]['billingpostcode'] ) ? $user_info[$order_id]['billingpostcode'] : '';
                    $response ['Country'] = isset( $user_info[$order_id]['billingcountry'] ) ? $user_info[$order_id]['billingcountry'] : '';
                    $response ['Contact_Phone_Number'] = isset( $user_info[$order_id]['billingphone']) ? $user_info[$order_id]['order_data']['billingphone'] : '';
                    $response ['Subscription_ID'] = '';

                    $transactions [] = $response;
                    
                    foreach( $cart_items[$order_id]['cart_items'] as $product_id => $cart_item ) {
                        
                        $order_item = array();
                        $order_item ['Type'] = 'Shopping Cart Item';
                        $order_item ['Item_Title'] = $cart_item['product_name'];
                        $order_item ['Item_ID'] = $product_id;
                        $order_item ['Gross'] = round ( $cart_item['price'], 2 );
                        $order_item ['Quantity'] = $cart_item['quantity'];
                        
                        $product = get_post( $product_id );
                        $parent_product_id = $product->post_parent;
                        
                        if( $parent_product_id && $parent_product_id !== 0 ){
                            
                            $prod_name = $cart_item['product_name'];
                            $product_attr_vals = explode( ",", substr( $prod_name, strrpos( $prod_name, '(')  + 1, -1) );
                            if( count( $product_attr_vals ) > 0 ){
                                $order_item['Option_1_Name'] = '';
                                $order_item['Option_1_Value'] = implode(",", $product_attr_vals);;
                            }
                            
                        }
                        
                        $transactions [] = array_merge ( $response, $order_item );
                        
                                                
                    }
                            
                }
                
                if ( empty($params['order_id']) ) {
                    $order_count = (is_array($results_order_details)) ? count($results_order_details) : 0 ;              
                    $params[ $this->name ] = array('count' => $order_count, 'last_start_limit' => $start_limit, 'data' => $transactions );
                } else {
                    $params['data'] = $transactions;
                }
                
                
                
            } else {

            }
            
            return $params;
        }
        
        
    }
    
    
}

<?php
/**
 * Plugin Name: WooCommerce Veeqo Shipment Tracking
 * Description: Integrating Veeqo with shipment tracking
 * Author: John Zuxer
 * Author URI: https://www.upwork.com/freelancers/~01f35acec4c4e5f366
 * Version: 1.0
 * License: GPL2 or later
 */

if( !defined( 'ABSPATH' ) ) exit;

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

new WC_Veeqo_Shipment_Tracking();
class WC_Veeqo_Shipment_Tracking{
	public static $option_names;
	public static $log;
	
	public function __construct(){
		self::$option_names = array(
			'orders_to_check' => 'wc_veeqo_shipment_tracking_orders_to_check'
		);
		
		//add_action( 'init', array( $this, 'load_plugin_textdomain' ) );
		
		add_action( 'woocommerce_new_order', 'add_order_to_check_shipment_later' );
		
		if ( ! wp_next_scheduled( 'wc_veeqo_shipment_tracking_check_orders_shipment' ) ) {
			wp_schedule_event( time(), 'hourly', 'wc_veeqo_shipment_tracking_check_orders_shipment' );
		}
		//add_action( 'wc_veeqo_shipment_tracking_check_orders_shipment', array( $this, 'check_orders_shipment' ) );
	}
	
	public function load_plugin_textdomain(){
		load_plugin_textdomain( 'wc_veeqo_shipment_tracking', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
	}
	
	public function log_error( $message ){
		if ( empty( self::$log ) ) {
			self::$log = new WC_Logger();
		}
		self::$log->add( 'wc_veeqo_shipment_tracking', $message );
	}
	
	public function add_order_to_check_shipment_later( $order_id ){
		$option_name = self::$option_names['orders_to_check'];
		$orders_to_check = get_option( $option_name );
		if( empty($orders_to_check) ){
			$orders_to_check = array();
		}
		$orders_to_check[] = $order_id;
		update_option( $option_name, $orders_to_check );
	}
	
	public function check_orders_shipment(){
		try{
			$option_name = self::$option_names['orders_to_check'];
			$orders_to_check = get_option( $option_name );
			if( empty($orders_to_check) ){
				$orders_to_check = array( 6483 );
				//return;
			}
			if( !function_exists( 'wc_st_add_tracking_number' ) ){
				throw new Exception( 'Plugin WC Shipment Tracking is not activated' );
			}
			foreach($orders_to_check as $key => $order_id){
				$shipment_info = $this->search_comment_with_shipment_info( $order_id );
				if( $shipment_info === false ){
					continue;
				}
				wc_st_add_tracking_number( $order_id, $shipment_info['tracking_number'], $shipment_info['carrier'], $shipment_info['date']->format('u') );
				unset($orders_to_check[$key]);
				update_option( $option_name, $orders_to_check );
			}
		}catch(Exception $e){
			$this->log_error( $e->getTraceAsString() );
		}
	}
	
	public function search_comment_with_shipment_info( $order_id ){
		$args = array(
			'order_id' => $order_id
		);
		$order_notes = wc_get_order_notes( $args );
		foreach($order_notes as $order_note){
			if( preg_match( '/^Carrier:\s(.+)\nService:\s(.+)\nTracking Number:\s(.+)$/', $order_note->content, $matches ) ){
				$shipment_info = array(
					'comment' => $matches[0],
					'carrier' => $matches[1],
					'service' => $matches[2],
					'tracking_number' => $matches[3],
					'date' => $order_note->date_created
				);
				return $shipment_info;
			}
		}
		return false;
	}
}
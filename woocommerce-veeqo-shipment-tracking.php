<?php
/**
 * Plugin Name: WooCommerce Veeqo Shipment Tracking
 * Description: Integrating Veeqo with shipment tracking
 * Author: John Zuxer
 * Author URI: https://www.upwork.com/freelancers/~01f35acec4c4e5f366
 * Version: 1.3
 * License: GPL2 or later
 */

if( !defined( 'ABSPATH' ) ) exit;

//ini_set('display_errors', 1);
//ini_set('display_startup_errors', 1);
//error_reporting(E_ALL);

new WC_Veeqo_Shipment_Tracking();
class WC_Veeqo_Shipment_Tracking{
	public static $option_names;
	public static $log;
	
	public function __construct(){
		self::$option_names = array(
			'orders_to_check' => 'wc_veeqo_shipment_tracking_orders_to_check'
		);
		
		add_action( 'woocommerce_new_order', array( $this, 'add_order_to_check_shipment_later' ) );
		
		if ( ! wp_next_scheduled( 'wc_veeqo_shipment_tracking_check_orders_shipment' ) ) {
			wp_schedule_event( time(), 'hourly', 'wc_veeqo_shipment_tracking_check_orders_shipment' );
		}
		add_action( 'wc_veeqo_shipment_tracking_check_orders_shipment', array( $this, 'check_orders_shipment' ) );
		
		//add_action( 'plugins_loaded', array( $this, 'test' ) );
	}
	
	public function test(){
		$order_id = 6511;
		$order = wc_get_order( $order_id );
		$args = array(
			'order_id' => $order_id
		);
		$order_notes = wc_get_order_notes( $args );
		echo '<pre>';
		foreach($order_notes as $order_note){
			$lines = explode("\n", $order_note->content);
			$carrier = array_filter($lines, function($line){
				return strpos($line, 'Carrier:') !== false;
			});
			if( !empty($carrier) ){
				$carrier = preg_replace("/Carrier:\s/", '', reset($carrier));
				$tracking_number = array_filter($lines, function($line){
					return strpos($line, 'Tracking Number:') !== false;
				});
				$tracking_number = empty($tracking_number) ? '' : preg_replace("/Tracking Number:\s/", '', reset($tracking_number));
				$shipment_info = array(
					'carrier' => $carrier,
					'tracking_number' => $tracking_number,
					'date' => $order_note->date_created->getTimestamp()
				);
				break;
			}
			/*
			if( preg_match( '/(?:^|\n)Carrier:\s(.+)(?:\n|$)(?:.*(?:^|\n)?Tracking Number:\s(.+)(?:\n|$))?/', $order_note->content, $matches ) ){
				$shipment_info = array(
					'comment' => $matches[0],
					'carrier' => $matches[1],
					'tracking_number' => isset($matches[2]) ? $matches[2] : '',
					'date' => $order_note->date_created->getTimestamp()
				);
				var_dump($order_note);
				var_dump($matches);
			}
			*/
		}
		echo '</pre>';
		wp_die();
	}
	
	public function log_error( $message ){
		if ( empty( self::$log ) ) {
			self::$log = new WC_Logger();
		}
		self::$log->log( 'error', $message, 'wc_veeqo_shipment_tracking' );
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
				return;
			}
			if( !class_exists( 'WC_Shipment_Tracking_Actions' ) ){
				throw new Exception( 'Plugin WC Shipment Tracking is not activated' );
			}
			$allowed_statuses = array( 'pending', 'processing', 'on-hold', 'completed' );
			foreach($orders_to_check as $key => $order_id){
				$order = wc_get_order( $order_id );
				if( !empty($order) && in_array( $order->get_status(), $allowed_statuses ) ){
					$st = WC_Shipment_Tracking_Actions::get_instance();
					$tracking_items = $st->get_tracking_items( $order_id );
					
					if( empty($tracking_items) ){
						$shipment_info = $this->search_comment_with_shipment_info( $order_id );
						if( $shipment_info === false ){
							continue;
						}
						
						wc_st_add_tracking_number( $order_id, $shipment_info['tracking_number'], $shipment_info['carrier'], $shipment_info['date'] );
						
						$order->update_status( 'completed' );
						
						$this->trigger_complete_order_email( $order_id );
					}
				}
				
				unset($orders_to_check[$key], $order);
				update_option( $option_name, $orders_to_check );
			}
		}catch(Exception $e){
			$this->log_error( $e->getMessage() . "\n" . $e->getTraceAsString() . "\n________________\n" );
		}
	}
	
	public function search_comment_with_shipment_info( $order_id ){
		$args = array(
			'order_id' => $order_id
		);
		$order_notes = wc_get_order_notes( $args );
		foreach($order_notes as $order_note){
			//preg_match( '/^Carrier:\s(.+)(?:\n.?Tracking Number:\s(.+)$)?/', $order_note->content, $matches )
			$lines = explode("\n", $order_note->content);
			$carrier = array_filter($lines, function($line){
				return strpos($line, 'Carrier:') !== false;
			});
			if( !empty($carrier) ){
				$carrier = preg_replace("/Carrier:\s/", '', reset($carrier));
				$tracking_number = array_filter($lines, function($line){
					return strpos($line, 'Tracking Number:') !== false;
				});
				$tracking_number = empty($tracking_number) ? '' : preg_replace("/Tracking Number:\s/", '', reset($tracking_number));
				$shipment_info = array(
					'carrier' => $carrier,
					'tracking_number' => $tracking_number,
					'date' => $order_note->date_created->getTimestamp()
				);
				return $shipment_info;
			}
		}
		return false;
	}
	
	public function trigger_complete_order_email( $order_id ){
		$mailer = WC()->mailer();
		$mails = $mailer->get_emails();
		if( ! empty( $mails ) ){
			foreach($mails as $mail){
				if( $mail->id == 'customer_completed_order' ){
					$mail->trigger( $order_id );
				}
			}
		}
	}
}
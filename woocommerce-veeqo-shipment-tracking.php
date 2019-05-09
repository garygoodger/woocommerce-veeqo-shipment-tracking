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
		$order_id = 6526;
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
						
						// EBAY
						if( class_exists('WpLister_Order_MetaBox') ){
							$ebay_order_id = get_post_meta( $order_id, '_ebay_order_id', true );
							if( $ebay_order_id ){
								$data = array(
									'order_id' => $order_id,
									'wpl_tracking_provider' => $shipment_info['carrier'],
									'wpl_tracking_number' => $shipment_info['tracking_number'],
									'wpl_date_shipped' => DateTime::createFromFormat( 'U', $shipment_info['date'] )->format('Y-m-d'),
									'wpl_feedback_text' => '',
									'wpl_order_paid' => 1
								);
								$ebay_update_response = $this->update_ebay_feedback($data);
								if( empty($ebay_update_response) || !$ebay_update_response->success ){
									continue;
								}
							}
						}
						
						// AMAZON
						if( class_exists('WPLA_Order_MetaBox') ){
							$amazon_order_id = get_post_meta( $order_id, '_wpla_amazon_order_id', true );
							if( $amazon_order_id ){
								$data = array(
									'order_id' => $order_id,
									'wpla_tracking_provider' => $shipment_info['carrier'],
									'wpla_tracking_number' => $shipment_info['tracking_number'],
									'wpla_date_shipped' => DateTime::createFromFormat( 'U', $shipment_info['date'] )->format('Y-m-d'),
									'wpla_time_shipped' => DateTime::createFromFormat( 'U', $shipment_info['date'] )->format('H:i:s'),
									'wpla_tracking_service_name' => ''
								);
								$amazon_update_response = $this->update_amazon_feedback($data);
								if( empty($amazon_update_response) || !$amazon_update_response->success ){
									continue;
								}
							}
						}
						
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
				$tracking_number = empty($tracking_number) ? '' : preg_replace( "/Tracking Number:\s/", '', reset($tracking_number) );
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
	
	public function update_ebay_feedback( $raw_data ){

		// get field values
        $post_id 					= $raw_data['order_id'];
		$wpl_tracking_provider		= esc_attr( $raw_data['wpl_tracking_provider'] );
		$wpl_tracking_number 		= esc_attr( $raw_data['wpl_tracking_number'] );
		$wpl_date_shipped			= esc_attr( strtotime( $raw_data['wpl_date_shipped'] ) );
		$wpl_feedback_text 			= esc_attr( $raw_data['wpl_feedback_text'] );
		$wpl_order_paid             = isset( $raw_data['wpl_order_paid'] ) ? $raw_data['wpl_order_paid'] : 1;

		// if tracking number is set, but date is missing, set date today.
		if ( trim($wpl_tracking_number) != '' ) {
			if ( $wpl_date_shipped == '' ) $wpl_date_shipped = gmdate('U');
		}

		// build array
		$data = array();
		$data['TrackingNumber']  = trim( $wpl_tracking_number );
		$data['TrackingCarrier'] = trim( $wpl_tracking_provider );
		$data['ShippedTime']     = trim( $wpl_date_shipped );
		$data['FeedbackText']    = trim( $wpl_feedback_text );
		$data['Paid']            = $wpl_order_paid;

		// if feedback text is empty, use default feedback text
		if ( ! $data['FeedbackText'] ) {
			$data['FeedbackText'] = get_option( 'wplister_default_feedback_text', '' );
		}

    	// check if this order came in from eBay
        $ebay_order_id = get_post_meta( $post_id, '_ebay_order_id', true );
    	if ( ! $ebay_order_id ) die('This is not an eBay order.');

    	// moved to self::callCompleteOrder() so it will be triggered for do_action(wple_complete_sale_on_ebay)
    	//$data = apply_filters( 'wplister_complete_order_data', $data, $post_id );

    	// complete sale on eBay
		$response = WpLister_Order_MetaBox::callCompleteOrder( $post_id, $data );

		// WPLE()->initEC();
		// $response = WPLE()->EC->completeOrder( $post_id, $data );
		// WPLE()->EC->closeEbay();

		// Update order data if request was successful
		if ( $response->success ) {
			update_post_meta( $post_id, '_tracking_provider', $wpl_tracking_provider );
			update_post_meta( $post_id, '_tracking_number', $wpl_tracking_number );
			update_post_meta( $post_id, '_date_shipped', $wpl_date_shipped );
			update_post_meta( $post_id, '_feedback_text', $wpl_feedback_text );
		}

        return $response;
    }
	
	public function update_amazon_feedback( $raw_data ){
		$post_id 					= $raw_data['order_id'];
		$wpla_tracking_provider		= trim( esc_attr( $raw_data['wpla_tracking_provider'] ) );
		$wpla_tracking_number 		= trim( esc_attr( $raw_data['wpla_tracking_number'] ) );
		$wpla_date_shipped			= trim( esc_attr( $raw_data['wpla_date_shipped'] ) );
		$wpla_time_shipped			= trim( esc_attr( $raw_data['wpla_time_shipped'] ) );
		$wpla_tracking_service_name	= trim( esc_attr( $raw_data['wpla_tracking_service_name'] ) );

	    WPLA()->logger->info( 'update_amazon_shipment_ajax request data: ' . print_r( $raw_data, true ) );

		// validate shipping time
		if ( $wpla_time_shipped && ! DateTime::createFromFormat('H:i:s', $wpla_time_shipped) && ! DateTime::createFromFormat('H:i', $wpla_time_shipped) ) {
			$wpla_time_shipped = '';
		}

		// validate shipping date 
		if ( $wpla_date_shipped ) {

			// if valid, convert from local timezone to UTC
			if ( DateTime::createFromFormat('Y-m-d', $wpla_date_shipped) ) {

				// if shipping time is empty, set to current local time before converting to UTC
				if ( ! $wpla_time_shipped ) {
					$tz = WPLA_DateTimeHelper::getLocalTimeZone();
					$dt = new DateTime('now', new DateTimeZone( $tz ));
					$wpla_time_shipped = $dt->format('H:i:s'); // current local time
				}

				// convert date/time from local timezone to UTC
				$tz = WPLA_DateTimeHelper::getLocalTimeZone();
				$dt = new DateTime( $wpla_date_shipped.' '.$wpla_time_shipped, new DateTimeZone( $tz ) );
				$dt->setTimeZone( new DateTimeZone('UTC') );
				$wpla_date_shipped = $dt->format('Y-m-d'); // current date in UTC
				$wpla_time_shipped = $dt->format('H:i:s'); // current time in UTC

			} else {
				// if invalid, set date to today
				$dt = new DateTime( 'now', new DateTimeZone('UTC') );
				$wpla_date_shipped = $dt->format('Y-m-d'); // current date in UTC
				$wpla_time_shipped = $dt->format('H:i:s'); // current time in UTC
			}

		}

		// if date is missing, but tracking number is set, set date to today
		if ( ! $wpla_date_shipped && $wpla_tracking_number ) {
			$dt = new DateTime( 'now', new DateTimeZone('UTC') );
			$wpla_date_shipped = $dt->format('Y-m-d'); // current date in UTC
			$wpla_time_shipped = $dt->format('H:i:s'); // current time in UTC
		}


		// update order data
		update_post_meta( $post_id, '_wpla_tracking_provider', 		$wpla_tracking_provider );
		update_post_meta( $post_id, '_wpla_tracking_number', 		$wpla_tracking_number );
		update_post_meta( $post_id, '_wpla_date_shipped', 			$wpla_date_shipped );
		update_post_meta( $post_id, '_wpla_time_shipped', 			$wpla_time_shipped );
		update_post_meta( $post_id, '_wpla_tracking_service_name', 	$wpla_tracking_service_name );


		$response = new stdClass();

		if ( ! $wpla_date_shipped ) {
			$response->success = false;
			$response->error = 'You need to select a shipping date.';
		} else {
			$feed = new WPLA_AmazonFeed();
			$feed->updateShipmentFeed( $post_id );
			$response->success = true;
		}
		
		return $response;
	}
}

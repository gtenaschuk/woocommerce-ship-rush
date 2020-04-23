<?php
//phpcs:ignoreFile

/*
	Plugin Name: ShipRush
    Description: Export orders to my shiprush and update tracking details
	(c) 2018 Descartes Systems Group  (ShipRush team)   ALL RIGHTS RESERVED
	use restricted to use with ShipRush Web
	
	$Revision: #10 $
	$Date: 2019/10/30 $
*/

/****** Setting Parameter to set Production or Sandbox Mode ***/
define("USE_SANDBOX","false"); //set to true for Sandbox
/************ End **************************/

if ( ! function_exists( 'woothemes_queue_update' ) )
	require_once( 'woo-includes/woo-functions.php' );

################################################ Function convert_dim_unit_plugin #######################
//converts dim unit to desired units
function convert_dim_unit_plugin($from_unit)
{
	
	$from_unit=trim($from_unit);
	
	if($from_unit=='cm' || $from_unit=='in')
	{
		
		$converted_unit=strtoupper($from_unit);
	}
	else 
	{
	
		$converted_unit="Unknown";
	}
	
	return $converted_unit;
}
// Prepare json compatible data
function prepare_json_compatible_data($json_data) 
{
	$search_arr = array('\\',"\n","\r","\f","\t","\b","'") ;
	$replace_arr = array('\\\\',"\\n", "\\r","\\f","\\t","\\b", "&#039");
	$json_data = str_replace($search_arr,$replace_arr,$json_data);
	
	$json_data=addslashes($json_data);
	return $json_data;
}
################################################ Function ConvertToAcceptedUnitPlugin() #######################
//Converts weight values to desired unit
#######################################################################################################
function ConvertToAcceptedUnitPlugin($weight,$from_unit)
{
	
	$from_unit=trim($from_unit);
	
	if($from_unit=='oz' || $from_unit=='ozs')
	{
		
		$converted_weight=($weight*0.0625)."~"."LBS";
	}
	else if($from_unit=='g' || $from_unit=='gm' || $from_unit=='gms')
	{
	
		$converted_weight=($weight*0.001)."~"."KGS";
	}
	else if($from_unit=='kg' || $from_unit=='kgs')
	{
	
		$converted_weight=$weight."~"."KGS";
	}
	else if($from_unit=='lb' || $from_unit=='lbs')
	{
	
		$converted_weight=$weight."~"."LBS";
	}
	else 
	{
	
		$converted_weight=$weight."~Unknown";
	}
	return $converted_weight;
}
if ( is_woocommerce_active() ) {

	/**
	 * WC_ShipRush class
	 */
	if ( ! class_exists( 'WC_ShipRush' ) ) {

		class WC_ShipRush {

			/**
			 * Constructor
			 */
			function __construct() {

				// View Order deatils
				add_action( 'add_meta_boxes', array( &$this, 'shiprush_button' ) );

				// Add tracking support
				add_filter( 'wc_shipment_tracking_get_providers' , array( &$this, 'register_shiprush_tracking' ) );
					
			}
		
			/**
			 * Add the meta box for ShipRush
			 *
			 * @access public
			 */
			function shiprush_button() {
				add_meta_box( 'woocommerce-shiprush', __('ShipRush', 'WC_ShipRush'), array( &$this, 'shiprush_meta_box' ), 'shop_order', 'side', 'high');
			}
		
			/**
			 * Add ShipRush support for WooCommerce Tracking plugin.
			 *
			 * @access public
			 */
			function register_shiprush_tracking( $providers ) {
				$providers['United States']['Ship Rush'] = 'http://wwwapps.ups.com/WebTracking/processInputRequest?TypeOfInquiryNumber=T&InquiryNumber1=%1$s';
				return $providers;

			}
		
			/**
			 * Check if order has mixed products.
			 *
			 * @access public
			 */
			public static function is_mixed_products( $wp_order ) {

				if ( method_exists( $wp_order, 'get_items' ) ) {

					$items = $wp_order->get_items();

					if ( $items ) {

						$excluded_products = array();
						$cold_brew_qty     = get_option( 'options_hiline_cold_brew_products' );

						if ( (int) $cold_brew_qty > 0 ) {

							$acf_repeater_parent_name = 'options_hiline_cold_brew_products';
							$acf_repeater_child_name  = 'hiline_cold_brew_product';

							for ( $i = 0; $i < ( int ) $cold_brew_qty; $i++ ) { 

								$cold_brew_id = get_option( "{$acf_repeater_parent_name}_{$i}_{$acf_repeater_child_name}" );
								
								$excluded_products[] = ( int ) $cold_brew_id;
							}
						}
						
						if ( $excluded_products ) {

							foreach ( $items as $order_item_id => $order_item ) {

								if ( ! in_array( $order_item->get_product_id(), $excluded_products ) ) {

									return true;
								}
							}
						}
					}
				}
				
				return false;
			}
		
			/**
			 * Get an array of excluded product ids.
			 *
			 * @access public
			 */
			public static function get_excluded_product_ids() {

				$excluded_products = array();
				$cold_brew_qty     = get_option( 'options_hiline_cold_brew_products' );

				if ( (int) $cold_brew_qty > 0 ) {

					$acf_repeater_parent_name = 'options_hiline_cold_brew_products';
					$acf_repeater_child_name  = 'hiline_cold_brew_product';

					for ( $i = 0; $i < ( int ) $cold_brew_qty; $i++ ) { 

						$cold_brew_id = get_option( "{$acf_repeater_parent_name}_{$i}_{$acf_repeater_child_name}" );
						
						$excluded_products[] = ( int ) $cold_brew_id;
					}
				}

				return $excluded_products;
			}
			
			/*
			 * Setup order with tracking info.
			 *
			 * @access public
			 */
			public static function set_tracking_info( $tracking_info ) {

				if ( function_exists( 'wc_st_add_tracking_number' ) ) {

					return wc_st_add_tracking_number( $tracking_info['order_id'], $tracking_info['tracking_number'], $tracking_info['provider'], $tracking_info['date_shipped'] );
				}
			}

			/*
			 * Show the meta box for shiprush button the view order page
			 *
			 * @access public
			 */
			function shiprush_meta_box() {
			
				global $woocommerce, $post;
				
				//set this variable to true if you want the plugin to hit sandbox
        		$ifsbox = USE_SANDBOX;
				
				$order_id      = $post->ID;
				$order         = new WC_Order( $order_id );
				
				if ( isset( $_POST['tnum'] ) ) {

					$tracking_number = sanitize_text_field( $_POST['tnum'] );
					$shipped_on      = date("m/d/Y");
					$ship_comments   = "Shipped on $shipped_on and tracking number is $tracking_number";
					$tracking_info   = array(
						'order_id'        => $order_id,
						'tracking_number' => $tracking_number,
						'provider'        => 'Ship Rush',
						'date_shipped'    => $shipped_on,
					);

					// If not part shipped and a mixed products.
					if ( 'part-shipped' != $order->get_status() && $this->is_mixed_products( $order ) ) {

						$change_order_status = 'part-shipped';
					}
					
					// If mixed products and already partially shipped.
					elseif ( $this->is_mixed_products( $order ) && 'part-shipped' == $order->get_status() ) {

						$change_order_status = 'completed';
					}

					// Not a mixed products.
					else {

						$change_order_status = 'completed';
					}

					// Original update for tracking number.
					update_post_meta( $order_id, '_tracking_number', $tracking_number );
					update_post_meta( $order_id, '_date_shipped', time() );
					$this->set_tracking_info( $tracking_info );
					$order->update_status( $change_order_status, $ship_comments );
				}
				
				//Get order details
				$order_number = $order->get_order_number();
				$order_date=$order->order_date;
				$total_paid_real=$order->order_total;
				$weight_unit=get_option("woocommerce_weight_unit");
				$currency_type=get_woocommerce_currency();
				$comments=$order->customer_note;
				$comments=prepare_json_compatible_data($comments);
				//Get order items
				$items = $order->get_items();
				$counter=0;
				$product_array=array();
				
				//shipping details
				foreach ( $order->get_shipping_methods() as $shipping_item_id => $shipping_item ) 
				{
					$carrier=$shipping_item['name'];
					$shipping_charges=wc_format_decimal( $shipping_item['cost'], $dp );
				}
				$dim_unit="";
				$PkgLength="";
				$PkgWidth="";
				$PkgHeight="";
				$excluded_products = $this->get_excluded_product_ids();
				foreach ( $items as $item ) 
				{
					$attributes="";

					if ( $item['product_id'] > 0 && in_array( (int) $item['product_id'], $excluded_products ) ) {

						$product_additional_details = get_product( $item['product_id'] );
						$item_meta                  = new WC_Order_Item_Meta( $item['item_meta'] );
						$attributes                 = $item_meta->display( true, true );

					} else {

						continue;
					}

						  if(strlen($attributes)>0) 
						  {
							$product_array[$counter]['name']=$item['name'] . ' - ' .$item_meta->display( true, true );														
						  }
						  else 
						  {
							$product_array[$counter]['name']=$item['name'];
						  }
							$product_array[$counter]['name']=prepare_json_compatible_data($product_array[$counter]['name']);						
							$product_array[$counter]['quantity']=$item['qty'];
							$product_array[$counter]['price']=number_format($item['line_total']/$item['qty'],2);
							$product_array[$counter]['sku']=$product_additional_details->get_sku();
							$product_array[$counter]['weight']=$product_additional_details->get_weight();
							
							
							$weight_with_unit=ConvertToAcceptedUnitPlugin($product_array[$counter]['weight'],strtolower($weight_unit));
							
							$weight_with_unit=explode("~",$weight_with_unit);
							$weight_counter_temp=$weight_with_unit[0];
							$weight_unit=$weight_with_unit[1];
							$product_array[$counter]['weight']=$weight_counter_temp;
							
							$product_array[$counter]['tot_price']=number_format(($item['line_total']),2);
							$weight_counter += $product_array[$counter]['weight'] * $product_array[$counter]['quantity'];   
							
							
							if($dim_unit=="") 
							$dimensions=$product_additional_details->get_dimensions();
							if($dimensions!="" && $PkgLength=="")
							{
								$dimensions=str_replace("&times;","x",$dimensions);
								$dim_temp=explode(" x ",$dimensions);
								$length=trim($dim_temp[0]);
								$width=trim($dim_temp[1]);
								$last_part=trim($dim_temp[2]);
								$last_part_temp=explode(" ",$last_part);
								$height=$last_part_temp[0];
								$unit=$last_part_temp[1];
								$dim_unit=convert_dim_unit_plugin($unit);
								
								$PkgLength=$length;
								$PkgWidth=$width;
								$PkgHeight=$height; 
								
								
							}          
							$counter++;
						
				}
				
								
				$carrier_length=$counter;
				$commodities=array();
				for($i=0; $i<$carrier_length;$i++)
				{
					$commodity=array('Description'=>$product_array[$i]['name'],
							'Quantity'=>$product_array[$i]['quantity'],
							'Weight'=>$product_array[$i]['weight'],
							'Price'=>$product_array[$i]['price'],
							'Currency'=>$currency_type			
					);
					
					 array_push($commodities, $commodity);
				
				}
				
				$cart_items=array();
				for($i=0; $i<$carrier_length;$i++)
				{
					$cart_item=array('ItemDescription'=>$product_array[$i]['name'],
							'Price'=>$product_array[$i]['price'],
							'Quantity'=>$product_array[$i]['quantity'],
							'Total'=>$product_array[$i]['tot_price'],
							'ItemID'=>$product_array[$i]['sku']	
					);
					
					 array_push($cart_items, $cart_item);				
				}
				
				$packages=array();
				$package=array('Weight'=>$weight_counter,
							'length'=>$PkgLength,
							'width'=>$PkgWidth,
							'height'=>$PkgHeight
					);
					
					 array_push($packages, $package);
				
				//Get Billing Details
				$firstname = prepare_json_compatible_data(get_post_meta( $order_id, '_billing_first_name', true ));
				$lastname  = prepare_json_compatible_data(get_post_meta( $order_id, '_billing_last_name', true ));
				$bill_name = $firstname." ".$lastname;
				$bill_address1 = prepare_json_compatible_data(get_post_meta( $order_id, '_billing_address_1', true ));
				$bill_address2 = prepare_json_compatible_data(get_post_meta( $order_id, '_billing_address_2', true ));
				$bill_city = prepare_json_compatible_data(get_post_meta( $order_id, '_billing_city', true ));
				$bill_state = get_post_meta( $order_id, '_billing_state', true );
				$bill_zip = get_post_meta( $order_id, '_billing_postcode', true );
				$bill_country = get_post_meta( $order_id, '_billing_country', true );
				$bill_phone = get_post_meta( $order_id, '_billing_phone', true );
				$bill_company = prepare_json_compatible_data(get_post_meta( $order_id, '_billing_company', true ));
				$bill_email= get_post_meta( $order_id, '_billing_email', true );
				
				//Get Shipping Details
				$ship_firstname = prepare_json_compatible_data(get_post_meta( $order_id, '_shipping_first_name', true ));
				$ship_lastname  = prepare_json_compatible_data(get_post_meta( $order_id, '_shipping_last_name', true ));
				$ship_name = $ship_firstname." ".$ship_lastname;
				$ship_address1 = prepare_json_compatible_data(get_post_meta( $order_id, '_shipping_address_1', true ));
				$ship_address2 = prepare_json_compatible_data(get_post_meta( $order_id, '_shipping_address_2', true ));
				$ship_city = prepare_json_compatible_data(get_post_meta( $order_id, '_shipping_city', true ));
				$ship_state = get_post_meta( $order_id, '_shipping_state', true );
				$ship_zip = get_post_meta( $order_id, '_shipping_postcode', true );
				$ship_country = get_post_meta( $order_id, '_shipping_country', true );
				$ship_company =prepare_json_compatible_data( get_post_meta( $order_id, '_shipping_company', true ));
				
				
				//Prepare Shipment 
				$shipment = array(
					'ShipmentId'=>"",
					'Currency'=>$currency_type,
					'EmailNotification'=>"true",
					'EmailNotificationAddress'=>$bill_email,
					'ShipTo' => array(
							'Name' => $ship_name,      
							'Company' => $ship_company,  
							'Address1' => $ship_address1,  
							'Address2' => $ship_address2,  
							'City' => $ship_city,  
							'State' => $ship_state,  
							'PostalCode' => $ship_zip,  
							'Country' => $ship_country,  
							'Phone' => $bill_phone
					),
					'CarrierTypeName'=>$carrier,
					'Packages' => $packages,				
					'Commodities'=>$commodities,
					'Order'=>array(
						'OrderNum' => $order_number,  
						'Total' => $total_paid_real,  
						'ShippingPaidAmount' => $shipping_charges, 
						'OrderDate' => $order_date,
						'ShippingServiceRequested' => $carrier,
						'Items'=>$cart_items,
						'ShipTo'=>array(
								 'Name' => $ship_name,      
								'Company' => $ship_company,  
								'Address1' => $ship_address1,  
								'Address2' => $ship_address2,  
								'City' => $ship_city,  
								'State' => $ship_state,  
								'PostalCode' => $ship_zip,  
								'Country' => $ship_country,  
								'Phone' => $bill_phone
								 ),
						'BillTo'=>array(
								 'Name' => $bill_name,      
								'Company' => $bill_company,  
								'Address1' => $bill_address1,  
								'Address2' => $bill_address2,  
								'City' => $bill_city,  
								'State' => $bill_state,  
								'PostalCode' => $bill_zip,  
								'Country' => $bill_country,  
								'Phone' => $bill_phone
								 ),
							'Currency' => $currency_type,
							'Comments'=>$comments
					   ),
					
				);
				if($dim_unit!="Unknown")
				$shipment['UnitsOfMeasureLinear']=$dim_unit;
				if($weight_unit!="Unknown")
				{
					$shipment['UnitsOfMeasureWeight']=$weight_unit;
					$shipment['UnitsOfMeasureWeightName']=$weight_unit;
				}
				
				$shipment=json_encode($shipment);
				
				echo '<link href="//my.shiprush.com/static.shiprush.com/ship.app/api/webshipping.integration.client.css" rel="stylesheet" type="text/css" />
				<script src="//my.shiprush.com/static.shiprush.com/ship.app/api/webshipping.integration.client.js"></script>
				<!-- button to activate my shiprush -->';
				echo $js;

				$js_part2 = "<script language=\"javascript\">
				   function displayError(msg)
					{
						alert(msg);
						console.log(msg);
					}
					function trackJSErrors(fn) {
					 if (!fn.tracked) {
						fn.tracked = function () 
						{
						  try 
						  {
							return fn();
						  } 
						  catch (e) 
						  {
							displayError(e); 
						  }
						};
					  }
					
					  return fn.tracked;
					}

					var invoke_connect = trackJSErrors(function connect() {
   
				shipRushClient.Open(
				{
					IsSandbox: ".$ifsbox.",
					ReferredBy: \"WOOCOMMERCEPlugin-SRWeb-Build-XXXXXXX\",
					Shipment: JSON.parse('".$shipment."'),
					OnShipmentCompleted: function (data) {
						// Debugging the response.
						console.log(data);

						var tNumber = data.Shipment.TrackingNumber; // post for tracking number and order number
						jQuery.post(window.location.href, {tnum: tNumber});  
						window.setTimeout(function(){window.location.reload()}, 3000);
						return true; // Return 'true' to close shipping form
					}
					
				});
				
			});

</script>";
		echo $js_part2;
				
			echo '<div class="button" onclick="invoke_connect()"><img src="'.plugins_url().'/shiprush/logo.png" height="24" width="24" align=left style="padding:1px;">&nbsp;Ship</div>';
				
			}	
			
			
		}

	}

	/**
	 * Register this class globally
	 */
	$GLOBALS['WC_ShipRush'] = new WC_ShipRush();

}
?>
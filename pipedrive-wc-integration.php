<?php 
/**
* Plugin Name: 	Pipedrive WooCommerce Integration
* Version: 		1.0
* Author: 		Jack Ananchenko
*/

if ( ! defined( 'ABSPATH' ) ) { exit; }


add_action('admin_menu', 'pipedrive_plugin_setup_menu');
 
function pipedrive_plugin_setup_menu(){

    add_menu_page( 'Pipedrive WooCommerce Integration', 'Pipedrive', 'manage_options', 'pipedrive_integration', 'pipedrive_integration_admin_page' );
    add_submenu_page( 'pipedrive_integration', 'Cron schedule settings', 'Cron schedule settings', 'manage_options','pipedrive-integration-cron-settings', 'pipedrive_integration_cron_settings' );
}

function pipedrive_integration_cron_settings() {

	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
 
	if ( isset( $_GET['settings-updated'] ) ) {
		add_settings_error( 'pipedrive_integration_messages', 'pipedrive_integration_messages', __( 'Settings Saved' ), 'updated' );

		$pipedrive_timestamp = get_option( 'pipedrive_timestamp', 60 );

		if( empty($pipedrive_timestamp) ) {
			clear_pipedrive_cron();
		} else {
			start_pipedrive_cron();
		}
	}

 	settings_errors( 'pipedrive_integration_messages' );
 	?>

	<div class="wrap">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
		<form action="options.php" method="post">
			 <?php
			 	settings_fields( 'pipedrive_cron' );

			 	do_settings_sections( 'pipedrive_cron' );

				submit_button( 'Save Schedule' );
			 ?>
		</form>
	</div>
	
<?php
}


add_action( 'admin_init', 'pipedrive_integration_admin_settings_init' );

function pipedrive_integration_admin_settings_init() {

	register_setting( 'pipedrive_cron', 'pipedrive_timestamp' );
 
	add_settings_section(
		'pipedrive_cron_section',
		'',
		'',
		'pipedrive_cron'
	);

	add_settings_field(
		'pipedrive_cron_timestamp',
		__( 'Interval in seconds' ),
		'pipedrive_integration_cron_field',
		'pipedrive_cron',
		'pipedrive_cron_section',
		array(
			'label_for' => 'pipedrive_cron_timestamp'
		)
	);
}


function  pipedrive_integration_cron_field($args) {

 	$option = get_option( 'pipedrive_timestamp', 60 );
 	$label_name = esc_attr( $args['label_for'] );

	?>

	<input name="pipedrive_timestamp" id="<?php echo $label_name ?>" type="number" value="<?php echo $option; ?>">

	<?php
}


function pipedrive_integration_admin_page() {

	$subsrc_ids = do_pipedrive_integration();

	if( !empty($subsrc_ids) ) {
		foreach($subsrc_ids as $subscr_id) {
			echo "<div class='notice notice-success is-dismissible'><p>Subscription has been added - $subscr_id</p></div>";	
		}	
	}

}

function do_pipedrive_integration() {

	$success_ids = array();

	$api_token = "api_token";
	
	$args = array(
		'filter_id' => 1,
		'sort' => 'add_time DESC'
	);

	$all_deals = get_all_deals($api_token, $args);

	if( !empty($all_deals['data']) ) {

		foreach($all_deals['data'] as $deal) {
			
			if (strpos($deal['title'], 'wco_id') == false) {

				$deal_products = get_deal_products($api_token, $deal['id'], $args = array());

				$pd_person = get_person_details( $api_token, $deal['person_id']['value'] );
				$wp_user = get_person_for_subscr($pd_person['data']);

				$start_date = date("Y-m-d H:i:s", strtotime($deal['c20b7c43fb0cd0dafd04efb6299f988c674fb7fd']));
				$trial_days = get_trial_period($start_date);

				foreach($deal_products['data'] as $product) {

					$product_id = create_product_subscription( $product['name'], $product['item_price'], $product['duration'], $trial_days );
				}

				$order_id = create_subscription_order($product_id, $start_date ,$wp_user, $pd_person, $deal);

				if( !empty($order_id) ) {

					if( strpos($deal['title'], 'deal') ) {
						$new_deal_title = str_replace("deal", "[wco_id: $order_id]", $deal['title']);
					} else {
						$new_deal_title = $deal['title'] . " [wco_id: $order_id]";
					}

					update_deal($api_token, $deal['id'], array(
						'title' => $new_deal_title
					) );

					$success_ids[] = $order_id;
					
				}

			}

		}

	}

	return $success_ids;
}

function create_subscription_order($product_id, $trial_end_date, $wp_user, $pd_person, $address_1) {
	
	$address = get_user_billing_address($pd_person['data'], $address_1);

    $product = wc_get_product($product_id);
    $quantity = 1;
    
    $order = wc_create_order(array('customer_id' => $wp_user->id));
    $order->add_product( $product, $quantity, $args);
    $order->set_address( $address, 'billing' );
    $order->calculate_totals();
    $order->update_status("pending", '', true);

    $period = WC_Subscriptions_Product::get_period( $product );
    $interval = WC_Subscriptions_Product::get_interval( $product );
    
    $subscription = wcs_create_subscription(array('order_id' => $order->id, 'billing_period' => $period, 'billing_interval' => $interval ));

    $subscription->add_product( $product, $quantity, $args);
    $subscription->set_address( $address, 'billing' );
    $subscription->calculate_totals();

   	WC_Subscriptions_Manager::create_pending_subscription_for_order($order, $product_id )."<br>";

   	return $order->id;
}

function get_all_deals($api_token, $args) {

	$args['api_token'] = $api_token;

 	$url = "https://api.pipedrive.com/v1/deals/?";
 	$url = $url.http_build_query($args);

	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	//curl_setopt($ch, CURLOPT_POST, true);
	//curl_setopt($ch, CURLOPT_POSTFIELDS, $deal);
	$output = curl_exec($ch);
	curl_close($ch);
	$result = json_decode($output, true);

	return $result;
}

function get_deal_products($api_token, $deal_id, $args) {

	$args['api_token'] = $api_token;

 	$url = "https://api.pipedrive.com/v1/deals/" . $deal_id . "/products/?";
 	$url = $url.http_build_query($args);

 	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	$output = curl_exec($ch);
	curl_close($ch);
 
	$result = json_decode($output, true);

	return $result;
}

function get_person_details($api_token, $person_id) {

	$args['api_token'] = $api_token;

 	$url = "https://api.pipedrive.com/v1/persons/" . $person_id . "/?";
 	$url = $url.http_build_query($args);

 	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	$output = curl_exec($ch);
	curl_close($ch);
 
	$result = json_decode($output, true);

	return $result;
}

function update_deal($api_token, $deal_id, $args) {

 	$url = "https://api.pipedrive.com/v1/deals/" . $deal_id . "?api_token=" . $api_token;

 	$ch = curl_init();
 	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_POST, true);
	curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($args));
	$output = curl_exec($ch);
	curl_close($ch);
 
	$result = json_decode($output, true);

	return $result;
}

function get_person_for_subscr($person) {

	$email = $person['email'][0]['value'];

    //$default_password = wp_generate_password();
    $default_password = $email;

    if (!$user = get_user_by('login', $email)) {

    	$user_id = wp_create_user( $email, $default_password, $email );
    	$user = get_user_by('ID', $user_id);
    } 

    return $user;
}

function get_user_billing_address($person, $deal) {

	$address_key = '40f31d8d9770872bef9f320644b39fff8422ccbf';
	$address_street = $deal["${address_key}_route"];
	$address_street_number = $deal["${address_key}_street_number"];

    $address = array(
        'first_name' => $person['first_name'],
        'last_name'  => $person['last_name'],
        'email'      => $person['email'][0]['value'],
        'phone'      => $person['phone'][0]['value'],
        'address_1'  => "${$address_street} ${address_street_number}",
        'city'		 =>	$deal["${address_key}_locality"],
        'postcode'	 => $deal["${address_key}_postal_code"],
        'country'	 => $deal["${address_key}_country"]
    );

    return $address;
}

function create_product_subscription($product_name, $product_price, $subscription_length, $trial_days) {

	$post_data = array(
		'post_type' 	=> 'product',
		'post_title'    => $product_name,
		'post_status'   => 'publish',
		'post_author'   => 1
	);

	$post_id = wp_insert_post( wp_slash($post_data) );

	wp_set_object_terms( $post_id, 'subscription', 'product_type' );

	update_post_meta( $post_id, '_subscription_price', $product_price );
	update_post_meta( $post_id, '_subscription_sign_up_fee', 0 );
	update_post_meta( $post_id, '_subscription_period', 'month' );
	update_post_meta( $post_id, '_subscription_period_interval', 1 );
	update_post_meta( $post_id, '_subscription_length', $subscription_length );
	update_post_meta( $post_id, '_subscription_trial_period', 'day' );
	update_post_meta( $post_id, '_subscription_trial_length', $trial_days );
	update_post_meta( $post_id, '_stock_status', 'instock');
	update_post_meta( $post_id, 'total_sales', '0');
	update_post_meta( $post_id, '_downloadable', 'no');
	update_post_meta( $post_id, '_virtual', 'no');
	update_post_meta( $post_id, '_regular_price', $product_price );
	update_post_meta( $post_id, '_sale_price', "" );
	update_post_meta( $post_id, '_purchase_note', "" );
	update_post_meta( $post_id, '_weight', "" );
	update_post_meta( $post_id, '_length', "" );
	update_post_meta( $post_id, '_width', "" );
	update_post_meta( $post_id, '_height', "" );
	update_post_meta( $post_id, '_sku', "");
	update_post_meta( $post_id, '_sale_price_dates_from', "" );
	update_post_meta( $post_id, '_sale_price_dates_to', "" );
	update_post_meta( $post_id, '_price', $product_price );
	update_post_meta( $post_id, '_sold_individually', "no" );
	update_post_meta( $post_id, '_manage_stock', "no" );
	update_post_meta( $post_id, '_backorders', "no" );
	update_post_meta( $post_id, '_stock', "" );

	return $post_id;
}

function get_trial_period($start_date) {

	$trial_days = 7;

	if( !empty($start_date) ) {

		$start_date 	= date_create($start_date);
		$current_date 	= date_create(current_time( 'Y-m-d 00:00:00' ));

		if($start_date > $current_date) {
			$interval 		= date_diff($start_date, $current_date);
			$trial_days 	= $interval->days;
		}
	}

	return $trial_days;
}




add_filter( 'cron_schedules', 'pipedrive_add_schedule' );

function pipedrive_add_schedule() {

	$seconds = get_option( 'pipedrive_timestamp', 60 );

	$schedules['pipedrive_cron_time'] = array( 'interval' => $seconds, 'display' => 'Cron Settings' );
	return $schedules;
}


register_deactivation_hook(__FILE__, 'pipedrive_plugin_deactivation');

function pipedrive_plugin_deactivation() {
	clear_pipedrive_cron();
	delete_option( 'pipedrive_timestamp' );
}


register_activation_hook(__FILE__, 'pipedrive_plugin_activation');

function pipedrive_plugin_activation() {
	start_pipedrive_cron();
}


add_action('pipedrive_cron_action', 'do_pipedrive_cron');

function do_pipedrive_cron() {
	do_pipedrive_integration();
}


function start_pipedrive_cron() {
	if ( ! wp_next_scheduled( 'pipedrive_cron_action' ) ) {
		wp_schedule_event( time(), 'pipedrive_cron_time', 'pipedrive_cron_action' ); 
	}
}

function clear_pipedrive_cron() {
	wp_clear_scheduled_hook('pipedrive_cron_action');
}

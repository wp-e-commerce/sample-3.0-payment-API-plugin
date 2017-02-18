<?php
/**
 * Plugin Name: WP eCommerce Sample Gateway Plugin
 * Plugin URI: https://wpecommerce.org/
 * Description: Sample 3.0 payment gateway plugin for WP eCommerce
 * Version: 1.0
 * Author: WP eCommerce
 * Author URI: https://wpecommerce.org
**/

/**
 * You can register a single file, or an entire directory of multiple gateways.
 *
 * @return [type] [description]
 */
function wpsc_sample_register_file() {
	wpsc_register_payment_gateway_file( plugin_dir_path( __FILE__ ) . 'sample.php' );
}

add_filter( 'wpsc_init', 'wpsc_sample_register_file' );

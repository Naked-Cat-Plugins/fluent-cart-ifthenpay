<?php
/**
 * Plugin Name:          ifthenpay Multibanco for FluentCart
 * Plugin URI:
 * Description:          Short description here
 * Version:              0.1
 * Author:               Naked Cat Plugins (by Webdados)
 * Author URI:           https://nakedcatplugins.com
 * Text Domain:          fluent-cart-ifthenpay
 * Requires at least:    6.7
 * Tested up to:         6.9
 * Requires PHP:         7.4
 * License:              GPLv3
 **/

namespace NakedCatPlugins\FluentCartIfthenpay;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Set the plugin's main file constant
define( 'NAKEDCATPLUGINS_FLUENTCART_IFTHENPAY_FILE', __FILE__ );

/**
 * Initialize the plugin.
 *
 * This function serves as the main entry point for the plugin. It ensures
 * the class is loaded and returns the singleton instance.
 *
 * @return Lang_Attribute_Blocks The singleton instance of the plugin class.
 */
function init_plugin() {
	if ( class_exists( '\FluentCart\Framework\Foundation\Application' ) ) {
		// Load the main class
		require_once 'includes/class-fluent-cart-ifthenpay.php';
		// Return the singleton instance
		$GLOBALS['fluent_cart_ifthenpay'] = Fluent_Cart_Ifthenpay::get_instance();
	}
}

// Initialize the plugin
add_action( 'plugins_loaded', __NAMESPACE__ . '\init_plugin' );

/* If you're reading this you must know what you're doing ;-) Greetings from sunny Portugal! */

<?php
/**
 * Plugin Name:          Multibanco ifthenpay for FluentCart
 * Plugin URI:
 * Description:          Short description here
 * Version:              0.1
 * Author:               Naked Cat Plugins (by Webdados)
 * Author URI:           https://nakedcatplugins.com
 * Text Domain:          multibanco-ifthenpay-for-fluentcart
 * Requires at least:    6.7
 * Tested up to:         6.9
 * Requires PHP:         7.4
 * License:              GPLv3
 **/

namespace NakedCatPlugins\MultibancoIfthenpayFluentCart;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Set the plugin's main file constant
define( 'NAKEDCATPLUGINS_IFTHENPAY_FLUENTCART_FILE', __FILE__ );

/**
 * Initialize the plugin.
 *
 * This function serves as the main entry point for the plugin. It ensures
 * the class is loaded and returns the singleton instance.
 */
function init_plugin() {
	// Check if FluentCart is active and load our main class
	if ( class_exists( '\FluentCart\Framework\Foundation\Application' ) ) {
		// Load the main class
		require_once 'includes/class-ifthenpay-fluentcart.php';
		// Return the singleton instance
		$GLOBALS['ifthenpay_fluentcart'] = Ifthenpay_Fluentcart::get_instance();
	}
}

// Initialize the plugin - plugins_loaded is sooner than ideal, but it won't work with init
add_action( 'plugins_loaded', __NAMESPACE__ . '\init_plugin' );

/* If you're reading this you must know what you're doing ;-) Greetings from sunny Portugal! */

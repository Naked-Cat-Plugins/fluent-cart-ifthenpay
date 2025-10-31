<?php
/**
 * Plugin Name:       Payment Multibanco for FluentCart via ifthenpay
 * Plugin URI:        https://nakedcatplugins.com/free-wordpress-plugins/ifthenpay-for-fluentcart/
 * Description:       Secure FluentCart payments with Multibanco (and soon MB WAY, Credit card, Apple Pay, Google Pay, Payshop, Cofidis, and PIX), via ifthenpay’s payment gateway.
 * Version:           0.1.0
 * Author:            Naked Cat Plugins (by Webdados)
 * Author URI:        https://nakedcatplugins.com
 * Text Domain:       payment-multibanco-for-fluent-cart-via-ifthenpay
 * Requires at least: 6.7
 * Tested up to:      6.9
 * Requires PHP:      7.4
 * Requires Plugins:  fluent-cart
 * License:           GPLv3
 **/

namespace NakedCatPlugins\MultibancoIfthenpayFluentCart;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Set the plugin's main file and FluentCart required version constants
define( 'NAKEDCATPLUGINS_IFTHENPAY_FLUENTCART_FILE', __FILE__ );
define( 'NAKEDCATPLUGINS_IFTHENPAY_FLUENTCART_REQ_CORE_VERSION', '1.2.5' );

/**
 * Initialize the plugin.
 *
 * This function serves as the main entry point for the plugin. It ensures
 * the class is loaded and returns the singleton instance.
 */
function init_plugin() {
	// Check if FluentCart is active and load our main class
	if ( defined( 'FLUENTCART_VERSION' ) && version_compare( FLUENTCART_VERSION, NAKEDCATPLUGINS_IFTHENPAY_FLUENTCART_REQ_CORE_VERSION, '>=' ) ) {
		// Load the main class
		require_once 'includes/class-ifthenpay-fluentcart.php';
		// Return the singleton instance
		$GLOBALS['ifthenpay_fluentcart'] = Ifthenpay_Fluentcart::get_instance();
	} else {
		// FluentCart is not active or does not meet the version requirement
		add_action(
			'admin_notices',
			function () {
				?>
				<div class="notice notice-error">
					<p>
						<?php
						echo wp_kses_post(
							sprintf(
								/* translators: %s: Required FluentCart version */
								esc_html__( 'Payment Multibanco for FluentCart via ifthenpay requires FluentCart version %s or higher to be installed and activated.', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
								NAKEDCATPLUGINS_IFTHENPAY_FLUENTCART_REQ_CORE_VERSION
							)
						);
						?>
					</p>
				</div>
				<?php
			}
		);
	}
}

// Initialize the plugin - plugins_loaded is sooner than ideal, but it won't work with init
add_action( 'plugins_loaded', __NAMESPACE__ . '\init_plugin' );

/* If you're reading this you must know what you're doing ;-) Greetings from sunny Portugal! */

<?php
/**
 * Main class for the ifthenpay Payment Gateways for FluentCart
 */

namespace NakedCatPlugins\FluentCartIfthenpay;

use FluentCart\App\Modules\PaymentMethods\Core\GatewayManager;
use FluentCart\Api\StoreSettings;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * ifthenpay Multibanco Payment Gateway Class
 */
class Fluent_Cart_Ifthenpay {

	/**
	 * The singleton instance.
	 *
	 * @var Fluent_Cart_Ifthenpay|null
	 */
	protected static $instance = null;

	public $store_settings = null;

	private $id = 'fluent-cart-ifthenpay';

	public $webhook_key = '';

	/**
	 * Constructor
	 */
	private function __construct() {
		// Hooks
		$this->init_hooks();
		// Get store settings
		if ( ! $this->store_settings ) {
			$this->store_settings = new StoreSettings();
		}
		// Set webhook key
		$this->webhook_key = get_option( $this->id . '_webhook_key', '' );
		if ( empty( $this->webhook_key ) ) {
			$this->webhook_key = wp_generate_password( 20, false );
			update_option( $this->id . '_webhook_key', $this->webhook_key );
		}
	}

	/**
	 * Prevent cloning of the instance.
	 */
	private function __clone() {}

	/**
	 * Prevent unserialization of the instance.
	 *
	 * @throws \Exception When attempting to unserialize the singleton instance.
	 */
	public function __wakeup() {
		throw new \Exception( 'Cannot unserialize singleton' );
	}

	/**
	 * Get the singleton instance.
	 *
	 * @return Fluent_Cart_Ifthenpay The singleton instance.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init_hooks() {
		add_action( 'fluent_cart/register_payment_methods', array( $this, 'register_payment_gateways' ) );
	}

	public function register_payment_gateways( $gateway_manager ) {
		// Multibanco
		require_once 'payment-gateways/multibanco/class-ifthenpay-multibanco.php';
		$gateway_manager['gatewayManager']->register( 'ifthenpay-multibanco', new Ifthenpay_Multibanco() );
	}

	public function requirements_met() {
		return // Store is set to Euro
			isset( $this->store_settings ) && $this->store_settings->get( 'currency' ) === 'EUR';
	}

	public function build_out_link( $url ) {
		$attributes = array(
			'utm_source'   => rawurlencode( esc_url( home_url( '/' ) ) ),
			'utm_medium'   => 'link',
			'utm_campaign' => 'fluent-cart-ifthenpay-plugin',
		);
		return esc_url( add_query_arg( $attributes, $url ) );
	}
}

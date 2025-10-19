<?php
/**
 * Main class for the ifthenpay Payment Gateways for FluentCart
 */

namespace NakedCatPlugins\MultibancoIfthenpayFluentCart;

use FluentCart\App\Modules\PaymentMethods\Core\GatewayManager;
use FluentCart\Api\StoreSettings;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Cart;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\OrderMeta;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * ifthenpay for FluentCart main Class
 */
class Ifthenpay_Fluentcart {

	/**
	 * The singleton instance.
	 *
	 * @var Ifthenpay_Fluentcart|null
	 */
	protected static $instance = null;

	/**
	 * Store settings instance.
	 *
	 * @var StoreSettings|null
	 */
	public $store_settings = null;

	/**
	 * Plugin ID.
	 *
	 * @var string
	 */
	private $id = 'ifthenpay-fluentcart';

	/**
	 * Filter prefix for hooks.
	 *
	 * @var string
	 */
	public $filter_prefix = 'ifthenpay_fluentcart_';

	/**
	 * Webhook key for callback/webhook validation.
	 *
	 * @var string
	 */
	public $webhook_key = '';

	/**
	 * Constructor
	 */
	private function __construct() {
		// Hooks
		$this->init_hooks();
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
	 * @return Ifthenpay_Fluentcart The singleton instance.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Initialize hooks.
	 */
	public function init_hooks() {
		// Init store settings
		add_action( 'init', array( $this, 'init_store_settings' ) );
		// Register payment gateways
		add_action( 'fluent_cart/register_payment_methods', array( $this, 'register_payment_gateways' ) );
	}

	/**
	 * Initialize FluentCart store settings.
	 */
	public function init_store_settings() {
		if ( ! $this->store_settings ) {
			$this->store_settings = new StoreSettings();
		}
	}

	/**
	 * Register payment gateways.
	 *
	 * @param GatewayManager $gateway_manager The gateway manager instance.
	 */
	public function register_payment_gateways( $gateway_manager ) {
		// Multibanco
		require_once 'payment-gateways/multibanco/class-ifthenpay-multibanco.php';
		$gateway_manager['gatewayManager']->register( 'ifthenpay-multibanco', new Ifthenpay_Multibanco() );
	}

	/**
	 * Check if the general requirements for using any of our gateways are met.
	 *
	 * @return bool True if requirements are met, false otherwise.
	 */
	public function requirements_met() {
		return // Store is set to Euro
			isset( $this->store_settings ) && $this->store_settings->get( 'currency' ) === 'EUR';
	}

	/**
	 * Admin CSS for payment methods page.
	 */
	public function admin_payment_methods_css() {
		?>
		<style type="text/css">
			.ifthenpay-admin-intro {
				margin: 1rem 0;

				p + ul {
					margin-top: 0.5rem;
				}

				ul {
					list-style-type: disc;
					margin-left: 1.5rem;
				}

				.ifthenpay-webhook-url-antiphishing-key {
					display: flex;
					margin-top: 0.5rem;
					gap: 1rem;

					div {
						flex: 1;

						b {
							display: block;
						}

						b + code {
							display: block;
						}
					}

					div + div {
						flex: 0.5;
					}
				}
			}
		</style>
		<?php
	}

	/**
	 * Thank you page CSS for payment methods.
	 *
	 * @param string $payment_method The payment method ID.
	 */
	public function thank_you_css( $payment_method = '' ) {
		?>
		<style type="text/css">
			.ifthenpay-thank-you {
				margin: 2rem auto;
				max-width: 400px;

				.details_table {
					width: 100% !important;
					/* border-collapse: collapse; */

					td, th {
						padding: 0.5rem 1rem;
						/* border: 1px solid #CCCCCC;
						background-color: #FFFFFF !important;
						color: #333333 !important; */
						white-space: nowrap;

						&.mb_value {
							text-align: right;
						}
					}

					th {
						text-align: center;

						img {
							margin: auto;
							margin-top: 0.5rem;
							max-height: 2.5rem;
						}
					}
				}
			}
		</style>
		<?php
	}

	/**
	 * Finalize cart after order is completed.
	 *
	 * @param int $order_id The order ID.
	 */
	public function finalize_cart( $order_id ) {
		$cart = Cart::query()->where( 'order_id', $order_id )->where( 'stage', '!=', 'completed' )->first();
		if ( $cart ) {
			$cart->stage        = 'completed';
			$cart->completed_at = date_i18n( 'Y-m-d H:i:s' );
			$cart->save();
		}
	}

	/**
	 * Get order by ID helper.
	 *
	 * @param int $order_id The order ID.
	 * @return \FluentCart\App\Models\Order|null The order object or null if not found.
	 */
	public function get_order( $order_id ) {
		return Order::find( $order_id );
	}

	/**
	 * Get order by request ID.
	 * We should be relying on a FluentCart method for this, not directly querying the database.
	 *
	 * @param string $payment_method The payment method ID.
	 * @param string $request_id The request ID.
	 * @return \FluentCart\App\Models\Order|false The order object or false if not found or multiple found.
	 */
	public function get_order_by_request_id( $payment_method, $request_id ) {
		global $wpdb;
		$order_metas = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT *
				FROM {$wpdb->prefix}fct_order_meta
				WHERE meta_key = %s
				AND meta_value = %s",
				$payment_method . '_RequestId',
				$request_id
			)
		);
		if ( ! empty( $order_metas ) ) {
			// We should only have one...
			if ( count( $order_metas ) === 1 ) {
				$order = $this->get_order( $order_metas[0]->order_id );
				if ( $order ) {
					return $order;
				}
			} else {
				// We need to deal with this
				return false;
			}
		}
		return false;
	}

	/**
	 * Set payment details in order meta.
	 *
	 * @param string                       $payment_method The payment method ID.
	 * @param \FluentCart\App\Models\Order $order The order object.
	 * @param array                        $details The payment details to set.
	 */
	public function set_payment_details( $payment_method, $order, $details ) {
		foreach ( $details as $key => $value ) {
			$order->updateMeta( $payment_method . '_' . $key, $value );
		}
	}

	/**
	 * Get payment details from order meta.
	 *
	 * @param string                       $payment_method The payment method ID.
	 * @param \FluentCart\App\Models\Order $order The order object.
	 * @return array|false The payment details or false if not found.
	 */
	public function get_payment_details( $payment_method, $order ) {
		$details = false;
		switch ( $payment_method ) {
			case 'ifthenpay-multibanco':
				$keys    = array( 'mb_key', 'ent', 'ref', 'val', 'RequestId', 'RequestId', 'expire' );
				$details = array();
				foreach ( $keys as $key ) {
					$details[ $key ] = (string) $order->getMeta( $payment_method . '_' . $key );
				}
				if ( ! empty( $details['ent'] ) && ! empty( $details['ref'] ) && ! empty( $details['val'] ) ) {
					return $details;
				}
				break;

		}
		return $details;
	}

	/**
	 * Filter payment description to send to API
	 *
	 * @param string $desc The description.
	 * @return string
	 */
	public function filter_description_for_api( $desc ) {
		// Trim and decode
		$desc = htmlspecialchars_decode( trim( $desc ), ENT_QUOTES );
		// Remove '
		$desc = str_replace( "'", '', $desc );
		// Remove "
		$desc = str_replace( '"', '', $desc );
		// Remove extra spaces
		$desc = preg_replace( '/\s+/', ' ', trim( $desc ) );
		return $desc;
	}

	/**
	 * Format transaction value for gateway API
	 *
	 * @param mixed $value The value.
	 * @return string
	 */
	public function format_transaction_value_for_api( $value ) {
		// Convert to float and divide by 100
		$value = floatval( $value ) / 100;
		// Two decimal places with dot as decimal separator
		$value = round( $value, 2 );
		return (string) number_format( $value, 2, '.', '' );
	}

	public function send_callback_response( $status_code = 200, $message = 'Success' ) {
		wp_send_json(
			array(
				'message' => $message,
			),
			$status_code
		);
		exit;
	}

	/**
	 * Format price to decimal according to FluentCart settings
	 *
	 * @param mixed $value The value.
	 * @param bool  $multiply_by_100 Whether to multiply by 100. Default true because Helper::toDecimal expects cents.
	 * @return string
	 */
	public function format_price( $value, $multiply_by_100 = true ) {
		$value = floatval( $value );
		if ( $multiply_by_100 ) {
			$value = $value * 100;
		}
		return Helper::toDecimal( $value );
	}

	/**
	 * Format MB reference - We keep it public because someone may be using it externally
	 *
	 * @param  string $ref Multibanco reference.
	 * @return string
	 */
	public function format_multibanco_ref( $ref ) {
		return apply_filters( $this->filter_prefix . 'format_multibanco_ref', trim( chunk_split( trim( $ref ), 3, '&nbsp;' ) ) );
	}

	/**
	 * Build out link with UTM parameters.
	 *
	 * @param string $url The base URL.
	 * @return string The URL with UTM parameters.
	 */
	public function build_out_link( $url ) {
		$attributes = array(
			'utm_source'   => rawurlencode( esc_url( home_url( '/' ) ) ),
			'utm_medium'   => 'link',
			'utm_campaign' => 'ifthenpay-fluentcart-plugin',
		);
		return esc_url( add_query_arg( $attributes, $url ) );
	}
}

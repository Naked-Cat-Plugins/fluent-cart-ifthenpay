<?php
/**
 * Main class for the ifthenpay Payment Gateways for FluentCart
 */

namespace NakedCatPlugins\IfthenpayFluentCart;

use FluentCart\App\Modules\PaymentMethods\Core\GatewayManager;
use FluentCart\Api\StoreSettings;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Cart;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\OrderMeta;
use FluentCart\Api\CurrencySettings;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\OrderTransaction;

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
	 * Plugin version.
	 *
	 * @var string
	 */
	private $version = '';

	/**
	 * Prefix for hooks.
	 *
	 * @var string
	 */
	public $hook_prefix = 'ifthenpay_fluentcart_';

	/**
	 * Webhook key for callback/webhook validation.
	 *
	 * @var string
	 */
	public $webhook_key = '';

	/**
	 * Webhook activation API endpoint.
	 *
	 * @var string
	 */
	private $webhook_activation_api_url = 'https://www.ifthenpay.com/api/endpoint/callback/activation';

	/**
	 * Constructor
	 */
	private function __construct() {
		// Hooks
		$this->init_hooks();

		// Set webhook key
		$this->webhook_key = $this->get_setting( 'webhook_key' );
		if ( empty( $this->webhook_key ) ) {
			$this->webhook_key = wp_generate_password( 20, false );
			$this->set_setting( 'webhook_key', $this->webhook_key );
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
		// Add links to plugin page
		add_filter( 'plugin_action_links_' . plugin_basename( NAKEDCATPLUGINS_IFTHENPAY_FLUENTCART_FILE ), array( $this, 'add_plugin_links' ) );
		// Load admin JS and CSS
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
		// Load frontend CSS for thank you page
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		// AJAX handler for webhook activation
		add_action( 'wp_ajax_ifthenpay_fluentcart_activate_webhook', array( $this, 'ajax_activate_webhook' ) );
	}

	/**
	 * Get the plugin version.
	 *
	 * @return string The plugin version.
	 */
	public function get_version() {
		if ( empty( $this->version ) ) {
			$plugin_data   = get_file_data(
				NAKEDCATPLUGINS_IFTHENPAY_FLUENTCART_FILE,
				array( 'Version' => 'Version' ),
				'plugin'
			);
			$this->version = $plugin_data['Version'];
		}
		return $this->version;
	}

	/**
	 * Get a plugin setting.
	 *
	 * @param string $key The setting key.
	 * @return mixed The setting value.
	 */
	public function get_setting( $key ) {
		$settings = get_option( $this->id . '_settings', array() );
		if ( isset( $settings[ $key ] ) ) {
			return $settings[ $key ];
		}
		return null;
	}

	/**
	 * Set a plugin setting.
	 *
	 * @param string $key The setting key.
	 * @param mixed  $value The setting value.
	 */
	public function set_setting( $key, $value ) {
		$settings         = get_option( $this->id . '_settings', array() );
		$settings[ $key ] = $value;
		update_option( $this->id . '_settings', $settings );
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
	 * Documentation: https://dev.fluentcart.com/payment-methods-integration/
	 */
	public function register_payment_gateways() {
		// Multibanco
		require_once 'payment-gateways/multibanco/class-ifthenpay-multibanco.php';
		fluent_cart_api()->registerCustomPaymentMethod( 'ifthenpay-multibanco', new Ifthenpay_Multibanco() );
		// MB WAY
		require_once 'payment-gateways/mbway/class-ifthenpay-mbway.php';
		fluent_cart_api()->registerCustomPaymentMethod( 'ifthenpay-mbway', new Ifthenpay_MbWay() );
	}

	/**
	 * Add plugin links to the plugin page.
	 *
	 * @param array $links The existing plugin links.
	 * @return array The modified plugin links.
	 */
	public function add_plugin_links( $links ) {
		$gateways  = array(
			'ifthenpay-multibanco' => esc_html__( 'Multibanco', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
			'ifthenpay-mbway'      => esc_html__( 'MB WAY', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
		);
		$our_links = array();
		foreach ( $gateways as $gateway_id => $gateway_name ) {
			$our_links[] = '<a href="' . admin_url( 'admin.php?page=fluent-cart#/settings/payments/' . $gateway_id ) . '">'
			.
			sprintf(
				/* translators: %s: Payment method */
				esc_html__( '%s settings', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
				esc_html( $gateway_name )
			)
			.
			'</a>';
		}
		$our_links = array_merge(
			$our_links,
			array(
				// Tech support
				'<a href="https://wordpress.org/plugins/payment-multibanco-for-fluent-cart-via-ifthenpay/" target="_blank" rel="noopener">' . esc_html__( 'Get support', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ) . '</a>',
			)
		);
		return array_merge( $our_links, $links );
	}

	/**
	 * Enqueue admin scripts and css.
	 *
	 * @param string $hook The current admin page hook.
	 */
	public function admin_enqueue_scripts( $hook ) {
		if ( $hook === 'toplevel_page_fluent-cart' ) {
			wp_enqueue_script(
				$this->id . '-admin',
				plugins_url( 'assets/admin.js', NAKEDCATPLUGINS_IFTHENPAY_FLUENTCART_FILE ),
				array( 'jquery' ),
				$this->get_version() . ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '.' . time() : '' ),
				true
			);
			wp_localize_script(
				$this->id . '-admin',
				'ifthenpayFluentCart',
				array(
					'text_enter_bo_key' => esc_html__( 'Please enter your ifthenpay Backoffice Key to activate the webhook for', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
					'nonce'             => wp_create_nonce( 'ifthenpay_webhook_activation' ),
				)
			);
			wp_enqueue_style(
				$this->id . '-admin',
				plugins_url( 'assets/admin.css', NAKEDCATPLUGINS_IFTHENPAY_FLUENTCART_FILE ),
				array(),
				$this->get_version() . ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '.' . time() : '' ),
				'all'
			);
		}
	}



	/**
	 * Enqueue frontend scripts and css.
	 */
	public function enqueue_scripts() {
		if ( isset( $this->store_settings ) ) {
			$page_id_thankyou = $this->store_settings->getReceiptPageId();
			$page_id_checkout = $this->store_settings->getCheckoutPageId();
			if ( is_page( $page_id_thankyou ) || is_page( $page_id_checkout ) ) {
				// Enqueue JS
				wp_enqueue_script(
					$this->id . '-frontend',
					plugins_url( 'assets/frontend.js', NAKEDCATPLUGINS_IFTHENPAY_FLUENTCART_FILE ),
					array(),
					$this->get_version() . ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '.' . time() : '' ),
					array(
						'in_footer' => true,
					)
				);
				wp_localize_script(
					$this->id . '-frontend',
					'ifthenpayFluentCart',
					array(
						'id' => $this->id,
					)
				);
				// Enqueue CSS
				wp_enqueue_style(
					$this->id . '-frontend',
					plugins_url( 'assets/frontend.css', NAKEDCATPLUGINS_IFTHENPAY_FLUENTCART_FILE ),
					array(),
					$this->get_version() . ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '.' . time() : '' ),
					'all'
				);
			}
		}
	}

	/**
	 * Check if the general requirements for using any of our gateways are met.
	 *
	 * @return bool True if requirements are met, false otherwise.
	 */
	public function requirements_met() {
		// Store is set to Euro
		return isset( $this->store_settings ) && $this->store_settings->get( 'currency' ) === 'EUR';
	}

	/**
	 * Log debug messages using FluentCart's logging system
	 *
	 * @param object    $gateway  Gateway instance with settings.
	 * @param string    $level    Log level (info, success, warning, error).
	 * @param string    $title    Log title.
	 * @param string    $message  Log message.
	 * @param bool|null $email Override email notification (null = use gateway setting).
	 * @param array     $extra_data Additional data to include in log.
	 */
	public function log( $gateway, $level, $title, $message = '', $email = false, $extra_data = array() ) {

		// Validate required parameters
		if ( empty( $title ) ) {
			return;
		}

		// Get debug setting with fallback
		$debug_setting = $gateway->settings->get( 'debug' ) ?? 'no';

		// Only log if debug is enabled
		if ( ! in_array( $debug_setting, array( 'yes', 'yes_email' ), true ) ) {
			return;
		}

		// Determine email notification setting
		$send_email = '';
		if ( $email === true ) {
			$send_email = 'yes';
		} elseif ( $email === false ) {
			$send_email = '';
		} else {
			// Use gateway setting when $email is null
			$send_email = ( $debug_setting === 'yes_email' ) ? 'yes' : '';
		}

		// Prepare log data
		$log_data = array_merge(
			array(
				'module_name'               => $gateway->ifthenpay_id ?? $this->id,
				'trigger_admin_alert_email' => $send_email,
				'module_type'               => 'payment_gateway',
			),
			$extra_data
		);

		// Sanitize inputs
		$title = sanitize_text_field( $title );
		$level = sanitize_text_field( $level );

		// Log the message
		\fluent_cart_add_log( $title, $message, $level, $log_data );
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
	 * AJAX handler to activate webhook/callback.
	 */
	public function ajax_activate_webhook() {
		// Verify nonce
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'ifthenpay_webhook_activation' ) ) {
			wp_die( 'Security check failed' );
		}

		// Check user capabilities
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions' );
		}

		// Sanitize inputs
		$gateway = isset( $_POST['gateway'] ) ? sanitize_text_field( wp_unslash( $_POST['gateway'] ) ) : ''; // Not used
		$ent     = isset( $_POST['ent'] ) ? sanitize_text_field( wp_unslash( $_POST['ent'] ) ) : '';
		$subent  = isset( $_POST['subent'] ) ? sanitize_text_field( wp_unslash( $_POST['subent'] ) ) : '';
		$bo_key  = isset( $_POST['bo_key'] ) ? sanitize_text_field( wp_unslash( $_POST['bo_key'] ) ) : '';

		$gateway_instance = GatewayManager::getInstance( $gateway );

		$data = array(
			'chave'       => $bo_key,
			'entidade'    => $ent,
			'subentidade' => $subent,
			'apKey'       => $this->webhook_key,
			'urlCb'       => $gateway_instance->webhook_url,
		);

		// Make API call to ifthenpay
		$response = wp_remote_post(
			$this->webhook_activation_api_url,
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode( $data ),
				'timeout' => apply_filters( $this->hook_prefix . 'api_timeout', 15 ),
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( 'Connection failed: ' . $response->get_error_message() );
		}

		$body = wp_remote_retrieve_body( $response );

		if ( intval( $response['response']['code'] ) === 200 ) {
			$this->set_setting( $gateway . '_webhook_activated', date_i18n( 'Y-m-d H:i:s' ) );
			$this->set_setting( $gateway . '_webhook_activated_key', $subent );
			wp_send_json_success( __( 'Webhook/Callback activated successfully', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ) );
		} else {
			wp_send_json_error( $body ?? __( 'Webhook/Callback activation failed', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ) );
		}
	}

	/**
	 * Make API call to ifthenpay payment request endpoint.
	 *
	 * @param object $gateway The gateway instance.
	 * @param object $order The order object.
	 * @param array  $payment_request_arguments The payment request arguments.
	 * @param string $expected_status The expected status code in the response (default '0').
	 * @return array The API response with status and body or error message.
	 */
	public function make_request_payment_api_call( $gateway, $order, $payment_request_arguments, $expected_status = '0' ) {

		$title = $gateway->meta()['title'];

		// Make API call to ifthenpay to create Multibanco reference - Maybe abstract this in the main class
		$args = array(
			'method'   => 'POST',
			'timeout'  => apply_filters( $this->hook_prefix . 'api_timeout', 15 ),
			'blocking' => true,
			'headers'  => array(
				'Content-Type' => 'application/json; charset=utf-8',
			),
			'body'     => wp_json_encode( $payment_request_arguments ),
		);
		// Make the request
		$response = wp_remote_post( $gateway->api_url, $args );

		$this->log( $gateway, 'info', $title . ' payment request', 'Order: ' . $order->id . ' - Data: ' . wp_json_encode( $payment_request_arguments ) );

		// Deal with errors - Step 1
		if ( is_wp_error( $response ) ) {
			$message = sprintf(
				/* translators: %s: Error details */
				__( 'Failed to create payment at ifthenpay API: %s', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
				$response->get_error_code() . ' - ' . $response->get_error_message()
			);
			$this->log( $gateway, 'error', 'Failed ' . $title . ' payment request', 'Order: ' . $order->id . ' - ' . $message, true );
			return array(
				'status'  => 'failed',
				'message' => $message,
			);
		}

		// Deal with errors - Step 2
		if ( ! ( isset( $response['response']['code'] ) && intval( $response['response']['code'] ) === 200 && isset( $response['body'] ) && trim( $response['body'] ) !== '' ) ) {
			$message = sprintf(
					/* translators: %s: Response code */
				__( 'Unexpected response from ifthenpay API. Response code: %s', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
				isset( $response['response']['code'] ) ? intval( $response['response']['code'] ) : 'N/A'
			);
			$this->log( $gateway, 'error', 'Failed ' . $title . ' payment request', 'Order: ' . $order->id . ' - ' . $message, true );
			return array(
				'status'  => 'failed',
				'message' => $message,
			);
		}

		// Deal with errors - Step 3
		$body = json_decode( $response['body'] );
		if ( ! ( ! empty( $body ) && isset( $body->Status ) && trim( $body->Status ) === $expected_status ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$message = sprintf(
					/* translators: %s: Response code */
				__( 'An error occurred processing the %s Payment request - please try again', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
				'“' . $title . '”'
			);
			$this->log( $gateway, 'error', 'Failed ' . $title . ' payment request', 'Order: ' . $order->id . ' - ' . $message, true );
			return array(
				'status'  => 'failed',
				'message' => $message,
			);
		}

		return array(
			'status' => 'success',
			'body'   => $body,
		);
	}

	/**
	 * Handle IPN/webhook callbacks from ifthenpay.
	 *
	 * @param object $gateway The gateway instance.
	 * @param array  $required_data The required data fields in the webhook.
	 * @param array  $matching_data The data fields to match between payment details and webhook data.
	 */
	public function handle_ipn( $gateway, $required_data, $matching_data ) {

		// Sanitize data
		$data = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		array_walk( $data, 'sanitize_text_field' );

		$this->log( $gateway, 'info', 'Webhook called', 'Data: ' . wp_json_encode( $data ) );

		// Validate webhook key
		if ( ! isset( $data['webhook_key'] ) || trim( $data['webhook_key'] ) === '' || $data['webhook_key'] !== trim( $this->webhook_key ) ) {
			$this->log( $gateway, 'error', 'Webhook failed', 'Invalid webhook key - Webhook data: ' . wp_json_encode( $data ), true );
			$this->send_callback_response( 403, 'Invalid webhook key', null, $data, true );
			return;
		}

		// Validate that all necessary data is present
		$valid = true;
		if ( isset( $data['plugin'] ) && $data['plugin'] !== 'webdados-ifthenpay-fluentcart' ) {
			$valid = false;
		} else {
			foreach ( $required_data as $field ) {
				if ( ! isset( $data[ $field ] ) ) {
					$valid = false;
					break;
				}
			}
		}
		if ( ! $valid ) {
			$this->log( $gateway, 'error', 'Webhook failed', 'Invalid data or fields missing - Webhook data: ' . wp_json_encode( $data ), true );
			$this->send_callback_response( 403, 'Invalid data or fields missing', null, $data, true );
			return;
		}

		// Get transaction based on request_id
		$transaction = OrderTransaction::query()
				->where( 'payment_method', $gateway->ifthenpay_id )
				->where( 'vendor_charge_id', $data['request_id'] ) // May be different based on gateway callback, OK for MB and MBWAY
				->where( 'total', (int) ( $data['value'] * 100 ) ) // May be different based on gateway callback, OK for MB and MBWAY
				->orderBy( 'id', 'DESC' )
				->first();
		if ( ! $transaction ) {
			$this->log( $gateway, 'error', 'Webhook failed', 'Transaction not found - Webhook data: ' . wp_json_encode( $data ), true );
			$this->send_callback_response( 200, 'Transaction not found' ); // Should be 404 but we want to stop ifthenpay from retrying
			return;
		}

		// Check if the transaction is to be processed or not
		if ( ! in_array( $transaction->status, array( Status::TRANSACTION_PENDING ), true ) ) {
			$this->log( $gateway, 'warning', 'Webhook failed', 'Transaction found but not pending payment - Transaction ID: ' . $transaction->id . ' - Order ID: ' . $transaction->order_id );
			$this->send_callback_response( 200, 'Transaction found but not pending payment' ); // Should be 404 but we want to stop ifthenpay from retrying
			return;
		}

		// Set the order
		$order = $transaction->order;
		// Get payment order payment details and compare them
		$payment_details = $this->get_payment_details( $gateway->ifthenpay_id, $order );
		if ( empty( $payment_details ) ) {
			$this->log( $gateway, 'error', 'Webhook failed', 'Order found but no payment details are recorded on it - Order ID: ' . $order->id . ' - Webhook data: ' . wp_json_encode( $data ), true );
			$this->send_callback_response( 200, 'Order found but no payment details are recorded on it' ); // Should be 404 but we want to stop ifthenpay from retrying
			return;
		}
		$valid = true;
		foreach ( $matching_data as $payment_key => $data_key ) {
			// Not set?
			if ( ! isset( $data[ $data_key ] ) ) {
				$valid = false;
				break;
			}
			// Special case - Value
			if ( $payment_key === 'val' ) { // Should be ok because we got the transaction by amount, but let's test it anyway
				if ( floatval( $data[ $data_key ] ) !== floatval( $payment_details[ $payment_key ] ) ) {
					$valid = false;
					break;
				}
			} elseif ( $payment_key === 'order_id' ) {
				// Special case - Order ID
				if ( intval( $data[ $data_key ] ) !== intval( $order->id ) ) {
					$valid = false;
					break;
				}
			} elseif ( (string) $data[ $data_key ] !== (string) $payment_details[ $payment_key ] ) {
				// Normal case - Direct comparison
				$valid = false;
				break;
			}
		}
		if ( ! $valid ) {
			$this->log( $gateway, 'error', 'Webhook failed', 'Order found but payment details do not match - Order ID: ' . $order->id . ' - Webhook data: ' . wp_json_encode( $data ) . ' - Payment Details: ' . wp_json_encode( $payment_details ), true );
			$this->send_callback_response( 200, 'Order found but payment details do not match' ); // Should be 404 but we want to stop ifthenpay from retrying
			return;
		}

		// Set transaction and order as paid
		$transaction->status = Status::TRANSACTION_SUCCEEDED;
		$transaction->fill(
			array(
				'status'           => Status::TRANSACTION_SUCCEEDED,
				'vendor_charge_id' => $data['request_id'], // Already set but just in case - May be different based on gateway callback, OK for MB and MBWAY
			)
		);
		$transaction->save();
		( new StatusHelper( $order ) )->syncOrderStatuses( $transaction );

		// Store ifthenpay fee, if present on the webhook data
		if ( isset( $data['payment_fee'] ) && floatval( $data['payment_fee'] ) > 0 ) {
			$order->updateMeta( $gateway->ifthenpay_id . '_fee', floatval( $data['payment_fee'] ) );

		}

		$this->log( $gateway, 'success', 'Webhook succeeded', 'Order found and payment processed successfully - Order ID: ' . $order->id );
		$this->send_callback_response( 200, 'Order found and payment processed successfully' );

		do_action( $this->hook_prefix . 'payment_completed', $gateway->ifthenpay_id, $order, $transaction );
	}

	/**
	 * Get order by ID helper.
	 * Still not used
	 *
	 * @param int $order_id The order ID.
	 * @return \FluentCart\App\Models\Order|null The order object or null if not found.
	 */
	public function get_order( $order_id ) {
		return Order::find( $order_id );
	}

	/**
	 * Set payment details in order meta.
	 *
	 * @param string                                  $payment_method The payment method ID.
	 * @param \FluentCart\App\Models\Order            $order The order object.
	 * @param \FluentCart\App\Models\OrderTransaction $transaction The transaction object.
	 * @param string                                  $request_id The unique request ID from ifthenpay.
	 * @param array                                   $details The payment details to set.
	 */
	public function set_payment_details( $payment_method, $order, $transaction, $request_id, $details ) {
		// Store on transaction
		$transaction->update(
			array(
				'vendor_charge_id' => $request_id,
			)
		);
		// Store on order
		foreach ( $details as $key => $value ) {
			$order->updateMeta( $payment_method . '_' . $key, $value );
		}
	}

	/**
	 * Get payment details from order meta.
	 * Should be better abstracted
	 *
	 * @param string                       $payment_method The payment method ID.
	 * @param \FluentCart\App\Models\Order $order The order object.
	 * @return array|false The payment details or false if not found.
	 */
	public function get_payment_details( $payment_method, $order ) {
		$details = false;
		switch ( $payment_method ) {
			case 'ifthenpay-multibanco':
				$keys    = array( 'mb_key', 'ent', 'ref', 'val', 'RequestId', 'expire' );
				$details = array();
				foreach ( $keys as $key ) {
					$details[ $key ] = (string) $order->getMeta( $payment_method . '_' . $key );
				}
				if ( ! empty( $details['ent'] ) && ! empty( $details['ref'] ) && ! empty( $details['val'] ) ) {
					return $details;
				}
				break;
			case 'ifthenpay-mbway':
				$keys    = array( 'mbway_key', 'val', 'RequestId', 'time', 'expire', 'phone', 'country_code', 'phone_api' );
				$details = array();
				foreach ( $keys as $key ) {
					$details[ $key ] = (string) $order->getMeta( $payment_method . '_' . $key );
				}
				if ( ! empty( $details['phone'] ) && ! empty( $details['val'] ) ) {
					return $details;
				}
				break;

		}
		return $details;
	}

	/**
	 * Filter active payment methods based on gateway requirements and settings.
	 *
	 * @param array  $active_payment_methods The active payment methods.
	 * @param array  $args The arguments including cart data.
	 * @param object $gateway The gateway instance.
	 * @return array The filtered active payment methods.
	 */
	public function filter_active_payment_methods( $active_payment_methods, $args, $gateway ) {
		foreach ( $active_payment_methods as $index => $method ) {
			if ( method_exists( $method, 'getMeta' ) && $method->getMeta( 'slug' ) === $gateway->ifthenpay_id ) {
				// Payment method requirements
				if ( ! $gateway->requirements_met() ) {
					unset( $active_payment_methods[ $index ] );
					break;
				}
				// Only for Portuguese customers?
				if ( $gateway->settings->get( 'only_portugal' ) === 'yes' ) {
					if (
						isset( $args['cart']->checkout_data['form_data'] )
					) {
						$address_data = $args['cart']->checkout_data['form_data'];
						if (
							isset( $address_data['billing_country'] ) && $address_data['billing_country'] !== 'PT'
							&&
							isset( $address_data['shipping_country'] ) && $address_data['shipping_country'] !== 'PT'
						) {
							unset( $active_payment_methods[ $index ] );
							break;
						}
					}
				}
				// By gateway min/max value?
				$cart_total = $args['cart']->getEstimatedTotal();
				if ( isset( $gateway->min_value ) && isset( $gateway->max_value ) ) {
					// Let's work with cents to avoid float precision issues as FluentCart does
					$min_value = intval( $gateway->min_value * 100 );
					$max_value = intval( $gateway->max_value * 100 );
					if ( $cart_total < $min_value || $cart_total > $max_value ) {
						unset( $active_payment_methods[ $index ] );
						break;
					}
				}
				// By settings "from" value
				if ( ! empty( $gateway->settings->get( 'only_from' ) ) && floatval( $gateway->settings->get( 'only_from' ) ) > 0 ) {
					$only_from = intval( floatval( $gateway->settings->get( 'only_from' ) ) * 100 );
					if ( $cart_total < $only_from ) {
						unset( $active_payment_methods[ $index ] );
						break;
					}
				}
				// By settings "up to" value
				if ( ! empty( $gateway->settings->get( 'only_up_to' ) ) && floatval( $gateway->settings->get( 'only_up_to' ) ) > 0 ) {
					$only_up_to = intval( floatval( $gateway->settings->get( 'only_up_to' ) ) * 100 );
					if ( $cart_total > $only_up_to ) {
						unset( $active_payment_methods[ $index ] );
						break;
					}
				}
				// We already found our method, no need to continue loop
				break;
			}
		}
		return $active_payment_methods;
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

	/**
	 * Send callback response and exit.
	 *
	 * @param int    $status_code The HTTP status code. Default 200.
	 * @param string $message The message. Default 'Success'.
	 */
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
	 * @param mixed       $value The value.
	 * @param bool        $multiply_by_100 Whether to multiply by 100. Default true because Helper::toDecimal expects cents.
	 * @param string|null $currency The currency code. Default null to use store currency.
	 * @return string
	 */
	public function format_price( $value, $multiply_by_100 = true, $currency = null ) {
		$value = floatval( $value );
		if ( $multiply_by_100 ) {
			$value = $value * 100;
		}
		return CurrencySettings::getPriceHtml( $value, $currency );
	}

	/**
	 * Format MB reference - We keep it public because someone may be using it externally
	 *
	 * @param  string $ref Multibanco reference.
	 * @return string
	 */
	public function format_multibanco_ref( $ref ) {
		$ref = trim( chunk_split( trim( $ref ), 3, '&nbsp;' ) );
		return apply_filters( $this->hook_prefix . 'format_multibanco_ref', $ref );
	}

	/**
	 * Settings field for gateway key.
	 *
	 * @param string $label The field label.
	 * @return array The settings field configuration.
	 */
	public function settings_field_key( $label ) {
		// Missing: max length and pattern
		return array(
			'type'        => 'text',
			'label'       => $label,
			'placeholder' => 'AAA-000000',
			'tooltip'     => sprintf(
				/* translators: %s: Gateway key name */
				__( '%s provided by ifthenpay when signing the contract.', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
				$label
			),
		);
	}

	/**
	 * Settings field for only Portugal option.
	 *
	 * @return array The settings field configuration.
	 */
	public function settings_field_only_portugal() {
		return array(
			'type'    => 'checkbox',
			'label'   => __( 'Only for Portuguese customers', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
			'tooltip' => __( 'Enable this option to make the payment method available only for customers with a billing or shipping address in Portugal.', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
		);
	}

	/**
	 * Settings field for only from value.
	 *
	 * @param object $gateway The gateway instance.
	 * @return array The settings field configuration.
	 */
	public function settings_field_only_from( $gateway ) {
		$field = array(
			'type'    => 'text',
			'label'   => __( 'Only for orders from', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
			'tooltip' => __( 'Enable only for orders with a value from x &euro;. Leave blank to not apply this restriction.', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
		);
		if ( isset( $gateway->min_value ) && isset( $gateway->max_value ) ) {
			$field['tooltip'] .= ' ' . sprintf(
				/* translators: %s: Minimum value */
				__( 'By design, %1$s only allows payments from %2$s to %3$s. You can use this option to further limit this range.', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
				'“' . $gateway->meta()['title'] . '”',
				$this->format_price( $gateway->min_value, true, 'EUR' ),
				$this->format_price( $gateway->max_value, true, 'EUR' )
			);
		}
		return $field;
	}

	/**
	 * Settings field for only up to value.
	 *
	 * @param object $gateway The gateway instance.
	 * @return array The settings field configuration.
	 */
	public function settings_field_only_up_to( $gateway ) {
		$field = array(
			'type'    => 'text',
			'label'   => __( 'Only for orders up to', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
			'tooltip' => __( 'Enable only for orders with a value up to x &euro;. Leave blank to not apply this restriction.', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
		);
		if ( isset( $gateway->min_value ) && isset( $gateway->max_value ) ) {
			$field['tooltip'] .= ' ' . sprintf(
				/* translators: %s: Minimum value */
				__( 'By design, %1$s only allows payments from %2$s to %3$s. You can use this option to further limit this range.', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
				'“' . $gateway->meta()['title'] . '”',
				$this->format_price( $gateway->min_value, true, 'EUR' ),
				$this->format_price( $gateway->max_value, true, 'EUR' )
			);
		}
		return $field;
	}

	/**
	 * Settings field for debug mode.
	 *
	 * @return array The settings field configuration.
	 */
	public function settings_field_debug() {
		$debug_options = array(
			array(
				'value' => 'no',
				'label' => __( 'Disabled', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
			),
			array(
				'value' => 'yes',
				'label' => __( 'Enabled', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
			),
			array(
				'value' => 'yes_email',
				'label' => __( 'Enabled (and send important events to email)', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
			),
		);
		return array(
			'type'    => 'select',
			'label'   => __( 'Debug mode', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
			'tooltip' => __( 'Log additional information for debugging purposes.', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
			'options' => $debug_options,
		);
	}

	/**
	 * Thank you page content - Payment instructions.
	 *
	 * @param object $gateway The gateway instance.
	 * @param array  $args    The arguments.
	 */
	public function thank_you_page( $gateway, $args ) {
		$order           = $args['order'];
		$is_first_time   = $args['is_first_time'];
		$order_operation = $args['order_operation'];
		// Our gateway?
		if ( $order->payment_method === $gateway->ifthenpay_id ) {

			switch ( $order->payment_status ) {
				case Status::PAYMENT_PENDING:
				case Status::PAYMENT_PARTIALLY_PAID:
					// Not paid or not completely paid yet
					$gateway->thank_you_page_pending( $order );
					break;
				case Status::PAYMENT_PAID:
					// Paid
					$gateway->thank_you_page_paid( $order );
					break;
				case Status::PAYMENT_FAILED:
				case Status::PAYMENT_REFUNDED:
				case Status::PAYMENT_PARTIALLY_REFUNDED:
				case Status::PAYMENT_AUTHORIZED:
				default:
					// Other statuses - Do nothing
					break;

			}
		}
	}

	/**
	 * Thank you page content for pending payments.
	 *
	 * @param object $gateway The gateway instance.
	 * @param array  $rows   Rows to display.
	 */
	public function thank_you_page_pending( $gateway, $rows ) {
		?>
		<div class="ifthenpay-thank-you">
			<table class="details_table" cellpadding="0" cellspacing="0">
				<tr>
					<th colspan="2">
						<div><?php esc_html_e( 'Payment instructions', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ); ?></div>
						<div><img src="<?php echo esc_url( $gateway->meta()['ifthenpay_banner'] ); ?>" alt="<?php echo esc_attr( $gateway->meta()['title'] ); ?>"/></div>
					</th>
				</tr>
				<?php
				foreach ( $rows as $title => $value ) {
					if ( $title !== 'action_html' ) {
						?>
						<tr>
							<td><?php echo esc_html( $title ); ?>:</td>
							<td class="mb_value"><?php echo wp_kses_post( $value ); ?></td>
						</tr>
						<?php
					}
				}
				if ( isset( $rows['action_html'] ) ) {
					?>
					<tr>
						<td colspan="2" class="mb_action">
							<?php echo wp_kses_post( $rows['action_html'] ); ?>
						</td>
					</tr>
					<?php
				}
				?>
			</table>
		</div>
		<?php
	}

	/**
	 * Thank you page content for paid payments.
	 *
	 * @param object $gateway The gateway instance.
	 * @param array  $rows   Rows to display.
	 */
	public function thank_you_page_paid( $gateway, $rows ) {
		?>
		<div class="ifthenpay-thank-you">
			<table class="details_table" cellpadding="0" cellspacing="0">
				<tr>
					<th colspan="2">
						<div><?php esc_html_e( 'Payment received', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ); ?></div>
						<div><img src="<?php echo esc_url( $gateway->meta()['ifthenpay_banner'] ); ?>" alt="<?php echo esc_attr( $gateway->meta()['title'] ); ?>"/></div>
					</th>
				</tr>
				<?php
				foreach ( $rows as $title => $value ) {
					?>
					<tr>
						<td><?php echo esc_html( $title ); ?>:</td>
						<td class="mb_value"><?php echo wp_kses_post( $value ); ?></td>
					</tr>
					<?php

				}
				?>
			</table>
		</div>
		<?php
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

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
use FluentCart\App\Services\Permission\PermissionManager;

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
	 * Payments list API endpoint, used to read the fee ifthenpay charged on a payment.
	 *
	 * @var string
	 */
	private $payments_api_url = 'https://api.ifthenpay.com/v2/payments/read';

	/**
	 * Refund API endpoint (MB WAY, card, Google Pay and Apple Pay).
	 *
	 * @var string
	 */
	private $refunds_api_url = 'https://api.ifthenpay.com/v2/payments/refund';

	/**
	 * Number of times we ask ifthenpay for a payment's fee before giving up.
	 *
	 * @var int
	 */
	private $fee_lookup_attempts = 3;

	/**
	 * Our payment methods, as gateway ID => class name.
	 *
	 * @var array
	 */
	private $gateway_classes = array(
		'ifthenpay-multibanco' => 'Ifthenpay_Multibanco',
		'ifthenpay-mbway'      => 'Ifthenpay_Mbway',
		'ifthenpay-ccard'      => 'Ifthenpay_Ccard',
	);

	/**
	 * Our registered payment method instances, as gateway ID => instance.
	 *
	 * @var array
	 */
	private $gateways = array();

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
		// AJAX handler for saving or removing the Backoffice Key
		add_action( 'wp_ajax_ifthenpay_fluentcart_backoffice_key', array( $this, 'ajax_backoffice_key' ) );
		// Payment instructions smartcode for emails
		add_filter( 'fluent_cart/smartcode_fallback', array( $this, 'smartcode_fallback' ), 10, 2 );
		add_filter( 'fluent_cart/editor_shortcodes', array( $this, 'editor_shortcodes' ) );
		// Payment details panel on the admin order screen
		add_filter( 'fluent_cart/widgets/single_order_page', array( $this, 'order_widget' ), 10, 2 );
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
	 * The ifthenpay Backoffice Key, shared by all our payment methods.
	 * Kept in its own option, not autoloaded, as it is only needed in a few admin requests.
	 *
	 * @return string The key, or an empty string if it is not saved.
	 */
	public function get_backoffice_key() {
		return trim( (string) get_option( $this->id . '_backoffice_key', '' ) );
	}

	/**
	 * Save or remove the ifthenpay Backoffice Key.
	 *
	 * @param string $key The key, or an empty string to remove it.
	 */
	public function set_backoffice_key( $key ) {
		$key = trim( (string) $key );
		if ( $key === '' ) {
			delete_option( $this->id . '_backoffice_key' );
			return;
		}
		update_option( $this->id . '_backoffice_key', $key, false );
	}

	/**
	 * Check if a Backoffice Key is in the 0000-0000-0000-0000 format.
	 *
	 * @param mixed $key The key.
	 * @return bool
	 */
	public function is_valid_backoffice_key( $key ) {
		return (bool) preg_match( '/^[0-9]{4}-[0-9]{4}-[0-9]{4}-[0-9]{4}$/', trim( (string) $key ) );
	}

	/**
	 * The Backoffice Key with all but the last group of digits hidden, for display.
	 *
	 * @param string $key The key.
	 * @return string The masked key.
	 */
	public function mask_backoffice_key( $key ) {
		$key = trim( (string) $key );
		return $key === '' ? '' : preg_replace( '/[0-9](?=.{4})/', '•', $key );
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
		$this->load_gateway_classes();
		foreach ( $this->gateway_classes as $gateway_id => $class_name ) {
			$class_name                    = __NAMESPACE__ . '\\' . $class_name;
			$this->gateways[ $gateway_id ] = new $class_name();
			fluent_cart_api()->registerCustomPaymentMethod( $gateway_id, $this->gateways[ $gateway_id ] );
		}
	}

	/**
	 * Load the payment gateway classes.
	 * Files are named after the gateway ID, in a folder named after its short ID.
	 */
	public function load_gateway_classes() {
		require_once __DIR__ . '/payment-gateways/class-ifthenpay-gateway-settings.php';
		require_once __DIR__ . '/payment-gateways/class-ifthenpay-gateway.php';
		foreach ( array_keys( $this->gateway_classes ) as $gateway_id ) {
			$short_id = str_replace( 'ifthenpay-', '', $gateway_id );
			require_once __DIR__ . '/payment-gateways/' . $short_id . '/class-' . $gateway_id . '.php';
		}
	}

	/**
	 * Our payment method IDs.
	 *
	 * @return array The gateway IDs.
	 */
	public function gateway_ids() {
		return array_keys( $this->gateway_classes );
	}

	/**
	 * Get one of our registered payment method instances.
	 *
	 * @param string $gateway_id The gateway ID.
	 * @return Ifthenpay_Gateway|null The gateway instance, or null if not registered.
	 */
	public function get_gateway( $gateway_id ) {
		return isset( $this->gateways[ $gateway_id ] ) ? $this->gateways[ $gateway_id ] : null;
	}

	/**
	 * Add plugin links to the plugin page.
	 *
	 * @param array $links The existing plugin links.
	 * @return array The modified plugin links.
	 */
	public function add_plugin_links( $links ) {
		$this->load_gateway_classes();
		$our_links = array();
		foreach ( $this->gateway_classes as $gateway_id => $class_name ) {
			$class_name   = __NAMESPACE__ . '\\' . $class_name;
			$gateway_name = $class_name::method_name();
			$our_links[]  = '<a href="' . admin_url( 'admin.php?page=fluent-cart#/settings/payments/' . $gateway_id ) . '">'
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
				$this->asset_version(),
				true
			);
			wp_localize_script(
				$this->id . '-admin',
				'ifthenpayFluentCart',
				array(
					'text_bo_key_needed' => esc_html__( 'Save your ifthenpay Backoffice Key first.', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
					'text_bo_key_reload' => esc_html__( 'Reload this page to update the refunds option.', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
					'text_bo_key_remove' => esc_html__( 'Remove the ifthenpay Backoffice Key? It is used by all ifthenpay payment methods in this store.', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
					'text_simulate'      => esc_html__( 'This is a testing tool and will set the order as paid. Are you sure you want to proceed?', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
					'text_simulate_err'  => esc_html__( 'Error: Could not set the order as paid', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
					'nonce'              => wp_create_nonce( 'ifthenpay_webhook_activation' ),
				)
			);
			wp_enqueue_style(
				$this->id . '-admin',
				plugins_url( 'assets/admin.css', NAKEDCATPLUGINS_IFTHENPAY_FLUENTCART_FILE ),
				array(),
				$this->asset_version(),
				'all'
			);
		}
	}



	/**
	 * Enqueue frontend css for the payment instructions.
	 *
	 * Only the receipt page is covered here. Anything a payment method needs while
	 * it is being rendered at checkout belongs in that gateway's own
	 * getEnqueueStyleSrc() / getEnqueueScriptSrc(), which FluentCart calls when the
	 * method is actually drawn, so it is not loaded for shoppers paying by other
	 * means. The receipt page draws our payment instructions outside of any payment
	 * method render, so it has nowhere else to hook.
	 */
	public function enqueue_scripts() {
		if ( ! isset( $this->store_settings ) ) {
			return;
		}
		if ( ! is_page( $this->store_settings->getReceiptPageId() ) ) {
			return;
		}
		wp_enqueue_style(
			$this->id . '-frontend',
			plugins_url( 'assets/frontend.css', NAKEDCATPLUGINS_IFTHENPAY_FLUENTCART_FILE ),
			array(),
			$this->asset_version(),
			'all'
		);
	}

	/**
	 * Asset version string, with cache busting while debugging.
	 *
	 * @return string
	 */
	public function asset_version() {
		return $this->get_version() . ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '.' . time() : '' );
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
	 * Encode data for the debug log, without exposing secrets or personal data.
	 *
	 * The antiphishing key is replaced when it is the correct one, as in our WooCommerce plugin,
	 * and left as received when it is not, so a wrong key can be diagnosed.
	 * Mobile numbers are masked, keeping the first and last two digits.
	 *
	 * @param array $data The data to log.
	 * @return string The data, JSON encoded.
	 */
	public function log_data( $data ) {
		if ( is_array( $data ) ) {
			if ( isset( $data['webhook_key'] ) && is_string( $data['webhook_key'] ) && trim( $this->webhook_key ) !== '' && hash_equals( trim( $this->webhook_key ), $data['webhook_key'] ) ) {
				$data['webhook_key'] = '[CORRECT_ANTIPHISHING_KEY]';
			}
			foreach ( array( 'phone', 'phone_api', 'mobileNumber' ) as $key ) {
				if ( isset( $data[ $key ] ) && is_string( $data[ $key ] ) ) {
					$data[ $key ] = $this->mask_phone( $data[ $key ] );
				}
			}
		}
		return wp_json_encode( $data );
	}

	/**
	 * Mask a mobile number for the debug log, keeping the first and last two digits (91*****89).
	 * A calling code prefix, as in 351#912345689, is kept as it is.
	 *
	 * @param string $phone The mobile number.
	 * @return string The masked mobile number.
	 */
	public function mask_phone( $phone ) {
		$prefix = '';
		if ( strpos( $phone, '#' ) !== false ) {
			list( $prefix, $phone ) = explode( '#', $phone, 2 );
			$prefix                .= '#';
		}
		$length = strlen( $phone );
		if ( $length <= 4 ) {
			return $prefix . str_repeat( '*', $length );
		}
		return $prefix . substr( $phone, 0, 2 ) . str_repeat( '*', $length - 4 ) . substr( $phone, -2 );
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
	 * Run the actions FluentCart runs when a cart is completed on payment.
	 *
	 * We complete the cart when the order is placed, as FluentCart's Cash on Delivery does, because
	 * an open cart attached to an order blocks a new checkout and a Multibanco payment can take days.
	 * FluentCart then skips these actions when the payment arrives, as it only runs them for a cart it
	 * completes itself (StatusHelper::syncOrderStatuses()), so we run them at that same moment:
	 * fluent_cart/cart_completed and the cart's own success actions, such as the order bump one.
	 *
	 * @param \FluentCart\App\Models\Order            $order       The order object.
	 * @param \FluentCart\App\Models\OrderTransaction $transaction The transaction object.
	 */
	public function run_cart_completed_actions( $order, $transaction ) {
		$cart = Cart::query()->where( 'order_id', $order->id )->first();
		if ( ! $cart ) {
			return;
		}
		do_action(
			'fluent_cart/cart_completed', // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- FluentCart's own hook
			array(
				'cart'  => $cart,
				'order' => $order,
			)
		);
		$checkout_data      = is_array( $cart->checkout_data ) ? $cart->checkout_data : array();
		$on_success_actions = isset( $checkout_data['__on_success_actions__'] ) ? (array) $checkout_data['__on_success_actions__'] : array();
		foreach ( $on_success_actions as $on_success_action ) {
			$on_success_action = (string) $on_success_action;
			if ( has_action( $on_success_action ) ) {
				do_action(
					$on_success_action, // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- FluentCart's own success actions
					array(
						'cart'        => $cart,
						'order'       => $order,
						'transaction' => $transaction,
					)
				);
			}
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

		// Same permission FluentCart requires to manage payment methods
		if ( ! PermissionManager::userCan( 'is_super_admin' ) ) {
			wp_die( 'Insufficient permissions' );
		}

		// Sanitize inputs
		$gateway = isset( $_POST['gateway'] ) ? sanitize_text_field( wp_unslash( $_POST['gateway'] ) ) : '';
		$ent     = isset( $_POST['ent'] ) ? sanitize_text_field( wp_unslash( $_POST['ent'] ) ) : '';
		$subent  = isset( $_POST['subent'] ) ? sanitize_text_field( wp_unslash( $_POST['subent'] ) ) : '';
		$bo_key  = $this->get_backoffice_key();

		// The saved Backoffice Key is needed
		if ( $bo_key === '' ) {
			wp_send_json_error( __( 'Save your ifthenpay Backoffice Key first.', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ) );
		}

		// Only our own payment methods
		$gateway_instance = isset( $this->gateway_classes[ $gateway ] ) ? GatewayManager::getInstance( $gateway ) : null;
		if ( ! $gateway_instance instanceof Ifthenpay_Gateway ) {
			wp_send_json_error( __( 'Invalid payment method', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ) );
		}

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
	 * AJAX handler to save or remove the Backoffice Key.
	 */
	public function ajax_backoffice_key() {
		// Verify nonce
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'ifthenpay_webhook_activation' ) ) {
			wp_die( 'Security check failed' );
		}

		// Same permission FluentCart requires to manage payment methods
		if ( ! PermissionManager::userCan( 'is_super_admin' ) ) {
			wp_die( 'Insufficient permissions' );
		}

		$operation = isset( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';

		if ( $operation === 'remove' ) {
			$this->set_backoffice_key( '' );
			wp_send_json_success(
				array(
					'masked'  => '',
					'message' => __( 'Backoffice Key removed', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
				)
			);
		}

		$key = isset( $_POST['bo_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['bo_key'] ) ) ) : '';
		if ( ! $this->is_valid_backoffice_key( $key ) ) {
			wp_send_json_error(
				sprintf(
					/* translators: %s: example of a Backoffice Key */
					__( 'The Backoffice Key does not look valid. It should be four groups of four digits, like %s.', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
					'0000-0000-0000-0000'
				)
			);
		}
		$this->set_backoffice_key( $key );
		wp_send_json_success(
			array(
				'masked'  => $this->mask_backoffice_key( $key ),
				'message' => __( 'Backoffice Key saved', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
			)
		);
	}

	/**
	 * The Backoffice Key box, shown on the settings screen of every one of our payment methods.
	 * The key is shared, so the box shows the same key, and saves to the same place, on all of them.
	 *
	 * @param bool $refunds Whether the payment method has the refunds option, which needs a reload to follow a key change.
	 * @return string The HTML.
	 */
	public function backoffice_key_box( $refunds = false ) {
		$key   = $this->get_backoffice_key();
		$saved = $key !== '';
		ob_start();
		?>
		<div class="ifthenpay-backoffice-key" data-saved="<?php echo $saved ? 'yes' : 'no'; ?>" data-refunds="<?php echo $refunds ? 'yes' : 'no'; ?>">
			<b><?php esc_html_e( 'ifthenpay Backoffice Key:', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ); ?></b>
			<p class="ifthenpay-backoffice-key-description"><?php esc_html_e( 'One key for all ifthenpay payment methods in this store. It is used to activate the Callback/Webhook, to refund MB WAY and card payments, and to read the fee ifthenpay charged on each payment.', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ); ?></p>
			<div class="ifthenpay-backoffice-key-saved">
				<code class="ifthenpay-backoffice-key-masked"><?php echo esc_html( $this->mask_backoffice_key( $key ) ); ?></code>
				<a href="#" class="el-button el-button--small is-plain ifthenpay-backoffice-key-change"><?php esc_html_e( 'Change', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ); ?></a>
				<a href="#" class="el-button el-button--small el-button--danger is-plain ifthenpay-backoffice-key-remove"><?php esc_html_e( 'Remove', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ); ?></a>
			</div>
			<div class="ifthenpay-backoffice-key-form">
				<div class="el-input ifthenpay-backoffice-key-field">
					<div class="el-input__wrapper">
						<input type="text" class="el-input__inner ifthenpay-backoffice-key-input" placeholder="0000-0000-0000-0000" maxlength="19" autocomplete="off" aria-label="<?php esc_attr_e( 'ifthenpay Backoffice Key', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ); ?>"/>
					</div>
				</div>
				<a href="#" class="el-button el-button--small el-button--primary is-plain ifthenpay-backoffice-key-save"><?php esc_html_e( 'Save', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ); ?></a>
				<a href="#" class="el-button el-button--small is-plain ifthenpay-backoffice-key-cancel"><?php esc_html_e( 'Cancel', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ); ?></a>
			</div>
			<p class="ifthenpay-backoffice-key-message" role="status"></p>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Read the fee ifthenpay charged on a paid order, if we do not have it yet.
	 *
	 * The callback brings the fee when ifthenpay sends it. When it does not (a card payment confirmed
	 * on return, or a callback without it), we ask ifthenpay's payments list by the payment's request ID,
	 * which needs the Backoffice Key. ifthenpay may not know the final fee in the first hours, so we try
	 * up to $fee_lookup_attempts times, at least an hour apart, and then give up.
	 *
	 * @param Ifthenpay_Gateway            $gateway The gateway instance.
	 * @param \FluentCart\App\Models\Order $order   The order object.
	 * @return float The fee, or 0 if it is not known.
	 */
	public function maybe_lookup_fee( $gateway, $order ) {
		$fee = floatval( $order->getMeta( $gateway->ifthenpay_id . '_fee' ) );
		if ( $fee > 0 ) {
			return $fee;
		}
		$bo_key = $this->get_backoffice_key();
		if ( $bo_key === '' || $order->payment_status !== Status::PAYMENT_PAID ) {
			return 0;
		}
		$details = $gateway->get_payment_details( $order );
		if ( empty( $details['RequestId'] ) ) {
			return 0;
		}
		$lookups = $order->getMeta( $gateway->ifthenpay_id . '_fee_lookups' );
		$lookups = is_array( $lookups ) ? $lookups : array();
		if ( count( $lookups ) >= $this->fee_lookup_attempts || ( $lookups && time() - (int) end( $lookups ) < HOUR_IN_SECONDS ) ) {
			return 0;
		}
		$lookups[] = time();
		$order->updateMeta( $gateway->ifthenpay_id . '_fee_lookups', $lookups );

		$response = wp_remote_post(
			$this->payments_api_url,
			array(
				'timeout' => apply_filters( $this->hook_prefix . 'api_timeout', 15 ),
				'headers' => array(
					'Content-Type' => 'application/json; charset=utf-8',
				),
				'body'    => wp_json_encode(
					array(
						'boKey'     => $bo_key,
						'requestId' => $details['RequestId'],
					)
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			$this->log( $gateway, 'warning', 'Fee lookup failed', 'Order: ' . $order->id . ' - ' . $response->get_error_message() );
			return 0;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ) );
		if ( empty( $body ) || ! isset( $body->status ) || (int) $body->status !== 200 ) {
			$this->log( $gateway, 'warning', 'Fee lookup failed', 'Order: ' . $order->id . ' - Response: ' . wp_remote_retrieve_body( $response ) );
			return 0;
		}
		$fee = ( isset( $body->payments ) && is_array( $body->payments ) && count( $body->payments ) === 1 && isset( $body->payments[0]->fee ) ) ? floatval( $body->payments[0]->fee ) : 0;
		if ( $fee > 0 ) {
			$order->updateMeta( $gateway->ifthenpay_id . '_fee', $fee );
			$this->log( $gateway, 'info', 'Fee lookup succeeded', 'Order: ' . $order->id . ' - Fee: ' . $fee );
		} else {
			$this->log( $gateway, 'info', 'Fee lookup without fee', 'Order: ' . $order->id . ' - Attempt ' . count( $lookups ) . ' of ' . $this->fee_lookup_attempts . ' - Payments found: ' . ( isset( $body->payments ) && is_array( $body->payments ) ? count( $body->payments ) : 0 ) );
		}
		return $fee;
	}

	/**
	 * Refund a payment, or part of it, through ifthenpay.
	 *
	 * Called by FluentCart through the gateway's processRefund(), after it has already recorded the refund
	 * on the order. Whatever we return as an error is shown on the order screen as the reason the money
	 * was not sent back, so the messages are written for the shop owner.
	 *
	 * @param Ifthenpay_Gateway                       $gateway     The gateway instance.
	 * @param \FluentCart\App\Models\OrderTransaction $transaction The paid transaction being refunded.
	 * @param int                                     $amount      The amount to refund, in cents.
	 * @return string|\WP_Error The ifthenpay request ID of the refunded payment, or the error.
	 */
	public function request_refund( $gateway, $transaction, $amount ) {
		$order  = $transaction->order;
		$bo_key = $this->get_backoffice_key();
		if ( $bo_key === '' ) {
			return new \WP_Error( 'ifthenpay_no_backoffice_key', __( 'The ifthenpay Backoffice Key is not saved. Save it in the settings of any ifthenpay payment method to refund through ifthenpay.', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ) );
		}
		$details    = $gateway->get_payment_details( $order );
		$request_id = trim( (string) ( $transaction->vendor_charge_id ? $transaction->vendor_charge_id : ( $details ? $details['RequestId'] : '' ) ) );
		if ( $request_id === '' ) {
			return new \WP_Error( 'ifthenpay_no_request_id', __( 'The ifthenpay request ID of this payment is missing, so it cannot be refunded through ifthenpay.', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ) );
		}
		$value = $this->format_transaction_value_for_api( $amount );

		$this->log( $gateway, 'info', 'Refund request', 'Order: ' . $order->id . ' - Request ID: ' . $request_id . ' - Amount: ' . $value );

		$response = wp_remote_post(
			$this->refunds_api_url,
			array(
				'timeout' => apply_filters( $this->hook_prefix . 'api_timeout', 30 ),
				'headers' => array(
					'Content-Type' => 'application/json; charset=utf-8',
				),
				'body'    => wp_json_encode(
					array(
						'backofficekey' => $bo_key,
						'requestId'     => $request_id,
						'amount'        => $value,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log( $gateway, 'error', 'Refund failed', 'Order: ' . $order->id . ' - ' . $response->get_error_message(), null );
			return new \WP_Error(
				'ifthenpay_refund_connection',
				sprintf(
					/* translators: %s: Error details */
					__( 'Could not reach ifthenpay: %s', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
					$response->get_error_message()
				)
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );
		if ( (int) wp_remote_retrieve_response_code( $response ) !== 200 || empty( $body ) || ! isset( $body->Code ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$this->log( $gateway, 'error', 'Refund failed', 'Order: ' . $order->id . ' - Unexpected response: ' . wp_remote_retrieve_response_code( $response ) . ' ' . wp_remote_retrieve_body( $response ), null );
			return new \WP_Error( 'ifthenpay_refund_response', __( 'Unexpected response from ifthenpay. The refund was not confirmed, so check it in the ifthenpay backoffice.', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ) );
		}

		$code    = trim( (string) $body->Code ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$message = isset( $body->Message ) ? trim( (string) $body->Message ) : ''; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		if ( $code === '1' ) {
			$this->log( $gateway, 'success', 'Refund succeeded', 'Order: ' . $order->id . ' - Request ID: ' . $request_id . ' - Amount: ' . $value );
			return $request_id;
		}

		$this->log( $gateway, 'error', 'Refund failed', 'Order: ' . $order->id . ' - ifthenpay code ' . $code . ': ' . $message, null );
		if ( $code === '-1' ) {
			return new \WP_Error( 'ifthenpay_refund_funds', __( 'ifthenpay could not refund it now, as there are not enough funds in your ifthenpay account. The available balance is the sum of the payments received since 20:00 of the previous day that were not yet transferred to your bank account.', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ) );
		}
		return new \WP_Error(
			'ifthenpay_refund_refused',
			sprintf(
				/* translators: %s: Message from ifthenpay */
				__( 'ifthenpay could not refund this payment: %s', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
				$message !== '' ? $message : $code
			)
		);
	}

	/**
	 * Make API call to ifthenpay payment request endpoint.
	 *
	 * @param object $gateway The gateway instance.
	 * @param object $order The order object.
	 * @param array  $payment_request_arguments The payment request arguments.
	 * @param string $expected_status The expected status code in the response (default '0').
	 * @param string $api_url The endpoint, if not the gateway's api_url.
	 * @return array The API response with status and body or error message.
	 */
	public function make_request_payment_api_call( $gateway, $order, $payment_request_arguments, $expected_status = '0', $api_url = '' ) {

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
		$response = wp_remote_post( $api_url ? $api_url : $gateway->api_url, $args );

		$this->log( $gateway, 'info', $title . ' payment request', 'Order: ' . $order->id . ' - Data: ' . $this->log_data( $payment_request_arguments ) );

		// Deal with errors - Step 1
		if ( is_wp_error( $response ) ) {
			$message = sprintf(
				/* translators: %s: Error details */
				__( 'Failed to create payment at ifthenpay API: %s', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
				$response->get_error_code() . ' - ' . $response->get_error_message()
			);
			$this->log( $gateway, 'error', 'Failed ' . $title . ' payment request', 'Order: ' . $order->id . ' - ' . $message, null );
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
			$this->log( $gateway, 'error', 'Failed ' . $title . ' payment request', 'Order: ' . $order->id . ' - ' . $message, null );
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
			$this->log( $gateway, 'error', 'Failed ' . $title . ' payment request', 'Order: ' . $order->id . ' - ' . $message, null );
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

		$data = $this->request_data();

		$this->log( $gateway, 'info', 'Webhook called', 'Data: ' . $this->log_data( $data ) );

		// Validate webhook key
		if ( ! isset( $data['webhook_key'] ) || trim( $data['webhook_key'] ) === '' || ! hash_equals( trim( $this->webhook_key ), $data['webhook_key'] ) ) {
			$this->log( $gateway, 'error', 'Webhook failed', 'Invalid webhook key - Webhook data: ' . $this->log_data( $data ), null );
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
			$this->log( $gateway, 'error', 'Webhook failed', 'Invalid data or fields missing - Webhook data: ' . $this->log_data( $data ), null );
			$this->send_callback_response( 403, 'Invalid data or fields missing', null, $data, true );
			return;
		}

		// Valid, but not something we process
		$error = $gateway->webhook_data_error( $data );
		if ( $error !== '' ) {
			$this->log( $gateway, 'warning', 'Webhook not processed', $error . ' - Webhook data: ' . $this->log_data( $data ) );
			$this->send_callback_response( 200, $error ); // We want to stop ifthenpay from retrying
			return;
		}

		// Get transaction based on request_id
		$transaction = OrderTransaction::query()
				->where( 'payment_method', $gateway->ifthenpay_id )
				->where( 'vendor_charge_id', $data['request_id'] ) // May be different based on gateway callback, OK for MB and MBWAY
				->where( 'total', $this->to_cents( $data['value'] ) ) // May be different based on gateway callback, OK for MB and MBWAY
				->orderBy( 'id', 'DESC' )
				->first();
		if ( ! $transaction ) {
			$this->log( $gateway, 'error', 'Webhook failed', 'Transaction not found - Webhook data: ' . $this->log_data( $data ), null );
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
		$payment_details = $gateway->get_payment_details( $order );
		if ( empty( $payment_details ) ) {
			$this->log( $gateway, 'error', 'Webhook failed', 'Order found but no payment details are recorded on it - Order ID: ' . $order->id . ' - Webhook data: ' . $this->log_data( $data ), null );
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
			$this->log( $gateway, 'error', 'Webhook failed', 'Order found but payment details do not match - Order ID: ' . $order->id . ' - Webhook data: ' . $this->log_data( $data ) . ' - Payment Details: ' . $this->log_data( $payment_details ), null );
			$this->send_callback_response( 200, 'Order found but payment details do not match' ); // Should be 404 but we want to stop ifthenpay from retrying
			return;
		}

		// Set the transaction and order as paid, unless another callback, or the customer's return, already did
		if ( ! $this->mark_transaction_paid( $gateway, $transaction, isset( $data['payment_datetime'] ) ? $data['payment_datetime'] : '', isset( $data['payment_fee'] ) ? $data['payment_fee'] : 0 ) ) {
			$this->log( $gateway, 'warning', 'Webhook failed', 'Transaction already being processed - Transaction ID: ' . $transaction->id . ' - Order ID: ' . $transaction->order_id );
			$this->send_callback_response( 200, 'Transaction found but not pending payment' );
			return;
		}

		$this->log( $gateway, 'success', 'Webhook succeeded', 'Order found and payment processed successfully - Order ID: ' . $order->id );

		$this->send_callback_response( 200, 'Order found and payment processed successfully' );
	}

	/**
	 * The request query string, sanitized.
	 * Only scalar values, as ifthenpay only sends those. Keys go through sanitize_key(), so they are lowercase.
	 *
	 * @return array The data.
	 */
	public function request_data() {
		$data = array();
		foreach ( $_GET as $key => $value ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( is_scalar( $value ) ) {
				$data[ sanitize_key( $key ) ] = sanitize_text_field( wp_unslash( (string) $value ) );
			}
		}
		return $data;
	}

	/**
	 * Set a pending transaction, and its order, as paid.
	 *
	 * Used by the webhook and by the customer's return from a payment page, whichever comes first.
	 *
	 * @param Ifthenpay_Gateway                       $gateway          The gateway instance.
	 * @param \FluentCart\App\Models\OrderTransaction $transaction      The transaction object.
	 * @param string                                  $payment_datetime The payment date/time as sent by ifthenpay, in Lisbon time.
	 * @param mixed                                   $fee              The ifthenpay fee, if sent.
	 * @return bool False if the transaction was no longer pending.
	 */
	public function mark_transaction_paid( $gateway, $transaction, $payment_datetime = '', $fee = 0 ) {
		$order = $transaction->order;

		// Claim the transaction, so the same payment reported twice at the same time is only processed once
		$claimed = OrderTransaction::query()
				->where( 'id', $transaction->id )
				->where( 'status', Status::TRANSACTION_PENDING )
				->update( array( 'status' => Status::TRANSACTION_SUCCEEDED ) );
		if ( ! $claimed ) {
			return false;
		}

		// Set transaction and order as paid
		$transaction->status = Status::TRANSACTION_SUCCEEDED;

		// Record when the money actually moved, not when we heard about it.
		// FluentCart stamps meta.settled_at with the current time when a transaction
		// first turns "succeeded", but only if a gateway has not set it already. For
		// Multibanco the customer may pay at an ATM long before the webhook arrives,
		// or the webhook may be retried, so the time ifthenpay reports is the correct
		// one. It has to be written before save() for FluentCart's fallback to stand down.
		$settled_at = $this->parse_ifthenpay_datetime( $payment_datetime, $gateway );
		if ( $settled_at ) {
			$meta               = $transaction->meta;
			$meta               = is_array( $meta ) ? $meta : array();
			$meta['settled_at'] = $settled_at;
			$transaction->meta  = $meta;
		}

		$transaction->save();
		// FluentCart only runs the cart completion actions for a cart it completes itself, not for one we completed when the order was placed
		$cart_open = Cart::query()->where( 'order_id', $order->id )->where( 'stage', '!=', 'completed' )->exists();
		( new StatusHelper( $order ) )->syncOrderStatuses( $transaction );
		if ( ! $cart_open ) {
			$this->run_cart_completed_actions( $order, $transaction );
		}

		// Store ifthenpay fee, if sent
		if ( floatval( $fee ) > 0 ) {
			$order->updateMeta( $gateway->ifthenpay_id . '_fee', floatval( $fee ) );
		}

		do_action( $this->hook_prefix . 'payment_completed', $gateway->ifthenpay_id, $order, $transaction );

		return true;
	}

	/**
	 * Convert a date/time reported by ifthenpay into UTC, for storage.
	 *
	 * Payments are always reported by ifthenpay in Lisbon local time, which is UTC+0
	 * in winter and UTC+1 in summer, so the offset cannot be hardcoded. FluentCart stores every
	 * date in UTC (see FluentCart\App\Services\DateTime\DateTime::gmtNow), so the
	 * value is converted rather than stored as it arrives. Anything we cannot parse
	 * with certainty returns an empty string, and FluentCart falls back to stamping
	 * the current time itself.
	 *
	 * @param string $datetime The date/time as sent by ifthenpay, in Lisbon time.
	 * @param object $gateway  The gateway instance, for logging.
	 * @return string The date/time in UTC as 'Y-m-d H:i:s', or '' if it could not be parsed.
	 */
	public function parse_ifthenpay_datetime( $datetime, $gateway ) {
		$datetime = trim( (string) $datetime );
		if ( $datetime === '' ) {
			return '';
		}

		// ifthenpay sends 'Y-m-d H:i:s', or 'd-m-Y H:i:s' for credit card according to
		// its callback documentation. Parsed strictly, so that a format change on
		// their side is noticed as a missing settlement time rather than silently
		// becoming a wrong one. The date is also rejected if PHP had to correct it
		// (an impossible date such as 2026-02-30 rolls over instead of failing).
		$parsed = false;
		foreach ( array( 'Y-m-d H:i:s', 'd-m-Y H:i:s' ) as $format ) {
			$attempt = \DateTime::createFromFormat(
				$format,
				$datetime,
				new \DateTimeZone( 'Europe/Lisbon' )
			);
			if ( $attempt && $attempt->format( $format ) === $datetime ) {
				$parsed = $attempt;
				break;
			}
		}
		if ( ! $parsed ) {
			$this->log(
				$gateway,
				'warning',
				'Unexpected ifthenpay date format',
				'Could not read the payment date/time sent by ifthenpay: ' . $datetime
			);
			return '';
		}

		$parsed->setTimezone( new \DateTimeZone( 'UTC' ) );
		return $parsed->format( 'Y-m-d H:i:s' );
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
	 * Kept for backwards compatibility, each gateway knows its own details.
	 *
	 * @param string                       $payment_method The payment method ID.
	 * @param \FluentCart\App\Models\Order $order The order object.
	 * @return array|false The payment details or false if not found.
	 */
	public function get_payment_details( $payment_method, $order ) {
		$gateway = $this->get_gateway( $payment_method );
		return $gateway ? $gateway->get_payment_details( $order ) : false;
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
				// Subscriptions are not supported by any of our payment methods.
				// FluentCart's own SubscriptionGatewayGate already hides gateways that
				// do not declare the "subscriptions" feature on subscription carts, but
				// only while the store is in the default "gateway managed" mode. Under
				// "store managed" mode, or with "manual fallback" enabled, it admits
				// one-time gateways so the store can invoice each renewal itself, and
				// we would be offered for a subscription we cannot fulfil. Since neither
				// Multibanco nor MB WAY can be charged again without the customer acting,
				// we opt out of every subscription cart, whatever the store setting.
				if (
					isset( $args['cart'] )
					&& method_exists( $args['cart'], 'hasSubscription' )
					&& $args['cart']->hasSubscription()
				) {
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
					$min_value = $this->to_cents( $gateway->min_value );
					$max_value = $this->to_cents( $gateway->max_value );
					if ( $cart_total < $min_value || $cart_total > $max_value ) {
						unset( $active_payment_methods[ $index ] );
						break;
					}
				}
				// By settings "from" value
				if ( ! empty( $gateway->settings->get( 'only_from' ) ) && floatval( $gateway->settings->get( 'only_from' ) ) > 0 ) {
					$only_from = $this->to_cents( $gateway->settings->get( 'only_from' ) );
					if ( $cart_total < $only_from ) {
						unset( $active_payment_methods[ $index ] );
						break;
					}
				}
				// By settings "up to" value
				if ( ! empty( $gateway->settings->get( 'only_up_to' ) ) && floatval( $gateway->settings->get( 'only_up_to' ) ) > 0 ) {
					$only_up_to = $this->to_cents( $gateway->settings->get( 'only_up_to' ) );
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
	 * Convert a value in euros to cents, as FluentCart stores amounts.
	 * Rounded, not truncated: a float such as 19.99 * 100 is 1998.999...
	 *
	 * @param mixed $value The value in euros.
	 * @return int The value in cents.
	 */
	public function to_cents( $value ) {
		return (int) round( floatval( $value ) * 100 );
	}

	/**
	 * Check if an ifthenpay key is in the AAA-000000 format (three letters, a hyphen and six digits).
	 *
	 * @param mixed $key The key.
	 * @return bool
	 */
	public function is_valid_key( $key ) {
		return (bool) preg_match( '/^[A-Za-z]{3}-[0-9]{6}$/', trim( (string) $key ) );
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
	 * The store's date or date and time format.
	 * FluentCart's own setting (WordPress formats or its own), falling back to WordPress
	 * on FluentCart versions without DateFormatter.
	 *
	 * @param bool $with_time Whether to include the time.
	 * @return string The format.
	 */
	public function date_format( $with_time = false ) {
		if ( class_exists( '\FluentCart\App\Services\DateTime\DateFormatter' ) ) {
			$formats = \FluentCart\App\Services\DateTime\DateFormatter::formats();
			$key     = $with_time ? 'date_time' : 'date';
			if ( ! empty( $formats[ $key ] ) ) {
				return (string) $formats[ $key ];
			}
		}
		return $with_time ? get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) : (string) get_option( 'date_format' );
	}

	/**
	 * Format a date for display, with the store's date format.
	 *
	 * A date with no time, such as the Multibanco expiration ifthenpay returns as d-m-Y, is
	 * formatted without any timezone conversion, which could otherwise show the previous day.
	 * A date and time stored in the site's timezone (Y-m-d H:i:s) is shown in the store's display timezone.
	 *
	 * @param string                            $date        The date, as stored.
	 * @param string                            $from_format The format it is stored in.
	 * @param \FluentCart\App\Models\Order|null $order       The order, for FluentCart's per order timezone.
	 * @return string The formatted date, or the date as stored if it cannot be read.
	 */
	public function format_date( $date, $from_format = 'd-m-Y', $order = null ) {
		$date      = trim( (string) $date );
		$with_time = strpos( $from_format, 'H' ) !== false;
		$source_tz = $with_time ? wp_timezone() : new \DateTimeZone( 'UTC' );
		$parsed    = \DateTime::createFromFormat( '!' . $from_format, $date, $source_tz );
		if ( $date === '' || ! $parsed || $parsed->format( $from_format ) !== $date ) {
			return $date;
		}
		$display_tz = $source_tz;
		if ( $with_time && class_exists( '\FluentCart\App\Services\DateTime\DateFormatter' ) ) {
			$display_tz = \FluentCart\App\Services\DateTime\DateFormatter::displayTimezone( $order );
		}
		return (string) wp_date( $this->date_format( $with_time ), $parsed->getTimestamp(), $display_tz );
	}

	/**
	 * Format MB reference - We keep it public because someone may be using it externally
	 *
	 * @param  string $ref Multibanco reference.
	 * @return string
	 */
	public function format_multibanco_ref( $ref ) {
		$ref = implode( '&nbsp;', str_split( trim( (string) $ref ), 3 ) );
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
	 * Settings field for refunds through ifthenpay.
	 * Disabled, and saying why, while the Backoffice Key is not saved.
	 *
	 * @return array The settings field configuration.
	 */
	public function settings_field_do_refunds() {
		$has_key = $this->get_backoffice_key() !== '';
		return array(
			'type'     => 'checkbox',
			'label'    => $has_key ? __( 'Process refunds through ifthenpay', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ) : __( 'Process refunds through ifthenpay (inactive until the ifthenpay Backoffice Key is saved)', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
			'tooltip'  => __( 'When on, refunding an order in FluentCart also sends the money back to the customer through ifthenpay. When off, FluentCart only records the refund, and you refund the customer yourself.', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
			'disabled' => ! $has_key,
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
	 * Validate the settings a gateway is about to be activated with.
	 *
	 * FluentCart calls each gateway's static validateSettings() from
	 * AbstractPaymentGateway::updateSettings(), but only when "is_active" is being
	 * set to "yes". Returning a "failed" status blocks the save with a 422 and shows
	 * the message, which is the only chance we get to tell the shop owner why the
	 * payment method would never appear at checkout.
	 *
	 * Every ifthenpay key follows the same AAA-000000 shape: three letters, a hyphen
	 * and six digits, the same is_valid_key() check requirements_met() applies at checkout.
	 *
	 * @param array  $data      The settings being saved.
	 * @param string $key_field The settings key holding the ifthenpay key.
	 * @param string $key_label The human readable name of that key.
	 * @return array The validation result.
	 */
	public function validate_gateway_settings( $data, $key_field, $key_label ) {
		// Store currency
		if ( ! $this->requirements_met() ) {
			return array(
				'status'  => 'failed',
				'message' => __( 'Your store currency is not set to EUR. This payment method only supports EUR transactions.', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
			);
		}

		// Gateway key
		$key = isset( $data[ $key_field ] ) ? trim( $data[ $key_field ] ) : '';
		if ( $key === '' ) {
			return array(
				'status'  => 'failed',
				'message' => sprintf(
					/* translators: %s: type of key */
					__( 'Please enter the %s provided by ifthenpay.', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
					$key_label
				),
			);
		}
		if ( ! $this->is_valid_key( $key ) ) {
			return array(
				'status'  => 'failed',
				'message' => sprintf(
					/* translators: 1: type of key, 2: example of a key */
					__( 'The %1$s does not look valid. It should be three letters, a hyphen and six digits, like %2$s.', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
					$key_label,
					'AAA-000000'
				),
			);
		}

		return array(
			'status'  => 'success',
			'message' => __( 'Settings saved successfully', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
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
	 * Payment details panel on the FluentCart admin order screen.
	 *
	 * Informative, like the ifthenpay metabox on WooCommerce orders. While debugging (WP_DEBUG on and the
	 * payment method's debug log enabled), a pending order also gets a button that calls our own callback
	 * URL with this order's details, to test the whole payment confirmation path.
	 *
	 * @param array $widgets The widgets.
	 * @param array $data    The request data, with 'order'.
	 * @return array The widgets.
	 */
	public function order_widget( $widgets, $data ) {
		$order   = isset( $data['order'] ) ? $data['order'] : null;
		$gateway = $order instanceof Order ? $this->get_gateway( $order->payment_method ) : null;
		if ( ! $gateway ) {
			return $widgets;
		}

		$rows = $gateway->payment_instructions_rows( $order );
		unset( $rows['action_html'] );
		$content = '';
		if ( empty( $rows ) ) {
			$content .= '<p>' . esc_html__( 'The payment details are missing from this order.', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ) . '</p>';
		} else {
			$details = $gateway->get_payment_details( $order );
			if ( ! empty( $details['RequestId'] ) ) {
				$rows[ __( 'ifthenpay request ID', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ) ] = esc_html( $details['RequestId'] );
			}
			$pending = in_array( $order->payment_status, array( Status::PAYMENT_PENDING, Status::PAYMENT_PARTIALLY_PAID ), true );
			$status  = $pending ? __( 'Waiting for payment', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ) : Status::getPaymentStatuses()[ $order->payment_status ] ?? $order->payment_status;
			if ( ! $pending ) {
				$transaction = OrderTransaction::query()->where( 'order_id', $order->id )->where( 'payment_method', $gateway->ifthenpay_id )->where( 'status', Status::TRANSACTION_SUCCEEDED )->orderBy( 'id', 'DESC' )->first();
				$settled_at  = $transaction && is_array( $transaction->meta ) && ! empty( $transaction->meta['settled_at'] ) ? $transaction->meta['settled_at'] : '';
				if ( $settled_at ) {
					$status .= ' - ' . $this->format_date( get_date_from_gmt( $settled_at ), 'Y-m-d H:i:s', $order );
				}
			}
			$rows[ __( 'Payment status', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ) ] = esc_html( $status );
			$fee = $pending ? 0 : $this->maybe_lookup_fee( $gateway, $order );
			if ( ! empty( $fee ) ) {
				$rows[ __( 'ifthenpay fee', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ) ] = $this->format_price( $fee, true, 'EUR' );
			}
			$content .= '<table class="ifthenpay-order-widget-details">';
			foreach ( $rows as $title => $value ) {
				$content .= '<tr><td>' . esc_html( $title ) . ':</td><td>' . wp_kses_post( $value ) . '</td></tr>';
			}
			$content .= '</table>';

			// Testing tool
			if ( $pending && defined( 'WP_DEBUG' ) && WP_DEBUG && in_array( $gateway->settings->get( 'debug' ), array( 'yes', 'yes_email' ), true ) ) {
				$url = $this->simulated_callback_url( $gateway, $order );
				if ( $url ) {
					$content .= '<p class="ifthenpay-order-widget-test"><a href="#" class="el-button el-button--warning is-plain ifthenpay-simulate-callback" data-url="' . esc_attr( $url ) . '">' . esc_html__( 'Simulate callback payment', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ) . '</a></p>';
					$content .= '<p class="ifthenpay-order-widget-test-note">' . esc_html__( 'Shown because WP_DEBUG and this payment method’s debug log are on.', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ) . '</p>';
				}
			}
		}

		$meta      = $gateway->meta();
		$widgets[] = array(
			'title'     => $meta['title'],
			'sub_title' => '',
			'type'      => 'html',
			'content'   => '<div class="ifthenpay-order-widget"><p class="ifthenpay-order-widget-logo">' . $this->inline_banner( $gateway, 32 ) . '</p>' . $content . '</div>',
		);
		return $widgets;
	}

	/**
	 * The payment method banner as inline SVG, for the admin.
	 *
	 * The parts of the banner without a colour of their own (the wordmark) are black, and
	 * assets/admin.css turns them white in FluentCart's dark mode. Nothing else is allowed.
	 * The brand coloured parts keep their own fill.
	 *
	 * @param Ifthenpay_Gateway $gateway The gateway instance.
	 * @param int               $height  The height in pixels.
	 * @return string The SVG, or an img tag if the file cannot be read.
	 */
	public function inline_banner( $gateway, $height = 32 ) {
		$meta = $gateway->meta();
		$file = dirname( NAKEDCATPLUGINS_IFTHENPAY_FLUENTCART_FILE ) . '/images/payment-gateways/' . $gateway->ifthenpay_short_id . '-banner.svg';
		$svg  = file_exists( $file ) ? file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file
		if ( strpos( (string) $svg, '<svg ' ) !== 0 ) {
			return '<img src="' . esc_url( $meta['ifthenpay_banner'] ) . '" alt="' . esc_attr( $meta['title'] ) . '" class="ifthenpay-banner-img" height="' . (int) $height . '"/>';
		}
		return preg_replace(
			'/^<svg /',
			'<svg class="ifthenpay-banner-svg" fill="#000000" role="img" aria-label="' . esc_attr( $meta['title'] ) . '" height="' . (int) $height . '" ',
			trim( $svg ),
			1
		);
	}

	/**
	 * The callback URL ifthenpay would call when this order is paid, with its real details.
	 * Used by the "Simulate callback payment" testing tool.
	 *
	 * @param Ifthenpay_Gateway            $gateway The gateway instance.
	 * @param \FluentCart\App\Models\Order $order   The order object.
	 * @return string The URL, or an empty string if the order has no payment details.
	 */
	public function simulated_callback_url( $gateway, $order ) {
		$details = $gateway->get_payment_details( $order );
		if ( ! $details ) {
			return '';
		}
		$values = array(
			'[ANTI_PHISHING_KEY]' => $this->webhook_key,
			'[REQUEST_ID]'        => $details['RequestId'],
			'[AMOUNT]'            => $details['val'],
			'[ENTITY]'            => isset( $details['ent'] ) ? $details['ent'] : '',
			'[REFERENCE]'         => isset( $details['ref'] ) ? $details['ref'] : '',
			'[ORDER_ID]'          => (string) $order->id,
			'[ID]'                => (string) $order->id,
			'[STATUS]'            => 'PAGO',
			'[PAYMENT_DATETIME]'  => ( new \DateTime( 'now', new \DateTimeZone( 'Europe/Lisbon' ) ) )->format( 'Y-m-d H:i:s' ),
			'[FEE]'               => '0',
		);
		$url    = $gateway->webhook_url;
		foreach ( $values as $placeholder => $value ) {
			$url = str_replace( $placeholder, rawurlencode( $value ), $url );
		}
		// Same scheme as the admin page, so the browser can call it
		return set_url_scheme( $url );
	}

	/**
	 * Add our smartcodes to the FluentCart email editor.
	 *
	 * @param array $groups The smartcode groups.
	 * @return array The smartcode groups.
	 */
	public function editor_shortcodes( $groups ) {
		$groups['ifthenpay'] = array(
			'title'      => 'ifthenpay',
			'key'        => 'ifthenpay',
			'shortcodes' => array(
				'{{ifthenpay.payment_instructions}}' => __( 'Payment instructions (Multibanco and MB WAY)', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
			),
		);
		return $groups;
	}

	/**
	 * Resolve our smartcodes, which FluentCart does not know about.
	 *
	 * {{ifthenpay.payment_instructions}} prints the payment instructions while the order
	 * is waiting for a Multibanco or MB WAY payment, and nothing otherwise, so it can be
	 * added to any email.
	 *
	 * @param string $code The smartcode, without braces.
	 * @param mixed  $data The data the email is parsed with.
	 * @return string The value.
	 */
	public function smartcode_fallback( $code, $data ) {
		if ( ! is_string( $code ) || trim( $code ) !== 'ifthenpay.payment_instructions' ) {
			return $code;
		}
		$order = is_array( $data ) && isset( $data['order'] ) ? $data['order'] : null;
		if ( is_array( $order ) && isset( $order['id'] ) ) {
			$order = Order::find( $order['id'] );
		}
		if ( ! $order instanceof Order ) {
			return '';
		}
		return $this->email_payment_instructions( $order );
	}

	/**
	 * Payment instructions for an email.
	 *
	 * @param \FluentCart\App\Models\Order $order The order object.
	 * @return string The HTML, or an empty string if the order is not waiting for one of our payments.
	 */
	public function email_payment_instructions( $order ) {
		$gateway = $this->get_gateway( $order->payment_method );
		if ( ! $gateway || ! $gateway->email_instructions || ! in_array( $order->payment_status, array( Status::PAYMENT_PENDING, Status::PAYMENT_PARTIALLY_PAID ), true ) ) {
			return '';
		}
		$rows = $gateway->payment_instructions_rows( $order );
		unset( $rows['action_html'] );
		if ( empty( $rows ) ) {
			return '';
		}
		$meta  = $gateway->meta();
		$color = $meta['brand_color'];
		ob_start();
		// Same layout as the WooCommerce plugin's email instructions
		?>
		<table cellpadding="10" cellspacing="0" align="center" border="0" style="margin: auto; margin-top: 2em; margin-bottom: 2em; border-collapse: collapse; border: 1px solid <?php echo esc_attr( $color ); ?>; background-color: #FFFFFF; font-size: 14px;">
			<tr>
				<td colspan="2" style="border: 1px solid <?php echo esc_attr( $color ); ?>; padding: 16px; text-align: center; color: #000000; font-weight: bold;">
					<?php esc_html_e( 'Payment instructions', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ); ?>
					<br/>
					<?php
					// The PNG is twice the displayed size (96px for 48px), so it stays sharp on high density screens. The width and height attributes are for
					// clients that ignore max-height (desktop Outlook), and max-width lets the logo shrink with a narrow table.
					$banner_file = dirname( NAKEDCATPLUGINS_IFTHENPAY_FLUENTCART_FILE ) . '/images/payment-gateways/' . $gateway->ifthenpay_short_id . '-banner.png';
					$banner_size = file_exists( $banner_file ) ? getimagesize( $banner_file ) : false;
					?>
					<img src="<?php echo esc_url( $meta['ifthenpay_banner_email'] ); ?>" alt="<?php echo esc_attr( $meta['title'] ); ?>" title="<?php echo esc_attr( $meta['title'] ); ?>"
					<?php
					if ( $banner_size ) {
						?>
						width="<?php echo esc_attr( (int) round( $banner_size[0] / 2 ) ); ?>" height="<?php echo esc_attr( (int) round( $banner_size[1] / 2 ) ); ?>"<?php } ?> style="display: block; margin: 16px auto 0 auto; max-width: 100%; max-height: 48px; width: auto; height: auto;"/>
				</td>
			</tr>
			<?php foreach ( $rows as $title => $value ) { ?>
				<tr>
					<td style="border-top: 1px solid <?php echo esc_attr( $color ); ?>; color: #000000;"><?php echo esc_html( $title ); ?>:</td>
					<td style="border-top: 1px solid <?php echo esc_attr( $color ); ?>; color: #000000; white-space: nowrap; text-align: right;"><?php echo wp_kses_post( $value ); ?></td>
				</tr>
			<?php } ?>
		</table>
		<?php
		return apply_filters( $this->hook_prefix . 'email_payment_instructions', ob_get_clean(), $order, $rows );
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

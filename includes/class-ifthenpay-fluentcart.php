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
	 * Plugin version.
	 *
	 * @var string
	 */
	private $version = '';

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
		// Load admin JS
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
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
	 *
	 * @param GatewayManager $gateway_manager The gateway manager instance.
	 */
	public function register_payment_gateways( $gateway_manager ) {
		// Multibanco
		require_once 'payment-gateways/multibanco/class-ifthenpay-multibanco.php';
		fluent_cart_api()->registerCustomPaymentMethod( 'ifthenpay-multibanco', new Ifthenpay_Multibanco() );
	}

	/**
	 * Add plugin links to the plugin page.
	 *
	 * @param array $links The existing plugin links.
	 * @return array The modified plugin links.
	 */
	public function add_plugin_links( $links ) {
		$gateways  = array(
			'ifthenpay-multibanco' => esc_html__( 'Multibanco', 'multibanco-ifthenpay-for-fluentcart' ),
		);
		$our_links = array();
		foreach ( $gateways as $gateway_id => $gateway_name ) {
			$our_links[] = '<a href="' . admin_url( 'admin.php?page=fluent-cart#/settings/payments/' . $gateway_id ) . '">'
			.
			sprintf(
				/* translators: %s: Payment method */
				esc_html__( '%s settings', 'multibanco-ifthenpay-for-fluentcart' ),
				esc_html( $gateway_name )
			)
			.
			'</a>';
		}
		$our_links = array_merge(
			$our_links,
			array(
				// Tech support
				'<a href="https://wordpress.org/plugins/multibanco-ifthenpay-for-fluentcart/" target="_blank" rel="noopener">' . esc_html__( 'Get support', 'multibanco-ifthenpay-for-fluentcart' ) . '</a>',
			)
		);
		return array_merge( $our_links, $links );
	}



	/**
	 * Enqueue admin scripts.
	 *
	 * @param string $hook The current admin page hook.
	 */
	public function admin_enqueue_scripts( $hook ) {
		if ( $hook === 'toplevel_page_fluent-cart' ) {
			wp_enqueue_script(
				'ifthenpay-fluentcart-admin',
				plugins_url( 'assets/admin.js', NAKEDCATPLUGINS_IFTHENPAY_FLUENTCART_FILE ),
				array( 'jquery' ),
				$this->get_version() . ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '.' . time() : '' ),
				true
			);
			wp_localize_script(
				'ifthenpay-fluentcart-admin',
				'ifthenpayFluentCart',
				array(
					'text_enter_bo_key' => esc_html__( 'Please enter your ifthenpay Backoffice Key to activate the webhook for', 'multibanco-ifthenpay-for-fluentcart' ),
					'nonce'             => wp_create_nonce( 'ifthenpay_webhook_activation' ),
				)
			);
		}
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
					margin: 0.5rem 0;
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
	public function thank_you_css( $payment_method = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
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
				'timeout' => apply_filters( $this->filter_prefix . 'api_timeout', 15 ),
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( 'Connection failed: ' . $response->get_error_message() );
		}

		$body = wp_remote_retrieve_body( $response );

		if ( intval( $response['response']['code'] ) === 200 ) {
			$this->set_setting( $gateway . '_webhook_activated', date_i18n( 'Y-m-d H:i:s' ) );
			$this->set_setting( $gateway . '_webhook_activated_key', $subent );
			wp_send_json_success( __( 'Webhook/Callback activated successfully', 'multibanco-ifthenpay-for-fluentcart' ) );
		} else {
			wp_send_json_error( $body ?? __( 'Webhook/Callback activation failed', 'multibanco-ifthenpay-for-fluentcart' ) );
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
		$order_metas = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
				__( '%s provided by ifthenpay when signing the contract.', 'multibanco-ifthenpay-for-fluentcart' ),
				$label
			),
			// 'maxLength' => 10, // Not working
			// 'size'      => 12,
		);
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
				'label' => __( 'Disabled', 'multibanco-ifthenpay-for-fluentcart' ),
			),
			array(
				'value' => 'yes',
				'label' => __( 'Enabled', 'multibanco-ifthenpay-for-fluentcart' ),
			),
			array(
				'value' => 'yes_email',
				'label' => __( 'Enabled (and send important events to email)', 'multibanco-ifthenpay-for-fluentcart' ),
			),
		);
		return array(
			'type'    => 'select',
			'label'   => __( 'Debug mode', 'multibanco-ifthenpay-for-fluentcart' ),
			'tooltip' => __( 'Log additional information for debugging purposes.', 'multibanco-ifthenpay-for-fluentcart' ),
			'options' => $debug_options,
		);
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

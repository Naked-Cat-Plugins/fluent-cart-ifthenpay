<?php
/**
 * Base class for the ifthenpay Payment Gateways for FluentCart
 */

namespace NakedCatPlugins\IfthenpayFluentCart;

use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;
use FluentCart\App\Services\Payments\PaymentHelper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ifthenpay Payment Gateway base Class
 *
 * Everything our payment methods have in common. Each payment method extends it,
 * sets the properties and constants below, and implements the abstract methods.
 */
abstract class Ifthenpay_Gateway extends AbstractPaymentGateway {

	/**
	 * Settings key holding the ifthenpay key for this payment method.
	 *
	 * @var string
	 */
	const KEY_FIELD = '';

	/**
	 * ifthenpay entity, used when activating the Callback/Webhook.
	 *
	 * @var string
	 */
	const IFTHENPAY_ENTITY = '';

	/**
	 * Gateway ID.
	 *
	 * @var string
	 */
	public $ifthenpay_id = '';

	/**
	 * Gateway Short ID.
	 * Used for the image file names.
	 *
	 * @var string
	 */
	public $ifthenpay_short_id = '';

	/**
	 * Webhook URL for payment notifications.
	 *
	 * @var string
	 */
	public $webhook_url = '';

	/**
	 * API URL for the ifthenpay payment request.
	 *
	 * @var string
	 */
	public $api_url = '';

	/**
	 * Minimum transaction value supported by this gateway.
	 *
	 * @var float
	 */
	public $min_value = 0.01;

	/**
	 * Maximum transaction value supported by this gateway.
	 *
	 * @var float
	 */
	public $max_value = 99999.99;

	/**
	 * Features supported by this gateway.
	 * Not in Snake Case because required by FluentCart.
	 *
	 * @var array
	 */
	public array $supportedFeatures = array( // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase
		'payment',
		'webhook',
		// phpcs:disable Squiz.PHP.CommentedOutCode.Found
		// 'refund', // We will support refunds in the future
		// 'subscriptions',
		// 'custom_payment',
		// 'card_update',
		// 'switch_payment_method',
		// 'dispute_handler',
		// phpcs:enable
	);

	/**
	 * The gateway settings.
	 *
	 * @var BaseGatewaySettings
	 */
	public BaseGatewaySettings $settings;

	/**
	 * Brand color.
	 *
	 * @var string
	 */
	protected $brand_color = '';

	/**
	 * Payment method name for the debug log (not translated).
	 *
	 * @var string
	 */
	protected $log_name = '';

	/**
	 * Status ifthenpay returns on a successful payment request.
	 *
	 * @var string
	 */
	protected $api_success_status = '0';

	/**
	 * Payment details stored on the order meta.
	 *
	 * @var array
	 */
	protected $payment_details_keys = array();

	/**
	 * Payment details that must be filled for the details to be considered complete.
	 *
	 * @var array
	 */
	protected $payment_details_required = array();

	/**
	 * Webhook URL attributes specific to this payment method, as $attribute => ifthenpay placeholder.
	 *
	 * @var array
	 */
	protected $webhook_attributes = array();

	/**
	 * Required data on webhook.
	 *
	 * @var array
	 */
	protected $webhook_required_data = array();

	/**
	 * Matching data between payment details stored and webhook data, as $payment_key => $data_key.
	 *
	 * @var array
	 */
	protected $webhook_matching_data = array();

	/**
	 * Whether the cart is completed as soon as the payment is requested.
	 * Payment methods that send the customer away to pay keep it open, so a customer who
	 * gives up can go back to the checkout, and FluentCart completes it on payment.
	 *
	 * @var bool
	 */
	protected $complete_cart_on_request = true;

	/**
	 * Whether the payment instructions go in the order emails.
	 *
	 * @var bool
	 */
	public $email_instructions = true;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			new Ifthenpay_Gateway_Settings(
				'fluent_cart_payment_settings_' . str_replace( '-', '_', $this->ifthenpay_id ),
				$this->settings_defaults()
			)
		);
		// Set webhook URL
		$attributes        = array_merge(
			array(
				'fluent-cart' => 'fct_payment_listener_ipn',
				'method'      => $this->ifthenpay_id,
				'plugin'      => 'webdados-ifthenpay-fluentcart',
				'webhook_key' => '[ANTI_PHISHING_KEY]',
			),
			$this->webhook_attributes
		);
		$this->webhook_url = add_query_arg( $attributes, site_url() );
	}

	/**
	 * The ifthenpay key name, for the settings and messages.
	 *
	 * @return string
	 */
	abstract public static function key_label();

	/**
	 * The payment method name, for the settings and messages.
	 *
	 * @return string
	 */
	abstract public static function method_name();

	/**
	 * The payment method title.
	 *
	 * @return string
	 */
	abstract protected function title();

	/**
	 * The payment method description.
	 *
	 * @return string
	 */
	abstract protected function description();

	/**
	 * Default settings specific to this payment method.
	 *
	 * @return array
	 */
	abstract protected function settings_defaults();

	/**
	 * Build the arguments for the ifthenpay payment request.
	 *
	 * @param \FluentCart\App\Models\Order            $order       The order object.
	 * @param string                                  $key         The ifthenpay key.
	 * @param string                                  $value       The value, formatted for the API.
	 * @param \FluentCart\App\Models\OrderTransaction $transaction The transaction object.
	 * @return array The payment request arguments.
	 */
	abstract protected function build_payment_request( $order, $key, $value, $transaction );

	/**
	 * Build the payment details to store on the order, from the ifthenpay response.
	 *
	 * @param object $body  The ifthenpay response body.
	 * @param string $key   The ifthenpay key.
	 * @param string $value The value, formatted for the API.
	 * @return array The payment details, including 'RequestId'.
	 */
	abstract protected function build_payment_details( $body, $key, $value );

	/**
	 * Rows to show on the thank you page while the payment is pending.
	 *
	 * @param \FluentCart\App\Models\Order $order           The order object.
	 * @param array                        $payment_details The payment details.
	 * @return array The rows, as $title => $value, plus an optional 'action_html'.
	 */
	abstract protected function thank_you_page_pending_rows( $order, $payment_details );

	/**
	 * Settings fields specific to this payment method, shown after the key.
	 *
	 * @return array The settings fields.
	 */
	protected function extra_fields() {
		return array();
	}

	/**
	 * Checkout fields specific to this payment method, shown after the description.
	 */
	protected function checkout_fields() {
	}

	/**
	 * Runs after the payment request succeeded and its details were stored on the order.
	 *
	 * @param \FluentCart\App\Services\Payments\PaymentInstance $payment_instance The payment instance.
	 */
	protected function after_payment_request( $payment_instance ) {
	}

	/**
	 * The ifthenpay endpoint for the payment request.
	 *
	 * @param string $key The ifthenpay key.
	 * @return string The URL.
	 */
	protected function payment_api_url( $key ) {
		return $this->api_url;
	}

	/**
	 * Where the customer goes once the payment is requested.
	 *
	 * @param \FluentCart\App\Services\Payments\PaymentInstance $payment_instance The payment instance.
	 * @param array                                             $details          The payment details stored on the order.
	 * @return string The URL, or an empty string if there is nowhere to go.
	 */
	protected function payment_redirect_url( $payment_instance, $details ) {
		return ( new PaymentHelper( $this->ifthenpay_id ) )->successUrl( $payment_instance->transaction->uuid );
	}

	/**
	 * Reason not to process a webhook that is otherwise valid, for payment methods that report more than payments.
	 *
	 * @param array $data The webhook data.
	 * @return string The reason, or an empty string to process it.
	 */
	public function webhook_data_error( $data ) {
		return '';
	}

	/**
	 * Payment instructions rows for a pending order, as shown on the thank you page.
	 *
	 * @param \FluentCart\App\Models\Order $order The order object.
	 * @return array The rows, or an empty array if the order has no payment details.
	 */
	public function payment_instructions_rows( $order ) {
		$payment_details = $this->get_payment_details( $order );
		return $payment_details ? $this->thank_you_page_pending_rows( $order, $payment_details ) : array();
	}

	/**
	 * The main plugin class.
	 *
	 * @return Ifthenpay_Fluentcart
	 */
	protected function plugin() {
		return Ifthenpay_Fluentcart::get_instance();
	}

	/**
	 * Initialize gateway.
	 */
	public function boot() {
		// Checkout
		add_action( 'fluent_cart/checkout_embed_payment_method_content', array( $this, 'checkout_embed_payment_method_content' ) );
		// Thank you
		add_action( 'fluent_cart/receipt/thank_you/before_order_items', array( $this, 'thank_you_page' ) );
		// Filter our gateway from the checkout
		add_filter( 'fluent_cart/checkout_active_payment_methods', array( $this, 'filter_active_payment_methods' ), 10, 2 );
	}

	/**
	 * Check if the requirements for using this gateway are met.
	 *
	 * @return bool True if requirements are met, false otherwise.
	 */
	public function requirements_met() {
		if ( ! $this->plugin()->is_valid_key( $this->settings->get( static::KEY_FIELD ) ) ) {
			return false;
		}
		return $this->plugin()->requirements_met();
	}

	/**
	 * Validate the settings before the payment method is activated.
	 * Called by FluentCart from AbstractPaymentGateway::updateSettings().
	 *
	 * @param array $data The settings being saved.
	 * @return array The validation result.
	 */
	public static function validateSettings( $data ): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		return Ifthenpay_Fluentcart::get_instance()->validate_gateway_settings(
			$data,
			static::KEY_FIELD,
			static::key_label()
		);
	}

	/**
	 * Get the meta information for the payment gateway.
	 *
	 * @return array The meta information array.
	 */
	public function meta(): array {
		return array(
			'slug'                   => $this->ifthenpay_id,
			'route'                  => $this->ifthenpay_id,
			'title'                  => $this->title(),
			'label'                  => $this->title(), // What is this used for?
			'description'            => $this->description(),
			'logo'                   => plugins_url( '/images/payment-gateways/' . $this->ifthenpay_short_id . '-icon.svg', NAKEDCATPLUGINS_IFTHENPAY_FLUENTCART_FILE ), // Frontend
			'icon'                   => plugins_url( '/images/payment-gateways/' . $this->ifthenpay_short_id . '-icon.svg', NAKEDCATPLUGINS_IFTHENPAY_FLUENTCART_FILE ), // Backend
			'ifthenpay_banner'       => plugins_url( '/images/payment-gateways/' . $this->ifthenpay_short_id . '-banner.svg', NAKEDCATPLUGINS_IFTHENPAY_FLUENTCART_FILE ), // Frontend banner for payment instructions
			'ifthenpay_banner_email' => plugins_url( '/images/payment-gateways/' . $this->ifthenpay_short_id . '-banner.png', NAKEDCATPLUGINS_IFTHENPAY_FLUENTCART_FILE ), // Email banner, PNG because most email clients do not show SVG
			'brand_color'            => $this->brand_color,
			'status'                 => $this->settings->get( 'is_active' ) === 'yes',
			'upcoming'               => false, // ??
			'supported_features'     => $this->supportedFeatures, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		);
	}

	/**
	 * Make payment from payment instance.
	 *
	 * @param PaymentInstance $paymentInstance The payment instance. In Snake Case because required by FluentCart.
	 * @return array The payment response.
	 */
	public function makePaymentFromPaymentInstance( $paymentInstance ): array { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
		$plugin = $this->plugin();

		// Get order
		$payment_instance = $paymentInstance; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
		$order            = $payment_instance->order;

		$plugin->log( $this, 'info', 'Starting ' . $this->log_name . ' payment request', 'Order: ' . $order->id . ' - Amount: ' . $plugin->format_transaction_value_for_api( $payment_instance->transaction->total ) );

		// Requirements met?
		if ( ! $this->requirements_met() ) {
			$message = sprintf(
					/* translators: %s: Payment method title */
				__( 'The requirements for using %s are not met.', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
				'“' . $this->meta()['title'] . '”'
			);
			$plugin->log( $this, 'error', 'Failed ' . $this->log_name . ' payment request', 'Order: ' . $order->id . ' - ' . $message, null );
			return array(
				'status'  => 'failed',
				'message' => $message,
			);
		}

		// Set payment method title
		$order->payment_method_title = $this->meta()['title'];
		$order->save();

		// No value?
		if ( (int) $payment_instance->transaction->total === 0 ) {

			// Set as "paid"
			$payment_instance->transaction->status = Status::TRANSACTION_SUCCEEDED;
			$payment_instance->transaction->save();
			( new StatusHelper( $order ) )->syncOrderStatuses( $payment_instance->transaction );

			// Clear cart
			$plugin->finalize_cart( $order->id );

			// Return with success
			$payment_helper = new PaymentHelper( $this->ifthenpay_id );
			$plugin->log( $this, 'info', 'No ' . $this->log_name . ' payment request needed', 'Order: ' . $order->id . ' - Amount: ' . $plugin->format_transaction_value_for_api( $payment_instance->transaction->total ) );
			return array(
				'status'      => 'success',
				'message'     => __( 'Order has been placed successfully', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
				'redirect_to' => $payment_helper->successUrl( $payment_instance->transaction->uuid ),
			);
		}

		// Payment details
		$key                       = apply_filters( $plugin->hook_prefix . 'base_' . static::KEY_FIELD, $this->settings->get( static::KEY_FIELD ), $order );
		$value                     = $plugin->format_transaction_value_for_api( $payment_instance->transaction->total );
		$payment_request_arguments = $this->build_payment_request( $order, $key, $value, $payment_instance->transaction );

		// Make API call
		$api_call = $plugin->make_request_payment_api_call( $this, $order, $payment_request_arguments, $this->api_success_status, $this->payment_api_url( $key ) );
		if ( $api_call['status'] !== 'success' ) {
			// Return error from API call
			return $api_call;
		}

		// All seems good - Get the details to store on order
		// Actually this should be stored on transaction
		$details = $this->build_payment_details( $api_call['body'], $key, $value );
		$plugin->set_payment_details( $this->ifthenpay_id, $order, $payment_instance->transaction, $details['RequestId'], $details );

		// Where the customer goes next
		$redirect_to = $this->payment_redirect_url( $payment_instance, $details );
		if ( empty( $redirect_to ) ) {
			$message = sprintf(
				/* translators: %s: Payment method title */
				__( 'An error occurred processing the %s Payment request - please try again', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
				'“' . $this->meta()['title'] . '”'
			);
			$plugin->log( $this, 'error', 'Failed ' . $this->log_name . ' payment request', 'Order: ' . $order->id . ' - No redirect URL - Details: ' . $plugin->log_data( $details ), null );
			return array(
				'status'  => 'failed',
				'message' => $message,
			);
		}

		// Anything the payment method does once the payment is requested
		$this->after_payment_request( $payment_instance );

		// Clear cart
		if ( $this->complete_cart_on_request ) {
			$plugin->finalize_cart( $order->id );
		}

		// Return with success
		$plugin->log( $this, 'success', 'Successful ' . $this->log_name . ' payment request', 'Order: ' . $order->id . ' - Details: ' . $plugin->log_data( $details ) );
		return array(
			'status'      => 'success',
			'message'     => __( 'Order has been placed successfully', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
			'redirect_to' => $redirect_to,
		);
	}

	/**
	 * Get the payment details stored on the order.
	 *
	 * @param \FluentCart\App\Models\Order $order The order object.
	 * @return array|false The payment details, or false if they are not complete.
	 */
	public function get_payment_details( $order ) {
		$details = array();
		foreach ( $this->payment_details_keys as $key ) {
			$details[ $key ] = (string) $order->getMeta( $this->ifthenpay_id . '_' . $key );
		}
		foreach ( $this->payment_details_required as $key ) {
			if ( empty( $details[ $key ] ) ) {
				return false;
			}
		}
		return $details;
	}

	/**
	 * Checkout content.
	 *
	 * @param array $args The arguments.
	 */
	public function checkout_embed_payment_method_content( $args ) {
		if ( isset( $args['method'] ) && $args['method'] instanceof static ) {
			?>
			<p class="<?php echo esc_attr( $this->ifthenpay_id ); ?>-checkout-description">
				<?php echo esc_html( $this->meta()['description'] ); ?>
				<br>
				&nbsp;<!-- some spacing -->
			</p>
			<?php
			$this->checkout_fields();
		}
	}

	/**
	 * Thank you page content - Payment instructions.
	 *
	 * @param array $args The arguments.
	 */
	public function thank_you_page( $args ) {
		$this->plugin()->thank_you_page( $this, $args );
	}

	/**
	 * Thank you page content for pending payments.
	 *
	 * @param mixed $order The order object.
	 */
	public function thank_you_page_pending( $order ) {
		$payment_details = $this->get_payment_details( $order );
		if ( ! empty( $payment_details ) ) {
			$this->plugin()->thank_you_page_pending( $this, $this->thank_you_page_pending_rows( $order, $payment_details ) );
		}
	}

	/**
	 * Thank you page content for paid payments.
	 *
	 * @param mixed $order The order object.
	 */
	public function thank_you_page_paid( $order ) {
		$payment_details = $this->get_payment_details( $order );
		if ( ! empty( $payment_details ) ) {
			$rows = array(
				__( 'Value', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ) => $this->plugin()->format_price( $payment_details['val'] ),
			);
			$this->plugin()->thank_you_page_paid( $this, $rows );
		}
	}

	/**
	 * Handle Instant Payment Notification (IPN/Webhook).
	 * https://dev.fluentcart.com/payment-methods-integration/quick-implementation#with-ipn-webhooks-hosted-payment
	 */
	public function handleIPN(): void {
		$this->plugin()->handle_ipn( $this, $this->webhook_required_data, $this->webhook_matching_data );
	}

	/**
	 * Get order information. Maybe not needed?
	 *
	 * @param array $data The data.
	 * @return array The order information.
	 */
	public function getOrderInfo( $data ): array {
		return array();
	}

	/**
	 * Define the settings fields for the payment gateway.
	 * Documentation: https://dev.fluentcart.com/payment-methods-integration/payment_setting_fields
	 *
	 * @return array The settings fields.
	 */
	public function fields(): array {
		$plugin    = $this->plugin();
		$key_label = static::key_label();
		$key       = trim( (string) $this->settings->get( static::KEY_FIELD ) );

		$fields = array();

		// Intro
		ob_start();
		?>
		<div class="ifthenpay-admin-intro">
			<p><b><?php esc_html_e( 'Instructions:', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ); ?></b></p>
			<?php
			if ( $plugin->requirements_met() ) {
				?>
				<p><?php esc_html_e( 'To use this payment method, please ensure all the requirements are met:', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ); ?></p>
				<ul>
					<li><?php esc_html_e( 'The store currency needs to be set to EUR.', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ); ?></li>
					<li>
						<?php
						echo wp_kses_post(
							sprintf(
								/* translators: %1$s: Link start tag, %2$s: Link end tag, %3$s: Payment method name */
								esc_html__( 'You need an active %1$sifthenpay%2$s account with the %3$s payment method enabled.', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
								'<a href="' . $plugin->build_out_link( 'https://ifthenpay.com/?lang=en' ) . '" target="_blank">',
								'</a>',
								static::method_name()
							)
						);
						?>
					</li>
					<li>
						<?php
						echo wp_kses_post(
							sprintf(
								/* translators: %s: type of key */
								esc_html__( 'The %s provided by ifthenpay is configured in the settings below.', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
								$key_label
							)
						);
						?>
					</li>
					<li>
						<?php
						echo wp_kses_post(
							sprintf(
								/* translators: %s: type of key */
								esc_html__( 'The same %s is not used in other websites or systems.', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
								$key_label
							)
						);
						?>
					</li>
					<li>
						<?php
						echo wp_kses_post(
							sprintf(
								/* translators: %1$s: Link start tag, %2$s: Link end tag, %3$s: type of key */
								esc_html__( 'The Callback/Webhook URL and Antiphishing Key are set correctly in your account at the %1$sifthenpay backoffice%2$s &gt; Management &gt; Contract/Accounts, on the corresponding %3$s, or by using the button below (available when the %3$s is set).', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
								'<a href="' . $plugin->build_out_link( 'https://backoffice.ifthenpay.com/Admin/ContratoContas' ) . '" target="_blank">',
								'</a>',
								$key_label
							)
						);
						?>
					</li>
				</ul>
				<div class="ifthenpay-webhook-url-antiphishing-key">
					<div>
						<b><?php esc_html_e( 'Callback/Webhook URL:', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ); ?></b>
						<code class="copyable-content" id="ifthenpay-webhook-url"><?php echo esc_html( $this->webhook_url ); // esc_url() causes problems with [] ?></code>
					</div>
					<div>
						<b><?php esc_html_e( 'Antiphishing Key:', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ); ?></b>
						<code class="copyable-content" id="ifthenpay-webhook-key"><?php echo esc_html( $plugin->webhook_key ); ?></code>
					</div>
				</div>
				<?php
				if ( $plugin->is_valid_key( $key ) ) {
					?>
					<p>
						<a class="el-button el-button--info is-plain" id="ifthenpay-activate-webhook" data-gateway="<?php echo esc_attr( $this->ifthenpay_id ); ?>" data-ent="<?php echo esc_attr( static::IFTHENPAY_ENTITY ); ?>" data-subent="<?php echo esc_attr( $this->settings->get( static::KEY_FIELD ) ); ?>" href="#"><?php esc_html_e( 'Activate Callback/Webhook', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ); ?></a>
						<?php
						$activated = $plugin->get_setting( $this->ifthenpay_id . '_webhook_activated' );
						if ( $activated ) {
							if ( trim( (string) $plugin->get_setting( $this->ifthenpay_id . '_webhook_activated_key' ) ) === trim( (string) $this->settings->get( static::KEY_FIELD ) ) ) {
								echo '<br>✅ <small>' . esc_html(
									sprintf(
									/* translators: %1$s: The key, %2$s: Date/time */
										esc_html__( 'The Callback/Webhook was last activated for %1$s in %2$s', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
										$plugin->get_setting( $this->ifthenpay_id . '_webhook_activated_key' ),
										$activated
									)
								) . '</small>';
							} else {
								echo '<br>⚠️ <small>' . esc_html(
									sprintf(
									/* translators: %1$s: The key, %2$s: Date/time */
										esc_html__( 'The Callback/Webhook was last activated for %1$s in %2$s, which is not the same key you are using now', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
										$plugin->get_setting( $this->ifthenpay_id . '_webhook_activated_key' ),
										$activated
									)
								) . '</small>';
							}
						} else {
							echo '<br>‼️ <small class="error fluent-cart">' . esc_html__( 'The Callback/Webhook was not activated yet (or it was configured manually in the ifthenpay backoffice).', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ) . '</small>';
						}
						?>
					</p>
					<?php
				}
			} else {
				?>
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: Payment method title */
							esc_html__( 'Warning: Your store currency is not set to EUR. %s only supports EUR transactions.', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
							'“' . $this->meta()['title'] . '”'
						)
					);
					?>
				</p>
				<?php
			}
			?>
		</div>
		<?php
		$html            = ob_get_clean();
		$fields['intro'] = array(
			'type'  => 'html_attr',
			'label' => __( 'Instructions', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
			'value' => $html,
		);

		// Key
		$fields[ static::KEY_FIELD ] = $plugin->settings_field_key( $key_label );

		// Override payment method title?
		// Is now part of FluentCart

		// Missing - Override payment method description?

		// Payment method specific fields
		$fields = array_merge( $fields, $this->extra_fields() );

		// Only for Portuguese customers
		$fields['only_portugal'] = $plugin->settings_field_only_portugal();

		// Only for orders between values
		$fields['only_from']  = $plugin->settings_field_only_from( $this );
		$fields['only_up_to'] = $plugin->settings_field_only_up_to( $this );

		// Debug
		$fields['debug'] = $plugin->settings_field_debug();

		return $fields;
	}

	/**
	 * Filter active payment methods on checkout.
	 *
	 * @param array $active_payment_methods The active payment methods.
	 * @param array $args The arguments.
	 * @return array The filtered active payment methods.
	 */
	public function filter_active_payment_methods( $active_payment_methods, $args ) {
		return $this->plugin()->filter_active_payment_methods( $active_payment_methods, $args, $this );
	}
}

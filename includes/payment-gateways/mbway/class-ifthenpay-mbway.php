<?php
/**
 * ifthenpay MB WAY Payment Gateway for FluentCart
 */

namespace NakedCatPlugins\IfthenpayFluentCart;

use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;
use FluentCart\App\Services\Payments\PaymentHelper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\OrderMeta;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\App;
use FluentCart\App\Services\Localization\LocalizationManager;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ifthenpay MB WAY Payment Gateway Class
 */
class Ifthenpay_Mbway extends AbstractPaymentGateway {

	/**
	 * MB WAY Gateway ID.
	 *
	 * @var string
	 */
	public $ifthenpay_id = 'ifthenpay-mbway';

	/**
	 * MB WAY Gateway Short ID.
	 * To be used in hooks, for example.
	 * Not in use for now, as we'll try to pass the $ifthenpay_id as hooks arguments
	 *
	 * @var string
	 */
	public $ifthenpay_short_id = 'mbway';

	/**
	 * Webhook URL for payment notifications.
	 *
	 * @var string
	 */
	public $webhook_url = '';

	/**
	 * API URL for ifthenpay MB WAY.
	 *
	 * @var string
	 */
	public $api_url = 'https://api.ifthenpay.com/spg/payment/mbway';

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
	 * Constructor for the Ifthenpay_Mbway class.
	 */
	public function __construct() {
		require_once 'class-ifthenpay-mbway-settings-base.php';
		parent::__construct(
			new Ifthenpay_Mbway_Settings_Base()
		);
		// Set webhook URL
		$attributes        = array(
			'fluent-cart'      => 'fct_payment_listener_ipn',
			'method'           => $this->ifthenpay_id,
			'plugin'           => 'webdados-ifthenpay-fluentcart',
			'webhook_key'      => '[ANTI_PHISHING_KEY]',
			'order_id'         => '[ORDER_ID]',
			'request_id'       => '[REQUEST_ID]',
			'value'            => '[AMOUNT]',
			'payment_datetime' => '[PAYMENT_DATETIME]',
			'payment_fee'      => '[FEE]',
		);
		$this->webhook_url = add_query_arg( $attributes, site_url() );
	}

	/**
	 * Initialize gateway.
	 */
	public function boot() {
		// Checkout
		add_action( 'fluent_cart/checkout_embed_payment_method_content', array( $this, 'checkout_embed_payment_method_content' ) );
		add_filter( 'fluent_cart/checkout/validate_data', array( $this, 'checkout_validate_data' ), 10, 2 );
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
		global $ifthenpay_fluentcart;
		if ( strlen( trim( $this->settings->get( 'mbway_key' ) ) ) !== 10 ) {
			return false;
		}
		return $ifthenpay_fluentcart->get_instance()->requirements_met();
	}

	/**
	 * Get the meta information for the payment gateway.
	 *
	 * @return array The meta information array.
	 */
	public function meta(): array {
		return array(
			'slug'               => $this->ifthenpay_id,
			'route'              => $this->ifthenpay_id,
			'title'              => __( 'MB WAY mobile payment (ifthenpay)', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
			'label'              => __( 'MB WAY mobile payment (ifthenpay)', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ), // What is this used for?
			'description'        => __( 'Easy and simple payment using “MB WAY” on your mobile phone. (Only available to customers of Portuguese banks - Payment service provided by ifthenpay)', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
			'logo'               => plugins_url( '/images/payment-gateways/mbway-icon.svg', NAKEDCATPLUGINS_IFTHENPAY_FLUENTCART_FILE ), // Frontend
			'icon'               => plugins_url( '/images/payment-gateways/mbway-icon.svg', NAKEDCATPLUGINS_IFTHENPAY_FLUENTCART_FILE ), // Backend
			'ifthenpay_banner'   => plugins_url( '/images/payment-gateways/mbway-banner.svg', NAKEDCATPLUGINS_IFTHENPAY_FLUENTCART_FILE ), // Frontend banner for payment instructions
			'brand_color'        => '#d52329',
			'status'             => $this->settings->get( 'is_active' ) === 'yes',
			'upcoming'           => false, // ??
			'supported_features' => $this->supportedFeatures, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		);
	}

	/**
	 * Make payment from payment instance.
	 *
	 * @param PaymentInstance $paymentInstance The payment instance. In Snake Case because required by FluentCart.
	 * @return array The payment response.
	 */
	public function makePaymentFromPaymentInstance( $paymentInstance ): array { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
		global $ifthenpay_fluentcart;

		// Get order
		$payment_instance = $paymentInstance; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
		$order            = $payment_instance->order;

		$ifthenpay_fluentcart->log( $this, 'info', 'Starting MB WAY payment request', 'Order: ' . $order->id . ' - Amount: ' . $ifthenpay_fluentcart->format_transaction_value_for_api( $payment_instance->transaction->total ) );

		// Requirements met? - Should be abstracted into main class
		if ( ! $this->requirements_met() ) {
			$message = sprintf(
					/* translators: %s: Payment method title */
				__( 'The requirements for using %s are not met.', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
				'“' . $this->meta()['title'] . '”'
			);
			$ifthenpay_fluentcart->log( $this, 'error', 'Failed MB WAY payment request', 'Order: ' . $order->id . ' - ' . $message, true );
			return array(
				'status'  => 'failed',
				'message' => $message,
			);
		}

		// Get order and set payment method title - Should be abstracted into main class
		$order->payment_method_title = $this->meta()['title'];
		$order->save();

		// No value? - Should be abstracted into main class
		if ( $payment_instance->transaction->total === 0 ) {

			// Set as "paid"
			$payment_instance->transaction->status = Status::TRANSACTION_SUCCEEDED;
			$payment_instance->transaction->save();
			( new StatusHelper( $order ) )->syncOrderStatuses( $payment_instance->transaction );

			// Clear cart
			$ifthenpay_fluentcart->finalize_cart( $order->id );

			// Return with success
			$payment_helper = new PaymentHelper( $this->ifthenpay_id );
			$ifthenpay_fluentcart->log( $this, 'info', 'No MB WAY payment request needed', 'Order: ' . $order->id . ' - Amount: ' . $ifthenpay_fluentcart->format_transaction_value_for_api( $payment_instance->transaction->total ) );
			return array(
				'status'      => 'success',
				'message'     => __( 'Order has been placed successfully', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
				'redirect_to' => $payment_helper->successUrl( $payment_instance->transaction->uuid ),
			);
		}

		// Get data from request - We'll assume validation was done before and Phone and Country code are valid
		$data = App::request()->all();
		// Phone can only have digits
		$phone = isset( $data[ $this->ifthenpay_id . '-phone' ] ) ? sanitize_text_field( $data[ $this->ifthenpay_id . '-phone' ] ) : '';
		$phone = preg_replace( '/[^0-9]/', '', $phone );
		// Country code, default to PT
		$country_code = isset( $data[ $this->ifthenpay_id . '-country-code' ] ) ? sanitize_text_field( $data[ $this->ifthenpay_id . '-country-code' ] ) : '';
		$country_code = strtoupper( preg_replace( '/[^A-Za-z]/', '', $country_code ) );
		if ( empty( $country_code ) ) {
			$country_code = 'PT';
		}
		$calling_code = LocalizationManager::getInstance()->phones()[ $country_code ] ?? '+351';
		if ( is_array( $calling_code ) ) {
			// Porto Rico and Dominican Republic uses +1 as calling code and FluentCart has is as an array
			if ( $country_code === 'PR' || $country_code === 'DO' ) {
				$calling_code = '+1';
			}
		}

		// Full phone for API
		$phone_api = trim( str_replace( '+', '', $calling_code ) . '#' . $phone );

		// Payment details
		$mbway_key                 = apply_filters( $ifthenpay_fluentcart->hook_prefix . 'base_mbway_key', $this->settings->get( 'mbway_key' ), $order );
		$value                     = $ifthenpay_fluentcart->format_transaction_value_for_api( $payment_instance->transaction->total ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
		$payment_request_arguments = array(
			'mbWayKey'     => $mbway_key,
			'orderId'      => (string) $order->id,
			'amount'       => $value,
			'mobileNumber' => $phone_api,
			'description'  => $ifthenpay_fluentcart->filter_description_for_api( get_bloginfo( 'name' ) . ' #' . $order->id ),
		);

		// Make API call
		$api_call = $ifthenpay_fluentcart->make_request_payment_api_call( $this, $order, $payment_request_arguments, '000' );
		if ( $api_call['status'] !== 'success' ) {
			// Return error from API call
			return $api_call;
		}
		$body = $api_call['body'];

		// All seems good - Get the details to store on order
		// Actually this should be stored on transaction
		$d = date_create( date_i18n( \DateTime::ISO8601 ) );
		date_add( $d, date_interval_create_from_date_string( '+4 minutes' ) );
		$expire  = date_format( $d, 'Y-m-d H:i:s' );
		$details = array(
			'mbway_key'    => $mbway_key,
			'val'          => $value,
			'RequestId'    => $body->RequestId, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			'time'         => date_i18n( 'Y-m-d H:i:s' ),
			'expire'       => $expire,
			'phone'        => $phone,
			'country_code' => $country_code,
			'phone_api'    => $phone_api,
		);
		$ifthenpay_fluentcart->set_payment_details( $this->ifthenpay_id, $order, $payment_instance->transaction, $details['RequestId'], $details );

		// Clear cart - Should be abstracted into main class
		$ifthenpay_fluentcart->finalize_cart( $order->id );

		// Return with success - Should be abstracted into main class
		$payment_helper = new PaymentHelper( $this->ifthenpay_id );
		$ifthenpay_fluentcart->log( $this, 'success', 'Successful MB WAY payment request', 'Order: ' . $order->id . ' - Details: ' . wp_json_encode( $details ) );
		return array(
			'status'      => 'success',
			'message'     => __( 'Order has been placed successfully', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
			'redirect_to' => $payment_helper->successUrl( $payment_instance->transaction->uuid ),
		);
	}

	/**
	 * Checkout content.
	 *
	 * @param array $args The arguments.
	 */
	public function checkout_embed_payment_method_content( $args ) {
		if ( isset( $args['method'] ) && $args['method'] instanceof Ifthenpay_Mbway ) {
			?>
			<p class="<?php echo esc_attr( $this->ifthenpay_id ); ?>-checkout-description">
				<?php echo esc_html( $this->meta()['description'] ); ?>
				<br>
				&nbsp;<!-- some spacing -->
			</p>
			<div class="<?php echo esc_attr( $this->ifthenpay_id ); ?>-checkout-fields">
				<div class="<?php echo esc_attr( $this->ifthenpay_id ); ?>-checkout-fields-container">
					<span class="<?php echo esc_attr( $this->ifthenpay_id ); ?>-country-code-container">
						<select name="<?php echo esc_attr( $this->ifthenpay_id ); ?>-country-code" id="<?php echo esc_attr( $this->ifthenpay_id ); ?>-country-code">
							<?php
							$phones    = LocalizationManager::getInstance()->phones();
							$countries = LocalizationManager::getInstance()->countries();
							$options   = array();
							foreach ( $phones as $country_code => $calling_code ) {
								$country_name = isset( $countries[ $country_code ] ) ? $countries[ $country_code ] : $country_code;
								// Porto Rico and Dominican Republic uses +1 as calling code and FluentCart has is as an array
								if ( $country_code === 'PR' || $country_code === 'DO' ) {
									$calling_code = '+1';
								}
								if ( ! empty( trim( $calling_code ) ) ) {
									$country_label = trim( $country_name ) . ' (' . trim( $calling_code ) . ')';
									$options[ $country_label ] = trim( $country_code );
								}
							}
							// Sort options by keys (country labels)
							ksort( $options );
							foreach ( $options as $country_label => $country_code ) {
								?>
								<option value="<?php echo esc_attr( $country_code ); ?>" <?php selected( $country_code, 'PT' ); ?>>
									<?php echo esc_html( $country_label ); ?>
								</option>
								<?php
							}
							?>
						</select>
					</span>
					<span class="<?php echo esc_attr( $this->ifthenpay_id ); ?>-phone-container">
						<input type="tel" autocomplete="off" class="" name="<?php echo esc_attr( $this->ifthenpay_id ); ?>-phone" id="<?php echo esc_attr( $this->ifthenpay_id ); ?>-phone" placeholder="9xxxxxxxx" value=""/>
					</span>
				</div>
			</div>
			<?php
		}
	}

	/**
	 * Validate checkout data.
	 *
	 * @param array $errors The errors array.
	 * @param array $args The arguments.
	 * @return array The errors array.
	 */
	public function checkout_validate_data( $errors, $args ) {
		if ( isset( $args['data']['_fct_pay_method'] ) && $args['data']['_fct_pay_method'] === $this->ifthenpay_id ) {
			// Phone can only have digits
			$phone = isset( $args['data'][ $this->ifthenpay_id . '-phone' ] ) ? sanitize_text_field( $args['data'][ $this->ifthenpay_id . '-phone' ] ) : '';
			$phone = preg_replace( '/[^0-9]/', '', $phone );
			if ( empty( $phone ) ) {
				$errors['payment_method'][ $this->ifthenpay_id ] = __( 'Please enter a valid MB WAY phone number.', 'payment-multibanco-for-fluent-cart-via-ifthenpay' );
				return $errors;
			}
			// If country code is provided, and it's Portugal, validate phone length and starting digit
			$country_code = isset( $args['data'][ $this->ifthenpay_id . '-country-code' ] ) ? sanitize_text_field( $args['data'][ $this->ifthenpay_id . '-country-code' ] ) : '';
			$country_code = strtoupper( preg_replace( '/[^A-Za-z]/', '', $country_code ) );
			if ( empty( $country_code ) ) {
				$country_code = 'PT';
				if ( $country_code === 'PT' && ( strlen( $phone ) !== 9 || substr( $phone, 0, 1 ) !== '9' ) ) {
					$errors['payment_method'][ $this->ifthenpay_id ] = __( 'Please enter a valid MB WAY phone number (9 digits for Portugal).', 'payment-multibanco-for-fluent-cart-via-ifthenpay' );

				}
			}
		}
		return $errors;
	}

	/**
	 * Thank you page content - Payment instructions.
	 *
	 * @param array $args The arguments.
	 */
	public function thank_you_page( $args ) {
		global $ifthenpay_fluentcart;
		$ifthenpay_fluentcart->thank_you_page( $this, $args );
	}

	/**
	 * Thank you page content for pending payments.
	 * We should implement payment and expiration checks here.
	 *
	 * @param mixed $order The order object.
	 */
	public function thank_you_page_pending( $order ) {
		global $ifthenpay_fluentcart;
		$payment_details = $ifthenpay_fluentcart->get_payment_details( $this->ifthenpay_id, $order );
		if ( ! empty( $payment_details ) ) {
			$rows = array(
				__( 'Phone number', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ) => str_replace( '#', ' ', $payment_details['phone_api'] ),
				__( 'Value', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ) => $ifthenpay_fluentcart->format_price( $payment_details['val'] ),
			);
			if ( isset( $payment_details['expire'] ) && trim( $payment_details['expire'] ) !== '' ) {
				$rows[ __( 'Expiration', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ) ] = $payment_details['expire'];
				if ( $payment_details['expire'] < date_i18n( 'Y-m-d H:i:s' ) ) {
					$rows['action_html'] = sprintf(
						/* translators: %1$s: Link start tag, %2$s: Link end tag */
						__( 'The payment deadline expired. %1$sPlease try again%2$s.', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
						'<a href="' . PaymentHelper::getCustomPaymentLink( $order->uuid ) . '">',
						'</a>'
					);
				}
			}
			global $ifthenpay_fluentcart;
			$ifthenpay_fluentcart->thank_you_page_pending( $this, $rows );
		}
	}

	/**
	 * Thank you page content for paid payments.
	 *
	 * @param mixed $order The order object.
	 */
	public function thank_you_page_paid( $order ) {
		global $ifthenpay_fluentcart;
		$payment_details = $ifthenpay_fluentcart->get_payment_details( $this->ifthenpay_id, $order );
		if ( ! empty( $payment_details ) ) {
			$rows = array(
				__( 'Value', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ) => $ifthenpay_fluentcart->format_price( $payment_details['val'] ),
			);
			$ifthenpay_fluentcart->thank_you_page_paid( $this, $rows );
		}
	}

	/**
	 * Handle Instant Payment Notification (IPN/Webhook).
	 * https://dev.fluentcart.com/payment-methods-integration/quick-implementation#with-ipn-webhooks-hosted-payment
	 */
	public function handleIPN(): void {
		global $ifthenpay_fluentcart;

		// Required data on webhook
		$required_data = array( 'plugin', 'request_id', 'value', 'order_id' );

		// Matching data between payment details stored and webhook data
		// $payment_key => $data_key
		$matching_data = array(
			'order_id' => 'order_id',
			'val'      => 'value',
		);

		// Handle IPN
		$ifthenpay_fluentcart->handle_ipn( $this, $required_data, $matching_data );
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
		global $ifthenpay_fluentcart;

		$fields = array();

		// Intro
		ob_start();
		?>
		<div class="ifthenpay-admin-intro">
			<p><b><?php esc_html_e( 'Instructions:', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ); ?></b></p>
			<?php
			if ( $ifthenpay_fluentcart->get_instance()->requirements_met() ) {
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
								'<a href="' . $ifthenpay_fluentcart->build_out_link( 'https://ifthenpay.com/?lang=en' ) . '" target="_blank">',
								'</a>',
								__( 'MB WAY', 'payment-multibanco-for-fluent-cart-via-ifthenpay' )
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
								__( 'MB WAY Key', 'payment-multibanco-for-fluent-cart-via-ifthenpay' )
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
								__( 'MB WAY Key', 'payment-multibanco-for-fluent-cart-via-ifthenpay' )
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
								'<a href="' . $ifthenpay_fluentcart->build_out_link( 'https://backoffice.ifthenpay.com/Admin/ContratoContas' ) . '" target="_blank">',
								'</a>',
								__( 'MB WAY Key', 'payment-multibanco-for-fluent-cart-via-ifthenpay' )
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
						<code class="copyable-content" id="ifthenpay-webhook-key"><?php echo esc_html( $ifthenpay_fluentcart->webhook_key ); ?></code>
					</div>
				</div>
				<?php
				if ( strlen( trim( $this->settings->get( 'mbway_key' ) ) ) === 10 ) {
					?>
					<p>
						<a class="el-button el-button--info is-plain" id="ifthenpay-activate-webhook" data-gateway="<?php echo esc_attr( $this->ifthenpay_id ); ?>" data-ent="MBWAY" data-subent="<?php echo esc_attr( $this->settings->get( 'mbway_key' ) ); ?>" href="#"><?php esc_html_e( 'Activate Callback/Webhook', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ); ?></a>
						<?php
						$activated = $ifthenpay_fluentcart->get_setting( $this->ifthenpay_id . '_webhook_activated' );
						if ( $activated ) {
							if ( trim( $ifthenpay_fluentcart->get_setting( $this->ifthenpay_id . '_webhook_activated_key' ) === $this->settings->get( 'mbway_key' ) ) ) {
								echo '<br>✅ <small>' . esc_html(
									sprintf(
									/* translators: %1$s: The key, %2$s: Date/time */
										esc_html__( 'The Callback/Webhook was last activated for %1$s in %2$s', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
										$ifthenpay_fluentcart->get_setting( $this->ifthenpay_id . '_webhook_activated_key' ),
										$activated
									)
								) . '</small>';
							} else {
								echo '<br>⚠️ <small>' . esc_html(
									sprintf(
									/* translators: %1$s: The key, %2$s: Date/time */
										esc_html__( 'The Callback/Webhook was last activated for %1$s in %2$s, which is not the same key you are using now', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
										$ifthenpay_fluentcart->get_setting( $this->ifthenpay_id . '_webhook_activated_key' ),
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

		// MB Key
		$fields['mbway_key'] = $ifthenpay_fluentcart->settings_field_key( __( 'MB WAY Key', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ) );

		// Override payment method title?
		// Is now part of FluentCart

		// Missing - Override payment method description?

		// Only for Portuguese customers
		$fields['only_portugal'] = $ifthenpay_fluentcart->settings_field_only_portugal();

		// Only for orders between values
		$fields['only_from']  = $ifthenpay_fluentcart->settings_field_only_from( $this );
		$fields['only_up_to'] = $ifthenpay_fluentcart->settings_field_only_up_to( $this );

		// Debug
		$fields['debug'] = $ifthenpay_fluentcart->settings_field_debug();

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
		global $ifthenpay_fluentcart;
		return $ifthenpay_fluentcart->filter_active_payment_methods( $active_payment_methods, $args, $this );
	}
}

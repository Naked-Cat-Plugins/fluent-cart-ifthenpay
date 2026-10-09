<?php
/**
 * ifthenpay MB WAY Payment Gateway for FluentCart
 */

namespace NakedCatPlugins\IfthenpayFluentCart;

use FluentCart\App\Services\Payments\PaymentHelper;
use FluentCart\App\App;
use FluentCart\App\Services\Localization\LocalizationManager;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ifthenpay MB WAY Payment Gateway Class
 */
class Ifthenpay_Mbway extends Ifthenpay_Gateway {

	/**
	 * Settings key holding the MB WAY Key.
	 *
	 * @var string
	 */
	const KEY_FIELD = 'mbway_key';

	/**
	 * ifthenpay entity, used when activating the Callback/Webhook.
	 *
	 * @var string
	 */
	const IFTHENPAY_ENTITY = 'MBWAY';

	/**
	 * MB WAY Gateway ID.
	 *
	 * @var string
	 */
	public $ifthenpay_id = 'ifthenpay-mbway';

	/**
	 * MB WAY Gateway Short ID.
	 *
	 * @var string
	 */
	public $ifthenpay_short_id = 'mbway';

	/**
	 * API URL for ifthenpay MB WAY.
	 *
	 * @var string
	 */
	public $api_url = 'https://api.ifthenpay.com/spg/payment/mbway';

	/**
	 * Brand color.
	 *
	 * @var string
	 */
	protected $brand_color = '#d52329';

	/**
	 * Payment method name for the debug log.
	 *
	 * @var string
	 */
	protected $log_name = 'MB WAY';

	/**
	 * Status ifthenpay returns on a successful payment request.
	 *
	 * @var string
	 */
	protected $api_success_status = '000';

	/**
	 * Payment details stored on the order meta.
	 *
	 * @var array
	 */
	protected $payment_details_keys = array( 'mbway_key', 'val', 'RequestId', 'time', 'expire', 'phone', 'country_code', 'phone_api' );

	/**
	 * Payment details that must be filled.
	 *
	 * @var array
	 */
	protected $payment_details_required = array( 'phone', 'val' );

	/**
	 * Webhook URL attributes.
	 *
	 * @var array
	 */
	protected $webhook_attributes = array(
		'order_id'         => '[ORDER_ID]',
		'request_id'       => '[REQUEST_ID]',
		'value'            => '[AMOUNT]',
		'payment_datetime' => '[PAYMENT_DATETIME]',
		'payment_fee'      => '[FEE]',
	);

	/**
	 * Required data on webhook.
	 *
	 * @var array
	 */
	protected $webhook_required_data = array( 'plugin', 'request_id', 'value', 'order_id' );

	/**
	 * Matching data between payment details stored and webhook data.
	 *
	 * @var array
	 */
	protected $webhook_matching_data = array(
		'order_id' => 'order_id',
		'val'      => 'value',
	);

	/**
	 * Phone details from the current payment request, kept for the payment details.
	 *
	 * @var array
	 */
	private $request_phone = array();

	/**
	 * The ifthenpay key name.
	 *
	 * @return string
	 */
	public static function key_label() {
		return __( 'MB WAY Key', 'payment-multibanco-for-fluent-cart-via-ifthenpay' );
	}

	/**
	 * The payment method name.
	 *
	 * @return string
	 */
	public static function method_name() {
		return __( 'MB WAY', 'payment-multibanco-for-fluent-cart-via-ifthenpay' );
	}

	/**
	 * The payment method title.
	 *
	 * @return string
	 */
	protected function title() {
		return __( 'MB WAY mobile payment (ifthenpay)', 'payment-multibanco-for-fluent-cart-via-ifthenpay' );
	}

	/**
	 * The payment method description.
	 *
	 * @return string
	 */
	protected function description() {
		return __( 'Easy and simple payment using “MB WAY” on your mobile phone. (Only available to customers of Portuguese banks - Payment service provided by ifthenpay)', 'payment-multibanco-for-fluent-cart-via-ifthenpay' );
	}

	/**
	 * Default settings specific to MB WAY.
	 *
	 * @return array
	 */
	protected function settings_defaults() {
		return array(
			'mbway_key'  => '',
			'do_refunds' => '', // For the future
		);
	}

	/**
	 * Initialize gateway.
	 */
	public function boot() {
		parent::boot();
		add_filter( 'fluent_cart/checkout/validate_data', array( $this, 'checkout_validate_data' ), 10, 2 );
	}

	/**
	 * Styles to load when this payment method is rendered at checkout.
	 * Called by FluentCart from AbstractPaymentGateway::enqueue().
	 *
	 * @return array The styles to enqueue.
	 */
	public function getEnqueueStyleSrc(): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		return array(
			array(
				'handle'  => $this->ifthenpay_id . '-checkout',
				'src'     => plugins_url( 'assets/checkout-mbway.css', NAKEDCATPLUGINS_IFTHENPAY_FLUENTCART_FILE ),
				'version' => $this->plugin()->asset_version(),
			),
		);
	}

	/**
	 * Build the arguments for the ifthenpay payment request.
	 *
	 * @param \FluentCart\App\Models\Order $order The order object.
	 * @param string                       $key   The MB WAY Key.
	 * @param string                       $value The value, formatted for the API.
	 * @return array The payment request arguments.
	 */
	protected function build_payment_request( $order, $key, $value ) {
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

		$this->request_phone = array(
			'phone'        => $phone,
			'country_code' => $country_code,
			'phone_api'    => $phone_api,
		);

		return array(
			'mbWayKey'     => $key,
			'orderId'      => (string) $order->id,
			'amount'       => $value,
			'mobileNumber' => $phone_api,
			'description'  => $this->plugin()->filter_description_for_api( get_bloginfo( 'name' ) . ' #' . $order->id ),
		);
	}

	/**
	 * Build the payment details to store on the order.
	 *
	 * @param object $body  The ifthenpay response body.
	 * @param string $key   The MB WAY Key.
	 * @param string $value The value, formatted for the API.
	 * @return array The payment details.
	 */
	protected function build_payment_details( $body, $key, $value ) {
		$d = date_create( date_i18n( \DateTime::ISO8601 ) );
		date_add( $d, date_interval_create_from_date_string( '+4 minutes' ) );
		$expire = date_format( $d, 'Y-m-d H:i:s' );
		return array(
			'mbway_key'    => $key,
			'val'          => $value,
			'RequestId'    => $body->RequestId, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			'time'         => date_i18n( 'Y-m-d H:i:s' ),
			'expire'       => $expire,
			'phone'        => $this->request_phone['phone'],
			'country_code' => $this->request_phone['country_code'],
			'phone_api'    => $this->request_phone['phone_api'],
		);
	}

	/**
	 * Checkout fields: country code and mobile number.
	 */
	protected function checkout_fields() {
		?>
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
								$country_label             = trim( $country_name ) . ' (' . trim( $calling_code ) . ')';
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
				$errors['payment_method'][ $this->ifthenpay_id ] = __( 'Please enter a valid MB WAY mobile number.', 'payment-multibanco-for-fluent-cart-via-ifthenpay' );
				return $errors;
			}
			// Normalize country code, default to PT if empty
			$country_code = isset( $args['data'][ $this->ifthenpay_id . '-country-code' ] ) ? sanitize_text_field( $args['data'][ $this->ifthenpay_id . '-country-code' ] ) : '';
			$country_code = strtoupper( preg_replace( '/[^A-Za-z]/', '', $country_code ) );
			if ( empty( $country_code ) ) {
				$country_code = 'PT';
			}
			// Portugal-specific validation: phone must be 9 digits and start with 9
			if ( $country_code === 'PT' && ( strlen( $phone ) !== 9 || substr( $phone, 0, 1 ) !== '9' ) ) {
				$errors['payment_method'][ $this->ifthenpay_id ] = __( 'Please enter a valid MB WAY mobile number (9 digits for Portugal).', 'payment-multibanco-for-fluent-cart-via-ifthenpay' );
			}
		}
		return $errors;
	}

	/**
	 * Rows to show on the thank you page while the payment is pending.
	 * We should implement payment and expiration checks here.
	 *
	 * @param \FluentCart\App\Models\Order $order           The order object.
	 * @param array                        $payment_details The payment details.
	 * @return array The rows.
	 */
	protected function thank_you_page_pending_rows( $order, $payment_details ) {
		$rows = array(
			__( 'Mobile number', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ) => str_replace( '#', ' ', $payment_details['phone_api'] ),
			__( 'Value', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ) => $this->plugin()->format_price( $payment_details['val'] ),
		);
		if ( isset( $payment_details['expire'] ) && trim( $payment_details['expire'] ) !== '' ) {
			$rows[ __( 'Expiration', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ) ] = $this->plugin()->format_date( $payment_details['expire'], 'Y-m-d H:i:s', $order );
			if ( $payment_details['expire'] < date_i18n( 'Y-m-d H:i:s' ) ) {
				$rows['action_html'] = sprintf(
					/* translators: %1$s: Link start tag, %2$s: Link end tag */
					__( 'The payment deadline expired. %1$sPlease try again%2$s.', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
					'<a href="' . PaymentHelper::getCustomPaymentLink( $order->uuid ) . '">',
					'</a>'
				);
			}
		}
		return $rows;
	}
}

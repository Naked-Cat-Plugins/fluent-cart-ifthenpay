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
	private $api_url = 'https://api.ifthenpay.com/spg/payment/mbway';

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
	 * Constructor for the Ifthenpay_Multibanco class.
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
		// TO DO - Check what can be abstracted to parent class
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
	 *
	 * @param mixed $order The order object.
	 */
	public function thank_you_page_pending( $order ) {
		global $ifthenpay_fluentcart;
		$payment_details = $ifthenpay_fluentcart->get_payment_details( $this->ifthenpay_id, $order );
		if ( ! empty( $payment_details ) ) {
			$rows = array(
				// MISSING STUFF?
				__( 'Value', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ) => $ifthenpay_fluentcart->format_price( $payment_details['val'] ),
			);
			if ( isset( $payment_details['expire'] ) && trim( $payment_details['expire'] ) !== '' ) {
				$rows[ __( 'Expiration', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ) ] = $payment_details['expire'];
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
		// TO DO - Check what can be abstracted to parent class
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

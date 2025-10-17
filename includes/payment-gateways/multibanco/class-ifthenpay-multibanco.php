<?php
/**
 * ifthenpay Multibanco Payment Gateway for FluentCart
 */

namespace NakedCatPlugins\FluentCartIfthenpay;

use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;

// phpcs:disable
/*
use FluentCart\Api\Resource\OrderResource;
use FluentCart\App\Events\Order\OrderStatusUpdated;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;*/
// phpcs:disable

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * ifthenpay Multibanco Payment Gateway Class
 */
class Ifthenpay_Multibanco extends AbstractPaymentGateway {

	private $ifthenpay_id = 'ifthenpay-multibanco';
	private $webhook_url = '';

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
		// 'refund',
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
		require_once 'class-ifthenpay-multibanco-settings-base.php';
		parent::__construct(
			new Ifthenpay_Multibanco_Settings_Base()
		);
		// Set webhook URL
		$attributes        = array(
			'fct_payment_listener' => '1',
			'method'               => $this->ifthenpay_id,
			'webhook_key'          => '[ANTI_PHISHING_KEY]', // Replace 'your_secret_key' with an actual secret key if needed
			'request_id'           => '[REQUEST_ID]',
			'value'                => '[AMOUNT]',
			'entity'               => '[ENTITY]',
			'reference'            => '[REFERENCE]',
			'payment_datetime'     => '[PAYMENT_DATETIME]',
			'payment_fee'          => '[FEE]',
		);
		$this->webhook_url = add_query_arg( $attributes, site_url() );
	}

	/**
	 * Check if the requirements for using this gateway are met.
	 *
	 * @return bool True if requirements are met, false otherwise.
	 */
	private function requirements_met() {
		global $fluent_cart_ifthenpay;
		return $fluent_cart_ifthenpay->get_instance()->requirements_met();
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
			'title'              => __( 'Multibanco Payment of Services (ifthenpay) TITLE', 'fluent-cart-ifthenpay' ),
			'label'              => __( 'Multibanco Payment of Services (ifthenpay) LABEL', 'fluent-cart-ifthenpay' ),
			'description'        => __( 'Easy and simple payment using “Payment of Services” at any “Multibanco” ATM terminal or your homebanking service. (Only available to customers of Portuguese banks - Payment service provided by ifthenpay)', 'fluent-cart-ifthenpay' ),
			'logo'               => plugins_url( '/images/payment-gateways/multibanco-banner.svg', NAKEDCATPLUGINS_FLUENTCART_IFTHENPAY_FILE ), // Frontend - Maybe also use the icon
			'icon'               => plugins_url( '/images/payment-gateways/multibanco-icon.svg', NAKEDCATPLUGINS_FLUENTCART_IFTHENPAY_FILE ), // Backend
			'brand_color'        => '#4376BB',
			'status'             => $this->settings->get( 'is_active' ) === 'yes',
			'upcoming'           => false, // ??
			'supported_features' => $this->supportedFeatures, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		);
	}

	public function boot() {
		// ??
	}

	/**
	 * Make payment from payment instance.
	 *
	 * @param PaymentInstance $paymentInstance The payment instance. Not in Snake Case because required by FluentCart.
	 * @return array The payment response.
	 */
	public function makePaymentFromPaymentInstance( $paymentInstance ): array { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
		// TODO: Implement Multibanco payment processing logic
		return array(
			'status'  => 'error',
			'message' => 'Payment method not implemented yet',
		);
	}

	/**
	 * Handle Instant Payment Notification (IPN/Webhook).
	 *
	 * @param array $data The IPN data.
	 * @return array The IPN response.
	 */
	public function handleIPN( array $data = array() ): array {
		// TODO: Implement Multibanco IPN/webhook handling
		return array(
			'status'  => 'success',
			'message' => 'IPN handled',
		);
	}

	/**
	 * Get order information.
	 *
	 * @param mixed $order The order object.
	 * @return array The order information.
	 */
	public function getOrderInfo( $order ): array {
		// TODO: Implement order information retrieval
		return array(
			'status'   => 'pending',
			'order_id' => $order->id ?? null,
		);
	}

	/**
	 * Additional method that may be required by the interface.
	 *
	 * @return array
	 */
	public function getAdditionalSettings(): array {
		// TODO: Implement additional settings if needed
		return array();
	}

	/**
	 * Define the settings fields for the payment gateway.
	 *
	 * @return array The settings fields.
	 */
	public function fields(): array {
		global $fluent_cart_ifthenpay;

		$fields = array();

		// Intro
		ob_start();
		$fluent_cart_ifthenpay->admin_payment_methdods_css();
		?>
		<div class="ifthenpay-admin-intro">
			<p><b><?php esc_html_e( 'Instructions:', 'fluent-cart-ifthenpay' ); ?></b></p>
			<?php
			if ( $this->requirements_met() ) {
				?>
				<p><?php esc_html_e( 'To use this payment method, please ensure all the requirements are met:', 'fluent-cart-ifthenpay' ); ?></p>
				<ul>
					<li><?php esc_html_e( 'The store currency needs to be set to EUR.', 'fluent-cart-ifthenpay' ); ?></li>
					<li>
						<?php
						echo wp_kses_post(
							sprintf(
								/* translators: 1: Link start tag, 2: Link end tag */
								esc_html__( 'You need an active %1$sifthenpay%2$s account with Multibanco payment method enabled.', 'fluent-cart-ifthenpay' ),
								'<a href="' . $fluent_cart_ifthenpay->build_out_link( 'https://ifthenpay.com/?lang=en' ) . '" target="_blank">',
								'</a>'
							)
						);
						?>
					</li>
					<li><?php esc_html_e( 'The MB Key provided by ifthenpay is configured in the settings below.', 'fluent-cart-ifthenpay' ); ?></li>
					<li><?php esc_html_e( 'The same MB Key is not used in other websites or systems.', 'fluent-cart-ifthenpay' ); ?></li>
					<li>
						<?php
						echo wp_kses_post(
							sprintf(
								/* translators: 1: Link start tag, 2: Link end tag */
								esc_html__( 'The Callback/Webhook URL and Antiphishing Key are set correctly in your account at the %1$sifthenpay backoffice%2$s &gt; Management &gt; Contract/Accounts, on the corresponding MB Key.', 'fluent-cart-ifthenpay' ),
								'<a href="' . $fluent_cart_ifthenpay->build_out_link( 'https://backoffice.ifthenpay.com/Admin/ContratoContas' ) . '" target="_blank">',
								'</a>'
							)
						);
						?>
					</li>
				</ul>
				<div class="ifthenpay-webhook-url-antiphishing-key">
					<div>
						<b><?php esc_html_e( 'Callback/Webhook URL:', 'fluent-cart-ifthenpay' ); ?></b>
						<code class="copyable-content"><?php echo esc_html( $this->webhook_url ); // esc_url() causes problems with [] ?></code>
					</div>
					<div>
						<b><?php esc_html_e( 'Antiphishing Key:', 'fluent-cart-ifthenpay' ); ?></b>
						<code class="copyable-content"><?php echo esc_html( $fluent_cart_ifthenpay->webhook_key ); ?></code>
					</div>
				</div>
				<?php
			} else {
				?>
				<p>
					<?php
					echo esc_html(
						sprintf(
						/* translators: 1: Payment method title */
							esc_html__( 'Warning: Your store currency is not set to EUR. %s only supports EUR transactions.', 'fluent-cart-ifthenpay' ),
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
			'label' => __( 'Instructions', 'fluent-cart-ifthenpay' ),
			'value' => $html,
		);

		// We should have a selector for offline mode or mb key mode

		// MB Key
		$fields['mb_key'] = array(
			'type'        => 'text',
			'label'       => __( 'MB Key', 'fluent-cart-ifthenpay' ),
			'placeholder' => 'AAA-000000',
			'description' => sprintf( // Does not exist yet in FluentCart
				/* translators: %s: Gateway key name */
				__( '%s provided by ifthenpay when signing the contract.', 'fluent-cart-ifthenpay' ),
				__( 'MB Key', 'fluent-cart-ifthenpay' )
			),
		);

		// Override payment method title?

		// Override payment method description?

		// Additional instructions for the payment instruction - Does this make sense anymore? - NO!

		// Reference expire time
		$expiry_options = array(
			array(
				'value' => '-1',
				'label' => __( 'No expiration', 'fluent-cart-ifthenpay' ),
			),
			array(
				'value' => '0',
				'label' => __( 'Same day at 23:59:59', 'fluent-cart-ifthenpay' ),
			),
		);
		for ( $i = 1; $i <= 31; $i++ ) {
			$expiry_options[] =
			array(
				'value' => strval( $i ),
				'label' => sprintf(
					/* translators: %d: number of days */
					_n( '%d day', '%d days', $i, 'fluent-cart-ifthenpay' ),
					$i
				),
			);
		}
		$other_days = array( 45, 60, 90, 120, 180, 365, 730 );
		foreach ( $other_days as $i ) {
			$expiry_options[] = array(
				'value' => strval( $i ),
				'label' => sprintf(
					/* translators: %d: number of days */
					_n( '%d day', '%d days', $i, 'fluent-cart-ifthenpay' ),
					$i
				),
			);
		}

		$fields['expiry'] = array(
			'type'        => 'select',
			'label'       => __( 'Reference expiration', 'fluent-cart-ifthenpay' ),
			'description' => __( 'Number of days until the reference expires (it will always expire at 23:59:59 when the number of days is reached)', 'fluent-cart-ifthenpay' ),
			'options'     => $expiry_options, // Why is this not working?
		);

		// Only for Portuguese customers

		// Only for orders between values

		// Debug?

		return $fields;
	}
}

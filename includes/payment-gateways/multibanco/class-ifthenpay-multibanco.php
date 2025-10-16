<?php
/**
 * ifthenpay Multibanco Payment Gateway for FluentCart
 */

namespace NakedCatPlugins\FluentCartIfthenpay;

use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;

/*
use FluentCart\Api\Resource\OrderResource;
use FluentCart\App\Events\Order\OrderStatusUpdated;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;*/

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
		// chave=[CHAVE_ANTI_PHISHING]&entidade=[ENTIDADE]&referencia=[REFERENCIA]&valor=[VALOR]&datahorapag=[DATA_HORA_PAGAMENTO]&terminal=[TERMINAL]&ifthenpayfee=[FEE]
		$this->webhook_url = add_query_arg( $attributes, site_url() );
	}

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
			'supported_features' => $this->supportedFeatures,
		);
	}

	public function boot() {
		// ??
	}

	/**
	 * Make payment from payment instance.
	 *
	 * @param PaymentInstance $paymentInstance The payment instance.
	 * @return array The payment response.
	 */
	public function makePaymentFromPaymentInstance( $paymentInstance ): array {
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

	public function fields(): array {
		global $fluent_cart_ifthenpay;

		$fields = array();

		// Intro
		ob_start();
		?>
		<div>
			<p><b><?php esc_html_e( 'Instructions:', 'fluent-cart-ifthenpay' ); ?></b></p>
			<?php
			if ( $this->requirements_met() ) {
				?>
				<p><?php esc_html_e( 'To use this payment method, please ensure all the requirements are met:', 'fluent-cart-ifthenpay' ); ?></p>
				<ul>
					<li>- <?php esc_html_e( 'The store currency needs to be set to EUR.', 'fluent-cart-ifthenpay' ); ?></li>
					<li>-
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
					<li>- <?php esc_html_e( 'The MB Key provided by ifthenpay is configured in the settings below.', 'fluent-cart-ifthenpay' ); ?></li>
					<li>- <?php esc_html_e( 'The same MB Key is not used in other websites or systems.', 'fluent-cart-ifthenpay' ); ?></li>
					<li>-
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
						<br/>
						&nbsp; &nbsp; <?php esc_html_e( 'Callback/Webhook URL:', 'fluent-cart-ifthenpay' ); ?>
						<br/>
						&nbsp; &nbsp; <code class="copyable-content"><?php echo esc_html( $this->webhook_url ); // esc_url() causes problems with [] ?></code>
						<br/>
						&nbsp; &nbsp; <?php esc_html_e( 'Antiphishing Key:', 'fluent-cart-ifthenpay' ); ?>
						<br/>
						&nbsp; &nbsp; <code class="copyable-content"><?php echo esc_html( $fluent_cart_ifthenpay->webhook_key ); ?></code>
					</li>
				</ul>
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

		return $fields;
	}
}

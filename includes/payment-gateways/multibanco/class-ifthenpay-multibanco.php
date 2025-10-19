<?php
/**
 * ifthenpay Multibanco Payment Gateway for FluentCart
 */

namespace NakedCatPlugins\MultibancoIfthenpayFluentCart;

use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;
use FluentCart\App\Services\Payments\PaymentHelper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\OrderMeta;
use FluentCart\App\Models\OrderTransaction;

// phpcs:disable
/*
use FluentCart\Api\Resource\OrderResource;
use FluentCart\App\Events\Order\OrderStatusUpdated;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\;*/
// phpcs:enable

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * ifthenpay Multibanco Payment Gateway Class
 */
class Ifthenpay_Multibanco extends AbstractPaymentGateway {

	/**
	 * Multibanco Gateway ID.
	 *
	 * @var string
	 */
	private $ifthenpay_id = 'ifthenpay-multibanco';

	/**
	 * Webhook URL for payment notifications.
	 *
	 * @var string
	 */
	private $webhook_url = '';

	/**
	 * API URL for ifthenpay Multibanco.
	 *
	 * @var string
	 */
	private $api_url = 'https://api.ifthenpay.com/multibanco/reference/init';

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
			'fluent-cart'      => 'fct_payment_listener_ipn',
			'method'           => $this->ifthenpay_id,
			'plugin'           => 'webdados-ifthenpay-fluentcart',
			'webhook_key'      => '[ANTI_PHISHING_KEY]', // Replace 'your_secret_key' with an actual secret key if needed
			'request_id'       => '[REQUEST_ID]',
			'value'            => '[AMOUNT]',
			'entity'           => '[ENTITY]',
			'reference'        => '[REFERENCE]',
			'payment_datetime' => '[PAYMENT_DATETIME]',
			'payment_fee'      => '[FEE]',
		);
		$this->webhook_url = add_query_arg( $attributes, site_url() );
		// Init our hooks
		$this->init_hooks();
	}

	/**
	 * Initialize hooks.
	 */
	private function init_hooks() {
		// Thank you
		add_action( 'fluent_cart/after_receipt', array( $this, 'thank_you' ) );
	}

	/**
	 * Check if the requirements for using this gateway are met.
	 *
	 * @return bool True if requirements are met, false otherwise.
	 */
	private function requirements_met() {
		global $ifthenpay_fluentcart;
		if ( strlen( trim( $this->settings->settings['mb_key'] ) ) !== 10 ) {
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
			'title'              => __( 'Multibanco Payment of Services (ifthenpay)', 'multibanco-ifthenpay-for-fluentcart' ),
			'label'              => __( 'Multibanco Payment of Services (ifthenpay)', 'multibanco-ifthenpay-for-fluentcart' ), // What is this used for?
			'description'        => __( 'Easy and simple payment using “Payment of Services” at any “Multibanco” ATM terminal or your homebanking service. (Only available to customers of Portuguese banks - Payment service provided by ifthenpay)', 'multibanco-ifthenpay-for-fluentcart' ),
			// 'logo'               => plugins_url( '/images/payment-gateways/multibanco-banner.svg', NAKEDCATPLUGINS_IFTHENPAY_FLUENTCART_FILE ), // Frontend - Maybe also use the icon
			'logo'               => plugins_url( '/images/payment-gateways/multibanco-icon.svg', NAKEDCATPLUGINS_IFTHENPAY_FLUENTCART_FILE ), // Frontend
			'icon'               => plugins_url( '/images/payment-gateways/multibanco-icon.svg', NAKEDCATPLUGINS_IFTHENPAY_FLUENTCART_FILE ), // Backend
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
	 * @param PaymentInstance $paymentInstance The payment instance. In Snake Case because required by FluentCart.
	 * @return array The payment response.
	 */
	public function makePaymentFromPaymentInstance( $paymentInstance ): array { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
		global $ifthenpay_fluentcart;

		$payment_instance = $paymentInstance; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

		if ( ! $this->requirements_met() ) {
			return array(
				'status'  => 'failed',
				'message' => sprintf(
					/* translators: 1: Payment method title */
					__( 'The requirements for using %s are not met.', 'multibanco-ifthenpay-for-fluentcart' ),
					'“' . $this->meta()['title'] . '”'
				),
			);
		}

		// Get order and set payment method title
		$order                       = $payment_instance->order;
		$order->payment_method_title = $this->meta()['title'];
		$order->save();

		// No value?
		if ( $payment_instance->transaction->total === 0 ) {

			// Set as "paid"
			$payment_instance->transaction->status = Status::TRANSACTION_SUCCEEDED;
			$payment_instance->transaction->save();
			( new StatusHelper( $order ) )->syncOrderStatuses( $payment_instance->transaction );

			// Clear cart
			$ifthenpay_fluentcart->finalize_cart( $order->id );

			// Return with success
			$payment_helper = new PaymentHelper( $this->ifthenpay_id );
			return array(
				'status'      => 'success',
				'message'     => __( 'Order has been placed successfully', 'multibanco-ifthenpay-for-fluentcart' ),
				'redirect_to' => $payment_helper->successUrl( $payment_instance->transaction->uuid ),
			);
		}

		// Payment details
		$mb_key                    = apply_filters( $ifthenpay_fluentcart->filter_prefix . 'base_mb_key', $this->settings->settings['mb_key'], $order );
		$value                     = $ifthenpay_fluentcart->format_transaction_value_for_api( $payment_instance->transaction->total ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
		$payment_request_arguments = array(
			'mbKey'       => $mb_key,
			'orderId'     => (string) $order->id,
			'amount'      => $value,
			'description' => $ifthenpay_fluentcart->filter_description_for_api( get_bloginfo( 'name' ) . ' #' . $order->id ),
		);

		// Expiry?
		if ( trim( $this->settings->settings['expiry'] ) !== '' && trim( $this->settings->settings['expiry'] ) !== '-1' && is_numeric( $this->settings->settings['expiry'] ) ) {
			$payment_request_arguments['expiryDays'] = (string) $this->settings->settings['expiry'];
		}

		// Make API call to ifthenpay to create Multibanco reference - Maybe abstract this in the main class
		$args = array(
			'method'   => 'POST',
			'timeout'  => apply_filters( $ifthenpay_fluentcart->filter_prefix . 'api_timeout', 15 ),
			'blocking' => true,
			'headers'  => array(
				'Content-Type' => 'application/json; charset=utf-8',
			),
			'body'     => wp_json_encode( $payment_request_arguments ),
		);
		// Make the request
		$response = wp_remote_post( $this->api_url, $args );

		// Deal with errors - Step 1
		if ( is_wp_error( $response ) ) {
			return array(
				'status'  => 'failed',
				'message' => sprintf(
					/* translators: 1: Error details */
					__( 'Failed to create payment at ifthenpay API: %s', 'multibanco-ifthenpay-for-fluentcart' ),
					$response->get_error_code() . ' - ' . $response->get_error_message()
				),
			);
		}

		// Deal with errors - Step 2
		if ( ! ( isset( $response['response']['code'] ) && intval( $response['response']['code'] ) === 200 && isset( $response['body'] ) && trim( $response['body'] ) !== '' ) ) {
			return array(
				'status'  => 'failed',
				'message' => sprintf(
					/* translators: 1: Response code */
					__( 'Unexpected response from ifthenpay API. Response code: %s', 'multibanco-ifthenpay-for-fluentcart' ),
					isset( $response['response']['code'] ) ? intval( $response['response']['code'] ) : 'N/A'
				),
			);
		}

		// Deal with errors - Step 3
		$body = json_decode( $response['body'] );
		if ( ! ( ! empty( $body ) && isset( $body->Status ) && trim( $body->Status ) === '0' ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			return array(
				'status'  => 'failed',
				'message' => sprintf(
					/* translators: 1: Response code */
					__( 'An error occurred processing the %s Payment request - please try again', 'multibanco-ifthenpay-for-fluentcart' ),
					'“' . $this->meta()['title'] . '”'
				),
			);
		}

		// All seems good - Get the details to store on order
		$details = array(
			'mb_key'    => $mb_key,
			'ent'       => $body->Entity, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			'ref'       => $body->Reference, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			'val'       => $value,
			'RequestId' => $body->RequestId, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			'expire'    => isset( $body->ExpiryDate ) && trim( $body->ExpiryDate ) !== '' ? trim( $body->ExpiryDate ) : '', // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		);
		$ifthenpay_fluentcart->set_payment_details( $this->ifthenpay_id, $order, $details );

		// Clear cart
		$ifthenpay_fluentcart->finalize_cart( $order->id );

		// Return with success
		$payment_helper = new PaymentHelper( $this->ifthenpay_id );
		return array(
			'status'      => 'success',
			'message'     => __( 'Order has been placed successfully', 'multibanco-ifthenpay-for-fluentcart' ),
			'redirect_to' => $payment_helper->successUrl( $payment_instance->transaction->uuid ),
		);
	}

	/**
	 * Thank you page content - Payment instructions.
	 *
	 * @param array $args The arguments.
	 */
	public function thank_you( $args ) {
		$order           = $args['order'];
		$is_first_time   = $args['is_first_time'];
		$order_operation = $args['order_operation'];
		// Our gateway?
		if ( $order->payment_method === $this->ifthenpay_id ) {

			switch ( $order->payment_status ) {
				case Status::PAYMENT_PENDING:
				case Status::PAYMENT_PARTIALLY_PAID:
					// Not paid or not completely paid yet
					$this->thank_you_pending( $order );
					break;
				case Status::PAYMENT_PAID:
					// Paid
					$this->thank_you_paid( $order );
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
	 * @param mixed $order The order object.
	 */
	public function thank_you_pending( $order ) {
		global $ifthenpay_fluentcart;
		$payment_details = $ifthenpay_fluentcart->get_payment_details( $this->ifthenpay_id, $order );
		if ( ! empty( $payment_details ) ) {
			$ifthenpay_fluentcart->thank_you_css( $this->ifthenpay_id );
			?>
			<div class="ifthenpay-thank-you">
				<table class="details_table" cellpadding="0" cellspacing="0">
					<tr>
						<th colspan="2">
							<div><?php esc_html_e( 'Payment instructions', 'multibanco-ifthenpay-for-fluentcart' ); ?></div>
							<div><img src="<?php echo esc_url( plugins_url( '/images/payment-gateways/multibanco-banner.svg', NAKEDCATPLUGINS_IFTHENPAY_FLUENTCART_FILE ) ); ?>" alt="<?php echo esc_attr( $this->meta()['title'] ); ?>"/></div>
						</th>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Entity', 'multibanco-ifthenpay-for-fluentcart' ); ?>:</td>
						<td class="mb_value"><?php echo esc_html( $payment_details['ent'] ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Reference', 'multibanco-ifthenpay-for-fluentcart' ); ?>:</td>
						<td class="mb_value"><?php echo esc_html( $ifthenpay_fluentcart->format_multibanco_ref( $payment_details['ref'] ) ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Value', 'multibanco-ifthenpay-for-fluentcart' ); ?>:</td>
						<td class="mb_value"><?php echo esc_html( $ifthenpay_fluentcart->format_price( $payment_details['val'] ) ); ?></td>
					</tr>
					<?php
					if ( isset( $payment_details['expire'] ) && trim( $payment_details['expire'] ) !== '' ) {
						?>
						<tr>
							<td><?php esc_html_e( 'Expiration', 'multibanco-ifthenpay-for-fluentcart' ); ?>:</td>
							<td class="mb_value"><?php echo esc_html( $payment_details['expire'] ); ?></td>
						</tr>
						<?php
					}
					?>
				</table>
			</div>
			<?php
		}
	}

	/**
	 * Thank you page content for paid payments.
	 *
	 * @param mixed $order The order object.
	 */
	public function thank_you_paid( $order ) {
		global $ifthenpay_fluentcart;
		$payment_details = $ifthenpay_fluentcart->get_payment_details( $this->ifthenpay_id, $order );
		if ( ! empty( $payment_details ) ) {
			$ifthenpay_fluentcart->thank_you_css( $this->ifthenpay_id );
			?>
			<div class="ifthenpay-thank-you">
				<table class="details_table" cellpadding="0" cellspacing="0">
					<tr>
						<th colspan="2">
							<div><?php esc_html_e( 'Payment received', 'multibanco-ifthenpay-for-fluentcart' ); ?></div>
							<div><img src="<?php echo esc_url( plugins_url( '/images/payment-gateways/multibanco-banner.svg', NAKEDCATPLUGINS_IFTHENPAY_FLUENTCART_FILE ) ); ?>" alt="<?php echo esc_attr( $this->meta()['title'] ); ?>"/></div>
						</th>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Value', 'multibanco-ifthenpay-for-fluentcart' ); ?>:</td>
						<td class="mb_value"><?php echo esc_html( $ifthenpay_fluentcart->format_price( $payment_details['val'] ) ); ?></td>
					</tr>
				</table>
			</div>
			<?php
		}
	}

	/**
	 * Handle Instant Payment Notification (IPN/Webhook).
	 *
	 * Looking at Stripe, we should be querying a transaction and not an order, because each order might have several transactions.
	 * But we're going to keep it simple for now and assume one transaction per order.
	 */
	public function handleIPN(): void {
		global $ifthenpay_fluentcart;

		// Sanitize data
		$data = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		array_walk( $data, 'sanitize_text_field' );

		// Validate webhook key
		if ( ! isset( $data['webhook_key'] ) || trim( $data['webhook_key'] ) === '' || $data['webhook_key'] !== trim( $ifthenpay_fluentcart->webhook_key ) ) {
			$ifthenpay_fluentcart->send_callback_response( 403, 'Invalid webhook key' );
			return;
		}

		// Check for order based on request_id
		$order = $ifthenpay_fluentcart->get_order_by_request_id( $this->ifthenpay_id, $data['request_id'] );
		if ( ! $order ) {
			$ifthenpay_fluentcart->send_callback_response( 200, 'Order not found' ); // Should be 404 but we want to stop ifthenpay from retrying
			return;
		}

		// Check if order is to be processed or not
		if ( ! in_array( $order->payment_status, array( Status::PAYMENT_PENDING, Status::PAYMENT_PARTIALLY_PAID ), true ) ) {
			$ifthenpay_fluentcart->send_callback_response( 200, 'Order found but not pending payment' ); // Should be 404 but we want to stop ifthenpay from retrying
			return;
		}

		// Get payment order payment details and compare them
		$payment_details = $ifthenpay_fluentcart->get_payment_details( $this->ifthenpay_id, $order );
		if ( empty( $payment_details ) ) {
			$ifthenpay_fluentcart->send_callback_response( 200, 'Order found but no payment details are recorded on it' ); // Should be 404 but we want to stop ifthenpay from retrying
			return;
		}
		if ( $payment_details['ent'] !== $data['entity'] || $payment_details['ref'] !== $data['reference'] || floatval( $payment_details['val'] ) !== floatval( $data['value'] ) ) {
			$ifthenpay_fluentcart->send_callback_response( 200, 'Order found but payment details do not match' ); // Should be 404 but we want to stop ifthenpay from retrying
			return;
		}

		// Set transaction and order as paid
		$transaction = OrderTransaction::query()
				->where( 'order_id', $order->id )
				->where( 'status', Status::TRANSACTION_PENDING )
				->where( 'payment_method', $this->ifthenpay_id )
				->where( 'total', (int) str_replace( '.', '', $payment_details['val'] ) )
				->orderBy( 'id', 'DESC' )
				->first();
		if ( empty( $transaction ) ) {
			$ifthenpay_fluentcart->send_callback_response( 200, 'Order found but no matching pending transaction found' ); // Should be 404 but we want to stop ifthenpay from retrying
			return;
		}
		$transaction->status = Status::TRANSACTION_SUCCEEDED;
		$transaction->save();
		( new StatusHelper( $order ) )->syncOrderStatuses( $transaction ); // Makes IPN fail...

		$ifthenpay_fluentcart->send_callback_response( 200, 'Order found and payment processed successfully' );
	}

	/**
	 * Get order information. Maybe not needed?
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
	 * Additional method that may be required by the interface. Maybe not needed?
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
		global $ifthenpay_fluentcart;

		$fields = array();

		// Intro
		ob_start();
		$ifthenpay_fluentcart->admin_payment_methods_css();
		?>
		<div class="ifthenpay-admin-intro">
			<p><b><?php esc_html_e( 'Instructions:', 'multibanco-ifthenpay-for-fluentcart' ); ?></b></p>
			<?php
			if ( $ifthenpay_fluentcart->get_instance()->requirements_met() ) {
				?>
				<p><?php esc_html_e( 'To use this payment method, please ensure all the requirements are met:', 'multibanco-ifthenpay-for-fluentcart' ); ?></p>
				<ul>
					<li><?php esc_html_e( 'The store currency needs to be set to EUR.', 'multibanco-ifthenpay-for-fluentcart' ); ?></li>
					<li>
						<?php
						echo wp_kses_post(
							sprintf(
								/* translators: 1: Link start tag, 2: Link end tag */
								esc_html__( 'You need an active %1$sifthenpay%2$s account with Multibanco payment method enabled.', 'multibanco-ifthenpay-for-fluentcart' ),
								'<a href="' . $ifthenpay_fluentcart->build_out_link( 'https://ifthenpay.com/?lang=en' ) . '" target="_blank">',
								'</a>'
							)
						);
						?>
					</li>
					<li><?php esc_html_e( 'The MB Key provided by ifthenpay is configured in the settings below.', 'multibanco-ifthenpay-for-fluentcart' ); ?></li>
					<li><?php esc_html_e( 'The same MB Key is not used in other websites or systems.', 'multibanco-ifthenpay-for-fluentcart' ); ?></li>
					<li>
						<?php
						echo wp_kses_post(
							sprintf(
								/* translators: 1: Link start tag, 2: Link end tag */
								esc_html__( 'The Callback/Webhook URL and Antiphishing Key are set correctly in your account at the %1$sifthenpay backoffice%2$s &gt; Management &gt; Contract/Accounts, on the corresponding MB Key.', 'multibanco-ifthenpay-for-fluentcart' ),
								'<a href="' . $ifthenpay_fluentcart->build_out_link( 'https://backoffice.ifthenpay.com/Admin/ContratoContas' ) . '" target="_blank">',
								'</a>'
							)
						);
						?>
					</li>
				</ul>
				<div class="ifthenpay-webhook-url-antiphishing-key">
					<div>
						<b><?php esc_html_e( 'Callback/Webhook URL:', 'multibanco-ifthenpay-for-fluentcart' ); ?></b>
						<code class="copyable-content"><?php echo esc_html( $this->webhook_url ); // esc_url() causes problems with [] ?></code>
					</div>
					<div>
						<b><?php esc_html_e( 'Antiphishing Key:', 'multibanco-ifthenpay-for-fluentcart' ); ?></b>
						<code class="copyable-content"><?php echo esc_html( $ifthenpay_fluentcart->webhook_key ); ?></code>
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
							esc_html__( 'Warning: Your store currency is not set to EUR. %s only supports EUR transactions.', 'multibanco-ifthenpay-for-fluentcart' ),
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
			'label' => __( 'Instructions', 'multibanco-ifthenpay-for-fluentcart' ),
			'value' => $html,
		);

		// We should have a selector for offline mode or mb key mode

		// MB Key
		// Missing: max length and pattern
		$fields['mb_key'] = array(
			'type'        => 'text',
			'label'       => __( 'MB Key', 'multibanco-ifthenpay-for-fluentcart' ),
			'placeholder' => 'AAA-000000',
			'description' => sprintf( // Does not exist yet in FluentCart
				/* translators: %s: Gateway key name */
				__( '%s provided by ifthenpay when signing the contract.', 'multibanco-ifthenpay-for-fluentcart' ),
				__( 'MB Key', 'multibanco-ifthenpay-for-fluentcart' )
			),
			'extra_atts'  => array( // Not working
				'maxlength' => 10,
				'size'      => 12,
			),
		);

		// Override payment method title?

		// Override payment method description?

		// Additional instructions for the payment instruction - Does this make sense anymore? - NO!

		// Reference expire time
		$expiry_options = array(
			array(
				'value' => '-1',
				'label' => __( 'No expiration', 'multibanco-ifthenpay-for-fluentcart' ),
			),
			array(
				'value' => '0',
				'label' => __( 'Same day at 23:59:59', 'multibanco-ifthenpay-for-fluentcart' ),
			),
		);
		for ( $i = 1; $i <= 31; $i++ ) {
			$expiry_options[] =
			array(
				'value' => strval( $i ),
				'label' => sprintf(
					/* translators: %d: number of days */
					_n( '%d day', '%d days', $i, 'multibanco-ifthenpay-for-fluentcart' ),
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
					_n( '%d day', '%d days', $i, 'multibanco-ifthenpay-for-fluentcart' ),
					$i
				),
			);
		}

		$fields['expiry'] = array(
			'type'        => 'select',
			'label'       => __( 'Reference expiration', 'multibanco-ifthenpay-for-fluentcart' ),
			'description' => __( 'Number of days until the reference expires (it will always expire at 23:59:59 when the number of days is reached)', 'multibanco-ifthenpay-for-fluentcart' ),
			'options'     => $expiry_options, // Why is this not working?
		);

		// Only for Portuguese customers

		// Only for orders between values

		// Debug?

		return $fields;
	}
}

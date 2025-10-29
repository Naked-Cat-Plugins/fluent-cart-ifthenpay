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
	public $ifthenpay_id = 'ifthenpay-multibanco';

	/**
	 * Multibanco Gateway Short ID.
	 * To be used in hooks, for example.
	 * Not in use for now, as we'll try to pass the $ifthenpay_id as hooks arguments
	 *
	 * @var string
	 */
	public $ifthenpay_short_id = 'multibanco';

	/**
	 * Webhook URL for payment notifications.
	 *
	 * @var string
	 */
	public $webhook_url = '';

	/**
	 * API URL for ifthenpay Multibanco.
	 *
	 * @var string
	 */
	private $api_url = 'https://api.ifthenpay.com/multibanco/reference/init';

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
	}

	/**
	 * Initialize gateway.
	 */
	public function boot() {
		// Thank you
		add_action( 'fluent_cart/after_receipt', array( $this, 'thank_you' ) );
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
		if ( strlen( trim( $this->settings->get( 'mb_key' ) ) ) !== 10 ) {
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
			'logo'               => plugins_url( '/images/payment-gateways/multibanco-icon.svg', NAKEDCATPLUGINS_IFTHENPAY_FLUENTCART_FILE ), // Frontend
			'icon'               => plugins_url( '/images/payment-gateways/multibanco-icon.svg', NAKEDCATPLUGINS_IFTHENPAY_FLUENTCART_FILE ), // Backend
			'brand_color'        => '#4376BB',
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

		$ifthenpay_fluentcart->log( $this, 'info', 'Starting Multibanco payment request', 'Order: ' . $order->id . ' - Amount: ' . $ifthenpay_fluentcart->format_transaction_value_for_api( $payment_instance->transaction->total ) );

		if ( ! $this->requirements_met() ) {
			$message = sprintf(
					/* translators: %s: Payment method title */
				__( 'The requirements for using %s are not met.', 'multibanco-ifthenpay-for-fluentcart' ),
				'“' . $this->meta()['title'] . '”'
			);
			$ifthenpay_fluentcart->log( $this, 'error', 'Failed Multibanco payment request', 'Order: ' . $order->id . ' - ' . $message, true );
			return array(
				'status'  => 'failed',
				'message' => $message,
			);
		}

		// Get order and set payment method title
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
			$ifthenpay_fluentcart->log( $this, 'info', 'No Multibanco payment request needed', 'Order: ' . $order->id . ' - Amount: ' . $ifthenpay_fluentcart->format_transaction_value_for_api( $payment_instance->transaction->total ) );
			return array(
				'status'      => 'success',
				'message'     => __( 'Order has been placed successfully', 'multibanco-ifthenpay-for-fluentcart' ),
				'redirect_to' => $payment_helper->successUrl( $payment_instance->transaction->uuid ),
			);
		}

		// Payment details
		$mb_key                    = apply_filters( $ifthenpay_fluentcart->hook_prefix . 'base_mb_key', $this->settings->get( 'mb_key' ), $order );
		$value                     = $ifthenpay_fluentcart->format_transaction_value_for_api( $payment_instance->transaction->total ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
		$payment_request_arguments = array(
			'mbKey'       => $mb_key,
			'orderId'     => (string) $order->id,
			'amount'      => $value,
			'description' => $ifthenpay_fluentcart->filter_description_for_api( get_bloginfo( 'name' ) . ' #' . $order->id ),
		);

		// Expiry?
		if ( trim( $this->settings->get( 'expiry' ) ) !== '' && trim( $this->settings->get( 'expiry' ) ) !== '-1' && is_numeric( $this->settings->get( 'expiry' ) ) ) {
			$payment_request_arguments['expiryDays'] = (string) $this->settings->get( 'expiry' );
		}

		// Make API call to ifthenpay to create Multibanco reference - Maybe abstract this in the main class
		$args = array(
			'method'   => 'POST',
			'timeout'  => apply_filters( $ifthenpay_fluentcart->hook_prefix . 'api_timeout', 15 ),
			'blocking' => true,
			'headers'  => array(
				'Content-Type' => 'application/json; charset=utf-8',
			),
			'body'     => wp_json_encode( $payment_request_arguments ),
		);
		// Make the request
		$response = wp_remote_post( $this->api_url, $args );

		$ifthenpay_fluentcart->log( $this, 'info', 'Multibanco payment request', 'Order: ' . $order->id . ' - Data: ' . wp_json_encode( $payment_request_arguments ) );

		// Deal with errors - Step 1
		if ( is_wp_error( $response ) ) {
			$message = sprintf(
				/* translators: %s: Error details */
				__( 'Failed to create payment at ifthenpay API: %s', 'multibanco-ifthenpay-for-fluentcart' ),
				$response->get_error_code() . ' - ' . $response->get_error_message()
			);
			$ifthenpay_fluentcart->log( $this, 'error', 'Failed Multibanco payment request', 'Order: ' . $order->id . ' - ' . $message, true );
			return array(
				'status'  => 'failed',
				'message' => $message,
			);
		}

		// Deal with errors - Step 2
		if ( ! ( isset( $response['response']['code'] ) && intval( $response['response']['code'] ) === 200 && isset( $response['body'] ) && trim( $response['body'] ) !== '' ) ) {
			$message = sprintf(
					/* translators: %s: Response code */
				__( 'Unexpected response from ifthenpay API. Response code: %s', 'multibanco-ifthenpay-for-fluentcart' ),
				isset( $response['response']['code'] ) ? intval( $response['response']['code'] ) : 'N/A'
			);
			$ifthenpay_fluentcart->log( $this, 'error', 'Failed Multibanco payment request', 'Order: ' . $order->id . ' - ' . $message, true );
			return array(
				'status'  => 'failed',
				'message' => $message,
			);
		}

		// Deal with errors - Step 3
		$body = json_decode( $response['body'] );
		if ( ! ( ! empty( $body ) && isset( $body->Status ) && trim( $body->Status ) === '0' ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$message = sprintf(
					/* translators: %s: Response code */
				__( 'An error occurred processing the %s Payment request - please try again', 'multibanco-ifthenpay-for-fluentcart' ),
				'“' . $this->meta()['title'] . '”'
			);
			$ifthenpay_fluentcart->log( $this, 'error', 'Failed Multibanco payment request', 'Order: ' . $order->id . ' - ' . $message, true );
			return array(
				'status'  => 'failed',
				'message' => $message,
			);
		}

		// All seems good - Get the details to store on order
		// Actually this should be stored on transaction
		$details = array(
			'mb_key'    => $mb_key,
			'ent'       => $body->Entity, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			'ref'       => $body->Reference, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			'val'       => $value,
			'RequestId' => $body->RequestId, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			'expire'    => isset( $body->ExpiryDate ) && trim( $body->ExpiryDate ) !== '' ? trim( $body->ExpiryDate ) : '', // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		);
		$ifthenpay_fluentcart->set_payment_details( $this->ifthenpay_id, $order, $payment_instance->transaction, $details['RequestId'], $details );

		// Clear cart
		$ifthenpay_fluentcart->finalize_cart( $order->id );

		// Return with success
		$payment_helper = new PaymentHelper( $this->ifthenpay_id );
		$ifthenpay_fluentcart->log( $this, 'success', 'Successful Multibanco payment request', 'Order: ' . $order->id . ' - Details: ' . wp_json_encode( $details ) );
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
	 * https://dev.fluentcart.com/payment-methods-integration/quick-implementation#with-ipn-webhooks-hosted-payment
	 */
	public function handleIPN(): void {
		global $ifthenpay_fluentcart;

		// Sanitize data
		$data = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		array_walk( $data, 'sanitize_text_field' );

		$ifthenpay_fluentcart->log( $this, 'info', 'Webhook called', 'Data: ' . wp_json_encode( $data ) );

		// Validate webhook key
		if ( ! isset( $data['webhook_key'] ) || trim( $data['webhook_key'] ) === '' || $data['webhook_key'] !== trim( $ifthenpay_fluentcart->webhook_key ) ) {
			$ifthenpay_fluentcart->log( $this, 'error', 'Webhook failed', 'Invalid webhook key - Webhook data: ' . wp_json_encode( $data ), true );
			$ifthenpay_fluentcart->send_callback_response( 403, 'Invalid webhook key', null, $data, true );
			return;
		}

		// Get transaction based on request_id - Maybe abstract this in the main class
		$transaction = OrderTransaction::query()
				->where( 'payment_method', $this->ifthenpay_id )
				->where( 'vendor_charge_id', $data['request_id'] )
				->where( 'total', (int) ( $data['value'] * 100 ) )
				->orderBy( 'id', 'DESC' )
				->first();
		if ( ! $transaction ) {
			$ifthenpay_fluentcart->log( $this, 'error', 'Webhook failed', 'Transaction not found - Webhook data: ' . wp_json_encode( $data ), true );
			$ifthenpay_fluentcart->send_callback_response( 200, 'Transaction not found' ); // Should be 404 but we want to stop ifthenpay from retrying
			return;
		}

		// Check if the transaction is to be processed or not
		if ( ! in_array( $transaction->status, array( Status::TRANSACTION_PENDING ), true ) ) {
			$ifthenpay_fluentcart->log( $this, 'warning', 'Webhook failed', 'Transaction found but not pending payment - Transaction ID: ' . $transaction->id . ' - Order ID: ' . $transaction->order_id );
			$ifthenpay_fluentcart->send_callback_response( 200, 'Transaction found but not pending payment' ); // Should be 404 but we want to stop ifthenpay from retrying
			return;
		}

		// Set the order
		$order = $transaction->order;

		// Get payment order payment details and compare them
		$payment_details = $ifthenpay_fluentcart->get_payment_details( $this->ifthenpay_id, $order );
		if ( empty( $payment_details ) ) {
			$ifthenpay_fluentcart->log( $this, 'error', 'Webhook failed', 'Order found but no payment details are recorded on it - Order ID: ' . $order->id . ' - Webhook data: ' . wp_json_encode( $data ), true );
			$ifthenpay_fluentcart->send_callback_response( 200, 'Order found but no payment details are recorded on it' ); // Should be 404 but we want to stop ifthenpay from retrying
			return;
		}
		if ( $payment_details['ent'] !== $data['entity'] || $payment_details['ref'] !== $data['reference'] || floatval( $payment_details['val'] ) !== floatval( $data['value'] ) ) {
			$ifthenpay_fluentcart->log( $this, 'error', 'Webhook failed', 'Order found but payment details do not match - Order ID: ' . $order->id . ' - Webhook data: ' . wp_json_encode( $data ) . ' - Payment Details: ' . wp_json_encode( $payment_details ), true );
			$ifthenpay_fluentcart->send_callback_response( 200, 'Order found but payment details do not match' ); // Should be 404 but we want to stop ifthenpay from retrying
			return;
		}

		// Set transaction and order as paid
		$transaction->status = Status::TRANSACTION_SUCCEEDED;
		$transaction->fill(
			array(
				'status'           => Status::TRANSACTION_SUCCEEDED,
				'vendor_charge_id' => $data['request_id'], // Already set but just in case
			)
		);
		$transaction->save();
		( new StatusHelper( $order ) )->syncOrderStatuses( $transaction );

		$ifthenpay_fluentcart->log( $this, 'success', 'Webhook succeeded', 'Order found and payment processed successfully - Order ID: ' . $order->id );
		$ifthenpay_fluentcart->send_callback_response( 200, 'Order found and payment processed successfully' );

		do_action( $ifthenpay_fluentcart->hook_prefix . 'payment_completed', $this->ifthenpay_id, $order, $transaction );
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
								/* translators: %1$s: Link start tag, %2$s: Link end tag, %3$s: Payment method name */
								esc_html__( 'You need an active %1$sifthenpay%2$s account with the %3$s payment method enabled.', 'multibanco-ifthenpay-for-fluentcart' ),
								'<a href="' . $ifthenpay_fluentcart->build_out_link( 'https://ifthenpay.com/?lang=en' ) . '" target="_blank">',
								'</a>',
								__( 'Multibanco', 'multibanco-ifthenpay-for-fluentcart' )
							)
						);
						?>
					</li>
					<li>
						<?php
						echo wp_kses_post(
							sprintf(
								/* translators: %s: type of key */
								esc_html__( ' The %s provided by ifthenpay is configured in the settings below.', 'multibanco-ifthenpay-for-fluentcart' ),
								__( 'MB Key', 'multibanco-ifthenpay-for-fluentcart' )
							)
						);
						?>
					</li>
					<li>
						<?php
						echo wp_kses_post(
							sprintf(
								/* translators: %s: type of key */
								esc_html__( ' The same %s is not used in other websites or systems.', 'multibanco-ifthenpay-for-fluentcart' ),
								__( 'MB Key', 'multibanco-ifthenpay-for-fluentcart' )
							)
						);
						?>
					</li>
					<li>
						<?php
						echo wp_kses_post(
							sprintf(
								/* translators: %1$s: Link start tag, %2$s: Link end tag, %3$s: type of key */
								esc_html__( 'The Callback/Webhook URL and Antiphishing Key are set correctly in your account at the %1$sifthenpay backoffice%2$s &gt; Management &gt; Contract/Accounts, on the corresponding %3$s, or by using the button below (available when the %3$s is set).', 'multibanco-ifthenpay-for-fluentcart' ),
								'<a href="' . $ifthenpay_fluentcart->build_out_link( 'https://backoffice.ifthenpay.com/Admin/ContratoContas' ) . '" target="_blank">',
								'</a>',
								__( 'MB Key', 'multibanco-ifthenpay-for-fluentcart' )
							)
						);
						?>
					</li>
				</ul>
				<div class="ifthenpay-webhook-url-antiphishing-key">
					<div>
						<b><?php esc_html_e( 'Callback/Webhook URL:', 'multibanco-ifthenpay-for-fluentcart' ); ?></b>
						<code class="copyable-content" id="ifthenpay-webhook-url"><?php echo esc_html( $this->webhook_url ); // esc_url() causes problems with [] ?></code>
					</div>
					<div>
						<b><?php esc_html_e( 'Antiphishing Key:', 'multibanco-ifthenpay-for-fluentcart' ); ?></b>
						<code class="copyable-content" id="ifthenpay-webhook-key"><?php echo esc_html( $ifthenpay_fluentcart->webhook_key ); ?></code>
					</div>
				</div>
				<?php
				if ( strlen( trim( $this->settings->get( 'mb_key' ) ) ) === 10 ) {
					?>
					<p>
						<a class="el-button el-button--info is-plain" id="ifthenpay-activate-webhook" data-gateway="<?php echo esc_attr( $this->ifthenpay_id ); ?>" data-ent="MB" data-subent="<?php echo esc_attr( $this->settings->get( 'mb_key' ) ); ?>" href="#"><?php esc_html_e( 'Activate Callback/Webhook', 'multibanco-ifthenpay-for-fluentcart' ); ?></a>
						<?php
						$activated = $ifthenpay_fluentcart->get_setting( $this->ifthenpay_id . '_webhook_activated' );
						if ( $activated ) {
							if ( trim( $ifthenpay_fluentcart->get_setting( $this->ifthenpay_id . '_webhook_activated_key' ) === $this->settings->get( 'mb_key' ) ) ) {
								echo '<br>✅ <small>' . esc_html(
									sprintf(
									/* translators: %1$s: The key, %2$s: Date/time */
										esc_html__( ' The Callback/Webhook was last activated for %1$s in %2$s', 'multibanco-ifthenpay-for-fluentcart' ),
										$ifthenpay_fluentcart->get_setting( $this->ifthenpay_id . '_webhook_activated_key' ),
										$activated
									)
								) . '</small>';
							} else {
								echo '<br>⚠️ <small>' . esc_html(
									sprintf(
									/* translators: %1$s: The key, %2$s: Date/time */
										esc_html__( ' The Callback/Webhook was last activated for %1$s in %2$s, which is not the same key you are using now', 'multibanco-ifthenpay-for-fluentcart' ),
										$ifthenpay_fluentcart->get_setting( $this->ifthenpay_id . '_webhook_activated_key' ),
										$activated
									)
								) . '</small>';
							}
						} else {
							echo '<br>‼️ <small class="error fluent-cart">' . esc_html__( ' The Callback/Webhook was not activated yet (or it was configured manually in the ifthenpay backoffice).', 'multibanco-ifthenpay-for-fluentcart' ) . '</small>';
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

		// Missing - We should have a selector for offline mode or mb key mode, if customers request it in the future

		// MB Key
		$fields['mb_key'] = $ifthenpay_fluentcart->settings_field_key( __( 'MB Key', 'multibanco-ifthenpay-for-fluentcart' ) );

		// Missing - Override payment method title?

		// Missing - Override payment method description?

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
			'type'    => 'select',
			'label'   => __( 'Reference expiration', 'multibanco-ifthenpay-for-fluentcart' ),
			'tooltip' => __( 'Number of days until the reference expires (it will always expire at 23:59:59 when the number of days is reached)', 'multibanco-ifthenpay-for-fluentcart' ),
			'options' => $expiry_options, // Why is this not working?
		);

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

<?php
/**
 * ifthenpay Credit Card Payment Gateway for FluentCart
 */

namespace NakedCatPlugins\IfthenpayFluentCart;

use FluentCart\Api\Cookie\Cookie;
use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Services\Payments\PaymentHelper;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ifthenpay Credit Card Payment Gateway Class
 *
 * The customer is sent to the ifthenpay payment page and comes back to our listener with the result.
 * A successful return carries a signature (sk), so the order is set as paid right away. The callback
 * stays active as well, and whichever arrives first sets the order as paid.
 */
class Ifthenpay_Ccard extends Ifthenpay_Gateway {

	/**
	 * Settings key holding the Credit card Key.
	 *
	 * @var string
	 */
	const KEY_FIELD = 'ccard_key';

	/**
	 * ifthenpay entity, used when activating the Callback/Webhook.
	 *
	 * @var string
	 */
	const IFTHENPAY_ENTITY = 'CCARD';

	/**
	 * Credit card Gateway ID.
	 *
	 * @var string
	 */
	public $ifthenpay_id = 'ifthenpay-ccard';

	/**
	 * Credit card Gateway Short ID.
	 *
	 * @var string
	 */
	public $ifthenpay_short_id = 'ccard';

	/**
	 * API URL for ifthenpay Credit card, followed by the key.
	 *
	 * @var string
	 */
	public $api_url = 'https://api.ifthenpay.com/creditcard/init/';

	/**
	 * Sandbox API URL for ifthenpay Credit card, followed by the key.
	 *
	 * @var string
	 */
	public $api_url_sandbox = 'https://api.ifthenpay.com/creditcard/sandbox/init/';

	/**
	 * Brand color.
	 *
	 * @var string
	 */
	protected $brand_color = '#4376bb';

	/**
	 * Payment method name for the debug log.
	 *
	 * @var string
	 */
	protected $log_name = 'Credit card';

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
	protected $payment_details_keys = array( 'ccard_key', 'val', 'RequestId', 'time', 'payment_url' );

	/**
	 * Payment details that must be filled.
	 *
	 * @var array
	 */
	protected $payment_details_required = array( 'RequestId', 'val' );

	/**
	 * Webhook URL attributes.
	 * As used by our WooCommerce plugin, which has [ID], [STATUS] and [REQUEST_ID] working in production.
	 *
	 * @var array
	 */
	protected $webhook_attributes = array(
		'order_id'         => '[ID]',
		'request_id'       => '[REQUEST_ID]',
		'value'            => '[AMOUNT]',
		'status'           => '[STATUS]',
		'payment_datetime' => '[PAYMENT_DATETIME]',
	);

	/**
	 * Required data on webhook.
	 *
	 * @var array
	 */
	protected $webhook_required_data = array( 'plugin', 'request_id', 'value', 'order_id', 'status' );

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
	 * The cart stays open while the customer is on the payment page.
	 *
	 * @var bool
	 */
	protected $complete_cart_on_request = false;

	/**
	 * Nothing for the customer to do after paying, so no instructions in the emails.
	 *
	 * @var bool
	 */
	public $email_instructions = false;

	/**
	 * The ifthenpay key name.
	 *
	 * @return string
	 */
	public static function key_label() {
		return __( 'Credit card Key', 'payment-multibanco-for-fluent-cart-via-ifthenpay' );
	}

	/**
	 * The payment method name.
	 *
	 * @return string
	 */
	public static function method_name() {
		return __( 'Credit or debit card', 'payment-multibanco-for-fluent-cart-via-ifthenpay' );
	}

	/**
	 * Whether payments go to the ifthenpay sandbox, for testing.
	 *
	 * @return bool
	 */
	public function is_sandbox() {
		return (bool) apply_filters( $this->plugin()->hook_prefix . 'ccard_sandbox', false );
	}

	/**
	 * The payment method title.
	 *
	 * @return string
	 */
	protected function title() {
		return __( 'Credit or debit card (ifthenpay)', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ) . ( $this->is_sandbox() ? ' - SANDBOX (TEST MODE)' : '' );
	}

	/**
	 * The payment method description.
	 *
	 * @return string
	 */
	protected function description() {
		return __( 'Easy and simple payment using a Credit or debit card. (Payment service provided by ifthenpay)', 'payment-multibanco-for-fluent-cart-via-ifthenpay' );
	}

	/**
	 * Default settings specific to Credit card.
	 *
	 * @return array
	 */
	protected function settings_defaults() {
		return array(
			'ccard_key' => '',
		);
	}

	/**
	 * The ifthenpay endpoint for the payment request, with the key in the path.
	 *
	 * @param string $key The Credit card Key.
	 * @return string The URL.
	 */
	protected function payment_api_url( $key ) {
		return ( $this->is_sandbox() ? $this->api_url_sandbox : $this->api_url ) . rawurlencode( trim( (string) $key ) );
	}

	/**
	 * Build the arguments for the ifthenpay payment request.
	 *
	 * The ifthenpay API appends id, amount and requestId to the three URLs, and sk to the success one.
	 * The transaction hash identifies the payment on our side, and is not something a customer can guess.
	 *
	 * @param \FluentCart\App\Models\Order            $order       The order object.
	 * @param string                                  $key         The Credit card Key.
	 * @param string                                  $value       The value, formatted for the API.
	 * @param \FluentCart\App\Models\OrderTransaction $transaction The transaction object.
	 * @return array The payment request arguments.
	 */
	protected function build_payment_request( $order, $key, $value, $transaction ) {
		$return_url = add_query_arg(
			array(
				'fluent-cart' => 'fct_payment_listener_ipn',
				'method'      => $this->ifthenpay_id,
				'trx_hash'    => $transaction->uuid,
			),
			site_url()
		);
		return array(
			'orderId'    => (string) $order->id,
			'amount'     => $value,
			'successUrl' => add_query_arg( 'ifthenpay_return', 'success', $return_url ),
			'errorUrl'   => add_query_arg( 'ifthenpay_return', 'error', $return_url ),
			'cancelUrl'  => add_query_arg( 'ifthenpay_return', 'cancel', $return_url ),
			'language'   => substr( trim( get_locale() ), 0, 2 ),
		);
	}

	/**
	 * Build the payment details to store on the order.
	 *
	 * @param object $body  The ifthenpay response body.
	 * @param string $key   The Credit card Key.
	 * @param string $value The value, formatted for the API.
	 * @return array The payment details.
	 */
	protected function build_payment_details( $body, $key, $value ) {
		return array(
			'ccard_key'   => $key,
			'val'         => $value,
			'RequestId'   => $body->RequestId, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			'time'        => date_i18n( 'Y-m-d H:i:s' ),
			'payment_url' => isset( $body->PaymentUrl ) ? trim( (string) $body->PaymentUrl ) : '', // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		);
	}

	/**
	 * Send the customer to the ifthenpay payment page.
	 *
	 * @param \FluentCart\App\Services\Payments\PaymentInstance $payment_instance The payment instance.
	 * @param array                                             $details          The payment details stored on the order.
	 * @return string The URL, or an empty string if ifthenpay did not return a valid one.
	 */
	protected function payment_redirect_url( $payment_instance, $details ) {
		return wp_http_validate_url( $details['payment_url'] ) ? $details['payment_url'] : '';
	}

	/**
	 * The callback also reports refunds (DEVOLVIDO), which we do not handle yet. Only payments (PAGO) are processed.
	 *
	 * @param array $data The webhook data.
	 * @return string The reason, or an empty string to process it.
	 */
	public function webhook_data_error( $data ) {
		if ( $data['status'] !== 'PAGO' ) {
			return 'Status not processed: ' . $data['status'];
		}
		return '';
	}

	/**
	 * Features supported by this gateway: ifthenpay refunds MB WAY and card payments through its API.
	 * Not in Snake Case because required by FluentCart.
	 *
	 * @var array
	 */
	public array $supportedFeatures = array( // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase
		'payment',
		'webhook',
		'refund',
	);

	/**
	 * Initialize gateway.
	 */
	public function boot() {
		parent::boot();
		add_filter( 'fluent_cart/checkout_page_notices', array( $this, 'checkout_page_notices' ) );
	}

	/**
	 * Handle the listener requests: the callback, or the customer coming back from the payment page.
	 */
	public function handleIPN(): void {
		if ( isset( $_GET['ifthenpay_return'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->handle_return();
			return;
		}
		parent::handleIPN();
	}

	/**
	 * The customer is back from the ifthenpay payment page.
	 *
	 * On success, the sk signature is the HMAC-SHA256 of id, amount and requestId, as received, with the
	 * Credit card Key used for the payment. If it checks out, the order is set as paid and the customer goes
	 * to the receipt. If anything does not match, the customer still goes to the receipt, which shows the
	 * payment as pending until the callback confirms it.
	 * On error or cancel, the customer goes back to the checkout, with the cart still there and a message, to try
	 * again. As in the WooCommerce plugin, an error sets the payment as failed and a cancel leaves it pending.
	 * FluentCart reuses the same order, as pending again, when the customer places it again.
	 */
	protected function handle_return() {
		$plugin = $this->plugin();
		$data   = $plugin->request_data();
		$status = isset( $data['ifthenpay_return'] ) ? $data['ifthenpay_return'] : '';

		$plugin->log( $this, 'info', 'Return from payment page', 'Data: ' . $plugin->log_data( $data ) );

		$transaction = OrderTransaction::query()
				->where( 'payment_method', $this->ifthenpay_id )
				->where( 'uuid', isset( $data['trx_hash'] ) ? $data['trx_hash'] : '' )
				->first();
		if ( ! $transaction || ! $transaction->order ) {
			$plugin->log( $this, 'error', 'Return from payment page failed', 'Transaction not found - Data: ' . $plugin->log_data( $data ), null );
			$this->redirect( home_url( '/' ) );
		}
		$order       = $transaction->order;
		$success_url = ( new PaymentHelper( $this->ifthenpay_id ) )->successUrl( $transaction->uuid );

		// Already paid, probably by the callback
		if ( $transaction->status === Status::TRANSACTION_SUCCEEDED ) {
			$plugin->log( $this, 'info', 'Return from payment page', 'Transaction already paid - Order ID: ' . $order->id );
			$this->redirect( $success_url );
		}

		if ( $status !== 'success' ) {
			$failed = $status === 'error';
			if ( $failed ) {
				$updated = OrderTransaction::query()
						->where( 'id', $transaction->id )
						->where( 'status', Status::TRANSACTION_PENDING )
						->update( array( 'status' => Status::TRANSACTION_FAILED ) );
				if ( $updated && $order->payment_status === Status::PAYMENT_PENDING ) {
					$order->payment_status = Status::PAYMENT_FAILED;
					$order->save();
				}
			}
			fluent_cart_add_log(
				$failed ? __( 'Credit or debit card payment failed at ifthenpay', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ) : __( 'Credit or debit card payment cancelled by the customer at ifthenpay', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
				$failed ? __( 'The customer was sent back to the checkout to try again.', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ) : __( 'The customer left the ifthenpay payment page without paying and was sent back to the checkout.', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
				$failed ? 'error' : 'info',
				array(
					'module_name' => 'order',
					'module_id'   => $order->id,
				)
			);
			$plugin->log( $this, 'warning', 'Return from payment page', 'Payment not completed (' . $status . ') - Order ID: ' . $order->id );
			$this->redirect( $this->checkout_url( $order, $failed ? 'failed' : 'cancelled' ) );
		}

		$error = $this->return_data_error( $data, $transaction );
		if ( $error !== '' ) {
			$plugin->log( $this, 'error', 'Return from payment page failed', $error . ' - Order ID: ' . $order->id . ' - Data: ' . $plugin->log_data( $data ), null );
			$this->redirect( $success_url );
		}

		if ( $plugin->mark_transaction_paid( $this, $transaction ) ) {
			$plugin->log( $this, 'success', 'Return from payment page succeeded', 'Payment processed successfully - Order ID: ' . $order->id );
		} else {
			$plugin->log( $this, 'info', 'Return from payment page', 'Transaction already being processed - Order ID: ' . $order->id );
		}
		$this->redirect( $success_url );
	}

	/**
	 * Check a successful return against the payment we requested.
	 *
	 * @param array                                   $data        The return data.
	 * @param \FluentCart\App\Models\OrderTransaction $transaction The transaction object.
	 * @return string The reason it is not valid, or an empty string if it is.
	 */
	protected function return_data_error( $data, $transaction ) {
		foreach ( array( 'id', 'amount', 'requestid', 'sk' ) as $field ) {
			if ( ! isset( $data[ $field ] ) || $data[ $field ] === '' ) {
				return 'Missing ' . $field;
			}
		}
		if ( $transaction->status !== Status::TRANSACTION_PENDING ) {
			return 'Transaction not pending payment';
		}
		$payment_details = $this->get_payment_details( $transaction->order );
		if ( ! $payment_details ) {
			return 'No payment details recorded on the order';
		}
		if ( $data['requestid'] !== (string) $transaction->vendor_charge_id || $data['requestid'] !== $payment_details['RequestId'] ) {
			return 'Request ID does not match';
		}
		if ( (int) $data['id'] !== (int) $transaction->order_id ) {
			return 'Order ID does not match';
		}
		if ( $this->plugin()->to_cents( $data['amount'] ) !== (int) $transaction->total ) {
			return 'Value does not match';
		}
		// Signed with the values exactly as received, so 7.40 stays 7.40
		$sk = hash_hmac( 'sha256', $data['id'] . $data['amount'] . $data['requestid'], trim( $payment_details['ccard_key'] ) );
		if ( ! hash_equals( $sk, $data['sk'] ) ) {
			return 'Signature (sk) does not match';
		}
		return '';
	}

	/**
	 * The checkout, with the order's cart, so the customer can try again.
	 *
	 * FluentCart only finds an "instant" cart (Buy now) by fct_cart_hash. With that argument and a regular cart,
	 * the checkout shows as empty, so a regular cart is found the usual way, by the cart cookie, which is set
	 * again in case it is gone.
	 *
	 * @param \FluentCart\App\Models\Order $order  The order object.
	 * @param string                       $notice The message to show: 'failed' or 'cancelled'.
	 * @return string The URL.
	 */
	protected function checkout_url( $order, $notice ) {
		$url  = ( new StoreSettings() )->getCheckoutPage();
		$cart = Cart::query()->where( 'order_id', $order->id )->where( 'stage', '!=', 'completed' )->first();
		if ( $cart ) {
			if ( $cart->cart_group === 'instant' ) {
				$url = add_query_arg( 'fct_cart_hash', $cart->cart_hash, $url );
			} elseif ( class_exists( Cookie::class ) ) {
				Cookie::setCartHash( $cart->cart_hash );
			}
		}
		return add_query_arg( 'ifthenpay_ccard', $notice, $url );
	}

	/**
	 * Message on the checkout when the customer is back from a failed or cancelled card payment.
	 *
	 * @param array $notices The notices.
	 * @return array The notices.
	 */
	public function checkout_page_notices( $notices ) {
		$notice   = isset( $_GET['ifthenpay_ccard'] ) ? sanitize_key( wp_unslash( $_GET['ifthenpay_ccard'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$messages = array(
			'failed'    => __( 'The card payment failed, and nothing was charged. Please try again, with another card or another payment method.', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
			'cancelled' => __( 'The card payment was cancelled, and nothing was charged. Please try again, or choose another payment method.', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
		);
		if ( isset( $messages[ $notice ] ) ) {
			$notices[] = array(
				'content' => esc_html( $messages[ $notice ] ),
			);
		}
		return $notices;
	}

	/**
	 * Redirect and end the request.
	 *
	 * @param string $url The URL.
	 */
	protected function redirect( $url ) {
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Rows to show on the thank you page while the payment is pending.
	 * The customer either left the payment page without paying, or the confirmation has not arrived yet.
	 *
	 * @param \FluentCart\App\Models\Order $order           The order object.
	 * @param array                        $payment_details The payment details.
	 * @return array The rows.
	 */
	protected function thank_you_page_pending_rows( $order, $payment_details ) {
		return array(
			__( 'Value', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ) => $this->plugin()->format_price( $payment_details['val'] ),
			'action_html' => sprintf(
				/* translators: %1$s: Link start tag, %2$s: Link end tag */
				__( 'The payment has not been confirmed yet. If you did not complete it, %1$splease try again%2$s.', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
				'<a href="' . esc_url( PaymentHelper::getCustomPaymentLink( $order->uuid ) ) . '">',
				'</a>'
			),
		);
	}
}

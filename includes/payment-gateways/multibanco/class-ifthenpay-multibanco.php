<?php
/**
 * ifthenpay Multibanco Payment Gateway for FluentCart
 */

namespace NakedCatPlugins\IfthenpayFluentCart;

use FluentCart\App\Helpers\Status;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ifthenpay Multibanco Payment Gateway Class
 */
class Ifthenpay_Multibanco extends Ifthenpay_Gateway {

	/**
	 * Settings key holding the MB Key.
	 *
	 * @var string
	 */
	const KEY_FIELD = 'mb_key';

	/**
	 * ifthenpay entity, used when activating the Callback/Webhook.
	 *
	 * @var string
	 */
	const IFTHENPAY_ENTITY = 'MB';

	/**
	 * Multibanco Gateway ID.
	 *
	 * @var string
	 */
	public $ifthenpay_id = 'ifthenpay-multibanco';

	/**
	 * Multibanco Gateway Short ID.
	 *
	 * @var string
	 */
	public $ifthenpay_short_id = 'multibanco';

	/**
	 * API URL for ifthenpay Multibanco.
	 *
	 * @var string
	 */
	public $api_url = 'https://api.ifthenpay.com/multibanco/reference/init';

	/**
	 * Brand color.
	 *
	 * @var string
	 */
	protected $brand_color = '#047bc0';

	/**
	 * Payment method name for the debug log.
	 *
	 * @var string
	 */
	protected $log_name = 'Multibanco';

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
	protected $payment_details_keys = array( 'mb_key', 'ent', 'ref', 'val', 'RequestId', 'expire' );

	/**
	 * Payment details that must be filled.
	 *
	 * @var array
	 */
	protected $payment_details_required = array( 'ent', 'ref', 'val' );

	/**
	 * Webhook URL attributes.
	 *
	 * @var array
	 */
	protected $webhook_attributes = array(
		'request_id'       => '[REQUEST_ID]',
		'value'            => '[AMOUNT]',
		'entity'           => '[ENTITY]',
		'reference'        => '[REFERENCE]',
		'payment_datetime' => '[PAYMENT_DATETIME]',
		'payment_fee'      => '[FEE]',
	);

	/**
	 * Required data on webhook.
	 *
	 * @var array
	 */
	protected $webhook_required_data = array( 'plugin', 'request_id', 'value', 'entity', 'reference' );

	/**
	 * Matching data between payment details stored and webhook data.
	 *
	 * @var array
	 */
	protected $webhook_matching_data = array(
		'ent' => 'entity',
		'ref' => 'reference',
		'val' => 'value',
	);

	/**
	 * The ifthenpay key name.
	 *
	 * @return string
	 */
	public static function key_label() {
		return __( 'MB Key', 'payment-multibanco-for-fluent-cart-via-ifthenpay' );
	}

	/**
	 * The payment method name.
	 *
	 * @return string
	 */
	public static function method_name() {
		return __( 'Multibanco', 'payment-multibanco-for-fluent-cart-via-ifthenpay' );
	}

	/**
	 * The payment method title.
	 *
	 * @return string
	 */
	protected function title() {
		return __( 'Multibanco Payment of Services (ifthenpay)', 'payment-multibanco-for-fluent-cart-via-ifthenpay' );
	}

	/**
	 * The payment method description.
	 *
	 * @return string
	 */
	protected function description() {
		return __( 'Easy and simple payment using “Payment of Services” at any “Multibanco” ATM terminal or your home banking service. (Only available to customers of Portuguese banks - Payment service provided by ifthenpay)', 'payment-multibanco-for-fluent-cart-via-ifthenpay' );
	}

	/**
	 * Default settings specific to Multibanco.
	 *
	 * @return array
	 */
	protected function settings_defaults() {
		return array(
			'mb_key' => '',
			'expiry' => '',
		);
	}

	/**
	 * Build the arguments for the ifthenpay payment request.
	 *
	 * @param \FluentCart\App\Models\Order $order The order object.
	 * @param string                       $key   The MB Key.
	 * @param string                       $value The value, formatted for the API.
	 * @return array The payment request arguments.
	 */
	protected function build_payment_request( $order, $key, $value ) {
		$payment_request_arguments = array(
			'mbKey'       => $key,
			'orderId'     => (string) $order->id,
			'amount'      => $value,
			'description' => $this->plugin()->filter_description_for_api( get_bloginfo( 'name' ) . ' #' . $order->id ),
		);
		// Expiry?
		if ( trim( $this->settings->get( 'expiry' ) ) !== '' && trim( $this->settings->get( 'expiry' ) ) !== '-1' && is_numeric( $this->settings->get( 'expiry' ) ) ) {
			$payment_request_arguments['expiryDays'] = (string) $this->settings->get( 'expiry' );
		}
		return $payment_request_arguments;
	}

	/**
	 * Build the payment details to store on the order.
	 *
	 * @param object $body  The ifthenpay response body.
	 * @param string $key   The MB Key.
	 * @param string $value The value, formatted for the API.
	 * @return array The payment details.
	 */
	protected function build_payment_details( $body, $key, $value ) {
		return array(
			'mb_key'    => $key,
			'ent'       => $body->Entity, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			'ref'       => $body->Reference, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			'val'       => $value,
			'RequestId' => $body->RequestId, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			'expire'    => isset( $body->ExpiryDate ) && trim( $body->ExpiryDate ) !== '' ? trim( $body->ExpiryDate ) : '', // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		);
	}

	/**
	 * Send FluentCart's offline payment order confirmation, as cash on delivery does,
	 * so the customer and the store are told about an order that is paid later, at an ATM
	 * or home banking. Renewal orders are skipped, as FluentCart does.
	 *
	 * @param \FluentCart\App\Services\Payments\PaymentInstance $payment_instance The payment instance.
	 */
	protected function after_payment_request( $payment_instance ) {
		$order = $payment_instance->order;
		if ( $order->type === Status::ORDER_TYPE_RENEWAL ) {
			return;
		}
		if ( ! $order->customer ) {
			$order->customer = $order->customer()->first();
		}
		$order->load( array( 'customer', 'shipping_address', 'billing_address' ) );
		do_action(
			'fluent_cart/order_placed_offline', // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- FluentCart's own hook
			array(
				'order'       => $order,
				'customer'    => $order->customer ?? array(),
				'transaction' => $payment_instance->transaction ?? array(),
			)
		);
	}

	/**
	 * Rows to show on the thank you page while the payment is pending.
	 *
	 * @param \FluentCart\App\Models\Order $order           The order object.
	 * @param array                        $payment_details The payment details.
	 * @return array The rows.
	 */
	protected function thank_you_page_pending_rows( $order, $payment_details ) {
		$rows = array(
			__( 'Entity', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ) => $payment_details['ent'],
			__( 'Reference', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ) => $payment_details['ref'],
			__( 'Value', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ) => $this->plugin()->format_price( $payment_details['val'] ),
		);
		if ( isset( $payment_details['expire'] ) && trim( $payment_details['expire'] ) !== '' ) {
			$rows[ __( 'Expiration', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ) ] = $payment_details['expire'];
		}
		return $rows;
	}

	/**
	 * Settings fields specific to Multibanco.
	 *
	 * @return array The settings fields.
	 */
	protected function extra_fields() {
		// Missing - We should have a selector for offline mode or mb key mode, if customers request it in the future

		// Reference expire time
		$expiry_options = array(
			array(
				'value' => '-1',
				'label' => __( 'No expiration', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
			),
			array(
				'value' => '0',
				'label' => __( 'Same day at 23:59:59', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
			),
		);
		for ( $i = 1; $i <= 31; $i++ ) {
			$expiry_options[] =
			array(
				'value' => strval( $i ),
				'label' => sprintf(
					/* translators: %d: number of days */
					_n( '%d day', '%d days', $i, 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
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
					_n( '%d day', '%d days', $i, 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
					$i
				),
			);
		}
		return array(
			'expiry' => array(
				'type'    => 'select',
				'label'   => __( 'Reference expiration', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
				'tooltip' => __( 'Number of days until the reference expires (it will always expire at 23:59:59 when the number of days is reached)', 'payment-multibanco-for-fluent-cart-via-ifthenpay' ),
				'options' => $expiry_options, // Why is this not working?
			),
		);
	}
}

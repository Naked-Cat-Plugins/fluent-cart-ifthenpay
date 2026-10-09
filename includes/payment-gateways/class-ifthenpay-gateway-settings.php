<?php
/**
 * Settings for the ifthenpay Payment Gateways for FluentCart
 */

namespace NakedCatPlugins\IfthenpayFluentCart;

use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;
use FluentCart\Api\StoreSettings;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ifthenpay Payment Gateway Settings Class, shared by all our payment methods
 */
class Ifthenpay_Gateway_Settings extends BaseGatewaySettings {

	/**
	 * FluentCart Method handler.
	 * The meta key FluentCart stores this payment method's settings under.
	 *
	 * @var string
	 */
	public $methodHandler; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase

	/**
	 * Gateway settings.
	 *
	 * @var array
	 */
	public $settings;

	/**
	 * Store settings instance.
	 * Not in Snake Case because required by FluentCart.
	 *
	 * @var StoreSettings|null
	 */
	public $storeSettings = null; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase

	/**
	 * Constructor
	 *
	 * @param string $method_handler   The meta key FluentCart stores the settings under.
	 * @param array  $gateway_defaults Defaults specific to the payment method, merged over getDefaults().
	 */
	public function __construct( $method_handler, $gateway_defaults = array() ) {
		$this->methodHandler = $method_handler; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		parent::__construct();

		// Get current settings and defaults
		// FluentCart only knows about the static getDefaults(), so the payment method's own defaults are applied here
		$settings = $this->getCachedSettings();
		$defaults = array_merge( static::getDefaults(), $gateway_defaults );
		if ( ! $settings || ! is_array( $settings ) || empty( $settings ) ) {
			$settings = $defaults;
		} else {
			$settings = wp_parse_args( $settings, $defaults );
		}
		$this->settings = $settings;

		// Get store settings
		if ( ! $this->storeSettings ) { //phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$this->storeSettings = Ifthenpay_Fluentcart::get_instance()->store_settings; //phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}
	}

	/**
	 * Get the default settings common to all our payment methods.
	 *
	 * @return array The default settings.
	 */
	public static function getDefaults(): array {
		return array(
			'is_active'  => 'no',
			'only_from'  => '',
			'only_up_to' => '',
			'debug'      => 'yes',
		);
	}

	/**
	 * Get a setting value by key.
	 *
	 * @param string $key The setting key. If empty, returns all settings.
	 * @return mixed The setting value or all settings.
	 */
	public function get( $key = '' ) {
		if ( empty( $key ) ) {
			return $this->settings;
		}
		return isset( $this->settings[ $key ] ) ? $this->settings[ $key ] : null;
	}

	/**
	 * Get the payment mode (test or live).
	 * We have no test mode, so this is always an empty string.
	 *
	 * @return string The payment mode.
	 */
	public function getMode(): string {
		return (string) $this->get( 'payment_mode' );
	}

	/**
	 * Check if the payment gateway is active.
	 *
	 * @return bool True if active, false otherwise.
	 */
	public function isActive(): bool {
		return $this->get( 'is_active' ) === 'yes';
	}
}

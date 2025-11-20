<?php
/**
 * ifthenpay MB WAY Payment Gateway for FluentCart
 */

namespace NakedCatPlugins\IfthenpayFluentCart;

use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;
use FluentCart\Api\StoreSettings;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ifthenpay Mbway Payment Gateway Settings Class
 */
class Ifthenpay_Mbway_Settings_Base extends BaseGatewaySettings {

	/**
	 * FluentCart Method handler.
	 * This seems to be used by FluentCart for whatever reason.
	 *
	 * @var string
	 */
	public $methodHandler = 'fluent_cart_payment_settings_ifthenpay_mbway'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase

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
	 */
	public function __construct() {
		parent::__construct();

		// Get current settings and defaults
		$settings = $this->getCachedSettings();
		$defaults = static::getDefaults();
		if ( ! $settings || ! is_array( $settings ) || empty( $settings ) ) {
			$settings = $defaults;
		} else {
			$settings = wp_parse_args( $settings, $defaults );
		}
		$this->settings = $settings;

		// Get store settings
		if ( ! $this->storeSettings ) { //phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			global $ifthenpay_fluentcart;
			$this->storeSettings = $ifthenpay_fluentcart->store_settings; //phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}
	}

	/**
	 * Get the default settings for the gateway.
	 *
	 * @return array The default settings.
	 */
	public static function getDefaults(): array {
		return array(
			'is_active'  => 'no',
			'mbway_key'  => '',
			'do_refunds' => '', // For the future
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
	 *
	 * @return string The payment mode.
	 */
	public function getMode(): string {
		return $this->get( 'payment_mode' );
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

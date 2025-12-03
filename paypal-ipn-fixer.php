<?php
/**
 * Plugin Name: PayPal IPN Fixer
 * Plugin URI: https://github.com/nicdwilson/paypal-ipn-fixer
 * Description: Fixes a bug in WooCommerce Subscriptions where renewal orders are not created when PayPal sends IPN notifications with -wcsfrp- in the invoice for renewal sign-ups after failure. Sets _paypal_failed_sign_up_recorded meta to prevent sign-up after failure flag.
 * Version: 1.2.0
 * Author: WooCommerce Growth Team
 * Author URI: https://woocommerce.com
 * Text Domain: paypal-ipn-fixer
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * WC requires at least: 3.0
 * WC tested up to: 8.0
 *
 * @package PayPal_IPN_Fixer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Main PayPal IPN Fixer Class
 */
class PayPal_IPN_Fixer {

	/**
	 * Logger instance
	 *
	 * @var WC_Logger
	 */
	private $logger;

	/**
	 * Singleton instance
	 *
	 * @var PayPal_IPN_Fixer
	 */
	private static $instance = null;

	/**
	 * Get singleton instance
	 *
	 * @return PayPal_IPN_Fixer
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		$this->logger = wc_get_logger();
		$this->init_hooks();
	}

	/**
	 * Initialize hooks
	 */
	private function init_hooks() {
		// Hook into valid IPN request at priority -10 (before Subscriptions at 0)
		// We'll set _paypal_failed_sign_up_recorded meta BEFORE Subscriptions processes,
		// so when Subscriptions checks line 195, it will see the order ID already matches
		// and won't set $is_renewal_sign_up_after_failure to true
		add_action( 'valid-paypal-standard-ipn-request', array( $this, 'prevent_renewal_signup_after_failure_flag' ), -10 );
	}

	/**
	 * Prevent $is_renewal_sign_up_after_failure from being set to true
	 * 
	 * By setting _paypal_failed_sign_up_recorded to match the order ID from invoice
	 * BEFORE Subscriptions processes the IPN, the condition on line 195 will fail:
	 * 
	 * if ( wcs_get_objects_property( $transaction_order, 'id' ) !== $subscription->get_meta( '_paypal_failed_sign_up_recorded', true ) ) {
	 *     $is_renewal_sign_up_after_failure = true;
	 * }
	 * 
	 * If the order ID matches _paypal_failed_sign_up_recorded, $is_renewal_sign_up_after_failure
	 * stays false, and Subscriptions will process it as a normal renewal.
	 */
	public function prevent_renewal_signup_after_failure_flag( $transaction_details ) {
		// Only process subscr_payment transactions
		if ( ( $transaction_details['txn_type'] ?? '' ) !== 'subscr_payment' ) {
			$this->logger->debug( 
				'PayPal IPN Fixer: Skipping - not a subscr_payment transaction',
				array( 
					'source' => 'paypal-ipn-fixer',
					'txn_type' => $transaction_details['txn_type'] ?? 'NOT_SET',
				)
			);
			return;
		}

		$invoice = $transaction_details['invoice'] ?? '';
		
		// Check if invoice contains -wcsfrp- (renewal sign-up after failure)
		if ( false === strpos( $invoice, '-wcsfrp-' ) ) {
			$this->logger->debug( 
				'PayPal IPN Fixer: Skipping - invoice does not contain -wcsfrp-',
				array( 
					'source' => 'paypal-ipn-fixer',
					'invoice' => $invoice,
				)
			);
			return;
		}

		// Extract order ID from invoice (everything after the last dash)
		$order_id_from_invoice = substr( $invoice, strrpos( $invoice, '-' ) + 1 );
		if ( ! is_numeric( $order_id_from_invoice ) ) {
			$this->logger->warning( 
				'PayPal IPN Fixer: Skipping - could not extract numeric order ID from invoice',
				array( 
					'source' => 'paypal-ipn-fixer',
					'invoice' => $invoice,
					'extracted_order_id' => $order_id_from_invoice,
				)
			);
			return;
		}

		$old_order = wc_get_order( $order_id_from_invoice );
		if ( ! $old_order ) {
			$this->logger->warning( 
				'PayPal IPN Fixer: Skipping - order not found',
				array( 
					'source' => 'paypal-ipn-fixer',
					'order_id' => $order_id_from_invoice,
					'invoice' => $invoice,
				)
			);
			return;
		}

		// Check if the old order is a renewal order (not a parent order)
		$is_renewal_order = function_exists( 'wcs_order_contains_renewal' ) && wcs_order_contains_renewal( $old_order );
		$is_parent_order = function_exists( 'wcs_order_contains_parent' ) && wcs_order_contains_parent( $old_order );

		// Only fix if it's a renewal order (not a parent order)
		if ( ! $is_renewal_order || $is_parent_order ) {
			$this->logger->debug( 
				'PayPal IPN Fixer: Skipping - order is not a renewal order or is a parent order',
				array( 
					'source' => 'paypal-ipn-fixer',
					'order_id' => $order_id_from_invoice,
					'is_renewal_order' => $is_renewal_order,
					'is_parent_order' => $is_parent_order,
				)
			);
			return;
		}

		// Get subscription from custom field
		$custom = $transaction_details['custom'] ?? '';
		if ( empty( $custom ) ) {
			$this->logger->warning( 
				'PayPal IPN Fixer: Skipping - custom field is empty',
				array( 
					'source' => 'paypal-ipn-fixer',
					'invoice' => $invoice,
					'order_id' => $order_id_from_invoice,
				)
			);
			return;
		}

		$custom_data = json_decode( $custom, true );
		if ( ! is_array( $custom_data ) || empty( $custom_data['subscription_id'] ) ) {
			$this->logger->warning( 
				'PayPal IPN Fixer: Skipping - could not parse custom field or subscription_id missing',
				array( 
					'source' => 'paypal-ipn-fixer',
					'invoice' => $invoice,
					'order_id' => $order_id_from_invoice,
					'custom' => $custom,
				)
			);
			return;
		}

		$subscription_id = absint( $custom_data['subscription_id'] );
		$subscription = function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( $subscription_id ) : null;

		if ( ! $subscription ) {
			$this->logger->warning( 
				'PayPal IPN Fixer: Skipping - subscription not found',
				array( 
					'source' => 'paypal-ipn-fixer',
					'subscription_id' => $subscription_id,
					'invoice' => $invoice,
					'order_id' => $order_id_from_invoice,
				)
			);
			return;
		}

		// Set _paypal_failed_sign_up_recorded to match the order ID from invoice
		// This will cause Subscriptions' check on line 195 to fail, preventing
		// $is_renewal_sign_up_after_failure from being set to true
		$current_recorded = $subscription->get_meta( '_paypal_failed_sign_up_recorded', true );
		
		// Always update the meta to ensure it matches, even if it's already set
		// This ensures the fix works even if the meta was set from a previous IPN
		$subscription->update_meta_data( '_paypal_failed_sign_up_recorded', $order_id_from_invoice );
		$subscription->save();
		
		$this->logger->info( 
			'PayPal IPN Fixer: Set _paypal_failed_sign_up_recorded to prevent $is_renewal_sign_up_after_failure flag',
			array( 
				'source' => 'paypal-ipn-fixer',
				'subscription_id' => $subscription_id,
				'old_order_id' => $order_id_from_invoice,
				'previous_recorded' => $current_recorded,
				'new_recorded' => $order_id_from_invoice,
				'txn_id' => $transaction_details['txn_id'] ?? 'NOT_SET',
				'invoice' => $invoice,
			)
		);
	}
}

/**
 * Initialize the plugin
 */
function paypal_ipn_fixer_init() {
	// Only initialize if WooCommerce and WooCommerce Subscriptions are active
	if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Subscriptions' ) ) {
		return;
	}

	PayPal_IPN_Fixer::instance();
}
add_action( 'plugins_loaded', 'paypal_ipn_fixer_init', 20 );


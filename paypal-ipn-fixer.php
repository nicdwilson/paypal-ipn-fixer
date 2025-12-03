<?php
/**
 * Plugin Name: PayPal IPN Fixer
 * Plugin URI: https://github.com/nicdwilson/paypal-ipn-fixer
 * Description: Fixes a bug in WooCommerce Subscriptions where renewal orders are not created when PayPal sends IPN notifications with -wcsfrp- in the invoice for renewal sign-ups after failure. Creates new renewal orders when payments are processed on old failed orders.
 * Version: 1.3.0
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
	 * Store the fixed invoice for this IPN request
	 * 
	 * @var string
	 */
	private static $fixed_invoice = null;
	

	/**
	 * Initialize hooks
	 */
	private function init_hooks() {
		// Hook into valid IPN request at priority -10 (before Subscriptions at 0)
		add_action( 'valid-paypal-standard-ipn-request', array( $this, 'fix_renewal_order_creation' ), -10 );
		
		// Filter meta retrieval to cast _paypal_failed_sign_up_recorded to integer
		// WordPress stores all meta as strings, but Subscriptions uses strict comparison (line 195)
		// This ensures the comparison works correctly
		// Note: WooCommerce CRUD objects use this filter pattern: woocommerce_{object_type}_get_{meta_key}
		add_filter( 'woocommerce_subscription_get__paypal_failed_sign_up_recorded', array( $this, 'cast_meta_to_integer' ), 10, 2 );
		
		// Also hook into get_post_meta as a fallback (for older WooCommerce versions or direct meta access)
		add_filter( 'get_post_metadata', array( $this, 'cast_post_meta_to_integer' ), 10, 4 );
	}

	/**
	 * Fix renewal order creation for -wcsfrp- IPNs
	 * 
	 * The problem: When PayPal sends IPN with -wcsfrp-{order_id} in the invoice,
	 * Subscriptions loads the old failed order and processes payment against it
	 * instead of creating a new renewal order.
	 * 
	 * Solution: 
	 * Set _paypal_failed_sign_up_recorded meta to prevent $is_renewal_sign_up_after_failure flag,
	 * ensuring Subscriptions creates a new renewal order instead of using the old failed order
	 */
	public function fix_renewal_order_creation( $transaction_details ) {
		// Only process subscr_payment transactions
		if ( ( $transaction_details['txn_type'] ?? '' ) !== 'subscr_payment' ) {
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
		
		// Convert to integer to match the type returned by wcs_get_objects_property() at line 195
		// This ensures the strict comparison ( !== ) works correctly
		$order_id_from_invoice = absint( $order_id_from_invoice );

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

		// Store the fixed invoice (without -wcsfrp- suffix) for potential use
		self::$fixed_invoice = preg_replace( '/-wcsfrp-\d+$/', '', $invoice );
		
		// Set _paypal_failed_sign_up_recorded to match the order ID from invoice
		// This will cause Subscriptions' check on line 195 to pass (strict comparison),
		// preventing $is_renewal_sign_up_after_failure from being set to true
		// Note: We use absint() to ensure integer type matches wcs_get_objects_property() return type
		$current_recorded = $subscription->get_meta( '_paypal_failed_sign_up_recorded', true );
		
		// Always update the meta to ensure it matches, even if it's already set
		// This ensures the fix works even if the meta was set from a previous IPN
		// Store as integer to match the type returned by wcs_get_objects_property( $transaction_order, 'id' )
		$subscription->update_meta_data( '_paypal_failed_sign_up_recorded', $order_id_from_invoice );
		$subscription->save();
		
		$this->logger->info( 
			'PayPal IPN Fixer: Set _paypal_failed_sign_up_recorded',
			array( 
				'source' => 'paypal-ipn-fixer',
				'subscription_id' => $subscription_id,
				'old_order_id' => $order_id_from_invoice,
				'previous_recorded' => $current_recorded,
				'new_recorded' => $order_id_from_invoice,
				'invoice' => $invoice,
			)
		);
	}
	
	/**
	 * Cast _paypal_failed_sign_up_recorded meta value to integer
	 * 
	 * WordPress stores all meta values as strings, but Subscriptions uses strict comparison
	 * at line 195. This filter ensures the meta value is returned as an integer to match
	 * the type returned by wcs_get_objects_property( $transaction_order, 'id' )
	 * 
	 * @param mixed $value The meta value
	 * @param WC_Subscription $subscription The subscription object
	 * @return int|mixed The meta value cast to integer if it's numeric, otherwise the original value
	 */
	public function cast_meta_to_integer( $value, $subscription ) {
		// Only cast if the value is numeric (could be empty string or null)
		if ( is_numeric( $value ) ) {
			return absint( $value );
		}
		return $value;
	}
	
	/**
	 * Cast _paypal_failed_sign_up_recorded meta value to integer when retrieved via get_post_meta
	 * 
	 * This is a fallback for cases where meta is accessed directly via get_post_meta()
	 * rather than through WooCommerce's CRUD API
	 * 
	 * @param mixed $value The meta value
	 * @param int $object_id The object ID
	 * @param string $meta_key The meta key
	 * @param bool $single Whether to return a single value
	 * @return int|mixed The meta value cast to integer if it's numeric and the key matches
	 */
	public function cast_post_meta_to_integer( $value, $object_id, $meta_key, $single ) {
		// Only process our specific meta key
		if ( '_paypal_failed_sign_up_recorded' !== $meta_key ) {
			return $value;
		}
		
		// Only cast if the value is numeric (could be empty string or null)
		if ( is_numeric( $value ) ) {
			return absint( $value );
		}
		return $value;
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


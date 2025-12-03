# PayPal IPN Fixer

Fixes a bug in WooCommerce Subscriptions where renewal orders are not created when PayPal sends IPN notifications with `-wcsfrp-` in the invoice for renewal sign-ups after failure.

## Problem

When a subscription payment fails and the customer signs up again, PayPal sends IPN notifications with `-wcsfrp-{order_id}` in the invoice field. WooCommerce Subscriptions has a bug where it processes payments on the old failed order instead of creating new renewal orders for each payment.

### Symptoms

- Order notes are added to the old failed renewal order for each subscription payment
- No new renewal orders are created for subsequent payments
- Multiple "IPN subscription payment completed" notes appear on the same old order
- The subscription continues to receive payments but renewal orders are not tracked properly

### Root Cause

In `class-wcs-paypal-standard-ipn-handler.php`:

1. **Line 192**: When invoice contains `-wcsfrp-{order_id}`, Subscriptions extracts the order ID and loads the old failed order
2. **Line 195**: Subscriptions checks if this is a renewal sign-up after failure using strict comparison (`!==`)
3. **Line 299**: Renewal order creation is skipped when `$is_renewal_sign_up_after_failure` is `true`
4. **Line 425**: Payment is processed on the old failed order instead of creating a new renewal order

The strict comparison at line 195 fails due to a type mismatch:
- `wcs_get_objects_property( $transaction_order, 'id' )` returns an **integer**
- `$subscription->get_meta( '_paypal_failed_sign_up_recorded', true )` returns a **string** (WordPress stores all meta as strings)

Even when the values match (e.g., `123` vs `"123"`), the strict comparison `!==` fails, causing `$is_renewal_sign_up_after_failure` to be set to `true`, which skips renewal order creation.

## Solution

This plugin fixes the issue by:

1. **Setting the meta value correctly**: Before Subscriptions processes the IPN (priority -10), the plugin sets `_paypal_failed_sign_up_recorded` to the old order ID as an **integer**, ensuring it matches the type returned by `wcs_get_objects_property()`.

2. **Type casting on retrieval**: The plugin uses filters to cast the meta value to an integer when retrieved, ensuring the strict comparison at line 195 works correctly:
   - `woocommerce_subscription_get__paypal_failed_sign_up_recorded` - For WooCommerce CRUD API
   - `get_post_metadata` - Fallback for direct `get_post_meta()` calls

3. **Result**: With `$is_renewal_sign_up_after_failure = false`, the renewal order creation block at line 299 runs, and Subscriptions creates a new renewal order for each payment.

## Installation

1. Upload the plugin files to `/wp-content/plugins/paypal-ipn-fixer/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. The plugin will automatically start fixing IPN processing for subscriptions with `-wcsfrp-` in the invoice

## Requirements

- WordPress 5.0 or higher
- PHP 7.2 or higher
- WooCommerce 3.0 or higher
- WooCommerce Subscriptions (any version)

## How It Works

1. The plugin hooks into `valid-paypal-standard-ipn-request` at priority -10 (before Subscriptions processes at priority 0)
2. For `subscr_payment` transactions with `-wcsfrp-` in the invoice:
   - Extracts the old order ID from the invoice
   - Sets `_paypal_failed_sign_up_recorded` meta to the old order ID (as an integer)
3. Filters ensure the meta value is returned as an integer when Subscriptions retrieves it
4. The strict comparison at line 195 passes, preventing `$is_renewal_sign_up_after_failure` from being set
5. Subscriptions creates a new renewal order for the payment

## Logging

The plugin logs its actions to WooCommerce logs:
- **Debug**: Skipped transactions, non-matching invoices
- **Info**: Successful meta updates
- **Warning**: Missing orders, invalid data

Logs can be viewed in **WooCommerce > Status > Logs** (look for `paypal-ipn-fixer` source).

## Version

1.3.0

## Author

WooCommerce Growth Team

## License

See LICENSE file

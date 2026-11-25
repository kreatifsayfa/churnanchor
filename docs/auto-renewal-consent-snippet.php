<?php
/**
 * Plugin Name: Auto-renewal consent checkbox (snippet from the ChurnAnchor guide)
 * Description: Classic checkout only. Adds a required, unticked auto-renewal checkbox when the cart has a subscription and stores the consent on the order.
 */

add_action( 'woocommerce_review_order_before_submit', function () {
	if ( ! class_exists( 'WC_Subscriptions_Cart' ) || ! WC_Subscriptions_Cart::cart_contains_subscription() ) {
		return;
	}
	// Checkout refreshes this area by AJAX (address, shipping, coupon changes); keep the tick.
	$checked = ! empty( $_POST['auto_renew_consent'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	if ( ! $checked && isset( $_POST['post_data'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		parse_str( wp_unslash( $_POST['post_data'] ), $form ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$checked = ! empty( $form['auto_renew_consent'] );
	}
	woocommerce_form_field(
		'auto_renew_consent',
		array(
			'type'     => 'checkbox',
			'required' => true,
			'label'    => 'I agree that my subscription renews automatically at the price and frequency shown until I cancel.',
		),
		$checked ? 1 : 0
	);
} );

add_action( 'woocommerce_checkout_process', function () {
	if ( class_exists( 'WC_Subscriptions_Cart' ) && WC_Subscriptions_Cart::cart_contains_subscription() && empty( $_POST['auto_renew_consent'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		wc_add_notice( 'Please confirm that your subscription renews automatically.', 'error' );
	}
} );

add_action( 'woocommerce_checkout_create_order', function ( $order ) {
	if ( ! empty( $_POST['auto_renew_consent'] ) && class_exists( 'WC_Subscriptions_Cart' ) && WC_Subscriptions_Cart::cart_contains_subscription() ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$order->update_meta_data( '_auto_renew_consent', gmdate( 'c' ) );
	}
} );

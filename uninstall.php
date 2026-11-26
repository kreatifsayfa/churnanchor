<?php
/**
 * Uninstall: remove data only if the store owner opted in.
 *
 * @package ChurnAnchor
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$churnanchor_settings = get_option( 'churnanchor_settings', array() );
if ( is_array( $churnanchor_settings ) && isset( $churnanchor_settings['delete_data'] ) && 'yes' === $churnanchor_settings['delete_data'] ) {
	global $wpdb;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- removing our own tables.
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'churnanchor_events' ) );
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'churnanchor_saves' ) );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_key IN (%s, %s, %s)', $wpdb->postmeta, '_churnanchor_last_offer_at', '_churnanchor_paid_until', '_churnanchor_feedback_notes' ) );
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'wc_orders_meta' ) ) ) ) {
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_key IN (%s, %s, %s)', $wpdb->prefix . 'wc_orders_meta', '_churnanchor_last_offer_at', '_churnanchor_paid_until', '_churnanchor_feedback_notes' ) );
	}
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( 'churnanchor_lock_' ) . '%' ) );
	// phpcs:enable
	delete_option( 'churnanchor_settings' );
	delete_option( 'churnanchor_db_version' );
	delete_option( 'churnanchor_rewrite_version' );
}
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'churnanchor_verify_saves' );
}

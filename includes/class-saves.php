<?php
/**
 * Honest save tracking and paid-period protection.
 *
 * A save starts "pending" when an offer is accepted. It becomes "verified" only when the
 * subscription completes a renewal payment after the offer. It is "clawed_back" if the
 * subscription is cancelled or ends first.
 *
 * Skip/pause move the next payment date forward. Until a renewal is paid, the period the
 * customer actually paid for ends at the original date (META_PAID_UNTIL), so cancelling
 * after a skip never turns the moved date into free access.
 *
 * @package ChurnAnchor
 */

namespace ChurnAnchor;

defined( 'ABSPATH' ) || exit;

/**
 * Save verification.
 */
class Saves {

	const DAILY_HOOK = 'churnanchor_verify_saves';

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'woocommerce_subscription_renewal_payment_complete', array( $this, 'on_renewal_paid' ), 10, 1 );
		add_action( 'woocommerce_subscription_status_updated', array( $this, 'on_status_updated' ), 10, 3 );
		add_filter( 'woocommerce_subscription_calculated_end_of_prepaid_term_date', array( $this, 'cap_prepaid_term' ), 10, 2 );
		add_action( self::DAILY_HOOK, array( $this, 'reconcile' ) );
		add_action( 'admin_init', array( __CLASS__, 'schedule' ) );
		add_filter( 'wc_subscriptions_renewal_order_data', array( __CLASS__, 'do_not_copy_meta' ) );
	}

	/**
	 * Keep ChurnAnchor's subscription bookkeeping off renewal orders.
	 *
	 * @param array $data Meta copied from the subscription to the renewal order.
	 * @return array
	 */
	public static function do_not_copy_meta( $data ) {
		foreach ( array( Offers::META_LAST_OFFER, Offers::META_PAID_UNTIL, Cancel_Flow::META_NOTES ) as $key ) {
			unset( $data[ $key ] );
		}
		return $data;
	}

	/**
	 * Schedule the daily reconciliation with Action Scheduler.
	 */
	public static function schedule() {
		if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( self::DAILY_HOOK ) ) {
			as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::DAILY_HOOK, array(), 'churnanchor' );
		}
	}

	/**
	 * A renewal was paid: pending saves are real, and the paid period now follows the normal schedule.
	 *
	 * @param \WC_Subscription $sub Subscription.
	 */
	public function on_renewal_paid( $sub ) {
		if ( ! $sub instanceof \WC_Subscription ) {
			return;
		}
		DB::resolve_pending_saves( $sub->get_id(), 'verified' );
		if ( $sub->get_meta( Offers::META_PAID_UNTIL ) ) {
			$sub->delete_meta_data( Offers::META_PAID_UNTIL );
			$sub->save_meta_data();
		}
	}

	/**
	 * The subscription ended or is ending: pending saves did not hold.
	 *
	 * @param \WC_Subscription $sub        Subscription.
	 * @param string           $new_status New status.
	 * @param string           $old_status Old status.
	 */
	public function on_status_updated( $sub, $new_status, $old_status ) {
		unset( $old_status );
		if ( $sub instanceof \WC_Subscription && in_array( $new_status, array( 'pending-cancel', 'cancelled', 'expired' ), true ) ) {
			DB::resolve_pending_saves( $sub->get_id(), 'clawed_back' );
		}
	}

	/**
	 * Cap WooCommerce Subscriptions' "end of prepaid term" at the end of the period the customer paid for.
	 * WCS uses this date both to decide pending-cancel vs. immediate cancel and as the access end date.
	 *
	 * @param string|int       $date MySQL GMT date or 0.
	 * @param \WC_Subscription $sub  Subscription.
	 * @return string|int
	 */
	public function cap_prepaid_term( $date, $sub ) {
		if ( ! $sub instanceof \WC_Subscription ) {
			return $date;
		}
		$current = $date ? (int) wcs_date_to_time( $date ) : 0;
		if ( ! $current ) {
			return $date; // WCS says there is no prepaid term; never extend it.
		}
		$paid_until = Offers::paid_until( $sub );
		if ( $paid_until <= 0 ) {
			return $date;
		}
		if ( $paid_until <= time() ) {
			return 0; // Paid period already over: WCS cancels immediately instead of pending-cancel.
		}
		return $current > $paid_until ? gmdate( 'Y-m-d H:i:s', $paid_until ) : $date;
	}

	/**
	 * Daily: resolve pending saves whose outcome a hook may have missed (deleted subscriptions,
	 * status changes made directly in the database, renewals paid while the plugin was inactive).
	 * A save is verified only if a renewal was paid after it was created.
	 * Walks the table with an id cursor so unresolved rows never block later ones.
	 */
	public function reconcile() {
		$last_id = 0;
		do {
			$rows = DB::pending_saves_after( $last_id, 200 );
			foreach ( $rows as $row ) {
				$last_id = (int) $row->id;
				if ( 0 === (int) $row->subscription_id ) {
					// Unlinked by a privacy erasure: the outcome can no longer be checked.
					DB::set_save_status( $last_id, 'anonymised' );
					continue;
				}
				$sub = wcs_get_subscription( (int) $row->subscription_id );
				if ( ! $sub || $sub->has_status( array( 'pending-cancel', 'cancelled', 'expired', 'trash' ) ) ) {
					DB::set_save_status( $last_id, 'clawed_back' );
				} elseif ( (int) $sub->get_time( 'last_order_date_paid' ) > (int) wcs_date_to_time( $row->created_at ) ) {
					DB::set_save_status( $last_id, 'verified' );
				}
			}
		} while ( count( $rows ) === 200 );
	}
}

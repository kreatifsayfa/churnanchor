<?php
/**
 * Save offers: eligibility and application.
 *
 * Offers never change the subscription status. "Skip" moves the next payment one
 * billing period forward; "pause" moves it N months forward. The subscription stays
 * active, so access is not cut and no resume job is needed.
 *
 * @package ChurnAnchor
 */

namespace ChurnAnchor;

defined( 'ABSPATH' ) || exit;

/**
 * Offer logic.
 */
class Offers {

	const META_LAST_OFFER = '_churnanchor_last_offer_at';
	const META_PAID_UNTIL = '_churnanchor_paid_until';

	/**
	 * The offer configured for a reason, if that offer is enabled.
	 *
	 * @param string $reason_key Reason key.
	 * @return string Offer key or ''.
	 */
	public static function offer_for_reason( $reason_key ) {
		$reasons = Settings::get( 'reasons' );
		$offer   = isset( $reasons[ $reason_key ]['offer'] ) ? $reasons[ $reason_key ]['offer'] : '';
		if ( 'skip' === $offer && ! Settings::is_on( 'offer_skip' ) ) {
			$offer = '';
		}
		if ( 'pause' === $offer && ! Settings::is_on( 'offer_pause' ) ) {
			$offer = '';
		}

		/**
		 * Filter the offer shown for a cancellation reason.
		 *
		 * @param string $offer      Offer key ('' for none).
		 * @param string $reason_key Reason key.
		 */
		return (string) apply_filters( 'churnanchor_offer_for_reason', $offer, $reason_key );
	}

	/**
	 * Offers to show for a reason, in order. The free version shows at most one.
	 *
	 * @param string           $reason_key Reason key.
	 * @param \WC_Subscription $sub        Subscription (for eligibility).
	 * @return string[] Eligible offer keys.
	 */
	public static function offers_for_reason( $reason_key, $sub ) {
		$single = self::offer_for_reason( $reason_key );
		$offers = $single ? array( $single ) : array();

		/**
		 * Filter the list of offers for a cancellation reason (before eligibility checks).
		 *
		 * @param string[]         $offers     Offer keys in display order.
		 * @param string           $reason_key Reason key.
		 * @param \WC_Subscription $sub        Subscription.
		 */
		$offers = (array) apply_filters( 'churnanchor_offers_for_reason', $offers, $reason_key, $sub );

		$out = array();
		foreach ( $offers as $offer ) {
			$offer = sanitize_key( (string) $offer );
			if ( $offer && ! in_array( $offer, $out, true ) && self::is_eligible( $sub, $offer ) ) {
				$out[] = $offer;
			}
		}
		/**
		 * Maximum number of offers shown at once (after eligibility checks), 1–3.
		 *
		 * @param int $max Maximum.
		 */
		$max = max( 1, min( 3, (int) apply_filters( 'churnanchor_max_offers', 3 ) ) );
		return array_slice( $out, 0, $max );
	}

	/**
	 * Conditions every offer shares: active, past any trial, a future renewal, no outstanding
	 * skip/pause since the last paid renewal, and not in the cooldown.
	 *
	 * @param \WC_Subscription $sub Subscription.
	 * @return bool
	 */
	public static function base_eligible( $sub ) {
		return $sub->has_status( 'active' )
			&& self::paid_until( $sub ) <= 0
			&& $sub->get_time( 'next_payment' ) > time()
			&& $sub->get_time( 'trial_end' ) < time()
			&& ! self::in_cooldown( $sub );
	}

	/**
	 * What an offer changes, for display: a summary sentence and before/after rows.
	 *
	 * @param \WC_Subscription $sub   Subscription.
	 * @param string           $offer Offer key.
	 * @return array{label:string,summary:string,rows:array<int,array{label:string,before:string,after:string}>}
	 */
	public static function details( $sub, $offer ) {
		$details = array(
			'label'   => self::label( $offer ),
			'summary' => '',
			'rows'    => array(),
		);
		if ( in_array( $offer, array( 'skip', 'pause' ), true ) ) {
			$next                = $sub->get_time( 'next_payment' );
			$details['summary']  = __( 'Your subscription stays active and you keep access.', 'churnanchor' );
			$details['rows'][]   = array(
				'label'  => __( 'Next payment', 'churnanchor' ),
				'before' => $next ? wp_date( wc_date_format(), $next ) : '—',
				'after'  => wp_date( wc_date_format(), self::new_next_payment( $sub, $offer, self::pause_months() ) ),
			);
		}

		/**
		 * Filter how an offer is described to the customer.
		 *
		 * @param array            $details Label, summary and before/after rows.
		 * @param \WC_Subscription $sub     Subscription.
		 * @param string           $offer   Offer key.
		 */
		return apply_filters( 'churnanchor_offer_details', $details, $sub, $offer );
	}

	/**
	 * Whether an offer can be applied to this subscription right now.
	 *
	 * @param \WC_Subscription $sub   Subscription.
	 * @param string           $offer Offer key.
	 * @return bool
	 */
	public static function is_eligible( $sub, $offer ) {
		if ( ! in_array( $offer, array( 'skip', 'pause' ), true ) || ! Settings::is_on( 'offer_' . $offer ) ) {
			return (bool) apply_filters( 'churnanchor_offer_eligible', false, $sub, $offer );
		}

		// No new skip/pause until a renewal has been paid since the last one: offers can never
		// be chained into an unpaid subscription (META_PAID_UNTIL is cleared on renewal payment).
		$eligible = self::base_eligible( $sub )
			&& $sub->payment_method_supports( 'subscription_date_changes' );

		if ( $eligible ) {
			$new_next = self::new_next_payment( $sub, $offer, self::pause_months() );
			$end      = $sub->get_time( 'end' );
			if ( ! $new_next || ( $end > 0 && $new_next >= $end ) ) {
				$eligible = false;
			}
		}

		/**
		 * Filter offer eligibility.
		 *
		 * @param bool             $eligible Eligible.
		 * @param \WC_Subscription $sub      Subscription.
		 * @param string           $offer    Offer key.
		 */
		return (bool) apply_filters( 'churnanchor_offer_eligible', $eligible, $sub, $offer );
	}

	/**
	 * Configured pause length in months (1–3).
	 *
	 * @return int
	 */
	public static function pause_months() {
		return max( 1, min( 3, (int) Settings::get( 'pause_max_months' ) ) );
	}

	/**
	 * Whether the subscription accepted an offer within the cooldown window.
	 *
	 * @param \WC_Subscription $sub Subscription.
	 * @return bool
	 */
	public static function in_cooldown( $sub ) {
		$last = (int) $sub->get_meta( self::META_LAST_OFFER );
		$days = max( 0, (int) Settings::get( 'cooldown_days' ) );
		return $last > 0 && ( time() - $last ) < $days * DAY_IN_SECONDS;
	}

	/**
	 * The next payment timestamp after applying an offer.
	 *
	 * @param \WC_Subscription $sub    Subscription.
	 * @param string           $offer  Offer key.
	 * @param int              $months Pause months.
	 * @return int Timestamp or 0.
	 */
	public static function new_next_payment( $sub, $offer, $months ) {
		$next = $sub->get_time( 'next_payment' );
		if ( $next <= 0 ) {
			return 0;
		}
		if ( 'skip' === $offer ) {
			return (int) wcs_add_time( (int) $sub->get_billing_interval(), $sub->get_billing_period(), $next );
		}
		if ( 'pause' === $offer ) {
			return (int) wcs_add_months( $next, (int) $months );
		}
		return 0;
	}

	/**
	 * Apply an offer. Caller must have verified ownership, nonce and eligibility.
	 *
	 * @param \WC_Subscription $sub   Subscription.
	 * @param string           $offer Offer key.
	 * @return true|\WP_Error
	 */
	public static function apply( $sub, $offer ) {
		$lock_key = 'sub_' . $sub->get_id();
		$token    = DB::acquire_lock( $lock_key );
		if ( '' === $token ) {
			return new \WP_Error( 'churnanchor_locked', __( 'Please wait a moment and try again.', 'churnanchor' ) );
		}

		try {
			$sub = wcs_get_subscription( $sub->get_id() ); // Fresh copy inside the lock.
			if ( ! $sub || ! self::is_eligible( $sub, $offer ) ) {
				return new \WP_Error( 'churnanchor_not_eligible', __( 'This option is no longer available for your subscription.', 'churnanchor' ) );
			}

			$custom = apply_filters( 'churnanchor_apply_offer', null, $sub, $offer );
			if ( null !== $custom ) {
				if ( true === $custom ) {
					$fresh = wcs_get_subscription( $sub->get_id() );
					if ( $fresh ) {
						$fresh->update_meta_data( self::META_LAST_OFFER, time() );
						$fresh->save_meta_data();
					}
				}
				return $custom;
			}

			$old_next    = $sub->get_date( 'next_payment' );
			$old_next_ts = $sub->get_time( 'next_payment' );
			$new_next    = self::new_next_payment( $sub, $offer, self::pause_months() );
			$sub->update_dates( array( 'next_payment' => gmdate( 'Y-m-d H:i:s', $new_next ) ) );
			$sub->update_meta_data( self::META_LAST_OFFER, time() );
			// Remember the end of the period the customer actually paid for (captured BEFORE moving the date),
			// so "skip, then cancel" cannot turn the moved date into free access (see Saves::cap_prepaid_term).
			$sub->update_meta_data( self::META_PAID_UNTIL, $old_next_ts );
			$sub->add_order_note(
				sprintf(
					/* translators: 1: offer name, 2: old date, 3: new date */
					__( 'ChurnAnchor: customer accepted "%1$s" instead of cancelling. Next payment moved from %2$s to %3$s (UTC).', 'churnanchor' ),
					self::label( $offer ),
					$old_next,
					gmdate( 'Y-m-d H:i:s', $new_next )
				)
			);
			$sub->save();
			return true;
		} catch ( \Exception $e ) {
			return new \WP_Error( 'churnanchor_apply_failed', $e->getMessage() );
		} finally {
			DB::release_lock( $lock_key, $token );
		}
	}

	/**
	 * End of the period the customer paid for, while a skip/pause is outstanding (GMT timestamp),
	 * or 0 when no protection applies. Self-healing: if a renewal was paid after the last offer
	 * (even while this plugin was inactive), the stale marker is ignored and removed.
	 *
	 * @param \WC_Subscription $sub Subscription.
	 * @return int
	 */
	public static function paid_until( $sub ) {
		$paid_until = (int) $sub->get_meta( self::META_PAID_UNTIL );
		if ( $paid_until <= 0 ) {
			return 0;
		}
		$last_offer = (int) $sub->get_meta( self::META_LAST_OFFER );
		$last_paid  = (int) $sub->get_time( 'last_order_date_paid' );
		if ( $last_offer > 0 && $last_paid > $last_offer ) {
			$sub->delete_meta_data( self::META_PAID_UNTIL );
			$sub->save_meta_data();
			return 0;
		}
		return $paid_until;
	}

	/**
	 * When access would end if the customer cancelled now (GMT timestamp; 0 = immediately).
	 *
	 * @param \WC_Subscription $sub Subscription.
	 * @return int
	 */
	public static function access_end_if_cancelled( $sub ) {
		$end  = $sub->calculate_date( 'end_of_prepaid_term' ); // Filtered by Saves::cap_prepaid_term().
		$time = $end ? (int) wcs_date_to_time( $end ) : 0;
		return $time > time() + MINUTE_IN_SECONDS ? $time : 0;
	}

	/**
	 * Human label for an offer.
	 *
	 * @param string $offer Offer key.
	 * @return string
	 */
	public static function label( $offer ) {
		switch ( $offer ) {
			case 'skip':
				return __( 'Skip the next renewal', 'churnanchor' );
			case 'pause':
				/* translators: %d: number of months */
				return sprintf( _n( 'Pause for %d month', 'Pause for %d months', self::pause_months(), 'churnanchor' ), self::pause_months() );
		}
		return (string) apply_filters( 'churnanchor_offer_label', $offer, $offer );
	}

	/**
	 * Monthly recurring revenue of a subscription in minor units (×100), excluding tax and shipping.
	 *
	 * @param \WC_Subscription $sub Subscription.
	 * @return int
	 */
	public static function mrr_cents( $sub ) {
		$amount   = (float) $sub->get_subtotal() - (float) $sub->get_discount_total();
		$interval = max( 1, (int) $sub->get_billing_interval() );
		switch ( $sub->get_billing_period() ) {
			case 'day':
				$monthly = $amount * 365 / 12;
				break;
			case 'week':
				$monthly = $amount * 52 / 12;
				break;
			case 'year':
				$monthly = $amount / 12;
				break;
			default:
				$monthly = $amount;
		}
		return (int) round( max( 0, $monthly / $interval ) * 100 );
	}
}

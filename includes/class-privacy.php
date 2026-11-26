<?php
/**
 * WordPress personal data export / erase integration.
 *
 * @package ChurnAnchor
 */

namespace ChurnAnchor;

defined( 'ABSPATH' ) || exit;

/**
 * Privacy tools.
 */
class Privacy {

	/**
	 * Register hooks.
	 */
	public function init() {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
		add_action( 'admin_init', array( $this, 'policy_text' ) );
	}

	/**
	 * Suggested privacy policy text.
	 */
	public function policy_text() {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content(
				'ChurnAnchor',
				wp_kses_post( wpautop( __( 'When you cancel a subscription, we store the reason you choose, any comment you write, and whether you accepted an alternative offer. This is stored on this website only and is used to improve our service.', 'churnanchor' ) ) )
			);
		}
	}

	/**
	 * Register exporter.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public function register_exporter( $exporters ) {
		$exporters['churnanchor'] = array(
			'exporter_friendly_name' => __( 'Subscription cancellation feedback', 'churnanchor' ),
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Register eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public function register_eraser( $erasers ) {
		$erasers['churnanchor'] = array(
			'eraser_friendly_name' => __( 'Subscription cancellation feedback', 'churnanchor' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Export a user's events.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array
	 */
	public function export( $email, $page = 1 ) {
		global $wpdb;
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}
		$per = 200;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, subscription_id, event_type, reason_key, reason_text, offer_key, created_at FROM %i WHERE customer_id = %d ORDER BY id ASC LIMIT %d OFFSET %d',
				DB::events_table(),
				$user->ID,
				$per,
				( max( 1, (int) $page ) - 1 ) * $per
			)
		);
		$items = array();
		foreach ( $rows as $r ) {
			$items[] = array(
				'group_id'    => 'churnanchor',
				'group_label' => __( 'Subscription cancellation feedback', 'churnanchor' ),
				'item_id'     => 'churnanchor-' . $r->id,
				'data'        => array(
					array(
						'name'  => __( 'Subscription', 'churnanchor' ),
						'value' => '#' . $r->subscription_id,
					),
					array(
						'name'  => __( 'Event', 'churnanchor' ),
						'value' => $r->event_type,
					),
					array(
						'name'  => __( 'Reason', 'churnanchor' ),
						'value' => $r->reason_key,
					),
					array(
						'name'  => __( 'Comment', 'churnanchor' ),
						'value' => (string) $r->reason_text,
					),
					array(
						'name'  => __( 'Offer', 'churnanchor' ),
						'value' => $r->offer_key,
					),
					array(
						'name'  => __( 'Date (UTC)', 'churnanchor' ),
						'value' => $r->created_at,
					),
				),
			);
		}
		return array(
			'data' => $items,
			'done' => count( $rows ) < $per,
		);
	}

	/**
	 * Anonymise a user's events (keeps aggregate counts, removes identity and free text).
	 *
	 * @param string $email Email.
	 * @return array
	 */
	public function erase( $email ) {
		global $wpdb;
		$user    = get_user_by( 'email', $email );
		$removed = 0;
		if ( $user ) {
			// Unlink saves of this customer's flows first (they share flow_id with the events).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- custom tables.
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE %i s INNER JOIN %i e ON e.flow_id = s.flow_id SET s.subscription_id = 0 WHERE e.customer_id = %d',
					DB::saves_table(),
					DB::events_table(),
					$user->ID
				)
			);
			// The cancellation notes ChurnAnchor added to this customer's subscriptions quote their comment.
			if ( function_exists( 'wcs_get_users_subscriptions' ) ) {
				foreach ( wcs_get_users_subscriptions( $user->ID ) as $sub ) {
					$ids = array_filter( array_map( 'absint', (array) $sub->get_meta( Cancel_Flow::META_NOTES ) ) );
					foreach ( $ids as $note_id ) {
						wc_delete_order_note( $note_id );
						++$removed;
					}
					if ( $ids ) {
						$sub->delete_meta_data( Cancel_Flow::META_NOTES );
						$sub->save_meta_data();
					}
				}
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
			$removed += (int) $wpdb->query(
				$wpdb->prepare( "UPDATE %i SET customer_id = 0, subscription_id = 0, reason_text = '' WHERE customer_id = %d", DB::events_table(), $user->ID )
			);
		}
		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}

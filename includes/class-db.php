<?php
/**
 * Database tables: flow events and saves.
 *
 * @package ChurnAnchor
 */

namespace ChurnAnchor;

defined( 'ABSPATH' ) || exit;

/**
 * Table management and queries.
 */
class DB {

	const SCHEMA_VERSION = '2';
	const OPTION         = 'churnanchor_db_version';

	/**
	 * Events table name.
	 *
	 * @return string
	 */
	public static function events_table() {
		global $wpdb;
		return $wpdb->prefix . 'churnanchor_events';
	}

	/**
	 * Saves table name.
	 *
	 * @return string
	 */
	public static function saves_table() {
		global $wpdb;
		return $wpdb->prefix . 'churnanchor_saves';
	}

	/**
	 * Install or upgrade when the schema version changed.
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::OPTION ) !== self::SCHEMA_VERSION ) {
			self::install();
		}
	}

	/**
	 * Create tables.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$events  = self::events_table();
		$saves   = self::saves_table();

		// Schema 1 named a column `event` (a MySQL keyword). Rename it in place so
		// existing rows and indexes are kept.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- one-time schema migration on a custom table.
		if ( self::table_exists( $events ) && self::column_exists( $events, 'event' ) && ! self::column_exists( $events, 'event_type' ) ) {
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i CHANGE `event` event_type varchar(32) NOT NULL', $events ) );
		}
		// phpcs:enable

		dbDelta(
			"CREATE TABLE {$events} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				flow_id char(36) NOT NULL,
				subscription_id bigint(20) unsigned NOT NULL,
				customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
				event_type varchar(32) NOT NULL,
				reason_key varchar(64) NOT NULL DEFAULT '',
				reason_text text NULL,
				offer_key varchar(64) NOT NULL DEFAULT '',
				mrr_cents bigint(20) NOT NULL DEFAULT 0,
				currency char(3) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY flow_event (flow_id, event_type),
				KEY sub_event_time (subscription_id, event_type, created_at),
				KEY event_time (event_type, created_at),
				KEY customer_id (customer_id)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$saves} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				flow_id char(36) NOT NULL,
				subscription_id bigint(20) unsigned NOT NULL,
				offer_key varchar(64) NOT NULL,
				mrr_cents bigint(20) NOT NULL DEFAULT 0,
				currency char(3) NOT NULL DEFAULT '',
				status varchar(16) NOT NULL DEFAULT 'pending',
				created_at datetime NOT NULL,
				resolved_at datetime NULL,
				PRIMARY KEY  (id),
				KEY sub_status (subscription_id, status),
				KEY status_time (status, created_at)
			) {$charset};"
		);

		// Only record the schema version once the tables and the renamed column really
		// exist, so a failed install or migration is retried instead of marked done.
		if ( self::table_exists( $saves ) && self::column_exists( $events, 'event_type' ) && ! self::column_exists( $events, 'event' ) ) {
			update_option( self::OPTION, self::SCHEMA_VERSION, true );
		}
	}

	/**
	 * Whether a table exists (case-insensitive, for lower_case_table_names hosts).
	 *
	 * @param string $table Table name.
	 * @return bool
	 */
	private static function table_exists( $table ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- schema check.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		return null !== $found && 0 === strcasecmp( (string) $found, $table );
	}

	/**
	 * Whether a column exists in a table.
	 *
	 * @param string $table  Table name.
	 * @param string $column Column name.
	 * @return bool
	 */
	private static function column_exists( $table, $column ) {
		global $wpdb;
		if ( ! self::table_exists( $table ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- schema check.
		return null !== $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, $wpdb->esc_like( $column ) ) );
	}

	/**
	 * Record a flow event.
	 *
	 * @param array $data Event fields.
	 * @return int Inserted id.
	 */
	public static function log_event( array $data ) {
		global $wpdb;
		$row = wp_parse_args(
			$data,
			array(
				'flow_id'         => '',
				'subscription_id' => 0,
				'customer_id'     => 0,
				'event'           => '',
				'reason_key'      => '',
				'reason_text'     => '',
				'offer_key'       => '',
				'mrr_cents'       => 0,
				'currency'        => '',
				'created_at'      => current_time( 'mysql', true ),
			)
		);
		// One row per (flow, event): INSERT IGNORE makes double submits harmless.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table write.
		$wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (flow_id, subscription_id, customer_id, event_type, reason_key, reason_text, offer_key, mrr_cents, currency, created_at) VALUES (%s, %d, %d, %s, %s, %s, %s, %d, %s, %s)',
				self::events_table(),
				$row['flow_id'],
				$row['subscription_id'],
				$row['customer_id'],
				$row['event'],
				$row['reason_key'],
				$row['reason_text'],
				$row['offer_key'],
				$row['mrr_cents'],
				$row['currency'],
				$row['created_at']
			)
		);
		if ( 1 !== (int) $wpdb->rows_affected ) {
			return 0;
		}
		$id = (int) $wpdb->insert_id;

		/**
		 * Fires after a ChurnAnchor flow event is recorded.
		 *
		 * @param int   $id  Event id.
		 * @param array $row Event data.
		 */
		do_action( 'churnanchor_event', $id, $row );
		return $id;
	}

	/**
	 * Whether a flow already has a given event for this subscription (and offer, if given).
	 *
	 * @param string $flow_id         Flow UUID.
	 * @param string $event           Event name.
	 * @param int    $subscription_id Subscription id.
	 * @param string $offer_key       Optional offer key.
	 * @return bool
	 */
	public static function flow_has_event( $flow_id, $event, $subscription_id, $offer_key = '' ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, must be fresh.
		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE flow_id = %s AND event_type = %s AND subscription_id = %d AND ( %s = \'\' OR offer_key = %s ) LIMIT 1',
				self::events_table(),
				$flow_id,
				$event,
				$subscription_id,
				$offer_key,
				$offer_key
			)
		);
		return null !== $found;
	}

	/**
	 * The reason step of a flow (reason key and comment), or null.
	 *
	 * @param string $flow_id         Flow UUID.
	 * @param int    $subscription_id Subscription id.
	 * @return object|null
	 */
	public static function flow_reason( $flow_id, $subscription_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, must be fresh.
		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT reason_key, reason_text FROM %i WHERE flow_id = %s AND event_type = %s AND subscription_id = %d LIMIT 1',
				self::events_table(),
				$flow_id,
				'reason',
				$subscription_id
			)
		);
	}

	/**
	 * Latest written comments from the reason step.
	 *
	 * @param string $since_gmt MySQL datetime (GMT).
	 * @param int    $limit     Rows.
	 * @return array
	 */
	public static function recent_comments( $since_gmt, $limit = 10 ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- reporting on a custom table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT subscription_id, reason_key, reason_text, created_at FROM %i WHERE event_type = %s AND reason_text <> \'\' AND created_at >= %s ORDER BY id DESC LIMIT %d',
				self::events_table(),
				'reason',
				$since_gmt,
				$limit
			)
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Create a pending save.
	 *
	 * @param array $data Save fields.
	 * @return int
	 */
	public static function create_save( array $data ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table write.
		$wpdb->insert(
			self::saves_table(),
			array(
				'flow_id'         => $data['flow_id'],
				'subscription_id' => $data['subscription_id'],
				'offer_key'       => $data['offer_key'],
				'mrr_cents'       => $data['mrr_cents'],
				'currency'        => $data['currency'],
				'status'          => 'pending',
				'created_at'      => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%s', '%d', '%s', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Resolve all pending saves of a subscription.
	 *
	 * @param int    $subscription_id Subscription id.
	 * @param string $status          verified|clawed_back.
	 * @return int Rows changed.
	 */
	public static function resolve_pending_saves( $subscription_id, $status ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table write.
		return (int) $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, resolved_at = %s WHERE subscription_id = %d AND status = %s',
				self::saves_table(),
				$status,
				current_time( 'mysql', true ),
				$subscription_id,
				'pending'
			)
		);
	}

	/**
	 * Pending saves after an id (cursor pagination).
	 *
	 * @param int $after_id Last processed id.
	 * @param int $limit    Max rows.
	 * @return array<int, object>
	 */
	public static function pending_saves_after( $after_id, $limit = 200 ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table read.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, subscription_id, created_at FROM %i WHERE status = %s AND id > %d ORDER BY id ASC LIMIT %d',
				self::saves_table(),
				'pending',
				$after_id,
				$limit
			)
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Acquire a short per-key lock. Atomic: INSERT IGNORE on the unique option_name.
	 * A lock older than $ttl seconds is taken over with a compare-and-swap UPDATE.
	 *
	 * @param string $key Lock key.
	 * @param int    $ttl Seconds before a lock counts as stale.
	 * @return string Token to release with, or '' if the lock is held.
	 */
	public static function acquire_lock( $key, $ttl = 60 ) {
		global $wpdb;
		$name  = 'churnanchor_lock_' . $key;
		$token = time() . ':' . wp_generate_uuid4();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic lock, must bypass the options cache.
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $wpdb->options, $name, $token ) );
		if ( 1 === (int) $wpdb->rows_affected ) {
			return $token;
		}
		$current = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $name ) );
		$since   = (int) strtok( $current, ':' );
		if ( $current && $since < time() - $ttl ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s', $wpdb->options, $token, $name, $current ) );
			if ( 1 === (int) $wpdb->rows_affected ) {
				return $token;
			}
		}
		// phpcs:enable
		return '';
	}

	/**
	 * Release a lock, only if we still own it.
	 *
	 * @param string $key   Lock key.
	 * @param string $token Token from acquire_lock().
	 */
	public static function release_lock( $key, $token ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic lock.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND option_value = %s', $wpdb->options, 'churnanchor_lock_' . $key, $token ) );
	}

	/**
	 * Mark a single save.
	 *
	 * @param int    $id     Save id.
	 * @param string $status New status.
	 */
	public static function set_save_status( $id, $status ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table write.
		$wpdb->update(
			self::saves_table(),
			array(
				'status'      => $status,
				'resolved_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Dashboard numbers since a date, grouped by currency where money is involved.
	 *
	 * @param string $since_gmt MySQL datetime (GMT).
	 * @return array
	 */
	public static function stats( $since_gmt ) {
		global $wpdb;
		$events = self::events_table();
		$saves  = self::saves_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- reporting on custom tables.
		$attempts = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(DISTINCT flow_id) FROM %i WHERE event_type = %s AND created_at >= %s', $events, 'attempt', $since_gmt )
		);
		$cancelled = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(DISTINCT flow_id) FROM %i WHERE event_type = %s AND created_at >= %s', $events, 'cancelled', $since_gmt )
		);
		$accepted = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(DISTINCT flow_id) FROM %i WHERE event_type = %s AND created_at >= %s', $events, 'offer_accepted', $since_gmt )
		);
		$money = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT currency, status, COUNT(*) AS n, SUM(mrr_cents) AS mrr FROM %i WHERE created_at >= %s GROUP BY currency, status',
				$saves,
				$since_gmt
			)
		);
		$reasons = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT reason_key, COUNT(*) AS n FROM %i WHERE event_type = %s AND reason_key <> \'\' AND created_at >= %s GROUP BY reason_key ORDER BY n DESC LIMIT 12',
				$events,
				'reason',
				$since_gmt
			)
		);
		$reasons_total = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE event_type = %s AND reason_key <> \'\' AND created_at >= %s', $events, 'reason', $since_gmt )
		);
		// phpcs:enable

		return array(
			'reasons_total' => $reasons_total,
			'attempts'  => $attempts,
			'cancelled' => $cancelled,
			'accepted'  => $accepted,
			'money'     => is_array( $money ) ? $money : array(),
			'reasons'   => is_array( $reasons ) ? $reasons : array(),
		);
	}
}

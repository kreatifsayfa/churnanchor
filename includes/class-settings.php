<?php
/**
 * Settings storage with sane defaults.
 *
 * @package ChurnAnchor
 */

namespace ChurnAnchor;

defined( 'ABSPATH' ) || exit;

/**
 * Settings accessor.
 */
class Settings {

	const OPTION = 'churnanchor_settings';

	/**
	 * Default cancellation reasons: key => [label, offer].
	 * Offer is the single save offer shown for that reason in the free version ('' = none).
	 *
	 * @return array
	 */
	public static function default_reasons() {
		return array(
			'too_much'      => array(
				'label' => __( 'I have too much product right now', 'churnanchor' ),
				'offer' => 'skip',
			),
			'too_expensive' => array(
				'label' => __( 'It is too expensive', 'churnanchor' ),
				'offer' => 'pause',
			),
			'not_using'     => array(
				'label' => __( 'I am not using it enough', 'churnanchor' ),
				'offer' => 'pause',
			),
			'quality'       => array(
				'label' => __( 'Quality or delivery problems', 'churnanchor' ),
				'offer' => '',
			),
			'switching'     => array(
				'label' => __( 'I am switching to something else', 'churnanchor' ),
				'offer' => '',
			),
			'other'         => array(
				'label' => __( 'Something else', 'churnanchor' ),
				'offer' => 'skip',
			),
		);
	}

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enabled'          => 'yes',
			'offer_skip'       => 'yes',
			'offer_pause'      => 'yes',
			'pause_max_months' => 3,
			'cooldown_days'    => 90,
			'delete_data'      => 'no',
			'reasons'          => array(), // Filled lazily: labels are translated, so build them only when needed (after init).
		);
	}

	/**
	 * All settings merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		$all   = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		if ( empty( $all['reasons'] ) || ! is_array( $all['reasons'] ) ) {
			$all['reasons'] = self::default_reasons();
		}
		return $all;
	}

	/**
	 * Single setting. Does not build translated defaults unless 'reasons' is requested.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( $key ) {
		if ( 'reasons' === $key ) {
			$all = self::all();
			return $all['reasons'];
		}
		$saved = get_option( self::OPTION, array() );
		$all   = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Whether a yes/no setting is on.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public static function is_on( $key ) {
		return 'yes' === self::get( $key );
	}
}

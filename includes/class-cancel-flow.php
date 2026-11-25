<?php
/**
 * Customer-facing cancellation flow on My Account.
 *
 * Steps (GET, read-only): reason → offer (if any) or confirm.
 * Every state change is a POST with a per-subscription nonce, followed by a redirect.
 * A "Cancel subscription" button is visible on every step.
 *
 * @package ChurnAnchor
 */

namespace ChurnAnchor;

defined( 'ABSPATH' ) || exit;

/**
 * Cancellation flow controller.
 */
class Cancel_Flow {

	const ENDPOINT = 'churnanchor-cancel';

	/**
	 * Register hooks.
	 */
	public function init() {
		// The endpoint stays registered even when the flow is off, so turning it back on never 404s.
		add_action( 'init', array( __CLASS__, 'add_endpoint' ) );
		add_action( 'init', array( $this, 'maybe_flush_rewrites' ), 99 );
		add_filter( 'woocommerce_get_query_vars', array( $this, 'query_vars' ) );
		if ( ! Settings::is_on( 'enabled' ) ) {
			add_action( 'template_redirect', array( $this, 'redirect_when_disabled' ) );
			return;
		}
		add_filter( 'wcs_view_subscription_actions', array( $this, 'replace_cancel_url' ), 20, 3 );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', array( $this, 'title' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'render' ) );
		add_action( 'template_redirect', array( $this, 'handle_post' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
	}

	/**
	 * Register the My Account endpoint.
	 */
	public static function add_endpoint() {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	/**
	 * Flush rewrite rules once after an update that may have added the endpoint.
	 */
	public function maybe_flush_rewrites() {
		if ( get_option( 'churnanchor_rewrite_version' ) !== CHURNANCHOR_VERSION ) {
			flush_rewrite_rules( false );
			update_option( 'churnanchor_rewrite_version', CHURNANCHOR_VERSION, true );
		}
	}

	/**
	 * Add the endpoint to WooCommerce query vars.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public function query_vars( $vars ) {
		$vars[ self::ENDPOINT ] = self::ENDPOINT;
		return $vars;
	}

	/**
	 * With the flow off, send visitors of our endpoint back to their subscriptions.
	 */
	public function redirect_when_disabled() {
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( self::ENDPOINT ) ) {
			wp_safe_redirect( wc_get_account_endpoint_url( 'subscriptions' ) );
			exit;
		}
	}

	/**
	 * Endpoint title.
	 *
	 * @return string
	 */
	public function title() {
		return __( 'Cancel subscription', 'churnanchor' );
	}

	/**
	 * Point the "Cancel" action at our flow.
	 *
	 * @param array            $actions Actions.
	 * @param \WC_Subscription $sub     Subscription.
	 * @param int              $user_id User id.
	 * @return array
	 */
	public function replace_cancel_url( $actions, $sub, $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( isset( $actions['cancel'] ) && is_array( $actions['cancel'] ) && self::owns( $sub, $user_id ) ) {
			$actions['cancel']['url'] = self::flow_url( $sub->get_id() );
		}
		return $actions;
	}

	/**
	 * URL of a flow step.
	 *
	 * @param int    $sub_id Subscription id.
	 * @param array  $args   Extra query args.
	 * @param string $anchor Optional fragment: 'churnanchor-title' or 'churnanchor-flow'.
	 * @return string
	 */
	public static function flow_url( $sub_id, array $args = array(), $anchor = '' ) {
		$url = wc_get_endpoint_url( self::ENDPOINT, (string) absint( $sub_id ), wc_get_page_permalink( 'myaccount' ) );
		$url = $args ? add_query_arg( $args, $url ) : $url;
		// Land on the step heading after a step, or on the notices after an error, so keyboard and
		// screen reader users start in the right place.
		return $anchor ? $url . '#' . $anchor : $url;
	}

	/**
	 * Strict ownership check.
	 *
	 * @param \WC_Subscription $sub     Subscription.
	 * @param int              $user_id User id.
	 * @return bool
	 */
	public static function owns( $sub, $user_id ) {
		return $user_id > 0 && $sub instanceof \WC_Subscription && (int) $sub->get_customer_id() === $user_id;
	}

	/**
	 * Load the subscription for the current user, or null.
	 *
	 * @param int $sub_id Subscription id.
	 * @return \WC_Subscription|null
	 */
	private function current_user_subscription( $sub_id ) {
		if ( ! is_user_logged_in() ) {
			return null;
		}
		$sub = wcs_get_subscription( absint( $sub_id ) );
		if ( ! $sub || ! self::owns( $sub, get_current_user_id() ) ) {
			return null;
		}
		return $sub;
	}

	/**
	 * Whether the subscription can still be cancelled by its owner.
	 *
	 * @param \WC_Subscription $sub Subscription.
	 * @return bool
	 */
	private function can_cancel( $sub ) {
		return \WCS_User_Change_Status_Handler::validate_request( get_current_user_id(), $sub, 'cancelled' );
	}

	/**
	 * Enqueue the small stylesheet on our endpoint only.
	 */
	public function assets() {
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( self::ENDPOINT ) ) {
			wp_enqueue_style( 'churnanchor-flow', CHURNANCHOR_URL . 'assets/flow.css', array(), CHURNANCHOR_VERSION );
		}
	}

	/**
	 * Render the current step.
	 *
	 * @param string $value Endpoint value (subscription id).
	 */
	public function render( $value ) {
		$sub = $this->current_user_subscription( $value );
		if ( ! $sub ) {
			wc_print_notice( __( 'We could not find that subscription.', 'churnanchor' ), 'error' );
			return;
		}

		$view_url = $sub->get_view_order_url();
		if ( ! $this->can_cancel( $sub ) ) {
			wc_print_notices();
			echo '<p><a class="button" href="' . esc_url( $view_url ) . '">' . esc_html__( 'Back to subscription', 'churnanchor' ) . '</a></p>';
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only step navigation.
		$step    = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : 'reason';
		$flow_id = isset( $_GET['flow'] ) ? self::clean_flow_id( sanitize_text_field( wp_unslash( $_GET['flow'] ) ) ) : '';
		$reason  = isset( $_GET['reason'] ) ? sanitize_key( wp_unslash( $_GET['reason'] ) ) : '';
		// phpcs:enable

		$reasons = Settings::get( 'reasons' );
		if ( ! isset( $reasons[ $reason ] ) ) {
			$reason = '';
		}

		$offers = array();
		if ( 'offer' === $step ) {
			$offers = $reason ? Offers::offers_for_reason( $reason, $sub ) : array();
			if ( ! $offers ) {
				$step = $reason ? 'confirm' : 'reason';
			}
		}
		if ( ! in_array( $step, array( 'reason', 'offer', 'confirm' ), true ) || ( 'reason' !== $step && ! $flow_id ) ) {
			$step = 'reason';
		}
		if ( ! $flow_id ) {
			$flow_id = wp_generate_uuid4();
		}

		wc_get_template(
			'cancel-flow.php',
			array(
				'step'         => $step,
				'subscription' => $sub,
				'reasons'      => $reasons,
				'reason'       => $reason,
				'offers'       => array_map(
					static function ( $key ) use ( $sub ) {
						return array( 'key' => $key ) + Offers::details( $sub, $key );
					},
					$offers
				),
				'access_end'   => Offers::access_end_if_cancelled( $sub ),
				'flow_id'      => $flow_id,
				'nonce_action' => self::nonce_action( $sub->get_id() ),
				'view_url'     => $view_url,
			),
			'churnanchor/',
			CHURNANCHOR_DIR . 'templates/'
		);
	}

	/**
	 * Nonce action bound to one subscription.
	 *
	 * @param int $sub_id Subscription id.
	 * @return string
	 */
	public static function nonce_action( $sub_id ) {
		return 'churnanchor_flow_' . absint( $sub_id );
	}

	/**
	 * Accept only UUIDv4-shaped flow ids.
	 *
	 * @param string $flow_id Raw value.
	 * @return string
	 */
	public static function clean_flow_id( $flow_id ) {
		return preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $flow_id ) ? $flow_id : '';
	}

	/**
	 * Handle POSTed flow actions.
	 */
	public function handle_post() {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] || ! isset( $_POST['churnanchor_action'], $_POST['churnanchor_sub'] ) ) {
			return;
		}

		$sub_id = absint( wp_unslash( $_POST['churnanchor_sub'] ) );
		$nonce  = isset( $_POST['_churnanchor_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_churnanchor_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::nonce_action( $sub_id ) ) ) {
			wc_add_notice( __( 'Your session expired. Please try again.', 'churnanchor' ), 'error' );
			wp_safe_redirect( self::flow_url( $sub_id, array(), 'churnanchor-flow' ) );
			exit;
		}

		$sub = $this->current_user_subscription( $sub_id );
		if ( ! $sub ) {
			wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
			exit;
		}

		$action = sanitize_key( wp_unslash( $_POST['churnanchor_action'] ) );
		if ( ! in_array( $action, array( 'reason', 'accept', 'cancel' ), true ) || ! $this->can_cancel( $sub ) ) {
			wp_safe_redirect( $sub->get_view_order_url() );
			exit;
		}

		$flow_id = isset( $_POST['churnanchor_flow'] ) ? self::clean_flow_id( sanitize_text_field( wp_unslash( $_POST['churnanchor_flow'] ) ) ) : '';
		if ( ! $flow_id ) {
			$flow_id = wp_generate_uuid4();
		}
		$reasons = Settings::get( 'reasons' );
		$reason  = isset( $_POST['churnanchor_reason'] ) ? sanitize_key( wp_unslash( $_POST['churnanchor_reason'] ) ) : '';
		if ( ! isset( $reasons[ $reason ] ) ) {
			$reason = '';
		}
		$base = array(
			'flow_id'         => $flow_id,
			'subscription_id' => $sub->get_id(),
			'customer_id'     => get_current_user_id(),
			'reason_key'      => $reason,
			'mrr_cents'       => Offers::mrr_cents( $sub ),
			'currency'        => $sub->get_currency(),
		);

		DB::log_event( $base + array( 'event' => 'attempt' ) );

		switch ( $action ) {
			case 'reason':
				$text = isset( $_POST['churnanchor_reason_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['churnanchor_reason_text'] ) ) : '';
				$text = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 1000 ) : substr( $text, 0, 1000 );
				if ( ! $reason ) {
					wc_add_notice( __( 'Please choose a reason, or cancel directly.', 'churnanchor' ), 'error' );
					wp_safe_redirect( self::flow_url( $sub_id, array( 'flow' => $flow_id ), 'churnanchor-flow' ) );
					exit;
				}
				DB::log_event( $base + array( 'event' => 'reason', 'reason_text' => $text ) );
				$offers = Offers::offers_for_reason( $reason, $sub );
				$step   = $offers ? 'offer' : 'confirm';
				if ( 'offer' === $step ) {
					DB::log_event( $base + array( 'event' => 'offer_shown', 'offer_key' => substr( implode( ',', $offers ), 0, 64 ) ) );
				}
				wp_safe_redirect(
					self::flow_url(
						$sub_id,
						array(
							'step'   => $step,
							'flow'   => $flow_id,
							'reason' => $reason,
						),
						'churnanchor-title'
					)
				);
				exit;

			case 'accept':
				$offer = isset( $_POST['churnanchor_offer'] ) ? sanitize_key( wp_unslash( $_POST['churnanchor_offer'] ) ) : '';
				// Only an offer this flow actually showed, and that is still valid for the reason, can be accepted.
				if ( ! $reason || ! $offer || ! in_array( $offer, Offers::offers_for_reason( $reason, $sub ), true ) || ! DB::flow_has_event( $flow_id, 'offer_shown', $sub->get_id() ) ) {
					wp_safe_redirect( self::flow_url( $sub_id ) );
					exit;
				}
				$result = Offers::apply( $sub, $offer );
				if ( is_wp_error( $result ) ) {
					wc_add_notice( $result->get_error_message(), 'error' );
					wp_safe_redirect( self::flow_url( $sub_id, array( 'step' => 'confirm', 'flow' => $flow_id, 'reason' => $reason ), 'churnanchor-flow' ) );
					exit;
				}
				if ( DB::log_event( $base + array( 'event' => 'offer_accepted', 'offer_key' => $offer ) ) ) {
					DB::create_save( $base + array( 'offer_key' => $offer ) );
					/**
					 * Fires after a customer accepts a save offer.
					 *
					 * @param \WC_Subscription $sub     Subscription.
					 * @param string           $offer   Offer key.
					 * @param string           $flow_id Flow UUID.
					 */
					do_action( 'churnanchor_offer_accepted', $sub, $offer, $flow_id );
				}
				wc_add_notice( __( 'Done! Your subscription stays active and your next payment date has been moved.', 'churnanchor' ), 'success' );
				wp_safe_redirect( $sub->get_view_order_url() );
				exit;

			case 'cancel':
				\WCS_User_Change_Status_Handler::change_users_subscription( $sub, 'cancelled' );
				$fresh = wcs_get_subscription( $sub->get_id() );
				if ( $fresh && $fresh->has_status( array( 'pending-cancel', 'cancelled' ) ) ) {
					DB::log_event( $base + array( 'event' => 'cancelled' ) );
					/**
					 * Fires after a customer cancels through the flow.
					 *
					 * @param \WC_Subscription $fresh   Subscription.
					 * @param string           $reason  Reason key ('' if none).
					 * @param string           $flow_id Flow UUID.
					 */
					do_action( 'churnanchor_subscription_cancelled', $fresh, $reason, $flow_id );
				}
				wp_safe_redirect( $sub->get_view_order_url() );
				exit;
		}
	}
}

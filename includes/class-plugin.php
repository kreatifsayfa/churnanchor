<?php
/**
 * Bootstrap: dependency checks and wiring.
 *
 * @package ChurnAnchor
 */

namespace ChurnAnchor;

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin class.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get (and boot) the plugin.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	/**
	 * Whether the WooCommerce Subscriptions engine is loaded.
	 *
	 * @return bool
	 */
	public static function subscriptions_available() {
		return class_exists( 'WC_Subscription' ) && function_exists( 'wcs_get_subscription' ) && class_exists( 'WCS_User_Change_Status_Handler' );
	}

	/**
	 * Wire hooks.
	 */
	private function boot() {
		DB::maybe_upgrade();

		if ( is_admin() ) {
			( new Admin() )->init();
		}
		// Privacy tools only touch our own table, so they work even if Subscriptions is inactive.
		( new Privacy() )->init();

		if ( ! self::subscriptions_available() ) {
			add_action( 'admin_notices', array( $this, 'missing_subscriptions_notice' ) );
			return;
		}

		( new Cancel_Flow() )->init();
		( new Saves() )->init();
	}

	/**
	 * Admin notice when no subscriptions engine is active.
	 */
	public function missing_subscriptions_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'ChurnAnchor needs WooCommerce Subscriptions to be active. The cancellation flow is paused until then.', 'churnanchor' ) . '</p></div>';
	}

	/**
	 * Activation: create tables and register the endpoint.
	 */
	public static function activate() {
		DB::install();
		Cancel_Flow::add_endpoint();
		flush_rewrite_rules();
		update_option( 'churnanchor_rewrite_version', CHURNANCHOR_VERSION, true );
		Saves::schedule();
	}

	/**
	 * Deactivation: clear scheduled jobs and rewrite rules.
	 */
	public static function deactivate() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( Saves::DAILY_HOOK );
		}
		delete_option( 'churnanchor_rewrite_version' );
		// Our endpoint is registered in this request; drop cached rules so WordPress rebuilds them without it.
		delete_option( 'rewrite_rules' );
	}
}

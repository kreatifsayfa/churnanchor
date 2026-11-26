<?php
/**
 * Plugin Name:       ChurnAnchor
 * Plugin URI:        https://churnanchor.marul.co/
 * Description:       A fair, dark-pattern-free cancellation flow for WooCommerce Subscriptions: ask why, offer a skip or a pause, and track the revenue you actually keep.
 * Version:           0.2.4
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            kreatiftr
 * Author URI:        https://profiles.wordpress.org/kreatiftr/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       churnanchor
 * Domain Path:       /languages
 * WC requires at least: 9.0
 * WC tested up to:   11.3
 *
 * @package ChurnAnchor
 */

defined( 'ABSPATH' ) || exit;

define( 'CHURNANCHOR_VERSION', '0.2.4' );
define( 'CHURNANCHOR_FILE', __FILE__ );
define( 'CHURNANCHOR_DIR', plugin_dir_path( __FILE__ ) );
define( 'CHURNANCHOR_URL', plugin_dir_url( __FILE__ ) );

require_once CHURNANCHOR_DIR . 'includes/class-db.php';
require_once CHURNANCHOR_DIR . 'includes/class-settings.php';
require_once CHURNANCHOR_DIR . 'includes/class-offers.php';
require_once CHURNANCHOR_DIR . 'includes/class-saves.php';
require_once CHURNANCHOR_DIR . 'includes/class-cancel-flow.php';
require_once CHURNANCHOR_DIR . 'includes/class-admin.php';
require_once CHURNANCHOR_DIR . 'includes/class-privacy.php';
require_once CHURNANCHOR_DIR . 'includes/class-plugin.php';

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

register_activation_hook( __FILE__, array( '\ChurnAnchor\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\ChurnAnchor\Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( '\ChurnAnchor\Plugin', 'instance' ), 20 );

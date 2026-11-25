<?php
/**
 * Admin page: dashboard and settings (WooCommerce → ChurnAnchor).
 *
 * @package ChurnAnchor
 */

namespace ChurnAnchor;

defined( 'ABSPATH' ) || exit;

/**
 * Admin UI.
 */
class Admin {

	const SLUG = 'churnanchor';

	/**
	 * Admin page hook suffix returned by add_submenu_page().
	 *
	 * @var string
	 */
	private $hook = '';

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'menu' ), 60 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_churnanchor_save_settings', array( $this, 'save' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( CHURNANCHOR_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Menu item under WooCommerce.
	 */
	public function menu() {
		$this->hook = (string) add_submenu_page( 'woocommerce', __( 'ChurnAnchor', 'churnanchor' ), __( 'ChurnAnchor', 'churnanchor' ), 'manage_woocommerce', self::SLUG, array( $this, 'page' ) );
	}

	/**
	 * Settings link on the plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Dashboard & settings', 'churnanchor' ) . '</a>' );
		return $links;
	}

	/**
	 * Format minor units.
	 *
	 * @param int    $cents    Amount ×100.
	 * @param string $currency Currency code.
	 * @return string
	 */
	private function money( $cents, $currency ) {
		return wp_strip_all_tags( wc_price( $cents / 100, array( 'currency' => $currency ) ) );
	}

	/**
	 * Enqueue admin styles on our page only.
	 *
	 * @param string $hook Admin page hook.
	 */
	public function assets( $hook ) {
		if ( $this->hook && $this->hook === $hook ) {
			wp_enqueue_style( 'churnanchor-admin', CHURNANCHOR_URL . 'assets/admin.css', array(), CHURNANCHOR_VERSION );
		}
	}

	/**
	 * Render page.
	 */
	public function page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view parameters.
		$days = isset( $_GET['range'] ) ? absint( $_GET['range'] ) : 30;
		$days = in_array( $days, array( 7, 30, 90 ), true ) ? $days : 30;
		$saved_notice = isset( $_GET['updated'] );
		// phpcs:enable

		$settings = Settings::all();
		$stats    = DB::stats( gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) );
		$reasons  = $settings['reasons'];

		$by_currency = array();
		foreach ( $stats['money'] as $m ) {
			$by_currency[ $m->currency ][ $m->status ] = (int) $m->mrr;
		}
		$rate     = $stats['attempts'] > 0 ? round( 100 * $stats['accepted'] / $stats['attempts'] ) : 0;
		$max_r    = 0;
		$total_r  = 0;
		foreach ( $stats['reasons'] as $r ) {
			$max_r = max( $max_r, (int) $r->n );
		}
		$total_r  = (int) $stats['reasons_total'];
		$is_empty = 0 === $stats['attempts'] && 0 === $stats['accepted'] && 0 === $stats['cancelled'] && empty( $stats['money'] );
		$base_url = admin_url( 'admin.php?page=' . self::SLUG );
		?>
		<div class="wrap churnanchor-admin">
			<header class="ca-head">
				<h1><?php esc_html_e( 'ChurnAnchor', 'churnanchor' ); ?></h1>
				<nav class="ca-range" aria-label="<?php esc_attr_e( 'Date range', 'churnanchor' ); ?>">
					<?php foreach ( array( 7, 30, 90 ) as $d ) : ?>
						<a href="<?php echo esc_url( add_query_arg( 'range', $d, $base_url ) ); ?>" <?php echo $d === $days ? 'aria-current="page"' : ''; ?>>
							<?php
							/* translators: %d: number of days */
							echo esc_html( sprintf( __( '%d days', 'churnanchor' ), $d ) );
							?>
						</a>
					<?php endforeach; ?>
				</nav>
			</header>

			<?php if ( $saved_notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'churnanchor' ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! Plugin::subscriptions_available() ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'WooCommerce Subscriptions was not detected. Activate it to use the cancellation flow.', 'churnanchor' ); ?></p></div>
			<?php endif; ?>

			<?php if ( $is_empty ) : ?>
				<section class="ca-panel ca-empty">
					<h2><?php esc_html_e( 'No cancellations in this period yet', 'churnanchor' ); ?></h2>
					<p><?php esc_html_e( 'When a customer clicks "Cancel" on a subscription in My Account, they now see the ChurnAnchor flow. Their reasons and any offers they accept will appear here.', 'churnanchor' ); ?></p>
					<p><?php esc_html_e( 'To see the flow yourself, log in as a customer with an active subscription and click "Cancel" on it.', 'churnanchor' ); ?></p>
				</section>
			<?php else : ?>
				<section class="ca-kpis" aria-label="<?php esc_attr_e( 'Summary', 'churnanchor' ); ?>">
					<div class="ca-kpi">
						<p class="ca-kpi-label"><?php esc_html_e( 'Started to cancel', 'churnanchor' ); ?></p>
						<p class="ca-kpi-value"><?php echo esc_html( number_format_i18n( $stats['attempts'] ) ); ?></p>
						<p class="ca-kpi-sub"><?php esc_html_e( 'customers who opened the flow and acted', 'churnanchor' ); ?></p>
					</div>
					<div class="ca-kpi">
						<p class="ca-kpi-label"><?php esc_html_e( 'Took an offer', 'churnanchor' ); ?></p>
						<p class="ca-kpi-value"><?php echo esc_html( number_format_i18n( $stats['accepted'] ) ); ?> <span><?php echo esc_html( $rate . '%' ); ?></span></p>
						<p class="ca-kpi-sub"><?php esc_html_e( 'skipped or paused instead of cancelling', 'churnanchor' ); ?></p>
					</div>
					<div class="ca-kpi">
						<p class="ca-kpi-label"><?php esc_html_e( 'Cancelled', 'churnanchor' ); ?></p>
						<p class="ca-kpi-value"><?php echo esc_html( number_format_i18n( $stats['cancelled'] ) ); ?></p>
						<p class="ca-kpi-sub"><?php esc_html_e( 'completed a cancellation', 'churnanchor' ); ?></p>
					</div>
				</section>

				<section class="ca-panel">
					<h2><?php esc_html_e( 'Monthly revenue kept', 'churnanchor' ); ?></h2>
					<?php if ( ! $by_currency ) : ?>
						<p class="ca-muted"><?php esc_html_e( 'No offers accepted in this period.', 'churnanchor' ); ?></p>
					<?php endif; ?>
					<?php foreach ( $by_currency as $currency => $s ) : ?>
						<table class="ca-ledger">
							<caption class="screen-reader-text"><?php echo esc_html( $currency ); ?></caption>
							<tbody>
								<tr class="is-verified">
									<th scope="row"><?php esc_html_e( 'Verified', 'churnanchor' ); ?> <span><?php esc_html_e( 'renewal paid after the offer', 'churnanchor' ); ?></span></th>
									<td><?php echo esc_html( $this->money( isset( $s['verified'] ) ? $s['verified'] : 0, $currency ) ); ?></td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e( 'Pending', 'churnanchor' ); ?> <span><?php esc_html_e( 'next renewal not due or not paid yet', 'churnanchor' ); ?></span></th>
									<td><?php echo esc_html( $this->money( isset( $s['pending'] ) ? $s['pending'] : 0, $currency ) ); ?></td>
								</tr>
								<tr class="is-lost">
									<th scope="row"><?php esc_html_e( 'Lost later', 'churnanchor' ); ?> <span><?php esc_html_e( 'cancelled before paying again', 'churnanchor' ); ?></span></th>
									<td><?php echo esc_html( $this->money( isset( $s['clawed_back'] ) ? $s['clawed_back'] : 0, $currency ) ); ?></td>
								</tr>
							</tbody>
						</table>
					<?php endforeach; ?>
					<p class="ca-muted"><?php esc_html_e( 'Per month, excluding tax and shipping. A save only counts as verified once the customer pays the next renewal.', 'churnanchor' ); ?></p>
				</section>

				<?php if ( $stats['reasons'] ) : ?>
					<section class="ca-panel">
						<h2><?php esc_html_e( 'Why customers cancel', 'churnanchor' ); ?></h2>
						<ol class="ca-bars">
							<?php foreach ( $stats['reasons'] as $r ) : ?>
								<?php
								$label = isset( $reasons[ $r->reason_key ]['label'] ) ? $reasons[ $r->reason_key ]['label'] : $r->reason_key;
								$share = $total_r ? round( 100 * (int) $r->n / $total_r ) : 0;
								$width = $max_r ? round( 100 * (int) $r->n / $max_r ) : 0;
								?>
								<li>
									<span class="ca-bar-label"><?php echo esc_html( $label ); ?></span>
									<span class="ca-bar-track" aria-hidden="true"><span class="ca-bar-fill" style="width: <?php echo esc_attr( (string) $width ); ?>%"></span></span>
									<span class="ca-bar-num"><?php echo esc_html( number_format_i18n( (int) $r->n ) . ' · ' . $share . '%' ); ?></span>
								</li>
							<?php endforeach; ?>
						</ol>
					</section>
				<?php endif; ?>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ca-panel ca-settings">
				<h2><?php esc_html_e( 'Settings', 'churnanchor' ); ?></h2>
				<input type="hidden" name="action" value="churnanchor_save_settings" />
				<?php wp_nonce_field( 'churnanchor_save_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Cancellation flow', 'churnanchor' ); ?></th>
						<td><label><input type="checkbox" name="enabled" value="yes" <?php checked( 'yes', $settings['enabled'] ); ?> /> <?php esc_html_e( 'Show the flow when customers click "Cancel"', 'churnanchor' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Offers', 'churnanchor' ); ?></th>
						<td>
							<fieldset>
								<label><input type="checkbox" name="offer_skip" value="yes" <?php checked( 'yes', $settings['offer_skip'] ); ?> /> <?php esc_html_e( 'Skip the next renewal', 'churnanchor' ); ?></label><br />
								<label><input type="checkbox" name="offer_pause" value="yes" <?php checked( 'yes', $settings['offer_pause'] ); ?> /> <?php esc_html_e( 'Pause for', 'churnanchor' ); ?></label>
								<select name="pause_max_months" aria-label="<?php esc_attr_e( 'Pause length', 'churnanchor' ); ?>">
									<?php foreach ( array( 1, 2, 3 ) as $m ) : ?>
										<option value="<?php echo esc_attr( (string) $m ); ?>" <?php selected( $m, (int) $settings['pause_max_months'] ); ?>>
											<?php
											/* translators: %d: months */
											echo esc_html( sprintf( _n( '%d month', '%d months', $m, 'churnanchor' ), $m ) );
											?>
										</option>
									<?php endforeach; ?>
								</select>
							</fieldset>
							<p class="description"><?php esc_html_e( 'Both move the next payment date; the subscription stays active. Offers only appear when the payment method supports date changes. A customer can accept one offer per paid renewal, and cancelling afterwards still ends access when the paid period ends.', 'churnanchor' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="churnanchor_cooldown"><?php esc_html_e( 'Offer cooldown', 'churnanchor' ); ?></label></th>
						<td><input id="churnanchor_cooldown" type="number" min="0" max="365" name="cooldown_days" value="<?php echo esc_attr( (string) $settings['cooldown_days'] ); ?>" class="small-text" /> <?php esc_html_e( 'days', 'churnanchor' ); ?>
						<p class="description"><?php esc_html_e( 'After accepting an offer, a customer will not see another one for this many days.', 'churnanchor' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="churnanchor_reasons"><?php esc_html_e( 'Reasons', 'churnanchor' ); ?></label></th>
						<td>
							<textarea id="churnanchor_reasons" name="reasons" rows="7" class="large-text code"><?php
							foreach ( $reasons as $k => $r ) {
								echo esc_textarea( $k . ' | ' . $r['label'] . ' | ' . $r['offer'] ) . "\n";
							}
							?></textarea>
							<p class="description"><?php esc_html_e( 'One per line: key | label | offer. Offer is skip, pause, or empty for none.', 'churnanchor' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Uninstall', 'churnanchor' ); ?></th>
						<td><label><input type="checkbox" name="delete_data" value="yes" <?php checked( 'yes', $settings['delete_data'] ); ?> /> <?php esc_html_e( 'Delete all ChurnAnchor data when the plugin is deleted', 'churnanchor' ); ?></label></td>
					</tr>
				</table>
				<?php submit_button( __( 'Save settings', 'churnanchor' ) ); ?>
			</form>
			<p class="ca-muted ca-legal"><?php esc_html_e( 'ChurnAnchor helps you run a fair cancellation flow. It is not legal advice; check the subscription rules that apply to your store.', 'churnanchor' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Save settings.
	 */
	public function save() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'churnanchor' ) );
		}
		check_admin_referer( 'churnanchor_save_settings' );

		$yes = static function ( $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
			return isset( $_POST[ $key ] ) && 'yes' === sanitize_key( wp_unslash( $_POST[ $key ] ) ) ? 'yes' : 'no';
		};

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$reasons = array();
		$raw     = isset( $_POST['reasons'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reasons'] ) ) : '';
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			$parts = array_map( 'trim', explode( '|', $line ) );
			$key   = sanitize_key( isset( $parts[0] ) ? $parts[0] : '' );
			$label = sanitize_text_field( isset( $parts[1] ) ? $parts[1] : '' );
			$offer = isset( $parts[2] ) && in_array( $parts[2], array( 'skip', 'pause' ), true ) ? $parts[2] : '';
			if ( $key && $label && count( $reasons ) < 12 ) {
				$reasons[ $key ] = array(
					'label' => $label,
					'offer' => $offer,
				);
			}
		}

		$settings = array(
			'enabled'          => $yes( 'enabled' ),
			'offer_skip'       => $yes( 'offer_skip' ),
			'offer_pause'      => $yes( 'offer_pause' ),
			'pause_max_months' => isset( $_POST['pause_max_months'] ) ? max( 1, min( 3, absint( $_POST['pause_max_months'] ) ) ) : 1,
			'cooldown_days'    => isset( $_POST['cooldown_days'] ) ? min( 365, absint( $_POST['cooldown_days'] ) ) : 90,
			'delete_data'      => $yes( 'delete_data' ),
			'reasons'          => $reasons ? $reasons : Settings::default_reasons(),
		);
		// phpcs:enable

		update_option( Settings::OPTION, $settings, true );
		wp_safe_redirect( add_query_arg( 'updated', '1', admin_url( 'admin.php?page=' . self::SLUG ) ) );
		exit;
	}
}

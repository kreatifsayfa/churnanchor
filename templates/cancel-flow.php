<?php
/**
 * Cancellation flow template.
 *
 * Override by copying to yourtheme/churnanchor/cancel-flow.php.
 * Keep the cancel button visible and as easy to use as the other buttons on every step.
 *
 * @package ChurnAnchor
 *
 * @var string           $step
 * @var WC_Subscription  $subscription
 * @var array            $reasons
 * @var string           $reason
 * @var array            $offers       Each: key, label, summary, rows[label, before, after].
 * @var int              $access_end   GMT timestamp when access ends if cancelled now (0 = immediately).
 * @var string           $flow_id
 * @var string           $nonce_action
 * @var string           $view_url
 */

defined( 'ABSPATH' ) || exit;

$churnanchor_hidden = static function ( $action ) use ( $subscription, $flow_id, $nonce_action, $reason ) {
	wp_nonce_field( $nonce_action, '_churnanchor_nonce', false );
	if ( $action ) {
		printf( '<input type="hidden" name="churnanchor_action" value="%s" />', esc_attr( $action ) );
	}
	printf( '<input type="hidden" name="churnanchor_sub" value="%d" />', (int) $subscription->get_id() );
	printf( '<input type="hidden" name="churnanchor_flow" value="%s" />', esc_attr( $flow_id ) );
	if ( $reason ) {
		printf( '<input type="hidden" name="churnanchor_reason" value="%s" />', esc_attr( $reason ) );
	}
};

$churnanchor_date = static function ( $timestamp ) {
	return wp_date( wc_date_format(), (int) $timestamp ); // Store timezone.
};

$churnanchor_items = array();
foreach ( $subscription->get_items() as $churnanchor_item ) {
	$churnanchor_items[] = $churnanchor_item->get_name();
}
$churnanchor_steps = 'reason' === $step ? 1 : 2;
?>
<div id="churnanchor-flow" class="churnanchor-flow churnanchor-step-<?php echo esc_attr( $step ); ?>">

	<?php wc_print_notices(); ?>

	<div class="ca-summary">
		<span class="ca-summary-name">
			<?php
			echo esc_html( implode( ', ', $churnanchor_items ) );
			/* translators: %s: subscription number */
			echo ' <span class="ca-muted">' . esc_html( sprintf( __( 'Subscription #%s', 'churnanchor' ), $subscription->get_order_number() ) ) . '</span>';
			?>
		</span>
		<span class="ca-summary-price"><?php echo wp_kses_post( $subscription->get_formatted_order_total() ); ?></span>
	</div>

	<p class="ca-step">
		<?php
		/* translators: 1: current step, 2: total steps */
		echo esc_html( sprintf( __( 'Step %1$d of %2$d', 'churnanchor' ), $churnanchor_steps, 2 ) );
		?>
	</p>

	<?php if ( 'reason' === $step ) : ?>
		<form method="post" class="ca-form">
			<?php $churnanchor_hidden( '' ); ?>
			<h2 class="ca-title" id="churnanchor-title" tabindex="-1"><?php esc_html_e( 'Before you go, can you tell us why?', 'churnanchor' ); ?></h2>
			<p class="ca-lead"><?php esc_html_e( 'This is optional and helps us improve. If you just want to cancel, use the button at the bottom.', 'churnanchor' ); ?></p>

			<fieldset class="ca-options">
				<legend class="screen-reader-text"><?php esc_html_e( 'Cancellation reason', 'churnanchor' ); ?></legend>
				<?php foreach ( $reasons as $churnanchor_key => $churnanchor_r ) : ?>
					<label class="ca-option">
						<input type="radio" name="churnanchor_reason" value="<?php echo esc_attr( $churnanchor_key ); ?>" required />
						<span><?php echo esc_html( $churnanchor_r['label'] ); ?></span>
					</label>
				<?php endforeach; ?>
			</fieldset>

			<p class="ca-field">
				<label for="churnanchor_reason_text"><?php esc_html_e( 'Anything you would like to add? (optional)', 'churnanchor' ); ?></label>
				<textarea id="churnanchor_reason_text" name="churnanchor_reason_text" rows="3" maxlength="1000"></textarea>
			</p>

			<div class="ca-actions">
				<button type="submit" class="ca-btn ca-btn-primary" name="churnanchor_action" value="reason"><?php esc_html_e( 'Continue', 'churnanchor' ); ?></button>
				<button type="submit" class="ca-btn ca-btn-secondary" name="churnanchor_action" value="cancel" formnovalidate><?php esc_html_e( 'Cancel subscription now', 'churnanchor' ); ?></button>
			</div>
			<p class="ca-keep"><a href="<?php echo esc_url( $view_url ); ?>"><?php esc_html_e( 'Keep my subscription', 'churnanchor' ); ?></a></p>
		</form>

	<?php elseif ( 'offer' === $step ) : ?>
		<h2 class="ca-title" id="churnanchor-title" tabindex="-1">
			<?php echo esc_html( count( $offers ) > 1 ? __( 'Would one of these work better?', 'churnanchor' ) : __( 'Would this work better?', 'churnanchor' ) ); ?>
		</h2>
		<p class="ca-lead">
			<?php
			if ( $access_end ) {
				/* translators: %s: date */
				echo esc_html( sprintf( __( 'You can still cancel: you would not be charged again and keep access until %s.', 'churnanchor' ), $churnanchor_date( $access_end ) ) );
			} else {
				esc_html_e( 'You can still cancel: you would not be charged again and your subscription would end now.', 'churnanchor' );
			}
			?>
		</p>

		<?php foreach ( $offers as $churnanchor_offer ) : ?>
			<div class="ca-offer">
				<p class="ca-offer-name"><?php echo esc_html( $churnanchor_offer['label'] ); ?></p>
				<?php if ( ! empty( $churnanchor_offer['rows'] ) ) : ?>
					<dl class="ca-dates">
						<?php foreach ( $churnanchor_offer['rows'] as $churnanchor_row ) : ?>
							<div>
								<dt><?php echo esc_html( $churnanchor_row['label'] ); ?></dt>
								<dd><s><?php echo esc_html( $churnanchor_row['before'] ); ?></s> <span aria-hidden="true">→</span> <strong><?php echo esc_html( $churnanchor_row['after'] ); ?></strong></dd>
							</div>
						<?php endforeach; ?>
					</dl>
				<?php endif; ?>
				<?php if ( ! empty( $churnanchor_offer['summary'] ) ) : ?>
					<p class="ca-offer-note"><?php echo esc_html( $churnanchor_offer['summary'] ); ?></p>
				<?php endif; ?>
				<?php if ( count( $offers ) > 1 ) : ?>
					<form method="post" class="ca-offer-accept">
						<?php $churnanchor_hidden( 'accept' ); ?>
						<input type="hidden" name="churnanchor_offer" value="<?php echo esc_attr( $churnanchor_offer['key'] ); ?>" />
						<button type="submit" class="ca-btn ca-btn-primary"><?php echo esc_html( $churnanchor_offer['label'] ); ?></button>
					</form>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>

		<div class="ca-actions">
			<?php if ( 1 === count( $offers ) ) : ?>
				<form method="post">
					<?php $churnanchor_hidden( 'accept' ); ?>
					<input type="hidden" name="churnanchor_offer" value="<?php echo esc_attr( $offers[0]['key'] ); ?>" />
					<button type="submit" class="ca-btn ca-btn-primary"><?php echo esc_html( $offers[0]['label'] ); ?></button>
				</form>
			<?php endif; ?>
			<form method="post">
				<?php $churnanchor_hidden( 'cancel' ); ?>
				<button type="submit" class="ca-btn ca-btn-secondary"><?php esc_html_e( 'No thanks, cancel my subscription', 'churnanchor' ); ?></button>
			</form>
		</div>
		<p class="ca-keep"><a href="<?php echo esc_url( $view_url ); ?>"><?php esc_html_e( 'Keep my subscription', 'churnanchor' ); ?></a></p>

	<?php else : ?>
		<form method="post" class="ca-form">
			<?php $churnanchor_hidden( 'cancel' ); ?>
			<h2 class="ca-title" id="churnanchor-title" tabindex="-1"><?php esc_html_e( 'Confirm cancellation', 'churnanchor' ); ?></h2>
			<div class="ca-offer ca-offer-plain">
				<dl class="ca-dates">
					<div>
						<dt><?php esc_html_e( 'Future payments', 'churnanchor' ); ?></dt>
						<dd><?php esc_html_e( 'None. You will not be charged again.', 'churnanchor' ); ?></dd>
					</div>
					<div>
						<dt><?php esc_html_e( 'Access', 'churnanchor' ); ?></dt>
						<dd>
							<?php
							if ( $access_end ) {
								/* translators: %s: date */
								echo esc_html( sprintf( __( 'Until %s, the end of the period you paid for', 'churnanchor' ), $churnanchor_date( $access_end ) ) );
							} else {
								esc_html_e( 'Ends immediately', 'churnanchor' );
							}
							?>
						</dd>
					</div>
				</dl>
			</div>
			<div class="ca-actions">
				<button type="submit" class="ca-btn ca-btn-primary"><?php esc_html_e( 'Cancel subscription', 'churnanchor' ); ?></button>
				<a class="ca-btn ca-btn-secondary" href="<?php echo esc_url( $view_url ); ?>"><?php esc_html_e( 'Keep my subscription', 'churnanchor' ); ?></a>
			</div>
		</form>
	<?php endif; ?>

</div>

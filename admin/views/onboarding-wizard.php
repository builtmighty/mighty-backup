<?php
/**
 * Onboarding wizard partial.
 *
 * Variables available:
 *   $onboarding_steps - array of step descriptors from
 *     Mighty_Backup_Settings::get_onboarding_steps().
 *
 * Rendered above the tabs by settings-page.php when needs_onboarding() is true.
 * Each step is a chip that links into the relevant tab (the existing JS
 * switchTab() handles the navigation).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$total = count( $onboarding_steps );
$done  = 0;
foreach ( $onboarding_steps as $s ) {
	if ( ! empty( $s['done'] ) ) {
		$done++;
	}
}
?>

<div class="mb-onboarding" role="region" aria-label="<?php esc_attr_e( 'Mighty Backup setup wizard', 'mighty-backup' ); ?>">
	<div class="mb-onboarding-header">
		<h2 class="mb-onboarding-title"><?php esc_html_e( 'Getting started', 'mighty-backup' ); ?></h2>
		<span class="mb-onboarding-progress">
			<?php
			/* translators: 1: completed steps, 2: total steps */
			printf( esc_html__( 'Step %1$d of %2$d', 'mighty-backup' ), (int) $done, (int) $total );
			?>
		</span>
		<button type="button" class="mb-onboarding-dismiss" aria-label="<?php esc_attr_e( 'Dismiss setup wizard', 'mighty-backup' ); ?>">
			<?php esc_html_e( 'Skip wizard', 'mighty-backup' ); ?>
		</button>
	</div>

	<ol class="mb-onboarding-steps">
		<?php foreach ( $onboarding_steps as $index => $step ) :
			$is_done = ! empty( $step['done'] );
			$classes = [ 'mb-onboarding-step' ];
			if ( $is_done ) {
				$classes[] = 'mb-onboarding-step-done';
			}
			?>
			<li class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
				<a href="#<?php echo esc_attr( $step['tab'] ); ?>"
				   class="mb-onboarding-step-link"
				   data-tab="<?php echo esc_attr( $step['tab'] ); ?>">
					<span class="mb-onboarding-step-marker" aria-hidden="true">
						<?php if ( $is_done ) : ?>
							<span class="mb-onboarding-check">&#10003;</span>
						<?php else : ?>
							<span class="mb-onboarding-num"><?php echo esc_html( (int) $index + 1 ); ?></span>
						<?php endif; ?>
					</span>
					<span class="mb-onboarding-step-body">
						<span class="mb-onboarding-step-label"><?php echo esc_html( $step['label'] ); ?></span>
						<span class="mb-onboarding-step-hint"><?php echo esc_html( $step['hint'] ); ?></span>
					</span>
				</a>
			</li>
		<?php endforeach; ?>
	</ol>
</div>

<?php
/**
 * Live tally shared by the ballot and the room display.
 *
 * @package Poller
 * @var array $state
 * @var string $total_label
 * @var string $summary
 * @var string $your_vote
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( 'words' === $state['selection'] ) {
	require __DIR__ . '/cloud.php';
	return;
}

$poller_ranked = 'dots' === $state['selection'];
// Dot polls send choice id => dots. The others send a list of choice ids.
$poller_mine = $poller_ranked ? array_map( 'intval', $state['mine'] ) : array_fill_keys( array_map( 'intval', $state['mine'] ), 1 );
$poller_rows = $poller_ranked ? BmltEnabled\Poller\Tally::rank( $state['choices'] ) : $state['choices'];
$poller_extra = $poller_ranked && count( $poller_rows ) > BmltEnabled\Poller\Tally::TOP;
?>
<section class="poller-results<?php echo $poller_ranked ? ' poller-results--ranked' : ''; ?>">
	<h2><?php echo esc_html( __( 'Results', 'poller' ) ); ?></h2>
	<p class="poller-sr" id="poller-live"><?php echo esc_html( $summary ); ?></p>
	<div class="poller-result-list">
		<?php foreach ( $poller_rows as $poller_rank => $poller_choice ) : ?>
			<?php
			$poller_votes = (int) $poller_choice['votes'];
			$poller_pct = BmltEnabled\Poller\Tally::share( $poller_votes, $state );
			$poller_have = (int) ( $poller_mine[ (int) $poller_choice['id'] ] ?? 0 );
			$poller_picture = ! empty( $poller_choice['image']['url'] );
			$poller_class = 'poller-result';
			$poller_class .= $poller_picture ? ' poller-result--picture' : '';
			$poller_class .= $poller_ranked && $poller_rank >= BmltEnabled\Poller\Tally::TOP ? ' poller-result--extra' : '';
			?>
			<div class="<?php echo esc_attr( $poller_class ); ?>" data-choice="<?php echo esc_attr( (string) $poller_choice['id'] ); ?>">
				<?php if ( $poller_picture ) : ?>
					<img class="poller-result-thumb" src="<?php echo esc_url( $poller_choice['image']['url'] ); ?>" alt="">
				<?php endif; ?>
				<div class="poller-result-label">
					<span class="poller-result-name"><?php echo esc_html( $poller_choice['label'] ); ?></span>
					<span class="poller-yours"<?php echo $poller_have > 0 ? '' : ' hidden'; ?>>
					<?php
						echo esc_html(
							$poller_ranked ? sprintf(
							/* translators: %s is how many dots this browser put on an item. */
								__( 'Your dots: %s', 'poller' ),
								$poller_have
							) : $your_vote
						);
					?>
					</span>
				</div>
				<div class="poller-count"><?php echo esc_html( (string) $poller_votes ); ?></div>
				<div class="poller-track" aria-hidden="true"><span class="poller-bar" style="width: <?php echo esc_attr( (string) $poller_pct ); ?>%"></span></div>
			</div>
		<?php endforeach; ?>
	</div>
	<?php if ( $poller_extra ) : ?>
		<button type="button" class="poller-more" data-poller-more hidden></button>
	<?php endif; ?>
	<p class="poller-total" id="poller-total"><?php echo esc_html( $total_label ); ?></p>
	<p class="poller-paused" data-poller-paused hidden></p>
</section>

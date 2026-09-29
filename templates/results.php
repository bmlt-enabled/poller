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

if (!defined('ABSPATH')) {
    exit;
}

$ballots = (int) $state['ballots'];
$mine = array_map('intval', $state['mine']);
?>
<section class="poller-results">
    <h2><?php echo esc_html(__('Results', 'poller')); ?></h2>
    <p class="poller-sr" id="poller-live"><?php echo esc_html($summary); ?></p>
    <div class="poller-result-list">
        <?php foreach ($state['choices'] as $choice) : ?>
            <?php
            $votes = (int) $choice['votes'];
            $pct = $ballots > 0 ? round(($votes / $ballots) * 100, 1) : 0;
            $is_mine = in_array((int) $choice['id'], $mine, true);
            $picture = !empty($choice['image']['url']);
            ?>
            <div class="poller-result<?php echo $picture ? ' poller-result--picture' : ''; ?>" data-choice="<?php echo esc_attr((string) $choice['id']); ?>">
                <?php if ($picture) : ?>
                    <img class="poller-result-thumb" src="<?php echo esc_url($choice['image']['url']); ?>" alt="">
                <?php endif; ?>
                <div class="poller-result-label">
                    <span class="poller-result-name"><?php echo esc_html($choice['label']); ?></span>
                    <span class="poller-yours"<?php echo $is_mine ? '' : ' hidden'; ?>><?php echo esc_html($your_vote); ?></span>
                </div>
                <div class="poller-count"><?php echo esc_html((string) $votes); ?></div>
                <div class="poller-track" aria-hidden="true"><span class="poller-bar" style="width: <?php echo esc_attr((string) $pct); ?>%"></span></div>
            </div>
        <?php endforeach; ?>
    </div>
    <p class="poller-total" id="poller-total"><?php echo esc_html($total_label); ?></p>
    <p class="poller-paused" data-poller-paused hidden></p>
</section>

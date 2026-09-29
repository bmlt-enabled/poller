<?php
/**
 * Room display: code, QR, and the live tally.
 *
 * @package Poller
 * @var array $state
 * @var array $client
 * @var string $join_human
 * @var string $total_label
 * @var string $summary
 * @var string $your_vote
 */

if (!defined('ABSPATH')) {
    exit;
}

$open = $state['status'] === 'open';
?>
<main class="poller-board">
    <header class="poller-board-head">
        <div class="poller-board-copy">
            <?php if (!empty($state['image']['url'])) : ?>
                <figure class="poller-prompt">
                    <img src="<?php echo esc_url($state['image']['url']); ?>" alt="<?php echo esc_attr($state['image']['alt']); ?>">
                </figure>
            <?php endif; ?>
            <h1 id="poller-question"><?php echo esc_html($state['question']); ?></h1>
            <p class="poller-code"><?php echo esc_html($state['code']); ?></p>
            <p class="poller-join-hint"><?php echo esc_html($join_human); ?></p>
        </div>
        <div class="poller-qr" id="poller-qr"></div>
    </header>
    <p class="poller-closed" data-poller-closed<?php echo $open ? ' hidden' : ''; ?>><?php echo esc_html(__('This poll is closed.', 'poller')); ?></p>
    <noscript><p class="poller-hint"><?php echo esc_html(__('Refresh the page to update the tally.', 'poller')); ?></p></noscript>
    <?php require __DIR__ . '/results.php'; ?>
</main>
<script type="application/json" id="poller-state"><?php echo wp_json_encode($client); ?></script>

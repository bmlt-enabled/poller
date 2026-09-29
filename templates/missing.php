<?php
/**
 * Unknown code.
 *
 * @package Poller
 * @var string $join_url
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<main class="poller-sheet">
    <a class="poller-mark" href="<?php echo esc_url($join_url); ?>">Poller</a>
    <h1><?php echo esc_html(__('That code does not match a poll.', 'poller')); ?></h1>
    <p><a class="poller-text-link" href="<?php echo esc_url($join_url); ?>"><?php echo esc_html(__('Try another code', 'poller')); ?></a></p>
</main>

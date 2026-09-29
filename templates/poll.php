<?php
/**
 * Ballot.
 *
 * @package Poller
 * @var array $state
 * @var array $client
 * @var string $error
 * @var string $join_url
 * @var string $post_url
 * @var string $nonce
 * @var bool $can_manage
 * @var string $total_label
 * @var string $summary
 * @var string $your_vote
 */

if (!defined('ABSPATH')) {
    exit;
}

$open = $state['status'] === 'open';
$voted = !empty($state['mine']);
$multiple = $state['selection'] === 'multi';
?>
<header class="poller-top">
    <a class="poller-mark" href="<?php echo esc_url($join_url); ?>">Poller</a>
    <?php if ($can_manage) : ?>
        <a class="poller-text-link" href="<?php echo esc_url($state['urls']['board']); ?>"><?php echo esc_html(__('Room display', 'poller')); ?></a>
    <?php endif; ?>
</header>
<main class="poller-sheet">
    <?php if (!empty($state['image']['url'])) : ?>
        <figure class="poller-prompt">
            <img src="<?php echo esc_url($state['image']['url']); ?>" alt="<?php echo esc_attr($state['image']['alt']); ?>">
        </figure>
    <?php endif; ?>
    <h1 id="poller-question"><?php echo esc_html($state['question']); ?></h1>
    <?php if ($multiple && $open) : ?>
        <p class="poller-hint"><?php echo esc_html(__('Choose as many as you like.', 'poller')); ?></p>
    <?php endif; ?>
    <p class="poller-error" role="alert" data-poller-error<?php echo $error === '' ? ' hidden' : ''; ?>><?php echo esc_html($error); ?></p>
    <p class="poller-closed" data-poller-closed<?php echo $open ? ' hidden' : ''; ?>><?php echo esc_html(__('This poll is closed.', 'poller')); ?></p>
    <form id="poller-vote" method="post" action="<?php echo esc_url($post_url); ?>"<?php echo $open ? '' : ' hidden'; ?>>
        <input type="hidden" name="action" value="poller_vote">
        <input type="hidden" name="code" value="<?php echo esc_attr($state['code']); ?>">
        <input type="hidden" name="nonce" value="<?php echo esc_attr($nonce); ?>">
        <div class="poller-choices <?php echo $state['kind'] === 'image' ? 'poller-choices--pictures' : 'poller-choices--text'; ?>" role="group" aria-labelledby="poller-question">
            <?php foreach ($state['choices'] as $choice) : ?>
                <?php $checked = in_array((int) $choice['id'], array_map('intval', $state['mine']), true); ?>
                <label class="poller-choice">
                    <input type="<?php echo $multiple ? 'checkbox' : 'radio'; ?>" name="choices[]" value="<?php echo esc_attr((string) $choice['id']); ?>"<?php echo $checked ? ' checked' : ''; ?><?php echo $multiple ? '' : ' required'; ?>>
                    <?php if ($state['kind'] === 'image' && !empty($choice['image']['url'])) : ?>
                        <img src="<?php echo esc_url($choice['image']['url']); ?>" alt="">
                    <?php endif; ?>
                    <span class="poller-choice-label"><?php echo esc_html($choice['label']); ?></span>
                </label>
            <?php endforeach; ?>
        </div>
        <button class="poller-submit" type="submit"><?php echo esc_html($voted ? __('Update vote', 'poller') : __('Vote', 'poller')); ?></button>
    </form>
    <noscript><p class="poller-hint"><?php echo esc_html(__('You can vote. Live updates need JavaScript.', 'poller')); ?></p></noscript>
    <?php require __DIR__ . '/results.php'; ?>
</main>
<script type="application/json" id="poller-state"><?php echo wp_json_encode($client); ?></script>

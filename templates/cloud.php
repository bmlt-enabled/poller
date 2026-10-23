<?php
/**
 * Word cloud tally shared by the ballot and the room display: the cloud,
 * then the same words as a ranked list so they read without the picture.
 *
 * Without JavaScript the cloud is a wrapped row of sized words. The script
 * lays it out as a cloud and keeps it live.
 *
 * @package Poller
 * @var array $state
 * @var string $total_label
 * @var string $summary
 * @var string $your_vote
 * @var array|null $moderate
 */

if (!defined('ABSPATH')) {
    exit;
}

$poller_words = $state['words'];
$poller_ballots = (int) $state['ballots'];
$poller_top = $poller_words ? (int) $poller_words[0]['count'] : 0;
$poller_yours = array_fill_keys($state['mine_keys'], true);
$poller_moderate = isset($moderate) && is_array($moderate) ? $moderate : null;
?>
<section class="poller-results poller-results--ranked poller-results--cloud">
    <h2><?php echo esc_html(__('Results', 'poller')); ?></h2>
    <p class="poller-sr" id="poller-live"><?php echo esc_html($summary); ?></p>
    <div class="poller-cloud" data-poller-cloud aria-hidden="true">
        <?php foreach ($poller_words as $poller_word) : ?>
            <?php
            $poller_count = (int) $poller_word['count'];
            $poller_size = $poller_top > 1 ? 1 + 1.6 * sqrt(($poller_count - 1) / ($poller_top - 1)) : 1.6;
            ?>
            <span class="poller-cloud-word" style="font-size: <?php echo esc_attr((string) round($poller_size, 2)); ?>em"><?php echo esc_html($poller_word['text']); ?></span>
        <?php endforeach; ?>
    </div>
    <p class="poller-cloud-empty" data-poller-empty<?php echo $poller_words ? ' hidden' : ''; ?>><?php echo esc_html(__('Words will show up here as people answer.', 'poller')); ?></p>
    <div class="poller-result-list" data-poller-words>
        <?php foreach ($poller_words as $poller_rank => $poller_word) : ?>
            <?php
            $poller_count = (int) $poller_word['count'];
            $poller_pct = $poller_ballots > 0 ? round(($poller_count / $poller_ballots) * 100, 1) : 0;
            $poller_class = 'poller-result';
            $poller_class .= $poller_rank >= BmltEnabled\Poller\Tally::TOP ? ' poller-result--extra' : '';
            ?>
            <div class="<?php echo esc_attr($poller_class); ?>" data-word="<?php echo esc_attr($poller_word['key']); ?>">
                <div class="poller-result-label">
                    <span class="poller-result-name"><?php echo esc_html($poller_word['text']); ?></span>
                    <span class="poller-yours"<?php echo isset($poller_yours[$poller_word['key']]) ? '' : ' hidden'; ?>><?php echo esc_html(__('Your word', 'poller')); ?></span>
                    <?php if ($poller_moderate) : ?>
                        <form class="poller-hide-word" method="post" action="<?php echo esc_url($poller_moderate['url']); ?>">
                            <input type="hidden" name="action" value="poller_hide_word">
                            <input type="hidden" name="poll_id" value="<?php echo esc_attr((string) $poller_moderate['poll']); ?>">
                            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($poller_moderate['nonce']); ?>">
                            <input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr($state['urls']['poll']); ?>">
                            <input type="hidden" name="word" value="<?php echo esc_attr($poller_word['text']); ?>">
                            <input type="hidden" name="hide" value="1">
                            <button type="submit" aria-label="<?php echo esc_attr(sprintf($poller_moderate['hideLabel'], $poller_word['text'])); ?>"><?php echo esc_html($poller_moderate['hide']); ?></button>
                        </form>
                    <?php endif; ?>
                </div>
                <div class="poller-count"><?php echo esc_html((string) $poller_count); ?></div>
                <div class="poller-track" aria-hidden="true"><span class="poller-bar" style="width: <?php echo esc_attr((string) $poller_pct); ?>%"></span></div>
            </div>
        <?php endforeach; ?>
    </div>
    <button type="button" class="poller-more" data-poller-more hidden></button>
    <p class="poller-total" id="poller-total"><?php echo esc_html($total_label); ?></p>
    <p class="poller-paused" data-poller-paused hidden></p>
</section>

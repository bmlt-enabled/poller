<?php
/**
 * Word cloud entries inside the ballot form: one text box per word.
 *
 * @package Poller
 * @var array $state
 * @var int $poller_budget
 */

if (!defined('ABSPATH')) {
    exit;
}

$poller_entries = is_array($state['mine']) ? array_values($state['mine']) : [];
?>
<div class="poller-words" role="group" aria-labelledby="poller-question">
    <?php for ($poller_n = 0; $poller_n < $poller_budget; $poller_n++) : ?>
        <?php $poller_field = 'poller-word-' . ($poller_n + 1); ?>
        <label class="poller-sr" for="<?php echo esc_attr($poller_field); ?>">
            <?php
            echo esc_html(sprintf(
                /* translators: %d is the position of the word box, starting at 1. */
                __('Word %d', 'poller'),
                $poller_n + 1
            ));
            ?>
        </label>
        <input class="poller-word-input" type="text" id="<?php echo esc_attr($poller_field); ?>" name="words[]" maxlength="<?php echo esc_attr((string) BmltEnabled\Poller\BallotRules::WORD_LENGTH); ?>" autocomplete="off" spellcheck="true" enterkeyhint="<?php echo $poller_n + 1 < $poller_budget ? 'next' : 'send'; ?>" value="<?php echo esc_attr((string) ($poller_entries[$poller_n] ?? '')); ?>" data-word-input<?php echo $poller_n === 0 ? ' required' : ''; ?>>
    <?php endfor; ?>
</div>

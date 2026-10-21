<?php
/**
 * Dot voting controls inside the ballot form.
 *
 * Without JavaScript each item is a checkbox (one dot per item) or a number
 * dropdown. The script adds the dot counter, the − / + buttons, and the filter.
 *
 * @package Poller
 * @var array $state
 * @var int $poller_budget
 * @var int $poller_per_choice
 */

if (!defined('ABSPATH')) {
    exit;
}

$poller_mine_dots = is_array($state['mine']) ? $state['mine'] : [];
$poller_stack = $poller_per_choice > 1;
?>
<div class="poller-dots-counter" data-poller-counter aria-live="polite" hidden>
    <span class="poller-dots-pips" aria-hidden="true"></span>
    <span class="poller-dots-left"></span>
</div>
<?php if (count($state['choices']) >= 15) : ?>
    <p class="poller-filter" data-poller-filter hidden>
        <label class="poller-sr" for="poller-filter-input"><?php echo esc_html(__('Filter items', 'poller')); ?></label>
        <input type="search" id="poller-filter-input" placeholder="<?php echo esc_attr(__('Filter items', 'poller')); ?>" autocomplete="off">
    </p>
<?php endif; ?>
<div class="poller-choices poller-choices--dots" role="group" aria-labelledby="poller-question">
    <?php foreach ($state['choices'] as $poller_choice) : ?>
        <?php
        $poller_id = (int) $poller_choice['id'];
        $poller_have = (int) ($poller_mine_dots[$poller_id] ?? 0);
        $poller_thumb = $state['kind'] === 'image' && !empty($poller_choice['image']['url']);
        $poller_field = 'poller-dot-' . $poller_id;
        ?>
        <div class="poller-dot-row<?php echo $poller_have > 0 ? ' is-on' : ''; ?>" data-dot-row data-label="<?php echo esc_attr(function_exists('mb_strtolower') ? mb_strtolower($poller_choice['label']) : strtolower($poller_choice['label'])); ?>">
            <?php if (!$poller_stack) : ?>
                <label class="poller-dot-toggle">
                    <input type="checkbox" name="choices[<?php echo esc_attr((string) $poller_id); ?>]" value="1" data-dot-input<?php echo $poller_have > 0 ? ' checked' : ''; ?>>
                    <?php if ($poller_thumb) : ?>
                        <img class="poller-dot-thumb" src="<?php echo esc_url($poller_choice['image']['url']); ?>" alt="">
                    <?php endif; ?>
                    <span class="poller-choice-label"><?php echo esc_html($poller_choice['label']); ?></span>
                    <span class="poller-dot-mark" aria-hidden="true"></span>
                </label>
            <?php else : ?>
                <label class="poller-dot-name" for="<?php echo esc_attr($poller_field); ?>">
                    <?php if ($poller_thumb) : ?>
                        <img class="poller-dot-thumb" src="<?php echo esc_url($poller_choice['image']['url']); ?>" alt="">
                    <?php endif; ?>
                    <span class="poller-choice-label"><?php echo esc_html($poller_choice['label']); ?></span>
                </label>
                <select class="poller-dot-select" id="<?php echo esc_attr($poller_field); ?>" name="choices[<?php echo esc_attr((string) $poller_id); ?>]" data-dot-input>
                    <?php for ($poller_n = 0; $poller_n <= $poller_per_choice; $poller_n++) : ?>
                        <option value="<?php echo esc_attr((string) $poller_n); ?>"<?php echo $poller_n === $poller_have ? ' selected' : ''; ?>><?php echo esc_html((string) $poller_n); ?></option>
                    <?php endfor; ?>
                </select>
                <span class="poller-stepper" hidden>
                    <button type="button" data-step="-1" aria-label="<?php echo esc_attr(sprintf(
                        /* translators: %s is the item's label. */
                        __('Take a dot off %s', 'poller'),
                        $poller_choice['label']
                    )); ?>">&minus;</button>
                    <span class="poller-stepper-count" aria-hidden="true"><?php echo esc_html((string) $poller_have); ?></span>
                    <button type="button" data-step="1" aria-label="<?php echo esc_attr(sprintf(
                        /* translators: %s is the item's label. */
                        __('Put a dot on %s', 'poller'),
                        $poller_choice['label']
                    )); ?>">+</button>
                </span>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

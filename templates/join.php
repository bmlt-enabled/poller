<?php
/**
 * Code entry.
 *
 * @package Poller
 * @var string $error
 * @var string $code_value
 * @var string $join_url
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<main class="poller-sheet poller-sheet--join">
    <p class="poller-mark">Poller</p>
    <form method="get" action="<?php echo esc_url($join_url); ?>">
        <input type="hidden" name="poller_view" value="join">
        <label class="poller-join-label" for="poller-code-input"><?php echo esc_html(__('Enter the code', 'poller')); ?></label>
        <input id="poller-code-input" class="poller-code-input" name="code" value="<?php echo esc_attr($code_value); ?>" required maxlength="14" autocapitalize="characters" autocomplete="off" spellcheck="false" inputmode="text" autofocus>
        <?php if ($error !== '') : ?>
            <p class="poller-error" role="alert"><?php echo esc_html($error); ?></p>
        <?php endif; ?>
        <button class="poller-submit" type="submit"><?php echo esc_html(__('Open poll', 'poller')); ?></button>
    </form>
</main>

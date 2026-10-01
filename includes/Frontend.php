<?php
/**
 * Public join, vote, and room-display pages.
 *
 * These pages are rendered on their own, without the theme, so a QR
 * landing stays fast and readable.
 *
 * @package Poller
 */

namespace BmltEnabled\Poller;

if (!defined('ABSPATH')) {
    exit;
}

class Frontend
{
    private Repository $repo;
    private Presenter $presenter;
    private VoteService $votes;

    public function __construct(Repository $repo, Presenter $presenter, VoteService $votes)
    {
        $this->repo = $repo;
        $this->presenter = $presenter;
        $this->votes = $votes;
    }

    public function hooks(): void
    {
        add_filter('redirect_canonical', [$this, 'canonical']);
        add_filter('pre_handle_404', [$this, 'pre_handle_404'], 10, 2);
        add_action('template_redirect', [$this, 'route']);
        add_action('admin_post_nopriv_poller_vote', [$this, 'post_vote']);
        add_action('admin_post_poller_vote', [$this, 'post_vote']);
    }

    /**
     * @param mixed $redirect
     * @return mixed
     */
    public function canonical($redirect)
    {
        if (get_query_var('poller_view')) {
            return false;
        }
        return $redirect;
    }

    /**
     * @param mixed $handled
     * @param \WP_Query $query
     * @return mixed
     */
    public function pre_handle_404($handled, $query)
    {
        unset($query);
        if (get_query_var('poller_view')) {
            return true;
        }
        return $handled;
    }

    public function route(): void
    {
        $view = (string) get_query_var('poller_view');
        if ($view === 'join') {
            $this->render_join();
            exit;
        }
        if ($view === 'poll' || $view === 'board') {
            $this->render_poll($view);
            exit;
        }
    }

    public function post_vote(): void
    {
        $code = isset($_POST['code']) ? Codes::normalize(sanitize_text_field(wp_unslash($_POST['code']))) : '';
        $target = Codes::is_valid($code) ? Plugin::poll_url($code) : Plugin::join_url();
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';

        if (!wp_verify_nonce($nonce, 'poller_vote_' . $code)) {
            $this->redirect_vote_error($target, 'poller_nonce');
        }

        $choices = [];
        if (isset($_POST['choices']) && is_array($_POST['choices'])) {
            $choices = map_deep(wp_unslash($_POST['choices']), 'absint');
        } elseif (isset($_POST['choices'])) {
            $choices = [absint(wp_unslash($_POST['choices']))];
        }

        $result = $this->votes->vote($code, $choices);
        if (is_wp_error($result)) {
            $this->redirect_vote_error($target, $result->get_error_code());
        }

        wp_safe_redirect($target);
        exit;
    }

    private function render_join(): void
    {
        $error = '';
        $code_value = '';

        // Public join lookup. The code only selects a poll to redirect to.
        if (isset($_GET['code'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $code_value = sanitize_text_field(wp_unslash($_GET['code'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $raw = Codes::normalize($code_value);
            if (Codes::has_ambiguous($raw)) {
                $error = __('That code has a character that is not used. Codes leave out 0, 1, I, L, and O.', 'poller');
            } elseif (!Codes::is_valid($raw)) {
                $error = __('Enter the 6-character code from the room.', 'poller');
            } elseif ($this->repo->get_poll_by_code($raw) === null) {
                $error = __('That code does not match a poll.', 'poller');
            } else {
                wp_safe_redirect(Plugin::poll_url($raw));
                exit;
            }
        }

        $body = $this->capture('join', [
            'error' => $error,
            'code_value' => $code_value,
            'join_url' => Plugin::join_url(),
        ]);
        $this->send(__('Enter the code', 'poller'), 'poller-body poller-body--join', $body, 200, false);
    }

    private function render_poll(string $view): void
    {
        $code = Codes::normalize((string) get_query_var('poller_code'));
        $poll = Codes::is_valid($code) ? $this->repo->get_poll_by_code($code) : null;
        if (!$poll) {
            $body = $this->capture('missing', [
                'join_url' => Plugin::join_url(),
            ]);
            $this->send(__('Poll not found', 'poller'), 'poller-body', $body, 404, false);
            return;
        }

        Voter::ensure();
        $hash = Voter::hash(Voter::token());
        $state = $this->presenter->payload($poll, $hash);
        $title = (string) $state['question'];

        $body = $this->capture($view === 'board' ? 'board' : 'poll', [
            'state' => $state,
            'client' => $this->client_state($state),
            'error' => $this->vote_error(),
            'join_url' => Plugin::join_url(),
            'join_human' => $this->human_url(Plugin::join_url()),
            'post_url' => admin_url('admin-post.php'),
            'nonce' => wp_create_nonce('poller_vote_' . $state['code']),
            'can_manage' => current_user_can('manage_options'),
            'total_label' => self::total_label((int) $state['ballots']),
            'summary' => $this->summary($state),
            'your_vote' => __('Your vote', 'poller'),
        ]);

        $class = $view === 'board' ? 'poller-body poller-body--board' : 'poller-body';
        $this->send($title, $class, $body, 200, $view === 'board');
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function client_state(array $state): array
    {
        $code = rawurlencode((string) $state['code']);
        $state['nonce'] = wp_create_nonce('poller_vote_' . $state['code']);
        $state['rest'] = rest_url('poller/v1/polls/' . $code);
        $state['restVotes'] = rest_url('poller/v1/polls/' . $code . '/votes');
        $state['i18n'] = [
            'oneVote' => __('1 vote', 'poller'),
            /* translators: %s is the number of ballots. */
            'manyVotes' => __('%s votes', 'poller'),
            'noVotes' => __('No votes yet', 'poller'),
            'yourVote' => __('Your vote', 'poller'),
            'vote' => __('Vote', 'poller'),
            'updateVote' => __('Update vote', 'poller'),
            'saving' => __('Saving vote', 'poller'),
            'paused' => __('Live results paused. Still showing the last update.', 'poller'),
            'chooseOne' => __('Choose one option.', 'poller'),
            'chooseSome' => __('Choose at least one option.', 'poller'),
            'voteFailed' => __('The vote did not save. Try again.', 'poller'),
            'qr' => __('QR code that opens this poll', 'poller'),
        ];
        return $state;
    }

    private function vote_error(): string
    {
        // Status key added by redirect_vote_error() and matched against a fixed list.
        if (!isset($_GET['vote'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return '';
        }
        $code = sanitize_key(wp_unslash($_GET['vote'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $map = [
            'poller_nonce' => __('That vote did not go through. Vote again.', 'poller'),
            'poller_closed' => __('This poll is closed.', 'poller'),
            'poller_choice_one' => __('Choose one option.', 'poller'),
            'poller_choice_some' => __('Choose at least one option.', 'poller'),
            'poller_choice_invalid' => __('That choice is not on this poll.', 'poller'),
            'poller_choice' => __('Choose an option to vote.', 'poller'),
            'poller_rate' => __('Too many new votes from this network just now. Wait a moment and try again.', 'poller'),
            'poller_missing' => __('That code does not match a poll.', 'poller'),
        ];
        return $map[$code] ?? '';
    }

    /**
     * @param array<string, mixed> $state
     */
    private function summary(array $state): string
    {
        $parts = [self::total_label((int) $state['ballots'])];
        foreach ($state['choices'] as $choice) {
            $parts[] = $choice['label'] . ' ' . (int) $choice['votes'];
        }
        return implode('. ', $parts) . '.';
    }

    public static function total_label(int $ballots): string
    {
        if ($ballots === 0) {
            return __('No votes yet', 'poller');
        }
        if ($ballots === 1) {
            return __('1 vote', 'poller');
        }
        return sprintf(
            /* translators: %s is the number of ballots. */
            __('%s votes', 'poller'),
            number_format_i18n($ballots)
        );
    }

    private function human_url(string $url): string
    {
        $human = preg_replace('#^https?://#', '', untrailingslashit($url));
        return $human ? $human : $url;
    }

    private function redirect_vote_error(string $target, string $code): void
    {
        wp_safe_redirect(add_query_arg('vote', sanitize_key($code), $target));
        exit;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function capture(string $template, array $data): string
    {
        $path = POLLER_DIR . 'templates/' . $template . '.php';
        ob_start();
        (static function (string $path, array $data) {
            extract($data, EXTR_SKIP);
            require $path;
        })($path, $data);
        return (string) ob_get_clean();
    }

    private function send(string $title, string $body_class, string $body, int $status, bool $qr): void
    {
        status_header($status);
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow', true);

        wp_enqueue_style('poller-fonts', POLLER_URL . 'assets/css/fonts.css', [], POLLER_VERSION);
        wp_enqueue_style('poller', POLLER_URL . 'assets/css/poller.css', ['poller-fonts'], POLLER_VERSION);

        $scripts = [];
        if ($qr) {
            wp_enqueue_script('poller-qrcode', POLLER_URL . 'assets/js/qrcode.js', [], POLLER_VERSION, false);
            $scripts[] = 'poller-qrcode';
        }
        if ($qr || str_contains($body, 'id="poller-state"')) {
            wp_enqueue_script('poller', POLLER_URL . 'assets/js/poller.js', $qr ? ['poller-qrcode'] : [], POLLER_VERSION, false);
            $scripts[] = 'poller';
        }
        ?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<title><?php echo esc_html($title); ?></title>
        <?php wp_print_styles(['poller-fonts', 'poller']); ?>
</head>
<body class="<?php echo esc_attr($body_class); ?>">
        <?php
        // Template markup is escaped at each value.
        echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        if ($scripts) {
            wp_print_scripts($scripts);
        }
        ?>
</body>
</html>
        <?php
    }
}

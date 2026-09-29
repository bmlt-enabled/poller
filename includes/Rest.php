<?php
/**
 * Public poll JSON.
 *
 * @package Poller
 */

namespace BmltEnabled\Poller;

if (!defined('ABSPATH')) {
    exit;
}

class Rest
{
    private VoteService $votes;

    public function __construct(VoteService $votes)
    {
        $this->votes = $votes;
    }

    public function register(): void
    {
        register_rest_route('poller/v1', '/polls/(?P<code>[A-Za-z0-9]{6})', [
            'methods' => 'GET',
            'callback' => [$this, 'get_poll'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('poller/v1', '/polls/(?P<code>[A-Za-z0-9]{6})/votes', [
            'methods' => 'POST',
            'callback' => [$this, 'vote'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_poll(\WP_REST_Request $request)
    {
        $code = Codes::normalize((string) $request['code']);
        $repo = new Repository();
        $poll = $repo->get_poll_by_code($code);
        if (!$poll) {
            return new \WP_Error(
                'poller_missing',
                __('That code does not match a poll.', 'poller'),
                ['status' => 404]
            );
        }

        $hash = null;
        if (Voter::has_token()) {
            $hash = Voter::hash(Voter::token());
        }

        $payload = (new Presenter($repo))->payload($poll, $hash);
        $response = new \WP_REST_Response($payload);
        $response->header('Cache-Control', 'no-store');
        return $response;
    }

    /**
     * @return array<string, mixed>|\WP_Error
     */
    public function vote(\WP_REST_Request $request)
    {
        $code = Codes::normalize((string) $request['code']);
        $nonce = (string) $request->get_header('x-poller-nonce');
        if ($nonce === '') {
            $nonce = (string) $request->get_param('nonce');
        }
        if (!wp_verify_nonce($nonce, 'poller_vote_' . $code)) {
            return new \WP_Error(
                'poller_nonce',
                __('Reload the page, then vote again.', 'poller'),
                ['status' => 403]
            );
        }

        $choices = $request->get_param('choices');
        if (!is_array($choices)) {
            $choices = [];
        }

        $result = $this->votes->vote($code, $choices);
        if (is_wp_error($result)) {
            return $result;
        }

        $response = new \WP_REST_Response($result);
        $response->header('Cache-Control', 'no-store');
        return $response;
    }
}

<?php
/**
 * Cast or replace an anonymous ballot.
 *
 * @package Poller
 */

namespace BmltEnabled\Poller;

if (!defined('ABSPATH')) {
    exit;
}

class VoteService
{
    private Repository $repo;
    private Presenter $presenter;

    public function __construct(Repository $repo, Presenter $presenter)
    {
        $this->repo = $repo;
        $this->presenter = $presenter;
    }

    /**
     * @param array<int, mixed> $submitted
     * @return array<string, mixed>|\WP_Error
     */
    public function vote(string $code, array $submitted)
    {
        $code = Codes::normalize($code);
        if (!Codes::is_valid($code)) {
            return new \WP_Error(
                'poller_missing',
                __('That code does not match a poll.', 'poller'),
                ['status' => 404]
            );
        }

        $poll = $this->repo->get_poll_by_code($code);
        if (!$poll) {
            return new \WP_Error(
                'poller_missing',
                __('That code does not match a poll.', 'poller'),
                ['status' => 404]
            );
        }
        if ($poll['status'] !== 'open') {
            return new \WP_Error(
                'poller_closed',
                __('This poll is closed.', 'poller'),
                ['status' => 409]
            );
        }

        $choices = $this->repo->get_choices((int) $poll['id']);
        $allowed = array_map(static function ($choice) {
            return (int) $choice['id'];
        }, $choices);
        $picked = BallotRules::select($submitted, $allowed, (string) $poll['selection']);
        if ($picked['error'] !== null) {
            return $this->choice_error($picked['error']);
        }

        $raw = Voter::ensure();
        $hash = Voter::hash($raw);

        try {
            $this->repo->cast((int) $poll['id'], $hash, $picked['ids']);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'closed') {
                return new \WP_Error(
                    'poller_closed',
                    __('This poll is closed.', 'poller'),
                    ['status' => 409]
                );
            }
            return new \WP_Error(
                'poller_choice_invalid',
                __('That choice is not on this poll.', 'poller'),
                ['status' => 400]
            );
        }

        $fresh = $this->repo->get_poll((int) $poll['id']);
        if (!$fresh) {
            return new \WP_Error(
                'poller_missing',
                __('That code does not match a poll.', 'poller'),
                ['status' => 404]
            );
        }

        return $this->presenter->payload($fresh, $hash);
    }

    private function choice_error(string $key): \WP_Error
    {
        $map = [
            'one' => ['poller_choice_one', __('Choose one option.', 'poller')],
            'some' => ['poller_choice_some', __('Choose at least one option.', 'poller')],
            'invalid' => ['poller_choice_invalid', __('That choice is not on this poll.', 'poller')],
        ];
        $pair = $map[$key] ?? ['poller_choice', __('Choose an option to vote.', 'poller')];
        return new \WP_Error($pair[0], $pair[1], ['status' => 400]);
    }
}

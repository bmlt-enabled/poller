<?php
/**
 * Public poll payload. Counts only — never a ballot token.
 *
 * @package Poller
 */

namespace BmltEnabled\Poller;

if (!defined('ABSPATH')) {
    exit;
}

class Presenter
{
    private Repository $repo;

    public function __construct(Repository $repo)
    {
        $this->repo = $repo;
    }

    /**
     * @param array<string, mixed> $poll
     * @return array<string, mixed>
     */
    public function payload(array $poll, ?string $voter_hash): array
    {
        $poll_id = (int) $poll['id'];
        $choices = $this->repo->get_choices($poll_id);
        $counts = $this->repo->choice_counts($poll_id);
        $mine = [];

        if ($voter_hash) {
            $ballot = $this->repo->get_ballot($poll_id, $voter_hash);
            if ($ballot) {
                $mine = $this->repo->ballot_choice_ids((int) $ballot['id']);
            }
        }

        $kind = (string) $poll['kind'];
        $public_choices = [];
        $index = 0;
        foreach ($choices as $choice) {
            $index++;
            $label = (string) $choice['label'];
            if ($label === '') {
                $label = sprintf(
                    /* translators: %d is the picture number on the ballot. */
                    __('Picture %d', 'poller'),
                    $index
                );
            }
            $image = null;
            if ($kind === 'image') {
                $image = $this->image((int) $choice['image_id'], 'large');
            }
            $public_choices[] = [
                'id' => (int) $choice['id'],
                'label' => $label,
                'votes' => (int) ($counts[(int) $choice['id']] ?? 0),
                'image' => $image,
            ];
        }

        return [
            'code' => (string) $poll['code'],
            'question' => (string) $poll['question'],
            'kind' => $kind,
            'selection' => (string) $poll['selection'],
            'status' => (string) $poll['status'],
            'revision' => (int) $poll['revision'],
            'ballots' => $this->repo->ballot_count($poll_id),
            'image' => $this->image((int) $poll['image_id'], 'large'),
            'mine' => array_values(array_map('intval', $mine)),
            'choices' => $public_choices,
            'urls' => [
                'poll' => Plugin::poll_url((string) $poll['code']),
                'board' => Plugin::board_url((string) $poll['code']),
                'join' => Plugin::join_url(),
            ],
        ];
    }

    /**
     * @return array{url: string, alt: string}|null
     */
    private function image(int $id, string $size): ?array
    {
        if ($id <= 0 || !wp_attachment_is_image($id)) {
            return null;
        }
        $url = wp_get_attachment_image_url($id, $size);
        if (!$url) {
            return null;
        }
        return [
            'url' => $url,
            'alt' => (string) get_post_meta($id, '_wp_attachment_image_alt', true),
        ];
    }
}

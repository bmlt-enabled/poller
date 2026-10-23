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
        $selection = (string) $poll['selection'];
        $dots = $selection === 'dots';
        $cloud = $selection === 'words';

        if ($voter_hash) {
            $ballot = $this->repo->get_ballot($poll_id, $voter_hash);
            if ($ballot) {
                $mine = $cloud
                    ? $this->repo->ballot_words((int) $ballot['id'])
                    : $this->repo->ballot_weights((int) $ballot['id']);
            }
        }

        // Only the aggregated words go out: text and how many ballots, never whose.
        $words = $cloud
            ? Tally::words($this->repo->word_rows($poll_id), $this->repo->hidden_words($poll_id))
            : [];

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
            'selection' => $selection,
            // Dots each voter has, or entries each voter can give on a word cloud.
            'budget' => ($dots || $cloud) ? (int) $poll['budget'] : 0,
            'per_choice' => $dots ? (int) $poll['per_choice'] : 0,
            'spend_all' => $dots && !empty($poll['spend_all']),
            'status' => (string) $poll['status'],
            'revision' => (int) $poll['revision'],
            'ballots' => $this->repo->ballot_count($poll_id),
            'dots' => $dots ? array_sum($counts) : 0,
            'image' => $this->image((int) $poll['image_id'], 'large'),
            // Dot polls send choice id => dots, word clouds this voter's entries,
            // and the others a list of choice ids.
            'mine' => ($dots || $cloud) ? $mine : array_keys($mine),
            'choices' => $public_choices,
            'words' => $words,
            // Keys for this voter's own entries, so the page can mark "Your word"
            // without guessing how the server lowercases.
            'mine_keys' => $cloud ? self::word_keys($mine) : [],
            'urls' => [
                'poll' => Plugin::poll_url((string) $poll['code']),
                'board' => Plugin::board_url((string) $poll['code']),
                'join' => Plugin::join_url(),
            ],
        ];
    }

    /**
     * @param array<int, mixed> $entries
     * @return array<int, string>
     */
    public static function word_keys(array $entries): array
    {
        $keys = [];
        foreach ($entries as $entry) {
            $word = BallotRules::word($entry);
            if ($word !== null) {
                $keys[] = $word['key'];
            }
        }
        return $keys;
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

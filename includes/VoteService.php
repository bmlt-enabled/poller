<?php
/**
 * Cast or replace an anonymous ballot.
 *
 * @package Poller
 */

namespace BmltEnabled\Poller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VoteService {

	private Repository $repo;
	private Presenter $presenter;

	public function __construct( Repository $repo, Presenter $presenter ) {
		$this->repo = $repo;
		$this->presenter = $presenter;
	}

	/**
	 * @param array<mixed, mixed> $submitted A list of choice ids, choice id => dots on a dot voting poll,
	 *                                       or a list of entries on a word cloud poll.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function vote( string $code, array $submitted ) {
		$code = Codes::normalize( $code );
		if ( ! Codes::is_valid( $code ) ) {
			return new \WP_Error(
				'poller_missing',
				__( 'That code does not match a poll.', 'poller' ),
				[ 'status' => 404 ]
			);
		}

		$poll = $this->repo->get_poll_by_code( $code );
		if ( ! $poll ) {
			return new \WP_Error(
				'poller_missing',
				__( 'That code does not match a poll.', 'poller' ),
				[ 'status' => 404 ]
			);
		}
		if ( 'open' !== $poll['status'] ) {
			return new \WP_Error(
				'poller_closed',
				__( 'This poll is closed.', 'poller' ),
				[ 'status' => 409 ]
			);
		}

		$words = 'words' === $poll['selection'];
		if ( $words ) {
			$picked = BallotRules::words( $submitted, (int) $poll['budget'] );
		} else {
			$choices = $this->repo->get_choices( (int) $poll['id'] );
			$allowed = array_map(
				static function ( $choice ) {
					return (int) $choice['id'];
				},
				$choices
			);
			$picked = BallotRules::weigh( $poll, $submitted, $allowed );
		}
		if ( null !== $picked['error'] ) {
			return self::choice_error( $picked['error'], $poll );
		}

		$raw = Voter::ensure();
		$hash = Voter::hash( $raw );

		try {
			if ( $words ) {
				$this->repo->cast_words( (int) $poll['id'], $hash, $picked['words'] );
			} else {
				$this->repo->cast( (int) $poll['id'], $hash, $picked['weights'] );
			}
		} catch ( \RuntimeException $e ) {
			if ( $e->getMessage() === 'closed' ) {
				return new \WP_Error(
					'poller_closed',
					__( 'This poll is closed.', 'poller' ),
					[ 'status' => 409 ]
				);
			}
			return new \WP_Error(
				'poller_choice_invalid',
				__( 'That choice is not on this poll.', 'poller' ),
				[ 'status' => 400 ]
			);
		}

		$fresh = $this->repo->get_poll( (int) $poll['id'] );
		if ( ! $fresh ) {
			return new \WP_Error(
				'poller_missing',
				__( 'That code does not match a poll.', 'poller' ),
				[ 'status' => 404 ]
			);
		}

		return $this->presenter->payload( $fresh, $hash );
	}

	/**
	 * @param array<string, mixed> $poll
	 */
	private static function choice_error( string $key, array $poll ): \WP_Error {
		$map = [
			'one' => 'poller_choice_one',
			'some' => 'poller_choice_some',
			'invalid' => 'poller_choice_invalid',
			'dots_none' => 'poller_dots_none',
			'dots_budget' => 'poller_dots_budget',
			'dots_per_choice' => 'poller_dots_per_choice',
			'dots_spend_all' => 'poller_dots_spend_all',
			'words_none' => 'poller_words_none',
			'words_limit' => 'poller_words_limit',
			'words_long' => 'poller_words_long',
			'words_phrase' => 'poller_words_phrase',
		];
		if ( 'invalid' === $key && ( $poll['selection'] ?? '' ) === 'words' ) {
			$map['invalid'] = 'poller_words_invalid';
		}
		$code = $map[ $key ] ?? 'poller_choice';
		return new \WP_Error( $code, (string) self::error_message( $code, $poll ), [ 'status' => 400 ] );
	}

	/**
	 * Message for a vote error code. Dot and word rules name the poll's own numbers.
	 *
	 * @param array<string, mixed> $poll
	 */
	public static function error_message( string $code, array $poll ): ?string {
		$budget = (int) ( $poll['budget'] ?? 0 );
		$per_choice = (int) ( $poll['per_choice'] ?? 0 );

		switch ( $code ) {
			case 'poller_nonce':
				return __( 'That vote did not go through. Vote again.', 'poller' );
			case 'poller_closed':
				return __( 'This poll is closed.', 'poller' );
			case 'poller_choice_one':
				return __( 'Choose one option.', 'poller' );
			case 'poller_choice_some':
				return __( 'Choose at least one option.', 'poller' );
			case 'poller_choice_invalid':
				return __( 'That choice is not on this poll.', 'poller' );
			case 'poller_choice':
				return __( 'Choose an option to vote.', 'poller' );
			case 'poller_rate':
				return __( 'Too many new votes from this network just now. Wait a moment and try again.', 'poller' );
			case 'poller_missing':
				return __( 'That code does not match a poll.', 'poller' );
			case 'poller_dots_none':
				return __( 'Place at least one dot.', 'poller' );
			case 'poller_dots_budget':
				return sprintf(
					/* translators: %d is how many dots each voter has. */
					_n( 'Use no more than %d dot.', 'Use no more than %d dots.', $budget, 'poller' ),
					$budget
				);
			case 'poller_dots_per_choice':
				return sprintf(
					/* translators: %d is the most dots allowed on one item. */
					_n( 'No more than %d dot on one item.', 'No more than %d dots on one item.', $per_choice, 'poller' ),
					$per_choice
				);
			case 'poller_dots_spend_all':
				return sprintf(
					/* translators: %d is how many dots each voter has. */
					_n( 'Use your %d dot.', 'Use all %d dots.', $budget, 'poller' ),
					$budget
				);
			case 'poller_words_none':
				return __( 'Enter at least one word.', 'poller' );
			case 'poller_words_limit':
				return sprintf(
					/* translators: %d is how many words each voter can enter. */
					_n( 'Enter no more than %d word.', 'Enter no more than %d words.', $budget, 'poller' ),
					$budget
				);
			case 'poller_words_long':
				return sprintf(
					/* translators: %d is the most characters in one entry. */
					__( 'Keep each entry to %d characters or fewer.', 'poller' ),
					BallotRules::WORD_LENGTH
				);
			case 'poller_words_phrase':
				return sprintf(
					/* translators: %d is the most words in one entry. */
					__( 'Keep each entry to %d words or fewer.', 'poller' ),
					BallotRules::WORD_PHRASE
				);
			case 'poller_words_invalid':
				return __( 'Those words could not be read. Try again.', 'poller' );
		}
		return null;
	}
}

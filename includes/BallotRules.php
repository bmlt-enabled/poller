<?php
/**
 * Pure checks for a submitted ballot.
 *
 * @package Poller
 */

namespace BmltEnabled\Poller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BallotRules {

	/** Most choices on a one-choice or more-than-one poll. */
	public const MAX_CHOICES = 12;

	/** Most items on a dot voting poll. */
	public const MAX_DOT_CHOICES = 100;

	/** Most dots one voter can have. */
	public const MAX_BUDGET = 50;

	public const DEFAULT_BUDGET = 5;

	/** Most entries one voter can give on a word cloud poll. */
	public const MAX_WORDS = 10;

	public const DEFAULT_WORDS = 3;

	/** Longest word cloud entry, in characters. */
	public const WORD_LENGTH = 24;

	/** Most words in one word cloud phrase. */
	public const WORD_PHRASE = 3;

	/**
	 * @param array<int, mixed> $submitted
	 * @param array<int, int> $allowed
	 * @return array{ids: array<int, int>, error: ?string}
	 */
	public static function select( array $submitted, array $allowed, string $selection ): array {
		$allowed_map = [];
		foreach ( $allowed as $id ) {
			$allowed_map[ (int) $id ] = true;
		}

		$ids = [];
		foreach ( $submitted as $value ) {
			$id = self::whole( $value );
			if ( null === $id || $id <= 0 || ! isset( $allowed_map[ $id ] ) ) {
				return [
					'ids' => [],
					'error' => 'invalid',
				];
			}
			$ids[ $id ] = $id;
		}

		$ids = array_values( $ids );
		if ( 'single' === $selection ) {
			if ( count( $ids ) !== 1 ) {
				return [
					'ids' => [],
					'error' => 'one',
				];
			}
		} elseif ( count( $ids ) < 1 ) {
			return [
				'ids' => [],
				'error' => 'some',
			];
		}

		if ( count( $ids ) > self::MAX_CHOICES ) {
			return [
				'ids' => [],
				'error' => 'some',
			];
		}

		return [
			'ids' => $ids,
			'error' => null,
		];
	}

	/**
	 * A dot ballot is a map of choice id => dots. Items with 0 dots are dropped.
	 *
	 * @param array<mixed, mixed> $submitted
	 * @param array<int, int> $allowed
	 * @return array{weights: array<int, int>, error: ?string}
	 */
	public static function dots( array $submitted, array $allowed, int $budget, int $per_choice, bool $spend_all ): array {
		$allowed_map = [];
		foreach ( $allowed as $id ) {
			$allowed_map[ (int) $id ] = true;
		}

		$weights = [];
		$total = 0;
		foreach ( $submitted as $key => $value ) {
			$id = self::whole( $key );
			$dots = self::whole( $value );
			if ( null === $id || $id <= 0 || ! isset( $allowed_map[ $id ] ) || null === $dots || $dots < 0 ) {
				return [
					'weights' => [],
					'error' => 'invalid',
				];
			}
			if ( 0 === $dots ) {
				continue;
			}
			if ( $dots > $per_choice ) {
				return [
					'weights' => [],
					'error' => 'dots_per_choice',
				];
			}
			$weights[ $id ] = $dots;
			$total += $dots;
		}

		if ( $total < 1 ) {
			return [
				'weights' => [],
				'error' => 'dots_none',
			];
		}
		if ( $total > $budget ) {
			return [
				'weights' => [],
				'error' => 'dots_budget',
			];
		}
		if ( $spend_all && $total !== $budget ) {
			return [
				'weights' => [],
				'error' => 'dots_spend_all',
			];
		}

		return [
			'weights' => $weights,
			'error' => null,
		];
	}

	/**
	 * Tidy one word cloud entry. Returns the text to show and the key it is
	 * counted under, or null when there is nothing left (or it is not text).
	 *
	 * Whitespace is collapsed and punctuation at either end is dropped. The
	 * key is the same text in lowercase, so "Hope", "hope!" and " HOPE " match.
	 *
	 * Control characters, private-use characters, and the bidi overrides that
	 * could flip text on the room display are removed. Other format characters
	 * stay, so a joined emoji like a family or a flag keeps its joiners.
	 * With intl, text is put in NFC first so "café" typed either way matches.
	 *
	 * @param mixed $value
	 * @return array{text: string, key: string}|null
	 */
	public static function word( $value ): ?array {
		if ( ! is_string( $value ) && ! is_int( $value ) ) {
			return null;
		}
		$text = preg_replace( '/[\p{Cc}\p{Co}\x{202A}-\x{202E}\x{2066}-\x{2069}]+/u', ' ', (string) $value );
		if ( null === $text ) {
			return null;
		}
		if ( class_exists( 'Normalizer' ) ) {
			$text = (string) \Normalizer::normalize( $text, \Normalizer::FORM_C );
		}
		$text = (string) preg_replace( '/\s+/u', ' ', $text );
		$text = (string) preg_replace( '/^[\p{P}\s]+|[\p{P}\s]+$/u', '', $text );
		if ( '' === $text ) {
			return null;
		}
		$key = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
		return [
			'text' => $text,
			'key' => $key,
		];
	}

	/**
	 * A word cloud ballot is a list of entries. Empty entries are dropped,
	 * and an entry repeated on one ballot counts once.
	 *
	 * @param array<mixed, mixed> $submitted
	 * @return array{words: array<int, array{text: string, key: string}>, error: ?string}
	 */
	public static function words( array $submitted, int $limit ): array {
		$words = [];
		$entries = 0;
		foreach ( $submitted as $value ) {
			if ( is_string( $value ) && trim( $value ) === '' ) {
				continue;
			}
			if ( ! is_string( $value ) && ! is_int( $value ) ) {
				return [
					'words' => [],
					'error' => 'invalid',
				];
			}
			if ( preg_match( '//u', (string) $value ) !== 1 ) {
				return [
					'words' => [],
					'error' => 'invalid',
				];
			}
			$word = self::word( $value );
			if ( null === $word ) {
				continue;
			}
			$entries++;
			if ( $entries > $limit ) {
				return [
					'words' => [],
					'error' => 'words_limit',
				];
			}
			if ( self::length( $word['text'] ) > self::WORD_LENGTH ) {
				return [
					'words' => [],
					'error' => 'words_long',
				];
			}
			if ( count( explode( ' ', $word['text'] ) ) > self::WORD_PHRASE ) {
				return [
					'words' => [],
					'error' => 'words_phrase',
				];
			}
			if ( ! isset( $words[ $word['key'] ] ) ) {
				$words[ $word['key'] ] = $word;
			}
		}

		if ( $entries < 1 ) {
			return [
				'words' => [],
				'error' => 'words_none',
			];
		}

		return [
			'words' => array_values( $words ),
			'error' => null,
		];
	}

	/**
	 * Check a ballot against a poll's rules and return choice id => weight.
	 * Single and multi polls weigh every choice as 1.
	 *
	 * @param array<string, mixed> $poll
	 * @param array<mixed, mixed> $submitted
	 * @param array<int, int> $allowed
	 * @return array{weights: array<int, int>, error: ?string}
	 */
	public static function weigh( array $poll, array $submitted, array $allowed ): array {
		$selection = (string) ( $poll['selection'] ?? 'single' );
		if ( 'words' === $selection ) {
			// Word cloud ballots carry text, not choice ids. See words().
			return [
				'weights' => [],
				'error' => 'invalid',
			];
		}
		if ( 'dots' === $selection ) {
			return self::dots(
				$submitted,
				$allowed,
				(int) ( $poll['budget'] ?? 0 ),
				(int) ( $poll['per_choice'] ?? 0 ),
				! empty( $poll['spend_all'] )
			);
		}

		$picked = self::select( $submitted, $allowed, $selection );
		if ( null !== $picked['error'] ) {
			return [
				'weights' => [],
				'error' => $picked['error'],
			];
		}
		return [
			'weights' => array_fill_keys( $picked['ids'], 1 ),
			'error' => null,
		];
	}

	/**
	 * Most items a poll with this selection can have.
	 */
	public static function max_choices( string $selection ): int {
		return 'dots' === $selection ? self::MAX_DOT_CHOICES : self::MAX_CHOICES;
	}

	private static function length( string $text ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
	}

	/**
	 * @param mixed $value
	 */
	private static function whole( $value ): ?int {
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) && preg_match( '/^\d+$/', $value ) ) {
			return (int) $value;
		}
		return null;
	}
}

<?php
/**
 * Unit tests for weighted tallies and the word cloud tally.
 */

use BmltEnabled\Poller\Tally;

class Test_Tally extends WP_UnitTestCase {

	public function test_rank_orders_by_votes_and_keeps_poll_order_on_ties() {
		$tally = [
			[ 'id' => 4, 'votes' => 3 ],
			[ 'id' => 9, 'votes' => 7 ],
			[ 'id' => 11, 'votes' => 3 ],
			[ 'id' => 12, 'votes' => 0 ],
		];
		$ranked = array_column( Tally::rank( $tally ), 'id' );
		$this->assertSame( [ 9, 4, 11, 12 ], $ranked, 'dot tallies rank by dots and keep poll order on ties' );
	}

	public function test_share_is_out_of_all_dots_for_dot_polls() {
		$this->assertSame( 53.8, Tally::share( 7, [ 'selection' => 'dots', 'dots' => 13, 'ballots' => 4 ] ), 'a dot share is out of all dots cast' );
	}

	public function test_share_is_out_of_ballots_otherwise() {
		$this->assertSame( 75.0, Tally::share( 3, [ 'selection' => 'multi', 'dots' => 0, 'ballots' => 4 ] ), 'other shares are out of ballots' );
	}

	public function test_empty_tally_has_no_share() {
		$this->assertSame( 0.0, Tally::share( 3, [ 'selection' => 'dots', 'dots' => 0, 'ballots' => 0 ] ), 'an empty tally has no share' );
	}

	public function test_words_rank_by_ballots_then_alphabetically() {
		$rows = [
			[ 'key' => 'hope', 'text' => 'Hope', 'count' => 1 ],
			[ 'key' => 'hope', 'text' => 'hope', 'count' => 1 ],
			[ 'key' => 'hope', 'text' => 'Hope', 'count' => 1 ],
			[ 'key' => 'tired', 'text' => 'tired', 'count' => 1 ],
			[ 'key' => 'calm', 'text' => 'Calm', 'count' => 1 ],
			[ 'key' => 'calm', 'text' => 'calm', 'count' => 1 ],
			[ 'key' => 'rude', 'text' => 'Rude', 'count' => 1 ],
			[ 'key' => 'rude', 'text' => 'rude', 'count' => 1 ],
			[ 'key' => '42', 'text' => '42', 'count' => 1 ],
		];
		$cloud = Tally::words( $rows, [ 'rude' ] );

		$this->assertSame( [ 'hope', 'calm', '42', 'tired' ], array_column( $cloud, 'key' ), 'words rank by ballots, then alphabetically' );
		$this->assertSame( [ 'text' => 'Hope', 'key' => 'hope', 'count' => 3 ], $cloud[0], 'variants merge and show the most common spelling' );
		$this->assertSame( 'Calm', $cloud[1]['text'], 'a spelling tie picks one the same way every time' );
		$this->assertFalse( in_array( 'rude', array_column( $cloud, 'key' ), true ), 'hidden words are left out' );
		$this->assertSame( '42', $cloud[2]['key'], 'a number-like word keeps a string key' );
		$this->assertSame( [ 'text', 'key', 'count' ], array_keys( $cloud[0] ), 'a tallied word carries no ballot or token' );
	}

	public function test_lowercase_majority_beats_a_capitalized_spelling() {
		$rows = [];
		foreach ( [ 'hope', 'hope', 'Hope', 'hope' ] as $text ) {
			$rows[] = [ 'key' => 'hope', 'text' => $text, 'count' => 1 ];
		}
		$this->assertSame( 'hope', Tally::words( $rows )[0]['text'], 'a lowercase majority beats a capitalized spelling that sorts first' );
	}

	public function test_payload_carries_the_top_100_words() {
		$lots = [];
		for ( $i = 0; $i < 150; $i++ ) {
			$lots[] = [ 'key' => 'w' . $i, 'text' => 'w' . $i, 'count' => 1 ];
		}
		$this->assertCount( 100, Tally::words( $lots ), 'the payload carries the top 100 words' );
		$this->assertCount( 150, Tally::words( $lots, [], 0 ), 'a limit of 0 keeps every word' );
	}
}

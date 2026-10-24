<?php
/**
 * Unit tests for ballot selection, dot voting, and the word cloud rules.
 */

use BmltEnabled\Poller\BallotRules;

class Test_Ballot_Rules extends WP_UnitTestCase {

	// -------------------------------------------------------------------------
	// Single / multi choice selection
	// -------------------------------------------------------------------------

	public function test_single_choice_accepts_one_id() {
		$result = BallotRules::select( [ '4' ], [ 4, 9 ], 'single' );
		$this->assertNull( $result['error'] );
		$this->assertSame( [ 4 ], $result['ids'], 'single choice accepts one id' );
	}

	public function test_single_choice_requires_exactly_one_id() {
		$this->assertSame( 'one', BallotRules::select( [], [ 4, 9 ], 'single' )['error'], 'single choice requires one id' );
		$this->assertSame( 'one', BallotRules::select( [ '4', '9' ], [ 4, 9 ], 'single' )['error'], 'single choice rejects two ids' );
	}

	public function test_multi_choice_dedupes_and_keeps_order() {
		$result = BallotRules::select( [ '9', '4', '9' ], [ 4, 9 ], 'multi' );
		$this->assertNull( $result['error'] );
		$this->assertSame( [ 9, 4 ], $result['ids'], 'multi choice dedupes and keeps order' );
	}

	public function test_multi_choice_requires_one_id() {
		$this->assertSame( 'some', BallotRules::select( [], [ 4, 9 ], 'multi' )['error'], 'multi choice requires one id' );
	}

	public function test_unknown_and_junk_choices_are_rejected() {
		$this->assertSame( 'invalid', BallotRules::select( [ '3' ], [ 4, 9 ], 'single' )['error'], 'unknown choice is rejected' );
		$this->assertSame( 'invalid', BallotRules::select( [ '4abc' ], [ 4, 9 ], 'single' )['error'], 'non-numeric choice is rejected' );
	}

	public function test_multi_choice_stops_at_twelve() {
		$this->assertSame( 'some', BallotRules::select( range( 1, 13 ), range( 1, 13 ), 'multi' )['error'], 'multi choice still stops at 12' );
	}

	// -------------------------------------------------------------------------
	// Dot voting
	// -------------------------------------------------------------------------

	public function test_dots_keep_each_items_dots_and_drop_zeros() {
		$result = BallotRules::dots( [ '4' => '2', '9' => 1, '11' => 0 ], [ 4, 9, 11 ], 5, 3, false );
		$this->assertNull( $result['error'] );
		$this->assertSame( [ 4 => 2, 9 => 1 ], $result['weights'], "dots keep each item's dots and drop zeros" );
	}

	public function test_dots_over_the_budget_are_rejected() {
		$this->assertSame( 'dots_budget', BallotRules::dots( [ 4 => 3, 9 => 3 ], [ 4, 9, 11 ], 5, 3, false )['error'], 'dots over the budget are rejected' );
	}

	public function test_dots_over_the_per_item_maximum_are_rejected() {
		$this->assertSame( 'dots_per_choice', BallotRules::dots( [ 4 => 4 ], [ 4, 9, 11 ], 5, 3, false )['error'], 'dots over the per-item maximum are rejected' );
		$this->assertSame( 'dots_per_choice', BallotRules::dots( [ 4 => 2 ], [ 4, 9, 11 ], 5, 1, false )['error'], 'stacking off rejects 2 dots on one item' );
	}

	public function test_dots_stacking_off_takes_one_dot_on_each_item() {
		$result = BallotRules::dots( [ 4 => 1, 9 => 1, 11 => 1 ], [ 4, 9, 11 ], 3, 1, true );
		$this->assertNull( $result['error'] );
		$this->assertSame( 3, array_sum( $result['weights'] ), 'stacking off takes one dot on each item' );
	}

	public function test_dots_spend_all() {
		$this->assertNull( BallotRules::dots( [ 4 => 3, 9 => 2 ], [ 4, 9, 11 ], 5, 3, true )['error'], 'spend all accepts the exact total' );
		$this->assertSame( 'dots_spend_all', BallotRules::dots( [ 4 => 3, 9 => 1 ], [ 4, 9, 11 ], 5, 3, true )['error'], 'spend all rejects a short total' );
		$this->assertNull( BallotRules::dots( [ 4 => 3, 9 => 1 ], [ 4, 9, 11 ], 5, 3, false )['error'], 'a short total is fine when spend all is off' );
	}

	public function test_dots_need_at_least_one_dot() {
		$this->assertSame( 'dots_none', BallotRules::dots( [ 4 => 0 ], [ 4, 9, 11 ], 5, 3, false )['error'], 'a dot ballot needs at least one dot' );
	}

	public function test_dots_reject_invalid_items_and_counts() {
		$items = [ 4, 9, 11 ];
		$this->assertSame( 'invalid', BallotRules::dots( [ 5 => 1 ], $items, 5, 3, false )['error'], 'dots on an item not on the poll are rejected' );
		$this->assertSame( 'invalid', BallotRules::dots( [ 4 => -1, 9 => 3 ], $items, 5, 3, false )['error'], 'negative dots are rejected' );
		$this->assertSame( 'invalid', BallotRules::dots( [ 4 => '1.5' ], $items, 5, 3, false )['error'], 'fractional dots are rejected' );
		$this->assertSame( 'invalid', BallotRules::dots( [ '4' => '-2', '9' => '1' ], $items, 5, 3, false )['error'], 'a negative count posted from the form is rejected' );
		$this->assertSame( 'invalid', BallotRules::dots( [ '4' => '1.5' ], $items, 5, 3, false )['error'], 'a fractional count posted from the form is rejected' );
		$this->assertSame( 'invalid', BallotRules::dots( [ 4, 9 ], $items, 5, 3, false )['error'], 'a plain list of ids is not a dot ballot' );
	}

	public function test_dot_polls_are_not_held_to_twelve_items() {
		$many = range( 1, 60 );
		$result = BallotRules::dots( [ 60 => 1 ] + array_fill_keys( $many, 0 ), $many, 5, 1, false );
		$this->assertNull( $result['error'], 'dot polls are not held to 12 items' );
	}

	public function test_item_caps_by_selection() {
		$this->assertSame( 100, BallotRules::max_choices( 'dots' ), 'dots cap at 100' );
		$this->assertSame( 12, BallotRules::max_choices( 'multi' ), 'multi caps at 12' );
	}

	// -------------------------------------------------------------------------
	// weigh() routes by the poll's selection
	// -------------------------------------------------------------------------

	public function test_weigh_checks_a_dot_ballot_by_the_polls_rules() {
		$poll = [ 'selection' => 'dots', 'budget' => 5, 'per_choice' => 2, 'spend_all' => false ];
		$result = BallotRules::weigh( $poll, [ 4 => 2, 9 => 2 ], [ 4, 9, 11 ] );
		$this->assertNull( $result['error'] );
		$this->assertSame( [ 4 => 2, 9 => 2 ], $result['weights'], "weigh checks a dot ballot by the poll's rules" );
	}

	public function test_a_word_cloud_never_takes_choice_ids() {
		$this->assertSame( 'invalid', BallotRules::weigh( [ 'selection' => 'words', 'budget' => 3 ], [ '4' ], [ 4, 9, 11 ] )['error'], 'a word cloud never takes choice ids' );
	}

	// -------------------------------------------------------------------------
	// Word cloud: normalization
	// -------------------------------------------------------------------------

	public function test_word_normalization() {
		$this->assertSame( [ 'text' => 'Hope', 'key' => 'hope' ], BallotRules::word( '  "Hope!!"  ' ), 'a word drops outer punctuation and spaces' );
		$this->assertSame( BallotRules::word( 'HOPE' )['key'], BallotRules::word( 'hope.' )['key'], 'case and punctuation variants share a key' );
		$this->assertSame( 'long day', BallotRules::word( "long \t\n  day" )['text'], 'inner whitespace collapses to one space' );
		$this->assertSame( "don't stop", BallotRules::word( "don't stop" )['text'], 'inner punctuation stays' );
		$this->assertSame( 'élan', BallotRules::word( 'Élan' )['key'], 'keys lowercase accented letters' );
		$this->assertSame( '<3', BallotRules::word( '<3' )['text'], 'symbols are kept as typed' );
		$this->assertNull( BallotRules::word( '...' ), 'punctuation alone is empty' );
		$this->assertSame( 'calm', BallotRules::word( "calm\u{0007}" )['text'], 'control characters are dropped' );
		$this->assertNull( BallotRules::word( [ 'hope' ] ), 'a non-string is not a word' );
		$this->assertSame( 'olleh', BallotRules::word( "\u{202E}olleh" )['text'], 'bidi overrides are dropped' );
	}

	public function test_word_normalization_keeps_emoji_intact() {
		$family = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}\u{200D}\u{1F466}";
		$this->assertSame( $family, BallotRules::words( [ $family ], 3 )['words'][0]['text'] ?? null, 'a joined emoji keeps its joiners and counts as one word' );
		$flag = "\u{1F3F3}\u{FE0F}\u{200D}\u{1F308}";
		$this->assertSame( $flag, BallotRules::word( $flag )['text'], 'a flag emoji is stored as typed' );
	}

	public function test_word_keys_normalize_composed_and_decomposed_accents() {
		if ( ! class_exists( 'Normalizer' ) ) {
			$this->markTestSkipped( 'intl Normalizer is not available' );
		}
		$this->assertSame( BallotRules::word( "cafe\u{0301}" )['key'], BallotRules::word( "caf\u{00E9}" )['key'], 'composed and decomposed accents share a key' );
	}

	// -------------------------------------------------------------------------
	// Word cloud: validation
	// -------------------------------------------------------------------------

	public function test_words_validation() {
		$words = BallotRules::words( [ 'Hope', '', '  ', 'hope!', 'Long day' ], 3 );
		$this->assertNull( $words['error'] );
		$this->assertSame( [ 'hope', 'long day' ], array_column( $words['words'], 'key' ), 'empty entries drop and repeats on one ballot count once' );

		$this->assertSame( 'words_none', BallotRules::words( [ '', ' ', '!!' ], 3 )['error'], 'a word ballot needs one entry' );
		$this->assertSame( 'words_none', BallotRules::words( [], 3 )['error'], 'an empty word ballot is rejected' );
		$this->assertSame( 'words_limit', BallotRules::words( [ 'a', 'b', 'c', 'd' ], 3 )['error'], 'more entries than the limit are rejected' );
		$this->assertNull( BallotRules::words( [ 'a', 'b', 'c' ], 3 )['error'], 'entries up to the limit are fine' );
		$this->assertNull( BallotRules::words( [ str_repeat( 'a', 24 ) ], 3 )['error'], 'a 24-character entry is fine' );
		$this->assertSame( 'words_long', BallotRules::words( [ str_repeat( 'a', 25 ) ], 3 )['error'], 'a 25-character entry is too long' );
		$this->assertNull( BallotRules::words( [ str_repeat( 'é', 24 ) ], 3 )['error'], 'length counts characters, not bytes' );
		$this->assertNull( BallotRules::words( [ 'one two three' ], 3 )['error'], 'a three-word phrase is fine' );
		$this->assertSame( 'words_phrase', BallotRules::words( [ 'one two three four' ], 3 )['error'], 'a four-word phrase is rejected' );
		$this->assertSame( 'invalid', BallotRules::words( [ [ 'nested' ] ], 3 )['error'], 'a nested value is rejected' );
		$this->assertSame( 'invalid', BallotRules::words( [ "\xff\xfe" ], 3 )['error'], 'invalid UTF-8 is rejected' );
	}

	public function test_cleaned_entries_pass_the_locked_recheck_unchanged() {
		$words = BallotRules::words( [ 'Hope', '', '  ', 'hope!', 'Long day' ], 3 );
		$again = BallotRules::words( array_column( $words['words'], 'text' ), 3 );
		$this->assertSame( $words['words'], $again['words'], 'cleaned entries pass the locked re-check unchanged' );
	}
}

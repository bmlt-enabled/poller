<?php
/**
 * Tests for the poll repository: the pre-dot-column upgrade path (pure) and
 * full create/read/update/delete plus vote casting against the database.
 */

use BmltEnabled\Poller\BallotRules;
use BmltEnabled\Poller\Repository;

class Test_Repository extends WP_UnitTestCase {

	private Repository $repo;

	public function setUp(): void {
		parent::setUp();
		poller_reset_tables();
		$this->repo = new Repository();
	}

	/**
	 * @param array<string, mixed> $overrides
	 */
	private function make_poll( array $overrides = [] ): int {
		$data = array_merge(
			[
				'code' => $this->repo->next_code(),
				'title' => 'Snacks',
				'question' => 'Which snack?',
				'image_id' => 0,
				'kind' => 'text',
				'selection' => 'single',
				'budget' => 0,
				'per_choice' => 0,
				'spend_all' => false,
				'status' => 'open',
			],
			$overrides
		);
		return $this->repo->insert_poll( $data );
	}

	// -------------------------------------------------------------------------
	// Upgrade path: a poll row saved before the dot columns existed.
	// -------------------------------------------------------------------------

	private function legacy_row(): array {
		return [
			'id' => '3',
			'code' => 'K7MQ2P',
			'title' => 'Snacks',
			'question' => 'Snacks?',
			'image_id' => '0',
			'kind' => 'text',
			'selection' => 'multi',
			'status' => 'open',
			'revision' => '2',
			'created_at' => '2026-01-01 00:00:00',
			'updated_at' => '2026-01-01 00:00:00',
		];
	}

	public function test_an_old_poll_row_reads_with_no_dot_rules() {
		$poll = Repository::hydrate_poll( $this->legacy_row() );
		$this->assertSame( 0, $poll['budget'] );
		$this->assertSame( 0, $poll['per_choice'] );
		$this->assertFalse( $poll['spend_all'], 'an old poll row reads with no dot rules' );
	}

	public function test_an_old_multi_poll_weighs_each_choice_as_one() {
		$poll = Repository::hydrate_poll( $this->legacy_row() );
		$vote = BallotRules::weigh( $poll, [ '9', '4' ], [ 4, 9, 11 ] );
		$this->assertNull( $vote['error'] );
		$this->assertSame( [ 9 => 1, 4 => 1 ], $vote['weights'], 'an old multi poll weighs each choice as 1' );
	}

	public function test_an_old_single_poll_still_takes_one_choice() {
		$poll = Repository::hydrate_poll( $this->legacy_row() );
		$single = BallotRules::weigh( [ 'selection' => 'single' ] + $poll, [ '4', '9' ], [ 4, 9, 11 ] );
		$this->assertSame( 'one', $single['error'], 'an old single poll still takes one choice' );
	}

	// -------------------------------------------------------------------------
	// Create / read
	// -------------------------------------------------------------------------

	public function test_insert_and_read_a_poll() {
		$id = $this->make_poll( [ 'code' => 'K7MQ2P', 'question' => 'Which snack?' ] );
		$this->assertGreaterThan( 0, $id );

		$by_id = $this->repo->get_poll( $id );
		$this->assertNotNull( $by_id );
		$this->assertSame( 'Which snack?', $by_id['question'] );

		$by_code = $this->repo->get_poll_by_code( 'K7MQ2P' );
		$this->assertSame( $id, $by_code['id'] );

		$this->assertNull( $this->repo->get_poll_by_code( 'ZZZZZZ' ), 'an unknown code reads as null' );
		$this->assertNull( $this->repo->get_poll( 999999 ), 'an unknown id reads as null' );
	}

	public function test_next_code_is_unique_and_valid() {
		$code = $this->repo->next_code();
		$this->assertTrue( \BmltEnabled\Poller\Codes::is_valid( $code ) );
		$this->make_poll( [ 'code' => $code ] );
		$this->assertNotSame( $code, $this->repo->next_code(), 'next_code skips a code already in use' );
	}

	public function test_list_polls_newest_first_with_ballot_counts() {
		$a = $this->make_poll( [ 'code' => 'AAAAAA', 'created_at' => '2026-01-01 00:00:00' ] );
		$b = $this->make_poll( [ 'code' => 'BBBBBB' ] );
		$this->repo->replace_choices( $a, [ [ 'label' => 'One', 'image_id' => 0 ], [ 'label' => 'Two', 'image_id' => 0 ] ] );
		$choices = $this->repo->get_choices( $a );
		$this->repo->cast( $a, str_repeat( 'a', 64 ), [ (int) $choices[0]['id'] => 1 ] );

		$polls = $this->repo->list_polls();
		$this->assertCount( 2, $polls );
		$ids = array_column( $polls, 'id' );
		$this->assertContains( $a, $ids );
		$this->assertContains( $b, $ids );
		$row_a = $polls[ array_search( $a, $ids, true ) ];
		$this->assertSame( 1, $row_a['ballots'], 'the list carries each poll ballot count' );
	}

	// -------------------------------------------------------------------------
	// Choices and the save lock
	// -------------------------------------------------------------------------

	public function test_replace_choices_writes_positions_and_locks_after_a_vote() {
		$id = $this->make_poll();
		$this->assertTrue( $this->repo->replace_choices( $id, [ [ 'label' => 'A', 'image_id' => 0 ], [ 'label' => 'B', 'image_id' => 0 ] ] ) );
		$choices = $this->repo->get_choices( $id );
		$this->assertSame( [ 'A', 'B' ], array_column( $choices, 'label' ) );
		$this->assertSame( [ 0, 1 ], array_column( $choices, 'position' ) );

		$this->repo->cast( $id, str_repeat( 'b', 64 ), [ (int) $choices[0]['id'] => 1 ] );
		$this->assertFalse( $this->repo->replace_choices( $id, [ [ 'label' => 'C', 'image_id' => 0 ] ] ), 'choices lock once a ballot exists' );
	}

	public function test_save_poll_keeps_choices_when_ballots_exist() {
		$id = $this->make_poll( [ 'selection' => 'multi' ] );
		$this->repo->replace_choices( $id, [ [ 'label' => 'A', 'image_id' => 0 ], [ 'label' => 'B', 'image_id' => 0 ] ] );
		$choices = $this->repo->get_choices( $id );
		$this->repo->cast( $id, str_repeat( 'c', 64 ), [ (int) $choices[0]['id'] => 1 ] );

		$fields = [
			'title' => 'Renamed',
			'question' => 'New question?',
			'image_id' => 0,
			'status' => 'open',
			'kind' => 'text',
			'selection' => 'single',
			'budget' => 0,
			'per_choice' => 0,
			'spend_all' => false,
		];
		$open = $this->repo->save_poll( $id, $fields, [ [ 'label' => 'Z', 'image_id' => 0 ] ] );
		$this->assertFalse( $open, 'save_poll reports the poll was locked' );

		$fresh = $this->repo->get_poll( $id );
		$this->assertSame( 'New question?', $fresh['question'], 'editable fields still save' );
		$this->assertSame( 'multi', $fresh['selection'], 'the locked selection is kept' );
		$this->assertSame( [ 'A', 'B' ], array_column( $this->repo->get_choices( $id ), 'label' ), 'the locked choices are kept' );
	}

	public function test_save_poll_rewrites_choices_before_any_vote() {
		$id = $this->make_poll();
		$this->repo->replace_choices( $id, [ [ 'label' => 'A', 'image_id' => 0 ], [ 'label' => 'B', 'image_id' => 0 ] ] );
		$fields = [
			'title' => 'T',
			'question' => 'Q?',
			'image_id' => 0,
			'status' => 'open',
			'kind' => 'text',
			'selection' => 'single',
			'budget' => 0,
			'per_choice' => 0,
			'spend_all' => false,
		];
		$open = $this->repo->save_poll( $id, $fields, [ [ 'label' => 'X', 'image_id' => 0 ], [ 'label' => 'Y', 'image_id' => 0 ] ] );
		$this->assertTrue( $open, 'an unvoted poll saves its new choices' );
		$this->assertSame( [ 'X', 'Y' ], array_column( $this->repo->get_choices( $id ), 'label' ) );
	}

	public function test_update_set_status_and_delete() {
		$id = $this->make_poll();
		$this->repo->set_status( $id, 'closed' );
		$this->assertSame( 'closed', $this->repo->get_poll( $id )['status'] );

		$this->repo->delete_poll( $id );
		$this->assertNull( $this->repo->get_poll( $id ), 'a deleted poll is gone' );
	}

	// -------------------------------------------------------------------------
	// Casting ballots
	// -------------------------------------------------------------------------

	public function test_cast_single_choice_counts_and_reads_back() {
		$id = $this->make_poll();
		$this->repo->replace_choices( $id, [ [ 'label' => 'A', 'image_id' => 0 ], [ 'label' => 'B', 'image_id' => 0 ] ] );
		$choices = $this->repo->get_choices( $id );
		$token = str_repeat( 'd', 64 );

		$this->repo->cast( $id, $token, [ (int) $choices[0]['id'] => 1 ] );
		$this->assertSame( 1, $this->repo->ballot_count( $id ) );
		$this->assertSame( [ (int) $choices[0]['id'] => 1 ], $this->repo->choice_counts( $id ) );

		$ballot = $this->repo->get_ballot( $id, $token );
		$this->assertNotNull( $ballot );
		$this->assertSame( [ (int) $choices[0]['id'] => 1 ], $this->repo->ballot_weights( (int) $ballot['id'] ) );

		// Re-cast replaces, does not add a second ballot.
		$this->repo->cast( $id, $token, [ (int) $choices[1]['id'] => 1 ] );
		$this->assertSame( 1, $this->repo->ballot_count( $id ) );
		$this->assertSame( [ (int) $choices[1]['id'] => 1 ], $this->repo->choice_counts( $id ) );
	}

	public function test_cast_dots_sums_weights() {
		$id = $this->make_poll( [ 'selection' => 'dots', 'budget' => 5, 'per_choice' => 3, 'spend_all' => false ] );
		$this->repo->replace_choices( $id, [ [ 'label' => 'A', 'image_id' => 0 ], [ 'label' => 'B', 'image_id' => 0 ] ] );
		$choices = $this->repo->get_choices( $id );
		$a = (int) $choices[0]['id'];
		$b = (int) $choices[1]['id'];

		$this->repo->cast( $id, str_repeat( 'e', 64 ), [ $a => 3, $b => 2 ] );
		$this->assertSame( [ $a => 3, $b => 2 ], $this->repo->choice_counts( $id ) );
	}

	public function test_cast_rejects_a_closed_poll() {
		$id = $this->make_poll();
		$this->repo->replace_choices( $id, [ [ 'label' => 'A', 'image_id' => 0 ], [ 'label' => 'B', 'image_id' => 0 ] ] );
		$choices = $this->repo->get_choices( $id );
		$this->repo->set_status( $id, 'closed' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'closed' );
		$this->repo->cast( $id, str_repeat( 'f', 64 ), [ (int) $choices[0]['id'] => 1 ] );
	}

	public function test_cast_rejects_a_choice_from_another_poll() {
		$id = $this->make_poll();
		$this->repo->replace_choices( $id, [ [ 'label' => 'A', 'image_id' => 0 ], [ 'label' => 'B', 'image_id' => 0 ] ] );

		$this->expectException( \RuntimeException::class );
		$this->repo->cast( $id, str_repeat( 'g', 64 ), [ 999999 => 1 ] );
	}

	// -------------------------------------------------------------------------
	// Word cloud storage
	// -------------------------------------------------------------------------

	public function test_cast_words_stores_rows_and_hidden_words() {
		$id = $this->make_poll( [ 'selection' => 'words', 'kind' => 'text', 'budget' => 3 ] );
		$token = str_repeat( 'h', 64 );

		$words = [
			[ 'text' => 'Hope', 'key' => 'hope' ],
			[ 'text' => 'Calm', 'key' => 'calm' ],
		];
		$this->repo->cast_words( $id, $token, $words );

		$ballot = $this->repo->get_ballot( $id, $token );
		$this->assertSame( [ 'Hope', 'Calm' ], $this->repo->ballot_words( (int) $ballot['id'] ) );

		$rows = $this->repo->word_rows( $id );
		$this->assertSame( [ 'hope', 'calm' ], array_column( $rows, 'key' ) );

		$this->repo->set_word_hidden( $id, 'calm', true );
		$this->assertSame( [ 'calm' ], $this->repo->hidden_words( $id ) );
		$this->repo->set_word_hidden( $id, 'calm', false );
		$this->assertSame( [], $this->repo->hidden_words( $id ), 'showing a word again removes it from hidden' );
	}

	public function test_cast_words_rejects_a_closed_poll() {
		$id = $this->make_poll( [ 'selection' => 'words', 'kind' => 'text', 'budget' => 3 ] );
		$this->repo->set_status( $id, 'closed' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'closed' );
		$this->repo->cast_words( $id, str_repeat( 'i', 64 ), [ [ 'text' => 'Hope', 'key' => 'hope' ] ] );
	}

	// -------------------------------------------------------------------------
	// Duplicating a poll
	// -------------------------------------------------------------------------

	public function test_duplicate_poll_copies_setup_and_choices_into_a_closed_poll() {
		$id = $this->make_poll( [ 'kind' => 'image', 'image_id' => 7, 'selection' => 'multi' ] );
		$this->repo->replace_choices(
			$id,
			[
				[ 'label' => 'Red', 'image_id' => 11 ],
				[ 'label' => 'Green', 'image_id' => 12 ],
				[ 'label' => 'Blue', 'image_id' => 13 ],
			]
		);
		$choices = $this->repo->get_choices( $id );
		$this->repo->cast( $id, str_repeat( 'j', 64 ), [ (int) $choices[1]['id'] => 1 ] );
		$before = $this->repo->get_poll( $id );

		$copy_id = $this->repo->duplicate_poll( $id );
		$this->assertIsInt( $copy_id );
		$this->assertNotSame( $id, $copy_id );

		$copy = $this->repo->get_poll( $copy_id );
		$this->assertNotSame( $before['code'], $copy['code'], 'the copy gets its own code' );
		$this->assertSame( 'Snacks (copy)', $copy['title'] );
		$this->assertSame( 'closed', $copy['status'], 'the copy does not go live by accident' );
		foreach ( [ 'question', 'image_id', 'kind', 'selection', 'budget', 'per_choice', 'spend_all' ] as $field ) {
			$this->assertSame( $before[ $field ], $copy[ $field ], "the copy keeps {$field}" );
		}

		$copied = $this->repo->get_choices( $copy_id );
		$this->assertSame( [ 'Red', 'Green', 'Blue' ], array_column( $copied, 'label' ) );
		$this->assertSame( [ 11, 12, 13 ], array_column( $copied, 'image_id' ) );
		$this->assertSame( [ 0, 1, 2 ], array_column( $copied, 'position' ) );
		$this->assertEmpty( array_intersect( array_column( $choices, 'id' ), array_column( $copied, 'id' ) ), 'the copy has its own choice rows' );

		$this->assertSame( 0, $this->repo->ballot_count( $copy_id ), 'the copy has no ballots' );
		$this->assertSame( [], $this->repo->choice_counts( $copy_id ), 'the copy has no votes' );
		$this->assertTrue( $this->repo->replace_choices( $copy_id, [ [ 'label' => 'Only', 'image_id' => 0 ] ] ), 'the copy\'s choices stay editable' );

		$this->assertSame( $before, $this->repo->get_poll( $id ), 'the original is unchanged' );
		$this->assertSame( $choices, $this->repo->get_choices( $id ) );
		$this->assertSame( 1, $this->repo->ballot_count( $id ) );
	}

	public function test_duplicate_poll_keeps_dot_rules() {
		$id = $this->make_poll( [ 'selection' => 'dots', 'budget' => 5, 'per_choice' => 3, 'spend_all' => true ] );
		$this->repo->replace_choices( $id, [ [ 'label' => 'A', 'image_id' => 0 ], [ 'label' => 'B', 'image_id' => 0 ] ] );

		$copy = $this->repo->get_poll( (int) $this->repo->duplicate_poll( $id ) );
		$this->assertSame( 'dots', $copy['selection'] );
		$this->assertSame( 5, $copy['budget'] );
		$this->assertSame( 3, $copy['per_choice'] );
		$this->assertTrue( $copy['spend_all'] );
		$this->assertCount( 2, $this->repo->get_choices( $copy['id'] ) );
	}

	public function test_duplicate_poll_starts_a_word_cloud_empty() {
		$id = $this->make_poll( [ 'selection' => 'words', 'kind' => 'text', 'budget' => 3 ] );
		$this->repo->cast_words( $id, str_repeat( 'k', 64 ), [ [ 'text' => 'Hope', 'key' => 'hope' ] ] );
		$this->repo->set_word_hidden( $id, 'hope', true );

		$copy_id = (int) $this->repo->duplicate_poll( $id );
		$copy = $this->repo->get_poll( $copy_id );
		$this->assertSame( 'words', $copy['selection'] );
		$this->assertSame( 3, $copy['budget'] );
		$this->assertSame( [], $this->repo->get_choices( $copy_id ) );
		$this->assertSame( [], $this->repo->word_rows( $copy_id ), 'the copy has no words' );
		$this->assertSame( [], $this->repo->hidden_words( $copy_id ), 'the copy has no hidden words' );
		$this->assertSame( [ 'hope' ], $this->repo->hidden_words( $id ), 'the original keeps its hidden words' );
		$this->assertCount( 1, $this->repo->word_rows( $id ) );
	}

	public function test_duplicate_poll_trims_a_long_title_to_fit() {
		$id = $this->make_poll( [ 'title' => str_repeat( 'é', 160 ) ] );
		$copy = $this->repo->get_poll( (int) $this->repo->duplicate_poll( $id ) );
		$this->assertSame( 160, mb_strlen( $copy['title'] ) );
		$this->assertStringEndsWith( ' (copy)', $copy['title'] );
	}

	public function test_duplicate_poll_returns_null_for_a_missing_poll() {
		$this->assertNull( $this->repo->duplicate_poll( 999999 ) );
		$this->assertCount( 0, $this->repo->list_polls() );
	}
}

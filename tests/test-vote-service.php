<?php
/**
 * Tests for casting and replacing an anonymous ballot through VoteService.
 */

use BmltEnabled\Poller\Presenter;
use BmltEnabled\Poller\Repository;
use BmltEnabled\Poller\VoteService;
use BmltEnabled\Poller\Voter;

class Test_Vote_Service extends WP_UnitTestCase {

	private Repository $repo;
	private VoteService $votes;

	public function setUp(): void {
		parent::setUp();
		poller_reset_tables();
		$_COOKIE[ Voter::COOKIE ] = str_repeat( 'f', 64 );
		$this->repo = new Repository();
		$this->votes = new VoteService( $this->repo, new Presenter( $this->repo ) );
	}

	public function tearDown(): void {
		unset( $_COOKIE[ Voter::COOKIE ] );
		parent::tearDown();
	}

	/**
	 * @param array<int, array{label: string, image_id: int}> $choices
	 * @param array<string, mixed> $overrides
	 * @return array{0: array<string, mixed>, 1: array<int, int>}
	 */
	private function poll( array $choices, array $overrides = [] ): array {
		$id = $this->repo->insert_poll(
			array_merge(
				[
					'code' => 'K7MQ2P',
					'title' => 'T',
					'question' => 'Q?',
					'image_id' => 0,
					'kind' => 'text',
					'selection' => 'single',
					'budget' => 0,
					'per_choice' => 0,
					'spend_all' => false,
					'status' => 'open',
				],
				$overrides
			)
		);
		if ( $choices ) {
			$this->repo->replace_choices( $id, $choices );
		}
		$ids = array_map( 'intval', array_column( $this->repo->get_choices( $id ), 'id' ) );
		return [ $this->repo->get_poll( $id ), $ids ];
	}

	public function test_unknown_code_is_a_404() {
		$result = $this->votes->vote( 'nope', [ '1' ] );
		$this->assertWPError( $result );
		$this->assertSame( 'poller_missing', $result->get_error_code() );
	}

	public function test_valid_but_missing_code_is_a_404() {
		$result = $this->votes->vote( 'K7MQ2P', [ '1' ] );
		$this->assertWPError( $result );
		$this->assertSame( 'poller_missing', $result->get_error_code() );
	}

	public function test_closed_poll_is_rejected() {
		[ $poll, $ids ] = $this->poll( [ [ 'label' => 'A', 'image_id' => 0 ], [ 'label' => 'B', 'image_id' => 0 ] ] );
		$this->repo->set_status( (int) $poll['id'], 'closed' );
		$result = $this->votes->vote( 'K7MQ2P', [ (string) $ids[0] ] );
		$this->assertWPError( $result );
		$this->assertSame( 'poller_closed', $result->get_error_code() );
	}

	public function test_single_choice_vote_succeeds() {
		[ $poll, $ids ] = $this->poll( [ [ 'label' => 'A', 'image_id' => 0 ], [ 'label' => 'B', 'image_id' => 0 ] ] );
		$result = $this->votes->vote( 'K7MQ2P', [ (string) $ids[0] ] );
		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['ballots'] );
		$this->assertSame( [ $ids[0] ], $result['mine'] );
	}

	public function test_single_choice_rejects_two_selections() {
		[ $poll, $ids ] = $this->poll( [ [ 'label' => 'A', 'image_id' => 0 ], [ 'label' => 'B', 'image_id' => 0 ] ] );
		$result = $this->votes->vote( 'K7MQ2P', [ (string) $ids[0], (string) $ids[1] ] );
		$this->assertWPError( $result );
		$this->assertSame( 'poller_choice_one', $result->get_error_code() );
	}

	public function test_multi_choice_vote_succeeds() {
		[ $poll, $ids ] = $this->poll( [ [ 'label' => 'A', 'image_id' => 0 ], [ 'label' => 'B', 'image_id' => 0 ] ], [ 'selection' => 'multi' ] );
		$result = $this->votes->vote( 'K7MQ2P', [ (string) $ids[0], (string) $ids[1] ] );
		$this->assertIsArray( $result );
		$this->assertSame( 2, count( $result['mine'] ) );
	}

	public function test_dots_vote_and_budget_error() {
		[ $poll, $ids ] = $this->poll(
			[ [ 'label' => 'A', 'image_id' => 0 ], [ 'label' => 'B', 'image_id' => 0 ] ],
			[ 'selection' => 'dots', 'budget' => 5, 'per_choice' => 3, 'spend_all' => false ]
		);
		$ok = $this->votes->vote( 'K7MQ2P', [ $ids[0] => 3, $ids[1] => 2 ] );
		$this->assertIsArray( $ok );
		$this->assertSame( 5, $ok['dots'] );

		$over = $this->votes->vote( 'K7MQ2P', [ $ids[0] => 3, $ids[1] => 3 ] );
		$this->assertWPError( $over );
		$this->assertSame( 'poller_dots_budget', $over->get_error_code() );
	}

	public function test_words_vote_and_limit_error() {
		[ $poll ] = $this->poll( [], [ 'selection' => 'words', 'kind' => 'text', 'budget' => 2 ] );
		$ok = $this->votes->vote( 'K7MQ2P', [ 'Hope', 'Calm' ] );
		$this->assertIsArray( $ok );
		// The cloud is sorted by ballots then alphabetically (calm before hope).
		$this->assertSame( [ 'calm', 'hope' ], array_column( $ok['words'], 'key' ) );

		$over = $this->votes->vote( 'K7MQ2P', [ 'a', 'b', 'c' ] );
		$this->assertWPError( $over );
		$this->assertSame( 'poller_words_limit', $over->get_error_code() );
	}

	public function test_error_message_covers_every_code() {
		$dots = [ 'selection' => 'dots', 'budget' => 5, 'per_choice' => 3 ];
		$words = [ 'selection' => 'words', 'budget' => 3 ];
		foreach (
			[
				'poller_nonce',
				'poller_closed',
				'poller_choice_one',
				'poller_choice_some',
				'poller_choice_invalid',
				'poller_choice',
				'poller_rate',
				'poller_missing',
				'poller_dots_none',
				'poller_dots_budget',
				'poller_dots_per_choice',
				'poller_dots_spend_all',
				'poller_words_none',
				'poller_words_limit',
				'poller_words_long',
				'poller_words_phrase',
				'poller_words_invalid',
			] as $code
		) {
			$poll = str_starts_with( $code, 'poller_words' ) ? $words : $dots;
			$this->assertNotEmpty( VoteService::error_message( $code, $poll ), "{$code} has a message" );
		}
		$this->assertNull( VoteService::error_message( 'poller_unknown', [] ), 'an unknown code has no message' );
	}
}
